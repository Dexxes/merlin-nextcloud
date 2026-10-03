<?php

declare(strict_types=1);

/**
 * Testharness für die Medien-Provider (lib/Service/Media/).
 *
 * Aufruf (im App-Verzeichnis):
 *   php tools/test-media-providers.php          – nur Offline-Prüfungen
 *   php tools/test-media-providers.php --live   – zusätzlich gegen die echten
 *                                                  Sender (Netzwerk nötig)
 *
 * Wie die anderen Harnesses bewusst ohne Composer-Autoload, PHPUnit oder
 * Nextcloud-Bootstrap. Die einzige Framework-Abhängigkeit
 * (Psr\Log\LoggerInterface) wird unten als Stub deklariert,
 * ContentFilterRepository durch eine Unterklasse mit festen Configs ersetzt.
 *
 * Die Live-Prüfungen hängen von fremden Servern ab: ein Fehlschlag dort kann
 * auch heissen, dass ein Beitrag depubliziert wurde – dann die Beispiel-URL
 * unten austauschen.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

namespace Psr\Log {

	if (!interface_exists(LoggerInterface::class)) {
		interface LoggerInterface {
			public function emergency($message, array $context = []);
			public function alert($message, array $context = []);
			public function critical($message, array $context = []);
			public function error($message, array $context = []);
			public function warning($message, array $context = []);
			public function notice($message, array $context = []);
			public function info($message, array $context = []);
			public function debug($message, array $context = []);
			public function log($level, $message, array $context = []);
		}
	}
}

namespace Merlin\MediaTest {

	use OCA\Merlin\Service\ContentFilterRepository;
	use Psr\Log\LoggerInterface;

	// Vor der Unterklasse unten nötig – der Autoloader wird erst im
	// Test-Block registriert.
	require_once __DIR__ . '/../lib/Service/ContentFilterRepository.php';
	require_once __DIR__ . '/../lib/Service/Http/SsrfSafeResolver.php';
	require_once __DIR__ . '/../lib/Service/Media/MediaHttpClient.php';

	final class NullLogger implements LoggerInterface {
		public function emergency($message, array $context = []) {}
		public function alert($message, array $context = []) {}
		public function critical($message, array $context = []) {}
		public function error($message, array $context = []) {}
		public function warning($message, array $context = []) {}
		public function notice($message, array $context = []) {}
		public function info($message, array $context = []) {}
		public function debug($message, array $context = []) {}
		public function log($level, $message, array $context = []) {}
	}

	/** Antwortet mit festen JSON-Daten je URL-Präfix, sonst wie bei einem 404 mit null. */
	final class FakeMediaHttpClient extends \OCA\Merlin\Service\Media\MediaHttpClient {
		/** @var list<string> */
		public array $requested = [];

		/**
		 * @param array<string, array<mixed>> $responses
		 * @param array<string, string> $pages
		 */
		public function __construct(private array $responses, private array $pages = []) {
		}

		public function getText(string $url): ?string {
			$this->requested[] = $url;
			return $this->pages[$url] ?? null;
		}

		public function getJson(string $url, array $extraHeaders = []): ?array {
			$this->requested[] = $url;
			foreach ($this->responses as $prefix => $json) {
				if (str_starts_with($url, $prefix)) {
					return $json;
				}
			}
			return null;
		}
	}

	/** Liefert die Bundle-Dateien aus content-filters/ ohne Datenbank. */
	final class BundleOnlyRepository extends ContentFilterRepository {
		public function __construct() {
		}

		public function getMerged(string $domain, ?string $userId = null): ?\SimpleXMLElement {
			$path = __DIR__ . '/../content-filters/' . $domain . '.xml';
			return is_file($path) ? simplexml_load_file($path) ?: null : null;
		}
	}

	final class TestRunner {
		private int $passed = 0;
		/** @var list<string> */
		private array $failures = [];
		private string $group = '';

		public function group(string $name): void {
			$this->group = $name;
			echo "\n\033[1m" . $name . "\033[0m\n";
		}

		public function ok(bool $condition, string $label, string $detail = ''): void {
			if ($condition) {
				$this->passed++;
				echo "  \033[32m✓\033[0m " . $label . "\n";
				return;
			}
			$this->failures[] = $this->group . ' → ' . $label . ($detail !== '' ? ' | ' . $detail : '');
			echo "  \033[31m✗ " . $label . "\033[0m\n";
			if ($detail !== '') {
				echo '      ' . str_replace("\n", "\n      ", $detail) . "\n";
			}
		}

		public function eq(mixed $actual, mixed $expected, string $label): void {
			$this->ok($actual === $expected, $label, 'erwartet: ' . var_export($expected, true) . "\nerhalten: " . var_export($actual, true));
		}

		public function summary(): int {
			$total = $this->passed + count($this->failures);
			echo "\n" . str_repeat('─', 72) . "\n";
			if ($this->failures === []) {
				echo "\033[32mAlle " . $total . " Prüfungen bestanden.\033[0m\n";
				echo str_repeat('─', 72) . "\n";
				return 0;
			}
			echo "\033[31m" . count($this->failures) . ' von ' . $total . " Prüfungen fehlgeschlagen:\033[0m\n";
			foreach ($this->failures as $failure) {
				echo '  · ' . $failure . "\n";
			}
			return 1;
		}
	}
}

namespace {

	use Merlin\MediaTest\BundleOnlyRepository;
	use Merlin\MediaTest\FakeMediaHttpClient;
	use Merlin\MediaTest\NullLogger;
	use Merlin\MediaTest\TestRunner;
	use OCA\Merlin\Service\ContentFilterSchema;
	use OCA\Merlin\Service\Media\MediaContext;
	use OCA\Merlin\Service\Media\MediaHttpClient;
	use OCA\Merlin\Service\Media\MediaProviderRegistry;
	use OCA\Merlin\Service\Media\MediaResolverService;
	use OCA\Merlin\Service\Media\MediaResult;
	use OCA\Merlin\Service\Media\Provider\ArdMediathekProvider;
	use OCA\Merlin\Service\Media\Provider\ArteProvider;
	use OCA\Merlin\Service\Media\Provider\DreiSatProvider;
	use OCA\Merlin\Service\Media\Provider\JsonLdMediaProvider;
	use OCA\Merlin\Service\Media\Provider\XPathMediaProvider;
	use OCA\Merlin\Service\Media\Provider\YoutubeEmbedProvider;
	use OCA\Merlin\Service\Media\Provider\ZdfProvider;

	spl_autoload_register(static function (string $class): void {
		$prefix = 'OCA\\Merlin\\';
		if (str_starts_with($class, $prefix)) {
			$path = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($path)) {
				require_once $path;
			}
		}
	});

	$live = in_array('--live', $argv, true);
	$t    = new TestRunner();

	$http     = new MediaHttpClient();
	$registry = new MediaProviderRegistry(
		new ArdMediathekProvider($http),
		new ZdfProvider($http),
		new ArteProvider($http),
		new DreiSatProvider($http),
		new XPathMediaProvider(),
		new JsonLdMediaProvider(),
		new YoutubeEmbedProvider(),
	);
	$resolver = new MediaResolverService($registry, new BundleOnlyRepository(), new NullLogger());

	$source = static function (string $attributes): \SimpleXMLElement {
		return new \SimpleXMLElement('<source ' . $attributes . ' />');
	};
	$bundleConfig = static function (string $domain): ?\SimpleXMLElement {
		return (new BundleOnlyRepository())->getMerged($domain);
	};

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('1. Registry und Schema kennen dieselben Typen');

	$registryTypes = $registry->types();
	$schemaTypes   = ContentFilterSchema::MEDIA_SOURCE_TYPES;
	sort($registryTypes);
	sort($schemaTypes);
	$t->eq($registryTypes, $schemaTypes, 'MediaProviderRegistry::types() == ContentFilterSchema::MEDIA_SOURCE_TYPES');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('2. YouTube: Video-ID aus allen URL-Formen');

	$youtube = new YoutubeEmbedProvider();
	$ytCases = [
		'https://www.youtube.com/watch?v=ECbCbaGCpIQ'          => 'https://www.youtube-nocookie.com/embed/ECbCbaGCpIQ',
		'https://m.youtube.com/watch?v=ECbCbaGCpIQ&t=90'       => 'https://www.youtube-nocookie.com/embed/ECbCbaGCpIQ?start=90',
		'https://youtu.be/ECbCbaGCpIQ?t=1m5s'                  => 'https://www.youtube-nocookie.com/embed/ECbCbaGCpIQ?start=65',
		'https://www.youtube.com/shorts/ECbCbaGCpIQ'           => 'https://www.youtube-nocookie.com/embed/ECbCbaGCpIQ',
		'https://www.youtube.com/live/ECbCbaGCpIQ?feature=x'   => 'https://www.youtube-nocookie.com/embed/ECbCbaGCpIQ',
	];
	foreach ($ytCases as $url => $expected) {
		$result = $youtube->resolve(new MediaContext($url, $source('type="youtube-embed" kind="video"')));
		$t->eq($result?->defaultUrl(), $expected, $url);
	}
	$t->eq($youtube->resolve(new MediaContext('https://www.youtube.com/@kanal', $source('type="youtube-embed" kind="video"'))), null, 'Kanalseite ohne Video-ID → null');
	$t->eq($youtube->resolve(new MediaContext('https://www.youtube.com/watch?v=<script>', $source('type="youtube-embed" kind="video"'))), null, 'ungültige ID → null');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('3. xpath-Provider (Deutschlandfunk-Markup)');

	// Nachbau der relevanten DLF-Struktur: Live-Button in der Navigation VOR
	// dem Beitrags-Audio, beide mit data-audio-Attributen.
	$dlfHtml = '<html><body>
		<a class="b-btn-live js-btn-live" data-islive="1" data-audio="https://st02.sslstream.dlf.de/dlf/02/128/mp3/stream.mp3">Live</a>
		<article><a alt="Anhören" data-audio="https://ondemand-mp3.dradio.de/file/dradio/2026/09/11/beitrag.mp3">Anhören</a></article>
		<a data-audio="https://evil.example/x.mp3">x</a>
	</body></html>';
	$dlfSource = $bundleConfig('deutschlandfunkkultur.de')?->media->source;
	$t->ok($dlfSource !== null, 'deutschlandfunkkultur.de.xml deklariert eine <media><source>');
	$xpathProvider = new XPathMediaProvider();
	$result = $dlfSource !== null ? $xpathProvider->resolve(new MediaContext('https://www.deutschlandfunkkultur.de/a-100.html', $dlfSource, $dlfHtml)) : null;
	$t->eq($result?->defaultUrl(), 'https://ondemand-mp3.dradio.de/file/dradio/2026/09/11/beitrag.mp3', 'Beitrags-Audio statt Live-Stream');
	$t->eq($result?->kind, MediaResult::KIND_AUDIO, 'kind=audio');
	$t->eq($result?->delivery, MediaResult::DELIVERY_FILE, 'mp3 → delivery=file');
	$t->eq($result?->isPersistable(), true, 'Datei-Quelle ist persistierbar');

	$foreign = $xpathProvider->resolve(new MediaContext('https://x.example/', $source('type="xpath" kind="audio" xpath="(//a[@data-audio][3]/@data-audio)[1]" host-allow="dradio.de"'), '<a data-audio="https://evil.example/x.mp3"></a>'));
	$t->eq($foreign, null, 'host-allow verwirft fremde Hosts');
	$plainHttp = $xpathProvider->resolve(new MediaContext('https://x.example/', $source('type="xpath" kind="audio" xpath="//a/@href"'), '<a href="http://plain.example/x.mp3"></a>'));
	$t->eq($plainHttp, null, 'http-URL wird verworfen');
	$t->eq($xpathProvider->resolve(new MediaContext('https://x.example/', $source('type="xpath" kind="audio" xpath="//a/@href"'))), null, 'ohne HTML (beim Öffnen) → null');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('4. json-ld-Provider (ARD-Sounds-Markup)');

	$ardSoundsHtml = '<html><head>
		<script type="application/ld+json">{"@type":"BreadcrumbList","itemListElement":[]}</script>
		<script type="application/ld+json" data-next-head="">{"@context":"https://schema.org","@type":"PodcastEpisode","description":"Absatz eins.\n\nAbsatz zwei.","associatedMedia":{"@type":"MediaObject","contentUrl":"https://funk-02dd.akamaized.net/22679/uploads/episode.mp3"}}</script>
	</head><body></body></html>';
	$ardSoundsConfig = $bundleConfig('ardsounds.de');
	$t->ok($ardSoundsConfig !== null && isset($ardSoundsConfig->media->source), 'ardsounds.de.xml deklariert eine <media><source>');
	$save = $resolver->resolveOnSave('https://www.ardsounds.de/episode/urn:ard:episode:1/', $ardSoundsConfig, $ardSoundsHtml);
	$t->eq($save['result']?->defaultUrl(), 'https://funk-02dd.akamaized.net/22679/uploads/episode.mp3', 'contentUrl aus verschachteltem MediaObject');
	$t->eq($save['kind'] ?? null, 'audio', 'kind=audio');

	// tagesschau.de/rbb24.de: EINE json-ld-Quelle, die Medienart folgt dem
	// @type des ersten Medienobjekts (Aufmacher), nicht dem kind der Regel.
	$ldPage = static fn (string $first, string $second): string => '<html><head>
		<script type="application/ld+json">{"@type":"NewsArticle","headline":"x"}</script>
	</head><body>
		<div class="article-head"><script type="application/ld+json">' . $first . '</script></div>
		<div class="copytext__video"><script type="application/ld+json">' . $second . '</script></div>
	</body></html>';
	$tsAudio = '{"@type":"AudioObject","contentUrl":"https://tagesschau-podcast.ard-mcdn.de/audio/2026/0928/AU-1.mp3"}';
	$tsVideo = '{"@type":"VideoObject","contentUrl":"https://tagesschau-progressive.ard-mcdn.de/video/2026/0928/TV-1.webxxl.h264.mp4"}';
	$tsConfig = $bundleConfig('tagesschau.de');
	$t->ok($tsConfig !== null && isset($tsConfig->media->source), 'tagesschau.de.xml deklariert eine <media><source>');
	$save = $resolver->resolveOnSave('https://www.tagesschau.de/a-100.html', $tsConfig, $ldPage($tsAudio, $tsVideo));
	$t->eq([$save['kind'] ?? null, $save['result']?->defaultUrl()], ['audio', 'https://tagesschau-podcast.ard-mcdn.de/audio/2026/0928/AU-1.mp3'], 'tagesschau: Audio-Aufmacher → kind=audio');
	$save = $resolver->resolveOnSave('https://www.tagesschau.de/a-100.html', $tsConfig, $ldPage($tsVideo, $tsAudio));
	$t->eq([$save['kind'] ?? null, $save['result']?->defaultUrl()], ['video', 'https://tagesschau-progressive.ard-mcdn.de/video/2026/0928/TV-1.webxxl.h264.mp4'], 'tagesschau: Video-Aufmacher → kind=video');

	$rbbAudio = '{"@type":"AudioObject","contentUrl":"https://rbbmediapmdp-a.akamaihd.net/content/1e/4e/x_mp3-256k.mp3"}';
	$rbbVideo = '{"@type":"VideoObject","contentUrl":"https://rbb-progressive.ard-mcdn.de/content/62/3a/x_hd1080-avc1080.mp4"}';
	$rbbConfig = $bundleConfig('rbb24.de');
	$t->ok($rbbConfig !== null && isset($rbbConfig->media->source), 'rbb24.de.xml deklariert eine <media><source>');
	$save = $resolver->resolveOnSave('https://www.rbb24.de/a.html', $rbbConfig, $ldPage($rbbAudio, $rbbVideo));
	$t->eq([$save['kind'] ?? null, $save['result']?->delivery], ['audio', 'file'], 'rbb24: Audio-Aufmacher → audio/file');
	$save = $resolver->resolveOnSave('https://www.rbb24.de/a.html', $rbbConfig, $ldPage($rbbVideo, $rbbAudio));
	$t->eq([$save['kind'] ?? null, $save['result']?->delivery], ['video', 'file'], 'rbb24: Video-Aufmacher → video/file');
	$foreignLd = '{"@type":"VideoObject","contentUrl":"https://evil.example/x.mp4"}';
	$save = $resolver->resolveOnSave('https://www.rbb24.de/a.html', $rbbConfig, $ldPage($foreignLd, $rbbAudio));
	$t->eq($save['result']?->defaultUrl(), 'https://rbbmediapmdp-a.akamaihd.net/content/1e/4e/x_mp3-256k.mp3', 'rbb24: fremder Host übersprungen, nächstes Objekt gewinnt');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('5. Marker: bauen, parsen, Mediathek-Provider erst beim Öffnen');

	$fileResult = MediaResult::single('audio', 'file', 'https://ondemand-mp3.dradio.de/a.mp3');
	$marker     = MediaResolverService::buildMarkerHtml('audio', $fileResult, 'https://www.deutschlandfunk.de/a.html');
	$parsed     = MediaResolverService::parseMarker('<p>vorher</p>' . $marker . '<p>Text</p>');
	$t->eq($parsed, ['kind' => 'audio', 'delivery' => 'file', 'src' => 'https://ondemand-mp3.dradio.de/a.mp3'], 'Marker mit Quelle round-trip');
	$t->ok(str_contains($marker, 'href="https://ondemand-mp3.dradio.de/a.mp3"'), 'Fallback-Link zeigt bei Dateien auf die Datei');

	$deferred = MediaResolverService::buildMarkerHtml('video', null, 'https://www.ardmediathek.de/video/x/y/z/abc123');
	$t->eq(MediaResolverService::parseMarker($deferred), ['kind' => 'video', 'delivery' => null, 'src' => null], 'Marker ohne Quelle (Mediathek)');
	$t->ok(str_contains($deferred, 'merlin-video-fallback-link'), 'Video-Marker trägt die alte Fallback-Klasse');
	$t->eq(
		MediaResolverService::parseMarker('<div class="merlin-media" data-media-kind="audio" data-media-delivery="file" data-media-src="javascript:alert(1)"></div>'),
		['kind' => 'audio', 'delivery' => null, 'src' => null],
		'Marker mit unsicherer Quelle verliert die Quelle'
	);
	$t->eq(MediaResolverService::parseMarker('<p>kein Marker</p>'), null, 'kein Marker → null');

	$ardSave = $resolver->resolveOnSave('https://www.ardmediathek.de/video/x/y/swr/abc', $bundleConfig('ardmediathek.de'), '<html></html>');
	$t->eq($ardSave, ['kind' => 'video', 'result' => null], 'ARD: beim Speichern nur Marker ohne Quelle (kein API-Aufruf)');

	$ytRequest = $resolver->resolveOnRequest('https://www.youtube.com/watch?v=ECbCbaGCpIQ', '<a class="merlin-video-fallback-link">Zum Video</a>', null);
	$t->eq($ytRequest?->delivery, 'embed', 'Alter YouTube-Artikel ohne Marker → Embed beim Öffnen');

	$fromMarker = $resolver->resolveOnRequest('https://www.deutschlandfunk.de/a.html', $marker, null);
	$t->eq($fromMarker?->defaultUrl(), 'https://ondemand-mp3.dradio.de/a.mp3', 'Beim Öffnen gewinnt die Quelle im Marker');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('6. ZDF-Beschreibung aus dem Next.js-Payload');

	$zdfHtml = '<script>self.__next_f.push([1,"{\\"teaser\\":{\\"title\\":\\"T\\",\\"description\\":\\"Kurzer Teaser\\",\\"imageWithoutLogo\\":1},\\"longInfoText\\":{\\"items\\":[{\\"text\\":\\"Erster Absatz\\",\\"style\\":1},{\\"text\\":\\"Zweiter \\\\\\"zitiert\\\\\\"\\",\\"style\\":1}],\\"editorialDate\\":1}"])</script>';
	$description = $resolver->providerDescription($bundleConfig('zdf.de'), $zdfHtml);
	$t->eq($description['teaser'] ?? null, 'Kurzer Teaser', 'Teaser');
	$t->eq($description['paragraphs'] ?? null, ['Erster Absatz', 'Zweiter "zitiert"'], 'Absätze inkl. doppelt escapter Anführungszeichen');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('6b. ARD: Übersichtsseite (/film/) bietet ihre Videos zur Auswahl an');

	$api = 'https://api.ardmediathek.de/page-gateway/pages/ard/';
	$ardItem = static fn (array $streams): array => ['widgets' => [['type' => 'player_ondemand', 'mediaCollection' => ['embedded' => ['streams' => $streams]]]]];
	$fakeHttp = new FakeMediaHttpClient([
		$api . 'grouping/R3JvdXBpbmdJZA' => ['widgets' => [
			['type' => 'gridlist', 'teasers' => [
				['type' => 'ondemand', 'mediumTitle' => 'Der Film', 'links' => ['target' => ['id' => 'RmlsbUlk']]],
				['type' => 'ondemand', 'shortTitle' => 'Trailer', 'id' => 'VHJhaWxlcklk'],
				['type' => 'ondemand', 'mediumTitle' => 'Doppelt', 'id' => 'RmlsbUlk'],
				['type' => 'live', 'mediumTitle' => 'Livestream', 'id' => 'TGl2ZUlk'],
			]],
		]],
		$api . 'item/RmlsbUlk' => $ardItem([
			['kind' => 'main', 'kindName' => 'Normal', 'media' => [['url' => 'https://example.akamaized.net/film.m3u8']]],
			['kind' => 'sign_language', 'kindName' => 'DGS', 'media' => [['url' => 'https://example.akamaized.net/film-dgs.m3u8']]],
		]),
		$api . 'item/VHJhaWxlcklk' => $ardItem([
			['kind' => 'main', 'kindName' => 'Normal', 'media' => [['url' => 'https://example.akamaized.net/trailer.mp4'], ['url' => 'https://example.akamaized.net/trailer.m3u8']]],
		]),
	]);
	$ardProvider = new ArdMediathekProvider($fakeHttp);
	$ardSource = $source('type="ard-mediathek" kind="video"');
	$grouping = $ardProvider->resolve(new MediaContext('https://www.ardmediathek.de/film/der-film/R3JvdXBpbmdJZA', $ardSource));
	$t->eq(
		array_map(static fn (array $v): string => $v['label'] . ' ' . $v['url'], $grouping?->variants ?? []),
		[
			'Der Film https://example.akamaized.net/film.m3u8',
			'Der Film · DGS https://example.akamaized.net/film-dgs.m3u8',
			'Trailer https://example.akamaized.net/trailer.m3u8',
		],
		'Videos der Übersichtsseite als Varianten, ohne Doppelte und Livestreams'
	);
	$t->eq($grouping?->defaultIndex, 0, 'Vorauswahl ist das erste Video');
	$t->ok(in_array($api . 'grouping/R3JvdXBpbmdJZA', $fakeHttp->requested, true), 'Grouping ohne embedded=false abgefragt (sonst liefert die API keine Teaser)');
	$t->ok(!in_array($api . 'item/TGl2ZUlk?embedded=false&mcV6=true', $fakeHttp->requested, true), 'Live-Teaser wird nicht abgefragt');

	$single = new FakeMediaHttpClient([$api . 'item/RmlsbUlk' => $ardItem([
		['kind' => 'main', 'kindName' => 'Normal', 'media' => [['url' => 'https://example.akamaized.net/film.m3u8']]],
	])]);
	$singleResult = (new ArdMediathekProvider($single))->resolve(new MediaContext('https://www.ardmediathek.de/video/x/der-film/ndr/RmlsbUlk', $ardSource));
	$t->eq($singleResult?->variants, [['label' => 'Normal', 'url' => 'https://example.akamaized.net/film.m3u8']], 'Einzelvideo: Label bleibt die Fassung');
	$t->eq(count($single->requested), 1, 'Einzelvideo: keine Grouping-Abfrage');

	// ══════════════════════════════════════════════════════════════════════════
	$t->group('6c. 3sat: Stream über data-zdfplayer-jsb und api.3sat.de');

	$dreiSatUrl = 'https://www.3sat.de/kultur/kulturdoku/one-night-at-kitkat-104.html';
	$contentUrl = 'https://api.3sat.de/content/documents/zdf/kultur/kulturdoku/one-night-at-kitkat-104.json?profile=player2';
	$jsb = htmlspecialchars(json_encode(['content' => $contentUrl, 'apiToken' => 'tok123'], JSON_UNESCAPED_SLASHES), ENT_QUOTES);
	$dreiSatHttp = new FakeMediaHttpClient([
		$contentUrl => [
			'http://zdf.de/rels/next-video-page' => ['mainVideoContent' => ['http://zdf.de/rels/target' => ['http://zdf.de/rels/streams/ptmd-template' => '/tmd/2/{playerId}/vod/ptmd/3sat/falsch/1']]],
			'mainVideoContent' => ['http://zdf.de/rels/target' => ['http://zdf.de/rels/streams/ptmd-template' => '/tmd/2/{playerId}/vod/ptmd/3sat/kitkat/2']],
		],
		'https://api.3sat.de/tmd/2/ngplayer_2_4/vod/ptmd/3sat/kitkat/2' => ['priorityList' => [
			['formitaeten' => [['qualities' => [['audio' => ['tracks' => [['uri' => 'https://nrodlzdf-a.akamaihd.net/kitkat.webm']]]]]]]],
			['formitaeten' => [['qualities' => [['audio' => ['tracks' => [['uri' => 'https://zdfvod.akamaized.net/kitkat.mp4.csmil/master.m3u8']]]]]]]],
		]],
	], [$dreiSatUrl => '<div class="b-playerbox" data-zdfplayer-jsb="' . $jsb . '"></div>']);
	$dreiSat = (new DreiSatProvider($dreiSatHttp))->resolve(new MediaContext($dreiSatUrl, $source('type="3sat" kind="video"')));
	$t->eq($dreiSat?->defaultUrl(), 'https://zdfvod.akamaized.net/kitkat.mp4.csmil/master.m3u8', 'm3u8 aus der PTMD des Hauptvideos');
	$t->eq($dreiSat?->delivery, MediaResult::DELIVERY_HLS, 'delivery=hls');
	$t->eq(DreiSatProvider::parsePlayerConfig('<div data-zdfplayer-jsb=\'{"content":"https://evil.example/x.json","apiToken":"t"}\'></div>'), null, 'Content-URL auf fremdem Host wird verworfen');
	$t->eq((new DreiSatProvider($dreiSatHttp))->resolve(new MediaContext('https://evil.example/x.html', $source('type="3sat" kind="video"'))), null, 'Artikel-URL außerhalb von 3sat.de → keine Abfrage');
	$t->eq((string) ($bundleConfig('3sat.de')?->media->source['type'] ?? ''), '3sat', '3sat.de.xml deklariert die 3sat-Quelle');

	// ══════════════════════════════════════════════════════════════════════════
	if ($live) {
		$t->group('7. Live gegen die Beispiel-URLs');

		$fetch = static function (string $url): string {
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
			]);
			$html = curl_exec($ch);
			curl_close($ch);
			return is_string($html) ? $html : '';
		};

		$liveCases = [
			['https://www.ardmediathek.de/video/babylon-berlin/babylon-berlin-die-doku-wie-die-demokratie-unterging-s05-e09/swr/Y3JpZDovL3N3ci5kZS9hZXgvbzIzNDkyMDY', 'ardmediathek.de', 'video', 'hls'],
			['https://www.ardmediathek.de/film/sommer-auf-asphalt-oder-komoedie/Y3JpZDovL25kci5kZS81MDQzIGM4ZDUyNDkxLTk5ODUtNGJjZi05ZjlhLTc4MWM0MmE4ZDM1Mw', 'ardmediathek.de', 'video', 'hls'],
			['https://www.zdf.de/video/reportagen/37-grad-leben-102/marcant--auf-tiktok-gegen-rechts-102', 'zdf.de', 'video', 'hls'],
			['https://www.3sat.de/kultur/kulturdoku/one-night-at-kitkat-104.html', '3sat.de', 'video', 'hls'],
			['https://www.arte.tv/de/videos/113630-007-A/country-music-7-9/', 'arte.tv', 'video', 'hls'],
			['https://www.youtube.com/watch?v=ECbCbaGCpIQ', 'youtube.com', 'video', 'embed'],
			['https://www.deutschlandfunkkultur.de/elektrotech-wer-auf-strom-setzt-spart-kuenftig-viel-geld-100.html', 'deutschlandfunkkultur.de', 'audio', 'file'],
			['https://www.ardsounds.de/episode/urn:ard:episode:3619826c5915c2e0/', 'ardsounds.de', 'audio', 'file'],
			['https://www.tagesschau.de/tagesschau_in_100_sekunden/video-1656878.html', 'tagesschau.de', 'video', 'file'],
			['https://www.tagesschau.de/multimedia/audio/audio-3503736.html', 'tagesschau.de', 'audio', 'file'],
			['https://www.rbb24.de/panorama/av/av24/forscher-aus-senftenberg-entwickeln-sternestaub-fuer-supercomput.html', 'rbb24.de', 'video', 'file'],
		];
		foreach ($liveCases as [$url, $domain, $kind, $delivery]) {
			$config = $bundleConfig($domain);
			$save   = $resolver->resolveOnSave($url, $config, $fetch($url));
			// Wie im Reader: ein Marker mit Quelle wird direkt abgespielt, sonst
			// fragt der Reader /media (resolveOnRequest ohne HTML).
			$content = $save === null ? '' : MediaResolverService::buildMarkerHtml($save['kind'], $save['result'], $url);
			$result  = $resolver->resolveOnRequest($url, $content, null);
			$t->ok(
				$result !== null
					&& $result->kind === $kind
					&& $result->delivery === $delivery
					&& str_starts_with($result->defaultUrl(), 'https://')
					&& !str_contains($result->defaultUrl(), 'sslstream'),
				$domain . ': ' . $kind . '/' . $delivery,
				$result === null ? 'keine Quelle' : json_encode($result->toArray(), JSON_UNESCAPED_SLASHES)
			);
		}
	}

	exit($t->summary());
}
