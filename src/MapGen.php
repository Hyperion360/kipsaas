<?php // src/MapGen.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Renders the nginx map from the registry: active and grace-period tenants
 * route to their own public dir (subdomain and verified custom domain
 * alike), suspended tenants route to the control plane's notice-serving
 * root, everything else falls to the empty root. Host values come from
 * Slug-normalized slugs, custom domains from DomainService's grammar, so
 * the map file cannot be poisoned through the registry.
 */
final class MapGen
{
    public function __construct(private Tenants $tenants, private array $config) {}

    public function render(): string
    {
        // The callers assemble this config from the nginx block plus the
        // top-level tenants_root (the single source of truth: the map must
        // point where the provisioner stamps). Fail loudly rather than
        // render a half-empty root that nginx would happily serve.
        $tenantsRoot = $this->config['tenants_root'] ?? null;
        if (!is_string($tenantsRoot) || $tenantsRoot === '') {
            throw new \InvalidArgumentException('MapGen config needs tenants_root (top-level config key)');
        }
        $lines = ['map $http_host $tenant_root {', '    default ' . $this->config['empty_root'] . ';'];
        // The control plane's own host must be in the map, or /start and
        // /healthz land on the empty root.
        $lines[] = '    ' . $this->config['control_host'] . ' ' . $this->config['control_public_root'] . ';';
        foreach ($this->tenants->hostToSlug() as $host => $slug) {
            $lines[] = '    ' . $host . ' ' . $tenantsRoot . '/' . $slug . '/public;';
        }
        foreach ($this->tenants->domainToSlug() as $domain => $slug) {
            $lines[] = '    ' . $domain . ' ' . $tenantsRoot . '/' . $slug . '/public;';
        }
        foreach ($this->tenants->hostsByStatus('suspended') as $host) {
            $lines[] = '    ' . $host . ' ' . $this->config['control_public_root'] . ';';
        }
        foreach ($this->tenants->suspendedDomains() as $domain) {
            $lines[] = '    ' . $domain . ' ' . $this->config['control_public_root'] . ';';
        }
        $lines[] = '}';
        return implode("\n", $lines) . "\n";
    }

    public function publish(): void
    {
        $file = (string) ($this->config['map_file'] ?? '');
        if ($file === '') return; // dev/test: rendering is the product
        $content = $this->render();
        if (is_file($file) && (string) file_get_contents($file) === $content) {
            return; // idempotent: cron map:write runs every 10 minutes; skip the write and the reload when nothing changed
        }
        // The old bytes ride in memory through the swap: if the reload gate
        // rejects the new map, they go back on disk, so the live nginx
        // config never keeps pointing at a file nginx cannot load.
        $previous = is_file($file) ? (string) file_get_contents($file) : null;
        $tmp = $file . '.tmp' . getmypid();
        file_put_contents($tmp, $content);
        rename($tmp, $file); // atomic: nginx never reads a half-written map
        if (!empty($this->config['reload'])) {
            exec('nginx -t 2>&1', $out, $code);
            if ($code !== 0) {
                $this->restore($file, $previous);
                throw new \RuntimeException('nginx -t failed after map write (previous map restored): ' . implode("\n", $out));
            }
            exec('systemctl reload nginx 2>&1', $out2, $code2);
            if ($code2 !== 0) throw new \RuntimeException('nginx reload failed: ' . implode("\n", $out2));
        }
    }

    /** Put the previous map back, atomically; null previous means there was no map before this write. */
    private function restore(string $file, ?string $previous): void
    {
        if ($previous === null) {
            @unlink($file);
            return;
        }
        $tmp = $file . '.tmp' . getmypid();
        file_put_contents($tmp, $previous);
        rename($tmp, $file);
    }
}
