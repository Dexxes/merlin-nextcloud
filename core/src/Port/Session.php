<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Werte der Browser-Session des aktuellen Besuchers (Nextcloud: ISession).
 */
interface Session {
	public function get(string $key): mixed;

	public function set(string $key, mixed $value): void;
}
