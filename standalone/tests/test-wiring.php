<?php

declare(strict_types=1);

/**
 * Verdrahtung des Standalone-Servers: Jeder Port in core/src/Port/ hat in
 * App::PORTS einen Adapter oder steht in App::UNAVAILABLE_PORTS, und jeder
 * Kern-Dienst, den der Server braucht, lässt sich aus dem Container bauen.
 * Dazu ein Rundweg durch die Adapter für Einstellungen, Verschlüsselung,
 * Nutzernamen und Content-Filter auf einer frisch migrierten Datenbank.
 *
 * Aufruf: php standalone/tests/test-wiring.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Merlin\Core\ContentFilter\ContentFilterMerger;
use Merlin\Core\ContentFilter\ContentFilterSerializer;
use Merlin\Core\ContentFilter\ContentFilterValidator;
use Merlin\Core\Extraction\ContentExtractorService;
use Merlin\Core\Extraction\Media\MediaResolverService;
use Merlin\Core\Port;
use Merlin\Core\Service\ArticleDeletionService;
use Merlin\Core\Service\CommentService;
use Merlin\Core\Service\ExportService;
use Merlin\Core\Service\PdfProxyService;
use Merlin\Core\Service\ReportBackendService;
use Merlin\Core\Service\RetentionService;
use Merlin\Core\Service\ShareAccessService;
use Merlin\Core\Service\SiteCredentialService;
use Merlin\Core\Service\SupportBoxService;
use Merlin\Core\Service\TtsStreamService;
use Merlin\Standalone\App;
use Merlin\Standalone\Config;
use Merlin\Standalone\Db\MigrationRunner;

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
	if (!$ok) {
		$failures++;
	}
}

$dir = sys_get_temp_dir() . '/merlin-wiring-' . bin2hex(random_bytes(4));
$app = new App(new Config([
	'db_path' => $dir . '/merlin.sqlite',
	'log_path' => $dir . '/merlin.log',
	'base_url' => 'https://merlin.example.org/',
	'secret' => base64_encode(random_bytes(32)),
]));
(new MigrationRunner($app->connection()))->migrate();

foreach (glob(dirname(__DIR__, 2) . '/core/src/Port/*.php') ?: [] as $file) {
	$port = 'Merlin\\Core\\Port\\' . basename($file, '.php');
	if (!interface_exists($port)) {
		continue;
	}
	$known = isset(App::PORTS[$port]) || in_array($port, App::UNAVAILABLE_PORTS, true);
	check($known, "Port {$port} hat einen Adapter oder gilt als nicht verfügbar");
	if (isset(App::PORTS[$port])) {
		try {
			$adapter = $app->get($port);
			check($adapter instanceof $port, "{$port} baut " . $adapter::class);
		} catch (\Throwable $e) {
			check(false, "{$port} baut", $e->getMessage());
		}
	}
}

foreach ([
	ContentFilterMerger::class, ContentFilterSerializer::class, ContentFilterValidator::class,
	ContentExtractorService::class, MediaResolverService::class, CommentService::class, ShareAccessService::class,
	RetentionService::class, SiteCredentialService::class, ArticleDeletionService::class, SupportBoxService::class,
	ExportService::class, PdfProxyService::class, ReportBackendService::class, TtsStreamService::class,
	// Handler der gemeinsamen API (core/src/Api/routes.php)
	...array_values(array_unique(array_map(static fn (array $r) => $r['handler'][0], \Merlin\Standalone\Http\CoreApi::routes()))),
] as $service) {
	try {
		check($app->get($service) instanceof $service, "Kern-Dienst {$service}");
	} catch (\Throwable $e) {
		check(false, "Kern-Dienst {$service}", $e->getMessage());
	}
}

check($app->get(Port\SiteSessions::class) === $app->get(SiteCredentialService::class), 'Port und Dienst sind dieselbe Instanz');
try {
	$app->get(Port\FilePreviews::class);
	check(false, 'Dateien-Ports melden sich als nicht verfügbar');
} catch (\LogicException) {
	check(true, 'Dateien-Ports melden sich als nicht verfügbar');
}

$app->get(Port\AppSettings::class)->setInt('retention_max_days', 30);
$app->get(Port\AppSettings::class)->setInt('retention_max_days', 60);
check($app->get(Port\AppSettings::class)->getInt('retention_max_days') === 60, 'AppSettings: setzen und überschreiben');
$users = $app->get(Port\UserSettings::class);
$users->set('7', 'merlin', 'theme', 'dark');
$users->set('7', 'merlin', 'theme', 'sepia');
check($users->get('7', 'merlin', 'theme') === 'sepia' && $users->get('8', 'merlin', 'theme', 'x') === 'x', 'UserSettings: je Nutzer, mit Standardwert');

$crypto = $app->get(Port\Crypto::class);
$cipher = $crypto->encrypt('geheim');
check($cipher !== 'geheim' && $crypto->decrypt($cipher) === 'geheim', 'Crypto: Rundweg');

$pdo = $app->connection()->pdo();
$pdo->exec("INSERT INTO users (username, email, password_hash, role, created_at, updated_at) VALUES ('anna', 'a@example.org', 'x', 'user', '', '')");
$id = (string) $pdo->lastInsertId();
check($app->get(Port\UserDirectory::class)->displayName($id) === 'anna' && $app->get(Port\UserDirectory::class)->displayName('999') === '', 'UserDirectory: Benutzername zur ID');

check(str_starts_with($app->get(Port\UrlGenerator::class)->absoluteImageUrl('x-preview.png'), 'https://merlin.example.org/img/'), 'UrlGenerator: Bilder unter der Basis-URL');

$filters = $app->get(Port\ContentFilterSource::class);
check($filters->readBundle('spiegel.de') !== null && count($filters->listFilters()) > 50, 'Content-Filter aus content-filters/ der Repository-Wurzel');
check($filters->getMerged($filters->normalizeUrlDomain('https://www.spiegel.de/politik/x')) instanceof \SimpleXMLElement, 'getMerged für eine Bundle-Domain');

$app = null;
$pdo = null;
foreach (glob($dir . '/*') ?: [] as $file) {
	unlink($file);
}
@rmdir($dir);

if ($failures > 0) {
	echo "\n{$failures} Fehler\n";
	exit(1);
}
echo "\nalle Prüfungen ok\n";
