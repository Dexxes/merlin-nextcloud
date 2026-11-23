<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\ThrottledException;
use Merlin\Core\Port\Throttler;
use Merlin\Standalone\Security\AttemptLog;

/**
 * Throttler-Port wie Nextclouds IThrottler: jeder Fehlversuch der letzten
 * zwölf Stunden verdoppelt die Wartezeit vor dem nächsten Versuch, höchstens
 * 25 Sekunden.
 *
 * Kurze Wartezeiten (bis MAX_SLEEP_MS) schläft der Request ab. Längere
 * blockieren keinen PHP-Worker: liegt der letzte Fehlversuch noch keine
 * Wartezeit zurück, wird der Versuch mit ThrottledException (HTTP 429)
 * abgewiesen, sonst ohne Warten geprüft. Ein Angreifer bekommt so höchstens
 * einen Versuch je Wartezeit, auch mit parallelen Anfragen.
 */
final class SqlThrottler implements Throttler {
	private const MAX_DELAY_MS = 25000;
	public const MAX_SLEEP_MS = 1600;

	public function __construct(private AttemptLog $log) {
	}

	public function sleepDelay(string $action, string $address): void {
		$key = 'throttle:' . $action;
		$delay = self::delayMs($this->log->count($key, $address, AttemptLog::KEEP_SECONDS));
		if ($delay <= 0) {
			return;
		}
		if ($delay <= self::MAX_SLEEP_MS) {
			usleep($delay * 1000);
			return;
		}
		$wait = self::remainingSeconds($delay, $this->log->latest($key, $address, AttemptLog::KEEP_SECONDS), time());
		if ($wait > 0) {
			throw new ThrottledException($wait);
		}
	}

	/** Sekunden bis zum nächsten erlaubten Versuch (0 = jetzt erlaubt). */
	public static function remainingSeconds(int $delayMs, ?int $lastFailure, int $now): int {
		if ($lastFailure === null) {
			return 0;
		}
		return max(0, $lastFailure + (int) ceil($delayMs / 1000) - $now);
	}

	public function registerFailure(string $action, string $address, array $metadata = []): void {
		$this->log->record('throttle:' . $action, $address);
	}

	/**
	 * Bewusst ohne Wirkung: die Tabelle kennt keine Metadaten (Token,
	 * Anmeldename), ein Zurücksetzen träfe also alle Fehlversuche der IP für
	 * die Aktion. Wer eigene Zugangsdaten kennt (eigener Share-Link, eigenes
	 * Konto), könnte die Bremse sonst nach jedem Rateversuch mit einem
	 * erfolgreichen Versuch leeren. Fehlversuche verfallen nach
	 * AttemptLog::KEEP_SECONDS von selbst.
	 */
	public function reset(string $action, string $address, array $metadata = []): void {
	}

	public static function delayMs(int $failures): int {
		return $failures <= 0 ? 0 : min(self::MAX_DELAY_MS, 100 * 2 ** min($failures, 10));
	}
}
