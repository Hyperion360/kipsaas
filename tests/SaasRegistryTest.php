<?php // tests/SaasRegistryTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\Registry;
use KipSaaS\Tenants;
use PHPUnit\Framework\TestCase;

final class SaasRegistryTest extends TestCase
{
    private Tenants $tenants;

    protected function setUp(): void
    {
        $dsn = 'sqlite:' . sys_get_temp_dir() . '/registry-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->tenants = new Tenants((new Registry($dsn))->pdo());
    }

    private function tenant(): int
    {
        return $this->tenants->create('acme', 'acme.saas.example.test', 'standard', 'ow@example.test',
            'Acme Archive', 'deadbeef', '2026-01-01T00:00:00Z');
    }

    public function test_create_and_lookup(): void
    {
        $id = $this->tenant();
        $t = $this->tenants->byId($id);
        self::assertSame('acme', $t['slug']);
        self::assertSame('pending', $t['status']);
        self::assertSame('acme', $this->tenants->byHost('acme.saas.example.test')['slug']);
        self::assertNull($this->tenants->bySlug('nobody'));
    }

    public function test_status_transitions_are_guarded(): void
    {
        $id = $this->tenant();
        $this->tenants->setStatus($id, 'verified');
        $this->tenants->setStatus($id, 'active');
        $this->tenants->setStatus($id, 'past_due');
        $this->tenants->setStatus($id, 'active');
        $this->tenants->setStatus($id, 'suspended', ['purge_after' => '2026-03-01T00:00:00Z']);
        $this->tenants->setStatus($id, 'closed');
        self::expectException(\DomainException::class);
        $this->tenants->setStatus($id, 'active'); // closed is terminal
    }

    public function test_event_claim_is_idempotent_and_rollbackable(): void
    {
        $id = $this->tenant();
        self::assertTrue($this->tenants->claimEvent('evt_1', 'checkout.session.completed', $id, '{}'));
        self::assertSame('pending', $this->tenants->eventStatus('evt_1')); // duplicate-while-pending: the handler 500s so Stripe retries
        $this->tenants->completeEvent('evt_1');
        self::assertSame('done', $this->tenants->eventStatus('evt_1')); // duplicate-after-done: acknowledged no-op
        $this->tenants->unclaimEvent('evt_1');
        self::assertTrue($this->tenants->claimEvent('evt_1', 'checkout.session.completed', $id, '{}')); // retry after failure
    }

    public function test_host_maps_and_status_lists(): void
    {
        $id = $this->tenant();
        $this->tenants->setStatus($id, 'verified');
        self::assertSame([], $this->tenants->hostToSlug()); // not serving yet
        $this->tenants->setStatus($id, 'active');
        self::assertSame(['acme.saas.example.test' => 'acme'], $this->tenants->hostToSlug());
        $this->tenants->setStatus($id, 'past_due', ['grace_until' => '2026-02-01T00:00:00Z']);
        self::assertSame(['acme.saas.example.test' => 'acme'], $this->tenants->hostToSlug()); // grace still serves
        $this->tenants->setStatus($id, 'suspended', ['purge_after' => '2026-03-01T00:00:00Z']);
        self::assertSame([], $this->tenants->hostToSlug());
        self::assertSame(['acme.saas.example.test'], $this->tenants->hostsByStatus('suspended'));
    }

    public function test_all_lists_every_row_oldest_first(): void
    {
        $this->tenant();
        $this->tenants->create('beta', 'beta.saas.example.test', 'pro', 'b@example.test', 'Beta', 'h', '2026-01-01T00:00:00Z');
        self::assertSame(['acme', 'beta'], array_column($this->tenants->all(), 'slug'));
    }

    public function test_plan_is_an_editable_column_but_identity_columns_still_are_not(): void
    {
        // A plan change (upgrade, comped pilot) is a supported repository
        // edit; slug and host are identity and must stay raw-SQL-free.
        $id = $this->tenant();
        $this->tenants->update($id, ['plan' => 'pro']);
        self::assertSame('pro', $this->tenants->byId($id)['plan']);
        self::expectException(\InvalidArgumentException::class);
        $this->tenants->update($id, ['slug' => 'hijack']);
    }

    public function test_lookup_and_due_queries_hit_indexes_not_full_scans(): void
    {
        // The db-optimize standard as a test: every WHERE the repositories
        // issue must be served by an index shape; a registry that grows to
        // real size must not turn claim lookups or cron sweeps into scans.
        $id = $this->tenant();
        $this->tenants->setStatus($id, 'verified');
        $this->tenants->setStatus($id, 'active');
        $this->tenants->update($id, ['stripe_subscription_id' => 'sub_1']);
        $this->tenants->setStatus($id, 'past_due', ['grace_until' => '2026-02-01T00:00:00Z']);
        $queries = [
            'token lookup (signup claim)' => ['SELECT * FROM tenants WHERE verify_token_hash = ?', ['x']],
            'email lookup (billing portal)' => ["SELECT * FROM tenants WHERE owner_email = ? AND status != 'closed' ORDER BY id", ['x']],
            'subscription lookup (webhooks)' => ['SELECT * FROM tenants WHERE stripe_subscription_id = ?', ['x']],
            'due-for-suspension sweep' => ["SELECT * FROM tenants WHERE status = 'past_due' AND grace_until IS NOT NULL AND grace_until < ?", ['2026-01-01T00:00:00Z']],
            'due-for-purge sweep' => ["SELECT * FROM tenants WHERE status = 'suspended' AND purge_after IS NOT NULL AND purge_after < ?", ['2026-01-01T00:00:00Z']],
            'routed-hosts map query' => ["SELECT host, slug FROM tenants WHERE status IN ('active', 'past_due')", []],
            'suspended-hosts map query' => ['SELECT host FROM tenants WHERE status = ? ORDER BY host', ['suspended']],
        ];
        foreach ($queries as $label => [$sql, $params]) {
            $q = $this->tenants->pdo()->prepare('EXPLAIN QUERY PLAN ' . $sql);
            $q->execute($params);
            $plan = strtolower((string) json_encode($q->fetchAll(\PDO::FETCH_ASSOC)));
            self::assertStringNotContainsString('scan tenants', $plan, "{$label} must use an index, not a full scan");
            self::assertStringNotContainsString('temp b-tree', $plan, "{$label} must not sort through a temp b-tree");
        }
    }
}
