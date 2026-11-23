<?php

declare(strict_types=1);

/**
 * Front-Controller des Standalone-Servers. Mit dem eingebauten PHP-Server:
 *   php -S localhost:8080 -t standalone/public standalone/public/index.php
 */

use Merlin\Standalone\App;
use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;
use Merlin\Standalone\Http\Routes;
use Psr\Log\LoggerInterface;

// Eingebauter PHP-Server: vorhandene Dateien (css/, img/) direkt ausliefern.
if (PHP_SAPI === 'cli-server') {
	$file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
	if ($file !== __FILE__ && is_file($file)) {
		return false;
	}
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$app = null;
try {
	$app = App::fromConfigFile();
	$basePath = $app->config()->string('base_path', '/');
	Response::setBasePath($basePath);
	$trustedProxies = $app->config()->get('trusted_proxies', []);
	$trustedProxies = is_array($trustedProxies) ? array_values(array_map('strval', $trustedProxies))
		: preg_split('/[\s,]+/', (string) $trustedProxies, -1, PREG_SPLIT_NO_EMPTY);
	Routes::build($app)->dispatch(Request::fromGlobals($basePath, $trustedProxies))->send();
} catch (\Throwable $e) {
	try {
		$app?->get(LoggerInterface::class)->error('Unbehandelter Fehler', ['exception' => $e]);
	} catch (\Throwable) {
	}
	Response::json(['error' => 'Internal server error'], 500)->send();
}
