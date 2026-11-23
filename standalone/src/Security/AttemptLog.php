<?php

declare(strict_types=1);

namespace Merlin\Standalone\Security;

use Merlin\Core\Port\Connection;

/**
 * Zeitstempel von Versuchen je Aktion und IP (Tabelle security_attempts),
 * Grundlage für SqlThrottler und RateLimitMiddleware. Alte Einträge räumt
 * record() gelegentlich selbst weg.
 */
final class AttemptLog {
	/** Älter wird nichts gebraucht (Throttler: 12 Stunden wie Nextcloud). */
	public const KEEP_SECONDS = 43200;

	public function __construct(private Connection $db) {
	}

	public function record(string $action, string $address): void {
		$now = time();
		$this->db->insert('security_attempts', ['action' => $action, 'address' => $address, 'created_at' => $now]);
		if (random_int(1, 50) === 1) {
			$this->db->execute('DELETE FROM security_attempts WHERE created_at < :cutoff', ['cutoff' => $now - self::KEEP_SECONDS]);
		}
	}

	public function count(string $action, string $address, int $withinSeconds): int {
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM security_attempts WHERE action = :action AND address = :address AND created_at >= :since',
			['action' => $action, 'address' => $address, 'since' => time() - $withinSeconds],
		);
	}

	public function clear(string $action, string $address): void {
		$this->db->execute(
			'DELETE FROM security_attempts WHERE action = :action AND address = :address',
			['action' => $action, 'address' => $address],
		);
	}
}
