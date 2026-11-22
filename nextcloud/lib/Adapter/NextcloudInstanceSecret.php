<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\InstanceSecret;
use OCP\IConfig;

/**
 * InstanceSecret-Port des Kerns: der Systemwert "secret" aus config.php.
 */
class NextcloudInstanceSecret implements InstanceSecret {
	public function __construct(
		private IConfig $config,
	) {
	}

	public function get(): string {
		return $this->config->getSystemValueString('secret', '');
	}
}
