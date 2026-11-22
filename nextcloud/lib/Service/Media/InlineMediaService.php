<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media;

use OCA\Merlin\Service\ContentFilterSchema;
use Psr\Log\LoggerInterface;

/**
 * Videos MITTEN im Artikeltext (zusätzlich zum Aufmacher-Medium aus
 * <media><source>, siehe MediaResolverService): deklariert über
 * <media><inline> in der Domain-Config, z. B. für rbb24.de:
 *
 *   <inline type="ard-player"
 *           container-xpath="//figure[contains(@class,'component-video')]"
 *           caption-xpath=".//figcaption//*[contains(@class,'headline')] | following-sibling::div[contains(@class,'component-video-caption')][1]"
 *           host-allow="ard-mcdn.de" />
 *
 * Zwei Schritte im Extractor:
 *   1. replace() VOR Readability: jeder Container wird durch eine schlichte
 *      <figure class="merlin-inline-media …"> mit Vorschaubild und
 *      Bildunterschrift ersetzt. Die aufgelöste Quelle hängt nur als
 *      Referenz-Klasse (merlin-inline-media-ref-N) an der Figure –
 *      data-Attribute würde der Weg durch Readability/cleanHtml() nicht
 *      zuverlässig überstehen, merlin-*-Klassen schon.
 *   2. injectMarkers() NACH cleanHtml(): in jede noch vorhandene Figure wird
 *      der Quellen-Marker (div.merlin-inline-media-source mit
 *      data-media-kind/-delivery/-src) samt Fallback-Link eingesetzt. Der
 *      Sanitizer prüft dessen Attribute wie beim Aufmacher-Marker
 *      (sanitizeMediaMarker()).
 *
 * Der Reader legt dann auf jede solche Figure einen MediaPlayer
 * (src/inline-media.js). Bewusst eine eigene Marker-Klasse statt
 * "merlin-media": parseMarker() (Backend wie Frontend) soll ein Inline-Video
 * nie für das Aufmacher-Medium halten.
 *
 * Nur stabile Quellen (mp4-Datei, notfalls HLS-Manifest) – die URL wird beim
 * Speichern in den Content geschrieben, wie bei MediaResult::isPersistable().
 * Fail-closed: lässt sich eine Quelle nicht auflösen, bleibt der Container
 * unverändert stehen.
 */
class InlineMediaService {
	/** Klasse der Figure im Content. */
	public const FIGURE_CLASS = 'merlin-inline-media';

	/** Klasse des Quellen-Markers in der Figure. */
	public const SOURCE_CLASS = 'merlin-inline-media-source';

	/**
	 * Klasse des Fallback-Links im Marker – bewusst nicht
	 * MediaResolverService::FALLBACK_LINK_CLASS, denn den blendet der Reader
	 * aus, sobald der AUFMACHER-Player läuft.
	 */
	public const FALLBACK_LINK_CLASS = 'merlin-inline-media-fallback-link';

	/** Präfix der Referenz-Klasse zwischen replace() und injectMarkers(). */
	private const REF_CLASS_PREFIX = 'merlin-inline-media-ref-';

	/**
	 * Enthält "content": überlebt so Readabilitys unlikelyCandidates-Pass,
	 * siehe merlin-content-figure in normalizeImageCaptions().
	 */
	private const SURVIVAL_CLASS = 'merlin-inline-media-content';

	/** Obergrenze je Artikel – jeder Treffer kostet einen HTTP-Request. */
	private const MAX_PER_ARTICLE = 8;

	private const CAPTION_SEPARATOR = ' • ';

	public function __construct(
		private MediaHttpClient $http,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * true, wenn die Config mindestens eine <media><inline>-Regel hat.
	 */
	public function hasRules(?\SimpleXMLElement $config): bool {
		return $this->rules($config) !== [];
	}

	/**
	 * Schritt 1 (vor Readability): Container durch Figures ersetzen.
	 *
	 * @return array{html: string, media: array<int, MediaResult>}
	 *         media: Referenznummer → aufgelöste Quelle
	 */
	public function replace(string $html, string $articleUrl, ?\SimpleXMLElement $config): array {
		$rules = $this->rules($config);
		if ($rules === []) {
			return ['html' => $html, 'media' => []];
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xpath = new \DOMXPath($dom);

		$media = [];
		foreach ($rules as $rule) {
			$containerXpath = trim((string) ($rule['container-xpath'] ?? ''));
			$captionXpath   = trim((string) ($rule['caption-xpath'] ?? ''));
			$allowedHosts   = VariantHelper::parseHostList((string) ($rule['host-allow'] ?? ''));
			if ($containerXpath === '') {
				continue;
			}

			$containers = @$xpath->query($containerXpath);
			if ($containers === false) {
				$this->logger->warning('content-filters: invalid inline container-xpath skipped', ['xpath' => $containerXpath]);
				continue;
			}

			foreach (iterator_to_array($containers) as $container) {
				if (count($media) >= self::MAX_PER_ARTICLE) {
					break 2;
				}
				if (!$container instanceof \DOMElement || $container->parentNode === null) {
					continue;
				}

				$result = $this->resolveArdPlayer($container, $xpath, $articleUrl, $allowedHosts);
				if ($result === null) {
					continue;
				}

				$captionNodes = $captionXpath !== '' ? @$xpath->query($captionXpath, $container) : null;
				$caption      = $captionNodes instanceof \DOMNodeList ? $this->captionText($captionNodes) : '';

				$ref    = count($media);
				$figure = $this->buildFigure($dom, $ref, $this->posterImage($container, $xpath, $articleUrl), $caption);
				$container->parentNode->replaceChild($figure, $container);
				$media[$ref] = $result;

				// Bildunterschriften AUSSERHALB des Containers (rbb24: eigenes
				// Geschwister-div) stünden sonst als verwaister Text unter der
				// Figure – sie sind jetzt Teil von deren figcaption.
				if ($captionNodes instanceof \DOMNodeList) {
					foreach (iterator_to_array($captionNodes) as $node) {
						if ($node instanceof \DOMElement && $node->parentNode !== null && !$this->isInside($node, $figure)) {
							$node->parentNode->removeChild($node);
						}
					}
				}
			}
		}

		if ($media === []) {
			return ['html' => $html, 'media' => []];
		}

		$out = $dom->saveHTML();
		return ['html' => $out === false ? $html : $out, 'media' => $media];
	}

	/**
	 * Schritt 2 (nach cleanHtml()): Quellen-Marker in die Figures einsetzen.
	 * Figures ohne passende Referenz verlieren nur die Referenz-Klasse und
	 * bleiben als normales Bild stehen.
	 *
	 * @param array<int, MediaResult> $media
	 */
	public function injectMarkers(string $content, array $media): string {
		if ($media === [] || !str_contains($content, self::REF_CLASS_PREFIX)) {
			return $content;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?><div id="merlin-inline-root">' . $content . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xpath = new \DOMXPath($dom);

		$figures = $xpath->query("//figure[contains(concat(' ', normalize-space(@class), ' '), ' " . self::FIGURE_CLASS . " ')]");
		foreach ($figures ?: [] as $figure) {
			if (!$figure instanceof \DOMElement) {
				continue;
			}
			$ref     = null;
			$classes = [];
			foreach (preg_split('/\s+/', trim($figure->getAttribute('class'))) ?: [] as $class) {
				if (str_starts_with($class, self::REF_CLASS_PREFIX)) {
					$ref = (int) substr($class, strlen(self::REF_CLASS_PREFIX));
					continue;
				}
				$classes[] = $class;
			}
			$figure->setAttribute('class', implode(' ', $classes));

			$result = $ref !== null ? ($media[$ref] ?? null) : null;
			if ($result === null) {
				continue;
			}

			$marker = $dom->createElement('div');
			$marker->setAttribute('class', self::SOURCE_CLASS);
			$marker->setAttribute('data-media-kind', $result->kind);
			$marker->setAttribute('data-media-delivery', $result->delivery);
			$marker->setAttribute('data-media-src', $result->defaultUrl());
			$link = $dom->createElement('a');
			$link->setAttribute('href', $result->defaultUrl());
			$link->setAttribute('class', self::FALLBACK_LINK_CLASS);
			$link->textContent = $result->kind === MediaResult::KIND_AUDIO ? 'Zum Audio' : 'Zum Video';
			$marker->appendChild($link);

			// Direkt hinter dem Vorschaubild, vor der figcaption.
			$caption = null;
			foreach ($figure->childNodes as $child) {
				if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'figcaption') {
					$caption = $child;
					break;
				}
			}
			$figure->insertBefore($marker, $caption);
		}

		$root = $dom->getElementById('merlin-inline-root');
		if ($root === null) {
			return $content;
		}
		$out = '';
		foreach ($root->childNodes as $child) {
			$out .= $dom->saveHTML($child);
		}
		return $out;
	}

	/**
	 * true, wenn eine der Inline-Quellen dasselbe Medium wie $other ist.
	 * ARD-CDNs legen alle Qualitäten eines Videos im selben Verzeichnis ab
	 * (…/<uuid>/<uuid>_9zu16-sm960.mp4), deshalb zählt Host + Verzeichnis.
	 *
	 * @param array<int, MediaResult> $media
	 */
	public function containsSameMedia(array $media, MediaResult $other): bool {
		$key = static function (string $url): ?string {
			$host = parse_url($url, PHP_URL_HOST);
			$path = parse_url($url, PHP_URL_PATH);
			return is_string($host) && is_string($path) ? strtolower($host) . dirname($path) : null;
		};
		$otherKey = $key($other->defaultUrl());
		foreach ($media as $result) {
			if ($result->defaultUrl() === $other->defaultUrl() || ($otherKey !== null && $key($result->defaultUrl()) === $otherKey)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * ARD-Player (rbb24, tagesschau & Co.): data-player-config enthält als
	 * JSON die URL einer Medien-Beschreibung ("media": ….jsn) mit
	 * _mediaArray[]._mediaStreamArray[]._stream. Bevorzugt wird die
	 * höchste mp4-Qualität (spielt ohne hls.js, auch in der Share-Ansicht),
	 * sonst ein HLS-Manifest – jeweils nur von host-allow.
	 *
	 * @param list<string> $allowedHosts
	 */
	private function resolveArdPlayer(\DOMElement $container, \DOMXPath $xpath, string $articleUrl, array $allowedHosts): ?MediaResult {
		$configNode = $xpath->query('descendant-or-self::*[@data-player-config]', $container)?->item(0);
		if (!$configNode instanceof \DOMElement) {
			return null;
		}
		$playerConfig = json_decode($configNode->getAttribute('data-player-config'), true);
		$mediaUrl     = is_array($playerConfig) && is_string($playerConfig['media'] ?? null)
			? $this->absoluteUrl($playerConfig['media'], $articleUrl)
			: null;
		// Die Medien-Beschreibung liegt beim Sender selbst: nur https auf der
		// Domain des Artikels, keine beliebigen Hosts aus dem Seiten-HTML.
		$articleHost = strtolower((string) parse_url($articleUrl, PHP_URL_HOST));
		$siteDomain  = preg_replace('/^www\./', '', $articleHost) ?? $articleHost;
		if ($mediaUrl === null || $siteDomain === '' || !VariantHelper::isHttpsUrlOnDomain($mediaUrl, [$siteDomain])) {
			return null;
		}

		try {
			$data = $this->http->getJson($mediaUrl);
		} catch (\Throwable $e) {
			$this->logger->info('InlineMediaService: Medien-Beschreibung nicht ladbar', ['url' => $mediaUrl, 'exception' => $e]);
			return null;
		}
		if (!is_array($data) || ($data['_isLive'] ?? false) === true) {
			return null;
		}
		$kind = ($data['_type'] ?? 'video') === 'audio' ? MediaResult::KIND_AUDIO : MediaResult::KIND_VIDEO;

		$bestFile    = null;
		$bestQuality = -1;
		$hls         = null;
		foreach (is_array($data['_mediaArray'] ?? null) ? $data['_mediaArray'] : [] as $entry) {
			foreach (is_array($entry['_mediaStreamArray'] ?? null) ? $entry['_mediaStreamArray'] : [] as $stream) {
				$streamUrls = $stream['_stream'] ?? null;
				// _stream ist meist ein String, bei manchen Sendern eine Liste.
				foreach (is_array($streamUrls) ? $streamUrls : [$streamUrls] as $url) {
					if (!is_string($url) || $allowedHosts === [] || !VariantHelper::isHttpsUrlOnDomain($url, $allowedHosts)) {
						continue;
					}
					if (VariantHelper::looksLikeHlsUrl($url)) {
						$hls ??= $url;
						continue;
					}
					$quality = is_int($stream['_quality'] ?? null) ? $stream['_quality'] : 0;
					if ($quality > $bestQuality) {
						$bestQuality = $quality;
						$bestFile    = $url;
					}
				}
			}
		}

		if ($bestFile !== null) {
			return MediaResult::single($kind, MediaResult::DELIVERY_FILE, $bestFile);
		}
		if ($hls !== null) {
			return MediaResult::single($kind, MediaResult::DELIVERY_HLS, $hls);
		}
		return null;
	}

	/**
	 * Erstes echtes Bild im Container (data:-Platzhalter der Player-Overlays
	 * übersprungen).
	 *
	 * @return array{src: string, alt: string}|null
	 */
	private function posterImage(\DOMElement $container, \DOMXPath $xpath, string $articleUrl): ?array {
		foreach ($xpath->query('.//img', $container) ?: [] as $img) {
			if (!$img instanceof \DOMElement) {
				continue;
			}
			foreach (['src', 'data-src'] as $attr) {
				$src = trim($img->getAttribute($attr));
				if ($src === '' || str_starts_with($src, 'data:')) {
					continue;
				}
				$absolute = $this->absoluteUrl($src, $articleUrl);
				if ($absolute !== null) {
					return ['src' => $absolute, 'alt' => trim($img->getAttribute('alt'))];
				}
			}
		}
		return null;
	}

	/**
	 * @param array{src: string, alt: string}|null $poster
	 */
	private function buildFigure(\DOMDocument $dom, int $ref, ?array $poster, string $caption): \DOMElement {
		$figure = $dom->createElement('figure');
		$figure->setAttribute('class', self::FIGURE_CLASS . ' ' . self::SURVIVAL_CLASS . ' ' . self::REF_CLASS_PREFIX . $ref);
		if ($poster !== null) {
			$img = $dom->createElement('img');
			$img->setAttribute('src', $poster['src']);
			$img->setAttribute('alt', $poster['alt']);
			$figure->appendChild($img);
		}
		if ($caption !== '') {
			$figcaption = $dom->createElement('figcaption');
			$figcaption->textContent = $caption;
			$figure->appendChild($figcaption);
		}
		return $figure;
	}

	private function captionText(\DOMNodeList $nodes): string {
		$parts = [];
		foreach ($nodes as $node) {
			$text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
			if ($text !== '' && !in_array($text, $parts, true)) {
				$parts[] = $text;
			}
		}
		return implode(self::CAPTION_SEPARATOR, $parts);
	}

	private function isInside(\DOMNode $node, \DOMNode $ancestor): bool {
		for ($current = $node; $current !== null; $current = $current->parentNode) {
			if ($current === $ancestor) {
				return true;
			}
		}
		return false;
	}

	private function absoluteUrl(string $url, string $base): ?string {
		$url = trim($url);
		if ($url === '') {
			return null;
		}
		if (preg_match('#^https?://#i', $url)) {
			return $url;
		}
		$scheme = parse_url($base, PHP_URL_SCHEME);
		$host   = parse_url($base, PHP_URL_HOST);
		if (!is_string($scheme) || !is_string($host)) {
			return null;
		}
		if (str_starts_with($url, '//')) {
			return $scheme . ':' . $url;
		}
		if (str_starts_with($url, '/')) {
			return $scheme . '://' . $host . $url;
		}
		// Relativ zum Verzeichnis der Artikel-URL.
		$path = (string) parse_url($base, PHP_URL_PATH);
		$dir  = str_ends_with($path, '/') ? $path : (dirname($path) === '/' ? '/' : dirname($path) . '/');
		return $scheme . '://' . $host . $dir . $url;
	}

	/**
	 * @return list<\SimpleXMLElement>
	 */
	private function rules(?\SimpleXMLElement $config): array {
		if ($config === null || !isset($config->media)) {
			return [];
		}
		$rules = [];
		foreach ($config->media->inline as $rule) {
			if (in_array(trim((string) ($rule['type'] ?? '')), ContentFilterSchema::MEDIA_INLINE_TYPES, true)) {
				$rules[] = $rule;
			}
		}
		return $rules;
	}
}
