<?php

declare(strict_types=1);

/**
 * Einstellungen, Löschfrist, Speicherverbrauch, Content-Filter,
 * Abo-Zugangsdaten, Meldungen und die API der Browser-Erweiterung des
 * Standalone-Servers über HTTP (Handler aus dem Kern, core/src/Api/routes.php).
 * Dieselben Abläufe wie nextcloud/tests/Api/SettingsApiTest.php,
 * ContentFilterApiTest.php und ArticleApiTest.php (Browser-Erweiterung) gegen
 * die Nextcloud-App, dazu Adminrechte, requirements der Platzhalter und das
 * Rate-Limit je Nutzer. Offline: die Erweiterung schickt das HTML mit.
 *
 * Aufruf: php standalone/tests/test-settings-api.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/support/TestServer.php';

use Merlin\Standalone\Tests\TestServer;

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
	if (!$ok) {
		$failures++;
	}
}

$server = new TestServer('merlin-settings');
[$code, $out] = $server->cli('migrate');
check($code === 0, 'bin/merlin migrate', $out);
[$code, $out] = $server->cli('create-admin --username=admin --email=admin@example.org --password=geheim123');
check($code === 0, 'bin/merlin create-admin', $out);
$server->start();
$http = $server->http(...);

try {
	$admin = $server->tokenFor('admin', 'geheim123');
	[$status] = $http('POST', '/admin/users', ['username' => 'bea', 'email' => 'bea@example.org', 'password' => 'passwort1', 'role' => 'user'], $admin);
	check($status === 201, 'zweites Konto anlegen');
	$bea = $server->tokenFor('bea', 'passwort1');

	echo "Einstellungen\n";
	[$status, $settings] = $http('GET', '/api/settings', null, $bea);
	check($status === 200 && $settings['theme'] === 'auto' && $settings['fontSize'] === 17 && $settings['saveProgress'] === true
		&& $settings['lineHeight'] === 1.6 && $settings['retentionDays'] === 0 && array_key_exists('retentionNoticeRequired', $settings),
		'Standardwerte typisiert', json_encode($settings));
	[$status, $saved] = $http('PUT', '/api/settings', ['theme' => 'sepia', 'fontSize' => '21', 'saveProgress' => 'false', 'unbekannt' => 'x', 'retentionDays' => 30], $bea);
	check($status === 200 && $saved['success'] === true && $saved['settings']['theme'] === 'sepia' && $saved['settings']['fontSize'] === 21
		&& $saved['settings']['saveProgress'] === false && !isset($saved['settings']['unbekannt']) && $saved['settings']['retentionEffectiveDays'] === 30,
		'speichern: kanonische Typen, unbekannte Schlüssel ignoriert', json_encode($saved));
	[, $settings] = $http('GET', '/api/settings', null, $bea);
	check($settings['theme'] === 'sepia' && $settings['saveProgress'] === false, 'gespeichert');
	[, $settings] = $http('GET', '/api/settings', null, $admin);
	check($settings['theme'] === 'auto', 'je Konto getrennt');

	echo "Löschfrist\n";
	[$status, $info] = $http('POST', '/api/retention/notice', null, $bea);
	check($status === 200 && $info['retentionNoticeRequired'] === false && $info['retentionEffectiveDays'] === 30, 'Hinweis bestätigen', json_encode($info));
	[$status] = $http('GET', '/api/admin/retention', null, $bea);
	check($status === 403, 'Admin-Maximum ohne Adminrechte: 403');
	[$status, $limits] = $http('PUT', '/api/admin/retention', ['days' => 14, 'favoritesDays' => 0], $admin);
	check($status === 200 && $limits === ['days' => 14, 'favoritesDays' => 0], 'Admin-Maximum setzen', json_encode($limits));
	[$status, $limits] = $http('GET', '/api/admin/retention', null, $admin);
	check($status === 200 && $limits['days'] === 14, 'Admin-Maximum lesen');
	[$status, $preview] = $http('GET', '/api/admin/retention/preview?days=7&favoritesDays=0', null, $admin);
	check($status === 200 && $preview === ['count' => 0], 'Vorschau', json_encode($preview));
	[, $settings] = $http('GET', '/api/settings', null, $bea);
	check($settings['retentionMaxDays'] === 14 && $settings['retentionEffectiveDays'] === 14 && $settings['retentionNoticeRequired'] === true,
		'kürzeres Admin-Maximum gilt und verlangt den Hinweis neu', json_encode($settings));

	$domain = 'merlin-ci.example.org';
	$html = '<html><head><title>Gefiltert</title></head><body><article><h1>Gefiltert</h1>'
		. '<p>Erster Absatz mit ausreichend Text, damit Readability den Artikel als Inhalt erkennt und nicht verwirft.</p>'
		. '<p class="ci-remove-me">WERBUNG-DIE-DER-FILTER-ENTFERNT mit ausreichend Text, damit der Absatz sonst erhalten bliebe.</p>'
		. '<p>Dritter Absatz mit ausreichend Text, damit Readability den Artikel als Inhalt erkennt und nicht verwirft.</p>'
		. '</article></body></html>';
	$waitFor = static function (int $id, array $auth) use ($http): ?array {
		for ($i = 0; $i < 50; $i++) {
			[$status, $article] = $http('GET', "/api/articles/{$id}", null, $auth);
			if ($status === 200 && empty($article['isProcessing'])) {
				return $article;
			}
			usleep(200_000);
		}
		return null;
	};

	echo "Content-Filter (eigene Ebene)\n";
	$xml = <<<XML
		<?xml version="1.0" encoding="UTF-8"?>
		<domain name="{$domain}">
		  <post-filter>
		    <remove class="ci-remove-me" />
		  </post-filter>
		</domain>
		XML;
	[$status, $shown] = $http('PUT', "/api/user/content-filters/{$domain}", ['xml' => $xml], $bea);
	check($status === 200 && str_contains(json_encode($shown), 'ci-remove-me') && $shown['domain'] === $domain, 'speichern', json_encode($shown));
	[$status, $index] = $http('GET', '/api/user/content-filters', null, $bea);
	$own = array_values(array_filter($index['domains'] ?? [], static fn (array $d) => $d['domain'] === $domain));
	check($status === 200 && isset($index['schema']) && ($own[0]['hasOwnOverride'] ?? false) === true, 'Liste mit eigenem Override');
	[$status, $other] = $http('GET', "/api/user/content-filters/{$domain}", null, $admin);
	check(!str_contains((string) json_encode($other), 'ci-remove-me'), 'für andere unsichtbar', (string) $status);
	[$status] = $http('PUT', "/api/user/content-filters/{$domain}", [], $bea);
	check($status === 400, 'ohne xml/rules: 400');
	[$status] = $http('PUT', "/api/user/content-filters/{$domain}", ['xml' => '<domain name="other.example"><nonsense/></domain>'], $bea);
	check($status === 400, 'ungültiger Filter: 400');
	[$status] = $http('PUT', '/api/user/content-filters/UNG%C3%9CLTIG', ['xml' => $xml], $bea);
	check($status === 404, 'Domain außerhalb der requirements: 404');
	[$status, $test] = $http('POST', "/api/user/content-filters/{$domain}/test", ['url' => 'https://example.com/x'], $bea);
	check($status === 400 && str_contains($test['message'] ?? '', 'example.com'), 'Testlauf mit fremder Domain: 400');

	echo "Browser-Erweiterung\n";
	[$status, $added] = $http('POST', '/api/v1/add', ['url' => "https://{$domain}/artikel", 'title' => 'Gefiltert', 'html' => $html], $bea);
	check($status === 201 && $added['status'] === 1 && $added['item']['given_title'] === 'Gefiltert' && $added['item']['status'] === '0', 'speichern: 201', json_encode($added));
	$articleId = (int) ($added['item']['item_id'] ?? 0);
	$article = $waitFor($articleId, $bea);
	check($article !== null && str_contains($article['content'], 'Dritter Absatz') && !str_contains($article['content'], 'WERBUNG-DIE-DER-FILTER-ENTFERNT')
		&& $article['title'] === 'Gefiltert', 'Inhalt aus dem mitgeschickten HTML, eigener Filter angewendet', json_encode($article['content'] ?? null));
	[$status, $error] = $http('POST', '/api/v1/add', [], $bea);
	check($status === 400 && $error['status'] === 0, 'ohne url: 400');
	[$status, $list] = $http('POST', '/api/v1/get', ['state' => 'unread', 'count' => 10], $bea);
	check($status === 200 && $list['status'] === 1 && isset($list['list'][(string) $articleId]) && $list['list'][(string) $articleId]['favorite'] === '0',
		'Liste (unread)', json_encode($list));
	[$status, $list] = $http('POST', '/api/v1/get', ['state' => 'unread'], $admin);
	check($status === 200 && $list['list'] === [], 'fremde Artikel unsichtbar');
	[$status, $sent] = $http('POST', '/api/v1/send', ['actions' => [
		['action' => 'favorite', 'item_id' => (string) $articleId],
		['action' => 'archive', 'item_id' => (string) $articleId],
		['action' => 'archive', 'item_id' => '999999'],
	]], $bea);
	check($status === 200 && array_column($sent['action_results'], 'success') === [true, true, false], 'Aktionen', json_encode($sent));
	[, $list] = $http('POST', '/api/v1/get', ['state' => 'archive'], $bea);
	check(($list['list'][(string) $articleId]['favorite'] ?? null) === '1' && ($list['list'][(string) $articleId]['status'] ?? null) === '1', 'favorisiert und archiviert');
	[$status, $sent] = $http('POST', '/api/v1/send', ['actions' => [['action' => 'delete', 'item_id' => $articleId]]], $admin);
	check($status === 200 && $sent['action_results'][0]['success'] === false, 'fremden Artikel löschen scheitert');

	echo "Speicherverbrauch\n";
	[$status, $storage] = $http('GET', '/api/storage', null, $bea);
	check($status === 200 && $storage['articleCount'] === 1 && $storage['highlightCount'] === 0 && $storage['articleBytes'] > 100
		&& $storage['totalBytes'] === $storage['articleBytes'] + $storage['highlightBytes'], 'Zähler und Bytes', json_encode($storage));
	[, $storage] = $http('GET', '/api/storage', null, $admin);
	check($storage['articleCount'] === 0 && $storage['totalBytes'] === 0, 'je Konto');

	[$status, $sent] = $http('POST', '/api/v1/send', ['actions' => [['action' => 'delete', 'item_id' => $articleId]]], $bea);
	check($status === 200 && $sent['action_results'][0]['success'] === true, 'löschen');
	[$status] = $http('GET', "/api/articles/{$articleId}", null, $bea);
	check($status === 404, 'gelöscht');

	[$status, $deleted] = $http('DELETE', "/api/user/content-filters/{$domain}", null, $bea);
	check($status === 200 && $deleted === ['domain' => $domain, 'deleted' => true], 'eigenen Filter löschen');

	echo "Content-Filter (Admin)\n";
	[$status] = $http('GET', '/api/admin/content-filters', null, $bea);
	check($status === 403, 'ohne Adminrechte: 403');
	[$status, $list] = $http('GET', '/api/admin/content-filters', null, $admin);
	check($status === 200 && isset($list['filters'], $list['schema']), 'Liste');
	[$status, $imported] = $http('POST', '/api/admin/content-filters/import', ['xml' => $xml], $admin);
	check($status === 200 && $imported['domain'] === $domain && $imported['custom'] !== null, 'Import, Domain aus name-Attribut', json_encode($imported));
	[$status, $conflict] = $http('POST', '/api/admin/content-filters/import', ['xml' => $xml], $admin);
	check($status === 409 && $conflict['code'] === 'custom_filter_exists', 'zweiter Import ohne overwrite: 409');
	[$status, , $body, $headers] = $http('GET', "/api/admin/content-filters/{$domain}/export", null, $admin);
	check($status === 200 && str_contains($body, 'ci-remove-me') && str_starts_with($headers['content-type'] ?? '', 'application/xml')
		&& ($headers['content-disposition'] ?? '') === "attachment; filename=\"{$domain}.xml\"", 'Export als Datei', json_encode($headers));
	[$status, $index] = $http('GET', '/api/user/content-filters', null, $bea);
	$entry = array_values(array_filter($index['domains'], static fn (array $d) => $d['domain'] === $domain));
	check(($entry[0]['hasAdminCustom'] ?? false) === true && ($entry[0]['hasOwnOverride'] ?? true) === false, 'Nutzer sieht den Admin-Filter als Referenz');
	[$status] = $http('DELETE', "/api/admin/content-filters/{$domain}", null, $admin);
	check($status === 200, 'Admin-Filter löschen');
	[$status] = $http('GET', "/api/admin/content-filters/{$domain}", null, $admin);
	check($status === 404, 'danach kein Filter: 404');

	echo "Abo-Zugangsdaten\n";
	[$status, $credentials] = $http('GET', '/api/user/site-credentials', null, $bea);
	check($status === 200 && $credentials['credentials'] === [] && is_array($credentials['availableDomains']), 'Liste', json_encode($credentials));
	[$status, $error] = $http('PUT', '/api/user/site-credentials/tagesspiegel.de', ['username' => 'bea'], $bea);
	check($status === 400 && isset($error['message']), 'ohne Passwort: 400');
	for ($i = 0; $i < 9; $i++) {
		$http('PUT', '/api/user/site-credentials/tagesspiegel.de', ['username' => 'bea'], $bea);
	}
	[$status] = $http('PUT', '/api/user/site-credentials/tagesspiegel.de', ['username' => 'bea'], $bea);
	check($status === 429, 'Rate-Limit je Nutzer: 11. Versuch 429');
	[$status] = $http('PUT', '/api/user/site-credentials/tagesspiegel.de', ['username' => 'admin'], $admin);
	check($status === 400, 'anderes Konto hat sein eigenes Limit');
	[$status, $deleted] = $http('DELETE', '/api/user/site-credentials/tagesspiegel.de', null, $bea);
	check($status === 200 && $deleted === ['domain' => 'tagesspiegel.de', 'deleted' => true], 'löschen');
	[$status] = $http('DELETE', '/api/user/site-credentials/Tages_Spiegel', null, $bea);
	check($status === 404, 'Domain außerhalb der requirements: 404');

	echo "Meldungen\n";
	[$status, $result] = $http('POST', '/api/report-backend/report', ['url' => 'https://example.org/a', 'comment' => 'x'], $bea);
	check($status === 400 && $result === ['status' => 'unconfigured'], 'ohne Backend: 400 unconfigured', json_encode($result));
	$http('PUT', '/api/settings', ['reportBackendUrl' => 'http://127.0.0.1:9/merlin-reports/'], $bea);
	[$status, $result] = $http('POST', '/api/report-backend/report', ['url' => 'kein-link'], $bea);
	check($status === 400 && $result['status'] === 'error', 'ungültiger Link: 400');
	[$status, $result] = $http('GET', '/api/report-backend/ping?url=' . rawurlencode('http://127.0.0.1:9/'), null, $bea);
	check($status === 200 && in_array($result['status'] ?? null, ['error', 'auth', 'ok'], true), 'Ping liefert einen Status', json_encode($result));

	[$status, $caps] = $http('GET', '/api/capabilities');
	$missing = array_diff(['retention', 'contentFilters', 'siteCredentials', 'reportBackend', 'pocketApi', 'comments', 'shares'], $caps['features'] ?? []);
	check($status === 200 && $missing === [] && in_array('pwa', $caps['features'], true) && !in_array('files', $caps['features'], true), 'capabilities melden die Funktionen', implode(', ', $missing));

	[$status, $manifest, , $headers] = $http('GET', '/manifest.webmanifest');
	check($status === 200 && str_contains($headers['content-type'] ?? '', 'application/manifest+json') && ($manifest['start_url'] ?? null) === './'
		&& ($manifest['icons'][0]['src'] ?? null) === '/img/icon-192.png', 'Web-App-Manifest ohne Anmeldung', json_encode($manifest));
	[$status, , $body, $headers] = $http('GET', '/sw.js');
	check($status === 200 && str_contains($headers['content-type'] ?? '', 'javascript') && str_contains($body, 'respondWith'), 'Service Worker');
	[$status] = $http('GET', '/img/icon-192.png');
	check($status === 200, 'Manifest-Icon erreichbar');

	echo "Ohne Anmeldung\n";
	foreach (['GET /api/settings', 'GET /api/storage', 'POST /api/v1/add', 'GET /api/user/content-filters', 'GET /api/user/site-credentials'] as $route) {
		[$method, $path] = explode(' ', $route);
		[$status] = $http($method, $path);
		check($status === 401, "{$route}: 401");
	}
} finally {
	if ($failures > 0) {
		echo "\n--- Server-Log ---\n" . $server->log() . "\n";
	}
	$server->stop();
}

echo $failures === 0 ? "\nalle Prüfungen ok\n" : "\n{$failures} Fehler\n";
exit($failures === 0 ? 0 : 1);
