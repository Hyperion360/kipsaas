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
}
