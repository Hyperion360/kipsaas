<?php // demo/public/index.php
declare(strict_types=1);

// The demo front controller: the same shape the Kip skeleton uses. A stamped
// tenant runs this file verbatim against its own vendored framework and its
// own rendered config.
require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config.php';

ob_start(); // lazy session may start mid-render; nothing may flush before headers

// One https rule for the whole app (session cookie Secure flag here,
// Request::$secure in the kernel).
$https = Kip\Http\Request::secureFromServer(trustedProxy: (bool) ($config['trusted_proxy'] ?? false));

$app = new Kip\App($config, Kip\Session::lazy(new Kip\SessionStarter($https)));
$app->handle(Kip\Http\Request::fromGlobals(trustedProxy: (bool) ($config['trusted_proxy'] ?? false)))->send();
ob_end_flush();
// Work queued with App::defer() runs after the response is out. Under PHP-FPM
// the connection closes first, so the client never waits on it.
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
$app->runDeferred();
