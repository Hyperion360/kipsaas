<?php // src/Tenants.php
declare(strict_types=1);

namespace KipSaaS;

final class Tenants
{
    /** Legal transitions; anything else is a bug, so it throws instead of guessing. */
    private const TRANSITIONS = [
        'pending'   => ['verified'],
        'verified'  => ['active'],
        'active'    => ['past_due', 'suspended'],
        'past_due'  => ['active', 'suspended'],
        'suspended' => ['active', 'closed'], // suspended->active: customer pays inside the retention window
        'closed'    => [],
    ];

    private const EDITABLE = ['status', 'plan', 'verify_token_hash', 'verify_expires_at', 'stripe_customer_id',
        'stripe_subscription_id', 'grace_until', 'purge_after'];

    public function __construct(private \PDO $pdo) {}

    public function create(string $slug, string $host, string $plan, string $email, string $title,
        string $tokenHash, string $tokenExpires): int
    {
        $q = $this->pdo->prepare('INSERT INTO tenants (slug, host, plan, title, owner_email, verify_token_hash, verify_expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $q->execute([$slug, $host, $plan, $title, $email, $tokenHash, $tokenExpires]);
        return (int) $this->pdo->lastInsertId();
    }

    public function byId(int $id): ?array
    {
        return $this->one('id', $id);
    }

    public function bySlug(string $slug): ?array
    {
        return $this->one('slug', $slug);
    }

    public function byHost(string $host): ?array
    {
        return $this->one('host', $host);
    }

    public function byTokenHash(string $hash): ?array
    {
        return $this->one('verify_token_hash', $hash);
    }

    public function byEmail(string $email): array
    {
        $q = $this->pdo->prepare('SELECT * FROM tenants WHERE owner_email = ? AND status != \'closed\' ORDER BY id');
        $q->execute([$email]);
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Every row, oldest first; the tenants:list listing surface. */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM tenants ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function setStatus(int $id, string $to, array $extra = []): void
    {
        $current = $this->byId($id)['status'] ?? throw new \RuntimeException("tenant {$id} missing");
        if (!in_array($to, self::TRANSITIONS[$current] ?? [], true)) {
            throw new \DomainException("illegal status transition {$current} -> {$to}");
        }
        $fields = array_merge(['status' => $to], $extra);
        $sets = implode(', ', array_map(fn(string $c): string => "{$c} = ?", array_keys($fields)));
        $q = $this->pdo->prepare("UPDATE tenants SET {$sets}, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?");
        $q->execute([...array_values($fields), $id]);
        if ($to === 'closed') {
            // Tenants are never deleted (purge only flips status to closed),
            // so the domain claim must be released here or UNIQUE(domain)
            // blocks reuse of the domain forever; an FK cascade can never
            // fire because no tenant row is ever deleted.
            $this->pdo->prepare('DELETE FROM tenant_domains WHERE tenant_id = ?')->execute([$id]);
        }
    }

    /**
     * The signup claim's atomic compare-and-swap: flips pending -> verified
     * only while the row still carries this token hash, so of two concurrent
     * submits of one link exactly one wins the rowCount. The spent hash stays
     * on the row: a replayed link must resolve to "already claimed", not to
     * an unknown-token error.
     */
    public function markVerified(int $id, string $tokenHash): bool
    {
        $q = $this->pdo->prepare("UPDATE tenants SET status = 'verified',
            updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
            WHERE id = ? AND status = 'pending' AND verify_token_hash = ?");
        $q->execute([$id, $tokenHash]);
        return $q->rowCount() === 1;
    }

    public function update(int $id, array $fields): void
    {
        foreach (array_keys($fields) as $c) {
            if (!in_array($c, self::EDITABLE, true)) {
                throw new \InvalidArgumentException("column {$c} is not editable here");
            }
        }
        if ($fields === []) return;
        $sets = implode(', ', array_map(fn(string $c): string => "{$c} = ?", array_keys($fields)));
        $q = $this->pdo->prepare("UPDATE tenants SET {$sets}, updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = ?");
        $q->execute([...array_values($fields), $id]);
    }

    /** @return array<string,string> host => slug for every tenant that should be routed (active and grace-period) */
    public function hostToSlug(): array
    {
        $q = $this->pdo->query("SELECT host, slug FROM tenants WHERE status IN ('active', 'past_due')");
        $out = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[$r['host']] = $r['slug'];
        }
        return $out;
    }

    /** @return string[] hosts currently NOT routed (suspended -> notice page) */
    public function hostsByStatus(string $status): array
    {
        $q = $this->pdo->prepare('SELECT host FROM tenants WHERE status = ? ORDER BY host');
        $q->execute([$status]);
        return array_column($q->fetchAll(\PDO::FETCH_ASSOC), 'host');
    }

    /** @return array<string,string> domain => slug for every ACTIVE custom-domain claim whose tenant is routed (active and grace-period); pending claims stay out of the map */
    public function domainToSlug(): array
    {
        $q = $this->pdo->query("SELECT d.domain, t.slug FROM tenant_domains d
            JOIN tenants t ON t.id = d.tenant_id
            WHERE d.status = 'active' AND t.status IN ('active', 'past_due') ORDER BY d.domain");
        $out = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[$r['domain']] = $r['slug'];
        }
        return $out;
    }

    /** @return string[] active custom domains of suspended tenants (notice page, same as their subdomain) */
    public function suspendedDomains(): array
    {
        $q = $this->pdo->query("SELECT d.domain FROM tenant_domains d
            JOIN tenants t ON t.id = d.tenant_id
            WHERE d.status = 'active' AND t.status = 'suspended' ORDER BY d.domain");
        return array_column($q->fetchAll(\PDO::FETCH_ASSOC), 'domain');
    }

    public function dueForSuspension(string $now): array
    {
        $q = $this->pdo->prepare("SELECT * FROM tenants WHERE status = 'past_due' AND grace_until IS NOT NULL AND grace_until < ?");
        $q->execute([$now]);
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function dueForPurge(string $now): array
    {
        $q = $this->pdo->prepare("SELECT * FROM tenants WHERE status = 'suspended' AND purge_after IS NOT NULL AND purge_after < ?");
        $q->execute([$now]);
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** True only for the FIRST delivery attempt of a Stripe event; the unique index makes later claims see the existing row. */
    public function claimEvent(string $eventId, string $type, ?int $tenantId, string $payloadJson): bool
    {
        $q = $this->pdo->prepare('INSERT OR IGNORE INTO events (stripe_event_id, type, tenant_id, payload_json) VALUES (?, ?, ?, ?)');
        $q->execute([$eventId, $type, $tenantId, $payloadJson]);
        return $q->rowCount() === 1;
    }

    /** 'pending' while a delivery is being processed, 'done' once handled, null if unknown. */
    public function eventStatus(string $eventId): ?string
    {
        $q = $this->pdo->prepare('SELECT status FROM events WHERE stripe_event_id = ?');
        $q->execute([$eventId]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : (string) $row['status'];
    }

    public function completeEvent(string $eventId): void
    {
        $this->pdo->prepare("UPDATE events SET status = 'done' WHERE stripe_event_id = ?")->execute([$eventId]);
    }

    /** Called when the action behind a claim threw, so Stripe's retry can reprocess it. */
    public function unclaimEvent(string $eventId): void
    {
        $this->pdo->prepare('DELETE FROM events WHERE stripe_event_id = ?')->execute([$eventId]);
    }

    /**
     * Registry hygiene: event rows past their audit window and rate-limit rows
     * whose window started before the cutoff are dead weight that only ever
     * grows. The cutoffs are the caller's policy (bin/saas prune: 90 days and
     * 1 day); this is the DELETE half. Returns the rows removed.
     */
    public function prune(string $eventsBefore, int $rateLimitsBefore): int
    {
        $q = $this->pdo->prepare('DELETE FROM events WHERE created_at < ?');
        $q->execute([$eventsBefore]);
        $n = $q->rowCount();
        $q = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?');
        $q->execute([$rateLimitsBefore]);
        return $n + $q->rowCount();
    }

    /** What prune() would delete right now under the same cutoffs; doctor's prunable-rows line. */
    public function prunable(string $eventsBefore, int $rateLimitsBefore): int
    {
        $q = $this->pdo->prepare('SELECT (SELECT COUNT(*) FROM events WHERE created_at < ?)
            + (SELECT COUNT(*) FROM rate_limits WHERE window_start < ?)');
        $q->execute([$eventsBefore, $rateLimitsBefore]);
        return (int) $q->fetchColumn();
    }

    public function bySubscription(string $subscriptionId): ?array
    {
        return $this->one('stripe_subscription_id', $subscriptionId);
    }

    /** The repository's connection (the rate limiter and signup share it). */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    private function one(string $col, string|int $val): ?array
    {
        $q = $this->pdo->prepare("SELECT * FROM tenants WHERE {$col} = ?");
        $q->execute([$val]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
