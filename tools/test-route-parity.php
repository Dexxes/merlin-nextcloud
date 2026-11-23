<?php

declare(strict_types=1);

/**
 * Routen-Parität (Plan „gemeinsames Repo“, Etappe 4): core/src/Api/routes.php
 * ist die eine Routentabelle der gemeinsamen API. Geprüft wird:
 *
 * - jede Zeile der Tabelle ist vollständig, hat einen Handler
 *   (Klasse::Methode(ApiRequest): ApiResult), und Name sowie Methode+Pfad
 *   kommen nur einmal vor;
 * - die Nextcloud-App (nextcloud/appinfo/routes.php) bietet jede Zeile mit
 *   demselben Namen, Verb und Pfad an, der Controller hat die Methode, ruft
 *   damit den Handler auf und trägt die passenden Zugriffsattribute;
 * - der Standalone-Server (standalone/src/Http/Routes.php) registriert jede
 *   Zeile mit demselben Verb und Pfad;
 * - Routen, die nur eine Variante hat, stehen in NEXTCLOUD_ONLY,
 *   NOT_YET_IN_CORE oder STANDALONE_ONLY. Eine neue Route ohne Eintrag dort
 *   lässt den Test scheitern: neue API gehört in den Kern.
 *
 * Aufruf (nach composer install in nextcloud/ und standalone/):
 *   php tools/test-route-parity.php
 */

$root = dirname(__DIR__);
require_once $root . '/standalone/vendor/autoload.php';

use Merlin\Core\Api\ApiRequest;
use Merlin\Core\Api\ApiResult;
use Merlin\Standalone\App;
use Merlin\Standalone\Config;
use Merlin\Standalone\Http\Routes;

/** Nur in Nextcloud, auch auf Dauer (Merlin Dateien braucht Nextcloud Files). */
const NEXTCLOUD_ONLY = ['file#target', 'file#register', 'file#content', 'file#rename'];

/**
 * Noch nicht in den Kern umgezogen. Wird mit jedem Teil von Etappe 4
 * kürzer; was hier steht, hat der Standalone-Server noch nicht.
 */
const NOT_YET_IN_CORE = [];

/**
 * HTML-Seiten der Vue-App: in beiden Varianten unter demselben Pfad, aber
 * keine API und damit nicht in der Routentabelle des Kerns. Nextcloud:
 * PageController/PublicShareController, Standalone: Controller\AppController.
 */
const PLATFORM_PAGES = ['page#index' => 'GET /', 'public_share#show' => 'GET /s/{token}'];

/** Nur im Standalone-Server: Seiten, Konten, Login Flow v2, Admin-Nutzer, Einstellungsseiten (Methode Pfad). */
const STANDALONE_ONLY = [
	'GET /login', 'POST /login', 'GET /logout', 'GET /register', 'POST /register',
	'GET /password/forgot', 'POST /password/forgot', 'GET /password/reset', 'POST /password/reset',
	'GET /account', 'GET /admin', 'GET /lang/{code}', 'GET /img/{file}',
	'POST /login/v2', 'POST /login/v2/poll', 'GET /login/v2/flow/{token}', 'POST /login/v2/flow/{token}',
	'GET /account/tokens', 'POST /account/tokens', 'DELETE /account/tokens/{id}',
	'GET /admin/users', 'POST /admin/users', 'PUT /admin/users/{id}', 'DELETE /admin/users/{id}',
	'GET /admin/settings', 'PUT /admin/settings',
	// Einstellungsseiten der Vue-App; in Nextcloud ISettings statt Routen
	'GET /settings', 'GET /settings/admin',
];

$errors = [];
$checks = 0;
$fail = static function (string $message) use (&$errors): void {
	$errors[] = $message;
};

// ── Routentabelle des Kerns ──────────────────────────────────────────────────
$core = require $root . '/core/src/Api/routes.php';
$names = [];
$keys = [];
foreach ($core as $i => $route) {
	$checks++;
	foreach (['name', 'verb', 'url', 'handler', 'access'] as $field) {
		if (!isset($route[$field])) {
			$fail("routes.php Zeile {$i}: '{$field}' fehlt");
			continue 2;
		}
	}
	$key = $route['verb'] . ' ' . $route['url'];
	if (isset($names[$route['name']])) {
		$fail("routes.php: Name {$route['name']} doppelt");
	}
	if (isset($keys[$key])) {
		$fail("routes.php: {$key} doppelt");
	}
	$names[$route['name']] = true;
	$keys[$key] = true;
	if (!in_array($route['access'], ['user', 'admin', 'public'], true)) {
		$fail("routes.php {$route['name']}: access '{$route['access']}' unbekannt");
	}
	if (isset($route['rateLimit']) && (!is_array($route['rateLimit']) || count($route['rateLimit']) !== 2
		|| !is_int($route['rateLimit'][0]) || !is_int($route['rateLimit'][1]) || $route['access'] !== 'public')) {
		$fail("routes.php {$route['name']}: rateLimit muss [limit, period] an einer öffentlichen Route sein");
	}
	if (isset($route['userRateLimit']) && (!is_array($route['userRateLimit']) || count($route['userRateLimit']) !== 2
		|| !is_int($route['userRateLimit'][0]) || !is_int($route['userRateLimit'][1]) || $route['access'] === 'public')) {
		$fail("routes.php {$route['name']}: userRateLimit muss [limit, period] an einer Route mit Anmeldung sein");
	}
	if (isset($route['csrf']) && ($route['csrf'] !== true || $route['access'] !== 'user')) {
		$fail("routes.php {$route['name']}: csrf ist nur als true an einer 'user'-Route vorgesehen ('admin' verlangt den requesttoken immer)");
	}
	foreach ($route['requirements'] ?? [] as $placeholder => $regex) {
		if (!str_contains($route['url'], '{' . $placeholder . '}') || @preg_match('#^' . $regex . '$#', '') === false) {
			$fail("routes.php {$route['name']}: requirements für {{$placeholder}} passt nicht zum Pfad oder ist kein Regex");
		}
	}
	[$class, $method] = $route['handler'];
	if (!method_exists($class, $method)) {
		$fail("routes.php {$route['name']}: Handler {$class}::{$method} fehlt");
		continue;
	}
	$reflection = new ReflectionMethod($class, $method);
	$params = $reflection->getParameters();
	$type = $params[0]?->getType();
	if (count($params) !== 1 || !$type instanceof ReflectionNamedType || $type->getName() !== ApiRequest::class
		|| (string) $reflection->getReturnType() !== ApiResult::class) {
		$fail("routes.php {$route['name']}: {$class}::{$method} muss (ApiRequest): ApiResult sein");
	}
}

// ── Nextcloud ────────────────────────────────────────────────────────────────
if (!is_file($root . '/nextcloud/vendor/merlin/core/src/Api/routes.php')) {
	$fail('nextcloud/vendor fehlt: vorher composer install in nextcloud/');
} else {
	$nextcloud = (require $root . '/nextcloud/appinfo/routes.php')['routes'];
	$byName = [];
	foreach ($nextcloud as $route) {
		if (isset($byName[$route['name']])) {
			$fail("nextcloud/appinfo/routes.php: Name {$route['name']} doppelt");
		}
		$byName[$route['name']] = $route;
	}
	$controllerSource = static function (string $name) use ($root): ?string {
		$controller = str_replace(' ', '', ucwords(str_replace('_', ' ', explode('#', $name)[0])));
		$file = $root . '/nextcloud/lib/Controller/' . ucfirst($controller) . 'Controller.php';
		return is_file($file) ? (string) file_get_contents($file) : null;
	};
	foreach ($core as $route) {
		$checks++;
		$nc = $byName[$route['name']] ?? null;
		if ($nc === null || $nc['verb'] !== $route['verb'] || $nc['url'] !== $route['url']) {
			$fail("Nextcloud: {$route['name']} fehlt oder weicht ab ({$route['verb']} {$route['url']})");
			continue;
		}
		$method = explode('#', $route['name'])[1];
		$source = $controllerSource($route['name']);
		// Attribute direkt vor der Methode, Aufruf des Handlers im Rumpf.
		if ($source === null || !preg_match('/((?:\s*#\[[^\]]+\])*)\s*public function ' . preg_quote($method, '/') . '\(\)[^{]*\{([^}]*)\}/', $source, $m)) {
			$fail("Nextcloud: Controller-Methode für {$route['name']} fehlt");
			continue;
		}
		if (!str_contains($m[2], '->' . $route['handler'][1] . '(')) {
			$fail("Nextcloud: {$route['name']} ruft nicht den Handler {$route['handler'][1]}() auf");
		}
		$attrs = $m[1];
		$expected = match ($route['access']) {
			'public' => str_contains($attrs, '#[PublicPage]') && str_contains($attrs, '#[NoCSRFRequired]'),
			'user' => str_contains($attrs, '#[NoAdminRequired]') && !str_contains($attrs, '#[PublicPage]')
				&& str_contains($attrs, '#[NoCSRFRequired]') === empty($route['csrf']),
			'admin' => !str_contains($attrs, '#[NoAdminRequired]') && !str_contains($attrs, '#[PublicPage]') && !str_contains($attrs, '#[NoCSRFRequired]'),
			default => false,
		};
		if (!$expected) {
			$fail("Nextcloud: Zugriffsattribute von {$route['name']} passen nicht zu access '{$route['access']}'");
		}
		$rateAttr = isset($route['rateLimit'])
			? "#[AnonRateLimit(limit: {$route['rateLimit'][0]}, period: {$route['rateLimit'][1]})]"
			: null;
		if ($rateAttr !== null ? !str_contains($attrs, $rateAttr) : str_contains($attrs, 'AnonRateLimit')) {
			$fail("Nextcloud: {$route['name']} braucht " . ($rateAttr ?? 'kein #[AnonRateLimit]') . ' wie in routes.php');
		}
		$userRateAttr = isset($route['userRateLimit'])
			? "#[UserRateLimit(limit: {$route['userRateLimit'][0]}, period: {$route['userRateLimit'][1]})]"
			: null;
		if ($userRateAttr !== null ? !str_contains($attrs, $userRateAttr) : str_contains($attrs, 'UserRateLimit')) {
			$fail("Nextcloud: {$route['name']} braucht " . ($userRateAttr ?? 'kein #[UserRateLimit]') . ' wie in routes.php');
		}
	}
	foreach ($byName as $name => $route) {
		$checks++;
		if (isset($names[$name])) {
			continue;
		}
		if (isset(PLATFORM_PAGES[$name])) {
			if (PLATFORM_PAGES[$name] !== "{$route['verb']} {$route['url']}") {
				$fail("Nextcloud: {$name} liegt unter {$route['verb']} {$route['url']}, PLATFORM_PAGES erwartet " . PLATFORM_PAGES[$name]);
			}
		} elseif (!in_array($name, NEXTCLOUD_ONLY, true) && !in_array($name, NOT_YET_IN_CORE, true)) {
			$fail("Nextcloud: {$name} ({$route['verb']} {$route['url']}) steht weder in core/src/Api/routes.php noch in NEXTCLOUD_ONLY/NOT_YET_IN_CORE");
		}
		$method = explode('#', $name)[1];
		$source = $controllerSource($name);
		if ($source === null || !preg_match('/public function ' . preg_quote($method, '/') . '\(/', $source)) {
			$fail("Nextcloud: Controller-Methode für {$name} fehlt");
		}
	}
	foreach ([...NEXTCLOUD_ONLY, ...NOT_YET_IN_CORE, ...array_keys(PLATFORM_PAGES)] as $name) {
		if (isset($names[$name])) {
			$fail("{$name} steht in der Routentabelle des Kerns: aus NEXTCLOUD_ONLY/NOT_YET_IN_CORE streichen");
		} elseif (!isset($byName[$name])) {
			$fail("{$name} gibt es in Nextcloud nicht mehr: aus NEXTCLOUD_ONLY/NOT_YET_IN_CORE streichen");
		}
	}
}

// ── Standalone ───────────────────────────────────────────────────────────────
$dir = sys_get_temp_dir() . '/merlin-routes-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
try {
	$app = new App(new Config([
		'db_path' => $dir . '/merlin.sqlite',
		'log_path' => $dir . '/merlin.log',
		'base_url' => 'http://localhost',
		'secret' => base64_encode(random_bytes(32)),
	]));
	$standalone = array_map(static fn (array $r) => $r['method'] . ' ' . $r['path'], Routes::build($app)->routes());
	foreach ($core as $route) {
		$checks++;
		if (!in_array($route['verb'] . ' ' . $route['url'], $standalone, true)) {
			$fail("Standalone: {$route['verb']} {$route['url']} ({$route['name']}) fehlt");
		}
	}
	foreach ($standalone as $key) {
		$checks++;
		if (!isset($keys[$key]) && !in_array($key, STANDALONE_ONLY, true) && !in_array($key, PLATFORM_PAGES, true)) {
			$fail("Standalone: {$key} steht weder in core/src/Api/routes.php noch in STANDALONE_ONLY/PLATFORM_PAGES");
		}
	}
	foreach ([...STANDALONE_ONLY, ...PLATFORM_PAGES] as $key) {
		if (!in_array($key, $standalone, true)) {
			$fail("STANDALONE_ONLY/PLATFORM_PAGES: {$key} gibt es im Standalone-Server nicht");
		}
	}
	if (count($standalone) !== count(array_unique($standalone))) {
		$fail('Standalone: Route doppelt registriert');
	}
} finally {
	foreach (glob($dir . '/*') ?: [] as $file) {
		@unlink($file);
	}
	@rmdir($dir);
}

echo 'Kern-Routen: ' . count($core) . ', Nextcloud noch ohne Kern: ' . count(NOT_YET_IN_CORE)
	. ', Seiten: ' . count(PLATFORM_PAGES) . ', nur Nextcloud: ' . count(NEXTCLOUD_ONLY) . ', nur Standalone: ' . count(STANDALONE_ONLY) . "\n";
if ($errors !== []) {
	foreach ($errors as $e) {
		echo "  ✗ {$e}\n";
	}
	echo "\n" . count($errors) . " Fehler\n";
	exit(1);
}
echo "{$checks} Prüfungen ok\n";
