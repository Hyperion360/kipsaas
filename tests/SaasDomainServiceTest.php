<?php // tests/SaasDomainServiceTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\DomainService;
use KipSaaS\Registry;
use KipSaaS\Tenants;
use PHPUnit\Framework\TestCase;

/**
 * Manual white-glove custom domains: no TXT challenge, no pending TTL. The
 * operator confirms DNS by hand and flips the claim live, so the tests cover
 * exactly the operator surface: claim grammar, plan gate, collisions, the
 * verify flip, idempotent removal, and release on close.
 */
final class SaasDomainServiceTest extends TestCase
{
    private \PDO $pdo;
    private Tenants $tenants;
    private DomainService $domains;

    protected function setUp(): void
    {
        $dsn = 'sqlite:' . sys_get_temp_dir() . '/domains-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = (new Registry($dsn))->pdo();
        $this->tenants = new Tenants($this->pdo);
        $this->domains = new DomainService($this->pdo, [
            'base_domain' => 'saas.example.test',
            'nginx' => ['control_host' => 'control.saas.example.test'],
            'plans' => [
                'standard' => ['label' => 'Standard'],                       // no custom_domains flag
                'pro' => ['label' => 'Pro', 'custom_domains' => true],
            ],
        ]);
    }

    /** A tenant row walked to active, on the given plan. */
    private function activeTenant(string $slug, string $plan = 'pro'): array
    {
        $id = $this->tenants->create($slug, "{$slug}.saas.example.test", $plan, "o@{$slug}.test", $slug, '', '');
        $this->tenants->setStatus($id, 'verified');
        $this->tenants->setStatus($id, 'active');
        return $this->tenants->byId($id);
    }

    private function add(array $tenant, string $domain): array
    {
        return $this->domains->add($tenant, $domain);
    }

    public function test_add_normalizes_case_and_trailing_dot_to_one_stored_domain(): void
    {
        $r = $this->add($this->activeTenant('acme'), 'Archive.Example.COM.');
        self::assertSame('added', $r['status']);
        self::assertSame('archive.example.com', $r['domain']);
        $rows = $this->domains->list();
        self::assertCount(1, $rows);
        self::assertSame('archive.example.com', $rows[0]['domain']);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame('acme', $rows[0]['slug']); // tenant slug joined in for the listing
    }

    public function test_add_rejects_underscore_traversal_single_label_and_overlength(): void
    {
        $t = $this->activeTenant('acme');
        $tooLong = implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 63)]) . '.com';
        self::assertSame(259, strlen($tooLong)); // grammar-legal labels, over the 253-byte host limit
        foreach ([
            'underscore' => 'my_site.example.com',
            'traversal' => '../secrets.example.com',
            'single label' => 'example',
            'over 253' => $tooLong,
        ] as $bad) {
            $r = $this->add($t, $bad);
            self::assertSame('error', $r['status'], "{$bad} must be rejected");
            self::assertNotSame('', $r['error'] ?? '');
        }
        self::assertSame([], $this->domains->list()); // nothing was claimed
    }

    public function test_plan_gate_reads_the_plan_not_the_tenant(): void
    {
        // 'standard' carries no custom_domains flag: rejected. The gate reads
        // the PLAN, so any tenant on a flagged plan (pro) is admitted.
        $r = $this->add($this->activeTenant('plain', 'standard'), 'plain.example.org');
        self::assertSame('error', $r['status']);
        self::assertStringContainsString('standard', $r['error']);
        $r = $this->add($this->activeTenant('fancy', 'pro'), 'fancy.example.org');
        self::assertSame('added', $r['status']);
    }

    public function test_each_collision_gets_its_own_error(): void
    {
        $acme = $this->activeTenant('acme');
        $this->add($acme, 'archive.example.com'); // the later double-claim target
        $bloc = $this->activeTenant('bloc');
        $errors = [
            'control host' => $this->add($bloc, 'control.saas.example.test')['error'],
            'existing tenant host' => $this->add($bloc, 'acme.saas.example.test')['error'],
            'own subdomain space' => $this->add($bloc, 'anything.saas.example.test')['error'],
            'double claim' => $this->add($bloc, 'archive.example.com')['error'],
        ];
        self::assertCount(4, array_unique($errors), 'the four collisions must read as four different mistakes');
        self::assertStringContainsString('control', $errors['control host']);
        self::assertStringContainsString('acme', $errors['existing tenant host']);
        self::assertStringContainsString('saas.example.test', $errors['own subdomain space']);
        self::assertStringContainsString('archive.example.com', $errors['double claim']);
    }

    public function test_verify_flips_pending_to_active_and_is_a_no_op_when_already_active(): void
    {
        $this->add($this->activeTenant('acme'), 'archive.example.com');
        $r = $this->domains->verify('archive.example.com');
        self::assertSame('verified', $r['status']);
        self::assertSame('active', $this->domains->list()[0]['status']);
        $r = $this->domains->verify('archive.example.com'); // second verify: success, not an error
        self::assertSame('verified', $r['status']);
        $r = $this->domains->verify('nobody.example.org');
        self::assertSame('error', $r['status']);
        self::assertStringContainsString('nobody.example.org', $r['error']);
    }

    public function test_remove_is_idempotent(): void
    {
        $this->add($this->activeTenant('acme'), 'archive.example.com');
        $this->domains->remove('archive.example.com');
        self::assertSame([], $this->domains->list());
        $this->domains->remove('archive.example.com'); // second remove: still fine
        self::assertSame([], $this->domains->list());
    }

    public function test_closing_a_tenant_releases_its_domain_for_reuse(): void
    {
        $acme = $this->activeTenant('acme');
        $this->add($acme, 'archive.example.com');
        $this->domains->verify('archive.example.com');
        foreach (['past_due' => ['grace_until' => '2026-02-01T00:00:00Z'],
                  'suspended' => ['purge_after' => '2026-03-01T00:00:00Z'],
                  'closed' => []] as $to => $extra) {
            $this->tenants->setStatus((int) $acme['id'], $to, $extra);
        }
        self::assertSame([], $this->domains->list()); // the claim rode along with the close
        $r = $this->add($this->activeTenant('bloc'), 'archive.example.com'); // reusable by the next tenant
        self::assertSame('added', $r['status']);
    }

    public function test_list_is_ordered_by_domain_and_carries_each_tenant_slug(): void
    {
        $acme = $this->activeTenant('acme');
        $bloc = $this->activeTenant('bloc');
        $this->add($bloc, 'zeta.example.net');
        $this->add($acme, 'alpha.example.com');
        $rows = $this->domains->list();
        self::assertSame(['alpha.example.com', 'zeta.example.net'], array_column($rows, 'domain'));
        self::assertSame(['acme', 'bloc'], array_column($rows, 'slug'));
    }
}
