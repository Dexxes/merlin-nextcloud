<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

// Load vendor autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use OCA\Merlin\Service\Http\SsrfSafeResolver;
use OCA\Merlin\Service\Login\PaywallLoginRequiredException;
use OCA\Merlin\Service\Media\InlineMediaService;
use OCA\Merlin\Service\Media\MediaResolverService;
use OCA\Merlin\Service\Media\MediaResult;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class ContentExtractorService {
	use SsrfSafeResolver;

	private LoggerInterface $logger;

	/** @var list<string>|null Gecachte Regex-Patterns aus url-shorteners.json */
	private ?array $shortenerPatterns = null;

	/**
	 * Maximale Anzahl an HTTP-Redirects, denen manuell gefolgt wird (fetchUrl()
	 * und followHttpRedirect()). CURLOPT_FOLLOWLOCATION wird bewusst NICHT genutzt,
	 * weil libcurl damit jedem Location-Header folgt, ohne dass wir die Ziel-IP vor
	 * dem Connect gegen private/reservierte Ranges prüfen könnten (SSRF-via-Redirect).
	 */
	private const MAX_REDIRECTS = 10;

	/**
	 * Obergrenze für den HTML-Body beim Abruf. Bricht der Download darüber ab,
	 * wird er verworfen (verhindert, dass eine riesige Antwort im Speicher landet).
	 * PDFs sind davon ausgenommen: sie werden gar nicht geladen, siehe
	 * buildPdfResult().
	 */
	private const MAX_BODY_BYTES = 20 * 1024 * 1024;

	/** Kategorie und Marker-Klasse für PDF-Artikel (nur URL, kein Dateiinhalt). */
	public const PDF_CATEGORY     = 'PDF';
	public const PDF_MARKER_CLASS = 'merlin-pdf';

	/**
	 * Header-Namen, die eine Domain-Config über <fetch> setzen darf (kleingeschrieben).
	 *
	 * Whitelist statt Blacklist, weil die XML-Dateien Konfigurationsdaten sind:
	 * Ein frei wählbarer Header-Name könnte sonst den Request umbiegen (Host),
	 * Zugangsdaten anhängen (Authorization) oder den Body-Parser verwirren
	 * (Content-Length, Transfer-Encoding).
	 *
	 * Die Liste steht in ContentFilterSchema, weil sie an zwei Stellen gilt: hier
	 * beim Abruf und im ContentFilterValidator, der einen unerlaubten Header schon
	 * beim Speichern in der Admin-UI ablehnt. Zwei Kopien würden auseinanderlaufen.
	 */
	private const FETCH_HEADER_WHITELIST = ContentFilterSchema::FETCH_HEADER_WHITELIST;

	/**
	 * Trennzeichen, das in Bildunterschriften jeden Zeilenumbruch ersetzt.
	 *
	 * Bildunterschriften sollen immer einlaufender Fließtext sein: Quellseiten
	 * packen dort gerne Titel, Copyright und Fotografennamen als eigene Blöcke
	 * bzw. per <br> untereinander, was im Reader unter dem Bild als mehrzeiliger
	 * Klotz landet. flattenCaptions() ersetzt jeden solchen Umbruch durch den
	 * Bullet – der Wortlaut bleibt erhalten, nur die Zeilenstruktur fällt weg.
	 */
	private const CAPTION_BULLET    = '•';
	private const CAPTION_SEPARATOR = ' ' . self::CAPTION_BULLET . ' ';

	/**
	 * Tags, die innerhalb einer <figcaption> einen sichtbaren Umbruch erzeugen.
	 *
	 * Bewusst nur Block-Elemente: Inline-Auszeichnung (<a>, <em>, <span>, …)
	 * bleibt unangetastet, weil sie ohnehin in derselben Zeile rendert.
	 */
	private const CAPTION_BLOCK_TAGS = [
		'p', 'div', 'section', 'article', 'header', 'footer', 'aside',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'ul', 'ol', 'li', 'dl', 'dt', 'dd',
		'blockquote', 'pre', 'figure', 'figcaption',
		'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption',
	];

	/**
	 * Obergrenze für sichtbaren Text in einem Absatz/einer Überschrift vor dem
	 * Hero-Bild, damit er noch als Byline/Datumszeile gilt und übersprungen
	 * wird (siehe stripLeadingImages()). Länger als das ist echter Fließtext
	 * und beendet die Suche nach weiteren Leitbildern.
	 */
	private const LEAD_IN_TEXT_MAX_LENGTH = 80;

	/**
	 * Obergrenze für die Anzahl an Geschwisterknoten, die stripLeadingImages()
	 * am Content-Anfang inspiziert, bevor abgebrochen wird - Schutz gegen
	 * pathologische Fälle (z. B. viele kurze Absätze in Folge). Seit
	 * isSkippableLeadIn() jeden kurzen Knoten unabhängig vom Tag überspringt
	 * (nicht mehr nur p/div), zehren reale Seiten mit viel kurzem UI-Rahmen
	 * vor dem Hero-Bild (leere Label-Liste, Social-Share-Icons, Meta-Zeilen, …)
	 * das Budget spürbar an - 20 statt der ursprünglichen 8 gibt genug
	 * Spielraum, ohne die Schutzfunktion aufzugeben.
	 */
	private const MAX_LEAD_IN_NODES = 20;

	private ContentFilterRepository $contentFilters;

	/**
	 * UID des Nutzers, in dessen Kontext gerade extrahiert wird – für die Dauer
	 * genau eines extract()/extractFromHtml()-Aufrufs, danach durch den
	 * nächsten Aufruf überschrieben.
	 *
	 * Warum ein Instanzfeld statt eines Parameters, der durch alle acht
	 * loadDomainConfig()-Aufrufstellen (Fetch-Header, Bilder, Zitate, Pre-/
	 * Post-Filter, Infoboxen, Klassenmarker, Metadaten) durchgereicht werden
	 * müsste: extract()/extractFromHtml() sind nicht reentrant (processHtml()
	 * ruft sich nie selbst auf), und der Service wird pro Request neu
	 * konstruiert – ein Datenleck zwischen Nutzern ist damit ausgeschlossen.
	 * loadDomainConfig() liest dieses Feld, statt dass jede private
	 * Applier-Methode einen zusätzlichen $userId-Parameter bekäme.
	 */
	private ?string $currentUserId = null;

	public function __construct(
		LoggerInterface $logger,
		ContentFilterRepository $contentFilters,
		private SiteCredentialService $siteCredentials,
		private BlueskyThreadResolverService $blueskyThreadResolver,
		private MastodonPostResolverService $mastodonPostResolver,
		private TikTokPostResolverService $tiktokPostResolver,
		private IURLGenerator $urlGenerator,
		private MediaResolverService $mediaResolver,
		private InlineMediaService $inlineMedia,
	) {
		$this->logger         = $logger;
		$this->contentFilters = $contentFilters;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Public API & Orchestration
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Extract article content from URL
	 *
	 * @param string $url
	 * @return array{
	 *   url: string,
	 *   title: string,
	 *   content: string,
	 *   excerpt: ?string,
	 *   author: ?string,
	 *   authorUrl: ?string,
	 *   authors: ?list<array{name: string, url: ?string}>,
	 *   siteName: ?string,
	 *   imageUrl: ?string,
	 *   siteIconUrl: ?string,
	 *   readingTime: int,
	 *   publishedAt: ?\DateTime,
	 *   category: ?string,
	 *   isPaywalled: bool,
	 *   paywallSubscribeUrl: ?string
	 * }
	 * @param ContentFilterTrace|null $trace Optionale Regel-Diagnose für den
	 *        Filter-Testlauf in den Admin-Einstellungen. Im Normalbetrieb null,
	 *        dann entsteht kein zusätzlicher Aufwand.
	 * @param string|null $userId UID des aufrufenden Nutzers, für die private
	 *        User-Custom-Ebene der Content-Filter (siehe $currentUserId). null
	 *        bei fehlendem Nutzerkontext – dann greift nur Bundle+Admin-Custom.
	 * @throws \Exception
	 */
	public function extract(string $url, ?ContentFilterTrace $trace = null, ?string $userId = null): array
	{
		$this->currentUserId = $userId;

		try {
			// Wirft UnsupportedSiteException, bevor überhaupt Netzwerk-Traffic zur
			// eigentlichen Zielseite entsteht (siehe assertUrlIsSupported()).
			$url = $this->assertUrlIsSupported($url);

			// PDF-Link: die Datei wird nie geladen oder gespeichert, der Artikel
			// besteht nur aus URL + Marker; die Clients laden die PDF selbst.
			if ($this->isPdfUrl($url))
			{
				return $this->buildPdfResult($url);
			}

			// Fetch HTML content from the network. httpRequestFollowingRedirects()
			// (aufgerufen über fetchUrl()) folgt jeder 3xx-Kette bereits vollständig,
			// unabhängig davon, ob der Host in resources/url-shorteners.json steht –
			// resolveRedirectUrl() deckt nur die Vorab-Auflösung ohne Body-Download ab.
			// $finalUrl ist daher die tatsächliche Artikel-URL nach ALLEN Redirects,
			// auch bei Shortenern/Trackern, die nicht auf der kuratierten Liste stehen.
			// Domain-Filter-Auswahl und die gespeicherte Artikel-URL müssen sich darauf
			// stützen, sonst greift bei unbekannten Shortenern die falsche (oder gar
			// keine) Content-Filter-Konfiguration.
			['body' => $rawHtml, 'httpCharset' => $httpCharset, 'finalUrl' => $finalUrl, 'isPdf' => $isPdf] = $this->fetchUrl($url);
			$url = $finalUrl;

			// Content-Type application/pdf trotz Nicht-.pdf-URL (Redirect,
			// Download-Skript): fetchUrl() hat den Transfer schon abgebrochen.
			if ($isPdf)
			{
				return $this->buildPdfResult($url);
			}

			$this->assertNotPaywalled($url, $rawHtml);

			return $this->processHtml($url, $rawHtml, $httpCharset, $trace);
		}
		catch (PaywallLoginRequiredException $e)
		{
			// Absichtlich VOR dem generischen \Exception-Catch unten: die
			// Exception muss unverändert bei ArticleController ankommen, das
			// daraus eine eindeutige "Login erforderlich"-API-Antwort baut
			// (siehe PLATFORMS.md) statt eines generischen Fehlschlags.
			throw $e;
		}
		catch (UnsupportedSiteException $e)
		{
			// Ebenso absichtlich vor dem generischen Catch: ArticleController/
			// ExtensionController übersetzen das in ein eindeutiges
			// unsupportedSiteDomain-Feld statt eines generischen Fehlschlags.
			throw $e;
		}
		catch (ParseException $e)
		{
			$this->logger->error('Failed to parse article: ' . $e->getMessage(), ['url' => $url]);
			throw new \Exception('Failed to extract article content: ' . $e->getMessage());
		} catch (\Exception $e) {
			$this->logger->error('Failed to fetch article: ' . $e->getMessage(), ['url' => $url]);
			throw new \Exception('Failed to fetch article: ' . $e->getMessage());
		}
	}

	/**
	 * Extract article content from pre-fetched HTML (e.g. sent by a browser extension).
	 * Skips the HTTP fetch step; the extraction pipeline is identical to extract().
	 *
	 * @return array{
	 *   url: string,
	 *   title: string,
	 *   content: string,
	 *   excerpt: ?string,
	 *   author: ?string,
	 *   authorUrl: ?string,
	 *   authors: ?list<array{name: string, url: ?string}>,
	 *   siteName: ?string,
	 *   imageUrl: ?string,
	 *   siteIconUrl: ?string,
	 *   readingTime: int,
	 *   publishedAt: ?\DateTime,
	 *   category: ?string,
	 *   isPaywalled: bool,
	 *   paywallSubscribeUrl: ?string
	 * }
	 * @param string|null $userId UID des aufrufenden Nutzers, siehe extract().
	 */
	public function extractFromHtml(string $url, string $html, ?string $userId = null): array
	{
		$this->currentUserId = $userId;

		try
		{
			if ($this->isPdfUrl($url))
			{
				return $this->buildPdfResult($url);
			}

			return $this->processHtml($url, $html);
		}
		catch (ParseException $e) 
		{
			$this->logger->error('Failed to parse article: ' . $e->getMessage(), ['url' => $url]);
			throw new \Exception('Failed to extract article content: ' . $e->getMessage());
		} 
		catch (\Exception $e) 
		{
			$this->logger->error('Failed to process article HTML: ' . $e->getMessage(), ['url' => $url]);
			throw new \Exception('Failed to process article HTML: ' . $e->getMessage());
		}
	}

	/**
	 * Run the full extraction pipeline on already-fetched HTML.
	 * Called by both extract() (after HTTP fetch) and extractFromHtml() (browser-sent HTML).
	 *
	 * @param string      $url         Artikel-URL (für relative Link-Auflösung und Domain-Config)
	 * @param string      $rawHtml     Roher HTML-Body
	 * @param string|null $httpCharset Charset aus dem HTTP-Content-Type-Header (Vorrang vor <meta>)
	 */
	private function processHtml(
		string $url,
		string $rawHtml,
		?string $httpCharset = null,
		?ContentFilterTrace $trace = null
	): array
	{
		// Normalise domain (strips www.) for config-file lookup
		$domain = $this->normalizeDomain($url);

		// Icon der konkreten Seite (Support-Infobox) - aus dem rohen HTML, bevor
		// Pre-Filter/Readability das <head> anfassen.
		$siteIconUrl = $this->extractSiteIconUrl($rawHtml, $url);

		// ── Step 0: Encoding normalisation ───────────────────────────────────
		// Seiten mit iso-8859-1 oder anderen Nicht-UTF-8-Encodings erzeugen
		// Mojibake, wenn DOMDocument oder html_entity_decode fälschlicherweise
		// UTF-8 annehmen. Deshalb: Encoding ermitteln und das Dokument vor
		// allen weiteren Schritten nach UTF-8 konvertieren.
		//
		// Priorität nach RFC 7231 §3.1.1.5:
		//   1. HTTP-Content-Type-Header  (fetchUrl() liefert $httpCharset)
		//   2. <meta>-Tag im HTML-Body   (detectHtmlEncoding())
		$detectedEncoding = $httpCharset ?? $this->detectHtmlEncoding($rawHtml);
		if ($detectedEncoding !== null) {
			$this->logger->debug('ContentExtractor: encoding source', [
				'source'   => $httpCharset !== null ? 'HTTP-Header' : 'meta-tag',
				'encoding' => $detectedEncoding,
			]);
		}
		if ($detectedEncoding !== null
			&& !in_array($detectedEncoding, ['utf-8', 'utf8'], true)
		) {
			$converted = mb_convert_encoding($rawHtml, 'UTF-8', $detectedEncoding);
			if ($converted !== false && $converted !== '') {
				$rawHtml = $converted;

				// Nach der Konvertierung ist die ursprüngliche Encoding-Deklaration
				// falsch – DOMDocument oder nachgelagerte Parser würden sonst wieder
				// das alte Encoding annehmen und die nun korrekt kodierten Bytes
				// fehlinterpretieren. Deshalb charset-Angaben aus beiden Meta-Formen
				// auf "utf-8" umschreiben.
				//
				// Form 1: <meta http-equiv="Content-Type" content="…; charset=iso-8859-1">
				$rawHtml = preg_replace(
					'/(;\s*charset=)[^\s;"\'>\\/]+/i',
					'${1}utf-8',
					$rawHtml
				) ?? $rawHtml;
				// Form 2: <meta charset="iso-8859-1"> (HTML5-Kurzform)
				$rawHtml = preg_replace(
					'/(<meta[^>]+charset=["\']?)[^\s;"\'>\\/]+/i',
					'${1}utf-8',
					$rawHtml
				) ?? $rawHtml;

				$this->logger->info('ContentExtractor: converted HTML encoding', [
					'from' => $detectedEncoding,
					'to'   => 'UTF-8',
				]);
			}
		}

		// ── Step 2: Domain metadata extraction ───────────────────────────────
		// Must run on the ORIGINAL HTML before any pre-filter stripping,
		// because applyRemoveRules() removes all <script> tags — which would
		// destroy JSON-LD and other embedded JSON sources before they can be read.
		$domainMeta = $this->extractDomainMetadata($rawHtml, $domain, $trace, $url);
		// extractDomainMetadata() setzt category nur, wenn eine gefunden wurde –
		// die Prüfungen unten (Medien, Mastodon, Readability-Zweig) erwarten
		// aber einen vorhandenen Schlüssel.
		$domainMeta['category'] ??= null;

		// ── Step 2a: Medien (Audio/Video) ────────────────────────────────────
		// Quelle und Beschreibung kommen deklarativ aus der <media>-Sektion
		// der Domain-Config, die senderspezifische Logik steckt in den
		// Providern unter Service/Media/ (siehe MediaResolverService). Läuft
		// auf dem UNVERÄNDERTEN $rawHtml, vor dem Script/Style-Strip (Step
		// 2d): JSON-LD und Next.js-Payloads stehen in <script>-Tags.
		//
		// $mediaDetailParagraphs: Beschreibung reiner Medienseiten (Kategorie
		// Video/Audio), die dort Readability überspringen und sonst nur den
		// Medien-Marker als Content hätten. Bewusst VOR der Excerpt-Kürzung
		// direkt darunter erfasst: die Beschreibung soll ungekürzt in den
		// Content wandern.
		$domainConfig          = $this->loadDomainConfig($domain);
		$media                 = $this->mediaResolver->resolveOnSave($url, $domainConfig, $rawHtml);
		$mediaDetailParagraphs = [];
		if ($this->isMediaCategory($domainMeta['category'])) {
			$mediaDescription = $this->extractMediaDescription($rawHtml, $domainConfig, $domain, $trace);
			if ($mediaDescription['teaser'] !== null) {
				$domainMeta['excerpt'] = $mediaDescription['teaser'];
			}
			$mediaDetailParagraphs = $mediaDescription['paragraphs'];
		}
		// Videolänge für die Lesezeit: vor dem Script-Strip (Step 2d) aus dem
		// unveränderten HTML lesen, JSON-LD/Player-Payloads stehen in <script>.
		$mediaDurationMinutes = $this->extractMediaDurationMinutes($rawHtml);

		//When the excerpt is too long, short it
		if(isset($domainMeta) && key_exists("excerpt", $domainMeta) && strlen($domainMeta['excerpt']) > 300)
			$domainMeta['excerpt'] = substr($domainMeta['excerpt'],0,300) . "...";

		// ── Step 2b: Mastodon-Erkennung (domain-unabhängig) ───────────────────
		// Mastodon ist föderiert - anders als bsky.app/x.com gibt es keine feste
		// Domain, für die ein content-filters/{domain}.xml eine Kategorie
		// deklarieren könnte. Erkennung deshalb rein über die URL-Form
		// "/@user/12345…" (looksLikeMastodonPostUrl()), NUR wenn keine andere
		// Domain-Config bereits eine eigene Kategorie zugewiesen hat (sonst
		// hätte z. B. ein regulärer Blog mit zufällig passendem Pfad Vorrang
		// vor seinem eigenen Content-Filter). $mastodonThreadPosts wird unten
		// im Thread-Zweig wiederverwendet statt den API-Call zu wiederholen.
		$mastodonThreadPosts = null;
		if ($domainMeta['category'] === null && $this->mastodonPostResolver->looksLikeMastodonPostUrl($url)) {
			$mastodonThreadPosts = $this->mastodonPostResolver->resolveSelfThread($url);
			if ($mastodonThreadPosts !== null) {
				$domainMeta['category'] = 'Mastodon';
			}
		}

		// Textartikel mit gefundenem Medium (z. B. Deutschlandfunk-Beitrag mit
		// Audio): eigene Kategorie "Mixed", der Text läuft trotzdem normal
		// durch Readability. Erst NACH der Mastodon-Erkennung, die auf
		// category === null prüft. Eine fest deklarierte <category> (Video,
		// Audio, …) hat Vorrang.
		if ($media !== null && $domainMeta['category'] === null) {
			$domainMeta['category'] = ContentFilterSchema::MIXED_CATEGORY;
		}

		// ── Step 2c: Generische Paywall-Erkennung ────────────────────────────
		// Muss vor dem Pre-Filter laufen: ein Bundle-<remove> entfernt
		// Paywall-Overlays typischerweise genau dort, wo der <paywall><marker>
		// sie erkennen soll.
		$paywall = $this->detectPaywall($rawHtml, $domain);

		// ── Step 2d: Script/style removal (früh, VOR jedem DOM-Roundtrip) ────
		// stripScriptAndStyleTags() arbeitet linear per strpos()/stripos() auf
		// dem rohen String, ohne den String selbst je zu parsen - sie kann ein
		// <script> daher nicht mit fremdem Markup verwechseln. Ein
		// DOMDocument::loadHTML()-Roundtrip (normalizeImageCaptions(),
		// applyPreFilters() & Co., alle unten) kann das dagegen sehr wohl: PHP
		// libxml ist kein spec-treuer HTML5-Parser und behandelt <template>
		// nicht als inerten DocumentFragment, sondern wie ein normales
		// Container-Element (verbreitet auf Alpine.js-Seiten wie spiegel.de,
		// siehe content-filters/spiegel.de.xml). Bei genug solcher <template>-
		// Elemente vor einem großen <script>-Block gerät libxmls interner
		// Parser-Zustand durcheinander und splittet den Script-Inhalt an einer
		// Stelle, an der im Original gar kein </script> stand - der Rest des
		// echten JS-Codes (mit < / > als Vergleichsoperatoren) rutscht als
		// gewöhnlicher Text in den Baum und wird von Readability als
		// Artikeltext aufgegriffen. Scripts/Styles VOR dem ersten
		// DOM-Roundtrip zu entfernen umgeht den Bug, statt ihn zu reparieren.
		$rawHtml = $this->stripScriptAndStyleTags($rawHtml);

		// ── Step 2e: Inline-Videos ───────────────────────────────────────────
		// Videos mitten im Text (<media><inline>, z. B. der ARD-Player bei
		// rbb24.de) werden VOR der Caption-Normalisierung und den Pre-Filtern
		// durch eine Figure mit Vorschaubild ersetzt, sonst bliebe vom Player
		// nur das Posterbild als scheinbar gewöhnliches Foto übrig. Die
		// Quellen-Marker setzt Step 11b ein, siehe InlineMediaService.
		$inlineMedia = [];
		if ($this->usesReadability($domainMeta['category']) && $this->inlineMedia->hasRules($domainConfig)) {
			try {
				$inline      = $this->inlineMedia->replace($rawHtml, $url, $domainConfig);
				$rawHtml     = $inline['html'];
				$inlineMedia = $inline['media'];
				// Dasselbe Video zusätzlich als Aufmacher (JSON-LD) liefe sonst
				// doppelt: oben als Hero-Player und im Text als Inline-Player.
				if ($media !== null && $media['result'] !== null && $this->inlineMedia->containsSameMedia($inlineMedia, $media['result'])) {
					$media = null;
				}
			} catch (\Throwable $e) {
				$this->logger->info('Inline-Medien nicht auflösbar', ['url' => $url, 'exception' => $e]);
			}
		}

		// ── Step 3: Image caption normalisation ─────────────────────────────
		// Rewrap domain-specific image+caption structures into standard
		// <figure><img><figcaption> HTML so Readability preserves them.
		// Must run before Readability; affects all images in the article body.
		if ($this->usesReadability($domainMeta['category']))
			$rawHtml = $this->normalizeImageCaptions($rawHtml, $domain, $trace);

		// ── Step 4: Pre-filter ────────────────────────────────────────────────
		// Apply per-domain <pre-filter> remove rules BEFORE Readability sees
		// the HTML, so filtered elements are never considered as article content.
		$rawHtml = $this->applyPreFilters($rawHtml, $domain, $trace);
		// ENT_NOQUOTES statt ENT_QUOTES: saveHTML() re-encodiert Attributwerte
		// korrekt (z. B. &quot;/&#34; für ein eingebettetes literales Anführungs-
		// zeichen). ENT_QUOTES decodierte diese Quote-Entities aber wieder in
		// literale " / ' zurück — auf dem rohen String, ohne erneute DOM-
		// Serialisierung. Bei Attributen, die selbst JSON/JS mit Anführungs-
		// zeichen tragen (z. B. Alpine.js' x-data="{…&#34;key&#34;…}", verbreitet
		// u. a. bei spiegel.de), riss das die Attributgrenze mittendrin auf: der
		// Rest des Attributwerts (oft ein ganzer Script-Block) rutschte als
		// kaputtes Markup/Textinhalt in den Baum und konnte von Readability als
		// Artikeltext ausgewählt werden. ENT_NOQUOTES decodiert weiterhin named/
		// numeric Entities in sichtbarem Text (Umlaute, &amp; …), lässt aber
		// Quote-Entities unangetastet, sodass Attributwerte gültig bleiben.
		$rawHtml = html_entity_decode($rawHtml, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

		// ── Step 5: Infobox markers ──────────────────────────────────────────
		// Add 'merlin-infobox' CSS class to elements declared as <infobox> in
		// the domain config. Must run BEFORE Readability so the class survives.
		$rawHtml = $this->applyInfoboxMarkers($rawHtml, $domain, $trace);
		$rawHtml = html_entity_decode($rawHtml, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

		// ── Step 6: Custom class markers ────────────────────────────────────
		// Add arbitrary CSS classes to elements declared as <saveElements> in
		// the domain config. Must run BEFORE Readability so the classes survive.
		$rawHtml = $this->applyClassMarkers($rawHtml, $domain, $trace);
		$rawHtml = html_entity_decode($rawHtml, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

		// Nur von Step 5 (domainMeta-Override) oder dem Video-Zweig unten gesetzt,
		// wenn kein og:description/domain-Excerpt vorhanden ist — explizit auf
		// null initialisiert, damit stripDuplicateMetadata()/der Rückgabewert
		// unten keine "undefined variable"-Warnung auslösen.
		$excerpt = null;

		// Der Video-Zweig unten überspringt Readability komplett und hat daher nie
		// $siteName gesetzt (undefined variable → null im Rückgabewert). Da
		// PublicArticleView.vue/ArticleReader.vue den URL-Link an
		// "article.siteName && safeArticleUrl" knüpfen, fehlte für Video-Domains
		// (z. B. ardmediathek.de) die komplette URL-Anzeige in den Metadaten.
		// extractSiteName() wertet ohnehin nur den Host aus $url aus, ist also in
		// beiden Zweigen identisch berechenbar – deshalb hier vorab setzen.
		$siteName = $this->extractSiteName($rawHtml, $url);
		$siteName = html_entity_decode($siteName ?? '', ENT_QUOTES, 'UTF-8');

		if ($this->usesReadability($domainMeta['category']))
		{
			// ── Step 7: hr-Schutz + Quote normalisation + Readability ───────────────
			// fivefilters/readability.php's isElementWithoutContent() treats <hr>
			// like <br>: an element whose only children are <hr>/<br> and that has
			// no text of its own counts as "without content" and gets discarded
			// during grabArticle()'s cleanup - which silently swallows a bare <hr>
			// scene-break marker whenever the source wraps it in an otherwise empty
			// <div>/<section>/<header> (a common pattern, e.g. WordPress themes'
			// "<div class="separator"><hr/></div>"). protectHorizontalRules() must
			// therefore run BEFORE Readability; restoreHorizontalRules() below
			// converts the surviving placeholders back once Readability is done.
			$readabilityInput = $this->protectHorizontalRules($rawHtml);

			// Normalise quote structures before Readability:
			//   1. Domain-specific <quotes> rules from the content-filter XML
			//   2. Standard <blockquote> elements → merlin-quote class
			//   3. <q> elements → merlin-quote-inline class
			// keepClasses=true (below) ensures Readability does NOT strip class attributes,
			// so all Merlin marker classes survive post-processing.
			$html = $this->normalizeQuotes($readabilityInput, $domain, $trace);

			// fivefilters/readability.php löst "./bild.jpg"-relative Bild-URLs im
			// Artikeltext über PHP_URL_PATH + dirname() auf (Readability.php,
			// getPathInfo()). Endet der Artikel-Pfad auf "/" — Standard bei
			// Ghost-CMS-Blogs wie blog.joinmastodon.org (".../post-slug/") —,
			// entfernt dirname() fälschlich das letzte Pfadsegment, sodass alle
			// Absatzbilder auf eine falsche, 404ende URL aufgelöst werden (das
			// meist root-relative og:image bleibt davon unbetroffen). Ein
			// synthetisches Pfadsegment vor der Übergabe an Readability umgeht den
			// Bug, ohne die Bibliothek zu patchen; Query/Fragment sind für die
			// Pfadauflösung dort irrelevant und werden bewusst weggelassen.
			$readabilityUrl = $url;
			$urlPath = parse_url($url, PHP_URL_PATH) ?? '';
			if ($urlPath !== '' && substr($urlPath, -1) === '/') {
				$readabilityUrl = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . rtrim($urlPath, '/') . '/index.html';
			}

			$readabilityConfig = new Configuration([
				'fixRelativeURLs' => true,
				'originalURL' => $readabilityUrl,
				'summonCthulhu' => true, // Remove unlikely candidates
				// Keep class attributes so that Merlin-specific marker classes (e.g.
				// merlin-infobox, merlin-quote) added before parsing survive intact.
				'keepClasses'   => true,
			]);

			$readability = new Readability($readabilityConfig);

			// If the quote-transform corrupted the HTML, fall back to the original
			try
			{
				// Debug-Dump des Pre-Readability-HTML, auskommentiert: schrieb bei
				// JEDEM Extract-Aufruf eine Datei (unbedingter Hot-Path-I/O). Bei
				// Bedarf für gezieltes Debugging wieder einkommentieren.
				//$path = __DIR__ . "/../../test/preReadability.html";
				//file_put_contents($path, $html);
				$html = str_replace('<?xml encoding="utf-8" ?>', '', $html);
				$readability->parse($html);
			}
			catch (ParseException $e) {
				$this->logger->warning('normalizeQuotes output rejected by Readability, retrying with raw HTML', ['url' => $url]);
				$readability = new Readability($readabilityConfig);
				$readability->parse($readabilityInput);
			}

			// ── Step 8: Collect Readability results ───────────────────────────────
			$title = $readability->getTitle() ?: $this->extractTitleFromHtml($html);
			$title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');

			$content = $readability->getContent() ?: '';
			// ENT_NOQUOTES statt ENT_QUOTES: $content ist weiterhin HTML-Markup
			// (Readability liefert einen HTML-Fragment-String, keinen reinen Text),
			// das anschließend durch applyPostFilters()/sanitizeHtml() erneut per
			// DOM geparst wird. ENT_QUOTES decodierte &quot;/&#34; in Attributen
			// (z. B. <blockquote data-instgrm-permalink="…&#34;…">, oder Reste
			// von Widget-Markup, das Readability unverändert übernommen hat)
			// zurück in literale Anführungszeichen und riss damit dieselbe
			// Attributgrenze auf wie bei den Pre-Readability-Decode-Aufrufen oben
			// (siehe dortiger Kommentar) — mit demselben Resultat: der Rest des
			// Attributwerts rutschte als kaputtes Markup/Text in den extrahierten
			// Content. ENT_NOQUOTES decodiert weiterhin named/numeric Entities in
			// sichtbarem Text, lässt Quote-Entities aber unangetastet.
			$content = html_entity_decode($content, ENT_NOQUOTES, 'UTF-8');
			// Placeholders from protectHorizontalRules() zurück in echte <hr> auflösen.
			$content = $this->restoreHorizontalRules($content);

			//$excerpt = $readability->getExcerpt();
			//$excerpt = html_entity_decode($excerpt, ENT_QUOTES, 'UTF-8');

			$author = $readability->getAuthor() ?: '';
			$author = html_entity_decode($author, ENT_QUOTES, 'UTF-8');

			// Prefer og:image (most reliable), fall back to Readability's detected image,
			// then scan raw HTML for a prominent hero figure (rescued before Readability drops it).
			// $heroImageData wird in Step 7b für den Post-Inject mit Caption verwendet.
			$heroImageData = $this->extractHeroImageFromHtml($rawHtml, $url);
			$imageUrl      = $this->extractOgImage($html)
				?: ($readability->getImage() ?: null)
				?: ($heroImageData['src'] ?? null);
			$publishedAt = $this->extractPublishedDate($html, $content);
		}
		elseif ($domainMeta['category'] === "Thread") {
			// Self-Thread-Zweig (bsky.app, siehe BlueskyThreadResolverService):
			// Readability wird übersprungen (bsky.app liefert als SPA praktisch
			// keinen Server-Side-Content). Titel zunächst aus dem og:title-
			// Fallback der bsky.app.xml (domainMeta, aus Step 2 oben)
			// vorbelegen - das greift, wenn die API-Auflösung unten
			// fehlschlägt. Kein Excerpt: der Post-Text steht schon
			// vollständig im Embed selbst. Statt eines Avatars/Fotos dient
			// das Bluesky-Icon (platformIconUrl()) als Vorschaubild - kein
			// Hero-Bild im Content selbst, siehe Step 12 unten
			// (hideHeroImage-Ausnahme für Thread/XPost/Mastodon).
			$title       = $domainMeta['title'] ?? '';
			$author      = null;
			$imageUrl    = $this->platformIconUrl('bluesky');
			$publishedAt = null;

			$threadPosts = $this->blueskyThreadResolver->resolveSelfThread($url);
			if ($threadPosts !== null && $threadPosts !== []) {
				$content   = $this->buildBlueskyThreadHtml($threadPosts);
				$firstPost = $threadPosts[0];

				$author = $firstPost['authorDisplayName'] ?: ($firstPost['authorHandle'] ?: null);
				$title  = $author !== null ? ('Post von ' . $author) : $title;

				$publishedAt = $firstPost['createdAt'] !== '' ? $this->parseDateString($firstPost['createdAt']) : null;

				// Gilt für den ganzen Self-Thread (ältester Post) - nicht von
				// Step 9 unten mit dem og:title der einzelnen VERLINKTEN
				// Post-Seite überschreiben lassen, die bei einem mehrteiligen
				// Thread nicht zum Autor des Threads passen muss.
				$domainMeta['title'] = $title;
			} else {
				// API-Auflösung fehlgeschlagen (gelöschter Post, Rate-Limit,
				// Netzwerkfehler) - einfacher Link-Fallback statt leerem Artikel.
				// Titel bleibt der og:title-Fallback von oben.
				$escapedBlueskyUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
				$content = '<a href="' . $escapedBlueskyUrl . '" class="merlin-bluesky-fallback-link">Zum Bluesky-Post</a>';
				if ($title === '') {
					$title = 'Bluesky-Post';
				}
			}
			$domainMeta['image'] = $imageUrl;
		}
		elseif ($domainMeta['category'] === "XPost") {
			// Einzelpost-Embed (x.com/twitter.com, siehe content-filters/x.com.xml
			// bzw. twitter.com.xml): kein API-Aufruf nötig/möglich - X hat keine
			// kostenlose öffentliche API mehr, mit der sich eine Reply-Kette
			// auflösen ließe. platform.twitter.com/widgets.js holt den
			// Tweet-Inhalt clientseitig selbst über Twitters eigenes oEmbed -
			// die Widget-Infrastruktur (Allowlist/CSP) existierte hier schon
			// vor der Bluesky-Arbeit. Deshalb auch kein Self-Thread-Walk wie
			// bei Bluesky/Mastodon, nur der einzelne verlinkte Post. Vorschaubild
			// ist das X-Icon statt eines Avatars/Fotos, siehe Thread-Zweig oben.
			$xHandle = $this->parseXStatusHandle($url);
			if ($xHandle !== null) {
				$content = $this->buildXPostHtml($url);
				$author  = '@' . $xHandle;
				$title   = 'Post von ' . $author;
			} else {
				// Keine Status-URL (Profil/Suche/Startseite) - einfacher
				// Link-Fallback statt eines falsch dargestellten Embeds.
				$escapedXUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
				$content = '<a href="' . $escapedXUrl . '" class="merlin-x-fallback-link">Zum X-Post</a>';
				$author  = null;
				$title   = $domainMeta['title'] ?? 'X-Post';
			}
			$imageUrl    = $this->platformIconUrl('x');
			$publishedAt = null;
			$domainMeta['title'] = $title;
			$domainMeta['image'] = $imageUrl;
		}
		elseif ($domainMeta['category'] === "InstagramPost") {
			// Einzelpost-Embed (instagram.com, siehe content-filters/instagram.com.xml):
			// wie bei X kein API-Aufruf nötig/möglich - Instagram hat keine
			// kostenlose öffentliche API, mit der sich der Post-Inhalt auflösen
			// ließe. www.instagram.com/embed.js holt den Post-Inhalt clientseitig
			// selbst über Instagrams eigenes oEmbed - Allowlist/CSP dafür
			// existierten schon vor diesem Kategorie-Zweig (Instagram-Embeds
			// INNERHALB fremder Artikel). Deshalb auch kein Self-Thread-Walk wie
			// bei Bluesky/Mastodon, nur der einzelne verlinkte Post. Vorschaubild
			// ist das Instagram-Icon statt eines Avatars/Fotos, siehe X-Zweig oben.
			//
			// Titel bewusst immer der feste String statt eines og:title-Scrapes:
			// Instagrams Permalink-URL enthält (anders als bei X/TikTok) keinen
			// Handle, es gibt keine kostenlose API für den echten Autorennamen -
			// ein gescraptes og:title wäre bestenfalls eine rohe, unformatierte
			// Caption statt eines sauberen "Post von {Ersteller}"-Titels wie bei
			// den anderen Plattformen.
			$title  = 'Instagram-Post';
			$author = null;
			$instagramPermalink = $this->parseInstagramPermalink($url);
			if ($instagramPermalink !== null) {
				$content = $this->buildInstagramPostHtml($instagramPermalink);
			} else {
				// Keine Post-/Reel-/TV-URL (Profil/Explore/Startseite) - einfacher
				// Link-Fallback statt eines falsch dargestellten Embeds.
				$escapedInstagramUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
				$content = '<a href="' . $escapedInstagramUrl . '" class="merlin-instagram-fallback-link">Zum Instagram-Post</a>';
			}
			$imageUrl    = $this->platformIconUrl('instagram');
			$publishedAt = null;
			$domainMeta['title'] = $title;
			$domainMeta['image'] = $imageUrl;
		}
		elseif ($domainMeta['category'] === "TikTokPost") {
			// Einzelpost-Embed (tiktok.com, siehe content-filters/tiktok.com.xml
			// bzw. TikTokPostResolverService): anders als bei X/Instagram (rein
			// clientseitiges Widget aus einem selbst gebauten, leeren
			// <blockquote>) liest TikToks embed.js die Video-ID beim Rendern
			// AUSSCHLIESSLICH aus dem data-video-id-Attribut - ein minimal
			// selbst gebautes Markup führte in der Praxis zu einem fehlerhaften
			// Embed ("/embed/v2/null"). Deshalb hier ein echter Server-Aufruf
			// gegen TikToks öffentliche, unauthentifizierte oEmbed-API, die das
			// komplette, von TikTok selbst generierte Embed-Markup liefert
			// (siehe TikTokPostResolverService). Vorschaubild ist trotzdem das
			// TikTok-Icon statt eines Video-Thumbnails, siehe X-Zweig oben.
			$tiktokVideoId  = $this->parseTikTokVideoId($url);
			$tiktokResolved = $tiktokVideoId !== null ? $this->tiktokPostResolver->resolve($url) : null;
			if ($tiktokResolved !== null) {
				$content = $tiktokResolved['html'];
				$author  = $tiktokResolved['authorName']
					?? ($tiktokResolved['authorUniqueId'] !== null ? '@' . $tiktokResolved['authorUniqueId'] : null);
				$title   = $author !== null ? ('Post von ' . $author) : ($domainMeta['title'] ?? 'TikTok-Post');
			} else {
				// Keine Video-URL (Profil/Discover/Startseite) oder
				// oEmbed-Auflösung fehlgeschlagen (gelöschtes/privates Video,
				// Rate-Limit, Netzwerkfehler) - einfacher Link-Fallback statt
				// eines fehlerhaften Embeds.
				$escapedTikTokUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
				$content = '<a href="' . $escapedTikTokUrl . '" class="merlin-tiktok-fallback-link">Zum TikTok-Post</a>';
				$author  = null;
				$title   = $domainMeta['title'] ?? 'TikTok-Post';
			}
			$imageUrl    = $this->platformIconUrl('tiktok');
			$publishedAt = null;
			$domainMeta['title'] = $title;
			$domainMeta['image'] = $imageUrl;
		}
		elseif ($domainMeta['category'] === "Mastodon") {
			// Self-Thread-Zweig für föderierte Mastodon-Posts (siehe
			// MastodonPostResolverService, domain-unabhängig oben in Step 2b
			// erkannt) - $mastodonThreadPosts wurde dort schon aufgelöst,
			// kein zweiter API-Call nötig. Anders als bsky.app/x.com gibt es
			// keinen zentralen Embed-Host für alle Instanzen, deshalb eigene,
			// native HTML-Karte statt eines Drittanbieter-Widgets (siehe
			// buildMastodonThreadHtml()). Vorschaubild ist das Mastodon-Icon
			// statt eines Avatars/Medien-Anhangs - Avatare innerhalb der
			// Post-Karte selbst bleiben aber (Teil der Post-Darstellung).
			$content   = $this->buildMastodonThreadHtml($mastodonThreadPosts);
			$firstPost = $mastodonThreadPosts[0];

			$author = $firstPost['authorDisplayName'] ?: ($firstPost['authorHandle'] !== '' ? '@' . $firstPost['authorHandle'] : null);
			$title  = $author !== null ? ('Post von ' . $author) : 'Mastodon-Post';

			$imageUrl    = $this->platformIconUrl('mastodon');
			$publishedAt = $firstPost['createdAt'] !== '' ? $this->parseDateString($firstPost['createdAt']) : null;

			$domainMeta['title'] = $title;
			$domainMeta['image'] = $imageUrl;
		}
		else {
			// Reine Medienseite (Video/Audio): kein Readability. Der Content
			// ist die Beschreibung (siehe Step 2a); der Medien-Marker samt
			// Fallback-Link wird unten nach dem Cleanup vorangestellt (Step 11b).
			// Titel/Autor/Bild/Datum kommen ausschliesslich aus den Domain-
			// Metadaten (Step 9) – hier nur initialisieren (Titel wie beim
			// Platzhalter-Artikel mit dem Host), sonst bricht eine Seite ohne
			// og:title (z. B. YouTubes Consent-Seite) die Extraktion in
			// stripDuplicateMetadata() ab.
			$content     = '';
			$title       = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
			$author      = null;
			$imageUrl    = null;
			$publishedAt = null;
			foreach ($mediaDetailParagraphs as $paragraph) {
				// Zeilenumbrüche im Absatz (z. B. "Host: …\nAutor: …") sichtbar
				// halten - als rohes \n würde der Browser sie zu Leerzeichen machen.
				$content .= '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8'), false) . '</p>';
			}
		}

		// ── Step 9: Apply domain metadata overrides ───────────────────────────
		if (!empty($domainMeta['title']))     { $title     = $domainMeta['title']; }
		if (!empty($domainMeta['author']))    { $author    = $domainMeta['author']; }
		// Profil-Link des Autors (siehe extractDomainMetadata()): nur
		// übernehmen, wenn es überhaupt einen Autorennamen gibt, an dem der
		// Link im Reader hängen kann - ein Link ohne Namen wäre unsichtbar.
		$authorUrl = (!empty($domainMeta['authorUrl']) && $author !== null && $author !== ''
				&& $this->countAuthors($author) === 1)
			? $domainMeta['authorUrl']
			: null;
		// Name + Profil-Link je Autor. Nur übernehmen, wenn die Namen auch
		// tatsächlich die angezeigten sind (nicht z. B. von einer API-Antwort
		// in den Thread-/Mastodon-Zweigen überschrieben).
		$authors = null;
		if (!empty($domainMeta['authors']) && $author === ($domainMeta['author'] ?? null)) {
			$authors = $domainMeta['authors'];
		} elseif ($authorUrl !== null) {
			$authors = [['name' => $author, 'url' => $authorUrl]];
		}
		if (!empty($domainMeta['excerpt']))   { $excerpt   = $domainMeta['excerpt']; }
		if (!empty($domainMeta['image']))     { $imageUrl  = $domainMeta['image']; }
		if (!empty($domainMeta['published'])) { $publishedAt = $this->parseDateString($domainMeta['published']); }

		// ── Step 10: Post-filter ───────────────────────────────────────────────
		// Apply per-domain <post-filter> remove rules to the Readability content.
		$content = $this->applyPostFilters($content, $domain, $trace);

		// ── Step 11: Cleanup pipeline ──────────────────────────────────────────
		$wordCount   = str_word_count(strip_tags($content));
		$readingTime = max(1, (int) ceil($wordCount / 200));
		// Reine Video-/Audioseiten haben kaum Text: dort ist die Medienlänge die Lesezeit.
		if ($mediaDurationMinutes !== null && $this->isMediaCategory($domainMeta['category'])) {
			$readingTime = $mediaDurationMinutes;
		}

		// "Mixed" nur, wenn neben dem Medium auch wirklich Text da ist: Seiten
		// wie Deutschlandfunk-Kommentare bestehen oft nur aus Audio plus
		// Autorenzeile - die sind in der Liste bei "Audio" richtiger
		// aufgehoben als bei den Seiten.
		if ($media !== null
			&& $domainMeta['category'] === ContentFilterSchema::MIXED_CATEGORY
			&& $wordCount < self::MIXED_MIN_WORDS) {
			$domainMeta['category'] = $media['kind'] === MediaResult::KIND_AUDIO ? 'Audio' : 'Video';
		}

		$content = $this->cleanHtml($content);

		$normalizedImageUrl = $imageUrl ? $this->normalizeUrl($imageUrl, $url) : null;

		// ── Step 11b: Medien-Marker ───────────────────────────────────────────
		// Zuerst die Quellen der Inline-Videos (Step 2e) in deren Figures,
		// dann der Marker des Aufmacher-Mediums.
		// Nach cleanHtml(), damit dessen Aufräumen die data-media-*-Attribute
		// nicht anfasst; vor Step 12, damit das Hero-Bild davor landet. Der
		// Marker trägt bei stabilen Quellen (Datei/Embed) die URL selbst, bei
		// Mediathek-Streams nur die Medienart – der Reader löst sie dann über
		// GET /api/articles/{id}/media auf (siehe MediaResolverService).
		// Medienseiten ohne deklarierte Quelle (z. B. vimeo.com) bekommen
		// weiterhin nur den Fallback-Link, wie vor Einführung von <media>.
		$content = $this->inlineMedia->injectMarkers($content, $inlineMedia);
		if ($media !== null) {
			$content = MediaResolverService::buildMarkerHtml($media['kind'], $media['result'], $url) . $content;
		} elseif ($this->isMediaCategory($domainMeta['category'])) {
			$isAudio = $domainMeta['category'] === 'Audio';
			$content = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="'
				. MediaResolverService::FALLBACK_LINK_CLASS
				. ($isAudio ? '' : ' ' . MediaResolverService::LEGACY_VIDEO_FALLBACK_CLASS) . '">'
				. ($isAudio ? 'Zum Audio' : 'Zum Video') . '</a>' . $content;
		}

		// ── Step 12: Hero-Image in Content einfügen ────────────────────────────────────────
		// Alle Bilder/Figures am Content-Anfang werden bis zum ersten
		// substantiellen Absatz bedingungslos entfernt (stripLeadingImages()) -
		// unabhängig davon, ob eines davon zufällig zur ermittelten imageUrl
		// passt. Das Hero-Bild wird danach immer als eigenes
		// merlin-hero-image-Figure vorangestellt. Dadurch hängt die
		// Duplikat-Vermeidung nicht mehr an einem URL-Ähnlichkeitsabgleich (der
		// für jede neue CDN-/Resize-URL-Form erneut nachgepflegt werden müsste,
		// siehe imagesMatchForDedup()) - der Abgleich entscheidet nur noch
		// darüber, welche der entfernten Captions zum Hero-Bild passt.
		// Bluesky/X/Mastodon: $imageUrl ist hier das feste Plattform-Icon
		// (Vorschaubild in der Artikelliste, siehe platformIconUrl()), soll
		// aber ausdrücklich NICHT zusätzlich als Hero-Bild im Content
		// erscheinen - der Post/Thread/die Karte steht selbst schon ganz
		// oben im Content.
		$suppressHeroImage = in_array($domainMeta['category'], ['Thread', 'XPost', 'Mastodon', 'InstagramPost', 'TikTokPost'], true);

		if ($normalizedImageUrl !== null && !$suppressHeroImage) {
			['content' => $content, 'images' => $leadImages] = $this->stripLeadingImages($content, $url);

			$heroCaption = null;
			foreach ($leadImages as $leadImage) {
				if ($leadImage['caption'] !== null && $this->imagesMatchForDedup($leadImage['src'], $normalizedImageUrl)) {
					$heroCaption = $leadImage['caption'];
					break;
				}
			}
			// Fallback auf den Rohscan (heroImageData) - aber nur, wenn dessen Bild
			// tatsächlich zur gewählten imageUrl passt. Vorher wurde die Caption hier
			// unconditional übernommen, obwohl heroImageData bei einer per og:image
			// ermittelten imageUrl von einer ganz anderen Figure stammen kann.
			if ($heroCaption === null && !empty($heroImageData['caption']) && !empty($heroImageData['src'])
				&& $this->imagesMatchForDedup($this->normalizeUrl($heroImageData['src'], $url), $normalizedImageUrl)) {
				$heroCaption = $heroImageData['caption'];
			}
			// Zweiter Rohscan-Fallback: extractHeroImageFromHtml() liefert die
			// ERSTE <figure> im Dokument - steht davor eine andere (z. B.
			// netzpolitik.org: Autoren-Avatar im Block-Theme-Header vor dem
			// Beitragsbild), passt deren Bild nicht und die Caption der
			// eigentlichen Hero-Figure (von Readability verworfen) ginge verloren.
			// Deshalb gezielt nach der <figure> suchen, deren Bild zur gewählten
			// imageUrl passt.
			if ($heroCaption === null) {
				$heroCaption = $this->findFigcaptionForImage($rawHtml, $normalizedImageUrl, $url);
			}

			// Ist das Hero-Bild zugleich das Vorschaubild eines Inline-Videos
			// (rbb24: Aufmacher ist ein ARD-Player, og:image dessen Standbild),
			// übernimmt das Video die Rolle des Hero-Bilds - sonst stünde
			// dasselbe Bild doppelt da, einmal als vermeintliches Foto.
			$heroIsInlineVideo = $this->inlineMediaPosterMatches($content, $normalizedImageUrl, $url);

			// Zweite, gezielte Dedup-Runde gegen die tatsächlich gewählte
			// Hero-Bild-URL - auch für Bilder, die stripLeadingImages() oben
			// bewusst stehen ließ, weil sie nicht am Content-Anfang stehen
			// (z. B. fsfe.org: Intro-Absatz vor der og:image-identischen
			// Feature-Grafik). Siehe removeDuplicateHeroImage()-Docblock.
			$content = $this->removeDuplicateHeroImage($content, $normalizedImageUrl, $url);

			if (!$heroIsInlineVideo) {
				$escapedUrl = htmlspecialchars($normalizedImageUrl, ENT_QUOTES, 'UTF-8');
				$figcaption = $heroCaption !== null
					? '<figcaption>' . htmlspecialchars($heroCaption, ENT_QUOTES, 'UTF-8') . '</figcaption>'
					: '';
				$content = '<figure class="merlin-hero-image"><img src="' . $escapedUrl . '" alt="">' . $figcaption . '</figure>' . $content;
			}
		}

		$content = $this->stripDuplicateMetadata($content, $title, $excerpt);

		// ── Step 13: HTML-Sanitizing (XSS-Schutz) ──────────────────────────────
		// Letzter Schritt vor der Rückgabe: Der Inhalt wird im Web-Reader und in
		// der öffentlichen Share-Ansicht per v-html gerendert. cleanHtml() und die
		// Readability-Pipeline entfernen zwar <script>/<style>, aber KEINE
		// Event-Handler-Attribute (onerror, onload, …), javascript:-URLs oder
		// gefährliche Tags (<iframe>, <object>, <form>). sanitizeHtml() schließt
		// diese Lücke serverseitig per DOM-Allowlist, statt die XSS-Abwehr allein
		// der Content-Security-Policy zu überlassen (Defense-in-Depth).
		$content = $this->sanitizeHtml($content);

		// ── Step 14: Paywall-Artikel ohne Login-Möglichkeit ─────────────────────
		// Ohne gültiges Abo ist $content bestenfalls ein Teaser, im schlimmsten
		// Fall Reste des Paywall-Overlays selbst, die Readability trotz Pre-
		// Filter als "Artikel" durchgewunken hat - beides Datenmüll, den kein
		// Client sinnvoll anzeigen kann. Wird daher NICHT in die DB
		// geschrieben; der Client zeigt stattdessen den in Article::isPaywalled
		// transportierten Hinweis mit den Optionen Abo/Archivieren.
		if ($paywall['isPaywalled']) {
			$content     = '';
			$readingTime = 0;
		}

		return [
			'url'                 => $url,
			'title'               => $title,
			'content'             => $content,
			'excerpt'             => $excerpt,
			'author'              => $author,
			'authorUrl'           => $authorUrl,
			'authors'             => $authors,
			'siteName'            => $siteName,
			'imageUrl'            => $normalizedImageUrl,
			'siteIconUrl'         => $siteIconUrl,
			'readingTime'         => $readingTime,
			'publishedAt'         => $publishedAt,
			'category'            => $domainMeta['category'],
			'isPaywalled'         => $paywall['isPaywalled'],
			'paywallSubscribeUrl' => $paywall['subscribeUrl'],
		];
	}

	/**
	 * Baut den Artikel-Content für einen Bluesky-Self-Thread: ein
	 * Blueskys-offizielles Embed-<blockquote data-bluesky-uri="…"> je Post
	 * (in chronologischer Reihenfolge), gefolgt vom offiziellen Loader-Script.
	 * embed.bsky.app ersetzt jedes [data-bluesky-uri]-Element client-seitig
	 * durch ein <iframe> mit dem echten, live gerenderten Post - der
	 * Blockquote-Inhalt hier ist nur der No-JS-Fallback-Text.
	 *
	 * @param list<array{uri: string, cid: string, text: string, authorDid: string,
	 *   authorHandle: string, authorDisplayName: ?string, authorAvatar: ?string,
	 *   createdAt: string, imageUrl: ?string}> $posts
	 */
	private function buildBlueskyThreadHtml(array $posts): string {
		$blocks = [];
		foreach ($posts as $post) {
			$escapedUri  = htmlspecialchars($post['uri'], ENT_QUOTES, 'UTF-8');
			$escapedText = nl2br(htmlspecialchars($post['text'], ENT_QUOTES, 'UTF-8'));

			$blocks[] = '<blockquote class="bluesky-embed" data-bluesky-uri="' . $escapedUri . '">'
				. '<p>' . $escapedText . '</p>'
				. '</blockquote>';
		}
		$blocks[] = '<script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>';

		return implode("\n", $blocks);
	}

	/**
	 * Handle aus einer x.com/twitter.com-Status-URL ("/handle/status/12345…"),
	 * oder null wenn die URL keine Tweet-Permalink-Form hat (Profil, Suche,
	 * Startseite, …). "/i/status/…" (Xs handle-loser Permalink-Kurzlink, z. B.
	 * über "Copy link") liefert bewusst null zurück statt "i" als Handle -
	 * "i" ist ein Platzhalter, kein Konto.
	 */
	private function parseXStatusHandle(string $url): ?string {
		$path = parse_url($url, PHP_URL_PATH);
		if (!is_string($path) || !preg_match('#^/([A-Za-z0-9_]{1,15})/status/\d+#', $path, $m)) {
			return null;
		}
		return strcasecmp($m[1], 'i') === 0 ? null : $m[1];
	}

	/**
	 * Blueskys Gegenstück, nur für X: ein offizielles Tweet-Embed-<blockquote>
	 * (leerer <a href> genügt - platform.twitter.com/widgets.js holt sich den
	 * Tweet-Inhalt selbst über Twitters eigenes oEmbed) + der Loader.
	 */
	private function buildXPostHtml(string $url): string {
		$escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
		return '<blockquote class="twitter-tweet"><a href="' . $escapedUrl . '"></a></blockquote>' . "\n"
			. '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';
	}

	/**
	 * Kanonischer Permalink ("https://www.instagram.com/{p|reel|tv}/{shortcode}/")
	 * aus einer instagram.com-URL, oder null wenn die URL keine Post-/Reel-/
	 * TV-Permalink-Form hat (Profil, Explore, Startseite, …). Baut den
	 * Permalink bewusst neu aus Typ+Shortcode statt die Original-URL
	 * wiederzuverwenden, damit Tracking-Query-Parameter (z. B.
	 * "?igsh=…"/"?utm_source=…") nicht ungefiltert ins data-instgrm-permalink-
	 * Attribut wandern.
	 */
	private function parseInstagramPermalink(string $url): ?string {
		$path = parse_url($url, PHP_URL_PATH);
		if (!is_string($path) || !preg_match('#^/(p|reel|tv)/([A-Za-z0-9_-]+)#', $path, $m)) {
			return null;
		}
		return 'https://www.instagram.com/' . $m[1] . '/' . $m[2] . '/';
	}

	/**
	 * X'/Blueskys Gegenstück, nur für Instagram: ein offizielles Post-Embed-
	 * <blockquote data-instgrm-permalink="…"> (leer genügt - www.instagram.com/
	 * embed.js holt sich den Post-Inhalt selbst über Instagrams eigenes
	 * oEmbed) + der Loader. Dieselbe Markup-Form (Klasse "instagram-media",
	 * data-instgrm-permalink/-version) wird schon für Instagram-Embeds
	 * INNERHALB fremder Artikel durchgelassen, siehe
	 * isAllowedInstagramPermalink()/sanitizeHtml().
	 */
	private function buildInstagramPostHtml(string $permalinkUrl): string {
		$escapedUrl = htmlspecialchars($permalinkUrl, ENT_QUOTES, 'UTF-8');
		return '<blockquote class="instagram-media" data-instgrm-permalink="' . $escapedUrl . '" data-instgrm-version="14"></blockquote>' . "\n"
			. '<script async src="https://www.instagram.com/embed.js" charset="utf-8"></script>';
	}

	/**
	 * true, wenn Artikel dieser Kategorie durch Readability laufen. Nicht für
	 * reine Medienseiten (Video/Audio, siehe ContentFilterSchema::
	 * MEDIA_CATEGORIES) und Social-Posts – die bauen ihren Content selbst.
	 * "Mixed" (Textartikel mit Medium) läuft bewusst normal durch.
	 */
	private function usesReadability(?string $category): bool {
		return !$this->isMediaCategory($category)
			&& !in_array($category, ['Thread', 'XPost', 'Mastodon', 'InstagramPost', 'TikTokPost'], true);
	}

	/**
	 * Länge des Mediums in Minuten (aufgerundet, min. 1) aus dem Roh-HTML, oder
	 * null wenn keine Dauer gefunden wurde. Quellen: Meta-Tags (og:video:duration,
	 * video:duration, itemprop=duration → Sekunden bzw. ISO 8601), JSON-LD/
	 * Microdata "duration" (ISO 8601, z. B. PT1H2M3S; auch als in Next.js-Strings
	 * escapte Variante \"duration\":\"PT3596S\" wie bei Arte) YouTubes
	 * "lengthSeconds" und das ARD-Mediathek-Seiten-JSON ("duration" in Sekunden).
	 */
	private function extractMediaDurationMinutes(string $html): ?int {
		$seconds = null;

		if (preg_match('/<meta[^>]+(?:property|name|itemprop)=["\'](?:og:video:duration|og:audio:duration|video:duration|duration)["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)
			|| preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name|itemprop)=["\'](?:og:video:duration|og:audio:duration|video:duration|duration)["\']/i', $html, $m)
			|| preg_match('/\\\\?"duration\\\\?"\s*:\s*\\\\?"(P[^"\\\\]+)/', $html, $m)
			|| preg_match('/"lengthSeconds"\s*:\s*"?(\d+)"?/', $html, $m)
			// ARD Mediathek: Sekunden im Seiten-JSON; Trailer/Extras (EXTRA_*) ignorieren.
			|| preg_match('/"coreAssetType"\s*:\s*"(?!EXTRA_)[A-Z_]+"\s*,\s*"duration"\s*:\s*(\d+)/', $html, $m)) {
			$value = trim($m[1]);
			if (ctype_digit($value)) {
				$seconds = (int) $value;
			} elseif (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/i', $value, $p)) {
				$seconds = (int) ($p[1] ?? 0) * 86400
					+ (int) ($p[2] ?? 0) * 3600
					+ (int) ($p[3] ?? 0) * 60
					+ (int) ceil((float) ($p[4] ?? 0));
			}
		}

		return $seconds !== null && $seconds > 0 ? max(1, (int) ceil($seconds / 60)) : null;
	}

	/** Reine Medienseite (Video/Audio)? */
	private function isMediaCategory(?string $category): bool {
		return in_array($category, ContentFilterSchema::MEDIA_CATEGORIES, true);
	}

	/**
	 * Beschreibung einer reinen Medienseite: zuerst die <media><description>-
	 * Regeln der Domain-Config (XPath oder JSON-Pfad wie bei <metadata>, erste
	 * nicht-leere gewinnt; Absätze an Leerzeilen getrennt), sonst ein Provider
	 * mit eigener Logik (DescriptionProviderInterface, z. B. zdf.de, dessen
	 * Beschreibung nur im Next.js-Payload steht).
	 *
	 * teaser ersetzt – falls gesetzt – das Excerpt; nur Provider liefern ihn,
	 * weil eine deklarative Regel dafür bereits <metadata><excerpt> hat.
	 *
	 * @return array{teaser: ?string, paragraphs: list<string>}
	 */
	private function extractMediaDescription(string $rawHtml, ?\SimpleXMLElement $config, string $domain, ?ContentFilterTrace $trace): array {
		$rules = [];
		if ($config !== null && isset($config->media)) {
			foreach ($config->media->description as $rule) {
				$rules[] = $rule;
			}
		}

		if ($rules === []) {
			return $this->mediaResolver->providerDescription($config, $rawHtml)
				?? ['teaser' => null, 'paragraphs' => []];
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->loadHTML('<?xml version="1.0" encoding="UTF-8"?>' . $rawHtml, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xpath       = new \DOMXPath($dom);
		$jsonSources = null;

		$text = '';
		foreach ($rules as $rule) {
			$expr = trim((string) ($rule['xpath'] ?? ''));
			$json = trim((string) ($rule['json'] ?? ''));
			if ($expr !== '') {
				$nodes = @$xpath->query($expr);
				$trace?->record('media', $rule, $nodes === false ? 0 : $nodes->length, $nodes === false ? 'Ungültiger XPath-Ausdruck' : null);
				foreach ($nodes ?: [] as $node) {
					$text = trim($node instanceof \DOMElement ? $node->textContent : (string) $node->nodeValue);
					if ($text !== '') {
						break;
					}
				}
			} elseif ($json !== '') {
				$jsonSources ??= $this->extractJsonSources($rawHtml, $config, $domain, null);
				[$sourceId, $path] = (!str_starts_with($json, '$') && str_contains($json, ':'))
					? array_map('trim', explode(':', $json, 2))
					: ['default', $json];
				$resolved = isset($jsonSources[$sourceId]) ? $this->resolveJsonPath($jsonSources[$sourceId], $path) : null;
				// JSON-LD-Strings tragen oft HTML-Entities (ardsounds.de:
				// "The Fame&quot;"); unten escapt htmlspecialchars() erneut.
				$text     = is_string($resolved) ? trim(html_entity_decode($resolved, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
				$trace?->record('media', $rule, $text !== '' ? 1 : 0);
			}
			if ($text !== '') {
				break;
			}
		}

		// Absätze an Leerzeilen trennen, auch bei Windows-Zeilenenden und
		// Leerzeichen am Zeilenende ("…P3.  \r\n\r\nHost: …"). Einfache
		// Zeilenumbrüche bleiben im Absatz und werden beim Rendern zu <br>.
		$text       = str_replace(["\r\n", "\r"], "\n", $text);
		$paragraphs = [];
		foreach (preg_split('/\n[ \t]*\n\s*/', $text) ?: [] as $paragraph) {
			$lines     = array_filter(array_map('trim', explode("\n", $paragraph)), static fn (string $line): bool => $line !== '');
			$paragraph = implode("\n", $lines);
			if ($paragraph !== '') {
				$paragraphs[] = $paragraph;
			}
		}
		return ['teaser' => null, 'paragraphs' => $paragraphs];
	}

	/**
	 * Numerische Video-ID aus einer tiktok.com-Video-URL
	 * ("/@handle/video/1234567890…"), oder null wenn die URL keine
	 * Video-Permalink-Form hat (Profil, Discover, Startseite, …). Dient dem
	 * TikTokPost-Zweig als billiges Vorab-Filter, bevor überhaupt ein
	 * oEmbed-Aufruf (TikTokPostResolverService) versucht wird - die ID selbst
	 * wird nicht weiterverwendet, TikToks oEmbed-API bekommt die volle URL.
	 */
	private function parseTikTokVideoId(string $url): ?string {
		$path = parse_url($url, PHP_URL_PATH);
		if (!is_string($path) || !preg_match('#/video/(\d+)#', $path, $m)) {
			return null;
		}
		return $m[1];
	}

	/**
	 * Baut den Artikel-Content für einen Mastodon-Self-Thread als eigene,
	 * native HTML-Karte je Post (kein Drittanbieter-Widget - Mastodon-
	 * Instanzen sind föderiert, es gibt keinen zentralen, allowlistbaren
	 * Embed-Host wie embed.bsky.app/platform.twitter.com). contentHtml kommt
	 * von der Mastodon-API und ist bereits einfaches HTML (Absätze, Mention-/
	 * Hashtag-Links, ggf. Custom-Emoji-<img>s) - läuft wie jeder andere
	 * extrahierte Content anschließend durch applyPostFilters()/cleanHtml()/
	 * sanitizeHtml(), wird also nicht blind vertraut.
	 *
	 * @param list<array{id: string, url: string, contentHtml: string,
	 *   authorDisplayName: ?string, authorHandle: string, authorAvatar: ?string,
	 *   createdAt: string, imageUrls: list<string>}> $posts
	 */
	private function buildMastodonThreadHtml(array $posts): string {
		$blocks = [];
		foreach ($posts as $post) {
			$displayName   = $post['authorDisplayName'] ?: $post['authorHandle'];
			$escapedName   = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
			$escapedHandle = htmlspecialchars($post['authorHandle'], ENT_QUOTES, 'UTF-8');
			$escapedUrl    = htmlspecialchars($post['url'], ENT_QUOTES, 'UTF-8');

			$avatarHtml = '';
			if ($post['authorAvatar'] !== null) {
				$escapedAvatar = htmlspecialchars($post['authorAvatar'], ENT_QUOTES, 'UTF-8');
				$avatarHtml = '<img class="merlin-mastodon-post__avatar" src="' . $escapedAvatar . '" alt="">';
			}

			$mediaHtml = '';
			foreach ($post['imageUrls'] as $mediaUrl) {
				$escapedMedia = htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8');
				$mediaHtml .= '<img class="merlin-mastodon-post__media-item" src="' . $escapedMedia . '" alt="">';
			}
			if ($mediaHtml !== '') {
				$mediaHtml = '<div class="merlin-mastodon-post__media">' . $mediaHtml . '</div>';
			}

			$blocks[] = '<div class="merlin-mastodon-post">'
				. '<a class="merlin-mastodon-post__header" href="' . $escapedUrl . '">'
				. $avatarHtml
				. '<span class="merlin-mastodon-post__author">'
				. '<span class="merlin-mastodon-post__name">' . $escapedName . '</span>'
				. '<span class="merlin-mastodon-post__handle">@' . $escapedHandle . '</span>'
				. '</span>'
				. '</a>'
				. '<div class="merlin-mastodon-post__content">' . $post['contentHtml'] . '</div>'
				. $mediaHtml
				. '</div>';
		}

		return implode("\n", $blocks);
	}

	/**
	 * URL des statischen Plattform-Icons (16:9-PNG, transparenter
	 * Hintergrund, unter img/{platform}-preview.png), das für Bluesky-/X-/
	 * Mastodon-Artikel statt eines Avatars/Post-Fotos als Vorschaubild
	 * dient (siehe Thread-/XPost-/Mastodon-Zweige oben).
	 *
	 * getAbsoluteURL() ist hier Pflicht, nicht Kür: imagePath() allein
	 * liefert einen host-relativen Pfad (z. B. "/index.php/apps/merlin/
	 * img/…") - im Web-Reader per v-html/<img> unproblematisch (der
	 * Browser löst ihn gegen die aktuelle Origin auf), aber iOS/Android
	 * laden $imageUrl über einen eigenständigen Netzwerk-Request
	 * (URLSession/Coil), der eine vollständige URL mit Schema+Host
	 * braucht - ein relativer Pfad schlägt dort still fehl und die Cards
	 * zeigen nur den lokalen Platzhalter. Anders als beim no-img.png-
	 * Fallback in ArticleController::resolveImageUrl() (dort geliefert,
	 * wenn $imageUrl bereits leer ist, wird das clientseitig gar nicht
	 * erst über die Bild-Pipeline geladen) ist dieser Wert hier ein
	 * "echtes" imageUrl, das denselben Weg wie ein von einer Drittseite
	 * gescraptes og:image-Bild nimmt - und die kommen immer schon absolut.
	 */
	private function platformIconUrl(string $platform): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('merlin', $platform . '-preview.png')
		);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// URL Resolution & Redirects
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Löst Redirect-Wrapper auf (siehe resolveRedirectUrl()) und prüft die
	 * Ziel-Domain gegen content-filters/$unsupported.xml, OHNE die Zielseite
	 * selbst abzurufen.
	 *
	 * Public, damit ExtensionController::add() das schon VOR dem Anlegen des
	 * Platzhalter-Artikels aufrufen kann: eine bekannt unscrapbare Domain wird
	 * so synchron mit der API-Antwort abgelehnt (HTTP 422) statt erst Sekunden
	 * später im Hintergrund zu scheitern - notwendig für Firefox/Thunderbird,
	 * die den asynchronen Extraktions-Ausgang sonst NIE sehen (kein Polling,
	 * siehe saveToMerlin() in background.js: "Content extraction runs
	 * asynchronously; no need to wait for it here") und ohne diesen Vorab-Check
	 * immer einen Erfolg anzeigen würden, obwohl der Artikel nie Inhalt bekommt.
	 *
	 * ArticleController::create() (Web/iOS/Android) macht diesen Vorab-Check
	 * bewusst NICHT: dort pollen die Clients ohnehin auf den Artikel und zeigen
	 * unsupportedSiteDomain, sobald extract() es asynchron setzt (siehe
	 * scheduleExtraction()) - ein zusätzlicher synchroner Fehlschlag würde dort
	 * nur eine zweite, abweichende Fehlerbehandlung nötig machen.
	 *
	 * @throws UnsupportedSiteException
	 */
	public function assertUrlIsSupported(string $url): string {
		$resolved = $this->resolveRedirectUrl($url);
		$domain   = $this->contentFilters->normalizeUrlDomain($resolved);
		if ($domain !== '' && $this->contentFilters->isUnsupportedDomain($domain)) {
			throw new UnsupportedSiteException($domain);
		}
		return $resolved;
	}

	/**
	 * Resolve common redirect/tracking URLs to their real target.
	 *
	 * Zwei Strategien:
	 *   1. Query-Parameter-Extraktion  – kein Netzwerk-Roundtrip nötig
	 *      · google.com/url?url=<target>  (Google Alerts, Google News, …)
	 *      · google.com/url?q=<target>    (älteres Google-Format)
	 *
	 *   2. HTTP-3xx-Folgen via HEAD-Request  – für reine Shortener ohne Payload
	 *      · dlvr.it    · bit.ly      · tinyurl.com  · t.co (Twitter/X)
	 *      · ow.ly      · buff.ly     · ift.tt        · fb.me
	 *      · wp.me      · rebrand.ly  · short.gy      · cutt.ly
	 *      · is.gd      · feeds.feedburner.com
	 */
	private function resolveRedirectUrl(string $url): string {
		$parsed = parse_url($url);
		if (empty($parsed['host'])) {
			return $url;
		}

		$host = strtolower($parsed['host']);

		// Strategie 1: Query-Parameter-Extraktion (kein Netzwerk-Roundtrip)
		// google.com/url?url=... or google.com/url?q=...
		if (preg_match('/(?:^|\.)google\.[a-z]{2,}$/', $host)) {
			if (!empty($parsed['query'])) {
				parse_str($parsed['query'], $params);
				$target = $params['url'] ?? $params['q'] ?? null;
				if ($target && filter_var($target, FILTER_VALIDATE_URL)) {
					$this->logger->info('Resolved Google redirect URL', [
						'from' => $url,
						'to'   => $target,
					]);
					return $target;
				}
			}
		}

		// Strategie 2: HTTP-3xx-Kette folgen (HEAD-Request, kein Body-Download)
		// Die Liste der bekannten Shortener kommt aus resources/url-shorteners.json –
		// dort können neue Einträge ergänzt werden, ohne PHP-Code anzufassen.
		foreach ($this->loadShortenerPatterns() as $pattern) {
			if (preg_match($pattern, $host)) {
				// followHttpRedirect() validiert jeden Hop gegen private/reservierte
				// IP-Ranges und wirft bei Verstoß eine Exception. Statt die gesamte
				// Extraktion abzubrechen, fallen wir hier auf die unaufgelöste
				// Shortener-URL zurück – fetchUrl() prüft sie beim eigentlichen
				// Abruf ohnehin erneut mit derselben SSRF-Guard-Logik.
				try {
					$resolved = $this->followHttpRedirect($url);
				} catch (\Exception $e) {
					$this->logger->warning('URL-Shortener-Auflösung abgelehnt oder fehlgeschlagen', [
						'url'   => $url,
						'error' => $e->getMessage(),
					]);
					return $url;
				}
				if ($resolved !== $url) {
					$this->logger->info('Resolved URL shortener redirect', [
						'from' => $url,
						'to'   => $resolved,
					]);
				}
				return $resolved;
			}
		}

		return $url;
	}

	/**
	 * Lädt die Shortener-Host-Liste aus resources/url-shorteners.json und wandelt
	 * jeden Hostnamen in ein Regex-Pattern um. Das Ergebnis wird gecacht, damit die
	 * Datei pro Request nur einmal gelesen wird.
	 *
	 * @return list<string>
	 */
	private function loadShortenerPatterns(): array {
		if ($this->shortenerPatterns !== null) {
			return $this->shortenerPatterns;
		}

		$file = __DIR__ . '/../../resources/url-shorteners.json';
		$json = @file_get_contents($file);
		if ($json === false) {
			$this->logger->warning('url-shorteners.json nicht gefunden', ['path' => $file]);
			return $this->shortenerPatterns = [];
		}

		$entries = json_decode($json, true);
		if (!is_array($entries)) {
			$this->logger->warning('url-shorteners.json ist kein gültiges JSON-Array');
			return $this->shortenerPatterns = [];
		}

		$this->shortenerPatterns = array_map(
			// Hostnamen in ein vollständig anchored Regex übersetzen.
			// Punkte escapen, damit "bitXly" nicht matcht.
			static fn(array $entry): string =>
				'/(?:^|\.)' . preg_quote($entry['host'], '/') . '$/',
			array_filter($entries, static fn($e) => is_array($e) && isset($e['host']))
		);

		return $this->shortenerPatterns;
	}

	/**
	 * Entfernt alle Bilder/Figures am Content-Anfang bis zum ersten
	 * substantiellen Absatz und liefert sie (Quelle + Caption) zurück, damit
	 * Step 12 daraus die passende Caption fürs Hero-Bild übernehmen kann.
	 *
	 * Ersetzt die frühere contentStartsWithMatchingImage()-Heuristik ("nur
	 * einfügen, wenn noch kein passendes Bild da ist"): statt zu erraten, ob
	 * ein vorhandenes Bild zum Hero-Bild passt, werden alle Leitbilder
	 * bedingungslos entfernt - das Hero-Bild wird danach immer separat
	 * eingefügt (siehe Step 12). Ein Ähnlichkeits-Fehltreffer kostet dadurch
	 * höchstens eine fehlende Caption statt eines sichtbar doppelten Bilds.
	 *
	 * Die eigentliche Suche läuft in scanForLeadingImages().
	 *
	 * @return array{content: string, images: list<array{src: string, caption: ?string}>}
	 */
	private function stripLeadingImages(string $content, string $baseUrl): array {
		$prevLibxmlErrors = libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $content . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prevLibxmlErrors);

		$body = $dom->getElementsByTagName('body')->item(0);
		if ($body === null) {
			return ['content' => $content, 'images' => []];
		}

		$images    = [];
		$inspected = 0;
		$this->scanForLeadingImages($body, $baseUrl, $images, $inspected);

		$out = '';
		foreach ($body->childNodes as $child) {
			$out .= $dom->saveHTML($child);
		}

		return ['content' => $out, 'images' => $images];
	}

	/**
	 * Entfernt aus dem (nach stripLeadingImages() verbliebenen) Content ein
	 * <img>, dessen Quelle exakt bzw. per imagesMatchForDedup() zur gewählten
	 * Hero-Bild-URL passt - auch wenn es NICHT am Content-Anfang steht.
	 *
	 * stripLeadingImages() entfernt bewusst nur Leitbilder vor dem ersten
	 * substantiellen Absatz (siehe dortiger Kommentar): ein Bild HINTER
	 * echtem Fließtext soll normalerweise stehen bleiben, weil es i. A. ein
	 * anderes Bild als die og:image-Vorschau ist. Newsletter-artige Seiten
	 * (z. B. fsfe.org: Intro-Absatz, danach dieselbe Grafik wie og:image)
	 * unterlaufen diese Annahme aber - dort ist das Bild nach dem Intro
	 * tatsächlich dasselbe wie das separat vorangestellte Hero-Bild, das
	 * Duplikat bliebe sonst sichtbar im Content stehen. Weil hier - anders
	 * als bei stripLeadingImages() - gegen die tatsächlich gewählte
	 * Hero-Bild-URL abgeglichen wird (nicht gegen ein beliebiges anderes
	 * Bild), ist das Entfernen hier sicher: ein Treffer bedeutet immer
	 * "dasselbe Bild wie das Hero-Bild", nie ein zufällig ähnliches anderes
	 * Motiv.
	 */
	private function removeDuplicateHeroImage(string $content, string $normalizedImageUrl, string $baseUrl): string {
		if (!str_contains($content, '<img')) {
			return $content;
		}

		$prevLibxmlErrors = libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $content . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prevLibxmlErrors);

		$body = $dom->getElementsByTagName('body')->item(0);
		if ($body === null) {
			return $content;
		}

		// Snapshot statt Live-NodeList: removeChild() unten würde eine
		// getElementsByTagName()-Live-Liste während der Iteration verändern.
		$imgs = iterator_to_array($body->getElementsByTagName('img'));

		foreach ($imgs as $img) {
			$src = $img->getAttribute('src');
			if ($src === '' || !$this->imagesMatchForDedup($this->normalizeUrl($src, $baseUrl), $normalizedImageUrl)) {
				continue;
			}
			// Vorschaubild eines Inline-Videos: Step 12 lässt in dem Fall das
			// Hero-Bild weg statt das Video zu entfernen.
			if ($img->parentNode !== null && $this->isInlineMediaFigure($img->parentNode)) {
				continue;
			}

			// Umschließende <figure> (falls vorhanden) komplett entfernen, sonst
			// nur das <img> selbst - eine evtl. Caption wurde bereits über
			// stripLeadingImages()/heroImageData für die separat vorangestellte
			// merlin-hero-image-Figure berücksichtigt.
			$target = $img;
			for ($ancestor = $img->parentNode; $ancestor !== null && $ancestor !== $body; $ancestor = $ancestor->parentNode) {
				if ($ancestor instanceof \DOMElement && strtolower($ancestor->nodeName) === 'figure') {
					$target = $ancestor;
					break;
				}
			}
			$target->parentNode?->removeChild($target);
			break;
		}

		$out = '';
		foreach ($body->childNodes as $child) {
			$out .= $dom->saveHTML($child);
		}

		return $out;
	}

	/**
	 * true, wenn $node die Figure eines Inline-Videos ist (siehe
	 * InlineMediaService).
	 */
	private function isInlineMediaFigure(\DOMNode $node): bool {
		return $node instanceof \DOMElement
			&& strtolower($node->nodeName) === 'figure'
			&& in_array(InlineMediaService::FIGURE_CLASS, preg_split('/\s+/', $node->getAttribute('class')) ?: [], true);
	}

	/**
	 * true, wenn ein Inline-Video im Content $normalizedImageUrl als
	 * Vorschaubild hat.
	 */
	private function inlineMediaPosterMatches(string $content, string $normalizedImageUrl, string $baseUrl): bool {
		if (!str_contains($content, InlineMediaService::FIGURE_CLASS)) {
			return false;
		}

		$prevLibxmlErrors = libxml_use_internal_errors(true);
		$dom = new \DOMDocument();
		$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $content . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prevLibxmlErrors);

		foreach ($dom->getElementsByTagName('figure') as $figure) {
			if (!$this->isInlineMediaFigure($figure)) {
				continue;
			}
			foreach ($figure->getElementsByTagName('img') as $img) {
				$src = $img->getAttribute('src');
				if ($src !== '' && $this->imagesMatchForDedup($this->normalizeUrl($src, $baseUrl), $normalizedImageUrl)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Durchsucht die Kinder von $parent der Reihe nach nach Leitbildern,
	 * entfernt sie direkt aus dem DOM und sammelt sie (Quelle + Caption) in
	 * $images. $inspected zählt dabei über alle Rekursionsebenen hinweg
	 * jeden geprüften Nicht-Wrapper-Knoten mit und bricht spätestens bei
	 * MAX_LEAD_IN_NODES ab (Schutz vor pathologischen Fällen, z. B. viele
	 * kurze Absätze in Folge).
	 *
	 * Reine Struktur-Wrapper (<div>/<section>/<article>/<main>) werden dabei
	 * IMMER transparent durchstiegen - unabhängig von ihrer Kinderzahl -,
	 * statt sie wie jeden anderen Knoten über
	 * resolveLeadingImage()/isSkippableLeadIn() zu bewerten. Drei reale Fälle
	 * brauchen das:
	 *   - Readabilitys äußerer Wrapper-Div (typischerweise
	 *     <div id="readability-page-1">…</div>, Attribute bereits von
	 *     cleanHtml() entfernt) enthält das Hero-Bild UND alle folgenden
	 *     Absätze als Geschwister-Kinder. Ohne Transparenz würde
	 *     resolveLeadingImage() zwar über diesen Wrapper hinweg bis zum Bild
	 *     entpacken (sein Einzel-Pfad-Algorithmus ignoriert spätere
	 *     Geschwister), aber ihn beim Entfernen als GANZES löschen und damit
	 *     den kompletten nachfolgenden Artikeltext mitreißen.
	 *   - Ein <article> mit Titel-/Meta-Block UND Hero-Figure als
	 *     Geschwister-Kinder (z. B. rbb24.de: <article><figure>…) landet bei
	 *     resolveLeadingImage() sonst in dessen internem Wrapper-Chain gar
	 *     nicht erst (nur p/div/span/a/figure/picture erlaubt), fällt auf
	 *     isSkippableLeadIn() zurück und wird dort fälschlich als "zu langer
	 *     echter Absatz" gewertet, sobald z. B. allein die Bildunterschrift
	 *     über der Schwelle liegt - die Suche bricht dann VOR dem Bild ab.
	 *   - <main><article><section><div><figure>… (z. B. spiegel.de): ohne
	 *     <main> in dieser Liste würde resolveLeadingImage() den <main>-Knoten
	 *     verwerfen (nicht in seiner eigenen p/div/span/a/figure-Allowlist)
	 *     und isSkippableLeadIn() ihn wegen des kompletten, weit über
	 *     LEAD_IN_TEXT_MAX_LENGTH liegenden Artikeltexts als "substantiell"
	 *     werten - die Suche bräche dann VOR dem eigentlichen Hero-Bild ab,
	 *     das Hero-Bild bliebe unentfernt im Content stehen und würde neben
	 *     dem in Step 12 separat vorangestellten og:image-Bild dupliziert.
	 * Deshalb muss dieser Check vor resolveLeadingImage()/isSkippableLeadIn()
	 * laufen, nicht danach.
	 *
	 * @return bool false = ein substantieller Knoten wurde erreicht, die
	 *         Suche muss auf dieser Ebene (und damit insgesamt) abbrechen.
	 *         true = $parent ist erschöpft oder das Node-Limit erreicht, der
	 *         Aufrufer darf mit seinem nächsten Geschwister weitermachen.
	 */
	private function scanForLeadingImages(\DOMNode $parent, string $baseUrl, array &$images, int &$inspected): bool {
		$node = $parent->firstChild;

		while ($node !== null && $inspected < self::MAX_LEAD_IN_NODES) {
			$next = $node->nextSibling;

			if ($node instanceof \DOMText) {
				if (trim(str_replace("\u{00A0}", ' ', $node->textContent)) !== '') {
					return false;
				}
				$node = $next;
				continue;
			}

			if (!$node instanceof \DOMElement) {
				$node = $next;
				continue;
			}

			// Inline-Video (siehe InlineMediaService): kein Leitbild, sondern
			// ein Player mit Vorschaubild - bleibt stehen, der Scan läuft
			// dahinter weiter wie bei einem überspringbaren Lead-in.
			if ($this->isInlineMediaFigure($node)) {
				$inspected++;
				$node = $next;
				continue;
			}

			if (in_array(strtolower($node->nodeName), ['div', 'section', 'article', 'main'], true)) {
				if (!$this->scanForLeadingImages($node, $baseUrl, $images, $inspected)) {
					return false;
				}
				$node = $next;
				continue;
			}

			$image = $this->resolveLeadingImage($node, $baseUrl);
			if ($image !== null) {
				$inspected++;
				$images[] = $image;
				$parent->removeChild($node);
				$node = $next;
				continue;
			}

			$inspected++;
			if ($this->isSkippableLeadIn($node)) {
				$node = $next;
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Löst einen Top-Level-Knoten zu einem einzelnen führenden Bild auf, sofern
	 * er - direkt oder in <p>/<a>/<div>/<span>/<figure>/<picture> verpackt -
	 * nichts als dieses eine <img> enthält. Gleiche Entpack-Logik wie zuvor in
	 * contentStartsWithMatchingImage(), nur ohne den URL-Abgleich: hier soll
	 * jedes Leitbild erkannt werden, nicht nur eines, das zur imageUrl passt.
	 *
	 * @return array{src: string, caption: ?string}|null
	 */
	private function resolveLeadingImage(\DOMElement $node, string $baseUrl): ?array {
		$current = $node;
		$depth   = 0;

		while ($current !== null && $depth < 6) {
			$tag = strtolower($current->nodeName);

			if ($tag === 'img') {
				$src = $current->getAttribute('src');
				if ($src === '') {
					return null;
				}
				return [
					'src'     => $this->normalizeUrl($src, $baseUrl),
					'caption' => $this->findFigcaption($node),
				];
			}

			if ($tag === 'picture') {
				$current = $this->firstImageInPicture($current);
				$depth++;
				continue;
			}

			if (!in_array($tag, ['p', 'div', 'span', 'a', 'figure'], true)) {
				return null;
			}

			$current = $this->firstNonWhitespaceElementChild($current);
			$depth++;
		}

		return null;
	}

	/**
	 * Sucht innerhalb eines (potenziellen) Leitbild-Knotens nach einer
	 * <figcaption> - entweder weil der Knoten selbst eine <figure> ist, oder
	 * eine als Nachfahre enthält (z. B. <div><figure>...</figure></div>).
	 */
	private function findFigcaption(\DOMElement $node): ?string {
		$figure = strtolower($node->nodeName) === 'figure' ? $node : $node->getElementsByTagName('figure')->item(0);
		if (!$figure instanceof \DOMElement) {
			return null;
		}

		$caption = $figure->getElementsByTagName('figcaption')->item(0);
		if (!$caption instanceof \DOMElement) {
			return null;
		}

		// Löst Block-Umbrüche (separate Caption-/Copyright-<div>s ohne Text
		// dazwischen, z. B. landeszeitung.de) in CAPTION_SEPARATOR auf statt
		// sie beim rohen textContent-Zugriff kommentarlos zu verschmelzen
		// ("…geheiratet.Quelle: privat"). Der Knoten wird gleich im Anschluss
		// aus dem Baum entfernt (siehe scanForLeadingImages()), die Mutation
		// hier ist also unbedenklich.
		$this->flattenCaptionElement($caption);

		$text = trim($caption->textContent);
		return $text !== '' ? $text : null;
	}

	/**
	 * Erkennt Knoten, die vor dem Hero-Bild stehen dürfen, ohne die Suche in
	 * stripLeadingImages() zu beenden - Überschriften immer, alles andere
	 * (z. B. Bylines wie "Von Max Mustermann", Datumszeilen, aber auch eine
	 * leere <ul> für Themen-Labels oder eine Social-Share-Icon-Liste) bis zu
	 * LEAD_IN_TEXT_MAX_LENGTH sichtbaren Zeichen, UNABHÄNGIG vom Tag.
	 *
	 * Früher nur für <p>/<div> geprüft - ein Tag außerhalb dieser Liste
	 * (z. B. eine leere <ul> für Themen-Labels vor dem Hero-Bild, wie bei
	 * deutschlandfunkkultur.de) beendete die Suche dadurch fälschlich sofort,
	 * selbst wenn der Knoten gar keinen sichtbaren Text enthielt. Die
	 * Tag-Einschränkung war nie das eigentliche Kriterium - entscheidend ist
	 * einzig, wie viel sichtbarer Text im Knoten steckt.
	 *
	 * Ein Knoten mit substantiellem Text (längere Absätze, echte Listen mit
	 * Inhalt, Blockquotes, Tabellen, …) beendet die Suche weiterhin, weil sein
	 * Text dann automatisch über der Schwelle liegt.
	 */
	private function isSkippableLeadIn(\DOMElement $node): bool {
		$tag = strtolower($node->nodeName);

		if (preg_match('/^h[1-6]$/', $tag) === 1) {
			return true;
		}

		$text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
		return mb_strlen($text) <= self::LEAD_IN_TEXT_MAX_LENGTH;
	}

	/**
	 * Liefert das <img> innerhalb eines <picture>-Elements.
	 *
	 * Sucht bewusst per getElementsByTagName() über ALLE Nachfahren statt nur
	 * die direkten Kinder zu prüfen: <source> ist ein Void-Element (kein
	 * schließendes Tag), aber libxml2s HTML-Parser (getestet mit 2.9.14)
	 * behandelt ein <source> ohne explizites "/>" NICHT als Void-Element,
	 * sondern verschachtelt jedes folgende Geschwister-Element als sein Kind -
	 * <picture><source>…<source>…<img></picture> wird dadurch zu
	 * <picture><source>…<source>…<img></source></source></picture>. Ein
	 * simpler Kind-für-Kind-Scan (wie firstNonWhitespaceElementChild) würde
	 * das <img> deshalb nie finden. Per Spezifikation kann ein <picture> ohnehin
	 * nur <source>-Elemente und genau ein <img> enthalten - anders als bei den
	 * generischen p/div/span/a/figure-Wrappern ist "irgendwo als Nachfahre"
	 * hier also gleichbedeutend mit "das gesuchte Bild".
	 */
	private function firstImageInPicture(\DOMElement $picture): ?\DOMElement {
		$img = $picture->getElementsByTagName('img')->item(0);
		return $img instanceof \DOMElement ? $img : null;
	}

	/**
	 * Liefert das erste Kind-Element eines Knotens, sofern davor nur
	 * Leerraum-Textknoten stehen (kein sonstiger Text). Steht vor dem ersten
	 * Element ein nicht-leerer Textknoten, oder hat der Knoten gar kein
	 * Element-Kind, wird null zurückgegeben.
	 *
	 * Geschützte Leerzeichen (&nbsp;, U+00A0) zählen dabei als Leerraum:
	 * WYSIWYG-Editoren fügen sie häufig als Abstandshalter direkt vor einem
	 * Bild ein, PHPs trim() entfernt sie aber nicht (kein ASCII-Whitespace) -
	 * ohne diese Normalisierung würde ein solches &nbsp; hier fälschlich als
	 * "echter" Text gewertet und contentStartsWithMatchingImage() bräche die
	 * Entpackung an dieser Stelle vorzeitig ab.
	 */
	private function firstNonWhitespaceElementChild(\DOMNode $parent): ?\DOMElement {
		foreach ($parent->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				return $child;
			}
			if ($child instanceof \DOMText && trim(str_replace("\u{00A0}", ' ', $child->textContent)) !== '') {
				return null;
			}
		}
		return null;
	}

	/**
	 * Vergleicht zwei bereits normalisierte Bild-URLs auf "wahrscheinlich
	 * dasselbe Bild" statt auf exakte Gleichheit.
	 *
	 * Ein exakter String-Vergleich schlägt in der Praxis ausgerechnet für die
	 * Bilder fehl, die contentStartsWithMatchingImage() erkennen soll - die
	 * src im Content und die per og:image ermittelte imageUrl sind zwar
	 * dasselbe Foto, aber fast nie exakt dieselbe URL:
	 *
	 *   - WordPress erzeugt für jedes in den Content eingefügte Bild
	 *     automatisch mehrere Größenvarianten und hängt dafür
	 *     "-{Breite}x{Höhe}" vor die Dateiendung an (z. B. "foto-1024x576.jpg"),
	 *     während og:image meist auf die Originaldatei ohne dieses Suffix
	 *     zeigt ("foto.jpg").
	 *   - Bilder-CDNs/Resize-Proxies (Jetpack Photon, Cloudinary, einfache
	 *     "?w=…"-Parameter) hängen die Zielgröße stattdessen als Query-String
	 *     an dieselbe Basis-URL an.
	 *   - AEM-basierte Bildserver (z. B. bei ARD/rbb: rbb-online.de) hängen
	 *     ein oder mehrere "key=wert"-Pfadsegmente ans Ende des Bildpfads an,
	 *     z. B. ".../foto.jpg.jpg/size=1280x720.jpg" (og:image) vs.
	 *     ".../foto.jpg.jpg/quality=160/size=1376x774.jpg" (dieselbe Aufnahme
	 *     im Artikeltext, andere Auflösung/Qualitätsstufe).
	 *
	 * Alle drei Varianten wurden vor diesem Fix ignoriert, wodurch das
	 * Voranstellen in genau diesen - sehr verbreiteten - Fällen weiterhin
	 * dupliziert hat. Die Suffix-/Pfadsegment-Muster sind spezifisch genug,
	 * um nicht versehentlich auf einen unverwandten Bildpfad zu matchen.
	 *
	 * Vierter Fall, nur als Fallback (Pfad ohne Host): manche Redaktionen
	 * liefern die per Readability/og:image ermittelte imageUrl und das im
	 * Content/Rohscan gefundene Bild von unterschiedlichen, aber
	 * äquivalenten Hostnamen derselben Organisation aus - z. B. rbb24.de
	 * (aktuelle Domain) vs. rbb-online.de (älteres Alias, liefert weiterhin
	 * identische Bild-Assets unter identischem Pfad aus). Der volle
	 * Host+Pfad-Vergleich oben schlägt dann trotz identischen Bilds fehl.
	 * Da diese Methode nur noch die Caption-Zuordnung in Step 12 steuert
	 * (nicht mehr die Entfernungs-Entscheidung, siehe stripLeadingImages()),
	 * kostet ein Fehltreffer hier bestenfalls eine falsche statt eine
	 * fehlende Caption - deshalb genügt der Pfad allein als Fallback.
	 */
	private function imagesMatchForDedup(string $contentImageUrl, string $normalizedImageUrl): bool {
		if ($contentImageUrl === $normalizedImageUrl) {
			return true;
		}

		$stripVariantMarkers = static function (string $url): string {
			$url = explode('?', $url, 2)[0];

			// Crop-Renditions von WordPress-Resizer-Plugins (u. a.
			// juedische-allgemeine.de): eine oder mehrere "-BxH"-Größenangaben
			// gefolgt von "-c-<position>" vor der Endung, z. B.
			// "foto-1440x720-1440x720-c-default.jpg" (og:image) vs.
			// "foto-1440x720-1160x580-c-default.jpg" (Hero-<figure> mit
			// <figcaption>). Die WordPress-Regel direkt darunter greift dort
			// nicht, weil "-c-default" zwischen Größenangabe und Endung steht.
			$url = preg_replace('/(?:-\d+x\d+)+-c-[a-z]+(?=\.\w+$)/i', '', $url) ?? $url;
			$url = preg_replace('/-\d+x\d+(?=\.\w+$)/i', '', $url) ?? $url;

			// WordPress "big image"-Handling (seit WP 5.3): Uploads über 2560 px
			// werden verkleinert und als "<name>-scaled.<ext>" abgelegt (bzw. nach
			// EXIF-Drehung als "<name>-rotated.<ext>"). og:image zeigt dann auf
			// diese Datei, die Größenvarianten im Content aber weiterhin auf den
			// Originalnamen ("<name>-860x484.<ext>"), z. B. netzpolitik.org:
			// "imago0061783399h-scaled.jpg" (og:image) vs.
			// "imago0061783399h-860x484.jpg" (Content-<figure> mit <figcaption>).
			// Das Suffix entfernen, damit beide auf "<name>.<ext>" normalisieren.
			$url = preg_replace('/-(?:scaled|rotated)(?=\.\w+$)/i', '', $url) ?? $url;

			// spiegel.de-Bildserver: Dateiname trägt Breite, Seitenverhältnis und
			// Fokuspunkt als "_w<Breite>_r<Verhältnis>_fpx<x>_fpy<y>"-Suffix vor
			// der Endung, z. B. "<uuid>_w1200_r1.778_fpx29_fpy41.jpg" (og:image)
			// vs. "<uuid>_w960_r1.5_fpx29_fpy41.jpg" (Content-<figure>, andere
			// Breite/Beschnitt derselben Aufnahme). Das Suffix entfernen, damit
			// beide auf dieselbe UUID+Endung normalisieren.
			$url = preg_replace('/_w\d+_r[\d.]+_fpx\d+_fpy\d+(?=\.\w+$)/i', '', $url) ?? $url;

			// Tagesspiegel-CDN "alternates"-Renditions: ein mittleres Pfadsegment
			// wie "BASE_16_9_W1400" oder "BASE_21_9_W1000" kodiert Seitenverhältnis
			// und Breite derselben Aufnahme, z. B.
			// ".../alternates/BASE_16_9_W1400/<ts>/foto.jpeg" (og:image) vs.
			// ".../alternates/BASE_21_9_W1000/<ts>/foto.jpeg" (Content-<figure>).
			// Das Segment entfernen, damit beide Varianten auf denselben Basispfad
			// normalisieren.
			$url = preg_replace('#/[A-Z0-9]+_\d+_\d+_W\d+(?=/)#i', '', $url) ?? $url;

			// TYPO3-Bildserver-Renditions (z. B. lto.de): "csm_"-verarbeitete
			// Dateien kodieren pro Crop/Skalierung einen eigenen Hash hinter der
			// Basis-Asset-ID, z. B. "csm_585333926_c7e56823e5.jpg" (og:image) vs.
			// "csm_585333926_caaa07ccb1.jpg" (Content-<figure>) - beide Renditions
			// derselben Aufnahme. Den Hash entfernen, damit beide auf dieselbe
			// Basis-ID normalisieren.
			$url = preg_replace('/(csm_\d+)_[0-9a-f]{6,}(\.\w+)$/i', '$1$2', $url) ?? $url;

			// taz.de-Bildserver: Pfadschema "/picture/<artikel-id>/<breite>/<dateiname>.<ext>"
			// liefert je nach Einbettung unterschiedliche Renditions UND unterschiedliche
			// Formate derselben Aufnahme, z. B. ".../picture/8594055/1200/41632941.jpeg"
			// (og:image) vs. ".../picture/8594055/14/41632941.webp" (Lazy-Load-Platzhalter
			// im Artikeltext, an dem im DOM die <figcaption> hängt). Der Dateiname ist
			// nicht immer ein reiner numerischer Hash - taz.de nutzt teils sprechende
			// Slugs, auch mit eingebetteten Punkten (z. B. "TRS.IMG-8042.KevinMazur.jpeg").
			// Deshalb "[^/]+" statt "\d+": Breite und Dateiendung entfernen, damit beide
			// auf denselben Dateinamen (ohne Endung) normalisieren.
			$url = preg_replace('#(/picture/\d+)/\d+/([^/]+)\.\w+$#i', '$1/$2', $url) ?? $url;

			// nd-aktuell.de-Bildserver: Pfadschema "/img/jpeg/<breite>/<id>" liefert je
			// nach Einbettung unterschiedliche Renditions derselben Aufnahme, z. B.
			// ".../img/jpeg/2400/325646" (og:image) vs. ".../img/jpeg/640/325646"
			// (Content-<figure>, kleinste srcset-Variante). Die Breite entfernen,
			// damit beide auf dieselbe Bild-ID normalisieren.
			$url = preg_replace('#(/img/jpeg)/\d+(/\d+)$#i', '$1$2', $url) ?? $url;

			// t-online.de-Bildserver (images.t-online.de): Pfadschema
			// ".../<crop>/fit-in/<breite>x0/<slug>.<ext>" liefert je nach
			// Einbettung unterschiedliche Renditions derselben Aufnahme, z. B.
			// ".../fit-in/1200x0/der-russische-....png" (og:image) vs.
			// ".../fit-in/1920x0/der-russische-....png" (Content-<figure>,
			// größte srcset-Variante). Nur die Zielbreite hinter "fit-in/"
			// entfernen, damit beide auf denselben Basispfad normalisieren.
			$url = preg_replace('#(/fit-in/)\d+x\d+(?=/)#i', '$1', $url) ?? $url;

			// Ghost-CMS-Bildserver (storage.ghost.io, u. a. jacobin.de): Renditions
			// werden über ein Pfadsegment ".../content/images/size/w<Breite>/..."
			// kodiert, optional gefolgt von "format/<fmt>/", z. B.
			// ".../size/w1200/2026/09/foto.jpg" (og:image) vs.
			// ".../size/w30/2026/09/foto.jpg" (Lazy-Load-Platzhalter im src-Attribut,
			// an dem im DOM die <figcaption> hängt). Das Segment entfernen, damit
			// alle Renditions auf denselben Basispfad normalisieren.
			$url = preg_replace('#/content/images/size/w\d+(?:/format/[a-z0-9]+)?(?=/)#i', '', $url) ?? $url;

			// golem.de-Bildserver: Dateinamen nach dem Schema
			// "/<JJMM>/<artikel-id>-<rendition-id>-<original-id>[_<crop>].<ext>",
			// jede Rendition derselben Aufnahme hat eine eigene ID in der Mitte
			// und optional ein Crop-Kürzel, z. B.
			// ".../2609/213534-600747-600744.jpg" (og:image) vs.
			// ".../2609/213534-600745-600744_rc.jpg" (Hero-<figure> mit
			// <figcaption>). Rendition-ID und Crop-Kürzel entfernen, damit
			// beide auf Artikel- und Original-ID normalisieren.
			$url = preg_replace('#(/\d{4}/\d+)-\d+-(\d+)(?:_[a-z]{1,3})?(\.\w+)$#i', '$1-$2$3', $url) ?? $url;

			// zeit.de-Bildserver (img.zeit.de): jede Rendition derselben
			// Aufnahme ist ein eigenes letztes Pfadsegment
			// "<variante>__<B>x<H>[__<zusatz>...]", z. B. ".../bild/wide__1300x731"
			// (og:image) vs. ".../bild/super__767x511" (Fullwidth-Kopfbild mit
			// Caption) oder ".../bild/wide__1000x562" (Kopf-<figure>), in
			// srcsets auch "wide__820x461__desktop__scale_2". Das Segment
			// entfernen, damit alle Varianten auf den Bildordner normalisieren.
			$url = preg_replace('#/[a-z]+(?:__\d+)*__\d+x\d+(?:__[a-z0-9_]+)*$#i', '', $url) ?? $url;

			// Drupal-Bildstile ("image styles", verbreitet u. a. bei
			// beck-aktuell.de): das Original liegt unter
			// "/sites/default/files/<pfad>", jede Rendition zusätzlich unter
			// einem eingeschobenen "/sites/default/files/styles/<stilname>/public/<pfad>"
			// - oft zusätzlich mit angehängter Formatendung, z. B.
			// ".../styles/1280w720h-webp-80/public/media/2026-08/Sicherheit.jpeg.webp"
			// (<picture>-<source>-Renditions) vs. ".../media/2026-08/Sicherheit.jpeg"
			// (og:image). Das eingebettete <picture>-Fallback-<img> selbst trägt
			// dabei sogar einen LEEREN Stilnamen ("styles//public/…", vom
			// clientseitigen picturefill-Polyfill erst per JS befüllt) - deshalb
			// "[^/]*" (auch 0 Zeichen), nicht "[^/]+". Das Segment entfernen,
			// damit beide auf denselben Pfad unter "files/" normalisieren.
			$url = preg_replace('#/styles/[^/]*/public(?=/)#i', '', $url) ?? $url;

			// Die Rendition oben hängt zusätzlich das Zielformat als weitere
			// Endung an die ursprüngliche Dateiendung an (z. B. ".jpeg.webp"
			// statt ".jpeg"). Dieses Anhängsel entfernen, wenn die verbleibende
			// Basis-Endung ein bekanntes Bildformat ist, damit beide Varianten
			// auf denselben Dateinamen normalisieren.
			$url = preg_replace('/\.(jpe?g|png|gif|webp|avif)\.(webp|avif|jpe?g|png)$/i', '.$1', $url) ?? $url;

			// AEM-Bildserver-Renditions: ein oder mehrere trailing "key=wert"-
			// Pfadsegmente (z. B. "size=1280x720.jpg", "quality=160")
			// entfernen, bis das stabile Basis-Asset übrig bleibt.
			$parts = explode('/', $url);
			while (count($parts) > 1 && preg_match('/^[a-z]+=[\w.,%-]+$/i', end($parts)) === 1) {
				array_pop($parts);
			}
			return implode('/', $parts);
		};

		$strippedContentUrl = $stripVariantMarkers($contentImageUrl);
		$strippedImageUrl   = $stripVariantMarkers($normalizedImageUrl);
		if ($strippedContentUrl === $strippedImageUrl) {
			return true;
		}

		$contentPath = parse_url($strippedContentUrl, PHP_URL_PATH);
		return $contentPath !== null && $contentPath !== ''
			&& $contentPath === parse_url($strippedImageUrl, PHP_URL_PATH);
	}

	/**
	 * Normalize relative URLs to absolute
	 */
	private function normalizeUrl(string $imageUrl, string $baseUrl): string {
		// Already absolute
		if (preg_match('/^https?:\/\//i', $imageUrl)) {
			// Cleartext http:// wird auf https:// hochgestuft: Viele Seiten
			// liefern im og:image ein http:// (og:image:secure_url wird von
			// den Extraktions-Pfaden oben nicht ausgewertet), obwohl derselbe
			// Host https anstandslos bedient. iOS App Transport Security
			// blockiert unverschlüsselte Bild-Loads ("Blocked: Load failed"),
			// deswegen wird hier konsequent auf https umgeschrieben.
			return preg_replace('/^http:\/\//i', 'https://', $imageUrl);
		}

		$base = parse_url($baseUrl);
		$scheme = $base['scheme'] ?? 'https';
		$host = $base['host'] ?? '';

		// Protocol-relative URL
		if (str_starts_with($imageUrl, '//')) {
			return $scheme . ':' . $imageUrl;
		}

		// Absolute path
		if (str_starts_with($imageUrl, '/')) {
			return $scheme . '://' . $host . $imageUrl;
		}

		// Relative path
		$path = $base['path'] ?? '/';
		$pathParts = explode('/', $path);
		array_pop($pathParts); // Remove filename
		$basePath = implode('/', $pathParts);

		return $scheme . '://' . $host . $basePath . '/' . $imageUrl;
	}

	/**
	 * Folgt der HTTP-Redirect-Kette eines URL-Shorteners und gibt die finale URL zurück.
	 *
	 * Wir nutzen einen reinen HEAD-Request (kein Body-Download), um schnell und
	 * ressourcenschonend die Ziel-URL zu ermitteln, ohne den Artikel bereits zu laden.
	 *
	 * @throws \Exception wenn ein Hop auf eine private/reservierte Adresse zeigt oder
	 *                     der Request fehlschlägt (siehe httpRequestFollowingRedirects()).
	 */
	private function followHttpRedirect(string $url): string {
		return $this->httpRequestFollowingRedirects($url, nobody: true)['finalUrl'];
	}

	// ──────────────────────────────────────────────────────────────────────────
	// HTTP Fetching & SSRF Protection
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Fetch URL content.
	 *
	 * Speed improvements over the naive implementation:
	 *   - Accept-Encoding: gzip / deflate  →  60-80 % smaller transfer
	 *   - decode_content: true             →  Guzzle decompresses transparently
	 *   - connect_timeout: 5 s             →  fail fast on unreachable hosts
	 *   - charset from Content-Type header →  skip mb_detect_encoding on full body
	 *   - meta charset fallback            →  scan only the first 2 KB
	 *
	 * @return array{body: string, httpCharset: ?string, finalUrl: string}
	 * @throws \Exception wenn ein Hop auf eine private/reservierte Adresse zeigt oder
	 *                     der Request fehlschlägt (siehe httpRequestFollowingRedirects()).
	 */
	private function fetchUrl(string $url): array
	{
		$result = $this->httpRequestFollowingRedirects($url, nobody: false);
		return [
			'body'        => $result['body'],
			'httpCharset' => $result['httpCharset'],
			'finalUrl'    => $result['finalUrl'],
			'isPdf'       => $result['isPdf'],
		];
	}

	/**
	 * true, wenn der URL-Pfad auf .pdf endet (Query/Fragment ignoriert).
	 */
	private function isPdfUrl(string $url): bool
	{
		$path = parse_url($url, PHP_URL_PATH);
		return is_string($path) && preg_match('/\.pdf$/i', rawurldecode($path)) === 1;
	}

	/**
	 * Baut das Extraktionsergebnis für einen PDF-Link. Die PDF selbst wird weder
	 * geladen noch gespeichert – gespeichert wird nur die URL als Marker
	 * (<div class="merlin-pdf" data-pdf-src>), die Clients laden das Dokument zur
	 * Lesezeit direkt von der Quelle. Der Titel kommt aus dem Dateinamen; die
	 * Clients können den echten PDF-Titel später aus den Dokument-Metadaten lesen.
	 *
	 * Der Host wird trotzdem gegen private/reservierte Adressen geprüft
	 * (SSRF-Schutz), damit sich über PDF-Links keine internen URLs in der
	 * Artikelliste ablegen lassen, die ein Client später abrufen würde.
	 *
	 * @throws \Exception bei ungültigem Schema oder privatem/nicht auflösbarem Host.
	 */
	private function buildPdfResult(string $url): array
	{
		$this->assertPublicHostAndResolve($url);

		$host = preg_replace('/^www\./i', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
		$path = (string) parse_url($url, PHP_URL_PATH);
		$name = rawurldecode(basename($path));
		$name = preg_replace('/\.pdf$/i', '', $name) ?? $name;
		$name = trim((string) preg_replace('/[\s_\-]+/u', ' ', $name));
		$title = $name !== '' ? $name : $host;

		$safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$content = $this->sanitizeHtml(
			'<div class="' . self::PDF_MARKER_CLASS . '" data-pdf-src="' . $safeUrl . '">'
			. '<a href="' . $safeUrl . '" target="_blank" class="merlin-pdf-fallback-link">PDF</a>'
			. '</div>'
		);

		return [
			'url'                 => $url,
			'title'               => $title,
			'content'             => $content,
			'excerpt'             => 'PDF · ' . $host,
			'author'              => null,
			'authorUrl'           => null,
			'authors'             => null,
			'siteName'            => $host,
			'imageUrl'            => null,
			'readingTime'         => 0,
			'publishedAt'         => null,
			'category'            => self::PDF_CATEGORY,
			'isPaywalled'         => false,
			'paywallSubscribeUrl' => null,
		];
	}

	/**
	 * Führt einen HTTP-Request aus und folgt 3xx-Redirects manuell statt über
	 * CURLOPT_FOLLOWLOCATION.
	 *
	 * SSRF-Schutz (siehe SECURITY-AUDIT.md, "SSRF beim Artikel-Import"):
	 * CURLOPT_FOLLOWLOCATION lässt libcurl jedem Location-Header selbstständig
	 * folgen – dabei gäbe es keine Gelegenheit, die Ziel-IP VOR dem Connect gegen
	 * private/reservierte Ranges zu prüfen. Ein Angreifer könnte so über einen
	 * öffentlichen Erst-Redirect (z. B. einen offenen URL-Shortener) intern auf
	 * 127.0.0.1, RFC1918-Adressen oder Cloud-Metadata-Endpunkte (169.254.169.254)
	 * umleiten. Deshalb:
	 *   1. Jeder Hop wird einzeln aufgelöst und über assertPublicHostAndResolve()
	 *      geprüft, BEVOR verbunden wird.
	 *   2. Die Verbindung wird per CURLOPT_RESOLVE auf genau die geprüfte(n) IP(s)
	 *      gepinnt, damit ein zweiter DNS-Lookup zwischen Prüfung und Connect
	 *      (DNS-Rebinding) nicht auf eine private Adresse umschwenken kann.
	 *   3. Redirects werden manuell über den Location-Header verfolgt, maximal
	 *      MAX_REDIRECTS mal.
	 *
	 * @return array{body: string, httpCharset: ?string, finalUrl: string, isPdf: bool}
	 * @throws \Exception bei ungültigem/privatem Host, zu vielen Redirects oder curl-Fehlern.
	 */
	private function httpRequestFollowingRedirects(string $url, bool $nobody): array
	{
		$currentUrl  = $url;
		$httpCharset = null;

		for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
			$parsed = parse_url($currentUrl);
			$host   = $parsed['host'] ?? '';
			$scheme = strtolower($parsed['scheme'] ?? '');
			$port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

			// Wirft eine Exception bei ungültigem Schema, nicht auflösbarem Host
			// oder privater/reservierter Ziel-IP.
			$ips  = $this->assertPublicHostAndResolve($currentUrl);
			$pins = $this->buildResolvePin($host, $port, $ips);

			// Domain-spezifische Header (z. B. Consent-Cookies) werden für JEDEN Hop
			// einzeln anhand des aktuellen Hosts geladen – siehe loadFetchOverrides().
			$overrides = $this->loadFetchOverrides($currentUrl);

			$ch   = curl_init($currentUrl);
			$opts = [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false, // manuelles Redirect-Following, siehe Docblock
				CURLOPT_HEADER         => true,  // Header mitliefern, um Location selbst auszuwerten
				CURLOPT_NOBODY         => $nobody,
				CURLOPT_TIMEOUT        => $nobody ? 10 : 20,
				CURLOPT_CONNECTTIMEOUT => $nobody ? 5 : 10,
				CURLOPT_RESOLVE        => $pins, // IP-Pinning gegen DNS-Rebinding
				CURLOPT_USERAGENT      => $nobody
					? 'Mozilla/5.0 (compatible; Merlin/1.0)'
					: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/150.0',
			];

			$headers = [];
			$abort   = null;

			if (!$nobody) {
				// Transfer abbrechen, sobald der Header eine PDF ankündigt (die wird
				// nie geladen, siehe buildPdfResult()) oder der Body zu groß wird.
				$opts[CURLOPT_NOPROGRESS]       = false;
				$opts[CURLOPT_PROGRESSFUNCTION] = static function ($ch, $dlTotal, $dlNow) use (&$abort): int {
					$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
					$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
					if ($code >= 200 && $code < 300
						&& is_string($type)
						&& stripos($type, 'application/pdf') === 0
					) {
						$abort = 'pdf';
						return 1;
					}
					if ($dlNow > self::MAX_BODY_BYTES || $dlTotal > self::MAX_BODY_BYTES) {
						$abort = 'too-large';
						return 1;
					}
					return 0;
				};
				$opts[CURLOPT_AUTOREFERER] = true;
				$opts[CURLOPT_ENCODING]    = ''; // Leerer String = alle unterstützten Encodings aktivieren
				$headers = [
					'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
					'Accept-Encoding: gzip, deflate, br, zstd',
					'Accept-Language: de,en-US;q=0.9,en;q=0.8',
					'Cache-Control: no-cache',
					'Connection: keep-alive',
					'DNT: 1',
					'Host: ' . $host,
					'Pragma: no-cache',
					'Priority: u=0, i',
					'Referer: https://google.com/',
					'Sec-Fetch-Dest: document',
					'Sec-Fetch-Mode: navigate',
					'Sec-Fetch-Site: same-origin',
					'Sec-Fetch-User: ?1',
					'Sec-GPC: 1',
					'Upgrade-Insecure-Requests: 1',
				];
			}

			foreach ($overrides as $name => $value) {
				// User-Agent geht über CURLOPT_USERAGENT statt als Header-Zeile,
				// sonst sendet curl den Header doppelt.
				if (strcasecmp($name, 'User-Agent') === 0) {
					$opts[CURLOPT_USERAGENT] = $value;
					continue;
				}
				// Gleichnamigen Default entfernen, damit der Override ihn ersetzt
				// statt einen zweiten Header derselben Art zu erzeugen.
				$headers = array_values(array_filter(
					$headers,
					static fn(string $h): bool => stripos($h, $name . ':') !== 0
				));
				$headers[] = $name . ': ' . $value;
			}

			if ($headers !== []) {
				$opts[CURLOPT_HTTPHEADER] = $headers;
			}

			curl_setopt_array($ch, $opts);
			$response = curl_exec($ch);

			if ($response === false) {
				$error = curl_error($ch);
				curl_close($ch);
				if ($abort === 'pdf') {
					return ['body' => '', 'httpCharset' => null, 'finalUrl' => $currentUrl, 'isPdf' => true];
				}
				if ($abort === 'too-large') {
					throw new \Exception('Antwort zu groß (>' . self::MAX_BODY_BYTES . ' Bytes): ' . $currentUrl);
				}
				throw new \Exception('HTTP-Request fehlgeschlagen: ' . $error);
			}

			$headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$statusCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE); // z. B. "text/html; charset=iso-8859-1"
			curl_close($ch);

			$rawHeaders = substr((string) $response, 0, $headerSize);
			$body       = substr((string) $response, $headerSize);

			if (in_array($statusCode, [301, 302, 303, 307, 308], true)
				&& preg_match('/^Location:\s*(.+?)\r?$/im', $rawHeaders, $m)
			) {
				// Location-Header können relativ sein (RFC 7231 erlaubt das, auch
				// wenn die meisten Server absolute URLs senden) – gegen den
				// aktuellen Hop auflösen, nicht gegen die ursprüngliche URL.
				$currentUrl = $this->normalizeUrl(trim($m[1]), $currentUrl);
				continue;
			}

			// Charset aus dem HTTP-Header extrahieren – der HTTP-Header hat nach
			// RFC 7231 Vorrang vor <meta>-Angaben im Body.
			if (is_string($contentType)
				&& preg_match('/;\s*charset=([^\s;]+)/i', $contentType, $m)
			) {
				$httpCharset = strtolower(trim($m[1], " \t\"'"));
			}

			return ['body' => $body, 'httpCharset' => $httpCharset, 'finalUrl' => $currentUrl, 'isPdf' => false];
		}

		throw new \Exception('Zu viele Redirects (>' . self::MAX_REDIRECTS . '): ' . $url);
	}

	/**
	 * Lädt domain-spezifische HTTP-Header aus der <fetch>-Sektion von
	 * content-filters/{domain}.xml.
	 *
	 * Anwendungsfall: Seiten wie golem.de antworten auf Server-Requests mit einem
	 * 302 auf ihre Consent-Seite, bevor überhaupt Artikel-HTML ausgeliefert wird.
	 * Ein mitgeschickter Consent-Cookie beendet diese Weiterleitung.
	 *
	 * Warum pro Redirect-Hop und nicht einmal pro Request: Cookies sind an einen
	 * Host gebunden. Würden wir sie einmal setzen und der gesamten Redirect-Kette
	 * mitgeben, landete das Cookie von Host A beim nächsten Hop auf Host B – genau
	 * das Leck, das Browser über die Same-Origin-Regeln verhindern.
	 *
	 * @return array<string,string> Header-Name => Wert (nur Whitelist, CRLF-frei)
	 */
	private function loadFetchOverrides(string $url): array
	{
		$domain = $this->normalizeDomain($url);
		$config = $this->loadDomainConfig($domain);

		$headers = [];

		if ($config !== null && isset($config->fetch)) {
			foreach ($config->fetch->header as $header) {
				$name  = trim((string) ($header['name'] ?? ''));
				$value = trim((string) ($header['value'] ?? ''));

				if ($name === '' || $value === '') {
					continue;
				}

				if (!in_array(strtolower($name), self::FETCH_HEADER_WHITELIST, true)) {
					$this->logger->warning(
						'Merlin: <fetch>-Header nicht erlaubt und ignoriert: ' . $name,
						['url' => $url]
					);
					continue;
				}

				// CR/LF entfernen: Ein Wert mit Zeilenumbruch würde sonst weitere
				// Header-Zeilen in den Request schmuggeln (Header-Injection).
				$headers[$name] = str_replace(["\r", "\n"], '', $value);
			}
		}

		$this->appendSiteCredentialCookies($domain, $headers);

		return $headers;
	}

	/**
	 * Hängt den per Paywall-Login gewonnenen Session-Cookie-Satz des
	 * AUFRUFENDEN Nutzers ($currentUserId) an den Cookie-Header an, sofern die
	 * Domain eine <login>-Sektion hat. Löst bei Bedarf einen frischen Login
	 * aus (SiteCredentialService::ensureValidCookies() cached selbst), holt
	 * aber NIE Zugangsdaten eines anderen Nutzers – ohne Nutzerkontext
	 * (anonymer/System-Aufruf) bleibt die Cookie-Injektion aus.
	 *
	 * @param array<string,string> $headers
	 */
	private function appendSiteCredentialCookies(string $domain, array &$headers): void
	{
		if ($this->currentUserId === null) {
			return;
		}

		$loginConfig = $this->siteCredentials->loadLoginConfig($domain);
		if ($loginConfig === null) {
			return;
		}

		$cookies = $this->siteCredentials->ensureValidCookies($this->currentUserId, $domain, $loginConfig);
		if ($cookies === null || $cookies === []) {
			return;
		}

		$cookiePairs = [];
		foreach ($cookies as $name => $value) {
			$cookiePairs[] = $name . '=' . str_replace(["\r", "\n", ';'], '', $value);
		}

		$existing = $headers['Cookie'] ?? '';
		$headers['Cookie'] = $existing === '' ? implode('; ', $cookiePairs) : $existing . '; ' . implode('; ', $cookiePairs);
	}

	/**
	 * Wirft PaywallLoginRequiredException, wenn die Domain eine
	 * <login>-Sektion mit paywall-marker-Pattern hat, dieses Pattern im
	 * gerade abgerufenen HTML greift UND der aufrufende Nutzer keine
	 * gültigen Session-Cookies für die Domain hat (kein Login-Kontext, keine
	 * Zugangsdaten hinterlegt, oder letzter Login-Versuch fehlgeschlagen).
	 * Ein Treffer trotz gültiger Cookies wird NICHT geworfen – dann ist der
	 * Cookie vermutlich einfach nicht (mehr) ausreichend, aber ein erneuter
	 * Login wurde in appendSiteCredentialCookies() bereits versucht.
	 */
	private function assertNotPaywalled(string $url, string $rawHtml): void
	{
		$domain      = $this->normalizeDomain($url);
		$loginConfig = $this->siteCredentials->loadLoginConfig($domain);
		if ($loginConfig === null || $loginConfig->paywallMarkerPattern === null) {
			return;
		}

		if (@preg_match($loginConfig->paywallMarkerPattern, $rawHtml) !== 1) {
			return;
		}

		$hasValidCookies = $this->currentUserId !== null
			&& $this->siteCredentials->getCachedCookies($this->currentUserId, $domain) !== null;
		if ($hasValidCookies) {
			return;
		}

		throw new PaywallLoginRequiredException($domain, $loginConfig->page);
	}

	/**
	 * Generische Paywall-Erkennung über <paywall><marker xpath="…"> (siehe
	 * ContentFilterSchema): anders als assertNotPaywalled() löst ein Treffer
	 * KEINE Exception und keinen Login-Versuch aus, sondern liefert ein Flag +
	 * optionale Abo-URL, die der Aufrufer auf den Artikel schreibt. Gedacht
	 * für Domains OHNE <login>-Unterstützung, bei denen Merlin den Artikel
	 * grundsätzlich nicht automatisch freischalten kann - der Client zeigt
	 * stattdessen einen Hinweis mit den Optionen "Abo abschliessen"/
	 * "Archivieren" (siehe Article::jsonSerialize()).
	 *
	 * Läuft bewusst VOR dem Pre-Filter (auf dem noch unveränderten
	 * $rawHtml): ein Bundle-<remove> entfernt Paywall-Overlays typischerweise
	 * genau dort, wo der Marker sie erkennen soll.
	 *
	 * Hat die Domain eine <login>-Konfiguration, übernimmt stattdessen der
	 * bestehende Credential-Login-Flow (assertNotPaywalled()) die Erkennung -
	 * ein zusätzlicher generischer Treffer wäre dort redundant und würde dem
	 * Nutzer zwei widersprüchliche Hinweise gleichzeitig zeigen.
	 *
	 * @return array{isPaywalled: bool, subscribeUrl: ?string}
	 */
	private function detectPaywall(string $rawHtml, string $domain): array {
		$none = ['isPaywalled' => false, 'subscribeUrl' => null];

		$config = $this->loadDomainConfig($domain);
		if ($config === null || !isset($config->paywall)) {
			return $none;
		}

		$markerRules = $config->xpath('paywall/marker') ?: [];
		if (empty($markerRules)) {
			return $none;
		}

		if ($this->siteCredentials->loadLoginConfig($domain) !== null) {
			return $none;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->encoding = 'UTF-8';
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $rawHtml,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xpath = new \DOMXPath($dom);

		$matched = false;
		foreach ($markerRules as $rule) {
			$expr = trim((string) ($rule['xpath'] ?? ''));
			if ($expr === '') {
				continue;
			}
			$result = @$xpath->query($expr);
			if ($result === false) {
				$this->logger->warning('content-filters: invalid paywall marker XPath skipped', [
					'xpath'   => $expr,
					'context' => $domain,
				]);
				continue;
			}
			if ($result->length > 0) {
				$matched = true;
				break;
			}
		}

		if (!$matched) {
			return $none;
		}

		$subscribeUrl = null;
		foreach (($config->xpath('paywall/subscribe') ?: []) as $rule) {
			$candidate = trim((string) ($rule['url'] ?? ''));
			if ($candidate !== '') {
				$subscribeUrl = $candidate;
				break;
			}
		}

		return ['isPaywalled' => true, 'subscribeUrl' => $subscribeUrl];
	}

	// SSRF-Guard (assertPublicHostAndResolve/resolveHostIps/isPublicIp/buildResolvePin) via SsrfSafeResolver-Trait.

	// ──────────────────────────────────────────────────────────────────────────
	// Encoding Detection
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Extract the character encoding declared in HTML meta tags.
	 *
	 * Unterstützte Formen:
	 *   <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
	 *   <meta charset="utf-8">
	 *
	 * Scannt nur die ersten 4 KB (der <head> steht immer am Anfang) und gibt
	 * den Charset-String in Kleinbuchstaben zurück (z. B. "iso-8859-1") oder
	 * null, wenn kein Encoding deklariert ist.
	 */
	private function detectHtmlEncoding(string $html): ?string
	{
		$head = substr($html, 0, 10000);

		// <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
		// Attributreihenfolge: http-equiv vor content
		if (preg_match(
			'/<meta[^>]+http-equiv=["\']?Content-Type["\']?[^>]+content=["\']?[^;]*;\s*charset=([^\s;"\'>\\/]+)/i',
			$head, $m
		)) {
			return strtolower(trim($m[1]));
		}

		// Attributreihenfolge umgekehrt: content vor http-equiv
		if (preg_match(
			'/<meta[^>]+content=["\']?[^;]*;\s*charset=([^\s;"\'>\\/]+)[^>]+http-equiv=["\']?Content-Type["\']?/i',
			$head, $m
		)) {
			return strtolower(trim($m[1]));
		}

		// <meta charset="utf-8"> (HTML5-Kurzform)
		if (preg_match('/<meta[^>]+charset=["\']?([^\s;"\'>\\/]+)/i', $head, $m)) {
			return strtolower(trim($m[1]));
		}

		return null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Basic HTML Metadata Extraction
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Extract title from HTML if Readability fails
	 */
	private function extractTitleFromHtml(string $html): string {
		if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
			return trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
		}
		return 'Untitled Article';
	}

	/**
	 * Extract og:image or twitter:image from HTML meta tags
	 */
	private function extractOgImage(string $html): ?string {
		// og:image:secure_url first: manche Seiten (z. B. berlin.de) liefern
		// im og:image ein cleartext http://, obwohl og:image:secure_url mit
		// https:// denselben Host bedient. Beide Attribut-Schreibweisen
		// (property= laut OpenGraph-Spec, name= wie berlin.de es nutzt)
		// abdecken. iOS ATS blockiert sonst den späteren Bild-Load.
		if (preg_match('/<meta[^>]+(?:property|name)=["\']og:image:secure_url["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
			return trim($matches[1]);
		}
		if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']og:image:secure_url["\'][^>]*>/i', $html, $matches)) {
			return trim($matches[1]);
		}

		// Try og:image (most common)
		if (preg_match('/<meta\s+(?:property=["\']og:image["\']\s+content|content=["\']([^"\']+)["\']\s+property=["\']og:image)["\']?\s*(?:content=["\']([^"\']+)["\'])?[^>]*>/i', $html, $matches)) {
			// Handle both attribute orders: property first or content first
			$img = !empty($matches[2]) ? $matches[2] : (!empty($matches[1]) ? $matches[1] : null);
			if ($img) return trim($img);
		}

		// Simpler og:image pattern (property= or name=)
		if (preg_match('/<meta[^>]+(?:property|name)=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
			return trim($matches[1]);
		}

		// Reverse attribute order
		if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']og:image["\'][^>]*>/i', $html, $matches)) {
			return trim($matches[1]);
		}

		// Twitter card image as fallback
		if (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
			return trim($matches[1]);
		}
		if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\'][^>]*>/i', $html, $matches)) {
			return trim($matches[1]);
		}

		return null;
	}

	/**
	 * Scan raw HTML for the first prominent hero image when no og:image / twitter:image is present.
	 *
	 * Readability entfernt <figure>-Elemente häufig, wenn sie vor dem Fließtext
	 * stehen oder tief verschachtelt sind. Diese Methode rettet Bild und Caption,
	 * bevor Readability sie verliert.
	 *
	 * Sucht in dieser Reihenfolge:
	 *   1. Erstes <img> in einer <figure> innerhalb von <article> oder <main>
	 *   2. Erstes <img> in irgendeiner <figure> im Dokument
	 *   3. Erstes <img> im <article>- oder <main>-Bereich (ohne figure-Wrapper)
	 *
	 * data-src / data-orig-src werden als Fallback für lazy-load-Bilder
	 * berücksichtigt (letzteres z. B. bei WP-Rocket-artigem Lazy-Loading, das
	 * die echte URL in data-orig-src statt data-src ablegt).
	 * Tracking-Pixel (1x1, data:-URLs, Icon-/Logo-Klassen) werden übersprungen.
	 *
	 * @return array{src: string, caption: ?string}|null
	 */
	private function extractHeroImageFromHtml(string $html, string $baseUrl): ?array
	{
		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xpath = new \DOMXPath($dom);

		$candidates = [
			// Prominenteste Position: figure in article/main
			'(//article | //main)//figure//img[@src or @data-src or @data-orig-src]',
			// Fallback: irgendeine figure im Dokument
			'//figure//img[@src or @data-src or @data-orig-src]',
			// Letzter Ausweg: erstes img in article/main ohne figure-Wrapper
			'(//article | //main)//img[@src or @data-src or @data-orig-src]',
		];

		foreach ($candidates as $query) {
			$nodes = $xpath->query($query);
			if (!$nodes || $nodes->length === 0) continue;

			foreach ($nodes as $img) {
				if (!$img instanceof \DOMElement) continue;

				// data-src/data-orig-src bevorzugen bei lazy-loading, sonst src
				$src = trim($img->getAttribute('src'));
				if ($src === '' || str_starts_with($src, 'data:')) {
					$src = trim($img->getAttribute('data-src'));
				}
				if ($src === '' || str_starts_with($src, 'data:')) {
					$src = trim($img->getAttribute('data-orig-src'));
				}
				if ($src === '' || str_starts_with($src, 'data:')) continue;

				// Tracking-Pixel und Dekorations-Icons ausschließen
				$class = strtolower($img->getAttribute('class'));
				if (str_contains($src, '1x1') || str_contains($class, 'icon') || str_contains($class, 'logo')) continue;

				// <figcaption> aus dem nächstgelegenen <figure>-Elternelement holen.
				// Wir wandern vom img-Knoten aufwärts, bis wir eine <figure> finden,
				// dann suchen wir darin nach <figcaption>.
				$caption = null;
				$ancestor = $img->parentNode;
				while ($ancestor !== null && strtolower($ancestor->nodeName) !== 'figure') {
					$ancestor = $ancestor->parentNode;
				}
				if ($ancestor instanceof \DOMElement) {
					$captionNodes = $xpath->query('.//figcaption', $ancestor);
					$captionNode  = $captionNodes ? $captionNodes->item(0) : null;
					if ($captionNode instanceof \DOMElement) {
						// Siehe findFigcaption(): löst Block-Umbrüche (separate
						// Caption-/Copyright-<div>s ohne Text dazwischen) in
						// CAPTION_SEPARATOR auf statt sie im rohen textContent
						// zu verschmelzen.
						$this->flattenCaptionElement($captionNode);
						$captionText = trim($captionNode->textContent);
						$caption = $captionText !== '' ? $captionText : null;
					}
				}

				return [
					'src'     => $this->normalizeUrl($src, $baseUrl),
					'caption' => $caption,
				];
			}
		}

		return null;
	}

	/**
	 * Sucht im rohen HTML die <figure>, deren <img> (src, data-src,
	 * data-orig-src oder eine srcset-Variante) per imagesMatchForDedup() zu
	 * $normalizedImageUrl passt, und liefert deren <figcaption>-Text.
	 *
	 * Ergänzt extractHeroImageFromHtml(), das nur die erste <figure> im
	 * Dokument betrachtet und deshalb an vorangestellten Figures mit anderem
	 * Bild (Autoren-Avatar, Logo) hängen bleibt.
	 */
	private function findFigcaptionForImage(string $html, string $normalizedImageUrl, string $baseUrl): ?string {
		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xpath = new \DOMXPath($dom);

		$figures = $xpath->query('//figure[.//figcaption]');
		if (!$figures) {
			return null;
		}

		foreach ($figures as $figure) {
			if (!$figure instanceof \DOMElement) {
				continue;
			}
			$imgs = $xpath->query('.//img', $figure);
			if (!$imgs) {
				continue;
			}
			foreach ($imgs as $img) {
				if (!$img instanceof \DOMElement) {
					continue;
				}
				$sources = [$img->getAttribute('src'), $img->getAttribute('data-src'), $img->getAttribute('data-orig-src')];
				foreach (explode(',', $img->getAttribute('srcset')) as $candidate) {
					$sources[] = preg_split('/\s+/', trim($candidate))[0] ?? '';
				}
				foreach ($sources as $src) {
					$src = trim($src);
					if ($src === '' || str_starts_with($src, 'data:')
						|| !$this->imagesMatchForDedup($this->normalizeUrl($src, $baseUrl), $normalizedImageUrl)) {
						continue;
					}
					$captionNode = $xpath->query('.//figcaption', $figure)?->item(0);
					if (!$captionNode instanceof \DOMElement) {
						return null;
					}
					// Siehe findFigcaption()/extractHeroImageFromHtml().
					$this->flattenCaptionElement($captionNode);
					$text = trim($captionNode->textContent);
					return $text !== '' ? $text : null;
				}
			}
		}

		return null;
	}

	/**
	 * Bestes Icon der konkreten Seite aus den <link>-/<meta>-Tags des <head>
	 * (Support-Infobox, siehe Service\SupportBoxService): apple-touch-icon
	 * (größtes per sizes) vor <link rel="icon"> (SVG vor PNG vor ICO, jeweils
	 * größtes), dann msapplication-TileImage, zuletzt /favicon.ico der Origin.
	 * Bewusst NICHT og:image - das ist meist ein Artikel-/Werbebanner, kein Logo.
	 *
	 * Es findet kein zusätzlicher Request statt; die URL wird nur aus dem
	 * bereits geladenen HTML gelesen. Der Client blendet ein nicht ladbares Bild
	 * einfach aus.
	 */
	private function extractSiteIconUrl(string $html, string $baseUrl): ?string {
		$parts = parse_url($baseUrl);
		$scheme = strtolower($parts['scheme'] ?? '');
		$host = $parts['host'] ?? '';
		if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
			return null;
		}
		$origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

		$best = null;
		$bestScore = -1;
		try {
			$previous = libxml_use_internal_errors(true);
			$doc = new \DOMDocument();
			// Nur den Anfang parsen: <head> steht vorn, der Rest ist für Icons irrelevant.
			$doc->loadHTML('<?xml encoding="utf-8" ?>' . substr($html, 0, 262144), LIBXML_NONET);
			libxml_clear_errors();
			libxml_use_internal_errors($previous);

			$xpath = new \DOMXPath($doc);

			// <base href> ändert die Auflösung relativer Icon-URLs.
			$base = $baseUrl;
			$baseEl = $xpath->query('//base[@href]')->item(0);
			if ($baseEl instanceof \DOMElement && trim($baseEl->getAttribute('href')) !== '') {
				$base = $this->normalizeUrl(trim($baseEl->getAttribute('href')), $baseUrl);
			}

			foreach ($xpath->query('//link[@rel and @href]') as $link) {
				/** @var \DOMElement $link */
				$rels = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
				$href = trim($link->getAttribute('href'));
				if ($href === '' || preg_match('/^(data|javascript|blob):/i', $href)) {
					continue;
				}

				$isApple = (bool) array_intersect($rels, ['apple-touch-icon', 'apple-touch-icon-precomposed']);
				$isIcon = in_array('icon', $rels, true);
				if (!$isApple && !$isIcon) {
					continue; // u. a. mask-icon (einfarbige Safari-Pinned-Tab-Silhouette)
				}

				$size = 0;
				foreach (preg_split('/\s+/', strtolower($link->getAttribute('sizes')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
					if (preg_match('/^(\d+)x(\d+)$/', $token, $m)) {
						$size = max($size, (int) $m[1], (int) $m[2]);
					}
				}

				$type = strtolower(trim($link->getAttribute('type')));
				$path = strtolower((string) parse_url($href, PHP_URL_PATH));
				$isSvg = $type === 'image/svg+xml' || str_ends_with($path, '.svg');
				$isIco = $type === 'image/x-icon' || $type === 'image/vnd.microsoft.icon' || str_ends_with($path, '.ico');

				// Klasse dominiert (Apple > SVG > Bitmap > ICO), innerhalb der Klasse die Größe.
				$class = $isApple ? 4 : ($isSvg ? 3 : ($isIco ? 1 : 2));
				$score = $class * 100000 + min($size > 0 ? $size : 16, 99999);
				if ($score > $bestScore) {
					$bestScore = $score;
					$best = $this->normalizeUrl($href, $base);
				}
			}

			if ($best === null) {
				$tile = $xpath->query("//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='msapplication-tileimage']/@content")->item(0);
				$tileUrl = $tile ? trim($tile->nodeValue ?? '') : '';
				if ($tileUrl !== '' && !preg_match('/^(data|javascript|blob):/i', $tileUrl)) {
					$best = $this->normalizeUrl($tileUrl, $base);
				}
			}
		} catch (\Throwable $e) {
			$best = null;
		}

		$candidate = $best ?? $origin . '/favicon.ico';
		$scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https'], true) || strlen($candidate) > 2048
			|| filter_var($candidate, FILTER_VALIDATE_URL) === false) {
			return $origin . '/favicon.ico';
		}
		return $candidate;
	}

	/**
	 * Extract site name from HTML meta tags or URL
	 */
	private function extractSiteName(string $html, string $url): ?string {
		// Extract from domain
		$parsedUrl = parse_url($url);
		if (isset($parsedUrl['host'])) {
			return preg_replace('/^www\./i', '', $parsedUrl['host']);
		}

		return null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Pre-Readability HTML Normalization
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Normalise image+caption structures in raw HTML before Readability parses it.
	 *
	 * Domain-specific rules from <images><caption container-xpath="..." caption-xpath="..."/>
	 * in the per-domain content-filter XML are applied.
	 *
	 * For each matched container:
	 *   1. The <img> inside the container is found.
	 *   2. The caption text is extracted via caption-xpath (relative to container).
	 *   3. The whole container is replaced with a <figure><img><figcaption>…</figcaption></figure>.
	 *
	 * This ensures Readability sees and preserves proper figure+figcaption structures,
	 * which are then rendered correctly in the reader for ALL images in the article body.
	 * The hero image extraction also benefits because it searches for <figcaption> within <figure>.
	 */
	private function normalizeImageCaptions(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		try {
			$config = $this->loadDomainConfig($domain);
			if ($config === null || !isset($config->images->caption)) {
				return $html;
			}

			$prev = libxml_use_internal_errors(true);
			$dom  = new \DOMDocument('1.0', 'UTF-8');
			$dom->loadHTML(
				'<?xml encoding="utf-8" ?>' . $html,
				LIBXML_NOERROR | LIBXML_NOWARNING
			);
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
			$xpath = new \DOMXPath($dom);

			foreach ($config->images->caption as $rule) {
				$containerXpath = trim((string) ($rule['container-xpath'] ?? ''));
				$captionXpath   = trim((string) ($rule['caption-xpath'] ?? ''));
				if ($containerXpath === '' || $captionXpath === '') continue;

				$containerResult = @$xpath->query($containerXpath);
				if ($containerResult === false) {
					$this->logger->warning('content-filters: invalid images container-xpath skipped', [
						'xpath'  => $containerXpath,
						'domain' => $domain,
					]);
					$trace?->record('images', $rule, 0, 'Ungültiger XPath-Ausdruck');
					continue;
				}

				$containers = iterator_to_array($containerResult);
				// Gezählt wird der Container-Treffer, nicht die ersetzte Figure:
				// so unterscheidet die UI "XPath trifft nichts" von "XPath trifft,
				// aber im Container steckt kein <img>".
				$trace?->record('images', $rule, count($containers));

				foreach ($containers as $container) {
					if (!$container instanceof \DOMElement) continue;

					// Find the <img> inside the container
					$imgNodes = $xpath->query('.//img[@src or @data-src or @data-orig-src]', $container);
					if (!$imgNodes || $imgNodes->length === 0) continue;
					$img = $imgNodes->item(0);
					if (!$img instanceof \DOMElement) continue;

					// Bildquelle bevorzugt aus src, sonst data-src bzw. data-orig-src
					// (Lazy-Loading; letzteres z. B. bei WP-Rocket-artigem Lazy-Loading,
					// das die echte URL nur in data-orig-src ablegt).
					$imgSrc = trim($img->getAttribute('src'));
					if ($imgSrc === '' || str_starts_with($imgSrc, 'data:')) {
						$imgSrc = trim($img->getAttribute('data-src'));
					}
					if ($imgSrc === '' || str_starts_with($imgSrc, 'data:')) {
						$imgSrc = trim($img->getAttribute('data-orig-src'));
					}
					// Web-Component-Bilder (z.B. heise.de <a-img src="...echtes-foto.jpeg">)
					// wrappen intern ein <img> mit einer Base64/data:-Platzhalter-SVG als
					// src (Lazy-Loading-Fallback für JS-lose Clients) - die echte
					// Bild-URL steckt dann nur am <a-img>-Wrapper selbst. Ohne diesen
					// Fallback würde die Figure mit der Platzhalter-SVG statt des echten
					// Fotos gebaut, wodurch der spätere Hero-Bild-Abgleich (Step 12 in
					// extract(), imagesMatchForDedup()) nie träfe und die hier extrahierte
					// Caption verworfen würde.
					if ($imgSrc === '' || str_starts_with($imgSrc, 'data:')) {
						$carrier = $img->parentNode;
						while ($carrier instanceof \DOMElement) {
							$carrierSrc = trim($carrier->getAttribute('src'));
							if ($carrierSrc !== '' && !str_starts_with($carrierSrc, 'data:')) {
								$imgSrc = $carrierSrc;
								break;
							}
							$carrier = $carrier->parentNode;
						}
					}
					if ($imgSrc === '' || str_starts_with($imgSrc, 'data:')) continue;

					$img->setAttribute('src', $imgSrc);

					// Extract caption text
					$captionResult = @$xpath->query($captionXpath, $container);
					if ($captionResult === false) {
						$this->logger->warning('content-filters: invalid images caption-xpath skipped', [
							'xpath'  => $captionXpath,
							'domain' => $domain,
						]);
						continue;
					}
					// caption-xpath kann ein Union-Ausdruck sein (z.B.
					// ".//p/text()[not(parent::b)] | .//p/b[@class='credit']"),
					// der mehrere Knoten in Dokumentreihenfolge liefert – etwa
					// Fließtext-Textknoten UND ein separates Credit-Element.
					// item(0) allein hätte hier nur den ersten Treffer genommen
					// und den Rest (inkl. Credit) stillschweigend verworfen.
					$captionText = '';
					if ($captionResult->length > 0) {
						$parts = [];
						foreach ($captionResult as $node) {
							$text = trim($node->textContent);
							if ($text !== '') {
								$parts[] = $text;
							}
						}
						$captionText = trim(implode(' ', $parts));
					}

					// Build <figure><img ...><figcaption>…</figcaption></figure>
					// "merlin-content-figure" enthält "content" → matcht Readabilitys
					// okMaybeItsACandidate-Regex → Element überlebt den unlikelyCandidates-Pass.
					$figure = $dom->createElement('figure');
					$figure->setAttribute('class', 'merlin-content-figure');
					$figure->appendChild($img->cloneNode(true));
					if ($captionText !== '') {
						$figcaption = $dom->createElement('figcaption');
						$figcaption->setAttribute('class', 'merlin-content-figcaption');
						$figcaption->textContent = $captionText;
						$figure->appendChild($figcaption);
					}

					// Wenn ein Custom-Element-Ancestor existiert (Tag-Name enthält "-"),
					// wird dieser durch die figure ersetzt – Readability würde sonst den
					// gesamten Custom-Element-Baum (z.B. <a-lightbox>) verwerfen. Gleichzeitig
					// wird der äußerste <header>/<nav>/<aside>/<footer>-Vorfahre gemerkt
					// (z.B. heise.de's <header class="a-article-header">, das den kompletten
					// Hero-Bild-Block umschließt): Readability entfernt solche Layout-Tags
					// per grabArticle()-Scoring komplett aus dem Top-Kandidaten, bevor die
					// "content"-Klasse der Figure überhaupt greifen kann - "merlin-content-figure"
					// rettet das Element also nur, wenn es NICHT in so einem Vorfahren hängen bleibt.
					$replaceTarget  = $container;
					$hoistAncestor  = null;
					$ancestor = $container->parentNode;
					while ($ancestor instanceof \DOMElement && strtolower($ancestor->nodeName) !== 'body') {
						if (str_contains($ancestor->nodeName, '-')) {
							$replaceTarget = $ancestor;
						}
						if (in_array(strtolower($ancestor->nodeName), ['header', 'nav', 'aside', 'footer'], true)) {
							$hoistAncestor = $ancestor;
						}
						$ancestor = $ancestor->parentNode;
					}

					if ($replaceTarget->parentNode !== null) {
						$replaceTarget->parentNode->replaceChild($figure, $replaceTarget);
					}

					// Figure aus dem Layout-Element herausziehen und als dessen direktes
					// Geschwister-Element wieder einfügen, damit sie im Dokumentfluss
					// neben dem eigentlichen Artikeltext steht statt in einem von
					// Readability verworfenen Ast.
					if ($hoistAncestor !== null && $hoistAncestor->parentNode !== null) {
						$contentContainer = $this->findLikelyContentContainer($hoistAncestor);
						if ($contentContainer !== null) {
							$contentContainer->insertBefore($figure, $contentContainer->firstChild);
						} else {
							$hoistAncestor->parentNode->insertBefore($figure, $hoistAncestor->nextSibling);
						}
					}
				}
			}

			return $dom->saveHTML() ?: $html;
		} catch (\Throwable) {
			return $html; // Never break extraction
		}
	}

	/**
	 * Findet ausgehend von einem verworfenen Layout-Element (siehe
	 * normalizeImageCaptions()' $hoistAncestor) den wahrscheinlichen
	 * Artikeltext-Container, in den die gerettete Hero-Figure gehängt werden
	 * kann, damit sie im selben Ast wie der von Readability ausgewählte
	 * Top-Kandidat landet.
	 *
	 * Domain-unabhängig: startet beim nächsten Geschwister-Container des
	 * Layout-Elements (bzw. dessen Elternelements) und steigt darin
	 * wiederholt in das Kind mit dem meisten Textinhalt ab, dessen
	 * class/id Readabilitys eigenem POSITIVE-Muster
	 * (article|body|content|entry|main|page|post|text|blog|story) entspricht -
	 * bei geschachtelten Wrapper-Divs (z.B. "content-container" >
	 * "article-content") konvergiert das zuverlässig auf den innersten
	 * Wrapper direkt um die eigentlichen Absätze, ohne domainspezifische
	 * Selektoren zu benötigen.
	 */
	private function findLikelyContentContainer(\DOMElement $hoistAncestor): ?\DOMElement {
		// Erst das eigene nächste Geschwister-Element des Layout-Ankers
		// probieren (z.B. <header>'s Geschwister-<div> mit dem Artikeltext) -
		// nur wenn der Anker selbst keins hat (z.B. letztes Kind seines
		// Elternelements), auf das Geschwister des Elternelements ausweichen.
		// Umgekehrt (Eltern-Geschwister zuerst) griff bei verschachtelten
		// <article><header>…</header><div>Text</div></article>-Strukturen
		// (z.B. tagesspiegel.de) daneben: dort ist <article>'s eigenes
		// nächstes Geschwister ein unverwandtes Layout-Element (z.B. ein
		// Related-Content-Widget), nicht der Textcontainer.
		$start = $hoistAncestor->nextElementSibling
			?? ($hoistAncestor->parentNode instanceof \DOMElement ? $hoistAncestor->parentNode->nextElementSibling : null);
		if (!$start instanceof \DOMElement) {
			return null;
		}

		$target  = $start;
		$matched = false;
		for ($depth = 0; $depth < 6; $depth++) {
			$best    = null;
			$bestLen = 0;
			foreach ($target->childNodes as $child) {
				if (!$child instanceof \DOMElement) continue;
				if (in_array(strtolower($child->nodeName), ['script', 'style', 'nav', 'header', 'footer', 'aside'], true)) continue;
				$classAndId = strtolower($child->getAttribute('class') . ' ' . $child->getAttribute('id'));
				if (!preg_match('/article|body|content|entry|main|page|post|text|blog|story/i', $classAndId)) continue;
				$len = strlen(trim($child->textContent));
				if ($len > $bestLen) {
					$bestLen = $len;
					$best    = $child;
				}
			}
			if ($best === null) break;
			$target  = $best;
			$matched = true;
		}

		// Kein einziger content-klassifizierter Nachfahre gefunden: $start war
		// eine reine Vermutung ohne Bestätigung (z.B. ein unrelated Widget) -
		// lieber null zurückgeben, damit die Aufrufer-Fallback-Logik greift
		// (Figure direkt neben $hoistAncestor einfügen), statt sie blind in
		// $start hineinzuhängen.
		return $matched ? $target : null;
	}

	/**
	 * Replace every <hr> with a marker <div> before Readability parses the HTML.
	 *
	 * fivefilters/readability.php's isElementWithoutContent() (used by
	 * grabArticle()'s cleanup pass) treats <hr> the same as <br>: a <div>/
	 * <section>/<header>/<h1>-<h6> whose only element children are <hr>/<br>
	 * and that has no text of its own counts as empty and gets removed -
	 * along with the <hr> inside it. That silently drops scene-break markers
	 * on sites that wrap a bare <hr> in an otherwise empty container (e.g.
	 * WordPress' "<div class="separator"><hr/></div>").
	 *
	 * The placeholder carries "merlin-content-hr" (needs "content" to match
	 * okMaybeItsACandidate() and survive Readability's unlikelyCandidates
	 * pass, same reasoning as merlin-content-figure in normalizeImageCaptions())
	 * plus a non-whitespace marker character so neither the placeholder itself
	 * nor an ancestor that would otherwise contain no text is seen as empty.
	 * restoreHorizontalRules() converts survivors back to <hr> after Readability.
	 */
	private function protectHorizontalRules(string $html): string {
		if (trim($html) === '' || !str_contains(strtolower($html), '<hr')) {
			return $html;
		}
		try {
			$prev = libxml_use_internal_errors(true);
			$dom  = new \DOMDocument('1.0', 'UTF-8');
			$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
			libxml_clear_errors();
			libxml_use_internal_errors($prev);

			$hrs = iterator_to_array($dom->getElementsByTagName('hr'));
			if ($hrs === []) {
				return $html;
			}

			foreach ($hrs as $hr) {
				if (!$hr instanceof \DOMElement || $hr->parentNode === null) {
					continue;
				}
				$placeholder = $dom->createElement('div');
				$placeholder->setAttribute('class', 'merlin-content-hr');
				// U+2063 INVISIBLE SEPARATOR: not stripped by jsTrim()/trim(), so it
				// keeps textContent from being considered empty, but renders as
				// nothing if it were ever to leak through (it never does -
				// restoreHorizontalRules() replaces the whole element).
				$placeholder->appendChild($dom->createTextNode("\u{2063}"));
				$hr->parentNode->replaceChild($placeholder, $hr);
			}

			return $dom->saveHTML() ?: $html;
		} catch (\Throwable) {
			return $html; // Never break extraction
		}
	}

	/**
	 * Convert protectHorizontalRules()'s placeholder <div>s back into real
	 * <hr> elements. Runs on the Readability-extracted content, before
	 * cleanHtml()/sanitizeHtml() (both of which would otherwise happily keep
	 * the placeholder as an inert <div class="merlin-content-hr">).
	 */
	private function restoreHorizontalRules(string $html): string {
		if (trim($html) === '' || !str_contains($html, 'merlin-content-hr')) {
			return $html;
		}
		try {
			$prev = libxml_use_internal_errors(true);
			$dom  = new \DOMDocument('1.0', 'UTF-8');
			$dom->loadHTML(
				'<?xml encoding="utf-8" ?><div data-merlin-hr-root="1">' . $html . '</div>',
				LIBXML_NOERROR | LIBXML_NOWARNING
			);
			libxml_clear_errors();
			libxml_use_internal_errors($prev);

			$xpath = new \DOMXPath($dom);
			$root  = $xpath->query('//div[@data-merlin-hr-root="1"]')->item(0);
			if ($root === null) {
				return $html;
			}

			$placeholders = $xpath->query(
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' merlin-content-hr ')]"
			);
			foreach (iterator_to_array($placeholders) as $placeholder) {
				if (!$placeholder instanceof \DOMElement || $placeholder->parentNode === null) {
					continue;
				}
				$placeholder->parentNode->replaceChild($dom->createElement('hr'), $placeholder);
			}

			$out = '';
			foreach ($root->childNodes as $child) {
				$serialized = $dom->saveHTML($child);
				if ($serialized !== false) {
					$out .= $serialized;
				}
			}
			return $out !== '' ? $out : $html;
		} catch (\Throwable) {
			return $html; // Never break extraction
		}
	}

	/**
	 * Normalise quote structures in raw HTML before Readability parses it.
	 *
	 * Three passes:
	 *   1. Domain-specific rules: <quotes><quote container-xpath="..." text-xpath="..." author-xpath="..."/></quotes>
	 *      in the per-domain content-filter XML are applied first.
	 *   2. Standard <blockquote> elements receive class="merlin-quote" and their
	 *      bare inline content is wrapped in <p class="merlin-quote__text">.
	 *   3. <q> elements receive class="merlin-quote-inline" for CSS styling.
	 *
	 * keepClasses=true on the Readability config ensures all added classes survive.
	 */
	private function normalizeQuotes(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		try {
			$prev = libxml_use_internal_errors(true);
			$dom  = new \DOMDocument('1.0', 'UTF-8');
			$dom->loadHTML(
				'<?xml encoding="utf-8" ?>' . $html, 
				LIBXML_NOERROR | LIBXML_NOWARNING
			);
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
			$xpath = new \DOMXPath($dom);

			// ── Pass 1: domain-specific quote rules ────────────────────────────
			$config = $this->loadDomainConfig($domain);
			if ($config !== null && isset($config->quotes->quote)) {
				foreach ($config->quotes->quote as $rule) {
					$containerXpath = trim((string) ($rule['container-xpath'] ?? ''));
					$textXpath      = trim((string) ($rule['text-xpath'] ?? ''));
					$authorXpath    = trim((string) ($rule['author-xpath'] ?? ''));
					if ($containerXpath === '') continue;

					$containerResult = @$xpath->query($containerXpath);
					if ($containerResult === false) {
						$this->logger->warning('content-filters: invalid quotes container-xpath skipped', [
							'xpath'  => $containerXpath,
							'domain' => $domain,
						]);
						$trace?->record('quotes', $rule, 0, 'Ungültiger XPath-Ausdruck');
						continue;
					}

					$containers = iterator_to_array($containerResult);
					$trace?->record('quotes', $rule, count($containers));

					foreach ($containers as $container) {
						// Alle Treffer des text-xpath werden je ein Absatz (Mehrabsatz-Zitate).
						$textEls = [];
						if ($textXpath !== '') {
							$textResult = $xpath->query($textXpath, $container);
							if ($textResult !== false) {
								foreach ($textResult as $node) {
									if ($node instanceof \DOMElement) $textEls[] = $node;
								}
							}
						} elseif ($container instanceof \DOMElement) {
							$textEls[] = $container;
						}
						if ($textEls === []) continue;

						$authorEl = null;
						if ($authorXpath !== '') {
							$authorResult = $xpath->query($authorXpath, $container);
							$authorEl = ($authorResult !== false) ? $authorResult->item(0) : null;
						}

						$bq = $this->buildReaderQuoteNode(
							$dom,
							$textEls,
							$authorEl instanceof \DOMElement ? $authorEl : null
						);
						$container->parentNode->replaceChild($bq, $container);
					}
				}
			}

			// ── Pass 2: standard <blockquote> normalisation ────────────────────
			$blockquotes = iterator_to_array($xpath->query('//blockquote') ?: []);
			foreach ($blockquotes as $bq) {
				if (!$bq instanceof \DOMElement) continue;
				$class = $bq->getAttribute('class');
				if (str_contains($class, 'merlin-quote')) continue; // already processed

				$bq->setAttribute('class', trim('merlin-quote ' . $class));
				$this->normalizeBlockquoteAttribution($dom, $bq);
			}

			// ── Pass 3: <q> inline quotes ──────────────────────────────────────
			$qEls = iterator_to_array($xpath->query('//q') ?: []);
			foreach ($qEls as $q) {
				if (!$q instanceof \DOMElement) continue;
				$existing = $q->getAttribute('class');
				if (!str_contains($existing, 'merlin-quote-inline')) {
					$q->setAttribute('class', trim('merlin-quote-inline ' . $existing));
				}
			}

			/* Return body content – same format Readability expects.
			$body = $dom->getElementsByTagName('body')->item(0);
			if (!$body) return $html;
			$out = '';
			foreach ($body->childNodes as $child) {
				$out .= $dom->saveHTML($child);
			}*/
			//return $out ?: $html;
			$out = $dom->saveHTML();
			return $out ?: $html;
		} catch (\Throwable $e) {
			return $html; // Never break extraction
		}
	}

	/**
	 * Build a <blockquote class="merlin-quote"> node from extracted quote elements.
	 *
	 * @param \DOMElement[] $textEls one <p class="merlin-quote__text"> per element
	 */
	private function buildReaderQuoteNode(
		\DOMDocument $dom,
		array        $textEls,
		?\DOMElement $authorEl
	): \DOMElement {
		$blockquote = $dom->createElement('blockquote');
		$blockquote->setAttribute('class', 'merlin-quote');

		// Autor vorab auslesen und - falls er im Zitattext steckt (kein
		// text-xpath) - aus dem DOM lösen, damit er nicht doppelt erscheint.
		$authorText = null;
		if ($authorEl !== null) {
			$authorText = trim((string) preg_replace('/\s+/u', ' ', $authorEl->textContent));
			foreach ($textEls as $textEl) {
				if ($authorEl !== $textEl && $authorEl->parentNode !== null && $this->isDescendantOf($authorEl, $textEl)) {
					$authorEl->parentNode->removeChild($authorEl);
					break;
				}
			}
		}

		foreach ($textEls as $textEl) {
			if ($textEl === $authorEl) continue;
			// Enthält der Text schon Absätze (Container ohne text-xpath), diese direkt übernehmen.
			if ($textEl->getElementsByTagName('p')->length > 0) {
				foreach (iterator_to_array($textEl->childNodes) as $child) {
					$blockquote->appendChild($child->cloneNode(true));
				}
				continue;
			}
			$p = $dom->createElement('p');
			$p->setAttribute('class', 'merlin-quote__text');
			foreach (iterator_to_array($textEl->childNodes) as $child) {
				$p->appendChild($child->cloneNode(true));
			}
			$blockquote->appendChild($p);
		}

		if ($authorText !== null && $authorText !== '') {
			$cite = $dom->createElement('cite');
			$cite->setAttribute('class', 'merlin-quote__source');
			$cite->appendChild($dom->createTextNode($authorText));
			$blockquote->appendChild($cite);
		}

		return $blockquote;
	}

	private function isDescendantOf(\DOMNode $node, \DOMNode $ancestor): bool {
		for ($n = $node->parentNode; $n !== null; $n = $n->parentNode) {
			if ($n === $ancestor) return true;
		}
		return false;
	}

	/**
	 * Bringt die Quellenangabe eines Standard-<blockquote> in die Form
	 * <blockquote><p class="merlin-quote__text">…</p><cite class="merlin-quote__source">…</cite></blockquote>.
	 *
	 * Erkannt werden:
	 *   - <cite>/<footer>/<address> als Kind (wird zur Quelle)
	 *   - loser Text bzw. Inline-Elemente NACH dem letzten Block-Kind
	 *     (z. B. WordPress-Pullquote: <p>Zitat</p>Name&emsp;<em>Funktion</em>)
	 *   - ein direkt folgendes <p>, das ausschließlich ein <cite> enthält
	 * Reiner Inline-Inhalt wird in <p class="merlin-quote__text"> gewickelt.
	 * Ein folgender normaler Absatz bleibt unberührt.
	 */
	private function normalizeBlockquoteAttribution(\DOMDocument $dom, \DOMElement $bq): void {
		// Social-Embeds (Instagram, X, Bluesky, TikTok) behalten ihr Original-Markup.
		if (preg_match('/\b(instagram-media|twitter-tweet|bluesky-embed|tiktok-embed)\b/', $bq->getAttribute('class'))) {
			return;
		}
		$blockTags  = ['p', 'div', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
		$sourceTags = ['cite', 'footer', 'address'];

		// <p><cite>…</cite></p> direkt hinter dem blockquote hereinholen.
		$next = $bq->nextSibling;
		while ($next instanceof \DOMText && trim($next->textContent) === '') {
			$next = $next->nextSibling;
		}
		if ($next instanceof \DOMElement && strtolower($next->nodeName) === 'p') {
			$els = [];
			$hasText = false;
			foreach ($next->childNodes as $c) {
				if ($c instanceof \DOMElement) $els[] = $c;
				elseif (trim($c->textContent) !== '') $hasText = true;
			}
			if (!$hasText && count($els) === 1 && strtolower($els[0]->nodeName) === 'cite') {
				$bq->appendChild($els[0]);
				$next->parentNode->removeChild($next);
			}
		}

		$sources   = [];
		$lastBlock = null;
		foreach (iterator_to_array($bq->childNodes) as $child) {
			if (!$child instanceof \DOMElement) continue;
			$name = strtolower($child->nodeName);
			if (in_array($name, $sourceTags, true)) {
				$sources[] = $child;
			} elseif (in_array($name, $blockTags, true)) {
				$lastBlock = $child;
			}
		}

		if ($lastBlock === null) {
			// Nur Inline-Inhalt: Quellen-Elemente beiseite, Rest in <p> wickeln.
			foreach ($sources as $src) {
				$bq->removeChild($src);
			}
			$p = $dom->createElement('p');
			$p->setAttribute('class', 'merlin-quote__text');
			foreach (iterator_to_array($bq->childNodes) as $child) {
				$p->appendChild($child);
			}
			$bq->appendChild($p);
			foreach ($sources as $src) {
				$this->markQuoteSource($dom, $bq, $src);
			}
			return;
		}

		foreach ($sources as $src) {
			$this->markQuoteSource($dom, $bq, $src);
		}

		// Loser Inhalt nach dem letzten Block-Kind -> Quelle (nur wenn noch keine existiert).
		if ($sources !== []) return;
		$tail = [];
		for ($n = $lastBlock->nextSibling; $n !== null; $n = $n->nextSibling) {
			if ($n instanceof \DOMElement && in_array(strtolower($n->nodeName), $blockTags, true)) return;
			$tail[] = $n;
		}
		$hasContent = false;
		foreach ($tail as $n) {
			if (trim(str_replace("\u{00A0}", ' ', $n->textContent)) !== '') $hasContent = true;
		}
		if (!$hasContent) return;

		$cite = $dom->createElement('cite');
		$cite->setAttribute('class', 'merlin-quote__source');
		foreach ($tail as $n) {
			$cite->appendChild($n);
		}
		$bq->appendChild($cite);
		$this->tidyQuoteSource($cite);
	}

	/** Setzt Tag/Klasse eines vorhandenen Quellen-Elements auf <cite class="merlin-quote__source">. */
	private function markQuoteSource(\DOMDocument $dom, \DOMElement $bq, \DOMElement $src): void {
		if (strtolower($src->nodeName) !== 'cite') {
			$cite = $dom->createElement('cite');
			while ($src->firstChild) {
				$cite->appendChild($src->firstChild);
			}
			if ($src->parentNode !== null) {
				$src->parentNode->removeChild($src);
			}
			$src = $cite;
		}
		$src->setAttribute('class', trim('merlin-quote__source ' . $src->getAttribute('class')));
		$bq->appendChild($src);
		$this->tidyQuoteSource($src);
	}

	/**
	 * Entfernt führende Trenner (—, –, -, Komma, Leerräume inkl. Geviert/NBSP) und
	 * ersetzt Geviert-Abstände zwischen Name und Funktion durch ", ".
	 */
	private function tidyQuoteSource(\DOMElement $cite): void {
		$sep   = '[\s\x{00A0}\x{2002}\x{2003}\x{2009}\x{2013}\x{2014}\x{2015}\-,:|~]+';
		$first = true;
		foreach (iterator_to_array($cite->childNodes) as $n) {
			if (!$n instanceof \DOMText) { $first = false; continue; }
			$t = $n->data;
			if ($first) {
				$t = (string) preg_replace('/^' . $sep . '/u', '', $t);
			}
			if ($n->nextSibling instanceof \DOMElement) {
				$t = (string) preg_replace('/\s*[\x{00A0}\x{2002}\x{2003}]+\s*$/u', ', ', $t);
			}
			$n->data = $t;
			if (trim($t) !== '') $first = false;
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Per-domain filter & metadata system
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Normalise a URL host to a bare domain name for config-file lookup.
	 * Strips www. prefix; no further subdomain stripping (exact match semantics).
	 *
	 * Delegiert ans ContentFilterRepository, weil die Admin-UI dieselbe
	 * Normalisierung braucht (Prüfung, ob eine Test-URL zum bearbeiteten Filter
	 * gehört) und zwei Kopien dieser Regel auseinanderlaufen würden.
	 */
	private function normalizeDomain(string $url): string {
		return $this->contentFilters->normalizeUrlDomain($url);
	}

	/**
	 * Load the per-domain config for $domain.
	 * Returns null when neither a bundled, admin- noch user-erstellter Filter existiert.
	 *
	 * Warum nur noch eine Delegation: Die Config kann aus drei Quellen kommen –
	 * dem mitgelieferten Filter in content-filters/, einem vom Admin über die
	 * Weboberfläche angelegten Filter und einem privaten Override des
	 * aufrufenden Nutzers ($currentUserId, siehe dort). Das Zusammenführen aller
	 * drei Quellen und das Caching übernimmt ContentFilterRepository; alle acht
	 * Aufrufstellen dieser Methode sehen weiterhin ein einzelnes
	 * SimpleXMLElement und bleiben unverändert.
	 */
	private function loadDomainConfig(string $domain): ?\SimpleXMLElement {
		return $this->contentFilters->getMerged($domain, $this->currentUserId);
	}

	/**
	 * Entfernt alle <script>- und <style>-Elemente (inkl. Inhalt) aus einem HTML-String,
	 * bevor der DOM-Parser ihn sieht.
	 *
	 * Ersetzt eine frühere regex-basierte Variante
	 * (`preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/si', ...)`), die bei sehr
	 * großen einzelnen <script>-Blöcken (reale Seiten packen dort ganze Drittanbieter-
	 * Bundles hinein, z. B. Outbrain-Bootstrap-Code mit >200 KB in einem einzigen Tag)
	 * PCRE an sein `pcre.backtrack_limit` bringen konnte. `preg_replace_callback()` gibt
	 * dann `null` zurück - und das dortige `?? $html` fing das lautlos ab, indem es das
	 * KOMPLETTE, ungefilterte Original-HTML durchreichte: nicht nur der eine große Block,
	 * sondern ALLE <script>-Tags im Dokument blieben stehen und ihr Inhalt (z. B. der
	 * "Auch interessant"-Platzhaltertext aus so einem Bootstrap-Script) konnte von
	 * Readability als Artikeltext aufgegriffen werden - ohne jede Fehlermeldung im Log.
	 *
	 * Diese Methode scannt stattdessen manuell per strpos()/stripos() von Tag zu Tag -
	 * lineares Verhalten unabhängig von der Blockgröße, kein Backtracking, kein PCRE-Limit.
	 * Ein Tag ohne schließendes Gegenstück (kaputtes HTML) wird geloggt und bis zum
	 * Dokumentende entfernt, statt endlos zu suchen oder unverändert stehen zu bleiben.
	 */
	private function stripScriptAndStyleTags(string $html): string {
		$out    = '';
		$pos    = 0;
		$length = strlen($html);

		while ($pos < $length) {
			// Nächstes <script oder <style suchen (case-insensitiv, mit Wortgrenze
			// über den nachfolgenden Whitespace/'>' sichergestellt).
			if (!preg_match('/<(script|style)\b/i', $html, $tagMatch, PREG_OFFSET_CAPTURE, $pos)) {
				$out .= substr($html, $pos);
				break;
			}

			// $tagMatch[0] ist der Gesamttreffer ("<script"/"<style") samt Offset des
			// führenden '<' - $tagMatch[1] (die Capture-Gruppe) beginnt dagegen erst beim
			// Tag-Namen selbst, ein Offset von dort würde das '<' im "davor"-Teil zurücklassen.
			[, $tagStart]  = $tagMatch[0];
			$tagName       = strtolower($tagMatch[1][0]);
			$out          .= substr($html, $pos, $tagStart - $pos);

			$openTagEnd = strpos($html, '>', $tagStart);
			if ($openTagEnd === false) {
				// Kaputtes Markup (Tag ohne '>') - Rest des Dokuments verwerfen, nicht
				// endlos weitersuchen.
				$this->logger->warning('stripScriptAndStyleTags: unclosed opening tag, truncating', ['tag' => $tagName]);
				break;
			}
			$openTagAttrs = substr($html, $tagStart, $openTagEnd - $tagStart + 1);

			$closeTag = '</' . $tagName;
			$closeTagStart = stripos($html, $closeTag, $openTagEnd + 1);
			if ($closeTagStart === false) {
				$this->logger->warning('stripScriptAndStyleTags: unclosed tag, truncating rest of document', ['tag' => $tagName]);
				$pos = $length;
				break;
			}
			$content     = substr($html, $openTagEnd + 1, $closeTagStart - $openTagEnd - 1);
			$closeTagEnd = strpos($html, '>', $closeTagStart);
			$pos         = $closeTagEnd === false ? $length : $closeTagEnd + 1;

			if ($tagName === 'style') {
				continue; // <style> wird immer komplett entfernt.
			}

			// <script>: leer lassen, außer es ist einer der erlaubten Widget-Loader
			// (Instagram/X/Bluesky/TikTok, siehe isAllowedWidgetScriptSrc()) - siehe
			// Kommentar oben in applyRemoveRules().
			if (trim($content) !== '') {
				continue;
			}
			if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $openTagAttrs, $srcMatch)) {
				continue;
			}
			$src = html_entity_decode($srcMatch[2], ENT_QUOTES, 'UTF-8');
			if ($this->isAllowedWidgetScriptSrc($src)) {
				$out .= $openTagAttrs . $content . substr($html, $closeTagStart, ($closeTagEnd === false ? $length : $closeTagEnd + 1) - $closeTagStart);
			}
		}

		return $out;
	}

	/**
	 * Apply a list of SimpleXMLElement <remove> nodes to an HTML string via DOM.
	 * Shared helper used by applyPreFilters() and applyPostFilters().
	 *
	 * @param \SimpleXMLElement[] $rules
	 * @param bool $returnFullDocument  true  → vollständiges HTML-Dokument zurückgeben (Pre-Filter, Readability erwartet das)
	 *                                  false → nur Body-Children zurückgeben (Post-Filter, Readability-Extrakt ist ein Fragment)
	 * @param string|null $traceSection Sektionsname für den Trace (null = keine Diagnose)
	 */
	private function applyRemoveRules(
		string $html,
		array $rules,
		string $context = '',
		bool $returnFullDocument = false,
		?ContentFilterTrace $trace = null,
		?string $traceSection = null
	): string {
		if (empty($html)) {
			return $html;
		}

		// <script>- und <style>-Tags (inkl. Inhalt) entfernen, bevor der DOM-Parser den
		// String verarbeitet. So werden auch komplexe JS-Inhalte mit <, >, & oder --
		// zuverlässig entfernt, ohne dass sie den DOM-Baum beschädigen können. Ausnahme wie
		// bei cleanHtml(): die offiziellen Widget-Loader von Instagram/X/Bluesky/TikTok (siehe
		// isAllowedWidgetScriptSrc()) überleben auch diesen - zeitlich früheren - Schritt,
		// sonst würde er dasselbe Script wieder entfernen, das cleanHtml()/sanitizeHtml()
		// weiter unten bewusst durchlassen (applyPostFilters() läuft VOR cleanHtml()).
		$html = $this->stripScriptAndStyleTags($html);

		$prev = libxml_use_internal_errors(true);
		$dom = new \DOMDocument('1.0', 'UTF-8');
		// XML-PI für Encoding-Hint: libxml wertet ihn aus, fügt ihn aber NICHT in den DOM-Baum ein.
		// LIBXML_NOENT absichtlich weggelassen: es würde &amp; → & usw. konvertieren und beim
		// Zurückserializieren zu ungültigem HTML führen.
		// LIBXML_NOBLANKS absichtlich weggelassen: es entfernt Whitespace-Text-Nodes und verändert Layout.
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . 
			$html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath    = new \DOMXPath($dom);
		$toRemove = [];

		// Je Regel abfragen statt die Regeln vorher in drei Listen nach Typ zu
		// flachzuklopfen: nur so lässt sich die Trefferzahl der EINZELNEN Regel
		// erfassen, die die Admin-UI anzeigt. Die entfernte Knotenmenge ist
		// identisch – alle Abfragen laufen weiterhin vollständig, bevor der erste
		// Knoten entfernt wird (sonst würden spätere Regeln auf einem bereits
		// veränderten Baum arbeiten).
		foreach ($rules as $rule) {
			$error   = null;
			$matches = 0;

			if (isset($rule['id'])) {
				$esc    = str_replace('"', '\\"', (string) $rule['id']);
				$result = $xpath->query('//*[@id="' . $esc . '"]');
			} elseif (isset($rule['class'])) {
				// Wortgrenzen-Match: verhindert, dass "foo" auch "foobar" oder "prefix-foo" trifft.
				$esc    = str_replace("'", "", (string) $rule['class']); // einfache Anführungszeichen im Klassenname sind extrem selten
				$result = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $esc . " ')]");
			} elseif (isset($rule['xpath'])) {
				$expr = trim((string) $rule['xpath']);
				if ($expr === '') {
					continue;
				}
				$result = @$xpath->query($expr);
				if ($result === false) {
					$this->logger->warning('content-filters: invalid XPath skipped', [
						'xpath'   => $expr,
						'context' => $context,
					]);
					$error = 'Ungültiger XPath-Ausdruck';
				}
			} else {
				continue;
			}

			if ($result === false) {
				// Auch die id-/class-Zweige können scheitern: XPath kennt keine
				// Backslash-Escapes, ein Anführungszeichen im id-Wert erzeugt also
				// einen ungültigen Ausdruck. Für die Diagnose in der Admin-UI ist
				// "fehlerhaft" die richtige Auskunft, nicht "0 Treffer".
				$error ??= 'Regel konnte nicht ausgewertet werden';
			} else {
				foreach ($result as $node) {
					$toRemove[] = $node;
					$matches++;
				}
			}

			if ($trace !== null && $traceSection !== null) {
				$trace->record($traceSection, $rule, $matches, $error);
			}
		}

		foreach ($toRemove as $node) {
			if ($node->parentNode !== null) {
				$node->parentNode->removeChild($node);
			}
		}

		// Pre-Filter: vollständiges Dokument zurückgeben, damit Readability damit arbeiten kann.
		if ($returnFullDocument) {
			$out = $dom->saveHTML();
			if ($out === false || $out === '') {
				return $html;
			}
			// Die XML-PI (<?xml encoding="utf-8" ?) kann im Output auftauchen — rausstreifen,
			// damit Readability kein ungültiges Präfix sieht.
			//$out = preg_replace('/^<\?xml[^?]*\>\s*/i', '', $out) ?? $out;
			$out = substr($out, strpos($out, '<html'));//
			return $out;
		}

		// Post-Filter: nur Body-Children zurückgeben — saveHTML() ohne Argument würde das komplette
		// Dokument (<!DOCTYPE>, <html>, <head>, <body>) zurückgeben und so Fragmente zerschießen.
		$body = $dom->getElementsByTagName('body')->item(0);
		if ($body !== null) {
			$out = '';
			foreach ($body->childNodes as $child) {
				$serialized = $dom->saveHTML($child);
				if ($serialized !== false) {
					$out .= $serialized;
				}
			}
			if ($out === '') {
				return $html;
			}
			return $out;
		}

		$out = $dom->saveHTML();
		if ($out === false || $out === '') {
			return $html;
		}
		return $out;
	}

	/**
	 * Apply <pre-filter> remove rules from the per-domain config.
	 * Runs on the raw fetched HTML BEFORE Readability.
	 */
	private function applyPreFilters(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		$config = $this->loadDomainConfig($domain);
		if ($config === null) {
			return $html;
		}
		$rules = $config->xpath('pre-filter/remove') ?: [];
		// returnFullDocument=true: Readability braucht ein vollständiges HTML-Dokument als Input.
		return $this->applyRemoveRules($html, $rules, $domain, true, $trace, 'pre-filter');
	}

	/**
	 * Apply <post-filter> remove rules to the Readability-extracted HTML.
	 * Runs AFTER Readability, on the extracted content DOM.
	 */
	private function applyPostFilters(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		if (empty($html)) {
			return $html;
		}
		$config = $this->loadDomainConfig($domain);
		if ($config === null) {
			return $html;
		}
		$rules = $config->xpath('post-filter/remove') ?: [];
		return $this->applyRemoveRules($html, $rules, $domain, false, $trace, 'post-filter');
	}

	/**
	 * Apply <pre-filter><infobox> rules: add the CSS class 'merlin-infobox' to
	 * matched elements BEFORE Readability runs, so the class survives parsing.
	 *
	 * Supported attributes (identical to <remove>):
	 *   <infobox id="element-id" />
	 *   <infobox class="teilstring" />
	 *   <infobox xpath="//div[@class='info-box']" />
	 *
	 * Returns a full HTML document (same contract as applyPreFilters).
	 */
	private function applyInfoboxMarkers(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		$config = $this->loadDomainConfig($domain);
		if ($config === null) {
			return $html;
		}
		$rules = $config->xpath('pre-filter/infobox') ?: [];
		if (empty($rules)) {
			return $html;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->encoding = 'UTF-8';
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath  = new \DOMXPath($dom);
		$toMark = [];

		foreach ($rules as $rule) {
			if (isset($rule['id'])) {
				$esc  = str_replace('"', '\\"', (string) $rule['id']);
				$expr = '//*[@id="' . $esc . '"]';
			} elseif (isset($rule['class'])) {
				$esc  = str_replace("'", '', (string) $rule['class']);
				$expr = "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $esc . " ')]";
			} elseif (isset($rule['xpath'])) {
				$expr = trim((string) $rule['xpath']);
				if ($expr === '') {
					continue;
				}
			} else {
				continue;
			}

			$result  = @$xpath->query($expr);
			$matches = 0;
			if ($result === false) {
				$this->logger->warning('content-filters: invalid infobox XPath skipped', [
					'xpath'   => $expr,
					'context' => $domain,
				]);
				$trace?->record('pre-filter', $rule, 0, 'Ungültiger XPath-Ausdruck');
				continue;
			}
			foreach ($result as $node) {
				$toMark[] = $node;
				$matches++;
			}
			$trace?->record('pre-filter', $rule, $matches);
		}

		// Tags, die Readability::_clean() in _prepArticle() bedingungslos aus dem
		// Artikel entfernt – unabhängig von Klasse oder Score (siehe
		// Readability.php: $this->_clean($article, 'aside') etc.). Ein Infokasten
		// steckt auf vielen Seiten genau in so einem <aside>; die Klasse
		// merlin-infobox allein würde ihn also NICHT vor dem Verwerfen retten,
		// wie es bei den unlikelyCandidates-Regex (siehe okMaybeItsACandidate an
		// anderer Stelle) der Fall ist. Solche Elemente werden daher zusätzlich
		// auf einen unbedenklichen Tag (<div>) umgetagged, bevor Readability
		// läuft. CSS für merlin-infobox greift rein über die Klasse, nicht über
		// den Tag-Namen (siehe ArticleReader.vue), das Umtaggen ist also optisch
		// folgenlos.
		$unsafeTags = ['aside', 'footer'];

		foreach ($toMark as $node) {
			if (!($node instanceof \DOMElement)) {
				continue;
			}
			$existing = $node->getAttribute('class');
			$classes  = preg_split('/\s+/', trim($existing), -1, PREG_SPLIT_NO_EMPTY);
			if (!in_array('merlin-infobox', $classes, true)) {
				$node->setAttribute('class', trim($existing . ' merlin-infobox'));
			}

			if (in_array(strtolower($node->nodeName), $unsafeTags, true) && $node->parentNode !== null) {
				$replacement = $dom->createElement('div');
				foreach (iterator_to_array($node->attributes) as $attr) {
					$replacement->setAttribute($attr->nodeName, $attr->nodeValue);
				}
				while ($node->firstChild !== null) {
					$replacement->appendChild($node->firstChild);
				}
				$node->parentNode->replaceChild($replacement, $node);
			}
		}

		$out = $dom->saveHTML();
		if ($out === false || $out === '') {
			return $html;
		}
		$out = preg_replace('/^<\?xml[^?]*\?>\s*/i', '', $out) ?? $out;
		return $out;
	}

	/**
	 * Apply <pre-filter><saveElements> rules: add a specified CSS class to
	 * elements matched by XPath BEFORE Readability runs, so the class survives.
	 *
	 * XML syntax (inside <pre-filter>):
	 *   <saveElements xpath="//aside[@data-type='infobox']" class="merlin-sidebar" />
	 *
	 * Multiple rules per domain are supported; each rule requires both
	 * xpath and class attributes. Invalid XPaths are logged and skipped.
	 *
	 * Returns a full HTML document (same contract as applyPreFilters).
	 */
	private function applyClassMarkers(string $html, string $domain, ?ContentFilterTrace $trace = null): string {
		$config = $this->loadDomainConfig($domain);
		if ($config === null) {
			return $html;
		}
		$rules = $config->xpath('pre-filter/saveElements') ?: [];
		if (empty($rules)) {
			return $html;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->encoding = 'UTF-8';
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath = new \DOMXPath($dom);

		foreach ($rules as $rule) {
			$xpathExpr = trim((string) ($rule['xpath'] ?? ''));
			$class     = trim((string) ($rule['class'] ?? ''));

			if ($xpathExpr === '' || $class === '') {
				continue;
			}

			$result = @$xpath->query($xpathExpr);
			if ($result === false) {
				$this->logger->warning('content-filters: invalid saveElements XPath skipped', [
					'xpath'   => $xpathExpr,
					'context' => $domain,
				]);
				$trace?->record('pre-filter', $rule, 0, 'Ungültiger XPath-Ausdruck');
				continue;
			}

			$matches = 0;
			foreach ($result as $node) {
				$matches++;
				if (!($node instanceof \DOMElement)) {
					continue;
				}
				$existing = $node->getAttribute('class');
				$classes  = preg_split('/\s+/', trim($existing), -1, PREG_SPLIT_NO_EMPTY);
				if (!in_array($class, $classes, true)) {
					$node->setAttribute('class', trim($existing . ' ' . $class));
				}
			}
			$trace?->record('pre-filter', $rule, $matches);
		}

		$out = $dom->saveHTML();
		if ($out === false || $out === '') {
			return $html;
		}
		$out = preg_replace('/^<\?xml[^?]*\?>\s*/i', '', $out) ?? $out;
		return $out;
	}

	/**
	 * og:/article: XPaths used as fallback for every field that has no custom
	 * XPath in the domain config.  These run for all domains automatically.
	 */
	/**
	 * Mindestzahl Wörter, ab der ein Artikel mit gefundenem Medium als
	 * "Mixed" (Text + Medium) statt als reine Audio-/Videoseite gilt.
	 */
	private const MIXED_MIN_WORDS = 80;

	private const OG_FALLBACK_XPATHS = [
		'title'     => "//meta[@property='og:title']/@content",
		'excerpt'   => "//meta[@property='og:description']/@content | //meta[@name='twitter:description']/@content",
		// property= und name= beide abdecken: nicht alle Seiten halten sich an
		// die OpenGraph-Spec (property=) - berlin.de z. B. setzt og:image als
		// name=. Ein eventuelles http:// in og:image wird zentral in
		// normalizeUrl() auf https:// hochgestuft (iOS ATS blockiert
		// unverschlüsselte Bild-Loads sonst mit "Blocked: Load failed").
		'image'     => "//meta[@property='og:image']/@content | //meta[@name='og:image']/@content",
		'author'    => "//meta[@property='article:author']/@content",
		'published' => "//meta[@property='article:published_time']/@content",
	];

	/**
	 * Extract metadata from the pre-filtered raw HTML.
	 *
	 * Per field the resolution order is:
	 *   1. Custom XPath from the domain's <metadata> section (if configured)
	 *   2. JSON path from the domain's <metadata> section (if configured)
	 *   3. Automatic og:/article: fallback (see OG_FALLBACK_XPATHS)
	 *
	 * Returns a sparse array — only fields where a non-empty value was found.
	 * Values override Readability's results in extract().
	 *
	 * For attribute XPaths (e.g. //meta[...]/@content) the attribute value is
	 * returned; for element XPaths the trimmed textContent.
	 *
	 * Zusätzlich zum Namen wird - wo erkennbar - ein Link zum Autorenprofil
	 * als "authorUrl" geliefert (siehe resolveAuthorMetadata()).
	 *
	 * @param string|null $baseUrl Artikel-URL, gegen die relative Profil-Links
	 *        aufgelöst werden. Ohne sie werden nur absolute Links übernommen.
	 * @return array{title?: string, author?: string, authorUrl?: string, authors?: list<array{name: string, url: ?string}>, excerpt?: string, image?: string, published?: string}
	 */
	private function extractDomainMetadata(string $html, string $domain, ?ContentFilterTrace $trace = null, ?string $baseUrl = null): array {
		$config = $this->loadDomainConfig($domain);
		$meta   = ($config !== null && isset($config->metadata)) ? $config->metadata : null;

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->loadHTML(
			'<?xml version="1.0" encoding="UTF-8"?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		//$path = __DIR__ . "/../../test/extractDomainMetadata.html";
		//file_put_contents($path, $html);

		$xpath       = new \DOMXPath($dom);
		$jsonSources = $this->extractJsonSources($html, $config, $domain, $trace);
		$result      = [];

		// Autor-Sonderfälle (siehe resolveAuthorMetadata()): die DOM-Knoten
		// der gewinnenden <author>-Regel (für den Profil-Link) und eine URL,
		// die eine Regel statt eines Namens geliefert hat (article:author ist
		// laut OpenGraph-Spec eine Profil-URL, kein Name).
		$authorNodes       = [];   // Treffer-Knoten der gewinnenden <author>-XPath-Regel, parallel zu $authorNames
		$authorNames       = [];   // alle gefundenen Autorennamen (vor dem Zusammenfassen)
		$authorUrlFromRule = null;

		foreach (array_keys(self::OG_FALLBACK_XPATHS) as $field) {
			// 1. Build ordered list of XPath expressions and JSON paths to try.
			//    A field may have multiple sibling elements in the domain config
			//    (e.g. two <author xpath="..."/> rules for different page layouts).
			//    SimpleXML's foreach iterates all of them in document order.
			//    Jeder Eintrag trägt seinen Regelknoten mit (rule = null beim
			//    automatischen og:-Fallback), damit der Trace die Trefferzahl der
			//    EINZELNEN Regel zuordnen kann statt nur des Feldes.
			$xpathExprs = [];
			$jsonPaths  = [];
			if ($meta !== null && isset($meta->$field)) {
				foreach ($meta->$field as $el) {
					// Two-step avoids PHP parsing $meta->$field['xpath']
					// as $meta->{$field['xpath']} (string-offset bug).
					$e = trim((string) ($el['xpath'] ?? ''));
					if ($e !== '') {
						$xpathExprs[] = ['expr' => $e, 'rule' => $el];
					}
					$j = trim((string) ($el['json'] ?? ''));
					if ($j !== '') {
						$jsonPaths[] = ['path' => $j, 'rule' => $el];
					}
				}
			}

			// 2. Always append the og:/article: fallback so it's tried when all
			//    custom rules are absent or return no match.
			$xpathExprs[] = ['expr' => self::OG_FALLBACK_XPATHS[$field], 'rule' => null];

			// 3. Try XPath expressions first; first non-empty result wins
			$value = '';
			foreach ($xpathExprs as $candidate)
			{
				$expr = $candidate['expr'];
				$rule = $candidate['rule'];

				$nodes = @$xpath->query($expr);
				if ($nodes === false) {
					if ($trace !== null && $rule !== null) {
						$trace->record('metadata', $rule, 0, 'Ungültiger XPath-Ausdruck');
					}
					continue;
				}
				if ($trace !== null && $rule !== null) {
					$trace->record('metadata', $rule, $nodes->length);
				}
				if ($nodes->length === 0) {
					continue;
				}
				$value = [];
				foreach ($nodes as $node)
				{
					$value[] = match (true)
					{
						$node instanceof \DOMAttr    => trim($node->value),
						$node instanceof \DOMText    => trim($node->nodeValue ?? ''),
						$node instanceof \DOMElement => trim($node->textContent),
						default => ''
					};
				}

				if ($field === 'author') {
					// URLs sind keine Autorennamen (typisch: <meta property=
					// "article:author" content="https://facebook.com/…">) -
					// als Profil-Link merken und, wenn die Regel NUR URLs
					// geliefert hat, mit der nächsten Regel weitersuchen.
					$names     = [];
					$nameNodes = [];
					foreach (array_values(iterator_to_array($nodes)) as $i => $node) {
						$v = $value[$i];
						if ($this->looksLikeUrl($v)) {
							$authorUrlFromRule ??= $this->normalizeAuthorUrl($v, $baseUrl);
						} elseif ($v !== '') {
							$names[]     = $v;
							$nameNodes[] = $node;
						}
					}
					if ($names === []) {
						$value = '';
						continue;
					}
					$value       = $names;
					$authorNodes = $nameNodes;
				}

				if ($value !== []) {
					break; // first hit wins
				}
			}

			// 4. If XPath found nothing, try JSON paths
			if ($value === '' && !empty($jsonPaths)) {
				foreach ($jsonPaths as $jsonCandidate) {
					$jsonPath = $jsonCandidate['path'];
					$jsonRule = $jsonCandidate['rule'];

					// Syntax: "sourceId:$.path"  — sourceId must NOT start with "$"
					//         "$.path"            — no prefix → use "default" source
					// The "$" guard prevents misidentifying a plain JSONPath (which
					// may contain ":" inside key names) as a prefixed expression.
					if (!str_starts_with($jsonPath, '$') && str_contains($jsonPath, ':')) {
						[$sourceId, $actualPath] = explode(':', $jsonPath, 2);
						$sourceId   = trim($sourceId);
						$actualPath = trim($actualPath);
					} else {
						$sourceId   = 'default';
						$actualPath = $jsonPath;
					}

					$sourceData = $jsonSources[$sourceId] ?? null;
					if ($sourceData === null) {
						if ($trace !== null && $jsonRule !== null) {
							$trace->record('metadata', $jsonRule, 0, 'JSON-Quelle "' . $sourceId . '" nicht gefunden');
						}
						continue;
					}

					// Mit [*] im Pfad liefert eine Regel mehrere Werte (z. B. alle
					// Autoren: "ld:$.author[*].name").
					$resolved = array_values(array_filter(
						$this->resolveJsonPathValues($sourceData, $actualPath),
						fn (string $v) => trim($v) !== ''
					));
					if ($field === 'author') {
						$names = [];
						foreach ($resolved as $v) {
							if ($this->looksLikeUrl($v)) {
								$authorUrlFromRule ??= $this->normalizeAuthorUrl($v, $baseUrl);
							} else {
								$names[] = trim($v);
							}
						}
						$resolved = $names;
					}
					if ($trace !== null && $jsonRule !== null) {
						$trace->record('metadata', $jsonRule, count($resolved));
					}
					if ($resolved !== []) {
						$value = $resolved;
						break;
					}
				}
			}

			if ($value !== '')
			{
				// Mehrere Treffer zu einem Feld zusammenzufassen ergibt nur bei
				// textuellen Feldern Sinn (z. B. mehrere <author xpath="…">-Regeln
				// für Co-Autoren). Bei "image"/"published" ist ein Feld mit mehreren
				// Treffern dagegen fast immer eine harmlos doppelte Meta-Angabe
				// derselben Quelle - manche Seiten (z. B. stadt-bremerhaven.de) geben
				// z. B. og:image sowohl übers Theme als auch übers SEO-Plugin aus.
				// implode() würde daraus eine ungültige "url1, url2"-Bild-URL bzw. ein
				// unparsbares Datum bauen; hier gewinnt deshalb immer der erste Treffer.
				//
				// Vor dem Zusammenfassen exakte Duplikate entfernen: Der
				// og:/twitter:description-Union-XPath für "excerpt" liefert z. B. zwei
				// Treffer, wenn eine Seite (wie nd-aktuell.de) beide Meta-Tags mit
				// identischem Text setzt - ohne array_unique() würde daraus "Text, Text"
				// werden, das dann durch die 300-Zeichen-Kürzung mitten im zweiten Text
				// abgeschnitten wird.
				if (is_array($value)) {
					$value = array_values(array_unique($value));
				}
				if ($field === 'author') {
					$authorNames = array_map(fn ($v) => html_entity_decode($v, ENT_QUOTES, 'UTF-8'), $value);
				}

				if (count($value) > 1 && !in_array($field, ['image', 'published'], true))
					$value = implode(', ', $value);
				else
					$value = $value[0];

				$result[$field] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
			}
		}

		// ── <author-link>: explizite Regeln für die Profil-Links ───────────────
		$configuredAuthorLinks = $this->extractConfiguredAuthorLinks($meta, $xpath, $jsonSources, $baseUrl, $trace);

		// ── Generic JSON-LD fallback (schema.org Article/@graph) ────────────────
		// Greift domainübergreifend, ganz ohne Domain-Config: viele CMS (Drupal,
		// WordPress/Yoast, …) betten Autor/Headline/Bild nur noch strukturiert per
		// <script type="application/ld+json"> ein, oft verschachtelt in einem
		// "@graph"-Array, statt (zusätzlich) klassische og:/article:-Meta-Tags zu
		// setzen. Läuft NACH der obigen Schleife und füllt nur Felder, die weder
		// eine Domain-Regel noch der og:/article:-Fallback treffen konnte - Domain-
		// Configs und OG-Tags behalten also unverändert Vorrang.
		$missingFields = array_diff(array_keys(self::OG_FALLBACK_XPATHS), array_keys($result));
		// Für den Autor wird JSON-LD auch dann gelesen, wenn der Name schon
		// feststeht: es kann noch den Profil-Link beisteuern.
		$genericLd = [];
		if ($missingFields !== [] || isset($result['author'])) {
			$genericLd = $this->extractGenericJsonLdMetadata($html);
			foreach ($missingFields as $field) {
				if (!empty($genericLd[$field])) {
					$result[$field] = $genericLd[$field];
				}
			}
		}

		// Kam der Name aus JSON-LD, stammen auch die Namensliste von dort.
		if ($authorNames === [] && isset($result['author']) && ($genericLd['authors'] ?? []) !== []
			&& $result['author'] === ($genericLd['author'] ?? null)) {
			$authorNames = array_column($genericLd['authors'], 'name');
		}
		$result = $this->resolveAuthorMetadata(
			$result, $xpath, $baseUrl, $authorNames, $authorNodes, $authorUrlFromRule,
			$genericLd['authors'] ?? [], $configuredAuthorLinks
		);

		// ── Static category declaration ──────────────────────────────────────────
		// <category>Video</category> in the domain config assigns a fixed category
		// without needing to parse the HTML (no XPath required).
		if ($config !== null && isset($config->category)) {
			$cat = trim((string) $config->category);
			if ($cat !== '') {
				$result['category'] = $cat;
			}
		}

		return $result;
	}

	/**
	 * Extract all named JSON sources declared in the domain config.
	 *
	 * XML syntax (inside <domain>, before <metadata>):
	 *   <json id="ld"   xpath="//script[@type='application/ld+json']" index="0" />
	 *   <json id="next" xpath="//script[@id='__NEXT_DATA__']" />
	 *
	 *   id     – Required. Referenced from metadata fields as "id:$.path".
	 *   xpath  – Required. Selects the <script> element(s) containing the JSON.
	 *   index  – Optional (default: 0). Which matched element to use (0-based).
	 *
	 * If no <json> elements are defined, one source with id "default" is
	 * auto-registered pointing at <script type="application/ld+json"> (index 0).
	 *
	 * @return array<string, mixed>  Map of id → decoded JSON (associative array)
	 */
	private function extractJsonSources(
		string $html,
		?\SimpleXMLElement $config,
		string $domain,
		?ContentFilterTrace $trace = null
	): array
	{
		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->loadHTML(
			'<?xml version="1.0" encoding="UTF-8"?>' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xpath = new \DOMXPath($dom);

		// Use direct SimpleXML property access instead of xpath('json') —
		// SimpleXML's xpath() can silently return [] for direct children
		// depending on context registration, making all sources fall through
		// to the auto-default. Property access ($config->json) is reliable.
		$hasExplicitDefs = ($config !== null && count($config->json) > 0);

		$sources = [];

		if (!$hasExplicitDefs) {
			// Auto-default: use first <script type="application/ld+json"> as "default"
			$nodes = @$xpath->query("//script[@type='application/ld+json']");
			if ($nodes !== false && $nodes->length > 0) {
				$json    = trim($nodes->item(0)->textContent ?? '');
				$decoded = $json !== '' ? json_decode($json, true) : null;
				if ($decoded !== null) {
					$sources['default'] = $decoded;
				}
			}
		}

		foreach (($hasExplicitDefs ? $config->json : []) as $def) {
			$id        = trim((string) ($def['id']    ?? ''));
			$xpathExpr = trim((string) ($def['xpath'] ?? ''));
			$index     = (int) ($def['index'] ?? 0);

			if ($id === '' || $xpathExpr === '') {
				$this->logger->warning('content-filters: <json> element missing id or xpath, skipped', ['domain' => $domain]);
				$trace?->record('json', $def, 0, 'id oder xpath fehlt');
				continue;
			}

			$nodes = @$xpath->query($xpathExpr);
			if ($nodes === false) {
				$trace?->record('json', $def, 0, 'Ungültiger XPath-Ausdruck');
				continue;
			}

			// Ohne diesen Zähler sähe der Admin bei einer nicht gefundenen Quelle
			// nur "JSON-Quelle nicht gefunden" am metadata-Feld – nicht, dass schon
			// der Quell-XPath hier ins Leere lief.
			$trace?->record('json', $def, $nodes->length);

			if ($nodes->length === 0) {
				continue;
			}

			// Use requested index, fall back to last available element
			$node = $nodes->item($index) ?? $nodes->item($nodes->length - 1);
			if ($node === null) {
				continue;
			}

			$json = trim($node->textContent ?? '');
			if ($json === '') {
				$trace?->record('json', $def, $nodes->length, 'Gefundenes Element ist leer');
				continue;
			}

			$decoded = json_decode($json, true);
			if ($decoded === null) {
				$this->logger->warning('content-filters: JSON source could not be decoded', [
					'id'     => $id,
					'domain' => $domain,
				]);
				$trace?->record('json', $def, $nodes->length, 'Inhalt ist kein gültiges JSON');
				continue;
			}

			$sources[$id] = $decoded;

			// First defined <json> element is also reachable as "default",
			// so json="$.path" (no prefix) works even when explicit sources exist.
			if (!isset($sources['default'])) {
				$sources['default'] = $decoded;
			}
		}

		return $sources;
	}

	/**
	 * Resolve a JSONPath-style expression against decoded JSON data.
	 *
	 * Supported syntax:
	 *   $.key              – top-level key
	 *   $.key.subkey       – nested key
	 *   $.array[0].key     – array index + key
	 *   $[0].key           – top-level array index
	 *
	 * Returns the string value of the resolved leaf, or null when the path
	 * does not match or the resolved value is not scalar.
	 */
	private function resolveJsonPath(mixed $data, string $path): ?string
	{
		return $this->resolveJsonPathValues($data, $path)[0] ?? null;
	}

	/**
	 * Wie resolveJsonPath(), liefert aber ALLE Treffer: "[*]" steht für
	 * jedes Element eines Arrays (z. B. "$.author[*].name" für alle
	 * Autoren). Ist der Wert an der Stelle kein Array, sondern ein einzelnes
	 * Objekt (schema.org erlaubt "author": {…} statt [{…}]), zählt er als
	 * einziges Element. Nur skalare Treffer landen im Ergebnis.
	 *
	 * @return list<string>
	 */
	private function resolveJsonPathValues(mixed $data, string $path): array
	{
		// Strip leading "$" / "$."
		$path = ltrim($path, '$');
		if (str_starts_with($path, '.')) {
			$path = substr($path, 1);
		}

		$current = [$data];
		if ($path !== '') {
			// Tokenize on "." — array indices stay attached to their token ("key[0]", "key[*]")
			foreach (explode('.', $path) as $token) {
				if (!preg_match('/^([^\[]*)((?:\[(?:\d+|\*)\])*)$/', $token, $m)) {
					return [];
				}
				preg_match_all('/\[(\d+|\*)\]/', $m[2], $indexMatches);

				$next = [];
				foreach ($current as $node) {
					if ($m[1] !== '') {
						if (!is_array($node) || !array_key_exists($m[1], $node)) {
							continue;
						}
						$node = $node[$m[1]];
					}
					$items = [$node];
					foreach ($indexMatches[1] as $index) {
						$narrowed = [];
						foreach ($items as $item) {
							if (!is_array($item)) {
								continue;
							}
							if ($index === '*') {
								if (array_is_list($item)) {
									array_push($narrowed, ...$item);
								} else {
									$narrowed[] = $item;
								}
							} elseif (array_key_exists((int) $index, $item)) {
								$narrowed[] = $item[(int) $index];
							}
						}
						$items = $narrowed;
					}
					array_push($next, ...$items);
				}
				$current = $next;
			}
		}

		$values = [];
		foreach ($current as $value) {
			if (is_scalar($value)) {
				$values[] = (string) $value;
			}
		}
		return $values;
	}

	/**
	 * schema.org @type-Werte, die als "das ist der Artikel-Knoten" gelten -
	 * verwendet vom domainübergreifenden JSON-LD-Fallback (siehe
	 * extractGenericJsonLdMetadata()).
	 */
	private const JSONLD_ARTICLE_TYPES = [
		'article', 'newsarticle', 'opinionnewsarticle', 'reportagenewsarticle',
		'analysisnewsarticle', 'reviewnewsarticle', 'blogposting', 'techarticle',
		'scholarlyarticle', 'socialmediaposting',
	];

	/**
	 * Domainübergreifender Fallback: sucht in ALLEN
	 * <script type="application/ld+json">-Blöcken der Seite nach einem
	 * schema.org-Knoten vom Typ Article/NewsArticle/… (auch verschachtelt in
	 * einem "@graph"-Array, wie es Drupal/Yoast/… erzeugen) und liest daraus
	 * title/author/excerpt/image/published.
	 *
	 * Anders als die Domain-Configs (extractJsonSources()/resolveJsonPath())
	 * braucht das kein XML-Setup pro Domain - viele Seiten liefern Autor/
	 * Bild/Headline inzwischen NUR noch strukturiert per JSON-LD, ohne
	 * (zusätzliche) og:/article:-Meta-Tags.
	 *
	 * @return array{title?: string, author?: string, authors?: list<array{name: string, url: ?string}>, excerpt?: string, image?: string, published?: string}
	 */
	private function extractGenericJsonLdMetadata(string $html): array {
		if (!preg_match_all(
			'/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
			$html,
			$scriptMatches
		)) {
			return [];
		}

		foreach ($scriptMatches[1] as $rawJson) {
			$decoded = json_decode(trim($rawJson), true);
			if (!is_array($decoded)) {
				continue;
			}

			$nodes = $this->flattenJsonLdNodes($decoded);
			// Yoast & Co. verweisen im Article-Knoten oft nur per
			// {"@id": "…#/schema/person/…"} auf einen Person-Knoten an anderer
			// Stelle im @graph - für die Auflösung nach @id indizieren.
			$nodesById = [];
			foreach ($nodes as $n) {
				if (isset($n['@id']) && is_string($n['@id'])) {
					$nodesById[$n['@id']] ??= $n;
				}
			}

			foreach ($nodes as $node) {
				if (!$this->isJsonLdArticleNode($node)) {
					continue;
				}

				$result = [];

				$title = $this->jsonLdScalarValue($node['headline'] ?? null)
					?? $this->jsonLdScalarValue($node['name'] ?? null);
				if ($title !== null) {
					$result['title'] = $title;
				}

				$excerpt = $this->jsonLdScalarValue($node['description'] ?? null)
					?? $this->jsonLdScalarValue($node['alternativeHeadline'] ?? null);
				if ($excerpt !== null) {
					$result['excerpt'] = $excerpt;
				}

				$authorEntries = $this->jsonLdAuthorEntries($node['author'] ?? null, $nodesById);
				$author = $this->jsonLdAuthorNames($authorEntries);
				if ($author !== null) {
					$result['author'] = $author;
					// Name + Profil-Link je Autor (Co-Autoren einzeln).
					$authors = [];
					foreach ($authorEntries as $entry) {
						$name = $this->jsonLdScalarValue($entry);
						if ($name !== null && !$this->looksLikeUrl($name)) {
							$authors[] = ['name' => $name, 'url' => $this->jsonLdAuthorUrl($entry)];
						}
					}
					$result['authors'] = $authors;
				}

				$image = $this->jsonLdImageUrl($node['image'] ?? null);
				if ($image !== null) {
					$result['image'] = $image;
				}

				$published = $this->jsonLdScalarValue($node['datePublished'] ?? null)
					?? $this->jsonLdScalarValue($node['dateModified'] ?? null);
				if ($published !== null) {
					$result['published'] = $published;
				}

				// Erster passende Knoten über alle Scripts hinweg gewinnt - bei
				// mehreren Article-Knoten (z. B. Haupt- + verlinkte Teaser-Artikel
				// im selben @graph) ist der erste i. d. R. der Hauptartikel.
				if ($result !== []) {
					return $result;
				}
			}
		}

		return [];
	}

	/**
	 * Flacht eine dekodierte JSON-LD-Struktur zu einer flachen Liste von
	 * Knoten (assoziative Arrays) ab: löst ein "@graph"-Array auf, akzeptiert
	 * aber auch ein einzelnes Objekt oder ein reines Array von Objekten als
	 * Top-Level-Struktur.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function flattenJsonLdNodes(array $decoded): array {
		if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
			return array_values(array_filter($decoded['@graph'], 'is_array'));
		}

		// Reines Array von Knoten (kein "@graph"-Wrapper, kein einzelnes Objekt
		// mit "@type") - z. B. `[{"@type": "..."}, {"@type": "..."}]`.
		if (array_is_list($decoded) && !isset($decoded['@type'])) {
			return array_values(array_filter($decoded, 'is_array'));
		}

		return [$decoded];
	}

	private function isJsonLdArticleNode(array $node): bool {
		$type = $node['@type'] ?? null;
		$types = is_array($type) ? $type : [$type];
		foreach ($types as $t) {
			if (is_string($t) && in_array(strtolower($t), self::JSONLD_ARTICLE_TYPES, true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalisiert einen JSON-LD-Wert zu einem einzelnen String: reine
	 * Skalare direkt, Objekte über ihr "name"-Feld (z. B. ein einzelnes
	 * Person/Organization-Objekt). Arrays/leere Werte liefern null - dafür
	 * gibt es die spezialisierten jsonLdAuthorNames()/jsonLdImageUrl().
	 */
	private function jsonLdScalarValue(mixed $value): ?string {
		if (is_string($value) && trim($value) !== '') {
			return trim($value);
		}
		if (is_array($value) && isset($value['name']) && is_string($value['name']) && trim($value['name']) !== '') {
			return trim($value['name']);
		}
		return null;
	}

	/**
	 * "author" kann laut schema.org ein String, ein einzelnes Person/
	 * Organization-Objekt oder ein Array davon (Co-Autoren) sein. Mehrere
	 * Namen werden wie bei den domainkonfigurierten <author xpath="…">-Regeln
	 * mit ", " verbunden.
	 */
	private function jsonLdAuthorNames(array $entries): ?string {
		$names = [];
		foreach ($entries as $entry) {
			$name = $this->jsonLdScalarValue($entry);
			if ($name !== null && !$this->looksLikeUrl($name)) {
				$names[] = $name;
			}
		}

		return $names !== [] ? implode(', ', array_values(array_unique($names))) : null;
	}

	/**
	 * Normalisiert "author" zu einer Liste von Einträgen (String oder
	 * Objekt) und löst reine {"@id": …}-Verweise über den @graph auf.
	 *
	 * @param array<string, array<string, mixed>> $nodesById
	 * @return list<mixed>
	 */
	private function jsonLdAuthorEntries(mixed $author, array $nodesById): array {
		if ($author === null) {
			return [];
		}

		$entries = (is_array($author) && array_is_list($author)) ? $author : [$author];
		$result  = [];
		foreach ($entries as $entry) {
			if (is_array($entry) && !isset($entry['name']) && isset($entry['@id'])
				&& is_string($entry['@id']) && isset($nodesById[$entry['@id']])) {
				$entry = $nodesById[$entry['@id']];
			}
			if ($entry !== null && $entry !== '') {
				$result[] = $entry;
			}
		}
		return $result;
	}

	/**
	 * Profil-Link eines JSON-LD-Autors: "url" des Person-Objekts, sonst ein
	 * als Name eingetragener String, der in Wahrheit eine URL ist.
	 */
	private function jsonLdAuthorUrl(mixed $entry): ?string {
		if (is_string($entry)) {
			return $this->looksLikeUrl($entry) ? trim($entry) : null;
		}
		if (!is_array($entry)) {
			return null;
		}
		$url = $entry['url'] ?? null;
		if (is_array($url)) {
			$url = $url[0] ?? null;
		}
		return (is_string($url) && $this->looksLikeUrl($url)) ? trim($url) : null;
	}

	/**
	 * "image" kann ein String (URL), ein ImageObject ({"contentUrl"/"url": …})
	 * oder ein Array davon sein - hier gewinnt immer der erste Treffer (siehe
	 * Begründung bei "image"/"published" oben in extractDomainMetadata()).
	 */
	private function jsonLdImageUrl(mixed $image): ?string {
		if ($image === null) {
			return null;
		}

		$entries = (is_array($image) && array_is_list($image)) ? $image : [$image];
		foreach ($entries as $entry) {
			if (is_string($entry) && trim($entry) !== '') {
				return trim($entry);
			}
			if (is_array($entry)) {
				$url = $entry['contentUrl'] ?? $entry['url'] ?? null;
				if (is_string($url) && trim($url) !== '') {
					return trim($url);
				}
			}
		}

		return null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Autor & Autorenprofil
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Domainübergreifende HTML-Signale für Autorenname und Profil-Link, in
	 * absteigender Verlässlichkeit. Greifen nur, wenn weder eine Domain-Regel,
	 * noch article:author, noch JSON-LD einen Namen geliefert haben (bzw. um
	 * zu einem bereits bekannten Namen den Profil-Link zu finden). Der erste
	 * Ausdruck mit mindestens einem brauchbaren Namen gewinnt.
	 */
	private const GENERIC_AUTHOR_XPATHS = [
		// WordPress-Block-Themes: "Post Author Name"-Block
		// (<div class="wp-block-post-author-name"><a class="wp-block-post-author-name__link" href="…">Name</a></div>)
		"//*[contains(concat(' ', normalize-space(@class), ' '), ' wp-block-post-author-name ')]",
		"//a[contains(concat(' ', normalize-space(@class), ' '), ' wp-block-post-author-name__link ')]",
		// Älterer WordPress-"Post Author"-Block
		"//*[contains(concat(' ', normalize-space(@class), ' '), ' wp-block-post-author__name ')]",
		// schema.org-Microdata (<span itemprop="author" itemscope itemtype="…/Person"><span itemprop="name">…</span></span>)
		"//*[@itemprop='author']",
		// hCard/hAtom, u. a. klassische WordPress-Themes
		// (<span class="author vcard"><a class="url fn n" href="…">Name</a></span>)
		"//*[contains(concat(' ', normalize-space(@class), ' '), ' author ')][contains(concat(' ', normalize-space(@class), ' '), ' vcard ')]",
		// HTML-Link-Relation rel="author"
		"//a[contains(concat(' ', normalize-space(@rel), ' '), ' author ')]",
	];

	/** Mehr verschiedene Namen pro Treffer-Ausdruck = eher Autorenliste/Sidebar als Byline. */
	private const GENERIC_AUTHOR_MAX_NAMES = 4;

	/** Längere "Namen" sind fast immer eine ganze Bio-/Infobox statt einer Byline. */
	private const AUTHOR_NAME_MAX_LENGTH = 80;


	/**
	 * Wertet die <metadata><author-link xpath="…" | json="…"/>-Regeln der
	 * Domain-Config aus (Fallback-Kette in Dokumentreihenfolge, wie bei den
	 * übrigen Feldern). Die erste Regel mit mindestens einem gültigen Link
	 * gewinnt und liefert ALLE ihre Links (ein Link je Treffer bzw. je Wert
	 * eines [*]-JSON-Pfads), damit sie mehreren Autoren der Reihe nach
	 * zugeordnet werden können. Ein XPath darf auf ein Attribut (…/@href),
	 * einen Textknoten oder ein Element zeigen; bei einem Element zählt
	 * dessen href (bzw. der Link darin/drumherum), sonst sein Text.
	 *
	 * @param array<string, mixed> $jsonSources
	 * @return list<string>
	 */
	private function extractConfiguredAuthorLinks(
		?\SimpleXMLElement $meta,
		\DOMXPath $xpath,
		array $jsonSources,
		?string $baseUrl,
		?ContentFilterTrace $trace
	): array {
		if ($meta === null || !isset($meta->{'author-link'})) {
			return [];
		}

		foreach ($meta->{'author-link'} as $rule) {
			$expr = trim((string) ($rule['xpath'] ?? ''));
			if ($expr !== '') {
				$nodes = @$xpath->query($expr);
				if ($nodes === false) {
					$trace?->record('metadata', $rule, 0, 'Ungültiger XPath-Ausdruck');
				} else {
					$urls = [];
					foreach ($nodes as $node) {
						$url = match (true) {
							$node instanceof \DOMAttr => $this->normalizeAuthorUrl($node->value, $baseUrl),
							default => $this->authorUrlFromNode($node, $baseUrl)
								?? $this->normalizeAuthorUrl(trim($node->textContent ?? ''), $baseUrl),
						};
						if ($url !== null) {
							$urls[] = $url;
						}
					}
					$trace?->record('metadata', $rule, $nodes->length,
						($nodes->length > 0 && $urls === []) ? 'Kein gültiger http(s)-Link im Treffer' : null);
					if ($urls !== []) {
						return array_values(array_unique($urls));
					}
				}
			}

			$jsonPath = trim((string) ($rule['json'] ?? ''));
			if ($jsonPath !== '') {
				if (!str_starts_with($jsonPath, '$') && str_contains($jsonPath, ':')) {
					[$sourceId, $actualPath] = array_map('trim', explode(':', $jsonPath, 2));
				} else {
					[$sourceId, $actualPath] = ['default', $jsonPath];
				}
				$sourceData = $jsonSources[$sourceId] ?? null;
				$urls = [];
				if ($sourceData !== null) {
					foreach ($this->resolveJsonPathValues($sourceData, $actualPath) as $value) {
						$url = $this->normalizeAuthorUrl($value, $baseUrl);
						if ($url !== null) {
							$urls[] = $url;
						}
					}
				}
				$trace?->record('metadata', $rule, count($urls),
					$sourceData === null ? 'JSON-Quelle "' . $sourceId . '" nicht gefunden' : null);
				if ($urls !== []) {
					return array_values(array_unique($urls));
				}
			}
		}

		return [];
	}

	/**
	 * Vervollständigt Autorennamen und Profil-Links im Metadaten-Ergebnis.
	 *
	 * Namen: Domain-Regel / article:author / JSON-LD wie bisher; fehlen sie,
	 * greifen die generischen HTML-Signale (GENERIC_AUTHOR_XPATHS).
	 *
	 * Profil-Links werden JE AUTOR ermittelt, erste Quelle gewinnt:
	 *   1. <author-link>-Regeln der Domain-Config: liefern sie genau so viele
	 *      Links wie es Autoren gibt, werden sie der Reihe nach zugeordnet;
	 *      bei nur einem Autor gilt der erste Link
	 *   2. <a href> am Treffer der gewinnenden Domain-<author>-Regel (Knoten,
	 *      Vorfahre oder Link darin)
	 *   3. JSON-LD author[].url mit gleichem Namen
	 *   4. generisches HTML-Signal mit gleichem Namen
	 *   5. nur bei genau einem Autor: eine URL, die eine Regel statt eines
	 *      Namens geliefert hat (typisch article:author)
	 *
	 * Ergebnis: "authors" = Liste aus {name, url} (nur wenn mindestens ein
	 * Link gefunden wurde), "authorUrl" = Link bei genau einem Autor.
	 *
	 * @param array<string, mixed>                       $result
	 * @param list<string>                               $authorNames
	 * @param list<\DOMNode>                             $authorNodes parallel zu $authorNames, wenn die Namen aus einer XPath-Regel stammen
	 * @param list<array{name: string, url: ?string}>    $jsonLdAuthors
	 * @param list<string>                               $configuredLinks
	 * @return array<string, mixed>
	 */
	private function resolveAuthorMetadata(
		array $result,
		\DOMXPath $xpath,
		?string $baseUrl,
		array $authorNames,
		array $authorNodes,
		?string $authorUrlFromRule,
		array $jsonLdAuthors,
		array $configuredLinks
	): array {
		$generic = null;
		$entries = [];

		if (isset($result['author'])) {
			if ($authorNames === []) {
				$authorNames = [$result['author']];
			}
			// Gleiche Namen (z. B. Byline oben + Autorenbox unten) nur einmal;
			// der erste Treffer mit Link gewinnt.
			foreach ($authorNames as $i => $name) {
				$key = mb_strtolower($name);
				$url = isset($authorNodes[$i]) ? $this->authorUrlFromNode($authorNodes[$i], $baseUrl) : null;
				if (!isset($entries[$key])) {
					$entries[$key] = ['name' => $name, 'url' => $url];
				} elseif ($entries[$key]['url'] === null) {
					$entries[$key]['url'] = $url;
				}
			}
			$entries = array_values($entries);
		} else {
			$generic = $this->extractGenericAuthors($xpath, $baseUrl);
			$entries = $generic ?? [];
			if ($entries !== []) {
				$result['author'] = implode(', ', array_column($entries, 'name'));
			}
		}

		if ($entries === []) {
			// Nur eine Profil-URL, kein Name: extract() kann sie noch mit dem
			// von Readability gefundenen Namen paaren.
			$url = $configuredLinks[0] ?? $authorUrlFromRule;
			if ($url !== null) {
				$result['authorUrl'] = $url;
			}
			return $result;
		}

		// 1. Konfigurierte <author-link>-Regeln haben Vorrang.
		if ($configuredLinks !== []) {
			if (count($configuredLinks) === count($entries)) {
				foreach ($entries as $i => $_) {
					$entries[$i]['url'] = $configuredLinks[$i];
				}
			} elseif (count($entries) === 1) {
				$entries[0]['url'] = $configuredLinks[0];
			}
		}

		// 3./4. Fehlende Links über den Namen aus JSON-LD bzw. generischen Signalen.
		$byName = function (array $list, string $name): ?string {
			foreach ($list as $candidate) {
				if ($candidate['url'] !== null && mb_strtolower($candidate['name']) === mb_strtolower($name)) {
					return $candidate['url'];
				}
			}
			return null;
		};
		foreach ($entries as $i => $entry) {
			if ($entry['url'] !== null) {
				continue;
			}
			$url = $byName($jsonLdAuthors, $entry['name']);
			if ($url !== null) {
				$url = $this->normalizeAuthorUrl($url, $baseUrl);
			}
			if ($url === null) {
				$generic ??= $this->extractGenericAuthors($xpath, $baseUrl) ?? [];
				$url = $byName($generic, $entry['name']);
			}
			$entries[$i]['url'] = $url;
		}

		// 5. URL statt Name (article:author) nur bei eindeutiger Zuordnung.
		if (count($entries) === 1 && $entries[0]['url'] === null) {
			$entries[0]['url'] = $authorUrlFromRule;
		}

		if (array_filter(array_column($entries, 'url')) !== []) {
			$result['authors'] = $entries;
		}
		if (count($entries) === 1 && $entries[0]['url'] !== null) {
			$result['authorUrl'] = $entries[0]['url'];
		}

		return $result;
	}

	/**
	 * Wertet GENERIC_AUTHOR_XPATHS aus.
	 *
	 * @return list<array{name: string, url: ?string}>|null
	 */
	private function extractGenericAuthors(\DOMXPath $xpath, ?string $baseUrl): ?array {
		foreach (self::GENERIC_AUTHOR_XPATHS as $expr) {
			$nodes = @$xpath->query($expr);
			if ($nodes === false || $nodes->length === 0) {
				continue;
			}

			// Name (kleingeschrieben als Schlüssel für die Deduplizierung) =>
			// [Anzeigename, erster gefundener Profil-Link]
			$found = [];
			foreach ($nodes as $node) {
				if (!$node instanceof \DOMElement) {
					continue;
				}
				$name = $this->authorNameFromElement($node, $xpath);
				if ($name === null) {
					continue;
				}
				$key = mb_strtolower($name);
				$url = $this->authorUrlFromNode($node, $baseUrl);
				if (!isset($found[$key])) {
					$found[$key] = ['name' => $name, 'url' => $url];
				} elseif ($found[$key]['url'] === null) {
					$found[$key]['url'] = $url;
				}
			}

			if ($found === [] || count($found) > self::GENERIC_AUTHOR_MAX_NAMES) {
				continue;
			}

			return array_values($found);
		}

		return null;
	}

	/**
	 * Liest den Autorennamen aus einem Treffer-Element: bei schema.org-
	 * Microdata bevorzugt aus [itemprop=name], bei hCard aus .fn, sonst aus
	 * content-Attribut (<meta itemprop="author" content="…">) bzw. Text.
	 */
	private function authorNameFromElement(\DOMElement $el, \DOMXPath $xpath): ?string {
		$raw = null;

		if ($el->getAttribute('itemprop') === 'author') {
			$nameNode = $xpath->query(".//*[@itemprop='name']", $el)->item(0);
			if ($nameNode instanceof \DOMElement) {
				$raw = $nameNode->hasAttribute('content') ? $nameNode->getAttribute('content') : $nameNode->textContent;
			} elseif ($el->hasAttribute('content')) {
				$raw = $el->getAttribute('content');
			} elseif (strtolower($el->nodeName) === 'link') {
				return null; // <link itemprop="author" href="…"> trägt nur eine URL
			}
		} elseif (str_contains(' ' . $el->getAttribute('class') . ' ', ' vcard ')) {
			$fn = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' fn ')]", $el)->item(0);
			if ($fn !== null) {
				$raw = $fn->textContent;
			}
		}

		return $this->cleanAuthorName($raw ?? $el->textContent);
	}

	/**
	 * Normalisiert einen gefundenen Autorennamen: Whitespace zusammenfassen,
	 * Byline-Präfixe ("Von", "By", "Autor:", …) abschneiden. null, wenn das
	 * Ergebnis leer, eine URL oder unplausibel lang ist.
	 */
	private function cleanAuthorName(string $raw): ?string {
		$name = trim((string) preg_replace('/\s+/u', ' ', $raw));
		$name = (string) preg_replace('/^(?:von|by|text|autor(?:in)?|author)\s*:?\s+/iu', '', $name);
		$name = trim($name, " \t\n\r\0\x0B,;:|·–-");

		if ($name === '' || mb_strlen($name) > self::AUTHOR_NAME_MAX_LENGTH || $this->looksLikeUrl($name)) {
			return null;
		}
		return $name;
	}

	/**
	 * Profil-Link zu einem Autor-Treffer: das Element selbst, wenn es ein
	 * Link ist, sonst ein umschließender Link, sonst [itemprop=url] bzw. der
	 * erste Link im Element. Text-/Attributknoten (z. B. aus einer Domain-
	 * Regel wie //a[@rel='author']/text()) werden über ihr Elternelement
	 * aufgelöst; <meta …/@content>-Treffer liefern keinen Link.
	 */
	private function authorUrlFromNode(\DOMNode $node, ?string $baseUrl): ?string {
		if ($node instanceof \DOMAttr) {
			return null;
		}
		$el = $node instanceof \DOMElement ? $node : $node->parentNode;
		if (!$el instanceof \DOMElement) {
			return null;
		}

		$tag = strtolower($el->nodeName);
		if (($tag === 'a' || $tag === 'link') && $el->hasAttribute('href')) {
			return $this->normalizeAuthorUrl($el->getAttribute('href'), $baseUrl);
		}

		for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
			if (strtolower($p->nodeName) === 'a' && $p->hasAttribute('href')) {
				return $this->normalizeAuthorUrl($p->getAttribute('href'), $baseUrl);
			}
		}

		$xpath = new \DOMXPath($el->ownerDocument);
		$urlNode = $xpath->query(".//*[@itemprop='url'][@href or @content]", $el)->item(0);
		if ($urlNode instanceof \DOMElement) {
			$href = $urlNode->getAttribute('href') ?: $urlNode->getAttribute('content');
			return $this->normalizeAuthorUrl($href, $baseUrl);
		}

		$link = $xpath->query('.//a[@href]', $el)->item(0);
		if ($link instanceof \DOMElement) {
			return $this->normalizeAuthorUrl($link->getAttribute('href'), $baseUrl);
		}

		return null;
	}

	/**
	 * Macht einen Profil-Link absolut und lässt nur http(s) durch - kein
	 * javascript:/mailto:/reiner Anker, die im Reader als Link nichts taugen.
	 */
	private function normalizeAuthorUrl(string $href, ?string $baseUrl): ?string {
		$href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
		if ($href === '' || str_starts_with($href, '#')) {
			return null;
		}
		if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $href) && !preg_match('/^https?:/i', $href)) {
			return null;
		}
		if (!preg_match('/^https?:\/\//i', $href)) {
			if ($baseUrl === null || $baseUrl === '') {
				return null;
			}
			$href = $this->normalizeUrl($href, $baseUrl);
		}
		if (filter_var($href, FILTER_VALIDATE_URL) === false) {
			return null;
		}
		// Ein "Profil-Link" auf den Artikel selbst (z. B. eine Byline, die
		// in einem Link auf die Seite steckt) ist keiner.
		if ($baseUrl !== null && $this->stripUrlForCompare($href) === $this->stripUrlForCompare($baseUrl)) {
			return null;
		}
		return $href;
	}

	private function stripUrlForCompare(string $url): string {
		$url = preg_replace('/#.*$/', '', $url) ?? $url;
		$url = preg_replace('/^https?:\/\/(www\.)?/i', '', $url) ?? $url;
		return rtrim(strtolower($url), '/');
	}

	private function looksLikeUrl(string $value): bool {
		return preg_match('/^(?:https?:)?\/\/\S+$/i', trim($value)) === 1;
	}

	/** Anzahl Autoren in einem per ", " zusammengeführten Autorenfeld. */
	private function countAuthors(string $author): int {
		return count(array_filter(array_map('trim', explode(', ', $author)), fn ($a) => $a !== ''));
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Content Cleanup
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Clean HTML content
	 */
	private function cleanHtml(string $html): string {
		// Remove script tags - AUSSER den offiziellen Widget-Loadern von
		// Instagram/X/Bluesky/TikTok (siehe isAllowedWidgetScriptSrc()): ohne diese
		// Ausnahme würde dieser regelbasierte, noch VOR sanitizeHtml() laufende
		// Schritt genau das Script wieder entfernen, das sanitizeHtml() später
		// bewusst durchlässt - die dortige Allowlist liefe leer. Kein Skript-Body
		// erlaubt (nur reine src-Loader), damit sich kein Inline-JS über einen
		// sonst passenden src-Wert einschleusen kann.
		$html = preg_replace_callback(
			'/<script\b[^>]*>(.*?)<\/script>/is',
			function (array $m): string {
				if (trim($m[1]) !== '') {
					return '';
				}
				if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $m[0], $srcMatch)) {
					return '';
				}
				$src = html_entity_decode($srcMatch[2], ENT_QUOTES, 'UTF-8');
				return $this->isAllowedWidgetScriptSrc($src) ? $m[0] : '';
			},
			$html
		) ?? $html;
		$html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

		// Remove linked CSS stylesheets
		$html = preg_replace('/<link\b[^>]+rel=["\']stylesheet["\'][^>]*>/is', '', $html);
		$html = preg_replace('/<link\b[^>]+href=[^>]+rel=["\']stylesheet["\'][^>]*>/is', '', $html);

		// Remove margin and padding from inline style attributes
		$html = preg_replace_callback(
			'/(<[^>]+\bstyle=["\'])([^"\']*?)(["\'])/i',
			static function (array $m): string {
				$style = preg_replace(
					'/\b(?:margin|padding)(?:-(?:top|right|bottom|left|block|inline|block-start|block-end|inline-start|inline-end))?\s*:[^;]*;?\s*/i',
					'',
					$m[2]
				);
				$style = trim($style ?? '', " \t\n\r;");
				if ($style === '') {
					// Drop the entire style attribute
					return preg_replace('/\s*\bstyle=["\'][^"\']*["\']/i', '', $m[0]) ?? $m[0];
				}
				return $m[1] . $style . $m[3];
			},
			$html
		) ?? $html;

		// Strip every class token except Merlin's own merlin-* marker classes
		// (merlin-infobox, merlin-quote, merlin-hero-image, …) plus the fixed
		// set of official embed-widget marker classes (instagram-media,
		// twitter-tweet, bluesky-embed) that isAllowedWidgetScriptSrc()'s
		// loaders key off of - same reasoning as the script-tag exception
		// above, this must stay in sync with sanitizeHtml()'s allowlist.
		//
		// keepClasses=true on the Readability config (see processHtml()) is needed
		// so those marker classes survive parsing — but it also lets every class
		// from the SOURCE site through unfiltered. Those classes are inert today
		// (the source stylesheet is stripped above), but only by accident: if the
		// source site's class names ever happen to match a class Merlin's own CSS
		// or the Nextcloud host CSS defines (e.g. a WordPress/Tailwind site using
		// generic utility names like "fixed", "absolute", "hidden", "row"), the
		// foreign element would suddenly be styled by Merlin's rules — exactly
		// what happened with amadeu-antonio-stiftung.de's leaked donation-box
		// markup (Tailwind classes "fixed", "pin-l", "pin-b", "absolute", …).
		// Allowlisting merlin-* closes this for every domain at once instead of
		// reacting to individual collisions. Every saveElements/<infobox> rule in
		// content-filters/*.xml already assigns merlin-*-prefixed classes only.
		$html = preg_replace_callback(
			'/(<[^>]+\bclass=["\'])([^"\']*?)(["\'])/i',
			static function (array $m): string {
				static $allowedWidgetClasses = ['instagram-media', 'twitter-tweet', 'bluesky-embed', 'tiktok-embed'];
				$classes = preg_split('/\s+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY);
				$kept = array_values(array_filter(
					$classes,
					static fn(string $c): bool => str_starts_with($c, 'merlin-') || in_array($c, $allowedWidgetClasses, true)
				));
				if ($kept === []) {
					// Drop the entire class attribute
					return preg_replace('/\s*\bclass=["\'][^"\']*["\']/i', '', $m[0]) ?? $m[0];
				}
				return $m[1] . implode(' ', $kept) . $m[3];
			},
			$html
		) ?? $html;

		// Strip every id attribute that wasn't explicitly set by Merlin itself
		// (none are, today — this is a forward-compatible merlin-* allowlist,
		// same convention as the class filter above).
		//
		// Source-site ids are exactly as dangerous as source-site classes: they're
		// inert once the source stylesheet/scripts are gone, but only by accident.
		// An id is also unique-per-document by spec, so a leaked source id (e.g.
		// "content", "main", "header") can collide with an id Merlin's own shell
		// chrome uses elsewhere in the same page, with unpredictable CSS/JS
		// side effects. The only thing this can break is same-document anchor
		// links (<a href="#fn1"> → <sup id="fn1">) inside the article, which is
		// an acceptable trade-off in a read-later reader view - sanitizeHtml()'s
		// isPureFragmentLink() check unwraps such pure-fragment links anyway
		// (dropping the dead <a> but keeping its content), rather than leaving
		// them pointing nowhere.
		$html = preg_replace_callback(
			'/(<[^>]+\bid=["\'])([^"\']*?)(["\'])/i',
			static function (array $m): string {
				$id = trim($m[2]);
				if ($id !== '' && str_starts_with($id, 'merlin-')) {
					return $m[0];
				}
				// Drop the entire id attribute
				return preg_replace('/\s*\bid=["\'][^"\']*["\']/i', '', $m[0]) ?? $m[0];
			},
			$html
		) ?? $html;

		//$path = __DIR__ . "/../../test/cleanHtml.html";
		//file_put_contents($path, $html);

		// Remove comments
		$html = preg_replace('/<!--(.*)-->/Uis', '', $html);

		return trim($html);
	}

	/**
	 * DOM-basierter Allowlist-Sanitizer gegen Stored-XSS.
	 *
	 * Warum zusätzlich zu cleanHtml()? cleanHtml() arbeitet mit RegExp und filtert
	 * nur <script>/<style>, Klassen und IDs. Es entfernt NICHT die eigentlich
	 * gefährlichen Vektoren: Event-Handler-Attribute (onerror, onload, onclick, …),
	 * javascript:-/data:-URLs und gefährliche Tags (<iframe>, <object>, <embed>,
	 * <form>, …). Da der Inhalt per v-html gerendert wird – auch unauthentifiziert
	 * in der öffentlichen Share-Ansicht – wird hier über eine strikte Tag- und
	 * Attribut-Allowlist auf DOM-Ebene bereinigt.
	 *
	 * Allowlist statt Denylist: nur explizit erlaubte Tags/Attribute überleben,
	 * alles andere wird entfernt bzw. (bei unbekannten Tags) durch seinen Textinhalt
	 * ersetzt. Das ist gegen neue/obskure Vektoren robuster als eine Sperrliste.
	 */
	private function sanitizeHtml(string $html): string {
		if (trim($html) === '') {
			return $html;
		}

		// Erlaubte Tags – deckt den vom Reader gerenderten Inhalt ab (Fließtext,
		// Listen, Tabellen, Zitate, Bilder/Figuren, semantische Container).
		static $allowedTags = [
			'p', 'br', 'hr', 'span', 'div', 'section', 'article', 'header', 'footer', 'aside', 'main',
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins', 'mark', 'small', 'sub', 'sup',
			'a', 'blockquote', 'q', 'cite', 'code', 'pre', 'kbd', 'samp', 'var', 'abbr', 'time',
			'ul', 'ol', 'li', 'dl', 'dt', 'dd',
			'img', 'figure', 'figcaption', 'picture', 'source', 'video',
			'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
		];

		// Pro Tag erlaubte Attribute. Alles nicht Gelistete (insb. alle on*-Handler,
		// style, srcset auf beliebige URLs …) wird entfernt. `srcset` fehlt hier
		// bewusst — <source> trägt es laut Spec statt `src`, sodass jedes
		// <picture><source srcset="…"> sonst leerläuft und der Reader auf das
		// <img>-Fallback der Quellseite zurückfällt (bei taz.de z. B. bewusst ein
		// 14px-Platzhalter statt des echten Bilds). resolvePictureElements() liest
		// `srcset` deshalb VOR diesem Allowlist-Durchlauf aus und schreibt den besten
		// Kandidaten in ein einzelnes <img src>, das dann ganz normal die img-Regel
		// unten durchläuft.
		static $allowedAttrs = [
			'*'          => ['class', 'id', 'title', 'lang', 'dir'],
			'a'          => ['href', 'target', 'rel'],
			'img'        => ['src', 'alt', 'width', 'height'],
			'source'     => ['src', 'type', 'media'],
			// Moderne "GIF"-Ersätze (z. B. Ghost/Hugo-Blogs): stumme, per Attribut
			// autoplayende/loopende <video>-Elemente, die self-hosted vom
			// Quell-Server ausgeliefert werden (kein Embed eines Dritt-Players
			// wie bei iframe) – daher ohne isAllowedVideoEmbedSrc()-Prüfung direkt
			// auf $allowedTags, nur die Attribute laufen durch die Allowlist.
			'video'      => ['src', 'poster', 'width', 'height', 'autoplay', 'loop', 'muted', 'playsinline', 'controls'],
			'time'       => ['datetime'],
			'td'         => ['colspan', 'rowspan'],
			'th'         => ['colspan', 'rowspan', 'scope'],
			'col'        => ['span'],
			'colgroup'   => ['span'],
			// data-instgrm-permalink/-version: Instagrams offizielles Embed-Markup
			// (siehe isAllowedInstagramPermalink()). Ohne diese Attribute rendert
			// embed.js nur einen leeren Platzhalter statt des Posts.
			// data-bluesky-uri: Blueskys offizielles Embed-Markup (siehe
			// isAllowedBlueskyUri()) - analog für den Self-Thread-Zweig
			// (BlueskyThreadResolverService/ContentExtractorService, category=Thread).
			// cite/data-video-id: TikToks offizielles Embed-Markup, siehe
			// isAllowedTiktokEmbedCite() und die data-video-id-Prüfung in
			// sanitizeAttributes() - analog für den TikTokPost-Zweig.
			// Medien-Marker (siehe MediaResolverService::buildMarkerHtml()); die
			// Werte werden zusammen in sanitizeMediaMarker() geprüft.
			'div'        => ['data-media-kind', 'data-media-delivery', 'data-media-src', 'data-pdf-src'],
			'blockquote' => ['data-instgrm-permalink', 'data-instgrm-version', 'data-bluesky-uri', 'cite', 'data-video-id'],
			// iframe steht bewusst NICHT auf $allowedTags (generisches iframe-Embed
			// ist ein XSS-Vektor) – erlaubt sind nur Video-Embeds von vertrauens-
			// würdigen Hosts, siehe isAllowedVideoEmbedSrc(). Deren Attribute laufen
			// trotzdem durch dieselbe Allowlist-Logik, deshalb der Eintrag hier.
			'iframe'     => ['src', 'width', 'height', 'frameborder', 'allow', 'allowfullscreen', 'referrerpolicy'],
			// script steht ebenfalls bewusst NICHT auf $allowedTags – erlaubt sind
			// nur die offiziellen Widget-Loader von Instagram/X/Bluesky/TikTok, siehe
			// isAllowedWidgetScriptSrc(). Kein "onload" o. Ä. auf der Liste: das
			// Element darf ausschließlich diese drei harmlosen Lade-Attribute tragen.
			'script'     => ['src', 'async', 'charset'],
		];

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		// Wrapper-Element mit eindeutigem data-Attribut, um den Inhalt nach dem
		// Parsen zuverlässig wiederzufinden. XML-PI nur als Encoding-Hint.
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?><div data-merlin-sanitize-root="1">' . $html . '</div>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();

		// getElementById() ist bei loadHTML() unzuverlässig (ohne DTD werden keine
		// IDs registriert) – deshalb den Wrapper per XPath über das data-Attribut holen.
		$xpath = new \DOMXPath($dom);
		$root  = $xpath->query('//div[@data-merlin-sanitize-root="1"]')->item(0);
		libxml_use_internal_errors($prev);

		if ($root === null) {
			// Parsing fehlgeschlagen – im Zweifel den Inhalt verwerfen statt
			// ungefiltert durchzureichen (fail-closed).
			return '';
		}

		// Muss VOR dem generischen Allowlist-Durchlauf laufen: der liest gleich
		// `srcset` aus den <source>-Kindern jedes <picture> aus, das Attribut
		// steht unten aber nicht auf der Allowlist und würde sonst weggeworfen,
		// bevor wir es je zu Gesicht bekommen.
		$this->resolvePictureElements($dom);

		// Läuft hier statt als eigener Pipeline-Schritt, weil der Content dafür
		// sonst ein zweites Mal komplett geparst werden müsste – und weil erst
		// jetzt ALLE Bildunterschriften im Baum stehen (die aus der Quellseite,
		// die von normalizeImageCaptions() umgebauten und die nachträglich
		// eingefügte Hero-Caption aus Step 7b).
		$this->flattenCaptions($dom);

		$allowedTagSet = array_flip($allowedTags);

		// Alle Elemente einsammeln, BEVOR wir den Baum verändern (eine Live-NodeList
		// während der Iteration zu mutieren überspringt Knoten).
		$elements = [];
		foreach ($dom->getElementsByTagName('*') as $el) {
			$elements[] = $el;
		}

		foreach ($elements as $el) {
			// Bereits durch das Entfernen eines Vorfahren aus dem Baum gelöst?
			if ($el->ownerDocument === null || $el->parentNode === null) {
				continue;
			}

			$tag = strtolower($el->nodeName);

			// Der Wrapper selbst wird nicht mit ausgegeben (nur seine Kinder),
			// daher hier überspringen.
			if ($el === $root) {
				continue;
			}

			// <iframe> ist grundsätzlich ein XSS-Vektor und steht deshalb nicht auf
			// $allowedTags – Ausnahme: Video-Embeds von vertrauenswürdigen Hosts
			// (taz.de, Blogs, … betten YouTube/Vimeo/Twitch/… häufig ein). Nur bei
			// einer Quelle aus isAllowedVideoEmbedSrc() bleibt das Element erhalten,
			// alles andere fällt auf den generischen Denylist-Zweig unten durch und
			// wird entfernt.
			if ($tag === 'iframe') {
				if ($this->isAllowedVideoEmbedSrc($el->getAttribute('src'))) {
					$this->sanitizeAttributes($el, $tag, $allowedAttrs);
					// Erzwungen statt nur erlaubt: Nextclouds eigener
					// Referrer-Policy-Header steht standardmäßig auf
					// "no-referrer" (Security-Default via .htaccess/nginx).
					// Ohne einen Referrer verweigern manche Player den Embed
					// (z. B. YouTube mit "Error 153"). Das per-Element-Attribut
					// überschreibt die Seiten-Policy für genau diesen iframe-
					// Request – auf die Quellseite (die es i. d. R. nicht
					// mitliefert) ist hier kein Verlass, also selbst setzen statt
					// nur durchlassen.
					$el->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
				} else {
					$el->parentNode->removeChild($el);
				}
				continue;
			}

			// <script> ist ebenso grundsätzlich verboten – Ausnahme: exakt die
			// offiziellen Widget-Loader von Instagram/X/Bluesky/TikTok (siehe
			// isAllowedWidgetScriptSrc()), die deren jeweiliges
			// <blockquote data-instgrm-permalink="…">/<blockquote class="twitter-tweet">
			// erst zu einem Player/Post rendern. Anders als beim iframe-Sandbox
			// läuft dieses Skript MIT vollem DOM-Zugriff auf der Reader-Seite,
			// daher zusätzlich zum exakten Src-Match: keine Kindknoten erlaubt
			// (kein Einschleusen von Inline-JS über denselben Tag).
			if ($tag === 'script') {
				if ($this->isAllowedWidgetScriptSrc($el->getAttribute('src')) && !$el->hasChildNodes()) {
					$this->sanitizeAttributes($el, $tag, $allowedAttrs);
				} else {
					$el->parentNode->removeChild($el);
				}
				continue;
			}

			// Reine Sprungmarken-Links (z. B. <a href="#focus">) verweisen nur auf eine
			// Anker-ID der URSPRÜNGLICHEN Seite - deren Zielelement existiert im
			// extrahierten Artikel gar nicht mehr (id-Attribute werden oben in
			// cleanHtml() entfernt, siehe Kommentar dort). Nur den <a>-Wrapper
			// auflösen (Kinder an seine Stelle setzen), NICHT das ganze Element
			// samt Inhalt entfernen: manche Seiten (z. B. t-online.de) verwenden
			// genau so einen Link als reinen Lightbox-/Vollbild-Trigger UM ein
			// Bild - ein removeChild() hätte dort das Bild mitgelöscht. Die
			// hochgezogenen Kinder (z. B. dieses <img>) stehen bereits in
			// $elements und durchlaufen den Rest dieser Schleife normal weiter,
			// nur ohne den nun toten Link drumherum.
			if ($tag === 'a' && $this->isPureFragmentLink($el->getAttribute('href'))) {
				$parent = $el->parentNode;
				while ($el->firstChild !== null) {
					$parent->insertBefore($el->firstChild, $el);
				}
				$parent->removeChild($el);
				continue;
			}

			// Nicht erlaubtes Tag: <style>/<object>/<form>/… komplett
			// samt Inhalt entfernen; bei rein strukturellen Unknowns bliebe zwar Text
			// erhalten – da wir aber fail-closed sein wollen und die Allowlist den
			// gesamten Reader-Content abdeckt, entfernen wir das ganze Element.
			if (!isset($allowedTagSet[$tag])) {
				$el->parentNode->removeChild($el);
				continue;
			}

			$this->sanitizeAttributes($el, $tag, $allowedAttrs);
		}

		// Nur die Kinder des Wrapper-Containers zurückgeben.
		$out = '';
		foreach ($root->childNodes as $child) {
			$serialized = $dom->saveHTML($child);
			if ($serialized !== false) {
				$out .= $serialized;
			}
		}

		return trim($out);
	}

	/**
	 * Macht aus jeder Bildunterschrift eine einzige Zeile: Umbrüche werden durch
	 * self::CAPTION_SEPARATOR ("•") ersetzt.
	 *
	 * Sichtbare Umbrüche entstehen in einer <figcaption> auf genau zwei Wegen –
	 * beide werden hier abgebaut:
	 *   1. <br>-Elemente         → Trenner-Textknoten
	 *   2. Block-Elemente        → aufgelöst (Kinder bleiben), Trenner davor/danach
	 *
	 * Reine Whitespace-Umbrüche im Quell-HTML (Einrückung) sind KEINE sichtbaren
	 * Umbrüche und werden deshalb zu einem Leerzeichen zusammengefasst statt zu
	 * einem Trenner – sonst bekäme jede eingerückte Caption Bullets, die im
	 * Browser nie zu sehen waren.
	 *
	 * Inline-Auszeichnung (<a href="…">, <em>, <span>) bleibt erhalten, deshalb
	 * arbeitet die Bereinigung auf den Textknoten statt auf textContent.
	 */
	private function flattenCaptions(\DOMDocument $dom): void {
		$captions = iterator_to_array($dom->getElementsByTagName('figcaption'));
		if ($captions === []) {
			return;
		}

		foreach ($captions as $caption) {
			// Kann beim Auflösen einer umschliessenden Caption aus dem Baum
			// gefallen sein (verschachtelte <figcaption> im Quell-HTML).
			if (!$caption instanceof \DOMElement || $caption->parentNode === null) {
				continue;
			}

			$this->flattenCaptionElement($caption);
			$this->markCaptionCredit($caption);
		}
	}

	/**
	 * Markiert die Bildquelle einer (bereits geglätteten) <figcaption> als
	 * <cite>, damit Clients sie getrennt vom Bildtext darstellen können
	 * (z. B. kursiv/abgeblendet). Als Quelle gilt der Teil nach dem letzten
	 * CAPTION_BULLET auf oberster Ebene ("Ein Bild • Foto: dpa"); ohne Trenner
	 * die ganze Caption, wenn sie mit einem Quellen-Präfix beginnt
	 * ("Foto: dpa", "© dpa"). Alles andere bleibt unverändert.
	 */
	private function markCaptionCredit(\DOMElement $caption): void {
		$dom = $caption->ownerDocument;
		if ($dom === null || $caption->getElementsByTagName('cite')->length > 0) {
			return;
		}

		$topLevelSplit = null;
		$topLevelCount = 0;
		foreach ($caption->childNodes as $child) {
			if ($child instanceof \DOMText && str_contains((string) $child->nodeValue, self::CAPTION_BULLET)) {
				$topLevelSplit = $child;
				$topLevelCount++;
			}
		}

		$cite = $dom->createElement('cite');

		if ($topLevelSplit !== null) {
			// Trenner in verschachtelter Auszeichnung: Quelle nicht eindeutig.
			$total = 0;
			foreach ((new \DOMXPath($dom))->query('.//text()', $caption) as $text) {
				if (str_contains((string) $text->nodeValue, self::CAPTION_BULLET)) {
					$total++;
				}
			}
			if ($total !== $topLevelCount) {
				return;
			}

			$value = (string) $topLevelSplit->nodeValue;
			$pos   = mb_strrpos($value, self::CAPTION_BULLET);
			$tail  = ltrim(mb_substr($value, $pos + 1));
			$topLevelSplit->nodeValue = mb_substr($value, 0, $pos + 1) . ' ';
			if ($tail !== '') {
				$cite->appendChild($dom->createTextNode($tail));
			}
			while (($next = $topLevelSplit->nextSibling) !== null) {
				$cite->appendChild($next);
			}
			if (!$cite->hasChildNodes()) {
				return;
			}
			$caption->appendChild($cite);
			return;
		}

		if (preg_match('/^\s*(?:©|(?:Fotos?|Bilder?|Quellen?|Credits?|Copyright|Grafik|Illustration)\s*:)/iu', $caption->textContent) !== 1) {
			return;
		}
		while ($caption->firstChild !== null) {
			$cite->appendChild($caption->firstChild);
		}
		$caption->appendChild($cite);
	}

	/**
	 * Löst innerhalb einer einzelnen <figcaption> Block-Umbrüche (<br> und
	 * Block-Elemente wie <div>/<p>, siehe CAPTION_BLOCK_TAGS) durch
	 * CAPTION_SEPARATOR auf, damit z. B. separate "Caption"- und
	 * "Copyright"-<div>s (ohne Text dazwischen) nicht zu einem Wort
	 * verschmelzen ("…geheiratet.Quelle: privat" statt "…geheiratet. •
	 * Quelle: privat"). Mutiert den Knoten in place - von flattenCaptions()
	 * für den finalen Content-Baum genutzt, und von findFigcaption() für ein
	 * einzelnes, ohnehin gleich aus dem Baum entferntes Leitbild.
	 */
	private function flattenCaptionElement(\DOMElement $caption): void {
		$dom = $caption->ownerDocument;
		if ($dom === null) {
			return;
		}

		// 1. <br> durch den Trenner ersetzen.
		foreach (iterator_to_array($caption->getElementsByTagName('br')) as $br) {
			$br->parentNode?->replaceChild($dom->createTextNode(self::CAPTION_SEPARATOR), $br);
		}

		// 2. Block-Elemente auflösen. Rückwärts (= von innen nach aussen),
		//    damit verschachtelte Blöcke nicht ins Leere greifen.
		$blocks = [];
		foreach ($caption->getElementsByTagName('*') as $el) {
			if (in_array(strtolower($el->nodeName), self::CAPTION_BLOCK_TAGS, true)) {
				$blocks[] = $el;
			}
		}
		foreach (array_reverse($blocks) as $block) {
			$parent = $block->parentNode;
			if ($parent === null) {
				continue;
			}
			// Trenner VOR und NACH dem Block: ein Block beendet auch die Zeile,
			// der nachfolgende Inhalt begönne sonst ohne Trennung
			// (<div><p>A</p><span>B</span></div> rendert A und B untereinander).
			// Überzählige Trenner fallen in Schritt 3 wieder weg.
			$parent->insertBefore($dom->createTextNode(self::CAPTION_SEPARATOR), $block);
			while ($block->firstChild !== null) {
				$parent->insertBefore($block->firstChild, $block);
			}
			$parent->insertBefore($dom->createTextNode(self::CAPTION_SEPARATOR), $block);
			$parent->removeChild($block);
		}

		// 3. Textknoten glätten: Whitespace vereinheitlichen, Trenner-Ketten
		//    zusammenfassen, führende Trenner entfernen.
		$xpath          = new \DOMXPath($dom);
		$lastVisible    = null;
		$afterSeparator = true;   // Caption-Anfang zählt wie "gerade getrennt"
		foreach ($xpath->query('.//text()', $caption) as $text) {
			$value = preg_replace('/\s+/u', ' ', (string) $text->nodeValue) ?? '';
			$value = preg_replace(
				'/(?:\s*' . self::CAPTION_BULLET . '\s*)+/u',
				self::CAPTION_SEPARATOR,
				$value
			) ?? $value;
			if ($afterSeparator) {
				$value = preg_replace('/^\s*(?:' . self::CAPTION_BULLET . '\s*)?/u', '', $value) ?? $value;
			}
			$text->nodeValue = $value;

			if (trim($value) !== '') {
				$lastVisible    = $text;
				$afterSeparator = (bool) preg_match('/' . self::CAPTION_BULLET . '\s*$/u', $value);
			}
		}

		// 4. Trenner am Ende abschneiden (entsteht, wenn die Caption mit einem
		//    Block oder einem <br> aufhört).
		if ($lastVisible !== null) {
			$lastVisible->nodeValue = preg_replace(
				'/\s*(?:' . self::CAPTION_BULLET . '\s*)?$/u',
				'',
				(string) $lastVisible->nodeValue
			) ?? '';
		}
	}

	/**
	 * Löst jedes <picture> in seinen besten Bildkandidaten auf und ersetzt es
	 * durch ein einzelnes <img src="…">, BEVOR der generische Allowlist-Filter
	 * (sanitizeAttributes) `srcset` von den <source>-Kindern entfernt.
	 *
	 * Grund: <source> trägt die eigentlichen Bild-URLs ausschließlich über
	 * `srcset` (nie `src`) – ohne diese Auflösung bleibt nach dem Sanitizing nur
	 * das <img>-Fallback-Element von <picture> übrig. Manche Seiten (z. B.
	 * taz.de) legen dort absichtlich einen winzigen Low-Quality-Platzhalter ab,
	 * den echte Browser dank <picture>-Auswahlalgorithmus nie rendern – unser
	 * DOM-Parser kennt diesen Algorithmus aber nicht und würde ihn 1:1 in den
	 * Reader übernehmen (sichtbar als winzig dargestelltes "Hero image").
	 *
	 * Wählt pro <picture> den <source> mit der größten per "Nw"-Deskriptor
	 * bekannten Breite; ist keine Breite bekannt, gewinnt der erste <source>
	 * ohne `media`-Attribut (die geräteunabhängige Standardvariante) vor
	 * mobil-spezifischen Breakpoints. Liefert kein <source> einen auswertbaren
	 * Kandidaten, bleibt das <picture> unverändert (heutiges Verhalten).
	 */
	private function resolvePictureElements(\DOMDocument $dom): void {
		foreach (iterator_to_array($dom->getElementsByTagName('picture')) as $picture) {
			if (!$picture instanceof \DOMElement || $picture->parentNode === null) {
				continue;
			}

			$best         = null;
			$bestHasMedia = true;

			foreach (iterator_to_array($picture->getElementsByTagName('source')) as $source) {
				if (!$source instanceof \DOMElement) {
					continue;
				}
				$candidate = $this->bestSrcsetCandidate($source->getAttribute('srcset'));
				if ($candidate === null) {
					continue;
				}
				$hasMedia = $source->getAttribute('media') !== '';

				$better =
					$best === null
					|| ($candidate['width'] !== null && ($best['width'] === null || $candidate['width'] > $best['width']))
					|| ($candidate['width'] === null && $best['width'] === null && $bestHasMedia && !$hasMedia);

				if ($better) {
					$best         = $candidate;
					$bestHasMedia = $hasMedia;
				}
			}

			if ($best === null) {
				continue;
			}

			// Bestehendes <img>-Fallback wiederverwenden (behält alt/title),
			// sonst eines anlegen — <picture> ohne <img>-Kind ist ungültiges
			// HTML, kommt in der Praxis aber vereinzelt vor.
			$img = null;
			foreach ($picture->childNodes as $child) {
				if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'img') {
					$img = $child;
				}
			}
			if ($img === null) {
				$img = $dom->createElement('img');
				$picture->appendChild($img);
			}
			$img->setAttribute('src', $best['url']);

			$picture->removeChild($img);
			$picture->parentNode->replaceChild($img, $picture);
		}
	}

	/**
	 * Parst einen `srcset`-Wert ("url1 800w, url2 1200w" oder auch nur "url")
	 * und liefert den Kandidaten mit der größten bekannten Breite. Kandidaten
	 * ohne "Nw"-Breitendeskriptor (z. B. taz.de: nackte URL ohne Deskriptor,
	 * oder "x"-Pixeldichte-Deskriptoren) gelten als Breite `null` und werden
	 * nur als Fallback verwendet, wenn kein Kandidat mit bekannter Breite
	 * vorliegt — der jeweils erste gewinnt dann.
	 *
	 * @return array{url: string, width: int|null}|null
	 */
	private function bestSrcsetCandidate(string $srcset): ?array {
		$srcset = trim($srcset);
		if ($srcset === '') {
			return null;
		}

		$best = null;
		// Split only on a comma followed by whitespace: that's an actual
		// candidate boundary per the srcset grammar. A bare comma can appear
		// inside a candidate's URL itself (e.g. Substack/Cloudinary/Imgix CDN
		// transform-parameter lists like ".../w_1456,c_limit,f_webp,.../<url>"),
		// and splitting on every comma shreds such a URL into fragments.
		foreach (preg_split('/,\s+/', $srcset) as $entry) {
			$parts = preg_split('/\s+/', trim($entry), -1, PREG_SPLIT_NO_EMPTY);
			if ($parts === [] || $parts[0] === '') {
				continue;
			}
			$url   = $parts[0];
			$width = null;
			if (isset($parts[1]) && preg_match('/^(\d+)w$/', $parts[1], $m)) {
				$width = (int) $m[1];
			}
			if ($best === null || ($width !== null && ($best['width'] === null || $width > $best['width']))) {
				$best = ['url' => $url, 'width' => $width];
			}
		}
		return $best;
	}

	/**
	 * Entfernt an einem Element alle nicht erlaubten Attribute und neutralisiert
	 * gefährliche URL-Schemata in href/src.
	 *
	 * @param array<string, list<string>> $allowedAttrs
	 */
	private function sanitizeAttributes(\DOMElement $el, string $tag, array $allowedAttrs): void {
		$globalAllowed = $allowedAttrs['*'] ?? [];
		$tagAllowed    = $allowedAttrs[$tag] ?? [];
		$allowed       = array_flip(array_merge($globalAllowed, $tagAllowed));

		// Attribute-Liste vorher kopieren – das Entfernen mutiert die Live-NamedNodeMap.
		$attrNames = [];
		foreach ($el->attributes as $attr) {
			$attrNames[] = $attr->nodeName;
		}

		foreach ($attrNames as $name) {
			$lname = strtolower($name);

			// Nicht erlaubt (fängt insbesondere ALLE on*-Handler und style ab).
			if (!isset($allowed[$lname])) {
				$el->removeAttribute($name);
				continue;
			}

			// URL-Attribute gegen gefährliche Schemata absichern.
			if ($lname === 'href' || $lname === 'src') {
				$value = trim($el->getAttribute($name));
				if ($this->isDangerousUrl($value)) {
					$el->removeAttribute($name);
					continue;
				}
			}

			// Instagrams Embed-Markup trägt die Post-URL in einem data-Attribut statt
			// href/src – muss trotzdem auf instagram.com zeigen, sonst könnte das
			// Widget-Skript (siehe isAllowedWidgetScriptSrc()) beliebige fremde Inhalte
			// nachladen/darstellen.
			if ($tag === 'blockquote' && $lname === 'data-instgrm-permalink') {
				$value = trim($el->getAttribute($name));
				if (!$this->isAllowedInstagramPermalink($value)) {
					$el->removeAttribute($name);
					continue;
				}
			}

			// Blueskys Embed-Markup trägt die Post-Identität als at://-URI in
			// einem data-Attribut statt href/src – muss syntaktisch eine
			// gültige app.bsky.feed.post-URI sein, siehe isAllowedBlueskyUri().
			if ($tag === 'blockquote' && $lname === 'data-bluesky-uri') {
				$value = trim($el->getAttribute($name));
				if (!$this->isAllowedBlueskyUri($value)) {
					$el->removeAttribute($name);
					continue;
				}
			}

			// TikToks Embed-Markup trägt die Video-URL im "cite"-Attribut statt
			// href/src – muss trotzdem auf tiktok.com zeigen, sonst könnte das
			// Widget-Skript (siehe isAllowedWidgetScriptSrc()) beliebige fremde
			// Inhalte nachladen/darstellen.
			if ($tag === 'blockquote' && $lname === 'cite') {
				$value = trim($el->getAttribute($name));
				if (!$this->isAllowedTiktokEmbedCite($value)) {
					$el->removeAttribute($name);
					continue;
				}
			}

			// "data-video-id" muss eine reine Zahlenfolge sein (TikToks Video-IDs
			// sind numerisch) - ohne diese Prüfung könnte hier beliebiger Text
			// stehen, den das Widget-Skript unvalidiert weiterverwendet.
			if ($tag === 'blockquote' && $lname === 'data-video-id') {
				$value = trim($el->getAttribute($name));
				if (!preg_match('/^\d+$/', $value)) {
					$el->removeAttribute($name);
					continue;
				}
			}
		}

		if ($tag === 'div' && $el->hasAttribute('data-media-kind')) {
			$this->sanitizeMediaMarker($el);
		}

		// PDF-Marker (siehe buildPdfResult()): nur http(s)-URLs, sonst fliegt das
		// Attribut raus und der Client zeigt nur den Fallback-Link.
		if ($tag === 'div' && $el->hasAttribute('data-pdf-src')) {
			$pdfSrc = trim($el->getAttribute('data-pdf-src'));
			if (!preg_match('#^https?://#i', $pdfSrc) || $this->isDangerousUrl($pdfSrc)) {
				$el->removeAttribute('data-pdf-src');
			}
		}

		// Bei Links, die in einem neuen Tab geöffnet werden, rel härten
		// (Schutz gegen window.opener-Tabnabbing).
		if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
			$el->setAttribute('rel', 'noopener noreferrer');
		}
	}

	/**
	 * Prüft die data-media-*-Attribute eines Medien-Markers als Einheit: die
	 * Medienart muss bekannt sein (sonst fliegen alle drei Attribute raus),
	 * Quelle und Auslieferung nur gemeinsam und nur mit https-URL – ein Embed
	 * zusätzlich nur von einem Host aus isAllowedVideoEmbedSrc(), denn der
	 * Reader rendert ihn als iframe. Bei ungültiger Quelle bleibt ein Marker
	 * ohne URL stehen (Reader fragt dann GET /media).
	 */
	private function sanitizeMediaMarker(\DOMElement $el): void {
		if (!in_array($el->getAttribute('data-media-kind'), MediaResult::KINDS, true)) {
			$el->removeAttribute('data-media-kind');
			$el->removeAttribute('data-media-delivery');
			$el->removeAttribute('data-media-src');
			return;
		}

		$delivery = $el->getAttribute('data-media-delivery');
		$src      = trim($el->getAttribute('data-media-src'));
		$valid    = in_array($delivery, MediaResult::DELIVERIES, true)
			&& str_starts_with(strtolower($src), 'https://')
			&& !$this->isDangerousUrl($src)
			&& ($delivery !== MediaResult::DELIVERY_EMBED || $this->isAllowedVideoEmbedSrc($src));
		if (!$valid) {
			$el->removeAttribute('data-media-delivery');
			$el->removeAttribute('data-media-src');
		}
	}

	/**
	 * true, wenn $src ein Video-Embed eines der fest hinterlegten, vertrauens-
	 * würdigen Hosts ist (https, exakter Host-Match, erforderliches Pfad-
	 * Präfix). Das ist die einzige Ausnahme von der iframe-Denylist in
	 * sanitizeHtml() – bewusst eng gefasst (kein Wildcard-Host, kein Schema-
	 * Downgrade), weil ein erlaubtes iframe sonst zum offenen SSRF-/
	 * Clickjacking-Vektor würde. Passend dazu muss
	 * AddContentSecurityPolicyListener frame-src auf dieselben Hosts begrenzen,
	 * sonst rendert der Browser das Embed trotz durchgelassenem Markup nicht.
	 *
	 * ARD Mediathek/ZDF sind bewusst NICHT gelistet: ARD bietet keinen
	 * dokumentierten Embed-Mechanismus, ZDFs tatsächlicher iframe-Host ist
	 * unverifiziert – siehe Plan/Commit-Historie. Erst nach manueller
	 * Verifikation eines echten Embed-Codes hier ergänzen, nicht raten.
	 */
	private function isAllowedVideoEmbedSrc(string $src): bool {
		$src = trim($src);
		if ($src === '') {
			return false;
		}

		$parts = parse_url($src);
		// 'path' bewusst NICHT in isset() – parse_url() liefert keinen path-Key,
		// wenn die URL keinen (z. B. "https://player.twitch.tv?channel=…"), das
		// ist trotzdem eine gültige, im Zweifel erlaubte URL (siehe Twitch unten).
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}

		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}

		$host = strtolower($parts['host']);
		$path = $parts['path'] ?? '';

		// Host => erforderliches Pfad-Präfix, null = kein Präfix nötig (Twitch
		// unterscheidet Kanal/VOD/Clip rein über Query-Parameter).
		static $allowedHostPrefixes = [
			'www.youtube.com'          => '/embed/',
			'youtube.com'              => '/embed/',
			'www.youtube-nocookie.com' => '/embed/',
			'youtube-nocookie.com'     => '/embed/',
			'player.vimeo.com'         => '/video/',
			'player.twitch.tv'         => null,
			'www.tiktok.com'           => '/player/v1/',
			'www.facebook.com'         => '/plugins/video.php',
			'www.arte.tv'              => '/player/v5/index.php',
		];

		if (!array_key_exists($host, $allowedHostPrefixes)) {
			return false;
		}

		$requiredPrefix = $allowedHostPrefixes[$host];
		if ($requiredPrefix !== null && !str_starts_with($path, $requiredPrefix)) {
			return false;
		}

		// Facebooks und Artes Player nehmen ihrerseits eine fremde URL als
		// Query-Parameter entgegen (href/json_url) und laden von dort nach –
		// ohne diese Prüfung wäre der jeweilige Player ein offenes
		// Redirect-/SSRF-artiges Gadget auf beliebige Ziel-URLs.
		parse_str($parts['query'] ?? '', $query);
		if ($host === 'www.facebook.com'
			&& !$this->hasAllowedQueryUrlHost((string) ($query['href'] ?? ''), ['facebook.com', 'www.facebook.com'])) {
			return false;
		}
		if ($host === 'www.arte.tv'
			&& !$this->hasAllowedQueryUrlHost((string) ($query['json_url'] ?? ''), ['api.arte.tv'])) {
			return false;
		}

		return true;
	}

	/**
	 * true, wenn $url eine https-URL ist, deren Host in $allowedHosts steht.
	 * Absichert Player, die selbst eine fremde URL als Query-Parameter
	 * entgegennehmen (siehe isAllowedVideoEmbedSrc() für Facebook/Arte).
	 */
	private function hasAllowedQueryUrlHost(string $url, array $allowedHosts): bool {
		if ($url === '') {
			return false;
		}

		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}

		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}

		return in_array(strtolower($parts['host']), $allowedHosts, true);
	}

	/**
	 * true, wenn $src exakt einer der offiziellen Widget-Loader von
	 * Instagram/X/Bluesky/TikTok ist. Bewusst als exakter String-Match (nicht
	 * nur Host/Pfad-Präfix wie bei isAllowedVideoEmbedSrc()): anders als ein
	 * sandboxed iframe läuft dieses Skript MIT vollem DOM-Zugriff auf der
	 * Reader-Seite, daher hier die engstmögliche Fassung.
	 */
	private function isAllowedWidgetScriptSrc(string $src): bool {
		$src = trim($src);
		if ($src === '') {
			return false;
		}

		// Instagram/X/Bluesky/TikTok liefern ihren offiziellen Embed-Code oft
		// protokollrelativ ("//www.instagram.com/embed.js") aus – vor dem
		// exakten Match auf https normalisieren.
		if (str_starts_with($src, '//')) {
			$src = 'https:' . $src;
		}

		static $allowedScriptSrcs = [
			'https://www.instagram.com/embed.js',
			'https://platform.twitter.com/widgets.js',
			// X liefert seinen Embed-Loader inzwischen auch (teils ausschließlich) unter
			// der eigenen Domain aus, z. B. bei in Artikel eingebetteten Tweets auf
			// spiegel.de - platform.twitter.com bleibt parallel gültig (Alt-Embeds,
			// Merlins eigener buildXPostHtml()), deshalb beide statt Ersatz.
			'https://platform.x.com/widgets.js',
			'https://embed.bsky.app/static/embed.js',
			'https://www.tiktok.com/embed.js',
		];

		return in_array($src, $allowedScriptSrcs, true);
	}

	/**
	 * true, wenn $url eine https-URL auf (www.)instagram.com ist. Für das
	 * data-instgrm-permalink-Attribut von Instagrams Embed-<blockquote>, siehe
	 * sanitizeAttributes().
	 */
	private function isAllowedInstagramPermalink(string $url): bool {
		if ($url === '') {
			return false;
		}

		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}

		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}

		return in_array(strtolower($parts['host']), ['www.instagram.com', 'instagram.com'], true);
	}

	/**
	 * true, wenn $uri eine syntaktisch gültige at://-URI eines
	 * app.bsky.feed.post-Records ist. Für das data-bluesky-uri-Attribut von
	 * Blueskys Embed-<blockquote>, siehe sanitizeAttributes() - dort ist der
	 * eigentliche Vertrauensanker aber ohnehin, dass diese URIs ausschließlich
	 * von uns selbst erzeugt werden (BlueskyThreadResolverService, aus einer
	 * API-Antwort desselben public.api.bsky.app-Hosts), nicht aus Fremd-HTML.
	 * Diese Prüfung ist Defense-in-Depth gegen ein verändertes/fehlerhaftes
	 * Content-Filter-Custom (Admin-/User-Ebene), keine Vertrauensentscheidung.
	 */
	private function isAllowedBlueskyUri(string $uri): bool {
		return preg_match('#^at://did:[a-z0-9]+:[A-Za-z0-9._:%-]+/app\.bsky\.feed\.post/[A-Za-z0-9._~-]+$#', $uri) === 1;
	}

	/**
	 * true, wenn $url eine https-URL auf (www.)tiktok.com ist. Für das
	 * cite-Attribut von TikToks Embed-<blockquote>, siehe sanitizeAttributes().
	 */
	private function isAllowedTiktokEmbedCite(string $url): bool {
		if ($url === '') {
			return false;
		}

		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}

		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}

		return in_array(strtolower($parts['host']), ['www.tiktok.com', 'tiktok.com'], true);
	}

	/**
	 * true, wenn die URL ein gefährliches Schema trägt (javascript:, data:, vbscript:).
	 * Relative URLs, Anchor-Links (#…), sowie http/https/mailto sind erlaubt.
	 */
	private function isDangerousUrl(string $url): bool {
		// Führende Steuerzeichen/Whitespace entfernen – Browser ignorieren sie beim
		// Scheme-Parsing (z. B. "java\tscript:…").
		$normalized = strtolower(preg_replace('/[\x00-\x20]+/', '', $url) ?? $url);

		return str_starts_with($normalized, 'javascript:')
			|| str_starts_with($normalized, 'vbscript:')
			|| str_starts_with($normalized, 'data:');
	}

	/**
	 * Ein href, der ausschließlich aus einer Sprungmarke besteht (z. B. "#focus",
	 * "#top" oder das bloße JS-Platzhalter-"#") - kein Schema, kein Host, kein
	 * Pfad. Ein href wie "https://example.com/artikel#abschnitt" oder
	 * "/seite#abschnitt" zählt bewusst NICHT dazu: dort führt der Link noch
	 * woandershin, die Sprungmarke ist nur ein Zusatz.
	 */
	private function isPureFragmentLink(string $href): bool {
		return str_starts_with(trim($href), '#');
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Published Date Extraction
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Extract article publication date from meta tags and structured data.
	 * Always returns UTC so that timezone offsets in the source string are
	 * preserved semantically even after MySQL strips the offset on storage.
	 */
	private function extractPublishedDate(string $html, string $content = ''): ?\DateTime {
		$dateString = null;

		// 1. og:article:published_time — most reliable, always prefer
		if (preg_match('/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
			$dateString = $m[1];
		} elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']article:published_time["\'][^>]*>/i', $html, $m)) {
			$dateString = $m[1];
		// 2. JSON-LD datePublished
		} elseif (preg_match('/"datePublished"\s*:\s*"([^"]+)"/i', $html, $m)) {
			$dateString = $m[1];
		// 3. meta name="pubdate"
		} elseif (preg_match('/<meta[^>]+name=["\']pubdate["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
			$dateString = $m[1];
		// 4. meta name="date" — used by e.g. rbb24 (may carry a non-standard "-T"
		//    typo instead of "T" as the date/time separator; normalise before use)
		} elseif (preg_match('/<meta[^>]+name=["\']date["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
			// Fix common CMS typo: "2026-03-22-T17:26:21" → "2026-03-22T17:26:21"
			$dateString = preg_replace('/(\d{4}-\d{2}-\d{2})-T/', '$1T', $m[1]);
		}

		// 5. <time datetime="..."> in extracted content (DOMXPath — much more
		//    reliable than searching raw HTML, which may match nav/footer timestamps)
		if ($dateString === null && $content !== '') {
			$dateString = $this->extractTimeTagFromContent($content);
		}

		// 6. Last resort: <time datetime="..."> anywhere in the raw HTML
		if ($dateString === null) {
			if (preg_match('/<time[^>]+datetime=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
				$candidate = $m[1];
				if ($this->looksLikeDate($candidate) || $this->looksLikeGermanDate($candidate)) {
					$dateString = $candidate;
				}
			}
		}

		if ($dateString === null) {
			return null;
		}

		return $this->parseDateString($dateString);
	}

	/**
	 * Search for the first <time datetime="..."> element in Readability-extracted
	 * content using DOMXPath. Returns the datetime string if it looks like a date,
	 * null otherwise.
	 *
	 * Handles both ISO 8601 values (2026-03-22T…) and locale-formatted values
	 * such as the German "DD.MM.YYYY HH:MM:SS" used by rbb24.
	 */
	private function extractTimeTagFromContent(string $content): ?string {
		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath = new \DOMXPath($dom);
		foreach ($xpath->query('//time[@datetime]') as $node) {
			/** @var \DOMElement $node */
			$datetime = trim($node->getAttribute('datetime'));
			if ($datetime === '') {
				continue;
			}
			if ($this->looksLikeDate($datetime) || $this->looksLikeGermanDate($datetime)) {
				return $datetime;
			}
		}
		return null;
	}

	/**
	 * Parse a date string to a UTC DateTime, handling:
	 *   - ISO 8601 with optional fractional seconds and timezone offset
	 *   - German locale format: "DD.MM.YYYY [HH:MM:SS]"
	 *
	 * Using createFromFormat() for known patterns avoids the ambiguity of
	 * PHP's DateTimeImmutable constructor, which cannot parse German dates
	 * and behaves inconsistently with fractional-second ISO strings in PHP < 8.
	 */
	private function parseDateString(string $dateString): ?\DateTime {
		$utc = new \DateTimeZone('UTC');

		// German date: DD.MM.YYYY HH:MM:SS  or  DD.MM.YYYY
		if ($this->looksLikeGermanDate($dateString)) {
			$fmt = str_contains($dateString, ' ') ? 'd.m.Y H:i:s' : 'd.m.Y';
			$dt  = \DateTimeImmutable::createFromFormat($fmt, $dateString);
			if ($dt !== false) {
				return \DateTime::createFromImmutable($dt->setTimezone($utc));
			}
		}

		// ISO 8601 — strip fractional seconds before handing to the constructor
		// so that PHP 7.x (which mishandles .NNN milliseconds in DateTimeImmutable)
		// also works correctly.
		$normalised = preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+/', '$1', $dateString) ?? $dateString;

		try {
			$immutable = new \DateTimeImmutable($normalised);
			return \DateTime::createFromImmutable($immutable->setTimezone($utc));
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Returns true when $value starts with a four-digit year and ISO dash separator
	 * (i.e. looks like an ISO 8601 date: YYYY-MM-…).
	 */
	private function looksLikeDate(string $value): bool {
		return (bool) preg_match('/^\d{4}-\d{2}/', $value);
	}

	/**
	 * Returns true when $value matches the German locale date format DD.MM.YYYY.
	 */
	private function looksLikeGermanDate(string $value): bool {
		return (bool) preg_match('/^\d{2}\.\d{2}\.\d{4}/', $value);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Metadata deduplication
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Remove headline and teaser paragraph from article content when they
	 * duplicate the title / excerpt fields that both clients render separately
	 * above the hero image.
	 *
	 * Sites like taz.de use CSS `order` to visually place the article title and
	 * intro paragraph before the body text, but in DOM order those elements live
	 * inside the <article> container and are therefore picked up by Readability.
	 * Without this pass they would appear twice in the reader view.
	 *
	 * Matching is intentionally fuzzy:
	 *   - Heading: ≥ 70 % of its normalised words appear in the normalised title.
	 *     Handles shortened headings (e.g. taz.de omits the kicker prefix).
	 *   - Teaser paragraph: ≥ 75 % word-overlap in either direction with the
	 *     excerpt (og:description / meta description).
	 *
	 * Both checks are limited to the first few occurrences so that legitimate
	 * sub-headings and body paragraphs deep in the article are never touched.
	 */
	private function stripDuplicateMetadata(string $content, string $title, ?string $excerpt): string {
		if (empty($content) || empty($title)) {
			return $content;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xpath = new \DOMXPath($dom);
		$body  = $dom->getElementsByTagName('body')->item(0);
		if (!$body) {
			return $content;
		}

		$changed     = false;
		$normalTitle = $this->normalizeForComparison($title);
		$titleWords  = array_filter(explode(' ', $normalTitle));

		// --- Pass 1: remove first heading (h1–h4) that duplicates the title ----
		$checked = 0;
		foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4]', $body) as $heading) {
			if (++$checked > 5) {
				break;
			}
			$normalHead = $this->normalizeForComparison($heading->textContent);
			$headWords  = array_filter(explode(' ', $normalHead));

			if (count($titleWords) === 0 || count($headWords) === 0) {
				continue;
			}

			if (count($titleWords) < 3 || count($headWords) < 3) {
				// Short title: require exact normalised match to avoid false positives
				$match = ($normalTitle === $normalHead);
			} else {
				// Longer title: 70 % word overlap is sufficient
				$shared = count(array_intersect($headWords, $titleWords));
				$match  = ($shared / count($headWords)) >= 0.70;
			}

			if ($match) {
				$heading->parentNode?->removeChild($heading);
				$changed = true;
				break; // Remove at most one heading
			}
		}

		// --- Pass 1b: title as a leading <p> (some CMSes skip heading tags) ----
		// Only run when no heading was removed and the title has at least 2 words.
		if (!$changed && count($titleWords) >= 2) {
			$checked = 0;
			foreach ($xpath->query('//p', $body) as $para) {
				if (++$checked > 3) {
					break;
				}
				$normalPara = $this->normalizeForComparison($para->textContent);
				if ($normalPara === $normalTitle) {
					$para->parentNode?->removeChild($para);
					$changed = true;
					break;
				}
			}
		}

		if (!$changed) {
			return $content;
		}

		$out = '';
		foreach ($body->childNodes as $child) {
			$out .= $dom->saveHTML($child);
		}
		return trim($out) ?: $content;
	}

	/**
	 * Normalise a string for text-similarity comparison:
	 * strip HTML tags, decode entities, lowercase, remove punctuation,
	 * collapse whitespace.
	 */
	private function normalizeForComparison(string $text): string {
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
		$text = mb_strtolower($text, 'UTF-8');
		// Keep only Unicode letters, digits and spaces
		$text = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
		$text = (string) preg_replace('/\s+/', ' ', $text);
		return trim($text);
	}
}