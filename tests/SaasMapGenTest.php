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
            . "    bloc.saas.example.test /srv/saas/control/public;\n"
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
}
