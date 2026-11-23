<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\Throttler;
use Merlin\Standalone\Security\AttemptLog;

/**
 * Throttler-Port wie Nextclouds IThrottler: jeder Fehlversuch der letzten
 * zwölf Stunden verdoppelt die Wartezeit vor dem nächsten Versuch, höchstens
 * 25 Sekunden.
 */
final class SqlThrottler implements Throttler {
	private const MAX_DELAY_MS = 25000;

	public function __construct(private AttemptLog $log) {
	}

	public function sleepDelay(string $action, string $address): void {
		$delay = self::delayMs($this->log->count('throttle:' . $action, $address, AttemptLog::KEEP_SECONDS));
		if ($delay > 0) {
			usleep($delay * 1000);
		}
	}

	public function registerFailure(string $action, string $address, array $metadata = []): void {
		$this->log->record('throttle:' . $action, $address);
	}

	public function reset(string $action, string $address, array $metadata = []): void {
		$this->log->clear('throttle:' . $action, $address);
	}

	public static function delayMs(int $failures): int {
		return $failures <= 0 ? 0 : min(self::MAX_DELAY_MS, 100 * 2 ** min($failures, 10));
	}
}
