<?php // tests/SaasMapGenTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\MapGen;
use KipSaaS\Registry;
use KipSaaS\Tenants;
use PHPUnit\Framework\TestCase;

final class SaasMapGenTest extends TestCase
{
    private Tenants $tenants;

    protected function setUp(): void
    {
        $this->tenants = new Tenants((new Registry('sqlite:' . sys_get_temp_dir() . '/map-' . bin2hex(random_bytes(4)) . '.sqlite'))->pdo());
        $this->tenants->create('acme', 'acme.saas.example.test', 'standard', 'a@example.test', 'A', 'h', '2026-01-01T00:00:00Z');
        $this->tenants->create('bloc', 'bloc.saas.example.test', 'pro', 'b@example.test', 'B', 'h', '2026-01-01T00:00:00Z');
        $this->tenants->setStatus((int) $this->tenants->bySlug('acme')['id'], 'verified');
        $this->tenants->setStatus((int) $this->tenants->bySlug('acme')['id'], 'active');
        $this->tenants->setStatus((int) $this->tenants->bySlug('bloc')['id'], 'verified');
        $this->tenants->setStatus((int) $this->tenants->bySlug('bloc')['id'], 'active');
        $this->tenants->setStatus((int) $this->tenants->bySlug('bloc')['id'], 'suspended', ['purge_after' => '2026-03-01T00:00:00Z']);
        // Custom-domain claims: seeded directly because MapGen's unit under
        // test is emission, not the claim workflow (SaasDomainServiceTest
        // covers that). acme's is live, bloc's is live under a suspended
        // tenant, and acme's pending claim must never reach the map.
        $claim = $this->tenants->pdo()->prepare('INSERT INTO tenant_domains (tenant_id, domain, status) VALUES (?, ?, ?)');
        $claim->execute([(int) $this->tenants->bySlug('acme')['id'], 'acme.example.com', 'active']);
        $claim->execute([(int) $this->tenants->bySlug('bloc')['id'], 'bloc.example.net', 'active']);
        $claim->execute([(int) $this->tenants->bySlug('acme')['id'], 'unverified.example.org', 'pending']);
    }

    public function test_renders_active_to_tenants_and_suspended_to_notice_root(): void
    {
        $gen = new MapGen($this->tenants, ['tenants_root' => '/srv/saas/tenants',
            'empty_root' => '/srv/saas/empty/public', 'control_public_root' => '/srv/saas/control/public',
            'control_host' => 'control.saas.example.test']);
        $expected = "map \$http_host \$tenant_root {\n"
            . "    default /srv/saas/empty/public;\n"
            . "    control.saas.example.test /srv/saas/control/public;\n"
            . "    acme.saas.example.test /srv/saas/tenants/acme/public;\n"
            . "    acme.example.com /srv/saas/tenants/acme/public;\n"
            . "    bloc.saas.example.test /srv/saas/control/public;\n"
            . "    bloc.example.net /srv/saas/control/public;\n"
            . "}\n";
        self::assertSame($expected, $gen->render());
    }

    public function test_publish_writes_atomically_and_skips_unchanged_content(): void
    {
        $file = sys_get_temp_dir() . '/map-' . bin2hex(random_bytes(4)) . '.conf';
        $gen = new MapGen($this->tenants, ['tenants_root' => '/srv/t', 'empty_root' => '/srv/e',
            'control_public_root' => '/srv/c', 'control_host' => 'control.saas.example.test',
            'map_file' => $file, 'reload' => false]);
        $gen->publish();
        self::assertFileExists($file);
        self::assertStringStartsWith('map $http_host $tenant_root {', (string) file_get_contents($file));
        $mtime = filemtime($file);
        clearstatcache(true, $file);
        $gen->publish(); // unchanged content: no rewrite, no reload (cron runs this every 10 minutes)
        self::assertSame($mtime, filemtime($file));
        @unlink($file);
    }

    public function test_a_failed_nginx_t_restores_the_previous_map_and_throws(): void
    {
        // The reload gate: when nginx -t rejects the freshly published map,
        // the live config must stay loadable. The old bytes are kept in
        // memory before the rename, so a failing check puts them back.
        $file = sys_get_temp_dir() . '/map-' . bin2hex(random_bytes(4)) . '.conf';
        $previous = "map \$http_host \$tenant_root {\n    default /previous;\n}\n";
        file_put_contents($file, $previous);
        $bin = sys_get_temp_dir() . '/mapbin-' . bin2hex(random_bytes(4));
        mkdir($bin);
        file_put_contents($bin . '/nginx', "#!/bin/sh\necho 'nginx: [emerg] map is broken' >&2\nexit 1\n");
        chmod($bin . '/nginx', 0755);
        $oldPath = getenv('PATH');
        putenv('PATH=' . $bin . ':' . $oldPath); // exec() inherits PATH: the fixture nginx fails the check
        try {
            $gen = new MapGen($this->tenants, ['tenants_root' => '/srv/t', 'empty_root' => '/srv/e',
                'control_public_root' => '/srv/c', 'control_host' => 'control.saas.example.test',
                'map_file' => $file, 'reload' => true]);
            $thrown = null;
            try {
                $gen->publish();
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
            self::assertNotNull($thrown, 'a failing nginx -t must throw');
            self::assertStringContainsString('nginx -t failed', $thrown->getMessage());
            self::assertSame($previous, (string) file_get_contents($file), 'the previous map must be back on disk');
            self::assertFileDoesNotExist($file . '.tmp' . getmypid());
        } finally {
            putenv('PATH=' . $oldPath);
            @unlink($file);
            @rmdir($bin);
        }
    }
}
