<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Geheimer Schlüssel der Installation, Grundlage für signierte Links.
 *
 * Nextcloud: der Systemwert "secret" aus config.php.
 */
interface InstanceSecret {
	public function get(): string;
}
