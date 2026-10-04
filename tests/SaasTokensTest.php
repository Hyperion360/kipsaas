<?php // tests/SaasTokensTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\Tokens;
use PHPUnit\Framework\TestCase;

final class SaasTokensTest extends TestCase
{
    public function test_issue_and_accept(): void
    {
        $t = Tokens::issue('s3cret');
        self::assertTrue(Tokens::valid($t['token'], 's3cret', $t['hash'], $t['expires_at']));
    }

    public function test_rejects_forged_and_expired(): void
    {
        $t = Tokens::issue('s3cret');
        $forged = $t['token'] . 'x'; // mutated payload/signature
        self::assertFalse(Tokens::valid($forged, 's3cret', $t['hash'], $t['expires_at']));
        self::assertFalse(Tokens::valid('completely.made-up', 's3cret', $t['hash'], $t['expires_at']));
        self::assertFalse(Tokens::valid($t['token'], 'wrong-secret', $t['hash'], $t['expires_at']));
        self::assertFalse(Tokens::valid($t['token'], 's3cret', $t['hash'], '2020-01-01T00:00:00Z'));
        self::assertFalse(Tokens::valid('no-dot-at-all', 's3cret', hash('sha256', 'no-dot-at-all'), '2030-01-01T00:00:00Z'));
    }

    public function test_hash_is_not_reversible_into_the_token(): void
    {
        $t = Tokens::issue('s3cret');
        self::assertStringNotContainsString($t['token'], $t['hash']);
    }
}
