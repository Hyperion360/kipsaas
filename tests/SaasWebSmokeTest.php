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
 * no price hidden from the pricing page and refused at signup), and the
 * trust surface: policy pages and footer links, the aggregate /status
 * board (percentage and state, never a host name; no-data and garbage
 * JSON stay 200), the suspended-host notice winning /terms, the
 * custom-domain pricing row, and the neutral-pack guard. Zero
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
final class SaasWebCountingStripeHttp implements KipSaaS\StripeHttp
{
    public function __construct(private string $log) {}
    public function post(string $path, array $form): array
    {
        file_put_contents($this->log, $path . "\n", FILE_APPEND);
        return ['id' => 'cs_test', 'url' => 'https://checkout.stripe.com/c/pay/cs_test'];
    }
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
        'pro' => ['price_id' => 'price_y', 'label' => 'Pro', 'amount_month' => 1900, 'storage_gb' => 10, 'powered_by' => false, 'custom_domains' => true],
        'flagship' => ['price_id' => null, 'label' => 'Flagship', 'amount_month' => 0, 'storage_gb' => 2, 'powered_by' => true],
    ],
__EXTRA__
    'grace_days' => 7, 'retention_days' => 30,
    'nginx' => ['map_file' => __DIR__ . '/tenants.map', 'empty_root' => '/srv/e',
                'control_public_root' => '/srv/c', 'control_host' => 'control.saas.example.test', 'reload' => false],
    'stripe_secret' => '',
    'stripe_webhook_secret' => 'whsec_test',
    'status_file' => __DIR__ . '/data/status.json',
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

    public function test_trust_pages_answer_with_their_headings_and_footer_links(): void
    {
        // terms, privacy, aup, refund, migration: the heading key renders as
        // the h1, the body key renders as paragraphs, and the footer links to
        // every page plus the support sentence as plain text.
        $headings = ['terms' => 'Terms of service', 'privacy' => 'Privacy',
                     'aup' => 'Acceptable use', 'refund' => 'Refunds',
                     'migration' => 'Moving your site to us'];
        foreach ($headings as $page => $heading) {
            [$status, $body] = $this->http(8097, 'GET', '/' . $page);
            self::assertSame(200, $status, $page);
            self::assertStringContainsString('<h1>' . htmlspecialchars($heading) . '</h1>', $body, $page);
            self::assertGreaterThan(1, substr_count($body, '<p>'), "{$page}: body paragraphs missing");
        }
        [$status, $body] = $this->http(8097, 'GET', '/terms');
        self::assertSame(200, $status);
        foreach (['terms', 'privacy', 'aup', 'refund', 'status'] as $link) {
            self::assertStringContainsString('href="/' . $link . '"', $body, "footer link to /{$link}");
        }
        self::assertStringContainsString('support@example.com', $body);
        // the migration page closes with its call to action back into signup
        [$status, $body] = $this->http(8097, 'GET', '/migration');
        self::assertSame(200, $status);
        self::assertStringContainsString('href="/start"', $body);
    }

    public function test_status_page_renders_the_probe_aggregate_without_host_names(): void
    {
        // ok state: empty hosts_down, the uptime percentage, the checks
        // count, and the last incident time all render.
        file_put_contents($this->dir . '/data/status.json', (string) json_encode([
            'generated_at' => '2026-10-07T06:00:00Z',
            'overall' => ['ok_pct' => 99.4, 'checks' => 12],
            'last_incident' => '2026-09-21T04:10:00Z',
            'hosts_down' => [],
        ]));
        [$status, $body] = $this->http(8097, 'GET', '/status');
        self::assertSame(200, $status);
        self::assertStringContainsString('All systems are operating normally.', $body);
        self::assertStringContainsString('99.40%', $body);
        self::assertStringContainsString('Checks monitored: 12', $body);
        self::assertStringContainsString('Last incident: 2026-09-21T04:10:00Z', $body);

        // degraded: a host in hosts_down flips the state line, and the host
        // name itself must not appear anywhere: a host is a customer slug,
        // and this page is public. A null last_incident omits the line.
        file_put_contents($this->dir . '/data/status.json', (string) json_encode([
            'generated_at' => '2026-10-07T06:05:00Z',
            'overall' => ['ok_pct' => 91.2, 'checks' => 12],
            'last_incident' => null,
            'hosts_down' => ['acme.saas.example.test'],
        ]));
        [$status, $body] = $this->http(8097, 'GET', '/status');
        self::assertSame(200, $status);
        self::assertStringContainsString('Degraded', $body);
        self::assertStringNotContainsString('Last incident:', $body);
        self::assertStringNotContainsString('acme.saas.example.test', $body, 'a host name from hosts_down leaked onto the public page');
        self::assertStringNotContainsString('acme', $body);
    }

    public function test_status_page_without_data_is_a_friendly_200(): void
    {
        // No file yet (the operator's probe has not run once): the no-data
        // state, still 200.
        [$status, $body] = $this->http(8097, 'GET', '/status');
        self::assertSame(200, $status);
        self::assertStringContainsString('No status data is available', $body);
        self::assertStringNotContainsString('30-day uptime', $body);

        // Garbage JSON is the same friendly page, never a 500.
        file_put_contents($this->dir . '/data/status.json', '{not json');
        [$status, $body] = $this->http(8097, 'GET', '/status');
        self::assertSame(200, $status);
        self::assertStringContainsString('No status data is available', $body);
    }

    public function test_a_suspended_host_gets_the_notice_even_on_trust_pages(): void
    {
        // The trust arms sit after the suspended fallthrough on purpose: a
        // suspended tenant's /terms answers the notice, not the policy.
        [$status, $body] = $this->http(8097, 'GET', '/terms', [], ['Host: susp.saas.example.test']);
        self::assertSame(200, $status);
        self::assertStringContainsString('suspended', $body);
        self::assertStringNotContainsString('Terms of service', $body);
    }

    public function test_the_kit_pack_stays_audience_neutral(): void
    {
        // The neutral pack must read as generic managed hosting: an adopter
        // ships it without scrubbing audience residue out of the strings.
        $pack = require dirname(__DIR__) . '/lang/en.php';
        self::assertIsArray($pack);
        foreach ($pack as $key => $value) {
            foreach (['fiction', 'fanfic', 'efiction'] as $needle) {
                self::assertStringNotContainsStringIgnoringCase($needle, $value, "lang key {$key}");
            }
        }
    }

    public function test_the_pricing_table_carries_the_custom_domain_row(): void
    {
        // The row mirrors the plan catalog exactly like the attribution row:
        // standard (flag absent) reads no, pro (flag true) reads yes. The
        // refund note and the migration callout ride the same page.
        [$status, $body] = $this->http(8097, 'GET', '/start');
        self::assertSame(200, $status);
        self::assertStringContainsString('<td>Custom domain</td><td>no</td><td>yes</td>', $body);
        self::assertStringContainsString('full refund on request', $body);
        self::assertStringContainsString('href="/migration"', $body);
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

    public function test_view_dir_overrides_one_page_template_and_the_rest_stay_kit(): void
    {
        // The view seam mirrors the framework's skin semantics: EVERY
        // template resolves override-first in view_dir and falls back to the
        // kit's own views on a miss, so an operator overrides a single page
        // without lifting the whole directory. A view_dir holding only
        // start.php must serve that page from the override and every other
        // page (the layout included) from the kit.
        $dir = $this->scratch();
        mkdir($dir . '/data', 0777, true);
        mkdir($dir . '/tenants', 0777, true);
        mkdir($dir . '/views', 0777, true);
        file_put_contents($dir . '/views/start.php', "<h1>Colony signup</h1>\n");
        $this->writeConfig($dir, "    'view_dir' => " . var_export($dir . '/views', true) . ",\n");
        $this->bootServer(8095, $dir . '/config.php');

        [$status, $body] = $this->http(8095, 'GET', '/start');
        self::assertSame(200, $status);
        self::assertStringContainsString('Colony signup', $body, 'the overridden page template must win');
        self::assertStringNotContainsString('name="csrf"', $body, 'the kit template must lose on the overridden page');
        self::assertStringContainsString('Brandtest', $body, 'the kit layout must still wrap the override (fallback)');
        [$status, $body] = $this->http(8095, 'GET', '/start/pending');
        self::assertSame(200, $status);
        self::assertStringContainsString('Check your email', $body, 'an unoverridden page must render the kit template');
    }

    public function test_view_dir_overrides_the_layout_alone_and_keeps_kit_pages(): void
    {
        // The layout is itself a template: an override of layout.php must
        // wrap pages whose bodies still come from the kit.
        $dir = $this->scratch();
        mkdir($dir . '/data', 0777, true);
        mkdir($dir . '/tenants', 0777, true);
        mkdir($dir . '/views', 0777, true);
        $layout = (string) file_get_contents(dirname(__DIR__) . '/views/layout.php');
        file_put_contents($dir . '/views/layout.php', str_replace('</body>', "<p>SHELLMARK</p></body>", $layout));
        $this->writeConfig($dir, "    'view_dir' => " . var_export($dir . '/views', true) . ",\n");
        $this->bootServer(8095, $dir . '/config.php');

        [$status, $body] = $this->http(8095, 'GET', '/start');
        self::assertSame(200, $status);
        self::assertStringContainsString('SHELLMARK', $body, 'the overridden layout must win');
        self::assertStringContainsString('Start your site', $body, 'the kit page template must fall back under the overridden layout');
    }

    public function test_a_double_submitted_claim_starts_exactly_one_checkout(): void
    {
        // Two POSTs of one verify link race the same pending tenant. The
        // first claim wins and opens the checkout; the second must land
        // somewhere true (the pending page) WITHOUT a second checkout
        // session: one tenant, one session, no matter the submit count.
        $dir = $this->scratch();
        mkdir($dir . '/data', 0777, true);
        mkdir($dir . '/tenants', 0777, true);
        $tenants = new Tenants((new Registry('sqlite:' . $dir . '/data/registry.sqlite'))->pdo());
        $id = $tenants->create('pend', 'pend.saas.example.test', 'standard', 'p@example.test', 'Pend', '', '');
        $token = \KipSaaS\Tokens::issue('test-secret');
        $tenants->update($id, ['verify_token_hash' => $token['hash'], 'verify_expires_at' => $token['expires_at']]);
        $this->writeConfig($dir, "    'stripe_http' => new SaasWebCountingStripeHttp(__DIR__ . '/stripe.log'),\n");
        $this->bootServer(8098, $dir . '/config.php');

        [$status, $body] = $this->http(8098, 'GET', '/start');
        self::assertSame(200, $status);
        preg_match('/name="csrf" value="([0-9a-f]{64})"/', $body, $m);
        $csrf = $m[1];

        [$status, , $headers] = $this->http(8098, 'POST', '/verify/claim', ['csrf' => $csrf, 'token' => $token['token']]);
        self::assertSame(303, $status);
        self::assertContains('Location: https://checkout.stripe.com/c/pay/cs_test', $headers);

        [$status, , $headers] = $this->http(8098, 'POST', '/verify/claim', ['csrf' => $csrf, 'token' => $token['token']]);
        self::assertSame(303, $status);
        self::assertContains('Location: /start/pending', $headers);

        self::assertSame(1, substr_count((string) file_get_contents($dir . '/stripe.log'), '/v1/checkout/sessions'),
            'two claim POSTs must open exactly one checkout session');
        $pdo = new \PDO('sqlite:' . $dir . '/data/registry.sqlite');
        self::assertSame('verified', $pdo->query("SELECT status FROM tenants WHERE slug = 'pend'")->fetchColumn());
    }

    public function test_the_claim_route_refuses_to_start_checkout_without_tenant_smtp(): void
    {
        // The GET interstitial 503s without tenant SMTP; the claim POST must
        // carry the same gate, or a direct POST starts a checkout that ends
        // at a provisioned tenant whose owner password nobody can know.
        $dir = $this->scratch();
        mkdir($dir . '/data', 0777, true);
        mkdir($dir . '/tenants', 0777, true);
        $this->writeConfig($dir, "    'tenant_smtp' => null,\n");
        $this->bootServer(8092, $dir . '/config.php');

        [$status, $body] = $this->http(8092, 'POST', '/verify/claim', ['csrf' => 'x', 'token' => 'forged.token']);
        self::assertSame(503, $status);
        self::assertStringContainsString('paused', $body); // the smtp_missing error page, not a 403 claim rejection
        // Operator self-help: the page names the exact keys to set and where
        // the walkthrough lives, so a fresh install can fix itself.
        self::assertStringContainsString('tenant_smtp', $body);
        self::assertStringContainsString('STRIPE-TESTMODE', $body);
    }

    public function test_machine_routes_never_start_a_session(): void
    {
        // A health probe or a Stripe delivery arriving without a session
        // cookie must not mint one: per-request session files and Set-Cookie
        // headers on machine routes are pure churn.
        $this->cookie = '';
        [$status, , $headers] = $this->http(8097, 'GET', '/healthz');
        self::assertSame(200, $status);
        foreach ($headers as $h) {
            self::assertDoesNotMatchRegularExpression('/^set-cookie:/i', $h, 'healthz must not set a session cookie');
        }
        self::assertSame('', $this->cookie, 'no cookie may be captured from healthz');

        $this->cookie = '';
        [$status, , $headers] = $this->http(8097, 'POST', '/webhooks/stripe', [], ['Content-Type: application/json'], '{}');
        self::assertSame(403, $status);
        self::assertSame('', $this->cookie, 'no cookie may be captured from the webhook route');
        foreach ($headers as $h) {
            self::assertDoesNotMatchRegularExpression('/^set-cookie:/i', $h, 'the webhook route must not set a session cookie');
        }
    }

    public function test_session_cookie_carries_secure_when_the_control_url_is_https(): void
    {
        // nginx's stock fastcgi_params never passes HTTPS, and a spoofable
        // X-Forwarded-Proto is the wrong basis for the flag: the configured
        // control URL is authoritative.
        $dir = $this->scratch();
        mkdir($dir . '/data', 0777, true);
        mkdir($dir . '/tenants', 0777, true);
        $this->writeConfig($dir, "    'control_base_url' => 'https://control.saas.example.test',\n");
        $this->bootServer(8094, $dir . '/config.php');

        $this->cookie = '';
        [$status, , $headers] = $this->http(8094, 'GET', '/start');
        self::assertSame(200, $status);
        $seen = false;
        foreach ($headers as $h) {
            if (stripos($h, 'Set-Cookie: PHPSESSID=') === 0) {
                $seen = true;
                self::assertStringContainsStringIgnoringCase('secure', $h, 'an https control URL must set the Secure flag even over a plain http request');
            }
        }
        self::assertTrue($seen, 'the start page must set a session cookie');
    }

    public function test_a_missing_lang_pack_is_a_clean_500(): void
    {
        // A typo'd lang code used to hit an uncatchable require fatal; it
        // must be a plain 500 like any other boot failure. healthz stays up:
        // liveness does not depend on the UI pack.
        $dir = $this->scratch();
        mkdir($dir . '/data', 0777, true);
        mkdir($dir . '/tenants', 0777, true);
        $this->writeConfig($dir, "    'lang' => 'zz',\n");
        $this->bootServer(8091, $dir . '/config.php');

        [$status, $body] = $this->http(8091, 'GET', '/start');
        self::assertSame(500, $status);
        self::assertStringContainsString('saas: no lang pack', $body);
        [$status] = $this->http(8091, 'GET', '/healthz');
        self::assertSame(200, $status);
    }

    public function test_portal_requests_are_rate_limited_per_ip(): void
    {
        // Each portal POST sends real mail and opens Stripe portal sessions
        // for every matching row: an unthrottled form is a mail-bomb and a
        // Stripe-quota faucet.
        [$status, $body] = $this->http(8097, 'GET', '/start');
        preg_match('/name="csrf" value="([0-9a-f]{64})"/', $body, $m);
        $csrf = $m[1];
        for ($i = 0; $i < 10; $i++) {
            [$status] = $this->http(8097, 'POST', '/billing/portal', ['csrf' => $csrf, 'email' => 'nobody@example.test']);
            self::assertSame(200, $status);
        }
        [$status, $body] = $this->http(8097, 'POST', '/billing/portal', ['csrf' => $csrf, 'email' => 'nobody@example.test']);
        self::assertSame(429, $status);
        self::assertStringContainsString('Too many', $body);
    }

    public function test_signature_helper_agrees_with_the_engine(): void
    {
        // sanity for the smoke's own signing: the engine accepts what we minted
        $body = '{"id":"evt_x"}';
        $t = (string) time();
        self::assertTrue(StripeWebhook::verify($body, "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", self::WHSEC), self::WHSEC));
    }
}
