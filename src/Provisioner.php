<?php // src/Provisioner.php
declare(strict_types=1);

namespace KipSaaS;

/** Your app's install ritual. The engine calls these; you implement them once. */
interface ProvisionerInterface
{
    /** Stamp and activate a tenant install. @return array{mail_sent: bool, one_time_password: ?string} */
    public function provision(array $tenant): array;
    /** A suspended customer paid again inside the retention window: restore service. */
    public function resume(array $tenant): void;
    public function suspend(array $tenant): void;
    public function purge(array $tenant): void;
}

/** Your app's data layer: the reference provisioner creates the owner row through it. */
interface TenantAppInterface
{
    /** Create the tenant's first admin/owner. Return a one-time password the caller hands over out of band. */
    public function createOwner(\PDO $tenantDb, array $tenant): string;
    /** CLI command that seeds a fresh tenant install (schema is the engine's; bootstrap data is yours). */
    public function seedCommand(): string;
}

/**
 * The kit's reference provisioner: stamps each tenant as a complete copy of
 * the consuming app's code_source directory with its own SQLite files, its
 * own rendered config, and an owner row created through the injected
 * TenantAppInterface. The app adapter is injected, so the kit carries no
 * app knowledge.
 */
final class ReferenceProvisioner implements ProvisionerInterface
{
    private const COPY_EXCLUDES = ['config.php', '.git', '.gstack', 'tests', 'docs', 'resources',
        'qa-full-reports', 'daily-qa-reports', '.claude', '.env',
        'app/data.sqlite', 'app/data.sqlite-wal', 'app/data.sqlite-shm', 'app/logs.sqlite', 'app/cache.sqlite',
        'app/backups', 'app/mail.log', 'app/maintenance.lock',
        'app/nav.json', 'public/cache', 'public/uploads', 'public/robots.txt'];

    /** @param \Closure(string,string,string):void $mail (to, subject, body) */
    public function __construct(private array $config, private \Closure $mail, private TenantAppInterface $app) {}

    public function provision(array $tenant): array
    {
        $dir = $this->tenantDir($tenant);
        if (is_dir($dir)) throw new \RuntimeException("tenant directory already exists: {$tenant['slug']}");
        try {
            $this->copySkeleton($this->config['code_source'], $dir);
            file_put_contents($dir . '/config.php', $this->renderConfig($tenant, $dir));
            chmod($dir . '/config.php', 0600);
            $this->assertStampIntegrity($this->config['code_source'], $dir);
            $this->run($dir, 'migrate');
            $this->run($dir, $this->app->seedCommand());
            $oneTime = $this->app->createOwner($this->ownerDb($dir), $tenant);
        } catch (\Throwable $e) {
            $this->rmDir($dir); // a half-built tenant must never block its own retry
            throw new \RuntimeException('provisioning ' . $tenant['slug'] . ' failed: ' . $e->getMessage(), 0, $e);
        }
        $url = 'https://' . $tenant['host'];
        if (is_array($this->config['tenant_smtp'] ?? null)) {
            // The SMTP caller is a webhook: it cannot hand a password over out
            // of band, so the welcome mail carries the one-time password
            // itself (same trust channel the verify link used). Without this,
            // owners of apps without a password-reset flow could never sign
            // in: the mail used to point at a reset the app may not have.
            ($this->mail)($tenant['owner_email'], 'Your site is ready: ' . $tenant['title'],
                "{$tenant['title']} is live at {$url}\n\n"
                . "The owner account is {$tenant['owner_email']}.\n"
                . "One-time sign-in password: {$oneTime}\n"
                . "Sign in at {$url} with it, then change it through your site's\n"
                . "account settings or password reset.\n");
            return ['mail_sent' => true, 'one_time_password' => null];
        }
        // No tenant SMTP configured: welcome mail cannot arrive, so onboarding
        // falls back to a one-time password the CALLER hands over out of band
        // (the CLI prints it for the operator; the self-serve web path refuses
        // to run without SMTP, see the verify routes' guard).
        return ['mail_sent' => false, 'one_time_password' => $oneTime];
    }

    public function resume(array $tenant): void
    {
        // The mirror of suspend(): a paying-again customer gets the site back.
        $lock = $this->tenantDir($tenant) . '/app/maintenance.lock';
        if (is_file($lock)) @unlink($lock);
    }

    public function suspend(array $tenant): void
    {
        // The app's own maintenance mode becomes the suspension screen: no new
        // page to build, and operators already know the lock file.
        $lock = $this->tenantDir($tenant) . '/app/maintenance.lock';
        if (is_dir(dirname($lock))) file_put_contents($lock, (string) time());
    }

    public function purge(array $tenant): void
    {
        $this->rmDir($this->tenantDir($tenant));
    }

    /** The tenant's root under tenants_root; the slug re-validates so a hand-edited registry row can never build an escaping path. */
    private function tenantDir(array $tenant): string
    {
        $slug = (string) $tenant['slug'];
        Slug::normalize($slug); // defence in depth: the registry row must still satisfy the firewall
        return $this->config['tenants_root'] . '/' . $slug;
    }

    private function ownerDb(string $dir): \PDO
    {
        return new \PDO('sqlite:' . $dir . '/app/data.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function copySkeleton(string $source, string $dest): void
    {
        if (!is_dir($dest) && !@mkdir($dest, 0775, true)) { // iterator order is undefined; a root-level file must never race the first mkdir
            throw new \RuntimeException("could not create tenant root {$dest}");
        }
        $this->copyTree($source, $dest, '', []);
    }

    /**
     * A symlinked directory in code_source (composer path-repo vendors in a
     * dev checkout, e.g. vendor/kip/framework -> a framework clone) stamps as
     * REAL files: copy() never follows directory links, and a copied link
     * would point outside the tenant, breaking the portability promise and
     * leaving the package's classes out of the stamp entirely. $seen guards
     * against a pathological link cycle recursing forever.
     */
    private function copyTree(string $srcDir, string $tenantRoot, string $prefix, array $seen): void
    {
        $ri = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($ri as $item) {
            $rel = $prefix . substr((string) $item->getPathname(), strlen($srcDir) + 1);
            if ($this->excluded($rel)) continue;
            $target = $tenantRoot . '/' . $rel;
            if ($item->isDir()) {
                if ($item->isLink()) {
                    $real = (string) $item->getRealPath();
                    if (in_array($real, $seen, true)) continue;
                    if (!is_dir($target) && !@mkdir($target, 0775, true)) {
                        throw new \RuntimeException("could not create {$rel}");
                    }
                    $this->copyTree($real, $tenantRoot, $rel . '/', [...$seen, $real]);
                } elseif (!is_dir($target) && !@mkdir($target, 0775, true)) {
                    throw new \RuntimeException("could not create {$rel}");
                }
            } elseif (!@copy((string) $item->getPathname(), $target)) {
                // A copy that returns false (permissions, disk full) must abort
                // the stamp: "succeeded with files missing" is the worst outcome.
                // The native warning is suppressed because the exception below
                // is the failure report.
                throw new \RuntimeException("could not copy {$rel}");
            }
        }
    }

    private function excluded(string $rel): bool
    {
        // Nested VCS/tool state (a vendored package's own .git reached through
        // a symlinked vendor) never belongs in a stamp, wherever it sits.
        foreach (explode('/', $rel) as $segment) {
            if (in_array($segment, ['.git', '.gstack', '.claude'], true)) return true;
        }
        foreach (self::COPY_EXCLUDES as $ex) {
            if ($rel === $ex || str_starts_with($rel, $ex . '/')) return true;
        }
        return false;
    }

    /**
     * Stamp integrity gate, run before kip migrate consumes the stamp: the
     * set of app/Features/<Name> directories and the migration file counts
     * (per feature plus app/migrations) must equal code_source's. A stamp
     * that lost part of the feature tree would otherwise migrate a partial
     * schema and report success. Runs inside provision()'s try block, so a
     * throw here deletes the half-built tenant and aborts the provisioning.
     */
    private function assertStampIntegrity(string $source, string $stamp): void
    {
        $srcFeatures = $this->featureDirs($source);
        $stampFeatures = $this->featureDirs($stamp);
        if ($srcFeatures !== $stampFeatures) {
            $missing = array_diff($srcFeatures, $stampFeatures);
            throw new \RuntimeException($missing !== []
                ? 'stamp is missing app/Features/' . (string) reset($missing) . ' (' . count($missing) . ' of '
                    . count($srcFeatures) . ' feature dirs)'
                : 'stamp has unexpected app/Features/' . (string) reset(array_diff($stampFeatures, $srcFeatures)));
        }
        foreach ($srcFeatures as $feature) {
            $this->assertMigrationCount(
                $source . '/app/Features/' . $feature . '/migrations',
                $stamp . '/app/Features/' . $feature . '/migrations',
                "app/Features/{$feature}");
        }
        $this->assertMigrationCount($source . '/app/migrations', $stamp . '/app/migrations', 'app/migrations');
    }

    private function assertMigrationCount(string $sourceDir, string $stampDir, string $label): void
    {
        $src = $this->migrationFileCount($sourceDir);
        $stamp = $this->migrationFileCount($stampDir);
        if ($src !== $stamp) {
            throw new \RuntimeException("stamp's {$label} has {$stamp} of {$src} migration files");
        }
    }

    /** @return list<string> sorted names of the app/Features/<Name> dirs ([] when the app has no feature dir at all) */
    private function featureDirs(string $root): array
    {
        $dir = $root . '/app/Features';
        if (!is_dir($dir)) return [];
        $names = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name[0] !== '.' && is_dir($dir . '/' . $name)) $names[] = $name;
        }
        sort($names);
        return $names;
    }

    /** Migration files directly inside a migrations dir (a missing dir counts as 0). */
    private function migrationFileCount(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $count = 0;
        foreach (scandir($dir) ?: [] as $name) {
            if (str_ends_with($name, '.php') && is_file($dir . '/' . $name)) $count++;
        }
        return $count;
    }

    private function renderConfig(array $tenant, string $dir): string
    {
        // DRY: the app source's own config.php is the single source of defaults;
        // only tenant-specific keys are overridden. var_export means no user
        // string is ever spliced into PHP source.
        $cfg = require $this->config['code_source'] . '/config.php';
        $app = $dir . '/app';
        $pub = $dir . '/public';
        $cfg['env'] = 'prod';
        $cfg['db']['dsn'] = 'sqlite:' . $app . '/data.sqlite';
        $cfg['log_db']['dsn'] = 'sqlite:' . $app . '/logs.sqlite';
        $cfg['cache_db']['dsn'] = 'sqlite:' . $app . '/cache.sqlite';
        $cfg['app_dir'] = $app;
        $cfg['uploads']['dir'] = $pub . '/uploads';
        $cfg['static_cache']['dir'] = $pub . '/cache';
        $cfg['public_dir'] = $pub;
        $cfg['nav_file'] = $app . '/nav.json';
        $cfg['backups']['dir'] = $app . '/backups';
        $cfg['base_url'] = 'https://' . $tenant['host'];
        $cfg['site_name'] = (string) $tenant['title'];
        // Stamps follow the operator's topology, not ours: bare nginx passes
        // client-supplied X-Forwarded-* straight through, so trusting them by
        // default lets a tenant visitor spoof an IP past per-IP throttles.
        // Only a TLS-terminating front with origin access restricted opts in.
        $cfg['trusted_proxy'] = (bool) ($this->config['tenant_trusted_proxy'] ?? false);
        $cfg['powered_by'] = Plans::poweredBy($this->config, (string) $tenant['plan']);
        // Per-plan feature limits (note_cap and friends) cross into the tenant
        // config under plan_flags: the tenant app enforces its plan's quotas
        // from here. Billing identity (price_id, amount_month) and the
        // powered_by flag (already rendered above) never cross.
        $flags = Plans::get($this->config, (string) $tenant['plan']);
        unset($flags['price_id'], $flags['amount_month'], $flags['powered_by']);
        $cfg['plan_flags'] = $flags;
        $smtp = $this->config['tenant_smtp'] ?? null;
        if (is_array($smtp)) {
            $cfg['mail'] = ['transport' => 'smtp', 'host' => $smtp['host'], 'port' => (int) $smtp['port'],
                'tls' => true, 'username' => $smtp['username'], 'password' => $smtp['password'], 'from' => $smtp['from']];
        } else {
            // Keep the log transport but NEVER the template's path: __DIR__ in
            // the source config.php evaluates against code_source, so without
            // this override every tenant would append to one shared mail.log.
            $cfg['mail']['log_path'] = $app . '/mail.log';
        }
        return "<?php\nreturn " . var_export($cfg, true) . ";\n";
    }

    private function run(string $dir, string $command): void
    {
        // The command is a fixed name chosen by the app adapter or this class,
        // never user input; every path segment is escapeshellarg'd.
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/bin/kip') . ' ' . escapeshellarg($command);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        if (!is_resource($proc)) throw new \RuntimeException("could not start kip {$command}");
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) throw new \RuntimeException("kip {$command} failed ({$code}): " . substr($out, -500));
    }

    private function rmDir(string $dir): void
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
