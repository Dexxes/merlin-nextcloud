<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\FilePreview;

/**
 * Vorschaubilder gespeicherter Dateien (JPEG auch für HEIC, Videos, PDFs).
 *
 * Nextcloud: IPreview. Standalone: eigener Generator oder keiner.
 */
interface FilePreviews {
	/**
	 * Ob sich für die Datei ein Vorschaubild erzeugen lässt. Darf werfen; der
	 * Aufrufer wertet das als „nein“.
	 */
	public function isAvailable(StoredFile $file): bool;

	/**
	 * Vorschaubild mit höchstens $size Pixeln Kantenlänge.
	 *
	 * @throws \Throwable wenn es keines gibt
	 */
	public function preview(StoredFile $file, int $size): FilePreview;
}
