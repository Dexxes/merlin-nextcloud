<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Domain\FilePreview;
use Merlin\Core\Port\FilePreviews;
use Merlin\Core\Port\StoredFile;
use OCP\IPreview;

/**
 * FilePreviews-Port des Kerns über Nextclouds IPreview. Kennt nur Dateien aus
 * NextcloudStoredFile.
 */
class NextcloudFilePreviews implements FilePreviews {
	public function __construct(
		private IPreview $preview,
	) {
	}

	public function isAvailable(StoredFile $file): bool {
		return $this->preview->isAvailable($this->node($file));
	}

	public function preview(StoredFile $file, int $size): FilePreview {
		$preview = $this->preview->getPreview($this->node($file), $size, $size);
		return new FilePreview(
			(string) $preview->getMimeType(),
			(int) $preview->getSize(),
			static fn (): string => (string) $preview->getContent(),
		);
	}

	private function node(StoredFile $file): \OCP\Files\File {
		if (!$file instanceof NextcloudStoredFile) {
			throw new \InvalidArgumentException('Not a Nextcloud file');
		}
		return $file->node();
	}
}
