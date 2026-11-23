<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\ThrottledException;
use Merlin\Core\Port\Throttler;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;

/**
 * Throttler-Port über Nextclouds Brute-Force-Schutz (gleiches Muster wie beim
 * Login und bei Nextclouds eigenen Datei-Freigaben).
 */
class NextcloudThrottler implements Throttler {
	/** Nextcloud nennt keine Restzeit; Fehlversuche zählen 30 Minuten. */
	private const RETRY_AFTER = 1800;

	public function __construct(
		private IThrottler $throttler,
	) {
	}

	/**
	 * Wie Nextclouds eigene Controller (#[BruteForceProtection]): bei maximaler
	 * Wartezeit sofort 429 statt eines Workers, der 25 Sekunden schläft.
	 */
	public function sleepDelay(string $action, string $address): void {
		try {
			$this->throttler->sleepDelayOrThrowOnMax($address, $action);
		} catch (MaxDelayReached) {
			throw new ThrottledException(self::RETRY_AFTER);
		}
	}

	public function registerFailure(string $action, string $address, array $metadata = []): void {
		$this->throttler->registerAttempt($action, $address, $metadata);
	}

	public function reset(string $action, string $address, array $metadata = []): void {
		$this->throttler->resetDelay($address, $action, $metadata);
	}
}
