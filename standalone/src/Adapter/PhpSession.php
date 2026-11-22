<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\Session;

/**
 * Session-Port über $_SESSION (in Nextcloud: ISession). Die PHP-Session
 * startet erst beim ersten Zugriff; ohne Session (CLI) bleiben die Werte im
 * Speicher.
 */
final class PhpSession implements Session {
	/** @var array<string, mixed> */
	private array $memory = [];

	public function get(string $key): mixed {
		if (!$this->start()) {
			return $this->memory[$key] ?? null;
		}
		return $_SESSION[$key] ?? null;
	}

	public function set(string $key, mixed $value): void {
		if (!$this->start()) {
			$this->memory[$key] = $value;
			return;
		}
		$_SESSION[$key] = $value;
	}

	private function start(): bool {
		if (PHP_SAPI === 'cli') {
			return false;
		}
		if (session_status() === PHP_SESSION_ACTIVE) {
			return true;
		}
		if (headers_sent()) {
			return false;
		}
		return session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true]);
	}
}
