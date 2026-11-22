<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\AppSettings;
use Merlin\Core\Port\Connection;
use Merlin\Core\Port\DuplicateEntryException;

/**
 * AppSettings-Port über die Tabelle settings (in Nextcloud: IAppConfig der
 * App merlin). Schlüssel des Kerns stehen dort mit Präfix "merlin.", damit
 * sie sich nicht mit den Konto-Schaltern des Servers mischen.
 */
final class StandaloneAppSettings implements AppSettings {
	private const PREFIX = 'merlin.';

	public function __construct(private Connection $db) {
	}

	public function getInt(string $key, int $default = 0): int {
		$value = $this->get(self::PREFIX . $key);
		return $value !== null && is_numeric($value) ? (int) $value : $default;
	}

	public function setInt(string $key, int $value): void {
		$this->set(self::PREFIX . $key, (string) $value);
	}

	public function get(string $key): ?string {
		$value = $this->db->fetchValue('SELECT value FROM *PREFIX*settings WHERE name = :name', ['name' => $key]);
		return $value === null ? null : (string) $value;
	}

	public function set(string $key, string $value): void {
		self::upsert($this->db, 'settings', ['name' => $key], ['value' => $value]);
	}

	/**
	 * Portables "einfügen oder ändern" ohne datenbankeigenes UPSERT.
	 *
	 * @param array<string, string> $keys
	 * @param array<string, string> $values
	 */
	public static function upsert(Connection $db, string $table, array $keys, array $values): void {
		$where = implode(' AND ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($keys)));
		$set = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($values)));
		$db->transactional(static function () use ($db, $table, $keys, $values, $where, $set): void {
			if ($db->fetchValue("SELECT 1 FROM *PREFIX*{$table} WHERE {$where}", $keys) !== null) {
				$db->execute("UPDATE *PREFIX*{$table} SET {$set} WHERE {$where}", $values + $keys);
				return;
			}
			try {
				$db->insert($table, $keys + $values, false);
			} catch (DuplicateEntryException) {
				// Ein paralleler Request war schneller: dann eben ändern.
				$db->execute("UPDATE *PREFIX*{$table} SET {$set} WHERE {$where}", $values + $keys);
			}
		});
	}
}
