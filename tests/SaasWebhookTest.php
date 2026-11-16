<?php // tests/SaasWebhookTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\ProvisionerInterface;
use KipSaaS\Registry;
use KipSaaS\Tenants;
use KipSaaS\WebhookHandler;
use PHPUnit\Framework\TestCase;

/** Records calls instead of touching the filesystem. */
final class RecordingProvisioner implements ProvisionerInterface
{
    public array $calls = [];
    public function provision(array $tenant): array { $this->calls[] = ['provision', $tenant['slug']]; return ['mail_sent' => true, 'one_time_password' => null]; }
    public function resume(array $tenant): void { $this->calls[] = ['resume', $tenant['slug']]; }
    public function suspend(array $tenant): void { $this->calls[] = ['suspend', $tenant['slug']]; }
    public function purge(array $tenant): void { $this->calls[] = ['purge', $tenant['slug']]; }
}

final class SaasWebhookTest extends TestCase
{
    private Tenants $tenants;
    private RecordingProvisioner $provisioner;
    private int $tenantId;

    protected function setUp(): void
    {
        $pdo = (new Registry('sqlite:' . sys_get_temp_dir() . '/wh-' . bin2hex(random_bytes(4)) . '.sqlite'))->pdo();
        $this->tenants = new Tenants($pdo);
        $this->tenants->create('acme', 'acme.saas.example.test', 'standard', 'ow@example.test', 'Acme', 'h', '2026-01-01T00:00:00Z');
        $this->tenantId = (int) $this->tenants->bySlug('acme')['id'];
        $this->tenants->setStatus($this->tenantId, 'verified');
        $this->provisioner = new RecordingProvisioner();
    }

    private function handler(): WebhookHandler
    {
        return new WebhookHandler($this->tenants, $this->provisioner,
            ['grace_days' => 7, 'retention_days' => 30], fn() => null /* maps published no-op in tests */);
    }

    private function event(string $type, array $object, string $id = 'evt_1'): array
    {
        return ['id' => $id, 'type' => $type, 'data' => ['object' => $object]];
    }

    public function test_checkout_completes_provisions_and_activates(): void
    {
        $out = $this->handler()->handle($this->event('checkout.session.completed', [
            'id' => 'cs_1', 'client_reference_id' => (string) $this->tenantId,
            'customer' => 'cus_9', 'subscription' => 'sub_3',
        ]));
        self::assertSame('ok', $out);
        $t = $this->tenants->byId($this->tenantId);
        self::assertSame('active', $t['status']);
        self::assertSame('cus_9', $t['stripe_customer_id']);
        self::assertSame('sub_3', $t['stripe_subscription_id']);
        self::assertSame(['provision', 'acme'], $this->provisioner->calls[0]);
    }

    public function test_duplicate_delivery_is_a_noop(): void
    {
        $e = $this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]);
        $this->handler()->handle($e);
        $this->handler()->handle($e); // same event id again, delivery already done
        self::assertCount(1, $this->provisioner->calls);
    }

    public function test_duplicate_while_still_pending_throws_so_stripe_retries(): void
    {
        $e = $this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]);
        // Delivery #1 in flight: the event row exists as pending but the handler has not completed it.
        $this->tenants->claimEvent('evt_1', 'checkout.session.completed', $this->tenantId, '{}');
        try {
            $this->handler()->handle($e);
            self::fail('a duplicate arriving mid-processing must not be acknowledged');
        } catch (\RuntimeException $ex) {
            self::assertStringContainsString('retry', $ex->getMessage());
        }
        // Finish #1, then the queued retry from Stripe is a clean no-op.
        $this->tenants->completeEvent('evt_1');
        self::assertSame('ok', $this->handler()->handle($e));
        self::assertCount(0, $this->provisioner->calls);
    }

    public function test_failed_action_rolls_back_the_claim_so_stripe_retry_reprocesses(): void
    {
        $failing = new class implements ProvisionerInterface {
            public function provision(array $tenant): array { throw new \RuntimeException('disk full'); }
            public function resume(array $tenant): void {}
            public function suspend(array $tenant): void {}
            public function purge(array $tenant): void {}
        };
        $e = $this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]);
        try {
            (new WebhookHandler($this->tenants, $failing, ['grace_days' => 7, 'retention_days' => 30], fn() => null))->handle($e);
            self::fail('expected the exception to surface');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        // The failed run must NOT have flipped the row active: activation is
        // the LAST step, so the retry re-enters the whole transition.
        self::assertSame('verified', $this->tenants->byId($this->tenantId)['status']);
        // The retry (fresh handler, working provisioner) must reprocess the SAME event id.
        $recovery = new RecordingProvisioner();
        (new WebhookHandler($this->tenants, $recovery, ['grace_days' => 7, 'retention_days' => 30], fn() => null))->handle($e);
        $t = $this->tenants->byId($this->tenantId);
        self::assertSame('active', $t['status']);
        self::assertSame(['provision', 'acme'], $recovery->calls[0] ?? null, 'the retry must provision, not just flip the row');
    }

    public function test_redelivery_after_a_failed_suspend_writes_the_lock_and_flips_the_row(): void
    {
        // Cancellation writes the suspension lock BEFORE the status flip, so
        // a lock failure leaves the row active and Stripe's retry redoes the
        // whole transition. The reverse order would strand the row suspended
        // with no lock file, serving traffic forever after a "successful" ack.
        $this->handler()->handle($this->event('checkout.session.completed',
            ['client_reference_id' => (string) $this->tenantId, 'customer' => 'cus_9', 'subscription' => 'sub_3'], 'evt_a'));
        $failingSuspend = new class implements ProvisionerInterface {
            public function provision(array $tenant): array { return ['mail_sent' => true, 'one_time_password' => null]; }
            public function resume(array $tenant): void {}
            public function suspend(array $tenant): void { throw new \RuntimeException('lock write failed'); }
            public function purge(array $tenant): void {}
        };
        $e = $this->event('customer.subscription.deleted', ['id' => 'sub_3'], 'evt_b');
        try {
            (new WebhookHandler($this->tenants, $failingSuspend, ['grace_days' => 7, 'retention_days' => 30], fn() => null))->handle($e);
            self::fail('expected the suspend failure to surface');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame('active', $this->tenants->byId($this->tenantId)['status'], 'a failed lock write must not suspend the row');
        $recovery = new RecordingProvisioner();
        (new WebhookHandler($this->tenants, $recovery, ['grace_days' => 7, 'retention_days' => 30], fn() => null))->handle($e);
        self::assertSame('suspended', $this->tenants->byId($this->tenantId)['status']);
        self::assertContains('suspend', array_column($recovery->calls, 0), 'the retry must write the lock, not just flip the row');
    }

    public function test_redelivery_after_a_failed_resume_reenters_the_transition(): void
    {
        $this->handler()->handle($this->event('checkout.session.completed',
            ['client_reference_id' => (string) $this->tenantId, 'subscription' => 'sub_3'], 'evt_a'));
        $this->handler()->handle($this->event('customer.subscription.deleted', ['id' => 'sub_3'], 'evt_b'));
        $failingResume = new class implements ProvisionerInterface {
            public function provision(array $tenant): array { return ['mail_sent' => true, 'one_time_password' => null]; }
            public function resume(array $tenant): void { throw new \RuntimeException('lock removal failed'); }
            public function suspend(array $tenant): void {}
            public function purge(array $tenant): void {}
        };
        $e = $this->event('invoice.paid', ['subscription' => 'sub_3'], 'evt_c');
        try {
            (new WebhookHandler($this->tenants, $failingResume, ['grace_days' => 7, 'retention_days' => 30], fn() => null))->handle($e);
            self::fail('expected the resume failure to surface');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame('suspended', $this->tenants->byId($this->tenantId)['status'], 'a failed lock removal must keep the row suspended for the retry');
        $recovery = new RecordingProvisioner();
        (new WebhookHandler($this->tenants, $recovery, ['grace_days' => 7, 'retention_days' => 30], fn() => null))->handle($e);
        self::assertSame('active', $this->tenants->byId($this->tenantId)['status']);
        self::assertNull($this->tenants->byId($this->tenantId)['purge_after']);
        self::assertContains('resume', array_column($recovery->calls, 0), 'the retry must remove the lock, not just flip the row');
    }

    public function test_unknown_tenant_event_is_acknowledged_not_retried_forever(): void
    {
        self::assertSame('ok', $this->handler()->handle($this->event('checkout.session.completed', ['client_reference_id' => '99999'], 'evt_8')));
    }

    public function test_payment_lifecycle_including_resumption_after_paying_again(): void
    {
        $h = $this->handler();
        $h->handle($this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId, 'customer' => 'cus_9', 'subscription' => 'sub_3']));
        $h->handle($this->event('invoice.payment_failed', ['subscription' => 'sub_3'], 'evt_2'));
        self::assertSame('past_due', $this->tenants->byId($this->tenantId)['status']);
        self::assertNotNull($this->tenants->byId($this->tenantId)['grace_until']);
        $h->handle($this->event('invoice.paid', ['subscription' => 'sub_3'], 'evt_3'));
        self::assertSame('active', $this->tenants->byId($this->tenantId)['status']);
        $h->handle($this->event('customer.subscription.deleted', ['id' => 'sub_3'], 'evt_4'));
        self::assertSame('suspended', $this->tenants->byId($this->tenantId)['status']);
        self::assertNotNull($this->tenants->byId($this->tenantId)['purge_after']);
        // A suspended customer pays again inside the retention window: back to active, lock removed, map republished.
        $h->handle($this->event('invoice.paid', ['subscription' => 'sub_3'], 'evt_5'));
        self::assertSame('active', $this->tenants->byId($this->tenantId)['status']);
        self::assertNull($this->tenants->byId($this->tenantId)['purge_after']);
        self::assertContains('resume', array_column($this->provisioner->calls, 0));
    }

    public function test_paid_too_late_after_retention_stays_suspended(): void
    {
        $h = $this->handler();
        $h->handle($this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId, 'subscription' => 'sub_3']));
        $h->handle($this->event('customer.subscription.deleted', ['id' => 'sub_3'], 'evt_2'));
        $this->tenants->update($this->tenantId, ['purge_after' => '2020-01-01T00:00:00Z']); // retention long elapsed
        $h->handle($this->event('invoice.paid', ['subscription' => 'sub_3'], 'evt_3'));
        self::assertSame('suspended', $this->tenants->byId($this->tenantId)['status']);
    }

    public function test_unknown_event_types_are_acknowledged_not_errors(): void
    {
        self::assertSame('ok', $this->handler()->handle($this->event('radar.early_fraud_warning.created', [], 'evt_9')));
        $this->addToAssertionCount(1);
    }

    public function test_route_wrapper_covers_claim_commit_and_publication_once(): void
    {
        $order = [];
        $handler = new WebhookHandler($this->tenants, $this->provisioner,
            ['grace_days' => 7, 'retention_days' => 30],
            function () use (&$order): void {
                self::assertSame('active', $this->tenants->byId($this->tenantId)['status']);
                self::assertSame('done', $this->tenants->eventStatus('evt_1'));
                $order[] = 'publish';
            },
            function (\Closure $change) use (&$order): void {
                $order[] = 'enter';
                if (count($order) === 1) self::assertNull($this->tenants->eventStatus('evt_1'));
                $change();
                $order[] = 'leave';
            });
        $event = $this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]);
        self::assertSame('ok', $handler->handle($event));
        self::assertSame(['enter', 'publish', 'leave'], $order);
        self::assertSame('ok', $handler->handle($event));
        self::assertSame(['enter', 'publish', 'leave', 'enter', 'leave'], $order);
        self::assertSame('ok', $handler->handle($this->event('ignored.type', [], 'evt_other')));
        self::assertCount(5, $order);
    }

    public function test_post_commit_wrapper_failure_keeps_done_event(): void
    {
        $attempts = 0;
        $handler = new WebhookHandler($this->tenants, $this->provisioner,
            ['grace_days' => 7, 'retention_days' => 30],
            function () use (&$attempts): void { ++$attempts; },
            function (\Closure $change): void { $change(); throw new \RuntimeException('lock release failed'); });
        $event = $this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]);
        self::assertSame('ok', $handler->handle($event));
        self::assertSame('done', $this->tenants->eventStatus('evt_1'));
        self::assertSame('ok', $handler->handle($event));
        self::assertSame(1, $attempts);
        self::assertCount(1, $this->provisioner->calls);
    }

    public function test_wrapper_cannot_run_action_twice_or_skip_it(): void
    {
        $event = $this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]);
        $attempts = 0;
        $twice = new WebhookHandler($this->tenants, $this->provisioner,
            ['grace_days' => 7, 'retention_days' => 30],
            function () use (&$attempts): void { ++$attempts; },
            function (\Closure $change): void { $change(); $change(); });
        self::assertSame('ok', $twice->handle($event));
        self::assertSame('done', $this->tenants->eventStatus('evt_1'));
        self::assertSame(1, $attempts);
        self::assertCount(1, $this->provisioner->calls);

        $skip = new WebhookHandler($this->tenants, $this->provisioner,
            ['grace_days' => 7, 'retention_days' => 30], fn() => null,
            fn(\Closure $change) => null);
        try {
            $skip->handle($this->event('invoice.payment_failed', ['client_reference_id' => (string) $this->tenantId], 'evt_skip'));
            self::fail('skipped action must not acknowledge the event');
        } catch (\LogicException) {
            self::assertNull($this->tenants->eventStatus('evt_skip'));
        }
    }

    public function test_wrapper_cannot_swallow_action_failure_and_acknowledge_event(): void
    {
        $failing = new class implements ProvisionerInterface {
            public function provision(array $tenant): array { throw new \RuntimeException('provision failed'); }
            public function resume(array $tenant): void {}
            public function suspend(array $tenant): void {}
            public function purge(array $tenant): void {}
        };
        $handler = new WebhookHandler($this->tenants, $failing,
            ['grace_days' => 7, 'retention_days' => 30], fn() => null,
            function (\Closure $change): void {
                try { $change(); } catch (\RuntimeException) { /* an incorrect host wrapper swallows the action error */ }
            });
        try {
            $handler->handle($this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]));
            self::fail('an uncommitted action must not be acknowledged');
        } catch (\RuntimeException $e) {
            self::assertSame('provision failed', $e->getMessage());
            self::assertNull($this->tenants->eventStatus('evt_1'));
            self::assertSame('verified', $this->tenants->byId($this->tenantId)['status']);
        }
    }

    public function test_retained_action_cannot_run_after_wrapper_releases_lock(): void
    {
        $retained = null;
        $handler = new WebhookHandler($this->tenants, $this->provisioner,
            ['grace_days' => 7, 'retention_days' => 30], fn() => null,
            function (\Closure $change) use (&$retained): void { $retained = $change; });
        try {
            $handler->handle($this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]));
            self::fail('returning without the action must fail');
        } catch (\LogicException) {
            self::assertNull($this->tenants->eventStatus('evt_1'));
        }
        self::assertInstanceOf(\Closure::class, $retained);
        try {
            $retained();
            self::fail('retained action must not run outside its wrapper');
        } catch (\LogicException) {
            self::assertNull($this->tenants->eventStatus('evt_1'));
            self::assertSame('verified', $this->tenants->byId($this->tenantId)['status']);
            self::assertSame([], $this->provisioner->calls);
        }
    }

    public function test_lock_failure_before_action_leaves_no_claim(): void
    {
        $handler = new WebhookHandler($this->tenants, $this->provisioner,
            ['grace_days' => 7, 'retention_days' => 30], fn() => null,
            function (\Closure $change): void { throw new \RuntimeException('lock busy'); });
        $this->expectException(\RuntimeException::class);
        try {
            $handler->handle($this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]));
        } finally {
            self::assertNull($this->tenants->eventStatus('evt_1'));
            self::assertSame('verified', $this->tenants->byId($this->tenantId)['status']);
        }
    }

    public function test_recovery_requires_a_route_wrapper(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebhookHandler($this->tenants, $this->provisioner,
            ['recover_pending_events' => true], fn() => null);
    }

    public function test_invalid_known_event_never_enters_route_callbacks(): void
    {
        $entered = 0;
        $published = 0;
        $handler = new WebhookHandler($this->tenants, $this->provisioner,
            ['grace_days' => 7, 'retention_days' => 30],
            function () use (&$published): void { ++$published; },
            function (\Closure $change) use (&$entered): void { ++$entered; $change(); });
        try {
            $handler->handle(['type' => 'checkout.session.completed', 'data' => ['object' => []]]);
            self::fail('missing event id must fail');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $entered);
            self::assertSame(0, $published);
        }
    }

    public function test_matching_interrupted_claim_is_recovered_only_when_enabled(): void
    {
        $event = $this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]);
        $this->tenants->claimEvent('evt_1', $event['type'], $this->tenantId, json_encode($event, JSON_THROW_ON_ERROR));
        $handler = new WebhookHandler($this->tenants, $this->provisioner,
            ['grace_days' => 7, 'retention_days' => 30, 'recover_pending_events' => true],
            fn() => null, fn(\Closure $change) => $change());
        self::assertSame('ok', $handler->handle($event));
        self::assertSame('done', $this->tenants->eventStatus('evt_1'));
        self::assertSame('active', $this->tenants->byId($this->tenantId)['status']);
    }

    public function test_changed_payload_cannot_adopt_interrupted_claim(): void
    {
        $event = $this->event('checkout.session.completed', ['client_reference_id' => (string) $this->tenantId]);
        $this->tenants->claimEvent('evt_1', $event['type'], $this->tenantId, json_encode($event, JSON_THROW_ON_ERROR));
        $event['data']['object']['customer'] = 'different';
        $handler = new WebhookHandler($this->tenants, $this->provisioner,
            ['grace_days' => 7, 'retention_days' => 30, 'recover_pending_events' => true],
            fn() => null, fn(\Closure $change) => $change());
        $this->expectException(\RuntimeException::class);
        $handler->handle($event);
    }
}
