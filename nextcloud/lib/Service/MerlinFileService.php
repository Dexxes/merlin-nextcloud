<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use Merlin\Core\Domain\FileEntry;
use Merlin\Core\Service\FileEntryService;
use Merlin\Core\Service\FileRules;
use OCA\Merlin\Adapter\NextcloudStoredFile;
use OCA\Merlin\Adapter\NextcloudTranslator;
use OCA\Merlin\Db\Article;
use OCA\Merlin\Db\ArticleMapper;
use OCP\Files\AlreadyExistsException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * Dateien vom Handy in „Merlin Dateien“ und ihre Einträge in der Leseliste.
 *
 * Ablauf (iOS-Share-Extension):
 *   1. uploadTarget(): Zielpfad im Unterordner der Dateiart (Bilder, Videos,
 *      Audio, PDFs, Sonstige Dateien) mit freiem Dateinamen.
 *   2. Der Client lädt die Datei selbst per WebDAV hoch (bei großen Dateien in
 *      Stücken). Die Datei geht bewusst nicht durch die Merlin-API: PHP-Upload-
 *      Grenzen und Speicher sind für Videos zu klein.
 *   3. register(): legt den Eintrag an (Kategorie nach Dateiart, Inhalt zeigt
 *      die Datei über signierte Links).
 *
 * Ordner, Upload-Ziel und Registrierung sind Nextcloud-Files/WebDAV-Sache und
 * stehen hier. Inhalt des Eintrags, signierte Links und das Ausliefern der
 * Datei stehen im Kern (Merlin\Core\Service\FileEntryService).
 *
 * Ordner: Name in der Nextcloud-Sprache des Nutzers. Die Datei-IDs werden in den
 * Nutzereinstellungen gemerkt, damit ein Sprachwechsel, Umbenennen oder
 * Verschieben keinen zweiten Ordner erzeugt; nur ein gelöschter Ordner wird neu
 * angelegt.
 *
 * Datei-Links (siehe FileEntryService): Löschen des Eintrags macht die Links
 * ungültig; die Datei selbst bleibt in Nextcloud liegen (auch bei der
 * Löschfrist).
 */
class MerlinFileService {
	private const USER_SETTINGS_APP = 'merlin';
	private const USER_KEY_FOLDER_IDS = 'files_folder_ids';
	private const ROOT_KEY = 'root';

	/** Kantenlänge der Vorschaubilder: Listen/Karten bzw. Bild im Reader. */
	public const THUMBNAIL_SIZE = FileEntryService::THUMBNAIL_SIZE;
	public const READER_IMAGE_SIZE = FileEntryService::READER_IMAGE_SIZE;

	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private ArticleMapper $articleMapper,
		private FileEntryService $entries,
	) {
	}

	/**
	 * Zielpfad für eine neue Datei. Legt die Ordner bei Bedarf an.
	 *
	 * @return array{path: string, davPath: string, uploadsPath: string, kind: string}
	 */
	public function uploadTarget(string $userId, string $name, string $mime): array {
		$kind = FileRules::kindFor($mime);
		$folder = $this->folderFor($userId, $kind);
		$fallback = 'Merlin ' . date('Y-m-d H-i-s');
		$fileName = $folder->getNonExistingName(FileRules::sanitizeName($name, $fallback));
		$path = rtrim((string) $this->userFolder($userId)->getRelativePath($folder->getPath()), '/') . '/' . $fileName;

		return [
			'path'        => $path,
			'davPath'     => '/remote.php/dav/files/' . rawurlencode($userId) . FileRules::encodePath($path),
			'uploadsPath' => '/remote.php/dav/uploads/' . rawurlencode($userId),
			'kind'        => $kind,
		];
	}

	/**
	 * Legt den Eintrag für eine hochgeladene Datei an (oder liefert den
	 * vorhandenen, falls dieselbe Datei schon registriert ist).
	 *
	 * @throws NotFoundException wenn $path keine Datei des Nutzers ist
	 */
	public function register(string $userId, string $path, ?string $text = null): Article {
		$node = $this->userFolder($userId)->get($path);
		if (!$node instanceof File) {
			throw new NotFoundException('Not a file');
		}
		$text = FileRules::sanitizeText($text);
		$fileId = (int) $node->getId();
		$existing = $this->articleMapper->findByFileId($userId, $fileId);
		if ($existing !== null) {
			// Erneutes Registrieren (Upload wiederholt): erkannten Text nachtragen.
			if ($text !== null && $existing->getFileText() === null) {
				$existing->setFileText($text);
				return $this->refresh($existing);
			}
			return $existing;
		}

		$l = $this->l10n($userId);
		$mime = (string) $node->getMimetype();
		$kind = FileRules::kindFor($mime);
		$name = (string) $node->getName();
		$extension = strtoupper(pathinfo($name, PATHINFO_EXTENSION));
		$size = FileRules::humanSize((int) $node->getSize());
		$now = new \DateTime();

		$article = new Article();
		$article->setUserId($userId);
		$article->setUrl($this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $fileId]));
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
		$article->setCategory(FileRules::categoryFor($kind));
		$article->setFileId($fileId);
		$article->setFileMime($mime);
		$article->setFileText($text);
		$article->setCreatedAt($now);
		$article->setUpdatedAt($now);
		$article = $this->articleMapper->insert($article);

		// Die Links brauchen die Eintrags-ID (Token), deshalb erst nach dem Insert.
		$this->render($article, $node, $l);
		return $this->articleMapper->update($article);
	}

	/**
	 * Baut Titel, Vorschaubild und Inhalt (inkl. Metadaten) eines
	 * Datei-Eintrags neu, z. B. für Einträge von vor der Metadaten-Anzeige oder
	 * nachdem die Datei in Nextcloud umbenannt wurde (der Titel ist immer der
	 * Dateiname). Aufruf beim Öffnen (ArticleController::show()) und über
	 * „Neu laden“ (ArticleController::retryExtraction()).
	 *
	 * @throws NotFoundException wenn die Datei nicht mehr existiert
	 */
	public function refresh(Article $article): Article {
		$file = $this->fileOf($article);
		$article->setTitle((string) $file->getName());
		$article->setFileMime((string) $file->getMimetype());
		$this->render($article, $file, $this->l10n((string) $article->getUserId()));
		$article->setUpdatedAt(new \DateTime());
		return $this->articleMapper->update($article);
	}

	/**
	 * Benennt die Datei eines Eintrags in Nextcloud um und baut den Eintrag
	 * neu auf (Titel = Dateiname). $name ist der neue Name ohne Endung; die
	 * Endung der Datei bleibt (eine mitgetippte gleiche Endung wird ignoriert).
	 *
	 * @throws NotFoundException wenn die Datei nicht mehr existiert
	 * @throws \InvalidArgumentException bei leerem Namen
	 * @throws AlreadyExistsException wenn der Name im Ordner schon vergeben ist
	 */
	public function rename(Article $article, string $name): Article {
		$file = $this->fileOf($article);
		$current = (string) $file->getName();
		$newName = FileRules::renamedName($current, $name);
		if ($newName === null) {
			throw new \InvalidArgumentException('Empty name');
		}
		if ($newName !== $current) {
			$parent = $file->getParent();
			// Groß-/Kleinschreibung allein ändern ist erlaubt (gleiche Datei).
			if ($parent->nodeExists($newName) && strcasecmp($newName, $current) !== 0) {
				throw new AlreadyExistsException($newName);
			}
			$file->move(rtrim($parent->getPath(), '/') . '/' . $newName);
		}
		return $this->refresh($article);
	}

	/**
	 * Ob der Eintrag neu aufgebaut werden muss: Datei in Nextcloud umbenannt
	 * oder Inhalt von vor Metadaten/Download-Link (ArticleController::show()).
	 */
	public function needsRefresh(Article $article): bool {
		if (!str_contains((string) $article->getContent(), 'merlin-file-metadata" data-download-src')) {
			return true;
		}
		try {
			return $this->fileOf($article)->getName() !== $article->getTitle();
		} catch (NotFoundException) {
			return false;
		}
	}

	/**
	 * Signierter, absoluter Link auf die Datei eines Eintrags.
	 *
	 * @param int|null $size Vorschaubild mit dieser Kantenlänge statt der Originaldatei
	 */
	public function fileUrl(Article $article, ?int $size = null, bool $download = false): string {
		return $this->entries->fileUrl($this->entryOf($article), $size, $download);
	}

	public function verifyToken(Article $article, string $token): bool {
		return $this->entries->verifyToken($this->entryOf($article), $token);
	}

	/**
	 * Die Datei eines Eintrags im Dateibereich seines Besitzers.
	 *
	 * @throws NotFoundException
	 */
	public function fileOf(Article $article): File {
		if ($article->getFileId() === null) {
			throw new NotFoundException('Not a file entry');
		}
		$node = $this->userFolder((string) $article->getUserId())->getFirstNodeById((int) $article->getFileId());
		if (!$node instanceof File) {
			throw new NotFoundException('File is gone');
		}
		return $node;
	}

	/**
	 * Gibt die Datei (oder ein Vorschaubild) aus und beendet den Prozess per
	 * exit() (FileEntryService::stream()), damit Nextcloud keine eigenen
	 * Bytes anhängt. Unterstützt einfache Range-Anfragen (Video-Spulen, pdf.js).
	 */
	public function stream(Article $article, ?int $size = null, bool $download = false): never {
		try {
			$file = new NextcloudStoredFile($this->fileOf($article));
		} catch (NotFoundException) {
			$file = null;
		}
		$this->entries->stream($file, $size, $download);
	}

	// ── Ordner ──────────────────────────────────────────────────────────────

	/**
	 * Unterordner der Dateiart in „Merlin Dateien“, bei Bedarf angelegt.
	 */
	public function folderFor(string $userId, string $kind): Folder {
		$userFolder = $this->userFolder($userId);
		$l = $this->l10n($userId);
		$ids = json_decode($this->config->getUserValue($userId, self::USER_SETTINGS_APP, self::USER_KEY_FOLDER_IDS, '{}'), true);
		$ids = is_array($ids) ? $ids : [];
		$before = $ids;

		$root = $this->ensureFolder($userFolder, $userFolder, $l->t('Merlin files'), $ids, self::ROOT_KEY);
		$folder = $this->ensureFolder($userFolder, $root, $this->subfolderName($l, $kind), $ids, $kind);

		if ($ids !== $before) {
			$this->config->setUserValue($userId, self::USER_SETTINGS_APP, self::USER_KEY_FOLDER_IDS, (string) json_encode($ids));
		}
		return $folder;
	}

	/**
	 * Gemerkter Ordner (per Datei-ID, egal wie er heute heißt oder wo er liegt),
	 * sonst ein vorhandener Ordner gleichen Namens, sonst ein neuer.
	 *
	 * @param array<string, int> $ids
	 */
	private function ensureFolder(Folder $userFolder, Folder $parent, string $name, array &$ids, string $key): Folder {
		if (isset($ids[$key])) {
			$known = $userFolder->getFirstNodeById((int) $ids[$key]);
			if ($known instanceof Folder) {
				return $known;
			}
		}
		if ($parent->nodeExists($name)) {
			$existing = $parent->get($name);
			if ($existing instanceof Folder) {
				$ids[$key] = (int) $existing->getId();
				return $existing;
			}
			// Eine Datei trägt den Namen: Ordner daneben mit freiem Namen.
			$name = $parent->getNonExistingName($name);
		}
		$folder = $parent->newFolder($name);
		$ids[$key] = (int) $folder->getId();
		return $folder;
	}

	private function subfolderName(IL10N $l, string $kind): string {
		return match ($kind) {
			FileRules::KIND_IMAGE => $l->t('Images'),
			FileRules::KIND_VIDEO => $l->t('Videos'),
			FileRules::KIND_AUDIO => $l->t('Audio'),
			FileRules::KIND_PDF   => $l->t('PDFs'),
			default               => $l->t('Other files'),
		};
	}

	// ── Inhalt des Eintrags ─────────────────────────────────────────────────

	/** Vorschaubild und Inhalt des Eintrags neu aus der Datei (Kern). */
	private function render(Article $article, File $file, IL10N $l): void {
		$rendered = $this->entries->render($this->entryOf($article), new NextcloudStoredFile($file), new NextcloudTranslator($l));
		$article->setImageUrl($rendered['imageUrl']);
		$article->setContent($rendered['content']);
	}

	private function entryOf(Article $article): FileEntry {
		$fileId = $article->getFileId();
		return new FileEntry((int) $article->getId(), $fileId === null ? null : (int) $fileId, (string) $article->getUserId(), $article->getFileText());
	}

	// ── Hilfen ──────────────────────────────────────────────────────────────

	private function userFolder(string $userId): Folder {
		return $this->rootFolder->getUserFolder($userId);
	}

	/** Übersetzungen in der Nextcloud-Sprache des Nutzers (nicht der des Geräts). */
	private function l10n(string $userId): IL10N {
		$user = $this->userManager->get($userId);
		return $this->l10nFactory->get('merlin', $this->l10nFactory->getUserLanguage($user));
	}
}
