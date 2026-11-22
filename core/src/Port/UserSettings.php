<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Einstellungen eines Nutzers als Zeichenketten, gruppiert nach Bereich
 * (z. B. "reader" für das Erscheinungsbild des Readers).
 *
 * Nextcloud: IConfig::getUserValue($userId, $section, $key, $default).
 */
interface UserSettings {
	public function get(string $userId, string $section, string $key, string $default = ''): string;
}
