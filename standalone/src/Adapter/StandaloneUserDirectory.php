<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\Connection;
use Merlin\Core\Port\UserDirectory;

/**
 * UserDirectory-Port über die Tabelle users (in Nextcloud: IUserManager).
 * Die Nutzer-ID des Kerns ist users.id als String; angezeigt wird der
 * Benutzername.
 */
final class StandaloneUserDirectory implements UserDirectory {
	/** @var array<string, string> */
	private array $cache = [];

	public function __construct(private Connection $db) {
	}

	public function displayName(string $userId): string {
		if (!ctype_digit($userId)) {
			return '';
		}
		return $this->cache[$userId] ??= (string) ($this->db->fetchValue(
			'SELECT username FROM *PREFIX*users WHERE id = :id',
			['id' => (int) $userId],
		) ?? '');
	}
}
