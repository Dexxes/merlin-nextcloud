<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\FileNameTakenException;

/**
 * Speicher für „Merlin Dateien“: wohin der Client eine Datei hochlädt, wie
 * eine hochgeladene Datei gefunden und umbenannt wird.
 *
 * Nextcloud: Ordner „Merlin Dateien“/<Art> in Nextcloud Files, Upload per
 * WebDAV (Adapter\NextcloudFileStorage). Standalone: Ordner je Nutzer im
 * Datenverzeichnis, Upload über dieselben WebDAV-Pfade
 * (Adapter\LocalFileStorage, Controller\DavController).
 */
interface FileStorage {
	/**
	 * Zielpfad für eine neue Datei der Art $kind (FileRules::KIND_*), freier
	 * Dateiname. Legt die Ordner bei Bedarf an.
	 *
	 * @return array{path: string, davPath: string, uploadsPath: string, kind: string}
	 *         davPath/uploadsPath relativ zur Basis-URL des Clients
	 */
	public function uploadTarget(string $userId, string $name, string $kind): array;

	/** Datei des Nutzers unter $path (wie von uploadTarget()), null wenn keine. */
	public function get(string $userId, string $path): ?StoredFile;

	/** Datei des Nutzers mit dieser ID, null wenn sie fehlt. */
	public function find(string $userId, int $fileId): ?StoredFile;

	/**
	 * Benennt die Datei im selben Ordner um. Nur Groß-/Kleinschreibung ändern
	 * ist erlaubt.
	 *
	 * @throws FileNameTakenException wenn der Name im Ordner schon vergeben ist
	 */
	public function rename(string $userId, StoredFile $file, string $newName): void;

	/**
	 * Link für das Feld url des Eintrags (Nextcloud: die Datei in der
	 * Files-App); null = der signierte Link auf die Datei.
	 */
	public function entryUrl(string $userId, StoredFile $file): ?string;
}
