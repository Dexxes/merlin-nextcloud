<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\Platform;
use Merlin\Standalone\Version;

/**
 * Platform-Port für /api/capabilities: gemeinsame Version und die
 * Funktionen, die nur der Standalone-Server hat (eigene Konten, API-Tokens,
 * Nachbau von Login Flow v2) sowie "files" (Merlin Dateien, Ablage im
 * Datenverzeichnis über Adapter\LocalFileStorage).
 */
final class StandalonePlatform implements Platform {
	public const FEATURES = ['accounts', 'apiTokens', 'files', 'loginFlowV2'];

	public function backend(): string {
		return 'standalone';
	}

	public function version(): string {
		return Version::current();
	}

	public function features(): array {
		return self::FEATURES;
	}
}
