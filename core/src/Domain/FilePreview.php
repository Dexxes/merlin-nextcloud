<?php

declare(strict_types=1);

namespace Merlin\Core\Domain;

/**
 * Ein Vorschaubild (Port\FilePreviews). Der Inhalt wird erst beim Ausgeben
 * gelesen, nach den Headern.
 */
final class FilePreview {
	/**
	 * @param \Closure(): string $content
	 */
	public function __construct(
		public readonly string $mimeType,
		public readonly int $size,
		private \Closure $content,
	) {
	}

	public function content(): string {
		return ($this->content)();
	}
}
