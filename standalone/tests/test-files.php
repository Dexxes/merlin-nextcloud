<?php

declare(strict_types=1);

/**
 * Merlin Dateien im Standalone-Server über HTTP: eingebauter PHP-Server,
 * frische SQLite-Datenbank, zwei Konten mit API-Token. Derselbe Ablauf wie
 * nextcloud/tests/Api/FileApiTest.php (Ziel holen, per WebDAV hochladen,
 * eintragen, signierter Link, Umbenennen, Zähler) und dazu, was in
 * Nextcloud dessen WebDAV übernimmt: Chunked Upload v2, Trennung der Konten,
 * ungültige Pfade, Vorschaubilder (GD) und das Aufräumen im täglichen Lauf.
 *
 * Aufruf: php standalone/tests/test-files.php
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
$dir = sys_get_temp_dir() . '/merlin-files-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$name = (string) stream_socket_get_name($probe, false);
$port = (int) substr($name, strrpos($name, ':') + 1);
fclose($probe);
$base = "http://127.0.0.1:{$port}";
$filesRoot = $dir . '/files';
file_put_contents($dir . '/config.php', '<?php return ' . var_export([
	'db_path' => $dir . '/merlin.sqlite',
	'log_path' => $dir . '/merlin.log',
	'files_path' => $filesRoot,
	'base_url' => $base,
	'secret' => base64_encode(random_bytes(32)),
	'background_jobs' => 'cron',
	// Klein, damit das Kontingent (DavController) prüfbar ist.
	'user_quota' => '200K',
], true) . ';');

$cli = static function (string $args) use ($root, $dir): array {
	exec('MERLIN_CONFIG=' . escapeshellarg($dir . '/config.php') . ' ' . escapeshellarg(PHP_BINARY) . ' '
		. escapeshellarg($root . '/bin/merlin') . ' ' . $args . ' 2>&1', $out, $code);
	return [$code, implode("\n", $out)];
};
[$code, $out] = $cli('migrate');
check($code === 0, 'bin/merlin migrate', $out);
[$code, $out] = $cli('create-admin --username=admin --email=admin@example.org --password=geheim123');
check($code === 0, 'bin/merlin create-admin', $out);

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

/**
 * @param array<string, string> $headers
 * @return array{0: int, 1: mixed, 2: string, 3: array<string, string>} Status, JSON, Body, Header
 */
$http = static function (string $method, string $path, array $headers = [], ?string $body = null, ?string $cookieJar = null) use ($base): array {
	$ch = curl_init(str_starts_with($path, 'http') ? $path : $base . $path);
	$sendHeaders = [];
	foreach ($headers as $name => $value) {
		$sendHeaders[] = "{$name}: {$value}";
	}
	if ($body !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
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
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
			if (str_contains($line, ':')) {
				[$name, $value] = explode(':', $line, 2);
				$responseHeaders[strtolower(trim($name))] = trim($value);
			}
			return strlen($line);
		},
	]);
	$response = (string) curl_exec($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return [$status, json_decode($response, true), $response, $responseHeaders];
};
$json = static fn (string $method, string $path, array $auth, array $data) =>
	$http($method, $path, $auth + ['Content-Type' => 'application/json'], json_encode($data));

$tokenFor = static function (string $username, string $password) use ($http, $dir): array {
	$jar = $dir . '/cookies-' . $username . '.txt';
	$http('POST', '/login', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['username' => $username, 'password' => $password]), $jar);
	[, $token] = $http('POST', '/account/tokens', ['X-Requested-With' => 'XMLHttpRequest', 'Content-Type' => 'application/json'], '{"name":"Test"}', $jar);
	return ['Authorization' => 'Basic ' . base64_encode($username . ':' . ($token['plainText'] ?? ''))];
};

/** Pfad und Query eines absoluten oder relativen Links. */
$local = static function (string $url): string {
	$parts = parse_url($url);
	return ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
};

try {
	$admin = $tokenFor('admin', 'geheim123');
	[$status] = $json('POST', '/admin/users', $admin, ['username' => 'bea', 'email' => 'bea@example.org', 'password' => 'passwort1', 'role' => 'user']);
	check($status === 201, 'zweites Konto anlegen');
	$bea = $tokenFor('bea', 'passwort1');

	[, $caps] = $http('GET', '/api/capabilities');
	check(in_array('files', $caps['features'] ?? [], true), 'capabilities: files');

	// ── Ganze Datei ────────────────────────────────────────────────────────
	[$status] = $json('POST', '/api/files/target', [], ['name' => 'x.txt']);
	check($status === 401, 'target ohne Anmeldung: 401', (string) $status);
	[$status, $target] = $json('POST', '/api/files/target', $admin, ['name' => 'Notiz: heute?.txt', 'mimeType' => 'text/plain']);
	check($status === 200 && str_starts_with($target['davPath'] ?? '', '/remote.php/dav/files/1/Other%20files/')
		&& ($target['uploadsPath'] ?? '') === '/remote.php/dav/uploads/1' && ($target['kind'] ?? '') === 'other',
		'target: Pfad in „Other files“', json_encode($target));
	$content = "Hallo Merlin\n" . str_repeat('x', 5000);
	[$status, , , $headers] = $http('PUT', $target['davPath'], $admin + ['Content-Type' => 'text/plain'], $content);
	check($status === 201 && preg_match('/^\d{8}merlin$/', $headers['oc-fileid'] ?? '') === 1, 'PUT: 201 mit OC-FileId', $status . ' ' . json_encode($headers));
	[$status] = $http('PUT', $target['davPath'], $admin + ['Content-Type' => 'text/plain'], $content);
	check($status === 204, 'PUT auf vorhandene Datei: 204', (string) $status);
	[$status] = $http('PUT', $target['davPath'], [], $content);
	check($status === 401, 'PUT ohne Anmeldung: 401', (string) $status);
	[$status] = $http('PUT', $target['davPath'], $bea, 'fremd');
	check($status === 403, 'PUT in fremden Ordner: 403', (string) $status);
	[$status] = $http('PUT', '/remote.php/dav/files/1/Fehlt/x.txt', $admin, 'x');
	check($status === 409, 'PUT ohne Elternordner: 409', (string) $status);
	[$status] = $http('PUT', '/remote.php/dav/files/1/..%2F..%2Fboese.txt', $admin, 'x');
	check($status === 400 && !file_exists($dir . '/boese.txt') && !file_exists($filesRoot . '/boese.txt'), 'PUT mit „..“: 400', (string) $status);
	[$status] = $http('PUT', '/remote.php/dav/files/admin/Other%20files/per-name.txt', $admin, 'x');
	check($status === 201, 'PUT mit Anmeldename statt ID', (string) $status);

	[$status, $entry] = $json('POST', '/api/files', $admin, ['path' => $target['path'], 'text' => 'Notiz zum Text']);
	check($status === 201 && ($entry['fileId'] ?? 0) > 0 && ($entry['fileMime'] ?? '') === 'text/plain'
		&& ($entry['siteName'] ?? '') === 'Merlin Dateien' && str_contains($entry['content'] ?? '', 'Notiz zum Text')
		&& str_contains($entry['url'] ?? '', '/api/articles/' . ($entry['id'] ?? 0) . '/file?t='),
		'register: Eintrag mit signiertem Link (deutsche Texte)', json_encode($entry));
	[$status, $again] = $json('POST', '/api/files', $admin, ['path' => $target['path']]);
	check(($again['id'] ?? null) === ($entry['id'] ?? false), 'register derselben Datei: derselbe Eintrag', json_encode($again));
	[$status] = $json('POST', '/api/files', $admin, ['path' => '/Other files/gibt-es-nicht.txt']);
	check($status === 404, 'register unbekannter Pfad: 404', (string) $status);
	[$status] = $json('POST', '/api/files', $bea, ['path' => $target['path']]);
	check($status === 404, 'register mit fremdem Konto: 404', (string) $status);

	$link = $local($entry['url'] ?? '');
	[$status, , $body, $headers] = $http('GET', $link);
	check($status === 200 && $body === $content, 'Download über signierten Link ohne Anmeldung', $status . ' ' . strlen($body));
	[$status, , $body] = $http('GET', $link, ['Range' => 'bytes=0-4']);
	check($status === 206 && $body === 'Hallo', 'Range-Anfrage: 206', $status . ' ' . $body);
	[$status] = $http('GET', preg_replace('/t=[^&]+/', 't=falsch', $link));
	check($status >= 400, 'falsches Token abgelehnt', (string) $status);

	// ── Umbenennen ─────────────────────────────────────────────────────────
	[$status, $renamed] = $json('PUT', '/api/articles/' . $entry['id'] . '/file-name', $admin, ['name' => 'Umbenannt']);
	check($status === 200 && is_file($filesRoot . '/1/Other files/Umbenannt.txt') && str_contains($renamed['content'] ?? '', 'Umbenannt.txt'),
		'umbenennen, Endung bleibt', $status . ' ' . json_encode(glob($filesRoot . '/1/Other files/*')));
	[$status, , $body] = $http('GET', $local($renamed['url'] ?? ''));
	check($status === 200 && $body === $content, 'Link nach dem Umbenennen gültig', (string) $status);
	[, $second] = $json('POST', '/api/files', $admin, ['path' => '/Other files/per-name.txt']);
	[$status] = $json('PUT', '/api/articles/' . ($second['id'] ?? 0) . '/file-name', $admin, ['name' => 'Umbenannt']);
	check($status === 409, 'umbenennen auf vorhandenen Namen: 409', (string) $status);
	[$status] = $json('PUT', '/api/articles/' . $entry['id'] . '/file-name', $bea, ['name' => 'Fremd']);
	check($status === 404, 'umbenennen mit fremdem Konto: 404', (string) $status);

	[, $counts] = $http('GET', '/api/articles/counts', $admin);
	check(($counts['files']['total'] ?? null) === 2, 'Zähler: zwei Dateien', json_encode($counts['files'] ?? null));

	// ── Chunked Upload v2 ──────────────────────────────────────────────────
	[, $big] = $json('POST', '/api/files/target', $admin, ['name' => 'gross.bin', 'mimeType' => 'application/octet-stream']);
	$uploads = $big['uploadsPath'] . '/transfer-1';
	[$status] = $http('MKCOL', $uploads, $admin + ['Destination' => $base . $big['davPath']]);
	check($status === 201, 'MKCOL: 201', (string) $status);
	[$status] = $http('MKCOL', $uploads, $admin);
	check($status === 405, 'MKCOL doppelt: 405', (string) $status);
	[$status] = $http('MKCOL', '/remote.php/dav/uploads/1/fremd', $bea);
	check($status === 403, 'MKCOL im fremden Upload-Ordner: 403', (string) $status);
	$parts = ['1' => str_repeat('A', 70000), '2' => str_repeat('B', 70000), '10' => 'Ende'];
	foreach (['10', '1', '2'] as $n) {
		[$status] = $http('PUT', $uploads . '/' . $n, $admin, $parts[$n]);
		check($status === 201, "Stück {$n}: 201", (string) $status);
	}
	$whole = $parts['1'] . $parts['2'] . $parts['10'];
	[$status] = $http('MOVE', $uploads . '/.file', $admin + ['Destination' => $base . $big['davPath'], 'OC-Total-Length' => '5']);
	check($status === 400, 'MOVE mit falscher OC-Total-Length: 400', (string) $status);
	[$status] = $http('MOVE', $uploads . '/.file', $bea + ['Destination' => $base . $big['davPath']]);
	check($status === 403, 'MOVE mit fremdem Konto: 403', (string) $status);
	[$status, , , $headers] = $http('MOVE', $uploads . '/.file', $admin + ['Destination' => $base . $big['davPath'], 'OC-Total-Length' => (string) strlen($whole)]);
	check($status === 201 && isset($headers['oc-fileid']) && !is_dir($filesRoot . '/.uploads/1/transfer-1'), 'MOVE .file: zusammengesetzt, Stücke weg', (string) $status);
	[, $bigEntry] = $json('POST', '/api/files', $admin, ['path' => $big['path']]);
	[$status, , $body] = $http('GET', $local($bigEntry['url'] ?? ''));
	check($status === 200 && $body === $whole, 'Stücke in natürlicher Reihenfolge (1, 2, 10)', $status . ' ' . strlen($body));
	// ── Kontingent je Konto (user_quota: 200K), mit bea (noch ohne Dateien) ──
	[, $huge] = $json('POST', '/api/files/target', $bea, ['name' => 'riesig.bin', 'mimeType' => 'application/octet-stream']);
	$beaId = basename((string) $huge['uploadsPath']);
	[$status] = $http('PUT', $huge['davPath'], $bea, str_repeat('Z', 210 * 1024));
	check($status === 507 && !is_file($filesRoot . '/' . $beaId . $huge['path']), 'PUT über dem Kontingent: 507, nichts gespeichert', (string) $status);
	[$status] = $http('PUT', $huge['davPath'], $bea, str_repeat('Z', 150 * 1024));
	check($status === 201, 'PUT im Kontingent: 201', (string) $status);
	[$status] = $http('PUT', $huge['davPath'], $bea, str_repeat('Y', 150 * 1024));
	check($status === 204, 'Datei ersetzen zählt nicht doppelt: 204', (string) $status);
	$json('POST', '/api/files', $bea, ['path' => $huge['path']]); // Eintrag, damit der tägliche Lauf sie behält
	$limitUpload = $huge['uploadsPath'] . '/grenze';
	$http('MKCOL', $limitUpload, $bea);
	[$status] = $http('PUT', $limitUpload . '/1', $bea, str_repeat('A', 40 * 1024));
	check($status === 201, 'Stück im restlichen Kontingent: 201', (string) $status);
	[$status] = $http('PUT', $limitUpload . '/2', $bea, str_repeat('B', 20 * 1024));
	check($status === 507 && !is_file($filesRoot . '/.uploads/' . $beaId . '/grenze/2'), 'Stücke zählen mit: 507', (string) $status);
	[$status] = $http('PUT', $limitUpload . '/1', $bea, str_repeat('C', 40 * 1024));
	check($status === 201, 'Stück ersetzen zählt nicht doppelt: 201', (string) $status);
	$http('DELETE', $limitUpload, $bea);

	[$status] = $http('MKCOL', $big['uploadsPath'] . '/abbruch', $admin);
	[$status] = $http('DELETE', $big['uploadsPath'] . '/abbruch', $admin);
	check($status === 204 && !is_dir($filesRoot . '/.uploads/1/abbruch'), 'Upload abbrechen: 204', (string) $status);

	// ── Vorschaubild ───────────────────────────────────────────────────────
	if (function_exists('imagecreatetruecolor')) {
		$image = imagecreatetruecolor(1200, 800);
		imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
		ob_start();
		imagepng($image);
		$png = (string) ob_get_clean();
		[, $photo] = $json('POST', '/api/files/target', $admin, ['name' => 'Foto.png', 'mimeType' => 'image/png']);
		check(str_starts_with($photo['davPath'] ?? '', '/remote.php/dav/files/1/Images/'), 'Bilder in „Images“', json_encode($photo));
		$http('PUT', $photo['davPath'], $admin + ['Content-Type' => 'image/png'], $png);
		[, $photoEntry] = $json('POST', '/api/files', $admin, ['path' => $photo['path']]);
		check(($photoEntry['category'] ?? '') === 'Image' && ($photoEntry['imageUrl'] ?? '') !== '', 'Bild-Eintrag mit Vorschaubild', json_encode($photoEntry));
		[$status, , $body, $headers] = $http('GET', $local($photoEntry['imageUrl'] ?? ''));
		$size = @getimagesizefromstring($body);
		check($status === 200 && ($headers['content-type'] ?? '') === 'image/jpeg' && is_array($size) && max($size[0], $size[1]) === 512,
			'Vorschaubild: JPEG, 512 px', $status . ' ' . json_encode($size));
		check(count(glob($filesRoot . '/.previews/' . $photoEntry['fileId'] . '-512-*.jpg') ?: []) === 1, 'Vorschaubild im Cache');
	} else {
		echo "skip Vorschaubild (ohne GD)\n";
	}

	// ── Aufräumen im täglichen Lauf ────────────────────────────────────────
	[, $orphan] = $json('POST', '/api/files/target', $admin, ['name' => 'verwaist.txt', 'mimeType' => 'text/plain']);
	$http('PUT', $orphan['davPath'], $admin, 'niemand trägt mich ein');
	[$status] = $http('DELETE', '/api/articles/' . ($second['id'] ?? 0), $admin);
	check($status === 200 || $status === 204, 'Eintrag löschen', (string) $status);
	$http('MKCOL', $big['uploadsPath'] . '/liegen-geblieben', $admin);
	$pdo = new PDO('sqlite:' . $dir . '/merlin.sqlite');
	$pdo->exec('UPDATE stored_files SET created_at = created_at - 172800');
	touch($filesRoot . '/.uploads/1/liegen-geblieben', time() - 172800);
	[$code, $out] = $cli('cron --force');
	check($code === 0 && str_contains($out, 'Merlin Dateien: 2 Dateien'), 'cron räumt zwei Dateien ohne Eintrag weg', $out);
	check(!is_file($filesRoot . '/1/Other files/verwaist.txt') && !is_file($filesRoot . '/1/Other files/per-name.txt')
		&& is_file($filesRoot . '/1/Other files/Umbenannt.txt') && !is_dir($filesRoot . '/.uploads/1/liegen-geblieben'),
		'nur Dateien mit Eintrag bleiben', json_encode(glob($filesRoot . '/1/*/*')));
	[$status, , $body] = $http('GET', $local($renamed['url'] ?? ''));
	check($status === 200 && $body === $content, 'eingetragene Datei weiter erreichbar', (string) $status);

	$log = (string) @file_get_contents($dir . '/merlin.log') . (string) @file_get_contents($dir . '/server.log');
	check(!preg_match('/Unbehandelter Fehler|PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'keine unbehandelten Fehler im Log', $log);
} finally {
	proc_terminate($server);
	proc_close($server);
	\Merlin\Standalone\Adapter\LocalFileStorage::removeDir($dir);
}

if ($failures > 0) {
	echo "\n{$failures} Fehler\n";
	exit(1);
}
echo "\nalle Prüfungen ok\n";
