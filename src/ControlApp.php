<?php // src/ControlApp.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * The control web app entry. Extracted from the procedural front controller
 * so an embedding repo can run the same surface over its own config array:
 * its public/index.php becomes a thin adapter that binds its tenant app and
 * provisioner at the config seams and calls run(). boot() is the kit's own
 * entry (SAAS_CONFIG, else the repo's config.php). Every path exits: HTTP
 * is terminal here, so both methods return never.
 */
final class ControlApp
{
    public static function boot(?string $configPath = null): never
    {
        $path = $configPath ?? (getenv('SAAS_CONFIG') ?: dirname(__DIR__) . '/config.php');
        $config = is_file($path) ? require $path : null;
        if (!is_array($config)) {
            http_response_code(500);
            exit("saas: no configuration\n");
        }
        self::run($config);
    }

    public static function run(array $config): never
    {
        if (($config['token_secret'] ?? '') === '' && ($config['env'] ?? 'prod') === 'prod') {
            http_response_code(500);
            exit("saas: token_secret is required in prod\n");
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Machine routes never touch session state: a health probe or a
        // Stripe delivery must not mint a session file and a Set-Cookie per
        // request.
        if ($path !== '/healthz' && $path !== '/webhooks/stripe') {
            // The configured control URL is authoritative for the Secure
            // flag (nginx's stock fastcgi_params never passes HTTPS, so
            // header detection alone leaves the flag off on a TLS site);
            // the header checks cover a fronting proxy that terminates TLS
            // elsewhere and forwards the scheme.
            $secure = str_starts_with(rtrim((string) ($config['control_base_url'] ?? ''), '/'), 'https://')
                || (($_SERVER['HTTPS'] ?? '') === 'on')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
            session_start();
        }

        // Liveness never depends on the UI: healthz answers before the lang
        // pack loads, so a broken pack cannot take the probe down with it.
        if ($path === '/healthz') {
            header('Content-Type: text/plain');
            exit('ok');
        }

        // view_dir/lang_dir point the renderer at the operator's copies
        // (package mode lifts views/ and lang/ into the app root); absent
        // both keys resolve to the package's own dirs, which is exactly
        // the same directory in template mode.
        $viewDir = is_string($config['view_dir'] ?? null) ? $config['view_dir'] : dirname(__DIR__) . '/views';
        $langDir = is_string($config['lang_dir'] ?? null) ? $config['lang_dir'] : dirname(__DIR__) . '/lang';
        $langFile = $langDir . '/' . basename((string) ($config['lang'] ?? 'en')) . '.php';
        $lang = is_file($langFile) ? require $langFile : null;
        if (!is_array($lang)) {
            http_response_code(500);
            exit("saas: no lang pack at {$langFile}\n");
        }
        $brand = (string) ($config['brand_name'] ?? 'KipSaaS');
        $brandUrl = (string) ($config['brand_url'] ?? '/start');

        $registry = new Registry($config['registry_dsn']);
        $tenants = new Tenants($registry->pdo());
        $mail = function (string $to, string $subject, string $body) use ($config): void {
            (new \Kip\Mailer($config['mail']))->send($to, $subject, $body);
        };
        $signup = new Signup($tenants, $config, $mail);
        $stripe = new StripeClient(new StreamStripeHttp((string) $config['stripe_secret']), $config);

        $view = function (string $name, array $vars = []) use ($config, $lang, $brand, $brandUrl, $viewDir): never {
            extract($vars, EXTR_SKIP);
            $L = $lang;
            $plans = is_array($config['plans'] ?? null) ? $config['plans'] : [];
            require $viewDir . '/layout.php';
            exit;
        };

        // The billing state machine with the real map publisher. Constructed lazily
        // on the routes that need it, so a Stripe misconfiguration cannot break
        // signup. The provisioner is the configured double when one is bound
        // (tests), otherwise the reference provisioner over the tenant_app adapter.
        $webhookHandler = function () use ($tenants, $config, $mail): WebhookHandler {
            $provisioner = $config['provisioner'] ?? null;
            if (!$provisioner instanceof ProvisionerInterface) {
                $app = $config['tenant_app'] ?? null;
                if (!$app instanceof TenantAppInterface) {
                    throw new \RuntimeException('config tenant_app must implement KipSaaS\TenantAppInterface (or bind provisioner)');
                }
                $provisioner = new ReferenceProvisioner($config, $mail, $app);
            }
            return new WebhookHandler($tenants, $provisioner, $config, function () use ($tenants, $config): void {
                try {
                    (new MapGen($tenants, ['tenants_root' => $config['tenants_root']] + $config['nginx']))->publish();
                } catch (\Throwable $e) {
                    error_log('saas: map publish failed: ' . $e->getMessage()); // cron map:write converges
                }
            });
        };

        try {
            if ($path === '/start' && $method === 'GET') {
                $view('start.php', ['error' => null, 'title' => $lang['start_heading']]);
            }
            if ($path === '/start' && $method === 'POST') {
                Csrf::check($_POST['csrf'] ?? null);
                // A fronting proxy's real-client header when present, falling back
                // to the socket address; restrict direct origin access so the
                // header cannot be spoofed by bypassing the proxy.
                $clientIp = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
                $out = $signup->submit((string) ($_POST['email'] ?? ''), (string) ($_POST['title'] ?? ''),
                    (string) ($_POST['plan'] ?? 'standard'), $clientIp);
                if ($out['status'] === 'pending') {
                    header('Location: /start/pending', true, 303);
                    exit;
                }
                http_response_code(422);
                $view('start.php', ['error' => (string) ($out['error'] ?? 'Something went wrong.'), 'title' => $lang['start_heading']]);
            }
            if ($path === '/start/pending' && $method === 'GET') {
                $view('pending.php', ['title' => $lang['pending_heading']]);
            }
            // The claim is a CSRF-guarded POST behind a GET interstitial: a mail
            // scanner prefetching the link must not burn the single-use token.
            if ($path === '/verify' && $method === 'GET') {
                if (!is_array($config['tenant_smtp'] ?? null)) {
                    http_response_code(503);
                    $view('error.php', ['title' => $lang['smtp_missing_heading'], 'message' => $lang['smtp_missing_body']]);
                }
                $view('verify.php', ['title' => $lang['verify_heading'], 'token' => (string) ($_GET['t'] ?? '')]);
            }
            if ($path === '/verify/claim' && $method === 'POST') {
                // The same SMTP gate as the GET interstitial: without tenant
                // mail the welcome (and its one-time password) cannot arrive,
                // so a self-serve claim must not start a checkout at all,
                // even by posting the route directly.
                if (!is_array($config['tenant_smtp'] ?? null)) {
                    http_response_code(503);
                    $view('error.php', ['title' => $lang['smtp_missing_heading'], 'message' => $lang['smtp_missing_body']]);
                }
                Csrf::check($_POST['csrf'] ?? null);
                $out = $signup->claim((string) ($_POST['token'] ?? ''));
                if ($out['status'] !== 'verified') {
                    http_response_code(403);
                    $view('error.php', ['title' => $lang['link_invalid_heading'], 'message' => (string) $out['error']]);
                }
                $tenant = $out['tenant'];
                $url = $stripe->checkoutSession($tenant, Plans::get($config, (string) $tenant['plan'])['price_id']);
                header('Location: ' . $url, true, 303);
                exit;
            }
            // Stripe's only write path into the registry. Signature verification with
            // a 300s tolerance; an unverified or replayed-pending delivery fails loudly
            // so Stripe retries. No config = fail closed.
            if ($path === '/webhooks/stripe' && $method === 'POST') {
                $raw = (string) file_get_contents('php://input');
                if ((string) ($config['stripe_webhook_secret'] ?? '') === ''
                    || !StripeWebhook::verify($raw, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), (string) $config['stripe_webhook_secret'])) {
                    http_response_code(403);
                    exit('bad signature');
                }
                $event = json_decode($raw, true);
                if (!is_array($event)) {
                    http_response_code(400);
                    exit('bad payload');
                }
                header('Content-Type: text/plain');
                exit($webhookHandler()->handle($event));
            }
            if ($path === '/billing/return' && $method === 'GET') {
                $view('return.php', ['title' => $lang['return_heading']]);
            }
            if ($path === '/billing/portal' && $method === 'GET') {
                $view('portal.php', ['title' => $lang['portal_heading'], 'sent' => false]);
            }
            if ($path === '/billing/portal' && $method === 'POST') {
                Csrf::check($_POST['csrf'] ?? null);
                // Every claim opens a Stripe portal session per matching row
                // and sends mail: the public form carries its own bucket so it
                // cannot be used as a mail-bomb or a Stripe-quota faucet.
                $clientIp = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
                if (!RateLimit::hit($registry->pdo(), 'portal:' . $clientIp, 10, 3600)) {
                    http_response_code(429);
                    $view('error.php', ['title' => $lang['rate_limited_heading'], 'message' => $lang['rate_limited_body']]);
                }
                $email = (string) ($_POST['email'] ?? '');
                foreach ($tenants->byEmail($email) as $t) {
                    if (($t['stripe_customer_id'] ?? '') === '') continue;
                    $mail($email, $lang['portal_mail_subject'] . ': ' . $t['title'],
                        $lang['portal_mail_body'] . ' ' . $t['title'] . ":\n" . $stripe->portalSession((string) $t['stripe_customer_id']) . "\n");
                }
                $view('portal.php', ['title' => $lang['portal_heading'], 'sent' => true]);
            }
            if ($path === '/suspended' && $method === 'GET') {
                // The reverse proxy routes suspended hosts here; the registry confirms before explaining.
                $t = $tenants->byHost(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
                $name = (string) ($t['title'] ?? $lang['suspended_default_name']);
                $view('suspended.php', ['title' => $name, 'name' => $name]);
            }
            // Default root visitors and suspended tenants whose homepage was requested
            // directly: the suspension notice must be the homepage's answer, not a 404.
            $hostTenant = $tenants->byHost(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
            if ($hostTenant !== null && $hostTenant['status'] === 'suspended') {
                $view('suspended.php', ['title' => (string) $hostTenant['title'], 'name' => (string) $hostTenant['title']]);
            }
            http_response_code(404);
            $view('error.php', ['title' => $lang['not_found_heading'], 'message' => $lang['not_found_body']]);
        } catch (\Throwable $e) {
            error_log('saas: ' . $e->getMessage());
            http_response_code(500);
            $view('error.php', ['title' => $lang['error_heading'], 'message' => $lang['error_body']]);
        }
    }
}
