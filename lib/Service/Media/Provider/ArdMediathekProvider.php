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
	 * ARD-Artikel-URLs enden auf die für die page-gateway-API nutzbare ID
	 * (Base64url-artiger "crid"), z. B.
	 * https://www.ardmediathek.de/video/<show>/<titel>/<sender>/<id>.
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
		if ($id === null || preg_match('/^[A-Za-z0-9_-]{5,300}$/', $id) !== 1) {
			return null;
		}

		$json = $this->http->getJson(
			'https://api.ardmediathek.de/page-gateway/pages/ard/item/' . rawurlencode($id)
				. '?embedded=false&mcV6=true',
		);
		if ($json === null) {
			return null;
		}

		$widgets = $json['widgets'] ?? null;
		if (!is_array($widgets)) {
			return null;
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

		return VariantHelper::buildHlsResult($context->kind(), $variants);
	}
}
