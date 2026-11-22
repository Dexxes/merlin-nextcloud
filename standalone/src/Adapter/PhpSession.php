<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\Session;
use Merlin\Standalone\Auth\SessionService;

/**
 * Session-Port über $_SESSION (in Nextcloud: ISession), gestartet von
 * Auth\SessionService mit denselben Cookie-Einstellungen wie der Login. Auf
 * der Kommandozeile bleiben die Werte im Speicher.
 */
final class PhpSession implements Session {
	/** @var array<string, mixed> */
	private array $memory = [];

	public function __construct(private SessionService $sessions) {
	}

	public function get(string $key): mixed {
		if (PHP_SAPI === 'cli') {
			return $this->memory[$key] ?? null;
		}
		$this->sessions->start();
		return $_SESSION[$key] ?? null;
	}

	public function set(string $key, mixed $value): void {
		if (PHP_SAPI === 'cli') {
			$this->memory[$key] = $value;
			return;
		}
		$this->sessions->start();
		$_SESSION[$key] = $value;
	}
}
