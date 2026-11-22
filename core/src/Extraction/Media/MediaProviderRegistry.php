<?php

declare(strict_types=1);

namespace Merlin\Core\Extraction\Media;

use Merlin\Core\Extraction\Media\Provider\ArdMediathekProvider;
use Merlin\Core\Extraction\Media\Provider\ArteProvider;
use Merlin\Core\Extraction\Media\Provider\DreiSatProvider;
use Merlin\Core\Extraction\Media\Provider\JsonLdMediaProvider;
use Merlin\Core\Extraction\Media\Provider\XPathMediaProvider;
use Merlin\Core\Extraction\Media\Provider\YoutubeEmbedProvider;
use Merlin\Core\Extraction\Media\Provider\ZdfProvider;

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
		DreiSatProvider $dreiSat,
		XPathMediaProvider $xpath,
		JsonLdMediaProvider $jsonLd,
		YoutubeEmbedProvider $youtube,
	) {
		foreach ([$ard, $zdf, $arte, $dreiSat, $xpath, $jsonLd, $youtube] as $provider) {
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
