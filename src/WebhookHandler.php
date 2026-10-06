<?php // src/WebhookHandler.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Billing state machine, driven ONLY by verified Stripe events. Idempotency
 * with a pending window: the event id is claimed first (unique index,
 * status 'pending'), the action runs, then the claim flips to 'done'. A
 * duplicate delivery arriving while the first is still running throws so
 * Stripe retries later instead of being told 'ok' for work that may still
 * fail; a duplicate after 'done' is a clean no-op. A thrown action deletes
 * the claim so Stripe's retry reprocesses. Unknown event types are
 * acknowledged: Stripe sends many we do not care about, and 500ing on them
 * would trip Stripe's retry alarm forever.
 */
final class WebhookHandler
{
    /** @param \Closure():void $publishMaps called when routing might change */
    public function __construct(private Tenants $tenants, private ProvisionerInterface $provisioner,
        private array $config, private \Closure $publishMaps) {}

    public function handle(array $event): string
    {
        $type = (string) ($event['type'] ?? '');
        $eventId = (string) ($event['id'] ?? '');
        $tenantId = $this->tenantIdFrom($event);
        // An event naming a tenant that does not exist is still claimed (so
        // duplicates no-op) but without the foreign key: the events table
        // references tenants (id), and an unknown id must acknowledge, not
        // violate the constraint.
        $claimId = $tenantId !== null && $this->tenants->byId($tenantId) === null ? null : $tenantId;
        if (!$this->tenants->claimEvent($eventId, $type, $claimId,
            (string) json_encode($event, JSON_THROW_ON_ERROR))) {
            if ($this->tenants->eventStatus($eventId) === 'pending') {
                throw new \RuntimeException('event ' . $eventId . ' still being processed; retry later');
            }
            return 'ok'; // duplicate delivery, already done
        }
        try {
            match ($type) {
                'checkout.session.completed' => $this->activate($event, (int) $tenantId),
                'invoice.paid' => $this->renewed($event, (int) $tenantId),
                'invoice.payment_failed' => $this->pastDue((int) $tenantId),
                'customer.subscription.deleted' => $this->canceled($event, (int) $tenantId),
                default => null,
            };
            $this->tenants->completeEvent($eventId);
        } catch (\Throwable $e) {
            $this->tenants->unclaimEvent($eventId);
            throw $e;
        }
        return 'ok';
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

    private function activate(array $event, int $tenantId): void
    {
        $tenant = $this->tenants->byId($tenantId);
        if ($tenant === null) {
            error_log("saas: checkout event for unknown tenant {$tenantId}; acknowledging"); // retrying would 500 forever
            return;
        }
        if ($tenant['status'] !== 'verified') return; // already activated by an earlier event
        $o = $event['data']['object'];
        $this->tenants->update($tenantId, [
            'stripe_customer_id' => isset($o['customer']) ? (string) $o['customer'] : null,
            'stripe_subscription_id' => isset($o['subscription']) ? (string) $o['subscription'] : null,
        ]);
        // The status flip comes AFTER the provisioner: side effects first,
        // commit last. A provision that throws leaves the row 'verified', so
        // Stripe's retry re-runs the whole transition; the reverse order
        // would strand an 'active' row whose directory was never stamped,
        // acked 'ok' on the retry because the guard above fired.
        $this->provisioner->provision($this->tenants->byId($tenantId)); // throws on failure -> claim rolls back
        $this->tenants->setStatus($tenantId, 'active');
        $this->publishMapsSafe();
    }

    private function renewed(array $event, int $tenantId): void
    {
        $t = $this->tenants->byId($tenantId);
        if ($t === null) return;
        if ($t['status'] === 'suspended') {
            // Paying again inside the retention window restores service; after
            // purge_after the data is gone and this is a no-op (the purge path closes the row).
            if ($t['purge_after'] !== null && $t['purge_after'] < gmdate('Y-m-d\TH:i:s\Z')) return;
            // Lock removal before the flip: a failed resume leaves the row
            // suspended so the retry re-enters this branch and redoes it.
            $this->provisioner->resume($this->tenants->byId($tenantId));
            $this->tenants->setStatus($tenantId, 'active');
            $this->tenants->update($tenantId, ['purge_after' => null, 'grace_until' => null]);
            $this->publishMapsSafe();
            return;
        }
        if ($t['status'] === 'past_due') {
            $this->tenants->setStatus($tenantId, 'active');
        }
        $this->tenants->update($tenantId, ['grace_until' => null]);
    }

    private function pastDue(int $tenantId): void
    {
        $t = $this->tenants->byId($tenantId);
        if ($t === null || $t['status'] !== 'active') return;
        $grace = gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * (int) $this->config['grace_days']);
        $this->tenants->setStatus($tenantId, 'past_due', ['grace_until' => $grace]);
    }

    private function canceled(array $event, int $tenantId): void
    {
        $t = $this->tenants->byId($tenantId);
        if ($t === null) return;
        $purge = gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * (int) $this->config['retention_days']);
        if (in_array($t['status'], ['active', 'past_due'], true)) {
            // The lock write comes before the flip: a suspension whose lock
            // failed must stay 'active' so the retry redoes the transition;
            // a flipped row with no lock would keep serving traffic forever.
            $this->provisioner->suspend($t);
            $this->tenants->setStatus($tenantId, 'suspended', ['purge_after' => $purge]);
            $this->publishMapsSafe();
        }
    }
}
