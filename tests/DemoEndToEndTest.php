<?php // tests/DemoEndToEndTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The full engine-to-demo round trip: tenant:create + provision:tenant
 * against the repo's demo/ as code_source, then HTTP through the stamped
 * tenant's own front controller and its own vendored framework (built-in
 * server): the owner logs in with the returned one-time password, writes
 * notes, and the free plan's note_cap stops the 11th.
 */
final class DemoEndToEndTest extends TestCase
{
    private static bool $vendorInstalled = false;

    private string $dir;
    private string $tenants;
    /** @var resource|null */
    private $server = null;
    private string $base = '';
    /** @var array<string, string> */
    private array $cookies = [];

    public static function setUpBeforeClass(): void
    {
        // demo/vendor is not committed; the e2e bootstrap installs it once per
        // run (network required the first time). An install left by an earlier
        // run is reused as-is.
        if (self::$vendorInstalled) return;
        $demo = dirname(__DIR__) . '/demo';
        if (!is_file($demo . '/vendor/autoload.php')) {
            $bin = dirname(PHP_BINARY);
            $composer = is_file($bin . '/composer') ? $bin . '/composer' : 'composer';
            putenv('PATH=' . $bin . ':' . getenv('PATH'));
            exec(sprintf('%s %s install --working-dir %s --no-interaction 2>&1',
                escapeshellarg(PHP_BINARY), escapeshellarg($composer), escapeshellarg($demo)), $out, $code);
            self::assertSame(0, $code, 'composer install in demo/ failed: ' . implode("\n", $out));
        }
        self::$vendorInstalled = true;
    }

    protected function setUp(): void
    {
        $kit = dirname(__DIR__);
        $this->dir = sys_get_temp_dir() . '/demo-e2e-' . bin2hex(random_bytes(4));
        $this->tenants = $this->dir . '/tenants';
        mkdir($this->dir . '/data', 0777, true);
        $config = str_replace('__DIR__', var_export($this->dir, true), <<<PHP
<?php
return [
    'env' => 'dev',
    'registry_dsn' => 'sqlite:' . __DIR__ . '/data/registry.sqlite',
    'tenants_root' => __DIR__ . '/tenants',
    'code_source' => {$this->singleQuoted($kit . '/demo')},
    'base_domain' => 'saas.example.test',
    'token_secret' => 'x',
    'tenant_smtp' => null,
    'tenant_app' => new \KipSaaS\Demo\DemoApp(),
    'mail' => ['transport' => 'log', 'log_path' => __DIR__ . '/data/mail.log', 'from' => 'noreply@saas.example.test'],
    'plans' => ['standard' => ['price_id' => 'price_fixture', 'label' => 'Standard', 'amount_month' => 900,
        'storage_gb' => 2, 'powered_by' => true, 'note_cap' => 10]],
    'nginx' => ['map_file' => __DIR__ . '/tenants.map', 'empty_root' => '/srv/e',
        'control_public_root' => '/srv/c', 'control_host' => 'control.saas.example.test', 'reload' => false],
];
PHP);
        file_put_contents($this->dir . '/config.php', $config);
        putenv('SAAS_CONFIG=' . $this->dir . '/config.php');
    }

    protected function tearDown(): void
    {
        putenv('SAAS_CONFIG');
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->rmTree($this->dir);
    }

    public function test_provisioned_demo_serves_auth_notes_and_enforces_the_plan_cap(): void
    {
        ['otp' => $otp, 'email' => $email] = $this->provisionDemoTenant();

        // The stamp: a complete, self-contained install with the rendered config.
        $dir = $this->tenants . '/noteco';
        $cfg = require $dir . '/config.php';
        self::assertSame(10, $cfg['plan_flags']['note_cap']);
        self::assertSame('Noteco Notes', $cfg['site_name']);
        self::assertSame('https://noteco.saas.example.test', $cfg['base_url']);
        foreach (['bin/kip', 'config.php', 'composer.json', 'migrations/001_users.php', 'migrations/002_notes.php',
                  'public/index.php', 'src/Demo/DemoApp.php', 'app/views/notes/index.php'] as $rel) {
            self::assertFileExists($dir . '/' . $rel, $rel);
        }
        self::assertFileExists($dir . '/vendor/autoload.php');       // the vendored framework stamped
        self::assertNotTrue(is_link($dir . '/vendor/kip/framework')); // as real files, never a link
        self::assertFileExists($dir . '/app/nav.json');               // db:seed-demo ran inside the stamp
        self::assertFileExists($dir . '/public/robots.txt');
        $pdo = new \PDO('sqlite:' . $dir . '/app/data.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $owner = $pdo->query('SELECT email, role, is_admin FROM users')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($email, $owner['email']);
        self::assertSame('owner', $owner['role']);
        self::assertSame(1, (int) $owner['is_admin']);
        self::assertStringContainsString('noteco.saas.example.test', (string) file_get_contents($this->dir . '/tenants.map'));

        // HTTP through the tenant's own front controller and vendor.
        $this->startServer($dir);

        $home = $this->get('/');
        self::assertSame(200, $home['status']);
        self::assertStringContainsString('Noteco Notes', $home['body']);   // rendered tenant config
        self::assertStringContainsString('href="/notes"', $home['body']);  // seeded nav.json

        $guest = $this->get('/notes');
        self::assertSame(302, $guest['status']);
        self::assertSame('/auth/login', $guest['headers']['location'] ?? '');

        $login = $this->get('/auth/login');
        self::assertSame(200, $login['status']);
        self::assertStringContainsString('name="_token"', $login['body']);

        $attempt = $this->post('/auth/attempt', ['email' => $email, 'password' => $otp]);
        self::assertSame(302, $attempt['status'], $attempt['body']);
        self::assertSame('/notes', $attempt['headers']['location'] ?? '');

        $list = $this->get('/notes');
        self::assertSame(200, $list['status']);
        self::assertStringContainsString('New note', $list['body']); // logged in: the create form is back

        $token = $this->csrf($list['body']);
        for ($i = 1; $i <= 10; $i++) {
            $made = $this->post('/notes/create', ['_token' => $token, 'body' => "Note number {$i}"]);
            self::assertSame(302, $made['status'], "note {$i} was refused: " . $made['body']);
        }
        $list = $this->get('/notes');
        self::assertStringContainsString('Note number 10', $list['body']);
        self::assertStringContainsString('Note number 1', $list['body']);

        // The 11th note hits the plan cap carried in plan_flags.
        $capped = $this->post('/notes/create', ['_token' => $token, 'body' => 'Note number 11']);
        self::assertSame(402, $capped['status']);
        self::assertStringContainsString('Plan limit reached: 10 notes', $capped['body']);
        self::assertSame(10, (int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn());
    }

    /** Provision noteco into the fresh scratch registry; returns the printed one-time password. @return array{otp: string, email: string} */
    private function provisionDemoTenant(): array
    {
        $email = 'owner@notes.example.test';
        $out = $this->saas('tenant:create', 'noteco', 'standard', $email, 'Noteco Notes');
        self::assertStringContainsString('recorded as active', $out);
        $out = $this->saas('provision:tenant', 'noteco');
        self::assertStringContainsString('provisioned noteco', $out);
        self::assertMatchesRegularExpression('/One-time password for ' . preg_quote($email, '/') . ': (\S+)/', $out);
        $otp = preg_split('/\s+/', trim((string) preg_replace('/.*One-time password for [^:]+: (\S+).*/s', '$1', $out)))[0];
        self::assertNotFalse($otp);
        self::assertNotSame('', $otp);
        return ['otp' => $otp, 'email' => $email];
    }

    public function test_the_one_time_password_can_be_changed_and_the_old_one_dies(): void
    {
        // The demo OTP is a standing password until the app offers a change
        // flow, so the demo ships one: /auth/password, auth-gated, current
        // password verified, new hash written in one transaction.
        ['otp' => $otp, 'email' => $email] = $this->provisionDemoTenant();
        $this->startServer($this->tenants . '/noteco');

        $attempt = $this->post('/auth/attempt', ['email' => $email, 'password' => $otp]);
        self::assertSame(302, $attempt['status'], $attempt['body']);

        $form = $this->get('/auth/password');
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('action="/auth/password"', $form['body']);
        $token = $this->csrf($form['body']);

        $wrong = $this->post('/auth/password', ['_token' => $token, 'current_password' => 'not-the-password',
            'new_password' => 'correct horse battery staple', 'confirm_password' => 'correct horse battery staple']);
        self::assertSame(422, $wrong['status']);
        self::assertStringContainsString('current password is wrong', $wrong['body']);

        $mismatch = $this->post('/auth/password', ['_token' => $token, 'current_password' => $otp,
            'new_password' => 'correct horse battery staple', 'confirm_password' => 'a different string']);
        self::assertSame(422, $mismatch['status']);
        self::assertStringContainsString('do not match', $mismatch['body']);

        $changed = $this->post('/auth/password', ['_token' => $token, 'current_password' => $otp,
            'new_password' => 'correct horse battery staple', 'confirm_password' => 'correct horse battery staple']);
        self::assertSame(302, $changed['status'], $changed['body']);

        // This session stays signed in (its epoch rides the new hash); log
        // out and prove exactly one password opens the door now.
        $this->post('/auth/logout', ['_token' => $this->csrf($this->get('/notes')['body'])]);
        $old = $this->post('/auth/attempt', ['email' => $email, 'password' => $otp]);
        self::assertSame(200, $old['status']);
        self::assertStringContainsString('Wrong email or password', $old['body']);
        $new = $this->post('/auth/attempt', ['email' => $email, 'password' => 'correct horse battery staple']);
        self::assertSame(302, $new['status'], $new['body']);
        self::assertSame('/notes', $new['headers']['location'] ?? '');
    }

    private function saas(string ...$args): string
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/saas')
            . ' ' . implode(' ', array_map(escapeshellarg(...), $args)) . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        return implode("\n", $out);
    }

    private function startServer(string $tenantDir): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock, $errstr);
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $this->base = "http://127.0.0.1:{$port}";
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $tenantDir . '/public', $tenantDir . '/public/index.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::assertIsResource($this->server);
        for ($i = 0; $i < 100; $i++) { // readiness: the first successful response ends the wait
            $r = $this->get('/');
            if ($r['status'] > 0) return;
            usleep(100_000);
        }
        self::fail('built-in server never answered');
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function request(string $method, string $path, array $post = []): array
    {
        $headers = [];
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $n => $v) $pairs[] = $n . '=' . $v;
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $content = null;
        if ($post !== []) {
            $content = http_build_query($post);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0, // redirects are asserted hop by hop, never followed
            'timeout' => 10,
        ]]);
        $body = (string) @file_get_contents($this->base . $path, false, $ctx);
        $raw = $http_response_header ?? [];
        $status = 0;
        if (isset($raw[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $raw[0], $m) === 1) $status = (int) $m[1];
        $map = [];
        foreach ($raw as $line) { // absorb cookies like a browser jar (session ids rotate on login)
            if (stripos($line, 'Set-Cookie:') === 0) {
                $pair = substr($line, 11, (int) strcspn(substr($line, 11), ';'));
                $eq = strpos($pair, '=');
                if ($eq !== false) $this->cookies[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
            } elseif (str_contains($line, ':')) {
                [$n, $v] = explode(':', $line, 2);
                $map[strtolower(trim($n))] = trim($v);
            }
        }
        return ['status' => $status, 'headers' => $map, 'body' => $body];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function get(string $path): array { return $this->request('GET', $path); }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function post(string $path, array $data): array { return $this->request('POST', $path, $data); }

    private function csrf(string $html): string
    {
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $html, $m), 'no csrf token on the page');
        return $m[1];
    }

    /** A path spliced into the heredoc fixture as a single-quoted PHP literal. */
    private function singleQuoted(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
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
