<?php // tests/SaasPackageModeTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The composer-package consumption shape: a fixture app whose vendor/
 * carries the kit as a symlinked package and whose public/index.php is
 * the SHIPPED file, lifted verbatim the way the README's package-mode
 * quickstart lifts it. The shipped front controller must resolve config
 * (and, once view_dir/lang_dir point at them, the lifted branding dirs)
 * against the APP root, never against the package directory in vendor/.
 */
final class SaasPackageModeTest extends TestCase
{
    private string $kit;
    private string $app;
    /** @var list<resource> */
    private array $procs = [];
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        $this->kit = dirname(__DIR__);
        $this->app = $this->scratch();
        mkdir($this->app . '/data', 0777, true);
        mkdir($this->app . '/tenants', 0777, true);
        // The README lift shape: the shipped public/ copied verbatim, the
        // kit reachable as vendor/hyper360/kipsaas, config at the app root.
        mkdir($this->app . '/vendor/hyper360', 0777, true);
        symlink($this->kit, $this->app . '/vendor/hyper360/kipsaas');
        symlink($this->kit . '/vendor/autoload.php', $this->app . '/vendor/autoload.php');
        mkdir($this->app . '/public', 0777, true);
        copy($this->kit . '/public/index.php', $this->app . '/public/index.php');
        copy($this->kit . '/public/style.css', $this->app . '/public/style.css');
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            proc_terminate($proc);
            proc_close($proc);
        }
        foreach ($this->dirs as $dir) $this->rmTree($dir);
    }

    private function scratch(): string
    {
        $dir = sys_get_temp_dir() . '/saaspkg-' . bin2hex(random_bytes(4));
        $this->dirs[] = $dir;
        return $dir;
    }

    /** Writes the fixture app's config; $extra splices additional lines in. */
    private function writeAppConfig(string $extra = ''): void
    {
        $config = str_replace(['__DIR__', '__EXTRA__'], [var_export($this->app, true), $extra], <<<'PHP'
<?php
return [
    'env' => 'dev',
    'registry_dsn' => 'sqlite:' . __DIR__ . '/data/registry.sqlite',
    'tenants_root' => __DIR__ . '/tenants',
    'code_source' => __DIR__ . '/fakecode',
    'base_domain' => 'saas.example.test',
    'control_base_url' => 'http://127.0.0.1:8089',
    'token_secret' => 'pkg-test-secret',
    'brand_name' => 'Pkgmodeapp',
    'brand_url' => '/start',
    'mail' => ['transport' => 'log', 'log_path' => __DIR__ . '/data/mail.log', 'from' => 'noreply@saas.example.test'],
    'plans' => [
        'standard' => ['price_id' => 'price_x', 'label' => 'Standard', 'amount_month' => 900],
    ],
__EXTRA__
    'grace_days' => 7, 'retention_days' => 30,
    'nginx' => ['map_file' => __DIR__ . '/data/tenants.map', 'empty_root' => '/srv/e',
                'control_public_root' => '/srv/c', 'control_host' => 'control.saas.example.test', 'reload' => false],
    'stripe_secret' => '',
    'stripe_webhook_secret' => '',
];
PHP);
        file_put_contents($this->app . '/config.php', $config);
    }

    /** Serves the fixture app through its lifted (shipped) front controller, SAAS_CONFIG deliberately unset. */
    private function bootAppServer(int $port): void
    {
        $pub = $this->app . '/public';
        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($pub) . ' ' . escapeshellarg($pub . '/index.php');
        $env = array_filter(getenv(), 'is_string');
        unset($env['SAAS_CONFIG']); // package mode: config must resolve at the app root, not through the env door
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $pub, $env);
        for ($i = 0; $i < 100; $i++) {
            usleep(50000);
            [$status] = $this->http($port, 'GET', '/healthz');
            if ($status > 0) { $this->procs[] = $proc; return; } // any HTTP answer means the server is listening
        }
        $this->fail("built-in server did not come up on 127.0.0.1:{$port}");
    }

    /** @return array{int, string} status, body */
    private function http(int $port, string $method, string $path): array
    {
        $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10]]);
        $raw = (string) @file_get_contents("http://127.0.0.1:{$port}" . $path, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
        }
        return [$status, $raw];
    }

    private function lift(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            copy($from . '/' . $f, $to . '/' . $f);
        }
    }

    public function test_package_mode_boots_the_app_root_config_not_the_package_one(): void
    {
        // boot() called with no argument resolved config at the PACKAGE
        // directory inside vendor/: a lifted app ran on the vendored
        // checkout's own config (or died on its absence) instead of the
        // config.php sitting next to its lifted public/.
        $this->writeAppConfig();
        $this->bootAppServer(8089);

        [$status, $body] = $this->http(8089, 'GET', '/healthz');
        self::assertSame(200, $status);
        self::assertSame('ok', $body);

        [$status, $body] = $this->http(8089, 'GET', '/start');
        self::assertSame(200, $status);
        self::assertStringContainsString('Pkgmodeapp', $body, 'the pricing page must carry the APP config brand, not the package config one');
        self::assertStringContainsString('Standard', $body);
        self::assertFileExists($this->app . '/data/registry.sqlite', 'the registry must land in the app root the config names');
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $ri = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $item) {
            if ($item->isLink()) { @unlink((string) $item->getPathname()); continue; } // never traverse a link outward
            $item->isDir() ? @rmdir((string) $item->getPathname()) : @unlink((string) $item->getPathname());
        }
        @rmdir($dir);
    }
}
