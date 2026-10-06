<?php // public/index.php
declare(strict_types=1);

// Autoload first: the config legitimately binds engine objects (tenant_app,
// a provisioner override), which cannot be constructed without it.
require dirname(__DIR__) . '/vendor/autoload.php';

// The app root, not the package: this file is lifted verbatim into a
// consuming repo (package mode), where dirname(__DIR__) is that APP's
// root; here in template mode it is the repo root, the same path boot()
// would pick on its own. SAAS_CONFIG stays the operator override.
KipSaaS\ControlApp::boot(getenv('SAAS_CONFIG') ?: dirname(__DIR__) . '/config.php');
