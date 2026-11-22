<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\Platform;
use Merlin\Standalone\Version;

/**
 * Platform-Port für /api/capabilities: gemeinsame Version und die
 * Funktionen, die nur der Standalone-Server hat (eigene Konten, API-Tokens,
 * Nachbau von Login Flow v2). "files" (Merlin Dateien) fehlt bewusst, bis es
 * einen lokalen Speicher gibt.
 */
final class StandalonePlatform implements Platform {
	public const FEATURES = ['accounts', 'apiTokens', 'loginFlowV2'];

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
