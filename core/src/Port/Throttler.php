<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Brute-Force-Schutz für Passwortprüfungen (z. B. Passwort eines
 * Share-Links): Fehlversuche je Aktion und IP verlängern die Wartezeit vor
 * dem nächsten Versuch. Nextcloud: IThrottler; Standalone: eigene Tabelle.
 */
interface Throttler {
	/**
	 * Wartet kurz, wie es die bisherigen Fehlversuche verlangen, oder weist
	 * den Versuch ab, wenn die Wartezeit dafür zu lang wäre.
	 *
	 * @throws ThrottledException
	 */
	public function sleepDelay(string $action, string $address): void;

	/** @param array<string, string> $metadata */
	public function registerFailure(string $action, string $address, array $metadata = []): void;

	/** Nach Erfolg: Fehlversuche dieser IP für die Aktion vergessen. */
	public function reset(string $action, string $address, array $metadata = []): void;
}
