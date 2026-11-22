<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\InstanceSecret;

/**
 * InstanceSecret-Port aus config.php ('secret', in Nextcloud der Systemwert
 * secret). Ohne Wert schlagen erst die Stellen fehl, die ihn brauchen
 * (Paywall-Zugangsdaten, signierte Links), nicht jeder Request.
 */
final class ConfigInstanceSecret implements InstanceSecret {
	public function __construct(private string $secret) {
	}

	public function get(): string {
		if (strlen($this->secret) < 16) {
			throw new \RuntimeException(
				"config.php: 'secret' fehlt oder ist zu kurz, siehe config/config.sample.php."
			);
		}
		return $this->secret;
	}

	public function isConfigured(): bool {
		return strlen($this->secret) >= 16;
	}
}
