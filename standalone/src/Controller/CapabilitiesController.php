<?php

declare(strict_types=1);

namespace Merlin\Standalone\Controller;

use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;
use Merlin\Standalone\Version;

/**
 * GET /api/capabilities: was dieser Server kann (Plan „gemeinsames Repo“,
 * Abschnitt Browser-Erweiterungen, Apps und API-Verträge). Clients schalten
 * Funktionen danach frei statt nach backendKind. Ohne Anmeldung abrufbar,
 * damit Clients vor dem Login wissen, womit sie sprechen.
 *
 * In "features" steht nur, was der Server wirklich anbietet. Die Artikel-API
 * kommt mit Etappe 4 dazu; "files" (Merlin Dateien) fehlt im
 * Standalone-Server bewusst, bis es einen lokalen Speicher gibt.
 */
final class CapabilitiesController {
	public const API_VERSION = 1;

	/** @var list<string> */
	public const FEATURES = ['accounts', 'apiTokens', 'loginFlowV2'];

	public function show(Request $request): Response {
		return Response::json([
			'backend' => 'standalone',
			'version' => Version::current(),
			'apiVersion' => self::API_VERSION,
			'features' => self::FEATURES,
		]);
	}
}
