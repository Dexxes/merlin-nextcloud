<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Throttler::sleepDelay(): zu viele Fehlversuche, die Anfrage wird ohne
 * Prüfung abgewiesen (HTTP 429). Statt eines Workers, der bis zu 25 Sekunden
 * schläft, bekommt der Client sofort eine Antwort mit Retry-After.
 */
final class ThrottledException extends \RuntimeException {
	public function __construct(public readonly int $retryAfter) {
		parent::__construct('Too many failed attempts, retry after ' . $retryAfter . ' s');
	}
}
