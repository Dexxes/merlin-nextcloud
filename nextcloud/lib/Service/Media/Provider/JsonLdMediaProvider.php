<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media\Provider;

use OCA\Merlin\Service\Media\MediaContext;
use OCA\Merlin\Service\Media\MediaResult;
use OCA\Merlin\Service\Media\MediaSourceProviderInterface;
use OCA\Merlin\Service\Media\VariantHelper;

/**
 * type="json-ld": generisch – liest die Medien-URL aus schema.org-JSON-LD
 * (<script type="application/ld+json">). Gesucht wird das erste
 * "contentUrl" eines AudioObject/VideoObject/MediaObject – egal wie tief
 * verschachtelt, z. B. PodcastEpisode.associatedMedia.contentUrl bei ARD
 * Sounds:
 *
 *   <source type="json-ld" kind="audio" />
 *
 * Die Medienart folgt dem @type des gefundenen Objekts: AudioObject → audio,
 * VideoObject → video. Nur beim generischen MediaObject gilt das kind der
 * <source>-Regel. So deckt EINE Quelle Seiten ab, die je Artikel mal ein
 * Audio, mal ein Video als Aufmacher haben (tagesschau.de, rbb24.de) – zwei
 * json-ld-Quellen mit unterschiedlichem kind gingen nicht, weil <source>
 * beim Merge je type geschlüsselt ist.
 *
 * host-allow wirkt wie bei type="xpath". Braucht das rohe HTML, wirkt also
 * nur beim Speichern (siehe MediaContext).
 */
class JsonLdMediaProvider implements MediaSourceProviderInterface {
	private const MEDIA_TYPES = ['AudioObject', 'VideoObject', 'MediaObject'];

	/** schema.org-Typen mit eindeutiger Medienart. */
	private const KIND_BY_TYPE = [
		'AudioObject' => MediaResult::KIND_AUDIO,
		'VideoObject' => MediaResult::KIND_VIDEO,
	];

	/** Schutz gegen absurd tief verschachtelte Dokumente. */
	private const MAX_DEPTH = 12;

	public function type(): string {
		return 'json-ld';
	}

	public function resolvesPerRequest(): bool {
		return false;
	}

	public function resolve(MediaContext $context): ?MediaResult {
		if ($context->rawHtml === null) {
			return null;
		}

		if (preg_match_all(
			'#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			$context->rawHtml,
			$matches
		) === false) {
			return null;
		}

		$allowed = VariantHelper::parseHostList($context->attribute('host-allow'));
		foreach ($matches[1] as $json) {
			try {
				$data = json_decode(trim($json), true, 512, JSON_THROW_ON_ERROR);
			} catch (\JsonException) {
				continue;
			}
			if (!is_array($data)) {
				continue;
			}
			$found = $this->findContentUrl($data, $allowed, 0);
			if ($found !== null) {
				[$url, $kind] = $found;
				$url = VariantHelper::akamaiSetToHls($url);
				return MediaResult::single($kind ?? $context->kind(), VariantHelper::deliveryForUrl($url), $url);
			}
		}

		return null;
	}

	/**
	 * @param array<mixed> $node
	 * @param list<string> $allowed
	 * @return array{0: string, 1: ?string}|null URL und aus @type abgeleitete
	 *         Medienart (null bei MediaObject)
	 */
	private function findContentUrl(array $node, array $allowed, int $depth): ?array {
		if ($depth > self::MAX_DEPTH) {
			return null;
		}

		$type = $node['@type'] ?? null;
		$types = array_filter(is_array($type) ? $type : [$type], 'is_string');
		if (array_intersect(self::MEDIA_TYPES, $types) !== []) {
			$url = $node['contentUrl'] ?? null;
			if (is_string($url) && VariantHelper::isHttpsUrlOnDomain($url, $allowed)) {
				$kinds = array_unique(array_values(array_intersect_key(self::KIND_BY_TYPE, array_flip($types))));
				return [$url, count($kinds) === 1 ? $kinds[0] : null];
			}
		}

		foreach ($node as $child) {
			if (is_array($child)) {
				$found = $this->findContentUrl($child, $allowed, $depth + 1);
				if ($found !== null) {
					return $found;
				}
			}
		}
		return null;
	}
}
