<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Domain\FileNameTakenException;
use Merlin\Core\Domain\FileNotFoundException;
use Merlin\Core\Port\FileStorage;
use Merlin\Core\Port\StoredFile;
use Merlin\Core\Port\Translations;
use Merlin\Core\Port\Translator;
use Merlin\Core\Service\FileRules;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * FileStorage-Port über Nextcloud Files: Ordner „Merlin Dateien“/<Art> im
 * Dateibereich des Nutzers, Upload per WebDAV (auch Chunked Upload v2).
 *
 * Ordner: Name in der Nextcloud-Sprache des Nutzers. Die Datei-IDs werden in
 * den Nutzereinstellungen gemerkt, damit ein Sprachwechsel, Umbenennen oder
 * Verschieben keinen zweiten Ordner erzeugt; nur ein gelöschter Ordner wird
 * neu angelegt. Löschen eines Eintrags lässt die Datei in Nextcloud liegen.
 */
class NextcloudFileStorage implements FileStorage {
	private const USER_SETTINGS_APP = 'merlin';
	private const USER_KEY_FOLDER_IDS = 'files_folder_ids';
	private const ROOT_KEY = 'root';

	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private Translations $translations,
	) {
	}

	public function uploadTarget(string $userId, string $name, string $kind): array {
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

	public function get(string $userId, string $path): ?StoredFile {
		try {
			$node = $this->userFolder($userId)->get($path);
		} catch (NotFoundException) {
			return null;
		}
		return $node instanceof File ? new NextcloudStoredFile($node) : null;
	}

	public function find(string $userId, int $fileId): ?StoredFile {
		$node = $this->userFolder($userId)->getFirstNodeById($fileId);
		return $node instanceof File ? new NextcloudStoredFile($node) : null;
	}

	public function rename(string $userId, StoredFile $file, string $newName): void {
		$node = $this->node($userId, $file);
		$parent = $node->getParent();
		// Groß-/Kleinschreibung allein ändern ist erlaubt (gleiche Datei).
		if ($parent->nodeExists($newName) && strcasecmp($newName, (string) $node->getName()) !== 0) {
			throw new FileNameTakenException($newName);
		}
		$node->move(rtrim($parent->getPath(), '/') . '/' . $newName);
	}

	public function entryUrl(string $userId, StoredFile $file): ?string {
		return $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $file->getId()]);
	}

	// ── Ordner ──────────────────────────────────────────────────────────────

	/** Unterordner der Dateiart in „Merlin Dateien“, bei Bedarf angelegt. */
	private function folderFor(string $userId, string $kind): Folder {
		$userFolder = $this->userFolder($userId);
		$l = $this->translations->forUser($userId);
		$ids = json_decode($this->config->getUserValue($userId, self::USER_SETTINGS_APP, self::USER_KEY_FOLDER_IDS, '{}'), true);
		$ids = is_array($ids) ? $ids : [];
		$before = $ids;

		$root = $this->ensureFolder($userFolder, $userFolder, $l->t('Merlin files'), $ids, self::ROOT_KEY);
		$folder = $this->ensureFolder($userFolder, $root, self::subfolderName($l, $kind), $ids, $kind);

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

	public static function subfolderName(Translator $l, string $kind): string {
		return match ($kind) {
			FileRules::KIND_IMAGE => $l->t('Images'),
			FileRules::KIND_VIDEO => $l->t('Videos'),
			FileRules::KIND_AUDIO => $l->t('Audio'),
			FileRules::KIND_PDF   => $l->t('PDFs'),
			default               => $l->t('Other files'),
		};
	}

	private function node(string $userId, StoredFile $file): File {
		$node = $file instanceof NextcloudStoredFile ? $file->node() : $this->userFolder($userId)->getFirstNodeById($file->getId());
		if (!$node instanceof File) {
			throw new FileNotFoundException('File is gone');
		}
		return $node;
	}

	private function userFolder(string $userId): Folder {
		return $this->rootFolder->getUserFolder($userId);
	}
}
