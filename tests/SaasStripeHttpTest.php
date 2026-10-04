<?php // tests/SaasStripeHttpTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\StripeError;
use KipSaaS\StripeHttp;
use KipSaaS\StreamStripeHttp;
use PHPUnit\Framework\TestCase;

final class SaasStripeHttpTest extends TestCase
{
    public function test_error_carries_status_and_message(): void
    {
        $e = new StripeError(402, 'card declined');
        self::assertSame(402, $e->status);
        self::assertSame('stripe 402: card declined', $e->getMessage());
    }

    public function test_stream_http_implements_the_seam(): void
    {
        // The streams implementation itself is exercised in the go-live smoke
        // (docs/DEPLOY.md): it needs real TLS to api.stripe.com. Here we pin
        // only that it satisfies the seam tests fake against.
        self::assertInstanceOf(StripeHttp::class, new StreamStripeHttp('sk_test_x'));
    }
}
