<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\Throttler;
use OCP\Security\Bruteforce\IThrottler;

/**
 * Throttler-Port über Nextclouds Brute-Force-Schutz (gleiches Muster wie beim
 * Login und bei Nextclouds eigenen Datei-Freigaben).
 */
class NextcloudThrottler implements Throttler {
	public function __construct(
		private IThrottler $throttler,
	) {
	}

	public function sleepDelay(string $action, string $address): void {
		$this->throttler->sleepDelay($address, $action);
	}

	public function registerFailure(string $action, string $address, array $metadata = []): void {
		$this->throttler->registerAttempt($action, $address, $metadata);
	}

	public function reset(string $action, string $address, array $metadata = []): void {
		$this->throttler->resetDelay($address, $action, $metadata);
	}
}
