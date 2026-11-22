<?php

declare(strict_types=1);

/**
 * Extraktion im Standalone-Server: ContentExtractorService aus dem
 * Container des Servers, mit den Bundle-Filtern aus content-filters/ und den
 * Admin-/Nutzer-Ebenen aus der SQLite-Datenbank (merlin_cfilter).
 *
 * Ohne Netz (Standard): ein Artikel-HTML durch die Extraktion, dazu prüfen,
 * dass Admin- und Nutzer-Filter aus der Datenbank greifen (und nur für
 * diesen Nutzer).
 *
 * Mit --live: echte Artikel-Links (Liste unten). Jede Seite wird einmal
 * geladen und dann zweimal extrahiert: mit den Standalone-Adaptern und mit
 * einer Referenz, in der Content-Filter-Ablage und Paywall-Zugangsdaten
 * In-Memory-Stubs sind. Beide Ergebnisse müssen gleich sein, und jedes muss
 * plausibel sein (Titel, Text, bei Artikeln mit Autor auch Autor).
 *
 *   php standalone/tests/test-extraction.php [--live] [url ...]
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Merlin\Core\ContentFilter\ContentFilterRepository;
use Merlin\Core\Domain\SiteCredential;
use Merlin\Core\Extraction\ContentExtractorService;
use Merlin\Core\Extraction\Login\PaywallLoginRequiredException;
use Merlin\Core\Extraction\UnsupportedSiteException;
use Merlin\Core\Port\ContentFilterSource;
use Merlin\Core\Port\ContentFilterStore;
use Merlin\Core\Port\SiteCredentialStore;
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

$dir = sys_get_temp_dir() . '/merlin-extract-' . bin2hex(random_bytes(4));
$config = new Config([
	'db_path' => $dir . '/merlin.sqlite',
	'log_path' => $dir . '/merlin.log',
	'base_url' => 'https://merlin.example.org',
	'secret' => base64_encode(random_bytes(32)),
]);
$app = new App($config);
(new MigrationRunner($app->connection()))->migrate();
$extractor = $app->get(ContentExtractorService::class);
/** @var ContentFilterRepository $filters */
$filters = $app->get(ContentFilterSource::class);

echo "Ohne Netz\n";
$html = <<<'HTML'
<!DOCTYPE html><html lang="de"><head>
<meta charset="utf-8"><title>Ein Testartikel | Beispielblatt</title>
<meta property="og:title" content="Ein Testartikel">
<meta name="author" content="Erika Mustermann">
<meta property="og:site_name" content="Beispielblatt">
<meta property="og:image" content="https://beispiel.example/bild.jpg">
<meta property="article:published_time" content="2026-11-20T08:00:00+01:00">
</head><body>
<nav class="menu">Startseite Politik Kultur</nav>
<article>
<h1>Ein Testartikel</h1>
<h2 class="kicker">Alternativer Titel aus dem Nutzerfilter</h2>
<p>Der erste Absatz erzählt, worum es geht, und ist lang genug, damit Readability ihn als Haupttext erkennt. Er enthält mehrere Sätze mit Inhalt.</p>
<figure><img src="https://beispiel.example/foto.jpg" alt="Foto"><figcaption>Ein Bild vom Ort. Foto: Max Muster</figcaption></figure>
<p>Der zweite Absatz geht weiter ins Detail und beschreibt die Lage ausführlich, mit Zahlen, Namen und einem Zitat aus dem Gespräch.</p>
<p class="werbung">Anzeige: Kaufen Sie jetzt!</p>
<p>Der dritte Absatz schließt den Text ab und fasst zusammen, was als Nächstes passiert und wer daran beteiligt ist.</p>
</article></body></html>
HTML;
$url = 'https://beispiel.example/politik/testartikel';
$plain = $extractor->extractFromHtml($url, $html);
check($plain['title'] === 'Ein Testartikel', 'Titel', (string) $plain['title']);
check($plain['author'] === 'Erika Mustermann', 'Autor aus den Metadaten', (string) $plain['author']);
check(str_contains($plain['content'], 'Der zweite Absatz') && str_contains($plain['content'], '<figure'), 'Text und Bild mit Unterschrift');
check($plain['publishedAt'] instanceof DateTimeInterface, 'Veröffentlichungsdatum');
check(str_contains($plain['content'], 'Anzeige'), 'ohne Filter bleibt die Anzeige im Text');

$filters->saveAdminCustom('beispiel.example', '<domain name="beispiel.example"><pre-filter><remove class="werbung" /></pre-filter></domain>', '1');
$filters->saveUserCustom('5', 'beispiel.example', '<domain name="beispiel.example"><metadata><title xpath="//h2[@class=\'kicker\']" /></metadata></domain>');
$fresh = new App($config);
$extractor2 = $fresh->get(ContentExtractorService::class);
$admin = $extractor2->extractFromHtml($url, $html);
check(!str_contains($admin['content'], 'Anzeige') && $admin['title'] === 'Ein Testartikel', 'Admin-Filter aus merlin_cfilter greift für alle');
$user = $extractor2->extractFromHtml($url, $html, '5');
check($user['title'] === 'Alternativer Titel aus dem Nutzerfilter' && !str_contains($user['content'], 'Anzeige'), 'Nutzerfilter aus merlin_cfilter greift für Nutzer 5, auf der Admin-Ebene', (string) $user['title']);
check($extractor2->extractFromHtml($url, $html, '6')['title'] === 'Ein Testartikel', 'Nutzer 6 sieht den Filter von Nutzer 5 nicht');

try {
	$extractor->assertUrlIsSupported('https://www.nytimes.com/2026/11/22/world/x.html');
	check(false, 'nicht unterstützte Seite aus $unsupported.xml (nytimes.com)');
} catch (UnsupportedSiteException) {
	check(true, 'nicht unterstützte Seite aus $unsupported.xml (nytimes.com)');
}

// ---------------------------------------------------------------------------
$live = in_array('--live', $argv, true);
$urls = array_fill_keys(array_values(array_filter(array_slice($argv, 1), static fn (string $a) => !str_starts_with($a, '--'))), 'any');
if ($live && $urls === []) {
	// Seite => erwartetes Ergebnis: 'article' (Text), 'paywall' (als Paywall
	// erkannt oder Login nötig), 'post' (Social-Post als Embed). Seiten, die
	// Cloud-IPs sperren (sueddeutsche.de, zeit.de, youtube.com), stehen nicht
	// in der Liste; sie lassen sich als Argument von zu Hause aus prüfen.
	$urls = [
		'https://www.spiegel.de/ausland/auswandern-in-die-schweiz-12-dinge-die-sie-vorher-wissen-sollten-a-14cd0939-59d0-4648-b770-91769305ae7a' => 'any',
		'https://www.spiegel.de/wissenschaft/cop31-deutschland-haelt-beschlusstext-fuer-klimakonferenz-nicht-fuer-zustimmungsfaehig-a-b1172321-139e-4859-8cb5-84327eaa462f' => 'any',
		'https://netzpolitik.org/2026/einsamkeit-im-alter-aber-wenigstens-hoeren-sie-mir-zu/' => 'article',
		'https://taz.de/CDU-trifft-GTA/!6232201/' => 'article',
		'https://www.tagesschau.de/ausland/asien/eilmeldung-weltklimakonferenz-cop31-antalya-kein-abschlussdokument-100.html' => 'article',
		'https://www.rbb24.de/panorama/beitrag/2026/11/berlin-marzahn-trauerfeier-getoetete-lisa-13-jaehrige.html' => 'article',
		'https://www.heise.de/news/Was-das-Videospiel-GTA-6-ueber-unsere-Gegenwart-erzaehlt-11533740.html' => 'article',
		'https://www.nd-aktuell.de/artikel/1204000.cop-klimakrise-bitte-verteidigt-die-wissenschaft.html' => 'article',
		'https://www.deutschlandfunk.de/buendnis-sahra-wagenknecht-bsw-soziale-gerechtigkeit-wirtschaftliche-vernunft-100.html' => 'article',
		'https://www.tagesspiegel.de/gesellschaft/ultrafan-zum-start-von-grand-theft-auto-6-vielleicht-muss-ich-mich-ubergeben-weinen-werde-ich-bestimmt-16290044.html' => 'paywall',
		'https://bsky.app/profile/did:plc:e6n7jxtu2qrhwvp3j6ib6sq6/post/3n2u4lvqty227' => 'post',
		'https://mastodon.social/@Mastodon/117625790631441803' => 'post',
	];
}

if ($urls !== []) {
	echo "\nEchte Artikel-Links\n";

	// Referenz: dieselben Kern-Klassen, aber Content-Filter-Ablage und
	// Paywall-Zugangsdaten im Speicher statt in SQLite.
	$memoryFilters = new class implements ContentFilterStore {
		public function readXml(string $scope, string $userId, string $domain): ?string { return null; }
		public function findId(string $scope, string $userId, string $domain): ?int { return null; }
		public function insert(string $scope, string $userId, string $domain, string $xml, \DateTime $now, string $updatedBy): void {}
		public function update(int $id, string $xml, \DateTime $now, string $updatedBy): void {}
		public function delete(string $scope, string $userId, string $domain): void {}
		public function deleteAllForUser(string $scope, string $userId): void {}
		public function listDomains(string $scope, ?string $userId = null, bool $sorted = false): array { return []; }
		public function countByDomain(string $scope): array { return []; }
	};
	$memoryCredentials = new class implements SiteCredentialStore {
		public function find(string $userId, string $domain): ?SiteCredential { return null; }
		public function findAllForUser(string $userId): array { return []; }
		public function save(SiteCredential $credential): void {}
		public function deleteByUserAndDomain(string $userId, string $domain): void {}
	};
	// Eigene Datenbank ohne die Test-Filter von oben.
	$liveApp = new App(new Config(['db_path' => $dir . '/live.sqlite', 'log_path' => $dir . '/merlin.log',
		'base_url' => 'https://merlin.example.org', 'secret' => base64_encode(random_bytes(32))]));
	(new MigrationRunner($liveApp->connection()))->migrate();
	$standalone = $liveApp->get(ContentExtractorService::class);
	$reference = (new App($config, [ContentFilterStore::class => $memoryFilters, SiteCredentialStore::class => $memoryCredentials]))
		->get(ContentExtractorService::class);

	$normalize = static function (array $result): array {
		foreach ($result as $key => $value) {
			if ($value instanceof DateTimeInterface) {
				$result[$key] = $value->format(DATE_ATOM);
			}
		}
		return $result;
	};

	foreach ($urls as $articleUrl => $expect) {
		$host = (string) parse_url($articleUrl, PHP_URL_HOST);
		try {
			$a = $normalize($standalone->extract($articleUrl));
		} catch (PaywallLoginRequiredException $e) {
			// Gleiche Ausnahme über die Referenz? Dann verhalten sich beide gleich.
			try {
				$reference->extract($articleUrl);
				$same = false;
			} catch (PaywallLoginRequiredException) {
				$same = true;
			} catch (\Throwable) {
				$same = false;
			}
			check(in_array($expect, ['paywall', 'any'], true) && $same, $host . ': Paywall, Login nötig (' . $e->getMessage() . ')');
			continue;
		} catch (\Throwable $e) {
			check(false, $articleUrl, get_class($e) . ': ' . $e->getMessage());
			continue;
		}
		// Zweite Extraktion mit derselben URL über die Referenz. Die Seite wird
		// dafür erneut geladen; dynamische Teile (Werbe-IDs, Uhrzeiten) können
		// sich unterscheiden, deshalb Vergleich auf den stabilen Feldern.
		try {
			$b = $normalize($reference->extract($articleUrl));
		} catch (\Throwable $e) {
			check(false, $articleUrl . ' (Referenz)', $e->getMessage());
			continue;
		}
		$fields = ['url', 'title', 'author', 'authors', 'siteName', 'imageUrl', 'category', 'isPaywalled', 'publishedAt'];
		$diff = [];
		foreach ($fields as $field) {
			if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
				$diff[] = $field;
			}
		}
		$lengthRatio = strlen((string) $b['content']) > 0 ? strlen((string) $a['content']) / strlen((string) $b['content']) : (strlen((string) $a['content']) === 0 ? 1.0 : 0.0);
		$text = trim(strip_tags((string) $a['content']));
		$paywalled = (bool) ($a['isPaywalled'] ?? false);
		$plausible = trim((string) $a['title']) !== '' && match ($expect) {
			'article' => !$paywalled && mb_strlen($text) > 800,
			'paywall' => $paywalled,
			'post' => str_contains((string) $a['content'], '-embed') || str_contains((string) $a['content'], 'class="merlin-'),
			default => $paywalled || mb_strlen($text) > 800,
		};
		$summary = sprintf('%s | %s | %d Zeichen, %d Bilder, %d Bildquellen | %s',
			$a['title'], $a['author'] ?? '–', mb_strlen($text), substr_count((string) $a['content'], '<img'),
			substr_count((string) $a['content'], '<cite'), ($a['isPaywalled'] ?? false) ? 'Paywall' : 'frei');
		check($plausible && $diff === [] && abs(1 - $lengthRatio) < 0.05, $host . ': ' . $summary,
			($diff !== [] ? 'abweichend: ' . implode(', ', $diff) . ' ' : '') . ($plausible ? '' : 'nicht plausibel ') . sprintf('Länge %.2f', $lengthRatio));
	}
}

$app = $fresh = $liveApp = null;
foreach (glob($dir . '/*') ?: [] as $file) {
	@unlink($file);
}
@rmdir($dir);

if ($failures > 0) {
	echo "\n{$failures} Fehler\n";
	exit(1);
}
echo "\nalle Prüfungen ok\n";
