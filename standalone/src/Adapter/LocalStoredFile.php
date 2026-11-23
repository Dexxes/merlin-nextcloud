<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\StoredFile;

/** StoredFile-Port über eine Datei im Datenverzeichnis (LocalFileStorage). */
final class LocalStoredFile implements StoredFile {
	public function __construct(
		private int $id,
		private string $absolutePath,
		private string $mime,
	) {
	}

	public function absolutePath(): string {
		return $this->absolutePath;
	}

	public function getId(): int {
		return $this->id;
	}

	public function getName(): string {
		return basename($this->absolutePath);
	}

	public function getMimetype(): string {
		return $this->mime;
	}

	public function getSize(): int {
		clearstatcache(true, $this->absolutePath);
		return (int) @filesize($this->absolutePath);
	}

	public function getMTime(): int {
		return (int) @filemtime($this->absolutePath);
	}

	public function open(string $mode) {
		return @fopen($this->absolutePath, $mode);
	}
}
