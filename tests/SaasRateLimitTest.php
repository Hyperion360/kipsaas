<?php // tests/SaasRateLimitTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\RateLimit;
use PHPUnit\Framework\TestCase;

final class SaasRateLimitTest extends TestCase
{
    private function pdo(): \PDO
    {
        // The registry's rate_limits shape (Registry lands with the state port);
        // the limiter itself only needs a PDO carrying this table.
        $pdo = new \PDO('sqlite:' . sys_get_temp_dir() . '/rl-' . bin2hex(random_bytes(4)) . '.sqlite', null, null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start INTEGER NOT NULL)');
        return $pdo;
    }

    public function test_fills_up_then_blocks_then_recovers(): void
    {
        $pdo = $this->pdo();
        self::assertTrue(RateLimit::hit($pdo, 'signup:1.2.3.4', 3, 60));
        self::assertTrue(RateLimit::hit($pdo, 'signup:1.2.3.4', 3, 60));
        self::assertTrue(RateLimit::hit($pdo, 'signup:1.2.3.4', 3, 60));
        self::assertFalse(RateLimit::hit($pdo, 'signup:1.2.3.4', 3, 60)); // blocked
        self::assertTrue(RateLimit::hit($pdo, 'signup:5.6.7.8', 3, 60)); // other bucket unaffected
        RateLimit::rewind($pdo, 'signup:1.2.3.4'); // test hook: expire the window
        self::assertTrue(RateLimit::hit($pdo, 'signup:1.2.3.4', 3, 60));
    }
}
