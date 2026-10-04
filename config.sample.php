<?php // config.sample.php  ->  copy to config.php (gitignored) and fill in
return [
    'env' => getenv('KIP_ENV') ?: 'prod',
    'registry_dsn' => getenv('SAAS_REGISTRY_DSN') ?: 'sqlite:' . __DIR__ . '/data/registry.sqlite',
    'tenants_root' => getenv('SAAS_TENANTS_ROOT') ?: __DIR__ . '/tenants',
    'code_source' => getenv('SAAS_CODE_SOURCE') ?: '/srv/saas/app', // a FULL app checkout (vendored); in dev set SAAS_CODE_SOURCE to your app checkout
    'base_domain' => getenv('SAAS_BASE_DOMAIN') ?: 'saas.example.test', // tenant host = {slug}.{base_domain}
    'control_base_url' => getenv('SAAS_CONTROL_URL') ?: 'https://control.saas.example.test',
    'token_secret' => getenv('SAAS_TOKEN_SECRET') ?: '', // REQUIRED in prod; the front controller refuses to boot without it
    'lang' => 'en', // lang/<code>.php carries every control-page string; drop in your own pack
    'brand_name' => 'KipSaaS', // shown in the control pages' header and title
    'brand_url' => '/start', // where the brand links
    'mail' => ['transport' => 'log', 'log_path' => __DIR__ . '/data/mail.log', 'from' => 'noreply@saas.example.test'],
    'tenant_smtp' => null, // copied into every tenant config: ['host' => '', 'port' => 587, 'username' => '', 'password' => '', 'from' => '']
    'tenant_app' => null, // REQUIRED before provisioning: your KipSaaS\TenantAppInterface implementation, e.g. new \App\DemoApp()
    'plans' => [
        'standard' => ['price_id' => 'price_REPLACE_ME', 'label' => 'Standard', 'amount_month' => 900, 'storage_gb' => 2, 'powered_by' => true],
        'pro' => ['price_id' => 'price_REPLACE_ME', 'label' => 'Pro', 'amount_month' => 1900, 'storage_gb' => 10, 'powered_by' => false],
    ],
    'grace_days' => 7,     // past_due keeps serving this long
    'retention_days' => 30, // cancelled tenants are purged this long after suspension
    'nginx' => ['map_file' => '/etc/nginx/saas-tenants.map', 'empty_root' => '/srv/saas/empty/public',
                'control_public_root' => '/srv/saas/control/public', 'control_host' => 'control.saas.example.test',
                'reload' => false],
    'stripe_secret' => getenv('SAAS_STRIPE_SECRET') ?: '',
    'stripe_webhook_secret' => getenv('SAAS_STRIPE_WEBHOOK_SECRET') ?: '',
];
