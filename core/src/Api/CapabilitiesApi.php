<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Port\Platform;

/**
 * GET /api/capabilities (ohne Anmeldung): was dieser Server kann. Clients
 * schalten Funktionen danach frei statt nach backendKind (Plan „gemeinsames
 * Repo“, Abschnitt Browser-Erweiterungen, Apps und API-Verträge).
 *
 * "features" = was der Kern in beiden Varianten anbietet (CORE_FEATURES)
 * plus was nur die jeweilige Variante hat (Port Platform). Wandert eine
 * Funktion in den Kern, zieht ihr Name von der Plattform-Liste hierher.
 */
final class CapabilitiesApi {
	public const API_VERSION = 1;

	/** @var list<string> */
	public const CORE_FEATURES = [
		'articles', 'tags', 'nestedTags', 'articleEvents', 'exportHtml',
		'highlights', 'comments', 'shares', 'pdf', 'media', 'tts', 'retention',
		'contentFilters', 'siteCredentials', 'reportBackend', 'pocketApi', 'pwa',
	];

	public function __construct(private Platform $platform) {
	}

	public function show(ApiRequest $request): ApiResult {
		return ApiResult::json([
			'backend' => $this->platform->backend(),
			'version' => $this->platform->version(),
			'apiVersion' => self::API_VERSION,
			'features' => array_values(array_unique(array_merge(self::CORE_FEATURES, $this->platform->features()))),
		]);
	}
}
