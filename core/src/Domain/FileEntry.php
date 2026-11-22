<?php

declare(strict_types=1);

namespace Merlin\Core\Domain;

/**
 * Die Felder eines Datei-Eintrags der Leseliste, die Links und Inhalt
 * brauchen (Service\FileEntryService). Nextcloud: aus OCA\Merlin\Db\Article.
 */
final class FileEntry {
	public function __construct(
		public readonly int $articleId,
		public readonly ?int $fileId,
		public readonly string $userId,
		public readonly ?string $fileText = null,
	) {
	}
}
