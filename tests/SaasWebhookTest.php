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
        // The retry (fresh handler, working provisioner) must reprocess the SAME event id.
        (new WebhookHandler($this->tenants, new RecordingProvisioner(), ['grace_days' => 7, 'retention_days' => 30], fn() => null))->handle($e);
        $t = $this->tenants->byId($this->tenantId);
        self::assertSame('active', $t['status']);
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
}
