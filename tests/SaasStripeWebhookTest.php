<?php // tests/SaasStripeWebhookTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\StripeWebhook;
use PHPUnit\Framework\TestCase;

final class SaasStripeWebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';

    public function test_signature_verification_accepts_and_rejects(): void
    {
        $body = '{"id":"evt_x"}';
        $t = (string) time();
        self::assertTrue(StripeWebhook::verify($body, "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", self::SECRET), self::SECRET));
        self::assertFalse(StripeWebhook::verify($body, "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", 'other'), self::SECRET));
        self::assertFalse(StripeWebhook::verify($body, 't=' . (string) (time() - 4000) . ',v1=' . hash_hmac('sha256', (string) (time() - 4000) . '.' . $body, self::SECRET), self::SECRET), 'stale timestamp');
        self::assertFalse(StripeWebhook::verify($body, '', self::SECRET));
        self::assertFalse(StripeWebhook::verify($body, "t={$t},v1=deadbeef", self::SECRET));
        // Rotation: any one valid v1 entry accepts.
        self::assertTrue(StripeWebhook::verify($body, "t={$t},v1=deadbeef,v1=" . hash_hmac('sha256', "{$t}.{$body}", self::SECRET), self::SECRET));
    }
}
