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
    }
}
