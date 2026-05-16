<?php

/**
 * Sidecar HTTP entry-point.
 *
 * Deploy patterns:
 *   - php-fpm + nginx: map all requests to this script.
 *   - Built-in dev server: php -S 0.0.0.0:8181 bin/sidecar.php
 *
 * The sidecar exposes three routes:
 *   - GET /health
 *   - POST /webhooks/quickbooks-online
 *   - GET /oauth/callback
 *
 * @license Apache-2.0 OR GPL-2.0-or-later
 */

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

use OpenSalesTax\QuickBooksOnline\Config\Config;
use OpenSalesTax\QuickBooksOnline\Config\ConfigException;
use OpenSalesTax\QuickBooksOnline\Http\PhpSapiAdapter;
use OpenSalesTax\QuickBooksOnline\Service\Bootstrap;
use OpenSalesTax\QuickBooksOnline\Service\Router;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "missing vendor/autoload.php — run `composer install` first.\n";
    exit(1);
}
require $autoload;

try {
    $config = new Config();
    $webhookHandler = Bootstrap::webhookHandler($config);
    $oauthHandler = Bootstrap::oauthCallbackHandler($config);
    $router = new Router($webhookHandler, $oauthHandler);
} catch (ConfigException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "sidecar configuration error: " . $e->getMessage() . "\n";
    exit(1);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}
$request = PhpSapiAdapter::buildRequest($_SERVER, $rawBody);
$response = $router->handle($request);
PhpSapiAdapter::emit($response);
