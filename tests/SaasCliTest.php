<?php // tests/SaasCliTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\Registry;
use KipSaaS\Tenants;
use KipSaaS\Version;
use PHPUnit\Framework\TestCase;

final class SaasCliTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/saascli-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/data', 0777, true);
        mkdir($this->dir . '/tenants/old/app', 0777, true);
        mkdir($this->dir . '/tenants/grace/app', 0777, true);
        mkdir($this->dir . '/tenants/lapsed/app', 0777, true);
        foreach (['old', 'grace', 'lapsed'] as $slug) {
            file_put_contents($this->dir . "/tenants/{$slug}/app/data.sqlite", '');
        }
        $config = str_replace('__DIR__', var_export($this->dir, true), <<<'PHP'
<?php
final class SaasCliFixtureApp implements KipSaaS\TenantAppInterface
{
    public function createOwner(\PDO $tenantDb, array $tenant): string
    {
        $otp = base64_encode(random_bytes(24));
        $tenantDb->prepare("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at)
            VALUES (?, ?, 'Owner', 'admin', 1, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))")
            ->execute([(string) $tenant['owner_email'], password_hash($otp, PASSWORD_DEFAULT)]);
        return $otp;
    }
    public function seedCommand(): string { return 'db:seed-fake'; }
}
return [
    'env' => 'dev',
    'registry_dsn' => 'sqlite:' . __DIR__ . '/data/registry.sqlite',
    'tenants_root' => __DIR__ . '/tenants',
    'code_source' => __DIR__ . '/fakecode',
    'base_domain' => 'saas.example.test',
    'grace_days' => 7, 'retention_days' => 30,
    'token_secret' => 'x',
    'stripe_webhook_secret' => 'whsec_fixture',
    'tenant_app' => new SaasCliFixtureApp(),
    'mail' => ['transport' => 'log', 'log_path' => __DIR__ . '/data/mail.log', 'from' => 'noreply@saas.example.test'],
    'plans' => ['standard' => ['powered_by' => true]],
    'nginx' => ['map_file' => __DIR__ . '/tenants.map', 'tenants_root' => __DIR__ . '/tenants', 'empty_root' => '/srv/e',
                'control_public_root' => '/srv/c', 'control_host' => 'control.saas.example.test', 'reload' => false],
];
PHP);
        file_put_contents($this->dir . '/config.php', $config);
        mkdir($this->dir . '/fakecode/bin', 0777, true);
        mkdir($this->dir . '/fakecode/app', 0777, true);
        file_put_contents($this->dir . '/fakecode/bin/kip', <<<'PHP'
#!/usr/bin/env php
<?php
if (($argv[1] ?? '') === 'migrate') {
    $pdo = new PDO('sqlite:' . dirname(__DIR__) . '/app/data.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL, penname TEXT, role TEXT, is_admin INTEGER, email_verified_at TEXT, approved_at TEXT)');
}
exit(0);
PHP);
        chmod($this->dir . '/fakecode/bin/kip', 0755);
        file_put_contents($this->dir . '/fakecode/config.php', "<?php\nreturn ['site_name' => 'Source'];\n");
        // Registry rows: 'old' is past grace AND past purge; 'grace' is mid-grace;
        // 'lapsed' is past grace and due for suspension.
        $tenants = new Tenants((new Registry('sqlite:' . $this->dir . '/data/registry.sqlite'))->pdo());
        $old = $tenants->create('old', 'old.saas.example.test', 'standard', 'o@e.test', 'Old', '', '');
        $tenants->setStatus($old, 'verified');
        $tenants->setStatus($old, 'active');
        $tenants->setStatus($old, 'past_due', ['grace_until' => '2026-01-01T00:00:00Z']);
        $tenants->setStatus($old, 'suspended', ['purge_after' => '2026-01-02T00:00:00Z']);
        $grace = $tenants->create('grace', 'grace.saas.example.test', 'standard', 'g@e.test', 'Grace', '', '');
        $tenants->setStatus($grace, 'verified');
        $tenants->setStatus($grace, 'active');
        $tenants->setStatus($grace, 'past_due', ['grace_until' => '2099-01-01T00:00:00Z']);
        $lapsed = $tenants->create('lapsed', 'lapsed.saas.example.test', 'standard', 'l@e.test', 'Lapsed', '', '');
        $tenants->setStatus($lapsed, 'verified');
        $tenants->setStatus($lapsed, 'active');
        $tenants->setStatus($lapsed, 'past_due', ['grace_until' => '2026-01-01T00:00:00Z']);
        putenv('SAAS_CONFIG=' . $this->dir . '/config.php');
    }

    private function saas(string ...$args): string
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/saas')
            . ' ' . implode(' ', array_map(escapeshellarg(...), $args)) . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        return implode("\n", $out);
    }

    public function test_map_write_publishes_and_tenants_list_reports_routing(): void
    {
        $out = $this->saas('map:write');
        self::assertSame('', $out); // map:write is silent on success, like a cron job should be
        $map = (string) file_get_contents($this->dir . '/tenants.map');
        self::assertStringContainsString('control.saas.example.test /srv/c', $map);
        $out = $this->saas('tenants:list');
        self::assertStringContainsString('grace', $out);
        self::assertStringContainsString('past_due', $out);
        self::assertStringContainsString('suspended', $out);
        self::assertMatchesRegularExpression('/grace\.saas\.example\.test\s+standard routed/', $out);
        self::assertMatchesRegularExpression('/old\.saas\.example\.test\s+standard -/', $out);
    }

    public function test_purge_due_suspends_and_purges_and_republishes_the_map(): void
    {
        $out = $this->saas('purge:due');
        self::assertStringContainsString('purged old', $out);
        self::assertStringContainsString('suspended lapsed', $out);
        self::assertFileDoesNotExist($this->dir . '/tenants/old');
        self::assertFileExists($this->dir . '/tenants/grace'); // mid-grace untouched
        self::assertFileExists($this->dir . '/tenants/lapsed/app/maintenance.lock'); // the suspension took hold
        $tenants = new Tenants((new Registry('sqlite:' . $this->dir . '/data/registry.sqlite'))->pdo());
        self::assertSame('closed', $tenants->bySlug('old')['status']);
        self::assertSame('past_due', $tenants->bySlug('grace')['status']);
        self::assertSame('suspended', $tenants->bySlug('lapsed')['status']);
        self::assertNotNull($tenants->bySlug('lapsed')['purge_after']);
        $map = (string) file_get_contents($this->dir . '/tenants.map');
        self::assertStringNotContainsString('old.saas.example.test', $map);  // closed: dropped from routing
        self::assertStringContainsString('grace.saas.example.test', $map);   // mid-grace: still routed
        self::assertStringContainsString('lapsed.saas.example.test /srv/c', $map); // suspended: notice root
        self::assertStringContainsString('control.saas.example.test', $map); // the control host always routes
    }

    public function test_manual_invoice_path_creates_and_provisions(): void
    {
        $out = $this->saas('tenant:create', 'acme', 'standard', 'ow@example.test', 'Acme Site');
        self::assertStringContainsString('recorded as active', $out);
        self::assertStringContainsString('saas provision:tenant acme', $out);
        $out = $this->saas('provision:tenant', 'acme');
        self::assertStringContainsString('provisioned acme', $out);
        self::assertStringContainsString('One-time password', $out); // no SMTP in the fixture: out-of-band handover
        self::assertFileExists($this->dir . '/tenants/acme/config.php');
        $pdo = new \PDO('sqlite:' . $this->dir . '/tenants/acme/app/data.sqlite');
        self::assertSame('ow@example.test', $pdo->query("SELECT email FROM users WHERE role = 'admin'")->fetchColumn());
        $map = (string) file_get_contents($this->dir . '/tenants.map');
        self::assertStringContainsString('acme.saas.example.test', $map);
    }

    public function test_doctor_checks_the_engine_side_and_prints_the_stack(): void
    {
        $out = $this->saas('doctor');
        self::assertStringContainsString('ok  registry reachable', $out);
        self::assertStringContainsString('ok  code_source present', $out);
        self::assertStringContainsString('ok  map path writable', $out);
        self::assertStringContainsString('ok  webhook secret set', $out);
        self::assertStringContainsString('ok  control host free', $out);
        self::assertStringContainsString('kipsaas ', $out);
        self::assertStringContainsString('kip/framework ', $out);
    }

    public function test_doctor_fails_when_a_tenant_squats_the_control_host(): void
    {
        // Two map lines for one host is an invalid nginx map; the doctor must
        // catch the collision before nginx -t does, at reload time.
        $tenants = new Tenants((new Registry('sqlite:' . $this->dir . '/data/registry.sqlite'))->pdo());
        $id = $tenants->create('squatter', 'control.saas.example.test', 'standard', 's@e.test', 'Squatter', '', '');
        $tenants->setStatus($id, 'verified');
        $tenants->setStatus($id, 'active');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/saas')
            . ' doctor 2>&1', $out, $code);
        self::assertSame(1, $code);
        self::assertStringContainsString('FAIL  control host free', implode("\n", $out));
    }

    public function test_version_reads_the_real_lock_for_the_framework_pin(): void
    {
        $out = $this->saas('version');
        self::assertStringContainsString('kipsaas ', $out);
        self::assertMatchesRegularExpression('/^kip\/framework (dev-[a-z]+@[0-9a-f]{12}|\d+\.\d+\.\d+)$/m', $out);
    }

    public function test_lock_parse_renders_git_path_and_tag_pins(): void
    {
        $git = ['packages' => [['name' => 'kip/framework', 'version' => 'dev-main',
            'source' => ['type' => 'git', 'reference' => '2979b924372eaaec4f48b4fe11337e3362cdf45b']]]];
        $path = ['packages' => [['name' => 'kip/framework', 'version' => 'dev-main',
            'dist' => ['type' => 'path', 'url' => '/Users/x/Sites/kip']]]];
        $tag = ['packages-dev' => [['name' => 'kip/framework', 'version' => '0.5.0',
            'source' => ['type' => 'git', 'reference' => 'abc123def4567890']]]];
        $dir = sys_get_temp_dir() . '/ver-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (['git' => $git, 'path' => $path, 'tag' => $tag] as $name => $lock) {
            file_put_contents($dir . '/' . $name . '.lock', (string) json_encode($lock));
        }
        self::assertSame('dev-main@2979b924372e', Version::lockedRef($dir . '/git.lock', 'kip/framework'));
        self::assertSame('dev-main (/Users/x/Sites/kip)', Version::lockedRef($dir . '/path.lock', 'kip/framework'));
        self::assertSame('0.5.0', Version::lockedRef($dir . '/tag.lock', 'kip/framework')); // packages-dev is scanned too; a tag prints the tag
        self::assertNull(Version::lockedRef($dir . '/missing.lock', 'kip/framework'));
        self::assertNull(Version::lockedRef($dir . '/tag.lock', 'some/other-package'));
        $this->rmTree($dir);
    }

    protected function tearDown(): void
    {
        putenv('SAAS_CONFIG');
        $this->rmTree($this->dir);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $ri = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $item) {
            $item->isDir() ? @rmdir((string) $item->getPathname()) : @unlink((string) $item->getPathname());
        }
        @rmdir($dir);
    }
}
