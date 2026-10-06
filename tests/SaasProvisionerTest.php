<?php // tests/SaasProvisionerTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\ReferenceProvisioner;
use KipSaaS\TenantAppInterface;
use PHPUnit\Framework\TestCase;

/** The consuming app's whole obligation, faked: an owner row plus a seed command. */
final class FakeTenantApp implements TenantAppInterface
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

final class SaasProvisionerTest extends TestCase
{
    private string $root;
    private string $code;
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $mails = [];

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/prov-' . bin2hex(random_bytes(4));
        mkdir($root . '/tenants', 0777, true);
        $this->root = $root;
        $this->code = $root . '/fakecode';
        $this->makeFakeInstall($this->code);
        $this->mails = [];
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);
    }

    private function makeFakeInstall(string $dir): void
    {
        mkdir($dir . '/bin', 0777, true);
        mkdir($dir . '/app', 0777, true);
        mkdir($dir . '/public', 0777, true);
        file_put_contents($dir . '/bin/kip', <<<'PHP'
#!/usr/bin/env php
<?php
// Minimal stand-in for the app CLI: migrate builds the table the owner
// insert writes into; every other command (including the seed) succeeds.
if (($argv[1] ?? '') === 'migrate') {
    $pdo = new PDO('sqlite:' . dirname(__DIR__) . '/app/data.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL, penname TEXT, role TEXT, is_admin INTEGER, email_verified_at TEXT, approved_at TEXT)');
}
exit(0);
PHP);
        chmod($dir . '/bin/kip', 0755);
        file_put_contents($dir . '/config.php', "<?php\nreturn ['site_name' => 'Source', 'rate_limit' => ['auth' => ['max' => 10, 'window' => 60]], 'features' => ['news' => true]];\n");
    }

    private function provisioner(?string $codeSource = null, ?array $plans = null): ReferenceProvisioner
    {
        $config = [
            'tenants_root' => $this->root . '/tenants',
            'code_source' => $codeSource ?? $this->code,
            'base_domain' => 'saas.example.test',
            'tenant_smtp' => ['host' => 'smtp.test', 'port' => 587, 'username' => 'u', 'password' => 'p', 'from' => 'sites@saas.example.test'],
            'plans' => $plans ?? ['standard' => ['powered_by' => true], 'pro' => ['powered_by' => false]],
        ];
        return new ReferenceProvisioner($config, function (string $to, string $s, string $b): void {
            $this->mails[] = ['to' => $to, 'subject' => $s, 'body' => $b];
        }, new FakeTenantApp());
    }

    private function tenant(string $slug = 'acme', string $plan = 'standard'): array
    {
        return ['id' => 1, 'slug' => $slug, 'host' => "{$slug}.saas.example.test", 'plan' => $plan,
            'title' => 'Acme Site', 'owner_email' => 'ow@example.test', 'status' => 'active'];
    }

    public function test_provision_stamps_config_migrates_seeds_and_creates_owner(): void
    {
        $this->provisioner()->provision($this->tenant());
        $dir = $this->root . '/tenants/acme';
        self::assertFileExists($dir . '/config.php');
        self::assertFileDoesNotExist($dir . '/config.php.bak');
        $rendered = (string) file_get_contents($dir . '/config.php');
        self::assertStringContainsString("'base_url' => 'https://acme.saas.example.test'", $rendered);
        self::assertStringContainsString("'site_name' => 'Acme Site'", $rendered);
        self::assertStringContainsString("'transport' => 'smtp'", $rendered);
        self::assertStringContainsString("'powered_by' => true", $rendered);
        self::assertSame('0600', substr(sprintf('%o', fileperms($dir . '/config.php')), -4));
        self::assertIsArray(require $dir . '/config.php'); // the rendered file must parse, not merely look right
        self::assertSame(getmyuid(), fileowner($dir . '/config.php')); // whoever stamps it owns it

        $pdo = new \PDO('sqlite:' . $dir . '/app/data.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $owner = $pdo->query('SELECT email, role, is_admin FROM users')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('ow@example.test', $owner['email']);
        self::assertSame('admin', $owner['role']);
        self::assertSame(1, (int) $owner['is_admin']);

        self::assertCount(1, $this->mails);
        self::assertSame('ow@example.test', $this->mails[0]['to']);
        self::assertStringContainsString('https://acme.saas.example.test', $this->mails[0]['body']);
    }

    public function test_runtime_state_is_never_copied_from_the_code_source(): void
    {
        // The excludes are the portability promise: a stamped tenant is a
        // fresh install, never the template's data, cache, or secrets.
        file_put_contents($this->code . '/app/data.sqlite', 'template data');
        file_put_contents($this->code . '/app/nav.json', 'template nav');
        mkdir($this->code . '/public/cache', 0777, true);
        file_put_contents($this->code . '/public/cache/page.html', 'template page');
        $this->provisioner()->provision($this->tenant());
        $dir = $this->root . '/tenants/acme';
        self::assertFileDoesNotExist($dir . '/app/nav.json');
        self::assertFileDoesNotExist($dir . '/public/cache');
        $pdo = new \PDO('sqlite:' . $dir . '/app/data.sqlite');
        self::assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM users WHERE email != ' . $pdo->quote('ow@example.test'))->fetchColumn());
    }

    public function test_pro_plan_hides_the_powered_by_link(): void
    {
        $this->provisioner()->provision($this->tenant(plan: 'pro'));
        $rendered = (string) file_get_contents($this->root . '/tenants/acme/config.php');
        self::assertStringContainsString("'powered_by' => false", $rendered);
    }

    public function test_provision_without_smtp_hands_back_a_working_one_time_password(): void
    {
        $cfg = ['tenants_root' => $this->root . '/tenants', 'code_source' => $this->code,
            'base_domain' => 'saas.example.test', 'tenant_smtp' => null,
            'plans' => ['standard' => ['powered_by' => true]]];
        $result = (new ReferenceProvisioner($cfg, fn() => null, new FakeTenantApp()))->provision($this->tenant());
        self::assertFalse($result['mail_sent']);
        self::assertNotEmpty($result['one_time_password']);
        $pdo = new \PDO('sqlite:' . $this->root . '/tenants/acme/app/data.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $hash = (string) $pdo->query("SELECT password_hash FROM users WHERE email = 'ow@example.test'")->fetchColumn();
        self::assertTrue(password_verify((string) $result['one_time_password'], $hash));
        // And the tenant never shares the template's mail.log path:
        $rendered = (string) file_get_contents($this->root . '/tenants/acme/config.php');
        self::assertStringContainsString($this->root . '/tenants/acme/app/mail.log', $rendered);
    }

    public function test_provision_is_refused_twice(): void
    {
        $p = $this->provisioner();
        $p->provision($this->tenant());
        $this->expectException(\RuntimeException::class);
        $p->provision($this->tenant());
    }

    public function test_smtp_mode_mails_a_one_time_password_that_actually_verifies(): void
    {
        // The SMTP path cannot hand the password over out of band (the caller
        // is a webhook), so the welcome mail must carry it; a mail that only
        // says "use the password reset" strands owners of apps without a
        // reset flow (the demo ships none).
        $this->provisioner()->provision($this->tenant());
        self::assertCount(1, $this->mails);
        self::assertMatchesRegularExpression('/One-time sign-in password: (\S+)/', $this->mails[0]['body']);
        preg_match('/One-time sign-in password: (\S+)/', $this->mails[0]['body'], $m);
        $pdo = new \PDO('sqlite:' . $this->root . '/tenants/acme/app/data.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $hash = (string) $pdo->query("SELECT password_hash FROM users WHERE email = 'ow@example.test'")->fetchColumn();
        self::assertTrue(password_verify($m[1], $hash), 'the mailed password must open the owner account');
    }

    public function test_suspend_resume_and_purge_round_trip(): void
    {
        $p = $this->provisioner();
        $p->provision($this->tenant());
        $p->suspend($this->tenant());
        self::assertFileExists($this->root . '/tenants/acme/app/maintenance.lock');
        $p->resume($this->tenant());
        self::assertFileDoesNotExist($this->root . '/tenants/acme/app/maintenance.lock');
        $p->suspend($this->tenant());
        $p->purge($this->tenant());
        self::assertFileDoesNotExist($this->root . '/tenants/acme');
    }

    public function test_app_failure_aborts_provisioning(): void
    {
        $t = $this->tenant();
        try {
            // A stub whose CLI always exits 1: every provisioning step fails.
            $bad = $this->root . '/badcode';
            $this->makeFakeInstall($bad);
            file_put_contents($bad . '/bin/kip', "#!/usr/bin/env php\n<?php exit(1);\n");
            chmod($bad . '/bin/kip', 0755);
            $cfg = ['tenants_root' => $this->root . '/tenants', 'code_source' => $bad, 'base_domain' => 'saas.example.test',
                'tenant_smtp' => null, 'plans' => ['standard' => ['powered_by' => true]]];
            (new ReferenceProvisioner($cfg, fn() => null, new FakeTenantApp()))->provision($t);
            self::fail('expected migration failure to abort');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('kip', $e->getMessage());
        }
        // Nothing half-built may survive: the tenant dir is gone so a retry starts clean.
        self::assertFileDoesNotExist($this->root . '/tenants/acme');
    }

    public function test_symlinked_vendor_dirs_stamp_as_real_files_and_the_tenant_autoloads(): void
    {
        // The dev-checkout shape: a composer path repo makes
        // vendor/kip/framework a SYMLINK to a framework clone. The stamp must
        // dereference it into real files (a copied link would point outside
        // the tenant) and skip the clone's nested .git wherever it sits.
        $framework = $this->root . '/framework-clone';
        mkdir($framework . '/src', 0777, true);
        file_put_contents($framework . '/src/App.php', "<?php\nnamespace Kip;\nfinal class App {}\n");
        mkdir($framework . '/.git', 0777, true);
        file_put_contents($framework . '/.git/HEAD', 'ref: refs/heads/main');
        symlink($framework, $framework . '/loop'); // a pathological link cycle must not hang the copy

        $code = $this->root . '/vendoredcode';
        $this->makeFakeInstall($code);
        mkdir($code . '/vendor/kip', 0777, true);
        symlink($framework, $code . '/vendor/kip/framework');
        // A real (non-link) nested package carrying its own VCS state: the
        // per-segment excludes must catch this one too, at any depth.
        mkdir($code . '/vendor/other/pkg/.git', 0777, true);
        file_put_contents($code . '/vendor/other/pkg/lib.php', "<?php\n");
        file_put_contents($code . '/vendor/other/pkg/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents($code . '/vendor/autoload.php', "<?php require __DIR__ . '/kip/framework/src/App.php';\n");
        file_put_contents($code . '/bin/kip', <<<'PHP'
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';
if (($argv[1] ?? '') === 'migrate') {
    $pdo = new PDO('sqlite:' . dirname(__DIR__) . '/app/data.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL, penname TEXT, role TEXT, is_admin INTEGER, email_verified_at TEXT, approved_at TEXT)');
}
exit(class_exists('Kip\App') ? 0 : 1); // every invocation proves the stamped vendor loads Kip classes
PHP);
        chmod($code . '/bin/kip', 0755);

        $this->provisioner(codeSource: $code)->provision($this->tenant());
        $dir = $this->root . '/tenants/acme';
        $fw = $dir . '/vendor/kip/framework';
        self::assertFileExists($fw . '/src/App.php');
        self::assertNotTrue(is_link($fw), 'the stamped vendor must be a real directory, not a copied link');
        self::assertNotTrue(is_link($fw . '/src/App.php'));
        self::assertFileExists($dir . '/vendor/autoload.php');
        self::assertFileDoesNotExist($fw . '/.git');   // nested VCS state never stamps
        self::assertFileDoesNotExist($fw . '/loop');   // a cycle link is not followed
        self::assertFileExists($dir . '/vendor/other/pkg/lib.php'); // real nested packages still copy
        self::assertFileDoesNotExist($dir . '/vendor/other/pkg/.git');
        foreach ($this->findDirsNamed($dir, '.git') as $stray) self::assertFileDoesNotExist($stray);

        // The tenant's own kip CLI must autoload the framework from the stamp.
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/bin/kip') . ' migrate 2>&1', $out, $codeOut);
        self::assertSame(0, $codeOut, implode("\n", $out));
    }

    public function test_every_non_price_plan_flag_crosses_into_the_rendered_config(): void
    {
        $plans = [
            'standard' => ['price_id' => 'price_1', 'label' => 'Standard', 'amount_month' => 900,
                'storage_gb' => 2, 'powered_by' => true, 'note_cap' => 10],
            'pro' => ['price_id' => 'price_2', 'label' => 'Pro', 'amount_month' => 1900,
                'storage_gb' => 10, 'powered_by' => false, 'note_cap' => 100],
        ];
        $this->provisioner(plans: $plans)->provision($this->tenant());
        $cfg = require $this->root . '/tenants/acme/config.php';
        self::assertSame(['label' => 'Standard', 'storage_gb' => 2, 'note_cap' => 10], $cfg['plan_flags']);

        $this->provisioner(plans: $plans)->provision($this->tenant(slug: 'procorp', plan: 'pro'));
        $cfg = require $this->root . '/tenants/procorp/config.php';
        self::assertSame(['label' => 'Pro', 'storage_gb' => 10, 'note_cap' => 100], $cfg['plan_flags']);
        self::assertFalse($cfg['powered_by']); // the billing-side flag stays where it was
    }

    private function findDirsNamed(string $dir, string $name): array
    {
        $found = [];
        $ri = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($ri as $item) {
            if ($item->isDir() && $item->getFilename() === $name) $found[] = (string) $item->getPathname();
        }
        return $found;
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
