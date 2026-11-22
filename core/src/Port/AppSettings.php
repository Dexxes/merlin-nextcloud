<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Instanzweite Einstellungen von Merlin (vom Admin gesetzt).
 *
 * Nextcloud: IAppConfig mit der App-ID "merlin".
 */
interface AppSettings {
	public function getInt(string $key, int $default = 0): int;

	public function setInt(string $key, int $value): void;
}
