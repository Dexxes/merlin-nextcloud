<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media;

use OCA\Merlin\Service\Media\Provider\ArdMediathekProvider;
use OCA\Merlin\Service\Media\Provider\ArteProvider;
use OCA\Merlin\Service\Media\Provider\JsonLdMediaProvider;
use OCA\Merlin\Service\Media\Provider\XPathMediaProvider;
use OCA\Merlin\Service\Media\Provider\YoutubeEmbedProvider;
use OCA\Merlin\Service\Media\Provider\ZdfProvider;

/**
 * type → Provider. Die einzige Stelle, an der ein neuer Provider
 * registriert werden muss (plus sein Typname in
 * ContentFilterSchema::MEDIA_SOURCE_TYPES, damit der Validator ihn kennt –
 * tools/test-media-providers.php prüft, dass beide Listen übereinstimmen).
 */
class MediaProviderRegistry {
	/** @var array<string, MediaSourceProviderInterface> */
	private array $providers = [];

	public function __construct(
		ArdMediathekProvider $ard,
		ZdfProvider $zdf,
		ArteProvider $arte,
		XPathMediaProvider $xpath,
		JsonLdMediaProvider $jsonLd,
		YoutubeEmbedProvider $youtube,
	) {
		foreach ([$ard, $zdf, $arte, $xpath, $jsonLd, $youtube] as $provider) {
			$this->providers[$provider->type()] = $provider;
		}
	}

	public function get(string $type): ?MediaSourceProviderInterface {
		return $this->providers[$type] ?? null;
	}

	/** @return list<string> */
	public function types(): array {
		return array_keys($this->providers);
	}
}
