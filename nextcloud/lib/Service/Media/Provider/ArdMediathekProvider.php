<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media\Provider;

use OCA\Merlin\Service\Media\MediaContext;
use OCA\Merlin\Service\Media\MediaHttpClient;
use OCA\Merlin\Service\Media\MediaResult;
use OCA\Merlin\Service\Media\MediaSourceProviderInterface;
use OCA\Merlin\Service\Media\VariantHelper;

/**
 * type="ard-mediathek": HLS-Stream über die interne page-gateway-API der
 * ARD Mediathek – siehe MediaResolverService-Docblock zur bewussten
 * Produktentscheidung (kein offizieller Embed-Weg).
 */
class ArdMediathekProvider implements MediaSourceProviderInterface {
	public function __construct(
		private MediaHttpClient $http,
	) {
	}

	public function type(): string {
		return 'ard-mediathek';
	}

	public function resolvesPerRequest(): bool {
		return true;
	}

	/**
	 * Höchstzahl der Videos, die von einer Übersichtsseite aufgelöst werden.
	 * Jedes kostet einen eigenen API-Aufruf beim Öffnen des Artikels.
	 */
	private const MAX_GROUPING_ITEMS = 6;

	private const API_BASE = 'https://api.ardmediathek.de/page-gateway/pages/ard/';

	/**
	 * ARD-Artikel-URLs enden auf die für die page-gateway-API nutzbare ID
	 * (Base64url-artiger "crid"), z. B.
	 * https://www.ardmediathek.de/video/<show>/<titel>/<sender>/<id>.
	 *
	 * Übersichtsseiten (z. B. https://www.ardmediathek.de/film/<titel>/<id>
	 * oder /sendung/…) haben stattdessen eine Grouping-ID: der item-Endpunkt
	 * antwortet dafür mit 404. Dann werden die Videos der Übersichtsseite
	 * einzeln aufgelöst und als Varianten angeboten, damit der Nutzer im
	 * Player zwischen ihnen wählen kann.
	 */
	public function resolve(MediaContext $context): ?MediaResult {
		$path = (string) parse_url($context->articleUrl, PHP_URL_PATH);
		$segments = array_values(array_filter(explode('/', $path), static fn (string $s) => $s !== ''));
		$id = end($segments) ?: null;
		// Die crid ist base64url("crid://<domain>/<uuid>") - je nach Domain-Länge
		// deutlich länger als eine kurze ID (z. B. "sportschau.de" ergibt 76
		// Zeichen). 64 war hier zu eng geraten und hat echte ARD-URLs verworfen,
		// bevor die API überhaupt angefragt wurde - 300 lässt genug Luft für
		// jede realistische Domain, bleibt aber eine Obergrenze gegen absurd
		// lange Eingaben.
		if ($id === null || !self::isValidId($id)) {
			return null;
		}

		$variants = $this->itemVariants($id, null);
		if ($variants === []) {
			$variants = $this->groupingVariants($id);
		}

		return VariantHelper::buildHlsResult($context->kind(), $variants);
	}

	private static function isValidId(string $id): bool {
		return preg_match('/^[A-Za-z0-9_-]{5,300}$/', $id) === 1;
	}

	/**
	 * Stream-Varianten eines einzelnen Videos. Mit $title (Video einer
	 * Übersichtsseite) wird der Titel zum Label, damit die Videos im Dropdown
	 * unterscheidbar sind; Sonderfassungen hängen ihren Namen an.
	 *
	 * @return list<array{label: string, url: string}>
	 */
	private function itemVariants(string $id, ?string $title): array {
		$json = $this->http->getJson(self::API_BASE . 'item/' . rawurlencode($id) . '?embedded=false&mcV6=true');
		$widgets = $json['widgets'] ?? null;
		if (!is_array($widgets)) {
			return [];
		}

		// Ein Stream-Eintrag pro verfügbarer Variante (kind/kindName, z. B.
		// "main"/"Normal" vs. "signLanguage"/"Gebärdensprache") - alle
		// sammeln statt nur die erste zu nehmen, damit das Frontend sie zur
		// Auswahl anbieten kann (siehe VariantHelper::buildHlsResult()).
		$variants = [];
		foreach ($widgets as $widget) {
			if (!is_array($widget)) {
				continue;
			}
			$type = $widget['type'] ?? '';
			if ($type !== 'player_ondemand' && $type !== 'player_live') {
				continue;
			}
			$streams = $widget['mediaCollection']['embedded']['streams'] ?? null;
			if (!is_array($streams)) {
				continue;
			}
			foreach ($streams as $stream) {
				if (!is_array($stream)) {
					continue;
				}
				$mediaList = $stream['media'] ?? null;
				if (!is_array($mediaList)) {
					continue;
				}
				$label = VariantHelper::firstNonEmptyString([$stream['kindName'] ?? null, $stream['kind'] ?? null]) ?? 'Standard';
				if ($title !== null) {
					$isMain = ($stream['kind'] ?? 'main') === 'main';
					$label = $isMain ? $title : $title . ' · ' . $label;
				}
				foreach ($mediaList as $media) {
					$url = $media['url'] ?? null;
					if (is_string($url) && VariantHelper::looksLikeHlsUrl($url)) {
						// Nur die erste m3u8 pro Stream-Gruppe - die mp4-
						// Fallback-Auflösungen derselben Gruppe sind keine
						// eigenständige Variante, sondern dieselbe Quelle.
						$variants[] = ['label' => $label, 'url' => $url];
						break;
					}
				}
			}
		}
		return $variants;
	}

	/**
	 * Videos einer Übersichtsseite (Grouping), in Seitenreihenfolge. Ohne
	 * embedded=false: damit liefert die API die gridlist ohne Teaser.
	 *
	 * @return list<array{label: string, url: string}>
	 */
	private function groupingVariants(string $id): array {
		$json = $this->http->getJson(self::API_BASE . 'grouping/' . rawurlencode($id));
		$widgets = $json['widgets'] ?? null;
		if (!is_array($widgets)) {
			return [];
		}

		$teasers = [];
		foreach ($widgets as $widget) {
			if (!is_array($widget) || !is_array($widget['teasers'] ?? null)) {
				continue;
			}
			foreach ($widget['teasers'] as $teaser) {
				if (!is_array($teaser) || ($teaser['type'] ?? 'ondemand') !== 'ondemand') {
					continue;
				}
				$teaserId = VariantHelper::firstNonEmptyString([$teaser['links']['target']['id'] ?? null, $teaser['id'] ?? null]);
				if ($teaserId === null || $teaserId === $id || !self::isValidId($teaserId) || isset($teasers[$teaserId])) {
					continue;
				}
				$teasers[$teaserId] = VariantHelper::firstNonEmptyString([
					$teaser['mediumTitle'] ?? null,
					$teaser['shortTitle'] ?? null,
					$teaser['longTitle'] ?? null,
				]) ?? 'Video ' . (count($teasers) + 1);
				if (count($teasers) >= self::MAX_GROUPING_ITEMS) {
					break 2;
				}
			}
		}

		$variants = [];
		foreach ($teasers as $teaserId => $title) {
			array_push($variants, ...$this->itemVariants((string) $teaserId, $title));
		}
		return $variants;
	}
}
