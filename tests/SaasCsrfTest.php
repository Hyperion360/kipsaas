<?php // tests/SaasCsrfTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\Csrf;
use PHPUnit\Framework\TestCase;

final class SaasCsrfTest extends TestCase
{
    public function test_token_stable_per_session_and_checked(): void
    {
        $_SESSION = [];
        $a = Csrf::token();
        self::assertSame($a, Csrf::token()); // stable within the session
        self::assertStringContainsString($a, Csrf::field());
        self::assertStringContainsString('name="csrf"', Csrf::field());
        $_SESSION['csrf'] = $a;
        Csrf::check($a); // valid passes
        $this->expectException(\RuntimeException::class);
        Csrf::check($a . 'x'); // forged fails
    }
}
