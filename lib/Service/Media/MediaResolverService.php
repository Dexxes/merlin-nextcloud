<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media;

use OCA\Merlin\Service\ContentFilterRepository;
use Psr\Log\LoggerInterface;

/**
 * Einstiegspunkt der Medien-Logik: liest die <media>-Sektion der
 * Content-Filter-Config einer Domain und delegiert je <source type="…"> an
 * den passenden Provider (siehe MediaProviderRegistry).
 *
 * Zwei Zeitpunkte:
 *   - Speichern (resolveOnSave): mit dem rohen Seiten-HTML. Stabile Quellen
 *     (Datei, Embed) werden hier aufgelöst und als Marker samt URL in den
 *     Content geschrieben; für Mediathek-Provider (resolvesPerRequest())
 *     entsteht nur ein Marker ohne URL.
 *   - Öffnen (resolveOnRequest): ein Marker mit URL wird direkt
 *     übernommen, sonst werden die Provider ohne HTML befragt (Mediathek-
 *     APIs, YouTube aus der URL) – das deckt auch Artikel ab, die vor
 *     Einführung der <media>-Sektion gespeichert wurden.
 *
 * BEWUSSTE PRODUKTENTSCHEIDUNG für die Mediathek-Provider (ARD/ZDF/Arte),
 * nicht Versehen: Anders als die offiziellen iFrame-/Widget-Embeds (siehe
 * ContentExtractorService::isAllowedVideoEmbedSrc()) ist deren Auflösung
 * über die internen, nicht öffentlich dokumentierten Player-APIs kein von den
 * Sendern autorisierter Einbettungsweg – analog zu dem, was yt-dlp/streamlink
 * tun. ZDFs Nutzungsbedingungen verlangen für Vervielfältigung/Speicherung/
 * Verbreitung ihrer Inhalte vorherige schriftliche Zustimmung; das OLG Köln
 * hat einem privaten Anbieter genau diese Art der Weiterverwendung von
 * ARD-Mediathek-Inhalten gerichtlich untersagt. Der Nutzer wurde auf dieses
 * Risiko hingewiesen und hat sich bewusst dafür entschieden, es zu tragen. Um
 * den Eingriff so klein wie möglich zu halten: keine Speicherung, kein
 * Download, kein dauerhafter Proxy/Cache – nur ein transientes Auflösen der
 * vom Sender selbst gelieferten Stream-URL für die Dauer eines einzelnen
 * Requests, die der Browser direkt vom Sender-CDN lädt.
 *
 * Fail-closed durchgängig: jeder Fehler eines Providers liefert null statt
 * einer Exception – ein nicht auflösbares Medium darf den Artikel-Reader nie
 * zum Absturz bringen, es fehlt dann einfach der Player.
 */
class MediaResolverService {
	/** CSS-Klasse des Markers im Artikel-Content. */
	public const MARKER_CLASS = 'merlin-media';

	/** Klasse des Fallback-Links im Marker (Reader blendet ihn bei laufendem Player aus). */
	public const FALLBACK_LINK_CLASS = 'merlin-media-fallback-link';

	/**
	 * Alte Klasse des Video-Fallback-Links – bleibt zusätzlich gesetzt, damit
	 * ältere Clients (und bereits gespeicherte Artikel) weiter funktionieren.
	 */
	public const LEGACY_VIDEO_FALLBACK_CLASS = 'merlin-video-fallback-link';

	public function __construct(
		private MediaProviderRegistry $registry,
		private ContentFilterRepository $contentFilters,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * true, wenn die Config mindestens eine <media><source> deklariert.
	 */
	public function hasSources(?\SimpleXMLElement $config): bool {
		return $this->sources($config) !== [];
	}

	/**
	 * Beim Speichern: erste deklarierte Quelle, die etwas liefert.
	 *
	 * @return array{kind: string, result: ?MediaResult}|null
	 *         result null = Mediathek-Provider, Auflösung erst beim Öffnen
	 */
	public function resolveOnSave(string $articleUrl, ?\SimpleXMLElement $config, string $rawHtml): ?array {
		foreach ($this->sources($config) as $source) {
			$provider = $this->registry->get(trim((string) $source['type']));
			if ($provider === null) {
				continue;
			}
			$context = new MediaContext($articleUrl, $source, $rawHtml);
			if ($provider->resolvesPerRequest()) {
				return ['kind' => $context->kind(), 'result' => null];
			}
			$result = $this->safeResolve($provider, $context);
			if ($result !== null) {
				return ['kind' => $result->kind, 'result' => $result];
			}
		}
		return null;
	}

	/**
	 * Beim Öffnen eines gespeicherten Artikels.
	 */
	public function resolveOnRequest(string $articleUrl, string $content, ?string $userId): ?MediaResult {
		$marker = self::parseMarker($content);
		if ($marker !== null && $marker['src'] !== null && $marker['delivery'] !== null) {
			return MediaResult::single($marker['kind'], $marker['delivery'], $marker['src']);
		}

		$domain = $this->contentFilters->normalizeUrlDomain($articleUrl);
		$config = $this->contentFilters->getMerged($domain, $userId);
		foreach ($this->sources($config) as $source) {
			$provider = $this->registry->get(trim((string) $source['type']));
			if ($provider === null) {
				continue;
			}
			$result = $this->safeResolve($provider, new MediaContext($articleUrl, $source));
			if ($result !== null) {
				return $result;
			}
		}
		return null;
	}

	/**
	 * Beschreibung aus einem Provider mit eigener Logik (siehe
	 * DescriptionProviderInterface), oder null, wenn keine deklarierte Quelle
	 * das kann.
	 *
	 * @return array{teaser: ?string, paragraphs: list<string>}|null
	 */
	public function providerDescription(?\SimpleXMLElement $config, string $rawHtml): ?array {
		foreach ($this->sources($config) as $source) {
			$provider = $this->registry->get(trim((string) $source['type']));
			if ($provider instanceof DescriptionProviderInterface) {
				try {
					return $provider->extractDescription($rawHtml);
				} catch (\Throwable $e) {
					$this->logger->info('MediaResolverService: Beschreibung nicht lesbar', ['exception' => $e]);
					return null;
				}
			}
		}
		return null;
	}

	/**
	 * Marker-HTML für den Artikel-Content. Enthält immer einen Fallback-Link
	 * (auf die Mediendatei bzw. die Artikelseite), damit Clients ohne
	 * eigenen Player – und der Reader, falls die Wiedergabe scheitert –
	 * trotzdem zum Medium kommen.
	 */
	public static function buildMarkerHtml(string $kind, ?MediaResult $result, string $articleUrl): string {
		$attrs = ' data-media-kind="' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') . '"';
		$linkTarget = $articleUrl;
		if ($result !== null) {
			$attrs .= ' data-media-delivery="' . htmlspecialchars($result->delivery, ENT_QUOTES, 'UTF-8') . '"'
				. ' data-media-src="' . htmlspecialchars($result->defaultUrl(), ENT_QUOTES, 'UTF-8') . '"';
			if ($result->delivery === MediaResult::DELIVERY_FILE) {
				$linkTarget = $result->defaultUrl();
			}
		}

		$linkClass = self::FALLBACK_LINK_CLASS;
		if ($kind === MediaResult::KIND_VIDEO) {
			$linkClass .= ' ' . self::LEGACY_VIDEO_FALLBACK_CLASS;
		}
		$label = $kind === MediaResult::KIND_AUDIO ? 'Zum Audio' : 'Zum Video';

		return '<div class="' . self::MARKER_CLASS . '"' . $attrs . '>'
			. '<a href="' . htmlspecialchars($linkTarget, ENT_QUOTES, 'UTF-8') . '" class="' . $linkClass . '">' . $label . '</a>'
			. '</div>';
	}

	/**
	 * Liest den ersten Marker aus gespeichertem Content. src/delivery nur,
	 * wenn beide gesetzt und plausibel sind (https, bekannte Auslieferung) –
	 * der Content ist zwar serverseitig sanitisiert, wird hier aber trotzdem
	 * nicht blind übernommen.
	 *
	 * @return array{kind: string, delivery: ?string, src: ?string}|null
	 */
	public static function parseMarker(string $content): ?array {
		if (!str_contains($content, self::MARKER_CLASS)) {
			return null;
		}

		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$dom->loadHTML('<?xml version="1.0" encoding="UTF-8"?><div>' . $content . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$node = (new \DOMXPath($dom))->query(
			"//div[contains(concat(' ', normalize-space(@class), ' '), ' " . self::MARKER_CLASS . " ')][@data-media-kind]"
		)->item(0);
		if (!$node instanceof \DOMElement) {
			return null;
		}

		$kind = $node->getAttribute('data-media-kind');
		if (!in_array($kind, MediaResult::KINDS, true)) {
			return null;
		}

		$delivery = $node->getAttribute('data-media-delivery');
		$src      = $node->getAttribute('data-media-src');
		if (!in_array($delivery, MediaResult::DELIVERIES, true) || !VariantHelper::isHttpsUrlOnDomain($src, [])) {
			$delivery = null;
			$src      = null;
		}

		return ['kind' => $kind, 'delivery' => $delivery, 'src' => $src];
	}

	/**
	 * @return list<\SimpleXMLElement>
	 */
	private function sources(?\SimpleXMLElement $config): array {
		if ($config === null || !isset($config->media)) {
			return [];
		}
		$sources = [];
		foreach ($config->media->source as $source) {
			$sources[] = $source;
		}
		return $sources;
	}

	private function safeResolve(MediaSourceProviderInterface $provider, MediaContext $context): ?MediaResult {
		try {
			return $provider->resolve($context);
		} catch (\Throwable $e) {
			// Fail-closed: jeder Fehler (Netzwerk, JSON, unerwartete Struktur)
			// bedeutet einfach "kein Player", nie einen kaputten Reader.
			$this->logger->info('MediaResolverService: Auflösen fehlgeschlagen', [
				'url'       => $context->articleUrl,
				'type'      => $provider->type(),
				'exception' => $e,
			]);
			return null;
		}
	}
}
