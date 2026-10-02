<?php

declare(strict_types=1);

/**
 * Testharness für Inline-Videos (<media><inline>, lib/Service/Media/
 * InlineMediaService.php) am Beispiel des ARD-Players bei rbb24.de.
 *
 * Aufruf (im App-Verzeichnis, nach composer install):
 *   php tools/test-inline-media.php
 *
 * Teil 1 prüft den Dienst allein, Teil 2 die ganze Extraktion
 * (ContentExtractorService::processHtml(), inkl. Readability und Sanitizer)
 * mit dem Markup aus
 * https://www.rbb24.de/content/rbb/r24/wirtschaft/beitrag/2026/09/berlin-leere-bueros-wohnungen.html
 * Die Medien-JSON des Players kommt aus einem Stub statt aus dem Netz.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

namespace Merlin\InlineMediaTest {

	use OCA\Merlin\Service\ContentFilterRepository;
	use OCA\Merlin\Service\Media\MediaHttpClient;
	require_once __DIR__ . '/../vendor/autoload.php';
	spl_autoload_register(static function (string $class): void {
		$prefix = 'OCA\\Merlin\\';
		if (str_starts_with($class, $prefix)) {
			$path = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($path)) {
				require_once $path;
			}
		}
	});

	/** Liefert die Bundle-Dateien aus content-filters/ ohne Datenbank. */
	final class BundleOnlyRepository extends ContentFilterRepository {
		public function __construct() {
		}

		public function getMerged(string $domain, ?string $userId = null): ?\SimpleXMLElement {
			$path = __DIR__ . '/../content-filters/' . $domain . '.xml';
			return is_file($path) ? simplexml_load_file($path) ?: null : null;
		}

		public function normalizeUrlDomain(string $url): string {
			return preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)) ?? '';
		}
	}

	/** Feste Antworten statt Netz; merkt sich die angefragten URLs. */
	final class StubHttpClient extends MediaHttpClient {
		/** @var list<string> */
		public array $requested = [];

		/** @param array<string, array<mixed>> $responses */
		public function __construct(private array $responses) {
		}

		public function getJson(string $url, array $extraHeaders = []): ?array {
			$this->requested[] = $url;
			return $this->responses[$url] ?? null;
		}
	}
}

namespace {

	use Merlin\InlineMediaTest\BundleOnlyRepository;
	use Merlin\InlineMediaTest\StubHttpClient;
	use OCA\Merlin\Service\ContentExtractorService;
	use OCA\Merlin\Service\ContentFilterValidator;
	use OCA\Merlin\Service\MastodonPostResolverService;
	use OCA\Merlin\Service\Media\InlineMediaService;
	use OCA\Merlin\Service\Media\MediaProviderRegistry;
	use OCA\Merlin\Service\Media\MediaResolverService;
	use OCA\Merlin\Service\Media\MediaResult;
	use OCA\Merlin\Service\Media\Provider\ArdMediathekProvider;
	use OCA\Merlin\Service\Media\Provider\ArteProvider;
	use OCA\Merlin\Service\Media\Provider\JsonLdMediaProvider;
	use OCA\Merlin\Service\Media\Provider\XPathMediaProvider;
	use OCA\Merlin\Service\Media\Provider\YoutubeEmbedProvider;
	use OCA\Merlin\Service\Media\Provider\ZdfProvider;
	use Psr\Log\NullLogger;

	$passed   = 0;
	$failures = [];
	$ok = static function (bool $condition, string $label, string $detail = '') use (&$passed, &$failures): void {
		if ($condition) {
			$passed++;
			echo "  \033[32m✓\033[0m " . $label . "\n";
			return;
		}
		$failures[] = $label . ($detail !== '' ? ' | ' . $detail : '');
		echo "  \033[31m✗ " . $label . "\033[0m\n";
		if ($detail !== '') {
			echo '      ' . str_replace("\n", "\n      ", $detail) . "\n";
		}
	};

	$articleUrl = 'https://www.rbb24.de/content/rbb/r24/wirtschaft/beitrag/2026/09/berlin-leere-bueros-wohnungen.html';
	$jsnUrl     = 'https://www.rbb24.de/wirtschaft/beitrag/2026/09/berlin-leere-bueros-wohnungen/_jcr_content/articlesContList/teaserbox_1594844559/teaserList/manualteaser.mediajsn.jsn/layout=standard.jsn';
	$posterUrl  = 'https://www.rbb24.de/content/dam/rbb/rbb/rbb24/2026/2026_09/sonstiges/Boulevard_Apartments1.jpg.jpg/quality=160/size=544x306.jpg';
	$mp4Best    = 'https://rbb-progressive.ard-mcdn.de/content/24/ae/24ae9b57-9dd1-409a-84aa-590d17db8d69/24ae9b57-9dd1-409a-84aa-590d17db8d69_9zu16-sm1280.mp4';

	// Medien-JSON wie von rbb24 ausgeliefert (gekürzt auf die genutzten Felder).
	$jsn = json_decode('{"_type":"video","_isLive":false,"_duration":89,"_mediaArray":[{"_plugin":1,"_mediaStreamArray":[
		{"_stream":"https://rbbvod.akamaized.net/i/content/24/ae/24ae9b57-9dd1-409a-84aa-590d17db8d69/24ae9b57-9dd1-409a-84aa-590d17db8d69_,9zu16-sm640,9zu16-sm480,9zu16-sm960,9zu16-sm1280,9zu16-sm1920,.mp4.csmil/master.m3u8","_quality":"auto"},
		{"_stream":"https://rbb-progressive.ard-mcdn.de/content/24/ae/24ae9b57-9dd1-409a-84aa-590d17db8d69/24ae9b57-9dd1-409a-84aa-590d17db8d69_9zu16-sm640.mp4","_quality":1},
		{"_stream":"https://rbb-progressive.ard-mcdn.de/content/24/ae/24ae9b57-9dd1-409a-84aa-590d17db8d69/24ae9b57-9dd1-409a-84aa-590d17db8d69_9zu16-sm960.mp4","_quality":1},
		{"_stream":"https://rbb-progressive.ard-mcdn.de/content/24/ae/24ae9b57-9dd1-409a-84aa-590d17db8d69/24ae9b57-9dd1-409a-84aa-590d17db8d69_9zu16-sm1280.mp4","_quality":2}
	]}]}', true, 512, JSON_THROW_ON_ERROR);

	// Player-Block aus dem Artikel (Player-Steuerung gekürzt).
	$playerConfig = htmlspecialchars(json_encode([
		'config' => 'https://www.rbb24.de/vorlagen/videoplayer/_jcr_content/properties/property_406262186.js.jsn',
		'media'  => $jsnUrl,
	], JSON_UNESCAPED_SLASHES), ENT_QUOTES);
	$videoBlock = '<div class="container__article-component container__article-component--small">
		<figure class="component-video hide-ardplayer-close" data-media-playing="false">
			<div><div class="ardplayer-close"><button class="button--black button" aria-label="Videoplayer Schließen"></button></div>
				<div class="ardplayer-container" data-player-manual="false" data-player-config="' . $playerConfig . '"><picture>
					<source srcset="/content/dam/rbb/rbb/rbb24/2026/2026_09/sonstiges/Boulevard_Apartments1.jpg.jpg/size=764x429.webp" type="image/webp" media="(max-width:768px)">
					<img src="/content/dam/rbb/rbb/rbb24/2026/2026_09/sonstiges/Boulevard_Apartments1.jpg.jpg/quality=160/size=544x306.jpg" width="544" height="306" alt="Außenansicht des Boulevard Berlin. (Quelle: rbb)" title="Außenansicht des Boulevard Berlin. (Quelle: rbb)" loading="lazy">
				</picture><div class="ardplayer ardplayer-show-posterframe ardplayer-state-inactive"><div class="ardplayer-posterbackdrop"><picture><img alt="ARD Player Poster" draggable="false"></picture></div>
					<div class="ardplayer-posterframe"><picture><img alt="Vollausgestattete Apartements in leerem Kaufhaus" src="data:image/png;base64,iVBORw0KGgo="></picture><span class="ardplayer-posterframe-title">Vollausgestattete Apartements in leerem Kaufhaus</span><div class="ardplayer-posterframe-chips"><span title="Dauer">1 Min</span></div></div></div></div>
				<figcaption class="label-sm font-light">
					<button class="video-teaser__play"><img src="/r24/images/play.svg" alt="Video abspielen" class="video-teaser__play-icon" loading="lazy"></button>
					<span class="roofline-sm">Berlin-Steglitz</span>
					<span class="headline-sm">Vollausgestattete Apartments im leeren Kaufhaus</span>
				</figcaption>
			</div>
		</figure>
		<div class="component-video-caption label-xxs font-light">Außenansicht des Boulevard Berlin. (Quelle: rbb)</div>
	</div>';

	$paragraph = static fn (int $n): string => '<p>Absatz ' . $n . ': In Berlin stehen zahlreiche Büroflächen leer, während Wohnungen dringend gesucht werden. '
		. 'Investoren und Bezirke diskutieren deshalb, wie leerstehende Gewerbeimmobilien zu Wohnraum umgebaut werden können, '
		. 'und welche baurechtlichen Hürden dabei zu überwinden sind. Fachleute sehen darin eine Chance für die Stadt.</p>';

	$page = static function (string $ogImage, string $body): string {
		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Leere Büros, fehlende Wohnungen | rbb24</title>'
			. '<meta property="og:title" content="Leere Büros, fehlende Wohnungen">'
			. '<meta property="og:image" content="' . $ogImage . '">'
			. '</head><body><main><article class="article"><h1 class="article__title">Leere Büros, fehlende Wohnungen</h1>'
			. '<div class="article__body">' . $body . '</div></article></main></body></html>';
	};

	$config = (new BundleOnlyRepository())->getMerged('rbb24.de');

	// ══════════════════════════════════════════════════════════════════════════
	echo "\n\033[1m1. rbb24.de.xml ist gültig\033[0m\n";

	$validator = (new ReflectionClass(ContentFilterValidator::class))->newInstanceWithoutConstructor();
	$validate  = new ReflectionMethod(ContentFilterValidator::class, 'validate');
	$errors    = $validate->getNumberOfParameters() > 0
		? $validate->invoke($validator, (string) file_get_contents(__DIR__ . '/../content-filters/rbb24.de.xml'), 'rbb24.de')
		: [];
	$ok(($errors['errors'] ?? $errors) === [], 'Validator meldet keine Fehler', var_export($errors, true));

	// ══════════════════════════════════════════════════════════════════════════
	echo "\n\033[1m2. InlineMediaService allein\033[0m\n";

	$http    = new StubHttpClient([$jsnUrl => $jsn]);
	$service = new InlineMediaService($http, new NullLogger());
	$ok($service->hasRules($config), 'rbb24.de hat eine <inline>-Regel');

	$replaced = $service->replace($page('https://www.rbb24.de/og.jpg', $paragraph(1) . $videoBlock . $paragraph(2)), $articleUrl, $config);
	$ok($http->requested === [$jsnUrl], 'Medien-JSON aus data-player-config geladen', var_export($http->requested, true));
	$ok(count($replaced['media']) === 1, 'ein Inline-Video gefunden');
	$result = $replaced['media'][0] ?? null;
	$ok($result?->delivery === MediaResult::DELIVERY_FILE && $result?->defaultUrl() === $mp4Best, 'beste mp4-Qualität (sm1280) als Datei', var_export($result?->toArray(), true));
	$ok(!str_contains($replaced['html'], 'component-video'), 'Player-Block und Bildzeile ersetzt');
	$ok(str_contains($replaced['html'], 'src="' . $posterUrl . '"'), 'Vorschaubild absolut übernommen');
	$ok(str_contains(html_entity_decode($replaced['html'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'Vollausgestattete Apartments im leeren Kaufhaus • Außenansicht des Boulevard Berlin. (Quelle: rbb)'), 'Bildunterschrift = Videotitel • Bildzeile');
	$ok(!str_contains($replaced['html'], 'data:image'), 'Platzhalter-Poster des Players nicht übernommen');

	$injected = $service->injectMarkers('<p>x</p><figure class="merlin-inline-media merlin-inline-media-content merlin-inline-media-ref-0"><img src="' . $posterUrl . '" alt=""><figcaption>c</figcaption></figure>', $replaced['media']);
	$ok(str_contains($injected, 'class="merlin-inline-media-source" data-media-kind="video" data-media-delivery="file" data-media-src="' . $mp4Best . '"'), 'Quellen-Marker eingesetzt', $injected);
	$ok(!str_contains($injected, 'merlin-inline-media-ref-'), 'Referenz-Klasse entfernt');
	$ok((bool) preg_match('#<img[^>]*><div class="merlin-inline-media-source".*</div><figcaption>#s', $injected), 'Marker zwischen Bild und figcaption');

	$foreign = new StubHttpClient([]);
	$evil    = str_replace(htmlspecialchars($jsnUrl, ENT_QUOTES), 'https://evil.example/x.jsn', $videoBlock);
	$evilRes = (new InlineMediaService($foreign, new NullLogger()))->replace($page('https://www.rbb24.de/og.jpg', $paragraph(1) . $evil), $articleUrl, $config);
	$ok($foreign->requested === [] && $evilRes['media'] === [], 'Medien-JSON von fremdem Host wird nicht geladen');

	$sameHero = MediaResult::single('video', 'file', str_replace('sm1280', 'sm960', $mp4Best));
	$ok($service->containsSameMedia($replaced['media'], $sameHero), 'andere Qualität desselben Videos gilt als gleiches Medium');
	$ok(!$service->containsSameMedia($replaced['media'], MediaResult::single('video', 'file', 'https://rbb-progressive.ard-mcdn.de/content/aa/bb/other/other_9zu16-sm960.mp4')), 'anderes Video gilt nicht als gleich');

	// ══════════════════════════════════════════════════════════════════════════
	echo "\n\033[1m3. Ganze Extraktion (Readability, Hero, Sanitizer)\033[0m\n";

	$extract = static function (string $html) use ($jsnUrl, $jsn, $articleUrl): array {
		$logger    = new NullLogger();
		$repo      = new BundleOnlyRepository();
		$extractor = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();
		$props     = [
			'logger'               => $logger,
			'contentFilters'       => $repo,
			'mastodonPostResolver' => new MastodonPostResolverService($logger),
			'mediaResolver'        => new MediaResolverService(new MediaProviderRegistry(
				new ArdMediathekProvider(new StubHttpClient([])),
				new ZdfProvider(new StubHttpClient([])),
				new ArteProvider(new StubHttpClient([])),
				new XPathMediaProvider(),
				new JsonLdMediaProvider(),
				new YoutubeEmbedProvider(),
			), $repo, $logger),
			'inlineMedia'          => new InlineMediaService(new StubHttpClient([$jsnUrl => $jsn]), $logger),
		];
		foreach ($props as $name => $value) {
			$prop = new ReflectionProperty(ContentExtractorService::class, $name);
			$prop->setValue($extractor, $value);
		}
		return (new ReflectionMethod(ContentExtractorService::class, 'processHtml'))->invoke($extractor, $articleUrl, $html, 'utf-8');
	};

	// a) Video mitten im Text, anderes og:image
	$a       = $extract($page('https://www.rbb24.de/content/dam/anderes-bild.jpg', $paragraph(1) . $paragraph(2) . $videoBlock . $paragraph(3) . $paragraph(4)));
	$content = (string) ($a['content'] ?? '');
	$ok(str_contains($content, 'merlin-inline-media-source') && str_contains($content, 'data-media-src="' . $mp4Best . '"'), 'a) Inline-Marker samt mp4 im gespeicherten Content', $content);
	// Die Quellenangabe setzt die bestehende Caption-Aufbereitung in <cite>.
	$ok(str_contains(strip_tags($content), 'Vollausgestattete Apartments im leeren Kaufhaus • Außenansicht des Boulevard Berlin. (Quelle: rbb)'), 'a) Bildunterschrift erhalten');
	$ok(substr_count($content, 'Außenansicht des Boulevard Berlin') === 2, 'a) Bildzeile nicht zusätzlich als loser Text (nur alt + figcaption)', $content);
	$ok(str_contains($content, 'merlin-hero-image'), 'a) Hero-Bild (anderes og:image) bleibt');
	$ok(strpos($content, 'Absatz 2') < strpos($content, 'merlin-inline-media-source') && strpos($content, 'merlin-inline-media-source') < strpos($content, 'Absatz 3'), 'a) Video steht an seiner Stelle im Text');
	$ok(!str_contains($content, 'class="merlin-media"'), 'a) kein Aufmacher-Marker erzeugt');
	$ok(MediaResolverService::parseMarker($content) === null, 'a) Inline-Marker wird nicht als Aufmacher-Medium gelesen');

	// b) Video als Aufmacher, og:image = dessen Standbild
	$b       = $extract($page($posterUrl, $videoBlock . $paragraph(1) . $paragraph(2) . $paragraph(3)));
	$content = (string) ($b['content'] ?? '');
	$ok(str_contains($content, 'merlin-inline-media-source'), 'b) Inline-Video am Artikelanfang bleibt erhalten', $content);
	$ok(!str_contains($content, 'merlin-hero-image'), 'b) kein zusätzliches Hero-Bild mit demselben Standbild');
	$ok(($b['imageUrl'] ?? $b['image'] ?? null) !== null, 'b) Vorschaubild für die Artikelliste bleibt gesetzt', var_export(array_keys($b), true));

	echo "\n" . str_repeat('─', 72) . "\n";
	$total = $passed + count($failures);
	if ($failures === []) {
		echo "\033[32mAlle " . $total . " Prüfungen bestanden.\033[0m\n";
		exit(0);
	}
	echo "\033[31m" . count($failures) . ' von ' . $total . " Prüfungen fehlgeschlagen:\033[0m\n";
	foreach ($failures as $failure) {
		echo '  · ' . $failure . "\n";
	}
	exit(1);
}
