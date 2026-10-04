<?php // tests/SaasPlansTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\Plans;
use PHPUnit\Framework\TestCase;

final class SaasPlansTest extends TestCase
{
    private array $config = ['plans' => [
        'standard' => ['label' => 'Standard', 'powered_by' => true],
        'pro' => ['label' => 'Pro', 'powered_by' => false],
    ]];

    public function test_get_returns_the_catalog_row(): void
    {
        self::assertSame('Standard', Plans::get($this->config, 'standard')['label']);
        self::assertSame('Pro', Plans::get($this->config, 'pro')['label']);
    }

    public function test_unknown_plan_throws(): void
    {
        $this->expectException(\DomainException::class);
        Plans::get($this->config, 'gold');
    }

    public function test_powered_by_flag_varies_per_plan(): void
    {
        self::assertTrue(Plans::poweredBy($this->config, 'standard'));
        self::assertFalse(Plans::poweredBy($this->config, 'pro'));
    }
}
