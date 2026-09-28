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
 * host-allow wirkt wie bei type="xpath". Braucht das rohe HTML, wirkt also
 * nur beim Speichern (siehe MediaContext).
 */
class JsonLdMediaProvider implements MediaSourceProviderInterface {
	private const MEDIA_TYPES = ['AudioObject', 'VideoObject', 'MediaObject'];

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
			$url = $this->findContentUrl($data, $allowed, 0);
			if ($url !== null) {
				return MediaResult::single($context->kind(), VariantHelper::deliveryForUrl($url), $url);
			}
		}

		return null;
	}

	/**
	 * @param array<mixed> $node
	 * @param list<string> $allowed
	 */
	private function findContentUrl(array $node, array $allowed, int $depth): ?string {
		if ($depth > self::MAX_DEPTH) {
			return null;
		}

		$type = $node['@type'] ?? null;
		$types = is_array($type) ? $type : [$type];
		if (array_intersect(self::MEDIA_TYPES, array_filter($types, 'is_string')) !== []) {
			$url = $node['contentUrl'] ?? null;
			if (is_string($url) && VariantHelper::isHttpsUrlOnDomain($url, $allowed)) {
				return $url;
			}
		}

		foreach ($node as $child) {
			if (is_array($child)) {
				$url = $this->findContentUrl($child, $allowed, $depth + 1);
				if ($url !== null) {
					return $url;
				}
			}
		}
		return null;
	}
}
