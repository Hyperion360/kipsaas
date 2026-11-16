<?php // src/WebhookHandler.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Billing state machine, driven only by verified Stripe events. A known
 * event's claim, action, finalization, and publication run inside the
 * optional host routing callback. The tenant update and event completion
 * share one SQLite transaction. Failed work releases a pending claim for
 * retry; a committed event remains done even if publication later fails.
 * Unknown event types are acknowledged without entering the routing callback.
 */
final class WebhookHandler
{
    /** @param \Closure():void $publishMaps called when routing might change */
    public function __construct(private Tenants $tenants, private ProvisionerInterface $provisioner,
        private array $config, private \Closure $publishMaps, private ?\Closure $routingChange = null)
    {
        if (($config['recover_pending_events'] ?? false) && $routingChange === null) {
            throw new \InvalidArgumentException('pending event recovery requires routing_change');
        }
    }

    public function handle(array $event): string
    {
        $type = (string) ($event['type'] ?? '');
        $eventId = (string) ($event['id'] ?? '');
        $known = in_array($type, ['checkout.session.completed', 'invoice.paid',
            'invoice.payment_failed', 'customer.subscription.deleted'], true);
        if ($known && ($eventId === '' || !is_array($event['data']['object'] ?? null))) {
            throw new \InvalidArgumentException('invalid billing event');
        }
        $change = function () use ($event, $eventId, $type): void {
            $tenantId = $this->tenantIdFrom($event);
            // An unknown tenant cannot be stored in the events foreign key.
            $claimId = $tenantId !== null && $this->tenants->byId($tenantId) === null ? null : $tenantId;
            $payload = (string) json_encode($event, JSON_THROW_ON_ERROR);
            if (!$this->tenants->claimEvent($eventId, $type, $claimId, $payload)) {
                $claim = $this->tenants->eventClaim($eventId);
                if (($claim['status'] ?? null) === 'done') return;
                if (!($this->config['recover_pending_events'] ?? false) || $this->routingChange === null
                    || !$this->matchingClaim($claim, $type, $claimId, $event)) {
                    throw new \RuntimeException('event ' . $eventId . ' still being processed; retry later');
                }
            }
            try {
                [$to, $statusExtra, $otherFields, $publish] = match ($type) {
                    'checkout.session.completed' => $this->activate($event, (int) $tenantId),
                    'invoice.paid' => $this->renewed((int) $tenantId),
                    'invoice.payment_failed' => $this->pastDue((int) $tenantId),
                    'customer.subscription.deleted' => $this->canceled((int) $tenantId),
                    default => [null, [], [], false],
                };
                $this->tenants->finalizeEventTransition($eventId, $claimId, $to, $statusExtra, $otherFields);
            } catch (\Throwable $e) {
                // A failed action owns a pending claim; a committed claim is never removed.
                if ($this->tenants->eventStatus($eventId) === 'pending') $this->tenants->unclaimEvent($eventId);
                throw $e;
            }
            if ($publish) $this->publishMapsSafe();
        };
        try {
            if ($known && $this->routingChange !== null) $this->routeChange($change);
            else $change();
        } catch (\Throwable $e) {
            if ($this->tenants->eventStatus($eventId) === 'done') {
                error_log('saas: routing callback failed after event commit: ' . $e->getMessage());
                return 'ok';
            }
            throw $e;
        }
        return 'ok';
    }

    private function routeChange(\Closure $change): void
    {
        $calls = 0;
        $once = static function () use (&$calls, $change): void {
            if (++$calls !== 1) throw new \LogicException('routing change called twice');
            $change();
        };
        ($this->routingChange)($once);
        if ($calls !== 1) throw new \LogicException('routing change was not called');
    }

    private function matchingClaim(?array $claim, string $type, ?int $tenantId, array $event): bool
    {
        if ($claim === null || $claim['status'] !== 'pending' || $claim['type'] !== $type
            || ($claim['tenant_id'] === null ? null : (int) $claim['tenant_id']) !== $tenantId) return false;
        try {
            $stored = json_decode((string) $claim['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            return hash('sha256', json_encode($this->canonical($stored), JSON_THROW_ON_ERROR))
                === hash('sha256', json_encode($this->canonical($event), JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return false;
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$part) $part = $this->canonical($part);
        return $value;
    }

    /** Map publish is a routing concern: a failed reload must never roll back a completed payment. Cron map:write converges. */
    private function publishMapsSafe(): void
    {
        try {
            ($this->publishMaps)();
        } catch (\Throwable $e) {
            error_log('saas: map publish failed: ' . $e->getMessage());
        }
    }

    private function tenantIdFrom(array $event): ?int
    {
        $o = $event['data']['object'] ?? [];
        $ref = $o['client_reference_id'] ?? $o['subscription_details']['metadata']['tenant_id']
            ?? $o['metadata']['tenant_id'] ?? null;
        if (($ref === null || $ref === '') && isset($o['subscription'])) {
            // invoice events reference the tenant through the subscription we stored
            $row = $this->tenants->bySubscription((string) $o['subscription']);
            return $row === null ? null : (int) $row['id'];
        }
        if (($ref === null || $ref === '') && ($event['type'] ?? '') === 'customer.subscription.deleted') {
            // the deleted event's object IS the subscription: its id field carries sub_*
            $row = $this->tenants->bySubscription((string) ($o['id'] ?? ''));
            return $row === null ? null : (int) $row['id'];
        }
        return is_numeric($ref) ? (int) $ref : null;
    }

    private function activate(array $event, int $tenantId): array
    {
        $tenant = $this->tenants->byId($tenantId);
        if ($tenant === null) {
            error_log("saas: checkout event for unknown tenant {$tenantId}; acknowledging"); // retrying would 500 forever
            return [null, [], [], false];
        }
        if ($tenant['status'] !== 'verified') return [null, [], [], false]; // already activated
        $o = $event['data']['object'];
        $fields = [
            'stripe_customer_id' => isset($o['customer']) ? (string) $o['customer'] : null,
            'stripe_subscription_id' => isset($o['subscription']) ? (string) $o['subscription'] : null,
        ];
        // The status flip comes AFTER the provisioner: side effects first,
        // commit last. A provision that throws leaves the row 'verified', so
        // Stripe's retry re-runs the whole transition; the reverse order
        // would strand an 'active' row whose directory was never stamped,
        // acked 'ok' on the retry because the guard above fired.
        $this->provisioner->provision(array_replace($tenant, $fields));
        return ['active', [], $fields, true];
    }

    private function renewed(int $tenantId): array
    {
        $t = $this->tenants->byId($tenantId);
        if ($t === null) return [null, [], [], false];
        if ($t['status'] === 'suspended') {
            // Paying again inside the retention window restores service; after
            // purge_after the data is gone and this is a no-op (the purge path closes the row).
            if ($t['purge_after'] !== null && $t['purge_after'] < gmdate('Y-m-d\TH:i:s\Z')) return [null, [], [], false];
            // Lock removal before the flip: a failed resume leaves the row
            // suspended so the retry re-enters this branch and redoes it.
            $this->provisioner->resume($this->tenants->byId($tenantId));
            return ['active', ['purge_after' => null, 'grace_until' => null], [], true];
        }
        if ($t['status'] === 'past_due') {
            return ['active', ['grace_until' => null], [], false];
        }
        return [null, [], ['grace_until' => null], false];
    }

    private function pastDue(int $tenantId): array
    {
        $t = $this->tenants->byId($tenantId);
        if ($t === null || $t['status'] !== 'active') return [null, [], [], false];
        $grace = gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * (int) $this->config['grace_days']);
        return ['past_due', ['grace_until' => $grace], [], false];
    }

    private function canceled(int $tenantId): array
    {
        $t = $this->tenants->byId($tenantId);
        if ($t === null) return [null, [], [], false];
        $purge = gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * (int) $this->config['retention_days']);
        if (in_array($t['status'], ['active', 'past_due'], true)) {
            // The lock write comes before the flip: a suspension whose lock
            // failed must stay 'active' so the retry redoes the transition;
            // a flipped row with no lock would keep serving traffic forever.
            $this->provisioner->suspend($t);
            return ['suspended', ['purge_after' => $purge], [], true];
        }
        return [null, [], [], false];
    }
}
