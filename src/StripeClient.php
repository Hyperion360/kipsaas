<?php // src/StripeClient.php
declare(strict_types=1);

namespace KipSaaS;

/** The only place that speaks Stripe's HTTP API. tenantId travels as client_reference_id AND subscription metadata so every later webhook can find its tenant. */
final class StripeClient
{
    public function __construct(private StripeHttp $http, private array $config) {}

    public function checkoutSession(array $tenant, string $priceId): string
    {
        $base = rtrim((string) $this->config['control_base_url'], '/');
        $session = $this->http->post('/v1/checkout/sessions', [
            'mode' => 'subscription',
            'success_url' => $base . '/billing/return',
            'cancel_url' => $base . '/start',
            'customer_email' => (string) $tenant['owner_email'],
            'client_reference_id' => (string) $tenant['id'],
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => 1,
            'subscription_data[metadata][tenant_id]' => (string) $tenant['id'],
        ]);
        return (string) $session['url'];
    }

    public function portalSession(string $customerId): string
    {
        $base = rtrim((string) $this->config['control_base_url'], '/');
        $session = $this->http->post('/v1/billing_portal/sessions', [
            'customer' => $customerId,
            'return_url' => $base . '/start',
        ]);
        return (string) $session['url'];
    }
}
