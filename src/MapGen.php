<?php // src/MapGen.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Renders the nginx map from the registry: active and grace-period tenants
 * route to their own public dir, suspended tenants route to the control
 * plane's notice-serving root, everything else falls to the empty root.
 * Host values come from Slug-normalized slugs, so the map file cannot be
 * poisoned through the registry.
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
        foreach ($this->tenants->hostsByStatus('suspended') as $host) {
            $lines[] = '    ' . $host . ' ' . $this->config['control_public_root'] . ';';
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
        $tmp = $file . '.tmp' . getmypid();
        file_put_contents($tmp, $content);
        rename($tmp, $file); // atomic: nginx never reads a half-written map
        if (!empty($this->config['reload'])) {
            exec('nginx -t 2>&1', $out, $code);
            if ($code !== 0) throw new \RuntimeException('nginx -t failed after map write: ' . implode("\n", $out));
            exec('systemctl reload nginx 2>&1', $out2, $code2);
            if ($code2 !== 0) throw new \RuntimeException('nginx reload failed: ' . implode("\n", $out2));
        }
    }
}
