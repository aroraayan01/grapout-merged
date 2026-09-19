<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

/*
 * Served under a path prefix — grapout.com/trade — the request arrives as
 * /trade/api/v1/... while the routes know only /api/v1/.... APP_URL_PREFIX
 * in .env names the prefix; it is taken off here, once, before anything else
 * looks at the request. Unset locally, where the dev server strips it.
 *
 * APP_BASE_PATH is still read as a fallback so a server whose .env predates
 * the rename keeps working; it must not be the primary name, because Laravel
 * uses it for the app's path on disk.
 */
$prefixVar = 'APP_URL_PREFIX';
$basePath = rtrim((string) (getenv($prefixVar) ?: ($_SERVER[$prefixVar] ?? '')), '/');
if ($basePath === '' && is_file(__DIR__.'/../.env')) {
    if (preg_match('/^(?:APP_URL_PREFIX|APP_BASE_PATH)=(.*)$/m', (string) file_get_contents(__DIR__.'/../.env'), $m)) {
        $basePath = rtrim(trim($m[1], " \t\"'"), '/');
    }
}
if ($basePath !== '') {
    foreach (['REQUEST_URI', 'PATH_INFO', 'ORIG_PATH_INFO'] as $key) {
        if (isset($_SERVER[$key]) && str_starts_with($_SERVER[$key], $basePath . '/')) {
            $_SERVER[$key] = substr($_SERVER[$key], strlen($basePath));
        } elseif (isset($_SERVER[$key]) && $_SERVER[$key] === $basePath) {
            $_SERVER[$key] = '/';
        }
    }
}

$app->handleRequest(Request::capture());
