<?php // tests/SaasWebSmokeTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\Registry;
use KipSaaS\StripeWebhook;
use KipSaaS\Tenants;
use PHPUnit\Framework\TestCase;

/**
 * HTTP wiring smoke against the real front controller on the PHP built-in
 * server: healthz, signup form, submit, pending page, verify interstitial,
 * the claim POST's invalid-token path, fail-closed webhooks, a signed
 * webhook driving a recording provisioner double and the map publish, the
 * suspended-host fallthrough, portal-by-email, plus the entry-seam pair
 * (an operator-owned lang pack via lang_dir, and manual-only plans with
 * no price hidden from the pricing page and refused at signup). Zero
 * external calls: the checkout redirect is engine-tested, the claim POST
 * is exercised on its no-Stripe error path, and the provisioner is a double.
 */
final class SaasWebSmokeTest extends TestCase
{
    private const WHSEC = 'whsec_test';
    private string $dir;
    /** @var list<resource> */
    private array $procs = [];
    /** @var list<string> */
    private array $dirs = [];
    private string $cookie = '';

    protected function setUp(): void
    {
        $this->dir = $this->scratch();
        mkdir($this->dir . '/data', 0777, true);
        mkdir($this->dir . '/tenants', 0777, true);

        // Registry rows: 'acme' verified (the webhook will activate it),
        // 'susp' suspended (the fallthrough must show its notice).
        $tenants = new Tenants((new Registry('sqlite:' . $this->dir . '/data/registry.sqlite'))->pdo());
        $acme = $tenants->create('acme', 'acme.saas.example.test', 'standard', 'ow@example.test', 'Acme', 'h', '2099-01-01T00:00:00Z');
        $tenants->setStatus($acme, 'verified');
        $susp = $tenants->create('susp', 'susp.saas.example.test', 'standard', 's@example.test', 'Susp', 'h', '2099-01-01T00:00:00Z');
        $tenants->setStatus($susp, 'verified');
        $tenants->setStatus($susp, 'active');
        $tenants->setStatus($susp, 'suspended', ['purge_after' => '2099-01-01T00:00:00Z']);

        $this->writeConfig($this->dir);
        $this->bootServer(8097, $this->dir . '/config.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            proc_terminate($proc);
            proc_close($proc);
        }
        foreach ($this->dirs as $dir) {
            if (!is_dir($dir)) continue;
            $ri = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($ri as $item) {
                $item->isDir() ? @rmdir((string) $item->getPathname()) : @unlink((string) $item->getPathname());
            }
            @rmdir($dir);
        }
    }

    private function scratch(): string
    {
        $dir = sys_get_temp_dir() . '/saasweb-' . bin2hex(random_bytes(4));
        $this->dirs[] = $dir;
        return $dir;
    }

    /** Writes the control config; $extra splices additional config lines in. */
    private function writeConfig(string $dir, string $extra = ''): void
    {
        $config = str_replace(['__DIR__', '__EXTRA__'], [var_export($dir, true), $extra], <<<'PHP'
<?php
final class SaasWebRecordingProvisioner implements KipSaaS\ProvisionerInterface
{
    public function __construct(private string $log) {}
    public function provision(array $tenant): array
    {
        file_put_contents($this->log, 'provision ' . $tenant['slug'] . "\n", FILE_APPEND);
        return ['mail_sent' => true, 'one_time_password' => null];
    }
    public function resume(array $tenant): void { file_put_contents($this->log, 'resume ' . $tenant['slug'] . "\n", FILE_APPEND); }
    public function suspend(array $tenant): void { file_put_contents($this->log, 'suspend ' . $tenant['slug'] . "\n", FILE_APPEND); }
    public function purge(array $tenant): void { file_put_contents($this->log, 'purge ' . $tenant['slug'] . "\n", FILE_APPEND); }
}
return [
    'env' => 'dev',
    'registry_dsn' => 'sqlite:' . __DIR__ . '/data/registry.sqlite',
    'tenants_root' => __DIR__ . '/tenants',
    'code_source' => __DIR__ . '/fakecode',
    'base_domain' => 'saas.example.test',
    'control_base_url' => 'http://127.0.0.1:8097',
    'token_secret' => 'test-secret',
    'brand_name' => 'Brandtest',
    'brand_url' => 'https://brandtest.example.test',
    'mail' => ['transport' => 'log', 'log_path' => __DIR__ . '/data/mail.log', 'from' => 'noreply@saas.example.test'],
    'tenant_smtp' => ['host' => 'smtp.test', 'port' => 587, 'username' => 'u', 'password' => 'p', 'from' => 'sites@saas.example.test'],
    'tenant_app' => null,
    'provisioner' => new SaasWebRecordingProvisioner(__DIR__ . '/provisioner.log'),
    'plans' => [
        'standard' => ['price_id' => 'price_x', 'label' => 'Standard', 'amount_month' => 900, 'storage_gb' => 2, 'powered_by' => true],
        'pro' => ['price_id' => 'price_y', 'label' => 'Pro', 'amount_month' => 1900, 'storage_gb' => 10, 'powered_by' => false],
        'flagship' => ['price_id' => null, 'label' => 'Flagship', 'amount_month' => 0, 'storage_gb' => 2, 'powered_by' => true],
    ],
__EXTRA__
    'grace_days' => 7, 'retention_days' => 30,
    'nginx' => ['map_file' => __DIR__ . '/tenants.map', 'tenants_root' => __DIR__ . '/tenants', 'empty_root' => '/srv/e',
                'control_public_root' => '/srv/c', 'control_host' => 'control.saas.example.test', 'reload' => false],
    'stripe_secret' => '',
    'stripe_webhook_secret' => 'whsec_test',
];
PHP);
        file_put_contents($dir . '/config.php', $config);
    }

    private function bootServer(int $port, string $configPath): void
    {
        $pub = dirname(__DIR__) . '/public';
        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($pub) . ' ' . escapeshellarg($pub . '/index.php');
        $env = array_merge(array_filter(getenv(), 'is_string'), ['SAAS_CONFIG' => $configPath]);
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $pub, $env);
        for ($i = 0; $i < 100; $i++) {
            usleep(50000);
            [$status] = $this->http($port, 'GET', '/healthz');
            if ($status === 200) { $this->procs[] = $proc; return; }
        }
        $this->fail("built-in server did not come up on 127.0.0.1:{$port}");
    }

    /** @return array{int, string, list<string>} status, body, raw headers */
    private function http(int $port, string $method, string $path, array $form = [], array $headers = [], string $rawBody = ''): array
    {
        $body = $rawBody !== '' ? $rawBody : ($form === [] ? '' : http_build_query($form));
        $send = $headers;
        if ($this->cookie !== '') $send[] = 'Cookie: ' . $this->cookie;
        if ($rawBody === '' && $body !== '') $send[] = 'Content-Type: application/x-www-form-urlencoded';
        $ctx = stream_context_create(['http' => ['method' => $method,
            'header' => implode("\r\n", $send), 'content' => $body, 'ignore_errors' => true, 'timeout' => 10,
            'follow_location' => 0]]);
        $raw = (string) @file_get_contents("http://127.0.0.1:{$port}" . $path, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
            if (stripos($h, 'Set-Cookie: PHPSESSID=') === 0) {
                $pair = substr($h, strlen('Set-Cookie: '));
                $this->cookie = trim((string) preg_replace('/;.*$/s', '', $pair));
            }
        }
        return [$status, $raw, $http_response_header ?? []];
    }

    public function test_signup_through_signed_webhook_smoke(): void
    {
        // healthz
        [$status, $body] = $this->http(8097, 'GET', '/healthz');
        self::assertSame(200, $status);
        self::assertSame('ok', $body);

        // the pricing page: brand from config, plans from the catalog, csrf field.
        // The manual-only plan (flagship: no price) renders nowhere.
        [$status, $body] = $this->http(8097, 'GET', '/start');
        self::assertSame(200, $status);
        self::assertStringContainsString('Brandtest', $body);
        self::assertStringContainsString('Standard', $body);
        self::assertStringContainsString('Pro', $body);
        self::assertStringContainsString('$9/mo', $body);
        self::assertStringContainsString('$19/mo', $body);
        self::assertStringNotContainsString('Flagship', $body);
        self::assertStringNotContainsString('$0/mo', $body);
        self::assertMatchesRegularExpression('/name="csrf" value="[0-9a-f]{64}"/', $body);
        $csrf = (string) preg_match('/name="csrf" value="([0-9a-f]{64})"/', $body, $m) ? $m[1] : '';

        // a forged plan value naming the manual-only tier is refused like an unknown key
        [$status, $body] = $this->http(8097, 'POST', '/start', ['csrf' => $csrf, 'email' => 'fl@example.test', 'title' => 'Flag Try', 'plan' => 'flagship']);
        self::assertSame(422, $status);
        self::assertStringContainsString('Pick a plan.', $body);

        // signup submit -> pending redirect
        [$status, , $headers] = $this->http(8097, 'POST', '/start', ['csrf' => $csrf, 'email' => 'ow@example.test', 'title' => 'Acme Site', 'plan' => 'standard']);
        self::assertSame(303, $status);
        self::assertContains('Location: /start/pending', $headers);
        [$status, $body] = $this->http(8097, 'GET', '/start/pending');
        self::assertSame(200, $status);
        self::assertStringContainsString('Check your email', $body);

        // the verify mail landed in the log transport; its link opens the interstitial
        $mail = (string) file_get_contents($this->dir . '/data/mail.log');
        self::assertMatchesRegularExpression('@/verify\?t=([A-Za-z0-9._%-]+)@', $mail, 'verify link missing from mail log');
        preg_match('@/verify\?t=([A-Za-z0-9._%-]+)@', $mail, $m);
        $token = urldecode($m[1]);
        [$status, $body] = $this->http(8097, 'GET', '/verify?t=' . urlencode($token));
        self::assertSame(200, $status);
        self::assertStringContainsString('action="/verify/claim"', $body);
        self::assertStringContainsString(htmlspecialchars($token, ENT_QUOTES), $body);

        // a bad token on the claim POST is rejected without touching Stripe
        [$status, $body] = $this->http(8097, 'POST', '/verify/claim', ['csrf' => $csrf, 'token' => 'forged.token']);
        self::assertSame(403, $status);
        self::assertStringContainsString('invalid', $body);

        // unsigned webhook: fail closed
        [$status, $body] = $this->http(8097, 'POST', '/webhooks/stripe', [], ['Content-Type: application/json'], '{}');
        self::assertSame(403, $status);
        self::assertSame('bad signature', $body);

        // signed webhook: activates the verified tenant through the recording provisioner and publishes the map
        $pdo = (new \PDO('sqlite:' . $this->dir . '/data/registry.sqlite'));
        $acmeId = (string) $pdo->query("SELECT id FROM tenants WHERE slug = 'acme'")->fetchColumn();
        $event = ['id' => 'evt_smoke_1', 'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_1', 'client_reference_id' => $acmeId, 'customer' => 'cus_9', 'subscription' => 'sub_3']]];
        $raw = (string) json_encode($event, JSON_THROW_ON_ERROR);
        $t = (string) time();
        $sig = "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$raw}", self::WHSEC);
        [$status, $body] = $this->http(8097, 'POST', '/webhooks/stripe', [], ['Content-Type: application/json', 'Stripe-Signature: ' . $sig], $raw);
        self::assertSame(200, $status);
        self::assertSame('ok', $body);
        self::assertSame('active', $pdo->query("SELECT status FROM tenants WHERE slug = 'acme'")->fetchColumn());
        self::assertSame('cus_9', $pdo->query("SELECT stripe_customer_id FROM tenants WHERE slug = 'acme'")->fetchColumn());
        self::assertSame('provision acme', trim((string) file_get_contents($this->dir . '/provisioner.log')));
        $map = (string) file_get_contents($this->dir . '/tenants.map');
        self::assertStringContainsString('acme.saas.example.test ' . $this->dir . '/tenants/acme/public', $map);
        self::assertStringContainsString('susp.saas.example.test /srv/c', $map); // suspended routes to the notice root

        // a suspended host's homepage gets the notice, not a 404
        [$status, $body] = $this->http(8097, 'GET', '/', [], ['Host: susp.saas.example.test']);
        self::assertSame(200, $status);
        self::assertStringContainsString('suspended', $body);

        // portal-by-email with no matching customer: the confirmation, no Stripe call
        [$status, $body] = $this->http(8097, 'POST', '/billing/portal', ['csrf' => $csrf, 'email' => 'nobody@example.test']);
        self::assertSame(200, $status);
        self::assertStringContainsString('billing link is on its way', $body);

        // unknown path: 404 through the error page
        [$status, $body] = $this->http(8097, 'GET', '/nope');
        self::assertSame(404, $status);
        self::assertStringContainsString('Nothing lives at that address', $body);
    }

    public function test_lang_dir_serves_an_operator_owned_pack(): void
    {
        // A consuming repo can point lang_dir at its own pack: same code,
        // same views, its voice. The override changes one string and the
        // kit default must not leak through it.
        $dir = $this->scratch();
        mkdir($dir . '/data', 0777, true);
        mkdir($dir . '/tenants', 0777, true);
        mkdir($dir . '/lang', 0777, true);
        $pack = (string) file_get_contents(dirname(__DIR__) . '/lang/en.php');
        $pack = str_replace("'start_heading' => 'Start your site',", "'start_heading' => 'Start your colony',", $pack);
        file_put_contents($dir . '/lang/en.php', $pack);
        $this->writeConfig($dir, "    'lang' => 'en',\n    'lang_dir' => " . var_export($dir . '/lang', true) . ",\n");
        $this->bootServer(8096, $dir . '/config.php');

        [$status, $body] = $this->http(8096, 'GET', '/start');
        self::assertSame(200, $status);
        self::assertStringContainsString('Start your colony', $body);
        self::assertStringNotContainsString('Start your site', $body);
        self::assertStringContainsString('Brandtest', $body); // everything else is the pack's unchanged copy
    }

    public function test_signature_helper_agrees_with_the_engine(): void
    {
        // sanity for the smoke's own signing: the engine accepts what we minted
        $body = '{"id":"evt_x"}';
        $t = (string) time();
        self::assertTrue(StripeWebhook::verify($body, "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", self::WHSEC), self::WHSEC));
    }
}
