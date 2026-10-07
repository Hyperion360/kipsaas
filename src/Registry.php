<?php // src/Registry.php
declare(strict_types=1);

namespace KipSaaS;

final class Registry
{
    private \PDO $pdo;

    public function __construct(string $dsn)
    {
        $pdo = new \PDO($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $this->pdo = $pdo;
        $this->migrate();
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec('PRAGMA busy_timeout=5000'); // FPM and cron share this file; SQLite's default 0 turns lock contention into 500s
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS tenants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            host TEXT NOT NULL UNIQUE,
            plan TEXT NOT NULL,
            title TEXT NOT NULL DEFAULT \'\',
            owner_email TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'pending\' CHECK (status IN
                (\'pending\',\'verified\',\'active\',\'past_due\',\'suspended\',\'closed\')),
            verify_token_hash TEXT,
            verify_expires_at TEXT,
            stripe_customer_id TEXT,
            stripe_subscription_id TEXT,
            grace_until TEXT,
            purge_after TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\')),
            updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stripe_event_id TEXT NOT NULL UNIQUE,
            type TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'pending\' CHECK (status IN (\'pending\',\'done\')),
            tenant_id INTEGER REFERENCES tenants (id),
            payload_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
            bucket TEXT PRIMARY KEY,
            hits INTEGER NOT NULL,
            window_start INTEGER NOT NULL
        )');
        // Custom-domain claims. 'pending' until the operator has confirmed
        // the domain's DNS by hand; 'active' once the map should route it.
        // UNIQUE(domain): one claim per hostname registry-wide, because the
        // nginx map allows exactly one value per key. No cascade: tenants
        // are never deleted, the close path releases the claim in Tenants.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS tenant_domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL REFERENCES tenants (id),
            domain TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT \'pending\' CHECK (status IN (\'pending\',\'active\')),
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%fZ\', \'now\'))
        )');
        // Serves the close-path release and every per-tenant join; domain
        // lookups ride the UNIQUE index SQLite builds for the constraint.
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tenant_domains_tenant ON tenant_domains (tenant_id)');
        // Index shapes matched to the repositories' real WHERE clauses. All
        // partial: each serves exactly one lookup family, stays small, and
        // never indexes the NULL dead weight the other rows carry. IF NOT
        // EXISTS so an existing registry converges on the next open.
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tenants_verify_token ON tenants (verify_token_hash)
            WHERE verify_token_hash IS NOT NULL');
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_tenants_owner_email ON tenants (owner_email)
            WHERE status != 'closed'");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tenants_subscription ON tenants (stripe_subscription_id)
            WHERE stripe_subscription_id IS NOT NULL');
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_tenants_due_suspension ON tenants (grace_until)
            WHERE status = 'past_due' AND grace_until IS NOT NULL");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_tenants_due_purge ON tenants (purge_after)
            WHERE status = 'suspended' AND purge_after IS NOT NULL");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tenants_status ON tenants (status, host, slug)');
    }
}
