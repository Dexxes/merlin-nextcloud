<?php

declare(strict_types=1);

namespace Merlin\Core\Service;

use Merlin\Core\Domain\Article;
use Merlin\Core\Domain\FileEntry;
use Merlin\Core\Domain\FileNameTakenException;
use Merlin\Core\Domain\FileNotFoundException;
use Merlin\Core\Port\ArticleFiles;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\FileStorage;
use Merlin\Core\Port\StoredFile;
use Merlin\Core\Port\Translations;

/**
 * Dateien vom Handy in „Merlin Dateien“ und ihre Einträge in der Leseliste.
 *
 * Ablauf (iOS-Share-Extension):
 *   1. uploadTarget(): Zielpfad im Unterordner der Dateiart mit freiem Namen.
 *   2. Der Client lädt die Datei selbst per WebDAV hoch (große Dateien in
 *      Stücken). Die Datei geht bewusst nicht durch die Merlin-API:
 *      PHP-Upload-Grenzen und Speicher sind für Videos zu klein.
 *   3. register(): legt den Eintrag an (Kategorie nach Dateiart, Inhalt zeigt
 *      die Datei über signierte Links, siehe FileEntryService).
 *
 * Wo die Dateien liegen, steht hinter dem Port FileStorage (Nextcloud Files
 * bzw. Datenverzeichnis des Standalone-Servers). Löschen des Eintrags macht
 * die Links ungültig; die Datei selbst bleibt liegen (Nextcloud) bzw. räumt
 * der tägliche Lauf auf (Standalone).
 */
class FileEntries implements ArticleFiles {
	public function __construct(
		private FileStorage $storage,
		private ArticleStore $articles,
		private FileEntryService $entries,
		private Translations $translations,
	) {
	}

	/** @return array{path: string, davPath: string, uploadsPath: string, kind: string} */
	public function uploadTarget(string $userId, string $name, string $mime): array {
		return $this->storage->uploadTarget($userId, $name, FileRules::kindFor($mime));
	}

	/**
	 * Legt den Eintrag für eine hochgeladene Datei an, oder liefert den
	 * vorhandenen, falls dieselbe Datei schon registriert ist (Upload
	 * wiederholt; dann wird ein neu mitgeschickter erkannter Text nachgetragen).
	 *
	 * @throws FileNotFoundException wenn $path keine Datei des Nutzers ist
	 */
	public function register(string $userId, string $path, ?string $text = null): Article {
		$file = $this->storage->get($userId, $path);
		if ($file === null) {
			throw new FileNotFoundException('Not a file');
		}
		$text = FileRules::sanitizeText($text);
		$existing = $this->articles->findByFileId($userId, $file->getId());
		if ($existing !== null) {
			if ($text !== null && $existing->getFileText() === null) {
				$existing->setFileText($text);
				return $this->rebuild($existing, $file);
			}
			return $existing;
		}

		$l = $this->translations->forUser($userId);
		$mime = $file->getMimetype();
		$name = $file->getName();
		$extension = strtoupper(pathinfo($name, PATHINFO_EXTENSION));
		$size = FileRules::humanSize($file->getSize());
		$now = new \DateTime();

		$article = new Article();
		$article->setUserId($userId);
		$article->setUrl($this->storage->entryUrl($userId, $file) ?? '');
		$article->setTitle($name);
		$article->setContent('');
		$article->setExcerpt($extension !== '' ? $extension . ' · ' . $size : $size);
		$article->setAuthor('');
		$article->setSiteName($l->t('Merlin files'));
		$article->setImageUrl('');
		$article->setReadingTime(0);
		$article->setIsRead(0);
		$article->setIsFavorite(null);
		$article->setIsArchived(0);
		$article->setIsProcessing(0);
		$article->setCategory(FileRules::categoryFor(FileRules::kindFor($mime)));
		$article->setFileId($file->getId());
		$article->setFileMime($mime);
		$article->setFileText($text);
		$article->setCreatedAt($now);
		$article->setUpdatedAt($now);
		$article = $this->articles->insert($article);

		// Die Links brauchen die Eintrags-ID (Token), deshalb erst nach dem Insert.
		if ($article->getUrl() === '') {
			$article->setUrl($this->entries->fileUrl($this->entryOf($article)));
		}
		$this->render($article, $file);
		return $this->articles->update($article);
	}

	/**
	 * Benennt die Datei eines Eintrags um und baut den Eintrag neu auf
	 * (Titel = Dateiname). $name ohne Endung; die Endung der Datei bleibt.
	 *
	 * @throws FileNotFoundException wenn die Datei fehlt
	 * @throws \InvalidArgumentException bei leerem Namen
	 * @throws FileNameTakenException wenn der Name im Ordner schon vergeben ist
	 */
	public function rename(Article $article, string $name): Article {
		$file = $this->fileOf($article);
		$current = $file->getName();
		$newName = FileRules::renamedName($current, $name);
		if ($newName === null) {
			throw new \InvalidArgumentException('Empty name');
		}
		if ($newName !== $current) {
			$this->storage->rename($article->getUserId(), $file, $newName);
			$file = $this->fileOf($article);
		}
		return $this->rebuild($article, $file);
	}

	/**
	 * Ob der Eintrag neu aufgebaut werden muss: Datei umbenannt oder Inhalt
	 * von vor Metadaten/Download-Link.
	 */
	public function needsRefresh(Article $article): bool {
		if (!str_contains($article->getContent(), 'merlin-file-metadata" data-download-src')) {
			return true;
		}
		$file = $this->storage->find($article->getUserId(), (int) $article->getFileId());
		return $file !== null && $file->getName() !== $article->getTitle();
	}

	public function verifyToken(Article $article, string $token): bool {
		return $this->entries->verifyToken($this->entryOf($article), $token);
	}

	/**
	 * Gibt die Datei (oder ein Vorschaubild) aus und beendet den Request
	 * (FileEntryService::stream()); Range für Video und pdf.js.
	 */
	public function streamFile(Article $article, ?int $size = null, bool $download = false): never {
		$file = $article->getFileId() === null ? null : $this->storage->find($article->getUserId(), (int) $article->getFileId());
		$this->entries->stream($file, $size, $download);
	}

	// ── Port ArticleFiles (Artikel-API) ─────────────────────────────────────

	public function refreshIfNeeded(Article $article): Article {
		return $this->needsRefresh($article) ? $this->refresh($article) : $article;
	}

	/** Eintrag aus der Datei neu aufbauen; unverändert, wenn die Datei fehlt. */
	public function refresh(Article $article): Article {
		try {
			return $this->rebuild($article, $this->fileOf($article));
		} catch (FileNotFoundException) {
			return $article;
		}
	}

	public function stream(Article $article): never {
		$this->streamFile($article);
	}

	// ── Hilfen ──────────────────────────────────────────────────────────────

	/** @throws FileNotFoundException */
	private function fileOf(Article $article): StoredFile {
		$file = $article->getFileId() === null ? null : $this->storage->find($article->getUserId(), (int) $article->getFileId());
		if ($file === null) {
			throw new FileNotFoundException('File is gone');
		}
		return $file;
	}

	/** Titel = Dateiname, Typ, Vorschaubild und Inhalt neu aus der Datei. */
	private function rebuild(Article $article, StoredFile $file): Article {
		$article->setTitle($file->getName());
		$article->setFileMime($file->getMimetype());
		$this->render($article, $file);
		$article->setUpdatedAt(new \DateTime());
		return $this->articles->update($article);
	}

	private function render(Article $article, StoredFile $file): void {
		$rendered = $this->entries->render($this->entryOf($article), $file, $this->translations->forUser($article->getUserId()));
		$article->setImageUrl($rendered['imageUrl']);
		$article->setContent($rendered['content']);
	}

	private function entryOf(Article $article): FileEntry {
		$fileId = $article->getFileId();
		return new FileEntry((int) $article->getId(), $fileId === null ? null : (int) $fileId, $article->getUserId(), $article->getFileText());
	}
}
