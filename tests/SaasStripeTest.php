<?php // tests/SaasStripeTest.php
declare(strict_types=1);

namespace KipSaaS\Tests;

use KipSaaS\StripeClient;
use KipSaaS\StripeHttp;
use PHPUnit\Framework\TestCase;

final class SaasStripeTest extends TestCase
{
    public function test_checkout_session_carries_tenant_and_price(): void
    {
        $http = new class implements StripeHttp {
            public array $calls = [];
            public function post(string $path, array $form): array
            {
                $this->calls[] = ['path' => $path, 'form' => $form];
                return ['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'];
            }
        };
        $client = new StripeClient($http, ['control_base_url' => 'https://control.saas.example.test']);
        $url = $client->checkoutSession(
            ['id' => 7, 'owner_email' => 'ow@example.test'],
            'price_x'
        );
        self::assertSame('https://checkout.stripe.com/c/pay/cs_1', $url);
        $call = $http->calls[0];
        self::assertSame('/v1/checkout/sessions', $call['path']);
        self::assertSame('subscription', $call['form']['mode']);
        self::assertSame('7', $call['form']['client_reference_id']);
        self::assertSame('price_x', $call['form']['line_items[0][price]']);
        self::assertSame('7', $call['form']['subscription_data[metadata][tenant_id]']);
        self::assertSame('https://control.saas.example.test/billing/return', $call['form']['success_url']);
    }

    public function test_portal_session_uses_customer_and_return_url(): void
    {
        $http = new class implements StripeHttp {
            public array $calls = [];
            public function post(string $path, array $form): array
            {
                $this->calls[] = ['path' => $path, 'form' => $form];
                return ['url' => 'https://billing.stripe.com/p/session/1'];
            }
        };
        $client = new StripeClient($http, ['control_base_url' => 'https://control.saas.example.test']);
        $url = $client->portalSession('cus_9');
        self::assertSame('https://billing.stripe.com/p/session/1', $url);
        self::assertSame('cus_9', $http->calls[0]['form']['customer']);
    }
}
