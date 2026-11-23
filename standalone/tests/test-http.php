<?php

declare(strict_types=1);

/**
 * Ende-zu-Ende-Test des Standalone-Servers über HTTP: startet den
 * eingebauten PHP-Server mit einer frischen SQLite-Datenbank und spielt
 * durch, was die Clients brauchen: /api/capabilities, Admin anlegen
 * (bin/merlin), Login Flow v2 wie in den Browser-Erweiterungen und Apps,
 * Basic Auth mit dem Token, Admin-Nutzerverwaltung, Löschen eines Kontos
 * samt seiner merlin_*-Zeilen, Bilder unter /img/.
 *
 * Aufruf: php standalone/tests/test-http.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
	if (!$ok) {
		$failures++;
	}
}

$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/merlin-http-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$port = 0;
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
fclose($probe);
$base = "http://127.0.0.1:{$port}";
file_put_contents($dir . '/config.php', '<?php return ' . var_export([
	'db_path' => $dir . '/merlin.sqlite',
	'log_path' => $dir . '/merlin.log',
	'base_url' => $base,
	'secret' => base64_encode(random_bytes(32)),
], true) . ';');
putenv('MERLIN_CONFIG=' . $dir . '/config.php');

$cli = static function (string $args) use ($root, $dir): array {
	exec('MERLIN_CONFIG=' . escapeshellarg($dir . '/config.php') . ' ' . escapeshellarg(PHP_BINARY) . ' '
		. escapeshellarg($root . '/bin/merlin') . ' ' . $args . ' 2>&1', $out, $code);
	return [$code, implode("\n", $out)];
};

[$code, $out] = $cli('migrate');
check($code === 0, 'bin/merlin migrate', $out);
[$code, $out] = $cli('create-admin --username=admin --email=admin@example.org --password=geheim123');
check($code === 0 && str_contains($out, "'admin'"), 'bin/merlin create-admin', $out);
[$code] = $cli('create-admin --username=admin --email=x@example.org --password=geheim123');
check($code === 1, 'create-admin: vergebener Name wird abgelehnt');

$server = proc_open(
	[PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . '/public', $root . '/public/index.php'],
	[0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'a'], 2 => ['file', $dir . '/server.log', 'a']],
	$pipes,
	null,
	['MERLIN_CONFIG' => $dir . '/config.php'] + getenv(),
);
for ($i = 0; $i < 50; $i++) {
	$socket = @fsockopen('127.0.0.1', $port);
	if ($socket !== false) {
		fclose($socket);
		break;
	}
	usleep(100_000);
}

/** @return array{0: int, 1: mixed, 2: string, 3: array<string, string>} Status, JSON, Body, Header */
$http = static function (string $method, string $path, ?array $json = null, array $headers = [], ?array $form = null, ?string $cookieJar = null) use ($base): array {
	$ch = curl_init($base . $path);
	$sendHeaders = [];
	foreach ($headers as $name => $value) {
		$sendHeaders[] = "{$name}: {$value}";
	}
	if ($json !== null) {
		$sendHeaders[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
	} elseif ($form !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
	}
	if ($cookieJar !== null) {
		curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
	}
	$responseHeaders = [];
	curl_setopt_array($ch, [
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => $sendHeaders,
		CURLOPT_TIMEOUT => 10,
		CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
			if (str_contains($line, ':')) {
				[$name, $value] = explode(':', $line, 2);
				$responseHeaders[strtolower(trim($name))] = trim($value);
			}
			return strlen($line);
		},
	]);
	$body = (string) curl_exec($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return [$status, json_decode($body, true), $body, $responseHeaders];
};

try {
	[$status, $caps] = $http('GET', '/api/capabilities');
	check($status === 200 && ($caps['backend'] ?? null) === 'standalone' && ($caps['apiVersion'] ?? null) === 1, 'capabilities ohne Anmeldung', json_encode($caps));
	check(preg_match('/^\d+\.\d+\.\d+/', (string) ($caps['version'] ?? '')) === 1, 'capabilities: gemeinsame Versionsnummer', (string) ($caps['version'] ?? ''));
	check(!in_array('files', $caps['features'] ?? ['files'], true), 'capabilities: Merlin Dateien aus');

	[$status] = $http('GET', '/account/tokens');
	check($status === 401, 'ohne Anmeldung: 401');
	[$status, , $body] = $http('GET', '/login');
	check($status === 200 && str_contains($body, '<form') && str_contains($body, 'password'), 'Login-Seite');
	[$status, , , $headers] = $http('GET', '/register');
	check($status === 303 && str_ends_with($headers['location'] ?? '', '/login'), 'Registrierung ohne Freigabe leitet zum Login');

	// Login Flow v2 wie die Clients: starten, Browser-Formular, pollen.
	[$status, $flow] = $http('POST', '/login/v2');
	$loginUrl = (string) ($flow['login'] ?? '');
	check($status === 200 && str_starts_with($loginUrl, $base . '/login/v2/flow/') && isset($flow['poll']['token'], $flow['poll']['endpoint']), 'Login Flow v2: start', json_encode($flow));
	[$status] = $http('POST', '/login/v2/poll', ['token' => $flow['poll']['token'] ?? '']);
	check($status === 404, 'Login Flow v2: poll vor dem Login 404');
	$flowPath = substr($loginUrl, strlen($base));
	[$status, , $body] = $http('POST', $flowPath, null, [], ['username' => 'admin', 'password' => 'falsch']);
	check($status === 200 && str_contains($body, 'class="error"'), 'Login Flow v2: falsches Passwort');
	[$status, , $body] = $http('POST', $flowPath, null, [], ['username' => 'admin', 'password' => 'geheim123']);
	check($status === 200 && str_contains($body, 'class="success"'), 'Login Flow v2: Formular');
	[$status, $result] = $http('POST', '/login/v2/poll', ['token' => $flow['poll']['token'] ?? '']);
	check($status === 200 && ($result['loginName'] ?? null) === 'admin' && strlen((string) ($result['appPassword'] ?? '')) === 64 && ($result['server'] ?? null) === $base,
		'Login Flow v2: poll liefert server, loginName, appPassword', json_encode($result));
	[$status] = $http('POST', '/login/v2/poll', ['token' => $flow['poll']['token'] ?? '']);
	check($status === 404, 'Login Flow v2: Poll-Token nur einmal');

	$basic = ['Authorization' => 'Basic ' . base64_encode('admin:' . ($result['appPassword'] ?? ''))];
	[$status, $tokens] = $http('GET', '/account/tokens', null, $basic);
	check($status === 200 && count($tokens) === 1 && $tokens[0]['name'] === 'Login Flow', 'Basic Auth mit dem App-Passwort', json_encode($tokens));
	[$status] = $http('GET', '/account/tokens', null, ['Authorization' => 'Basic ' . base64_encode('jemand:' . ($result['appPassword'] ?? ''))]);
	check($status === 401, 'Token passt nur zum eigenen Benutzernamen');

	[$status, $created] = $http('POST', '/admin/users', ['username' => 'bea', 'email' => 'bea@example.org', 'password' => 'passwort1', 'role' => 'user'], $basic);
	check($status === 201 && ($created['username'] ?? null) === 'bea', 'Admin legt Nutzer an', json_encode($created));
	[$status] = $http('POST', '/admin/users', ['username' => 'bea', 'email' => 'b2@example.org', 'password' => 'passwort1'], $basic);
	check($status === 409, 'doppelter Benutzername: 409');
	[$status, $settings] = $http('PUT', '/admin/settings', ['allowSelfRegistration' => true], $basic);
	check($status === 200 && ($settings['allowSelfRegistration'] ?? null) === true, 'Registrierung freigeben');

	// Web-Login mit Session-Cookie, dann Token anlegen.
	$jar = $dir . '/cookies.txt';
	[$status, , , $headers] = $http('POST', '/login', null, [], ['username' => 'bea', 'password' => 'passwort1'], $jar);
	check($status === 303 && str_ends_with($headers['location'] ?? '', '/'), 'Web-Login leitet zum Reader');
	[$status, , $body] = $http('GET', '/account', null, [], null, $jar);
	check($status === 200 && str_contains($body, 'bea'), 'Kontoseite mit Session');
	[$status] = $http('POST', '/account/tokens', ['name' => 'Firefox'], [], null, $jar);
	check($status === 403, 'Session ohne X-Requested-With: schreibende Anfrage 403 (CSRF)');
	[$status] = $http('POST', '/api/tags', ['name' => 'csrf'], [], null, $jar);
	check($status === 403, 'auch für die gemeinsame API');
	[$status, $token] = $http('POST', '/account/tokens', ['name' => 'Firefox'], ['X-Requested-With' => 'XMLHttpRequest'], null, $jar);
	check($status === 201 && strlen((string) ($token['plainText'] ?? '')) === 64, 'Token über die Session anlegen');

	// Vue-App: Reader unter /, Share-Ansicht unter /s/{token}. Ohne Build
	// (npm run build:standalone) 503 mit Hinweis.
	$built = is_file(dirname(__DIR__) . '/public/app/js/merlin-main.mjs');
	[$status, , $body] = $http('GET', '/', null, [], null, $jar);
	check($built
		? $status === 200 && str_contains($body, 'id="merlin-app"') && str_contains($body, '"loginUrl":"/login"') && str_contains($body, '"appInfo":{"version"')
		: $status === 503 && str_contains($body, 'build:standalone'), 'Reader-Seite mit Session', substr($body, 0, 300));
	[$status, , , $headers] = $http('GET', '/');
	check($status === 303 && str_ends_with($headers['location'] ?? '', '/login'), 'Reader ohne Anmeldung: zum Login');
	[$status, , $body] = $http('GET', '/s/abc_123');
	check($built
		? $status === 200 && str_contains($body, 'id="merlin-public-app"') && str_contains($body, '"shareToken":"abc_123"')
		: $status === 503, 'Share-Seite ohne Anmeldung', substr($body, 0, 300));
	[, , $body] = $http('GET', '/s/' . rawurlencode('</script><b>x'));
	check(!str_contains($body, '</script><b>'), 'Token im Seitenzustand ist maskiert');
	[$status, , , $headers] = $http('POST', '/login', null, [], ['username' => 'bea', 'password' => 'passwort1', 'return' => '/s/abc'], $dir . '/cookies2.txt');
	check($status === 303 && str_ends_with($headers['location'] ?? '', '/s/abc'), 'Login springt zu return zurück');
	[$status, , , $headers] = $http('POST', '/login', null, [], ['username' => 'bea', 'password' => 'passwort1', 'return' => '//evil.example'], $dir . '/cookies3.txt');
	check($status === 303 && !str_contains($headers['location'] ?? '', 'evil'), 'fremdes return-Ziel wird ignoriert');
	[$status] = $http('GET', '/admin/users', null, [], null, $jar);
	check($status === 403, 'Nicht-Admin: Admin-API 403');

	// Konto löschen räumt die merlin_*-Zeilen mit ab (keine Fremdschlüssel).
	$pdo = new PDO('sqlite:' . $dir . '/merlin.sqlite');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$beaId = (string) $created['id'];
	$pdo->prepare("INSERT INTO merlin_articles (user_id, url, title, content, created_at, updated_at) VALUES (?, 'https://example.org/a', 'A', '', '2026-01-01 00:00:00', '2026-01-01 00:00:00')")->execute([$beaId]);
	$pdo->prepare("INSERT INTO merlin_tags (user_id, name, created_at) VALUES (?, 't', '2026-01-01 00:00:00')")->execute([$beaId]);
	$pdo->prepare("INSERT INTO user_settings (user_id, section, name, value) VALUES (?, 'core', 'lang', 'en')")->execute([$beaId]);
	$pdo->prepare("INSERT INTO merlin_articles (user_id, url, title, content, created_at, updated_at) VALUES ('1', 'https://example.org/b', 'B', '', '2026-01-01 00:00:00', '2026-01-01 00:00:00')")->execute();
	[$status] = $http('DELETE', '/admin/users/' . $beaId, null, $basic);
	check($status === 204, 'Admin löscht Nutzer');
	$left = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM merlin_articles WHERE user_id = '{$beaId}') + (SELECT COUNT(*) FROM merlin_tags WHERE user_id = '{$beaId}') + (SELECT COUNT(*) FROM user_settings WHERE user_id = '{$beaId}') + (SELECT COUNT(*) FROM api_tokens WHERE user_id = {$beaId})")->fetchColumn();
	check($left === 0, 'Artikel, Tags, Einstellungen und Tokens des Kontos sind weg');
	check((int) $pdo->query("SELECT COUNT(*) FROM merlin_articles WHERE user_id = '1'")->fetchColumn() === 1, 'Artikel anderer Konten bleiben');
	[$status] = $http('DELETE', '/admin/users/1', null, $basic);
	check($status === 409, 'letzter Admin lässt sich nicht löschen');

	[$status, , $body, $headers] = $http('GET', '/img/x-preview.png');
	check($status === 200 && ($headers['content-type'] ?? '') === 'image/png' && strlen($body) > 100, 'Bild aus nextcloud/img unter /img/');
	[$status] = $http('GET', '/img/..%2Fappinfo%2Finfo.xml');
	check($status === 404, '/img/ liefert nur Bilddateien');
	[$status, , , $headers] = $http('GET', '/css/merlin.css');
	check($status === 200 && str_starts_with($headers['content-type'] ?? '', 'text/css'), 'Stylesheet');
	[$status, $json] = $http('GET', '/gibt-es-nicht');
	check($status === 404 && ($json['error'] ?? '') === 'Not found', 'unbekannte Route: 404');

	$log = (string) @file_get_contents($dir . '/merlin.log');
	check(!str_contains($log, 'ERROR'), 'keine Fehler im Log', $log);
} finally {
	proc_terminate($server);
	proc_close($server);
	foreach (glob($dir . '/*') ?: [] as $file) {
		@unlink($file);
	}
	@rmdir($dir);
}

if ($failures > 0) {
	echo "\n{$failures} Fehler\n";
	exit(1);
}
echo "\nalle Prüfungen ok\n";
