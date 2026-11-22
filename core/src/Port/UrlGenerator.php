<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Baut absolute URLs auf Ressourcen der laufenden Merlin-Instanz.
 *
 * Nextcloud: IURLGenerator (App-Pfad unter /apps/merlin bzw. custom_apps).
 * Standalone: Basis-URL aus der Konfiguration.
 */
interface UrlGenerator {
	/**
	 * Absolute URL eines mitgelieferten Bildes der App (z. B.
	 * "bluesky-preview.png" aus img/).
	 */
	public function absoluteImageUrl(string $file): string;

	/**
	 * Absolute URL des Datei-Endpunkts eines Datei-Eintrags
	 * (/api/articles/{id}/file). $params: id, t (Signatur), optional size
	 * und download.
	 *
	 * @param array<string, int|string> $params
	 */
	public function fileContentUrl(array $params): string;
}
