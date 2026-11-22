<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\StoredFile;
use OCP\Files\File;

/**
 * StoredFile-Port des Kerns über eine Datei in Nextcloud Files.
 */
class NextcloudStoredFile implements StoredFile {
	public function __construct(
		private File $file,
	) {
	}

	/** Die Nextcloud-Datei selbst (Vorschaubilder, Umbenennen). */
	public function node(): File {
		return $this->file;
	}

	public function getId(): int {
		return (int) $this->file->getId();
	}

	public function getName(): string {
		return (string) $this->file->getName();
	}

	public function getMimetype(): string {
		return (string) $this->file->getMimetype();
	}

	public function getSize(): int {
		return (int) $this->file->getSize();
	}

	public function getMTime(): int {
		return (int) $this->file->getMTime();
	}

	public function open(string $mode) {
		return $this->file->fopen($mode);
	}
}
