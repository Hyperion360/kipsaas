<?php // public/index.php
declare(strict_types=1);

// Autoload first: the config legitimately binds engine objects (tenant_app,
// a provisioner override), which cannot be constructed without it.
require dirname(__DIR__) . '/vendor/autoload.php';

KipSaaS\ControlApp::boot();
