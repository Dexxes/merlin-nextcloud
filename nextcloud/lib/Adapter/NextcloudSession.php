<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\Session;
use OCP\ISession;

/**
 * Session-Port des Kerns über ISession.
 */
class NextcloudSession implements Session {
	public function __construct(
		private ISession $session,
	) {
	}

	public function get(string $key): mixed {
		return $this->session->get($key);
	}

	public function set(string $key, mixed $value): void {
		$this->session->set($key, $value);
	}
}
