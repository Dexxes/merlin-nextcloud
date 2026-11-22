<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Eine gespeicherte Datei eines Datei-Eintrags (Upload vom Handy).
 *
 * Nextcloud: eine Datei in „Merlin Dateien“ (OCP\Files\File).
 * Standalone: eine Datei im Speicherordner.
 */
interface StoredFile {
	public function getId(): int;

	public function getName(): string;

	public function getMimetype(): string;

	/** Größe in Bytes. */
	public function getSize(): int;

	/** Letzte Änderung als Unix-Zeit, 0 = unbekannt. */
	public function getMTime(): int;

	/**
	 * Öffnet die Datei zum Lesen.
	 *
	 * @return resource|false
	 * @throws \Throwable wenn der Speicher den Zugriff verweigert
	 */
	public function open(string $mode);
}
