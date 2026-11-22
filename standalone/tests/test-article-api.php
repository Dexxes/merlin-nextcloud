<?php

declare(strict_types=1);

/**
 * Artikel- und Schlagwort-API des Standalone-Servers über HTTP (Handler aus
 * dem Kern, core/src/Api/routes.php): eingebauter PHP-Server, frische
 * SQLite-Datenbank, zwei Konten mit API-Token. Dieselben Abläufe wie
 * nextcloud/tests/Api/ArticleApiTest.php gegen die Nextcloud-App: Speichern,
 * Liste, Zähler, Zustände, Leseposition, Suche, Export, Schlagworte mit
 * Unterschlagworten, Trennung zwischen Konten, Löschen, /api/events.
 *
 * Offline (Standard, so läuft es in CI) wird der Inhalt eines Artikels direkt
 * in die Datenbank geschrieben; POST /api/articles geht an eine nicht
 * auflösbare Adresse und muss sauber ohne Inhalt enden.
 *
 * Mit --live speichert der Test zusätzlich echte Artikel-Links über
 * POST /api/articles und wartet auf die Extraktion (braucht Internet):
 *   php standalone/tests/test-article-api.php --live [url …]
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$live = in_array('--live', $argv, true);
$liveUrls = array_values(array_filter(array_slice($argv, 1), static fn (string $a) => !str_starts_with($a, '--')));
if ($live && $liveUrls === []) {
	$liveUrls = [
		// Startseiten statt einzelner Artikel, damit die Links nicht veralten.
		'https://www.tagesschau.de/',
		'https://netzpolitik.org/',
		'https://www.heise.de/',
	];
}

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
	if (!$ok) {
		$failures++;
	}
}

$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/merlin-articles-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$name = (string) stream_socket_get_name($probe, false);
$port = (int) substr($name, strrpos($name, ':') + 1);
fclose($probe);
$base = "http://127.0.0.1:{$port}";
file_put_contents($dir . '/config.php', '<?php return ' . var_export([
	'db_path' => $dir . '/merlin.sqlite',
	'log_path' => $dir . '/merlin.log',
	'base_url' => $base,
	'secret' => base64_encode(random_bytes(32)),
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

/** @return array{0: int, 1: mixed, 2: string, 3: array<string, string>} Status, JSON, Body, Header */
$http = static function (string $method, string $path, ?array $json = null, array $headers = [], ?array $form = null, ?string $cookieJar = null, int $timeout = 30) use ($base): array {
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
		CURLOPT_TIMEOUT => $timeout,
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

/** Konto anmelden (Session) und ein API-Token anlegen; gibt den Basic-Auth-Header zurück. */
$tokenFor = static function (string $username, string $password) use ($http, $dir): array {
	$jar = $dir . '/cookies-' . $username . '.txt';
	$http('POST', '/login', null, [], ['username' => $username, 'password' => $password], $jar);
	[, $token] = $http('POST', '/account/tokens', ['name' => 'Test'], [], null, $jar);
	return ['Authorization' => 'Basic ' . base64_encode($username . ':' . ($token['plainText'] ?? ''))];
};

try {
	$admin = $tokenFor('admin', 'geheim123');
	[$status] = $http('POST', '/admin/users', ['username' => 'bea', 'email' => 'bea@example.org', 'password' => 'passwort1', 'role' => 'user'], $admin);
	check($status === 201, 'zweites Konto anlegen');
	$bea = $tokenFor('bea', 'passwort1');

	[$status, $caps] = $http('GET', '/api/capabilities');
	check($status === 200 && in_array('articles', $caps['features'] ?? [], true) && in_array('nestedTags', $caps['features'] ?? [], true)
		&& in_array('accounts', $caps['features'] ?? [], true) && !in_array('files', $caps['features'] ?? [], true),
		'capabilities: Kern-Funktionen plus Standalone-eigene, ohne files', json_encode($caps));

	[$status] = $http('GET', '/api/articles');
	check($status === 401, 'ohne Anmeldung: 401');

	// Speichern wie iOS/Android: Platzhalter sofort (202), Extraktion danach.
	// example.invalid lässt sich nie auflösen: die Extraktion scheitert und
	// muss isProcessing trotzdem zurücksetzen.
	[$status, $placeholder] = $http('POST', '/api/articles', ['url' => 'https://example.invalid/merlin-test'], $admin);
	check($status === 202 && ($placeholder['isProcessing'] ?? null) === true && ($placeholder['title'] ?? null) === 'example.invalid'
		&& ($placeholder['tags'] ?? null) === [] && str_ends_with((string) ($placeholder['imageUrl'] ?? ''), '/img/no-img.png'),
		'POST /api/articles: Platzhalter mit 202', json_encode($placeholder));
	$failedId = (int) ($placeholder['id'] ?? 0);
	[$status, $failedArticle] = $http('GET', "/api/articles/{$failedId}", null, $admin);
	check($status === 200 && ($failedArticle['isProcessing'] ?? null) === false && ($failedArticle['content'] ?? null) === '',
		'fehlgeschlagene Extraktion endet ohne Inhalt, nicht in Verarbeitung', json_encode($failedArticle));
	check(array_key_exists('supportBox', $failedArticle ?? []), 'Einzelabruf mit supportBox');

	[$status, $rejected] = $http('POST', '/api/articles', ['url' => 'https://t3n.de/news/merlin-test/', 'rejectUnsupported' => true], $admin);
	check($status === 422 && ($rejected['error'] ?? null) === 'unsupported_site' && ($rejected['domain'] ?? null) === 't3n.de',
		'rejectUnsupported: 422 unsupported_site', json_encode($rejected));
	[$status] = $http('POST', '/api/articles', [], $admin);
	check($status === 400, 'ohne url: 400');

	// Inhalt wie nach einer Extraktion direkt schreiben (offline).
	$pdo = new PDO('sqlite:' . $dir . '/merlin.sqlite');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$insert = $pdo->prepare('INSERT INTO merlin_articles (user_id, url, title, content, excerpt, author, site_name, image_url, reading_time, category, created_at, updated_at)'
		. " VALUES ('1', ?, ?, ?, ?, 'Erika Mustermann', 'example.org', ?, 2, ?, ?, ?)");
	$saved = static function (string $title, string $created, ?string $category = null, string $image = '') use ($pdo, $insert): int {
		$insert->execute(['https://example.org/' . rawurlencode($title), $title, "<p>{$title}: Zweiter Absatz mit Text.</p>",
			"Auszug zu {$title}", $image, $category, $created, $created]);
		return (int) $pdo->lastInsertId();
	};
	$textId = $saved('Zustände', '2026-02-01 10:00:00', null, 'https://example.org/bild.jpg');
	$videoId = $saved('Ein Video', '2026-02-02 10:00:00', 'Video');
	$pdfId = $saved('Ein PDF', '2026-02-03 10:00:00', 'PDF');

	[$status, $list] = $http('GET', '/api/articles', null, $admin);
	check($status === 200 && array_column($list, 'id') === [$failedId, $pdfId, $videoId, $textId], 'Liste: neueste zuerst', json_encode(array_column($list ?? [], 'id')));
	$byId = array_column($list ?? [], null, 'id');
	check(($byId[$pdfId]['imageUrl'] ?? '') === $base . '/img/pdf-preview.png', 'PDF ohne Bild: absolutes PDF-Icon', (string) ($byId[$pdfId]['imageUrl'] ?? ''));
	check(($byId[$textId]['imageUrl'] ?? '') === 'https://example.org/bild.jpg' && ($byId[$textId]['tags'] ?? null) === [], 'Bild und leere Schlagworte');
	[, $pages] = $http('GET', '/api/articles?contentType=page', null, $admin);
	[, $videos] = $http('GET', '/api/articles?contentType=video', null, $admin);
	check(array_column($pages, 'id') === [$failedId, $pdfId, $textId] && array_column($videos, 'id') === [$videoId], 'contentType page/video');
	[, $paged] = $http('GET', '/api/articles?limit=2&offset=1', null, $admin);
	check(array_column($paged, 'id') === [$pdfId, $videoId], 'limit/offset');
	[, $counts] = $http('GET', '/api/articles/counts', null, $admin);
	check(($counts['pages']['total'] ?? null) === 3 && ($counts['videos']['total'] ?? null) === 1 && ($counts['files']['total'] ?? null) === 0, 'counts', json_encode($counts));

	[$status, $read] = $http('PUT', "/api/articles/{$textId}/read", null, $admin);
	check($status === 200 && $read === ['isRead' => 1], 'PUT …/read: {isRead: 1}', json_encode($read));
	[, $fav] = $http('PUT', "/api/articles/{$textId}/favorite", null, $admin);
	check(is_string($fav['isFavorite'] ?? null) && strtotime($fav['isFavorite']) > 0, 'PUT …/favorite: Zeitpunkt', json_encode($fav));
	[, $progress] = $http('PUT', "/api/articles/{$textId}/progress", ['progress' => 1.7, 'updatedAt' => 1767225600000], $admin);
	check(($progress['scrollProgress'] ?? null) === 1 && ($progress['scrollUpdatedAt'] ?? null) === 1767225600000, 'PUT …/progress: auf 0..1 begrenzt', json_encode($progress));
	[, $renamed] = $http('PUT', "/api/articles/{$textId}", ['title' => 'Umbenannt', 'isRead' => false], $admin);
	check(($renamed['title'] ?? null) === 'Umbenannt' && ($renamed['isRead'] ?? null) === false && ($renamed['scrollProgress'] ?? null) === 1, 'PUT /api/articles/{id}', json_encode($renamed));
	[, $favorites] = $http('GET', '/api/articles?isFavorite=true', null, $admin);
	[, $unread] = $http('GET', '/api/articles?isRead=false', null, $admin);
	check(array_column($favorites, 'id') === [$textId] && in_array($textId, array_column($unread, 'id'), true), 'Filter isFavorite/isRead');

	[, $found] = $http('GET', '/api/articles/search?query=umbenannt', null, $admin);
	check(array_column($found, 'id') === [$textId], 'Suche');
	[, $archived] = $http('PUT', "/api/articles/{$textId}/archive", null, $admin);
	check(($archived['isArchived'] ?? null) === 1 && !empty($archived['archivedAt']), 'PUT …/archive', json_encode($archived));
	[, $onlyArchived] = $http('GET', '/api/articles?isArchived=true', null, $admin);
	[, $foundArchived] = $http('GET', '/api/articles/search?query=umbenannt', null, $admin);
	check(array_column($onlyArchived, 'id') === [$textId] && $foundArchived === [], 'Archiv-Filter, Suche ohne Archiv');

	[$status, , $body, $headers] = $http('GET', "/api/articles/{$textId}/export/html", null, $admin);
	check($status === 200 && str_starts_with($headers['content-type'] ?? '', 'text/html') && str_contains($body, 'Zweiter Absatz')
		&& ($headers['content-disposition'] ?? '') === 'attachment; filename="Umbenannt.html"', 'HTML-Export', json_encode($headers));

	// Schlagworte, verschachtelt.
	[$status, $parent] = $http('POST', '/api/tags', ['name' => 'Politik'], $admin);
	check($status === 201 && ($parent['color'] ?? null) === '#0082c9' && array_key_exists('parentId', $parent ?? []) && $parent['parentId'] === null, 'POST /api/tags', json_encode($parent));
	[, $child] = $http('POST', '/api/tags', ['name' => 'Europa', 'parentId' => $parent['id']], $admin);
	check(($child['parentId'] ?? null) === $parent['id'], 'Unterschlagwort');
	[$status] = $http('POST', '/api/tags', ['name' => 'Politik'], $admin);
	check($status === 400, 'doppelter Name: 400');
	[$status, $moved] = $http('PUT', "/api/tags/{$parent['id']}", ['parentId' => $child['id']], $admin);
	check($status === 400, 'unter eigenes Unterschlagwort verschieben: 400', json_encode($moved));
	[$status, $linked] = $http('POST', "/api/articles/{$videoId}/tags/{$child['id']}", null, $admin);
	check($status === 200 && $linked === ['success' => true], 'Schlagwort zuordnen');
	[, $byParent] = $http('GET', "/api/articles?tagId={$parent['id']}", null, $admin);
	check(array_column($byParent, 'id') === [$videoId] && array_column($byParent[0]['tags'] ?? [], 'name') === ['Europa'], 'Filter nach Oberschlagwort enthält Unterschlagworte');
	[, $tags] = $http('GET', '/api/tags', null, $admin);
	check(array_column($tags, 'name') === ['Europa', 'Politik'], 'GET /api/tags nach Name');

	// Fremde Konten sehen und ändern nichts.
	[$status] = $http('GET', "/api/articles/{$videoId}", null, $bea);
	check($status === 404, 'fremder Artikel: 404');
	[$status] = $http('PUT', "/api/articles/{$videoId}", ['title' => 'Fremd'], $bea);
	check($status === 404, 'fremder Artikel ändern: 404');
	[$status] = $http('DELETE', "/api/articles/{$videoId}", null, $bea);
	check($status === 404, 'fremder Artikel löschen: 404');
	[$status] = $http('POST', "/api/articles/{$videoId}/tags/{$parent['id']}", null, $bea);
	check($status === 400, 'fremdes Schlagwort zuordnen: 400');
	[, $beaList] = $http('GET', '/api/articles', null, $bea);
	[, $beaTags] = $http('GET', '/api/tags', null, $bea);
	check($beaList === [] && $beaTags === [], 'fremdes Konto: leere Liste');

	[$status, , $events, $headers] = $http('GET', '/api/events', null, $admin);
	check($status === 200 && str_starts_with($headers['content-type'] ?? '', 'text/event-stream') && str_contains($events, '"type":"idle"'), '/api/events: idle ohne laufende Extraktion', $events);

	[$status, $deleted] = $http('DELETE', "/api/tags/{$parent['id']}", null, $admin);
	check($status === 200 && ($deleted['deletedIds'] ?? null) === [$parent['id'], $child['id']], 'Schlagwort samt Unterschlagwort löschen', json_encode($deleted));
	[$status] = $http('DELETE', "/api/articles/{$videoId}", null, $admin);
	[$gone] = $http('GET', "/api/articles/{$videoId}", null, $admin);
	check($status === 200 && $gone === 404, 'Artikel löschen');

	if ($live) {
		echo "\nEchte Artikel-Links\n";
		foreach ($liveUrls as $url) {
			// Unter php -S wartet die Antwort auf die Extraktion (kein fastcgi_finish_request).
			[$status, $article] = $http('POST', '/api/articles', ['url' => $url], $admin, null, null, 150);
			$id = (int) ($article['id'] ?? 0);
			$deadline = time() + 120;
			do {
				[, $article] = $http('GET', "/api/articles/{$id}", null, $admin);
				if (empty($article['isProcessing'])) {
					break;
				}
				sleep(1);
			} while (time() < $deadline);
			$content = (string) ($article['content'] ?? '');
			// Bezahlartikel ohne Inhalt sind ein gültiges Ergebnis: Hinweis mit
			// Abo-Link (isPaywalled) oder Login-Dialog (requiresLoginDomain).
			$state = match (true) {
				!empty($article['requiresLoginDomain']) => 'Login nötig (' . $article['requiresLoginDomain'] . ')',
				!empty($article['isPaywalled']) => 'Bezahlartikel',
				!empty($article['unsupportedSiteDomain']) => 'nicht unterstützt',
				default => strlen(strip_tags($content)) . ' Zeichen',
			};
			check($status === 202 && empty($article['isProcessing']) && trim((string) ($article['title'] ?? '')) !== ''
				&& (strlen(strip_tags($content)) > 200 || !empty($article['isPaywalled']) || !empty($article['requiresLoginDomain'])),
				"live: {$url}", json_encode(['status' => $status, 'title' => $article['title'] ?? null, 'state' => $state]));
			echo '     ' . ($article['title'] ?? '') . ' · ' . ($article['siteName'] ?? '') . ' · ' . ($article['author'] ?? '–')
				. ' · ' . ($article['readingTime'] ?? 0) . " min · {$state}\n";
		}
	}

	// Erwartete Fehler (nicht auflösbarer Host, doppelter Name) stehen im
	// Log; unbehandelte Ausnahmen und PHP-Warnungen dürfen es nicht.
	$log = (string) @file_get_contents($dir . '/merlin.log') . (string) @file_get_contents($dir . '/server.log');
	check(!preg_match('/Unbehandelter Fehler|PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'keine unbehandelten Fehler im Log', $log);
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
