<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\Db\Article;
use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Service\Media\MediaResolverService;
use OCA\Merlin\Service\Media\MediaResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Util;
use Psr\Log\LoggerInterface;

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
 * Ordner: Name in der Nextcloud-Sprache des Nutzers. Die Datei-IDs werden in den
 * Nutzereinstellungen gemerkt, damit ein Sprachwechsel, Umbenennen oder
 * Verschieben keinen zweiten Ordner erzeugt; nur ein gelöschter Ordner wird neu
 * angelegt.
 *
 * Datei-Links: /api/articles/{id}/file?t=… ist ohne Login abrufbar, das Token
 * (FileRules::sign) bindet ihn an Eintrag, Datei und Besitzer. So funktionieren
 * dieselben Links in der Web-Oberfläche, im iOS-Reader (Bild-Cache, Player,
 * PDF-Cache laden ohne Zugangsdaten) und hinter öffentlichen Share-Links.
 * Löschen des Eintrags macht die Links ungültig; die Datei selbst bleibt in
 * Nextcloud liegen (auch bei der Löschfrist).
 */
class MerlinFileService {
	private const USER_SETTINGS_APP = 'merlin';
	private const USER_KEY_FOLDER_IDS = 'files_folder_ids';
	private const ROOT_KEY = 'root';

	/** Kantenlänge der Vorschaubilder: Listen/Karten bzw. Bild im Reader. */
	public const THUMBNAIL_SIZE = 512;
	public const READER_IMAGE_SIZE = 2048;

	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private IPreview $preview,
		private ArticleMapper $articleMapper,
		private LoggerInterface $logger,
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
	public function register(string $userId, string $path): Article {
		$node = $this->userFolder($userId)->get($path);
		if (!$node instanceof File) {
			throw new NotFoundException('Not a file');
		}
		$fileId = (int) $node->getId();
		$existing = $this->articleMapper->findByFileId($userId, $fileId);
		if ($existing !== null) {
			return $existing;
		}

		$l = $this->l10n($userId);
		$mime = (string) $node->getMimetype();
		$kind = FileRules::kindFor($mime);
		$name = (string) $node->getName();
		$extension = strtoupper(pathinfo($name, PATHINFO_EXTENSION));
		$size = Util::humanFileSize((int) $node->getSize());
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
		$article->setCreatedAt($now);
		$article->setUpdatedAt($now);
		$article = $this->articleMapper->insert($article);

		// Die Links brauchen die Eintrags-ID (Token), deshalb erst nach dem Insert.
		$hasPreview = $this->hasPreview($node);
		$article->setImageUrl($hasPreview ? $this->fileUrl($article, self::THUMBNAIL_SIZE) : '');
		$article->setContent($this->buildContent($article, $kind, $name, $size, $hasPreview, $l));
		return $this->articleMapper->update($article);
	}

	/**
	 * Signierter, absoluter Link auf die Datei eines Eintrags.
	 *
	 * @param int|null $size Vorschaubild mit dieser Kantenlänge statt der Originaldatei
	 */
	public function fileUrl(Article $article, ?int $size = null, bool $download = false): string {
		$params = [
			'id' => (int) $article->getId(),
			't'  => FileRules::sign($this->secret(), (int) $article->getId(), (int) $article->getFileId(), (string) $article->getUserId()),
		];
		if ($size !== null) {
			$params['size'] = $size;
		}
		if ($download) {
			$params['download'] = 1;
		}
		return $this->urlGenerator->linkToRouteAbsolute('merlin.file.content', $params);
	}

	public function verifyToken(Article $article, string $token): bool {
		return $article->getFileId() !== null
			&& FileRules::verify($this->secret(), (int) $article->getId(), (int) $article->getFileId(), (string) $article->getUserId(), $token);
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
	 * Gibt die Datei (oder ein Vorschaubild) aus und beendet den Prozess wie
	 * PdfProxyService/TtsStreamService per exit(), damit Nextcloud keine eigenen
	 * Bytes anhängt. Unterstützt einfache Range-Anfragen (Video-Spulen, pdf.js).
	 */
	public function stream(Article $article, ?int $size = null, bool $download = false): never {
		try {
			$file = $this->fileOf($article);
		} catch (NotFoundException) {
			$this->fail(404, 'File not found');
		}

		if ($size !== null && $size > 0 && !$download) {
			$this->streamPreview($file, min($size, self::READER_IMAGE_SIZE));
		}

		$mime = (string) $file->getMimetype();
		$inline = !$download && FileRules::isInlineMime($mime);
		$total = (int) $file->getSize();
		$range = FileRules::parseRange($_SERVER['HTTP_RANGE'] ?? null, $total);
		if ($range === false) {
			http_response_code(416);
			header('Content-Range: bytes */' . $total);
			exit();
		}

		$handle = $file->fopen('rb');
		if ($handle === false) {
			$this->fail(500, 'File could not be opened');
		}
		[$start, $end] = $range ?? [0, max(0, $total - 1)];
		if ($start > 0 && fseek($handle, $start) !== 0) {
			// Speicher ohne Seek (selten): dann eben die ganze Datei.
			rewind($handle);
			[$start, $end] = [0, max(0, $total - 1)];
			$range = null;
		}
		$length = $total === 0 ? 0 : $end - $start + 1;

		$this->prepareOutput();
		http_response_code($range !== null ? 206 : 200);
		header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
		header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode((string) $file->getName()));
		header('Content-Length: ' . $length);
		header('Accept-Ranges: bytes');
		if ($range !== null) {
			header('Content-Range: bytes ' . $start . '-' . $end . '/' . $total);
		}
		$this->hardeningHeaders();

		$remaining = $length;
		while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
			$chunk = fread($handle, min(65536, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}
			echo $chunk;
			flush();
			$remaining -= strlen($chunk);
		}
		fclose($handle);
		exit();
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

	/**
	 * HTML des Eintrags: dieselben Marker wie bei Web-Artikeln, damit Web-Reader,
	 * Share-Ansicht und iOS die Datei ohne Sonderweg anzeigen (Bild als Figure,
	 * Audio/Video als Medien-Marker mit Auslieferung "file", PDF als .merlin-pdf).
	 */
	private function buildContent(Article $article, string $kind, string $name, string $size, bool $hasPreview, IL10N $l): string {
		$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$fileUrl = $this->fileUrl($article);
		$poster = $hasPreview
			? '<figure><img src="' . $esc($this->fileUrl($article, self::READER_IMAGE_SIZE)) . '" alt="' . $esc($name) . '"></figure>'
			: '';

		switch ($kind) {
			case FileRules::KIND_IMAGE:
				// Vorschaubild (JPEG, auch für HEIC), sonst die Originaldatei.
				$src = $hasPreview ? $this->fileUrl($article, self::READER_IMAGE_SIZE) : $fileUrl;
				return '<figure><img src="' . $esc($src) . '" alt="' . $esc($name) . '"></figure>';
			case FileRules::KIND_VIDEO:
			case FileRules::KIND_AUDIO:
				$media = MediaResult::single($kind, MediaResult::DELIVERY_FILE, $fileUrl);
				return $poster . MediaResolverService::buildMarkerHtml($kind, $media, $fileUrl);
			case FileRules::KIND_PDF:
				return '<div class="' . ContentExtractorService::PDF_MARKER_CLASS . '" data-pdf-src="' . $esc($fileUrl) . '">'
					. '<a href="' . $esc($fileUrl) . '" target="_blank" class="merlin-pdf-fallback-link">PDF</a>'
					. '</div>';
			default:
				return '<p><a class="merlin-file-download" href="' . $esc($this->fileUrl($article, null, true)) . '">'
					. $esc($l->t('Download file')) . '</a> · ' . $esc($name) . ' · ' . $esc($size) . '</p>';
		}
	}

	private function hasPreview(File $file): bool {
		try {
			return $this->preview->isAvailable($file);
		} catch (\Throwable) {
			return false;
		}
	}

	private function streamPreview(File $file, int $size): never {
		try {
			$preview = $this->preview->getPreview($file, $size, $size);
		} catch (\Throwable $e) {
			$this->logger->debug('Merlin: no preview for file entry', ['exception' => $e]);
			$this->fail(404, 'No preview');
		}
		$this->prepareOutput();
		http_response_code(200);
		header('Content-Type: ' . $preview->getMimeType());
		header('Content-Length: ' . $preview->getSize());
		$this->hardeningHeaders();
		echo $preview->getContent();
		exit();
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

	private function secret(): string {
		return $this->config->getSystemValueString('secret', '') . '|merlin-file-links';
	}

	private function hardeningHeaders(): void {
		header('X-Content-Type-Options: nosniff');
		header("Content-Security-Policy: sandbox; default-src 'none'");
		header('Cache-Control: private, max-age=86400');
		header('X-Accel-Buffering: no');
	}

	private function prepareOutput(): void {
		set_time_limit(0);
		while (ob_get_level()) {
			ob_end_clean();
		}
	}

	private function fail(int $status, string $message): never {
		http_response_code($status);
		header('Content-Type: application/json');
		echo json_encode(['error' => $message]);
		exit();
	}
}
