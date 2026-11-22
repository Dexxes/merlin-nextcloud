<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\Connection;
use Merlin\Core\Port\UserSettings;

/**
 * UserSettings-Port über die Tabelle user_settings (in Nextcloud:
 * IConfig::getUserValue/setUserValue, section entspricht der App-ID).
 */
final class StandaloneUserSettings implements UserSettings {
	public function __construct(private Connection $db) {
	}

	public function get(string $userId, string $section, string $key, string $default = ''): string {
		$value = $this->db->fetchValue(
			'SELECT value FROM *PREFIX*user_settings WHERE user_id = :user_id AND section = :section AND name = :name',
			['user_id' => $userId, 'section' => $section, 'name' => $key],
		);
		return $value === null ? $default : (string) $value;
	}

	public function set(string $userId, string $section, string $key, string $value): void {
		StandaloneAppSettings::upsert($this->db, 'user_settings',
			['user_id' => $userId, 'section' => $section, 'name' => $key], ['value' => $value]);
	}

	/** Alle Werte eines Nutzers entfernen (Konto löschen). */
	public function deleteAllForUser(string $userId): void {
		$this->db->execute('DELETE FROM *PREFIX*user_settings WHERE user_id = :user_id', ['user_id' => $userId]);
	}
}
