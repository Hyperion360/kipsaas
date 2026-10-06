<?php // demo/config.php
// The demo install's own config: the SOURCE CONFIG ReferenceProvisioner
// renders. A stamped tenant gets this array with the per-tenant keys
// (db/log/cache paths, app_dir, base_url, site_name, mail, powered_by,
// plan_flags) overridden by renderConfig; every other key crosses verbatim,
// so running the demo in place and running a provisioned tenant behave
// identically. Paths are __DIR__-relative so the rendered copy is repointed,
// never inherited from this checkout.
return [
    'env' => getenv('KIP_ENV') ?: 'prod',
    'db' => ['dsn' => 'sqlite:' . __DIR__ . '/app/data.sqlite'],
    'log_db' => ['dsn' => 'sqlite:' . __DIR__ . '/app/logs.sqlite', 'retention_days' => 30],
    'cache_db' => ['dsn' => 'sqlite:' . __DIR__ . '/app/cache.sqlite', 'ttl_seconds' => 3600],
    'app_dir' => __DIR__ . '/app',
    'base_url' => getenv('KIP_BASE_URL') ?: 'http://localhost:8080',
    'site_name' => 'Demo Notes',
    'tagline' => 'A notes app stamped whole by the KipSaaS provisioner',
    'trusted_proxy' => false,
    'mail' => ['transport' => 'log', 'log_path' => __DIR__ . '/app/mail.log', 'from' => 'noreply@demo.invalid'],
    'nav_file' => __DIR__ . '/app/nav.json',
];
