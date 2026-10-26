<?php // tests/SaasDomainServiceTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\CfClient;
use KipSaaS\CfError;
use KipSaaS\DomainService;
use KipSaaS\Registry;
use KipSaaS\Tenants;
use PHPUnit\Framework\TestCase;

/**
 * Custom domains, two surfaces. Manual white-glove: no TXT challenge, no
 * pending TTL, the operator confirms DNS by hand and flips the claim live,
 * so the tests cover the operator surface: claim grammar, plan gate,
 * collisions, the verify flip, idempotent removal, and release on close.
 * They also cover the Cloudflare-backed automation: claim register, verify,
 * and remove, release on close, and the fail-closed refusals.
 */
final class SaasDomainServiceTest extends TestCase
{
    private \PDO $pdo;
    private Tenants $tenants;
    private DomainService $domains;
    /** @var array<string,mixed> */
    private array $config;

    protected function setUp(): void
    {
        $dsn = 'sqlite:' . sys_get_temp_dir() . '/domains-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->pdo = (new Registry($dsn))->pdo();
        $this->tenants = new Tenants($this->pdo);
        $this->config = [
            'base_domain' => 'saas.example.test',
            'nginx' => ['control_host' => 'control.saas.example.test'],
            'plans' => [
                'standard' => ['label' => 'Standard'],                       // no custom_domains flag
                'pro' => ['label' => 'Pro', 'custom_domains' => true],
            ],
        ];
        $this->domains = new DomainService($this->pdo, $this->config);
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

    public function test_an_existing_registry_gains_the_cf_hostname_column_on_open(): void
    {
        $dsn = 'sqlite:' . sys_get_temp_dir() . '/domains-legacy-' . bin2hex(random_bytes(4)) . '.sqlite';
        $raw = new \PDO($dsn);
        $raw->exec("CREATE TABLE tenant_domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            domain TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
        )"); // the pre-automation shape, live in every deployed registry
        $pdo = (new Registry($dsn))->pdo(); // opening migrates
        $cols = $pdo->query('PRAGMA table_info(tenant_domains)')->fetchAll(\PDO::FETCH_COLUMN, 1);
        self::assertContains('cf_hostname_id', $cols);
    }

    /** DomainService in CNAME mode: a real CfClient over scripted responses. */
    private function automated(RecordingCfHttp $http): DomainService
    {
        return new DomainService($this->pdo, $this->config, new CfClient($http, 'zone1'));
    }

    /** The queue pair one automated add consumes: the empty lookup, then the create. */
    private function addScript(string $id): array
    {
        return [
            ['success' => true, 'result' => []],
            ['success' => true, 'result' => ['id' => $id, 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']]],
        ];
    }

    public function test_automated_add_registers_the_hostname_and_stores_its_id(): void
    {
        $http = new RecordingCfHttp($this->addScript('ch1'));
        $r = $this->automated($http)->add($this->activeTenant('acme'), 'archive.example.com');
        self::assertSame('added', $r['status']);
        self::assertSame('ch1', $r['cf']);
        $row = $this->pdo->query("SELECT status, cf_hostname_id FROM tenant_domains WHERE domain = 'archive.example.com'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('pending', $row['status']);
        self::assertSame('ch1', $row['cf_hostname_id']);
        self::assertSame(['hostname' => 'archive.example.com', 'ssl' => ['method' => 'http', 'type' => 'dv']], $http->calls[1]['json']);
    }

    public function test_a_refused_claim_makes_no_cloudflare_call(): void
    {
        // S1: the plan gate runs before anything touches the API, so a
        // refused claim can never leave a custom-hostname object behind it.
        $http = new RecordingCfHttp([]);
        $r = $this->automated($http)->add($this->activeTenant('plain', 'standard'), 'plain.example.org');
        self::assertSame('error', $r['status']);
        self::assertSame([], $http->calls);
    }

    public function test_manual_add_leaves_the_claim_operator_verified(): void
    {
        $http = new RecordingCfHttp([]);
        $r = $this->automated($http)->add($this->activeTenant('acme'), 'archive.example.com', true);
        self::assertSame('added', $r['status']);
        self::assertArrayNotHasKey('cf', $r);
        self::assertSame([], $http->calls);
        self::assertNull($this->pdo->query("SELECT cf_hostname_id FROM tenant_domains WHERE domain = 'archive.example.com'")->fetchColumn());
    }

    public function test_the_base_zone_apex_is_a_manual_claim(): void
    {
        // The apex IS the SaaS zone; its TLS is the zone's own. Flagship
        // (the brand apex) still claims it, with --manual.
        $http = new RecordingCfHttp([]);
        $r = $this->automated($http)->add($this->activeTenant('acme'), 'saas.example.test');
        self::assertSame('error', $r['status']);
        self::assertStringContainsString('--manual', $r['error']);
        self::assertSame([], $http->calls);
        $r = $this->automated(new RecordingCfHttp())->add($this->activeTenant('flagship'), 'saas.example.test', true);
        self::assertSame('added', $r['status']);
    }

    public function test_automated_verify_flips_only_when_hostname_and_certificate_are_both_active(): void
    {
        $this->automated(new RecordingCfHttp($this->addScript('ch1')))
            ->add($this->activeTenant('acme'), 'archive.example.com');
        $status = fn(): string => (string) $this->pdo->query("SELECT status FROM tenant_domains WHERE domain = 'archive.example.com'")->fetchColumn();

        // GET responses arrive as a list even filtered to one row (the eng-review fixture fix)
        $pending = ['success' => true, 'result' => [['id' => 'ch1', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']]]];
        $certPending = ['success' => true, 'result' => [['id' => 'ch1', 'status' => 'active', 'ssl' => ['status' => 'pending_validation']]]];
        $ready = ['success' => true, 'result' => [['id' => 'ch1', 'status' => 'active', 'ssl' => ['status' => 'active']]]];

        // S2: DNS control is the proof. The certificate only issues once the
        // customer's CNAME answers, so anything short of active plus active
        // fails closed with the row still pending.
        $r = $this->automated(new RecordingCfHttp([$pending]))->verify('archive.example.com');
        self::assertSame('error', $r['status']);
        self::assertSame('pending', $status());

        $r = $this->automated(new RecordingCfHttp([$certPending]))->verify('archive.example.com');
        self::assertSame('error', $r['status']);
        self::assertStringContainsString('certificate', $r['error']);
        self::assertSame('pending', $status());

        $r = $this->automated(new RecordingCfHttp([$ready]))->verify('archive.example.com');
        self::assertSame('verified', $r['status']);
        self::assertSame('active', $status());
    }

    public function test_automated_verify_fails_closed_when_the_object_is_gone_or_the_api_errors(): void
    {
        $this->automated(new RecordingCfHttp($this->addScript('ch1')))
            ->add($this->activeTenant('acme'), 'archive.example.com');

        $r = $this->automated(new RecordingCfHttp([['success' => true, 'result' => []]]))->verify('archive.example.com');
        self::assertSame('error', $r['status']);
        self::assertStringContainsString('no custom hostname', $r['error']);

        $r = $this->automated(new RecordingCfHttp([new CfError(500, 'boom')]))->verify('archive.example.com');
        self::assertSame('error', $r['status']);
        self::assertStringContainsString('cloudflare 500', $r['error']);
        self::assertSame('pending', (string) $this->pdo->query("SELECT status FROM tenant_domains WHERE domain = 'archive.example.com'")->fetchColumn());
    }

    public function test_a_manual_claim_verifies_by_operator_confirmation_even_with_automation_configured(): void
    {
        $http = new RecordingCfHttp([]);
        $svc = $this->automated($http);
        $svc->add($this->activeTenant('acme'), 'archive.example.com', true);
        self::assertSame('verified', $svc->verify('archive.example.com')['status']);
        self::assertSame([], $http->calls); // the mode is the row's, not the service's
    }

    public function test_automated_remove_retires_the_edge_object_first_and_fails_closed_on_error(): void
    {
        $acme = $this->activeTenant('acme');
        $created = ['success' => true, 'result' => ['id' => 'ch1', 'status' => 'active', 'ssl' => ['status' => 'active']]];
        $listed = ['success' => true, 'result' => [['id' => 'ch1', 'status' => 'active', 'ssl' => ['status' => 'active']]]];

        $this->automated(new RecordingCfHttp([['success' => true, 'result' => []], $created]))
            ->add($acme, 'archive.example.com');

        $ok = $this->automated(new RecordingCfHttp([$listed, ['success' => true, 'result' => ['id' => 'ch1']]]));
        self::assertSame('removed', $ok->remove('archive.example.com')['status']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_domains WHERE domain = 'archive.example.com'")->fetchColumn());

        $this->automated(new RecordingCfHttp([['success' => true, 'result' => []], $created]))
            ->add($acme, 'archive.example.com'); // claim it again for the failure half
        // S3: Cloudflare first, registry second; the claim survives a
        // refused delete so the operator retries rather than orphaning an
        // edge certificate for a hostname we no longer route.
        $r = $this->automated(new RecordingCfHttp([new CfError(500, 'boom')]))->remove('archive.example.com');
        self::assertSame('error', $r['status']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_domains WHERE domain = 'archive.example.com'")->fetchColumn());
    }

    public function test_remove_of_an_unknown_domain_still_retires_an_orphaned_edge_object(): void
    {
        $http = new RecordingCfHttp([
            ['success' => true, 'result' => [['id' => 'ch1', 'status' => 'active', 'ssl' => ['status' => 'active']]]],
            ['success' => true, 'result' => ['id' => 'ch1']],
        ]);
        $r = $this->automated($http)->remove('archive.example.com');
        self::assertSame('removed', $r['status']);
        self::assertSame('DELETE', $http->calls[1]['method']);
    }

    public function test_closing_releases_every_claim_and_collects_cloudflare_failures_as_warnings(): void
    {
        $acme = $this->activeTenant('acme');
        $svc = $this->automated(new RecordingCfHttp([
            ...$this->addScript('ch1'), ...$this->addScript('ch2'),
        ]));
        $svc->add($acme, 'one.example.com');
        $svc->add($acme, 'two.example.com');

        // S3: the registry release is authoritative and cannot be blocked by
        // an API outage; the refusal comes back as a warning, and the runbook
        // line it feeds (domain:remove) is the cleanup.
        $rel = $this->automated(new RecordingCfHttp([
            ['success' => true, 'result' => [['id' => 'ch1', 'status' => 'active', 'ssl' => ['status' => 'active']]]],
            ['success' => true, 'result' => ['id' => 'ch1']], // one.example.com deletes fine
            new CfError(500, 'boom'),                          // two.example.com refuses
        ]))->removeForTenant((int) $acme['id']);
        self::assertSame(2, $rel['released']);
        self::assertSame(['two.example.com'], $rel['orphans']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM tenant_domains')->fetchColumn());

        // R1 fold (eng review): automation unwired at close. The claim still
        // releases, and its unretired edge object surfaces instead of
        // vanishing silently.
        $this->automated(new RecordingCfHttp($this->addScript('ch3')))
            ->add($acme, 'three.example.com');
        $rel = $this->domains->removeForTenant((int) $acme['id']); // setUp's service: no client wired
        self::assertSame(['three.example.com'], $rel['orphans']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM tenant_domains')->fetchColumn());
    }

    public function test_a_cname_mode_claim_refuses_verify_when_the_client_is_unwired(): void
    {
        // Eng-review fold (F-S3): the row carries the mode, so an automated
        // claim must never fall back to the operator-confirmation flip just
        // because the service lost its client.
        $this->automated(new RecordingCfHttp($this->addScript('ch1')))
            ->add($this->activeTenant('acme'), 'archive.example.com');
        $r = $this->domains->verify('archive.example.com'); // setUp's service: no client
        self::assertSame('error', $r['status']);
        self::assertStringContainsString('no cloudflare client', $r['error']);
        self::assertSame('pending', (string) $this->pdo->query("SELECT status FROM tenant_domains WHERE domain = 'archive.example.com'")->fetchColumn());
    }

    public function test_a_cname_mode_claim_refuses_remove_when_the_client_is_unwired(): void
    {
        // The remove-side mirror of the pin above: without a client, a
        // CNAME-mode claim must fail closed rather than degrade to the old
        // no-op delete, or a config-lost operator's remove would release the
        // claim while its edge certificate survives unreported.
        $this->automated(new RecordingCfHttp($this->addScript('ch1')))
            ->add($this->activeTenant('acme'), 'archive.example.com');
        $r = $this->domains->remove('archive.example.com'); // setUp's service: no client
        self::assertSame('error', $r['status']);
        self::assertStringContainsString('no cloudflare client', $r['error']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM tenant_domains WHERE domain = 'archive.example.com'")->fetchColumn()); // fail closed: the claim survives so the operator retries with config present
    }

    public function test_a_suspended_tenants_custom_domain_finds_its_tenant_at_the_control_root(): void
    {
        $acme = $this->activeTenant('acme');
        $svc = $this->domains; // manual mode is enough: the lookup is registry-side
        $svc->add($acme, 'archive.example.com');
        $svc->verify('archive.example.com');
        self::assertNull($this->tenants->byHostOrDomain('archive.example.com')); // active tenant: not the control root's business yet
        $this->tenants->setStatus((int) $acme['id'], 'past_due', ['grace_until' => '2026-02-01T00:00:00Z']);
        $this->tenants->setStatus((int) $acme['id'], 'suspended', ['purge_after' => '2026-03-01T00:00:00Z']);
        $found = $this->tenants->byHostOrDomain('archive.example.com');
        self::assertNotNull($found);
        self::assertSame('acme', $found['slug']);
        self::assertSame('suspended', $found['status']);
        self::assertSame('acme', $this->tenants->byHostOrDomain('acme.saas.example.test')['slug']); // primary hosts still match
        self::assertNull($this->tenants->byHostOrDomain('nobody.example.org')); // unknown stays unknown
    }
}
