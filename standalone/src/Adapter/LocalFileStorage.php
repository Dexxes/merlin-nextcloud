<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Domain\FileNameTakenException;
use Merlin\Core\Port\Connection;
use Merlin\Core\Port\FileStorage;
use Merlin\Core\Port\StoredFile;
use Merlin\Core\Service\FileRules;

/**
 * FileStorage-Port des Standalone-Servers: „Merlin Dateien“ im
 * Datenverzeichnis ('files_path', Vorgabe data/files neben der Datenbank).
 *
 *   <root>/<userId>/<Art>/<Datei>   die Dateien (Art: Images, Videos, Audio, PDFs, Other files)
 *   <root>/.uploads/<userId>/<id>/  Stücke eines Chunked Uploads (Controller\DavController)
 *   <root>/.previews/               Vorschaubilder (GdFilePreviews)
 *
 * Tabelle stored_files ordnet jeder Datei eine ID zu (file_id der Einträge).
 * Hochgeladen wird über dieselben WebDAV-Pfade wie in Nextcloud
 * (/remote.php/dav/files/…, /remote.php/dav/uploads/…), damit die Apps
 * nichts unterscheiden müssen. Dateien ohne Eintrag räumt der tägliche Lauf
 * nach einem Tag weg (cleanup()).
 */
final class LocalFileStorage implements FileStorage {
	public const FOLDERS = [
		FileRules::KIND_IMAGE => 'Images',
		FileRules::KIND_VIDEO => 'Videos',
		FileRules::KIND_AUDIO => 'Audio',
		FileRules::KIND_PDF   => 'PDFs',
		FileRules::KIND_OTHER => 'Other files',
	];

	/** Endung => MIME-Typ, vor der Inhaltserkennung (wie Nextclouds Zuordnung nach Endung). */
	private const MIME_BY_EXTENSION = [
		'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
		'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif', 'avif' => 'image/avif',
		'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml',
		'mp4' => 'video/mp4', 'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
		'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav',
		'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg', 'flac' => 'audio/flac',
		'pdf' => 'application/pdf', 'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv',
		'json' => 'application/json', 'zip' => 'application/zip',
	];

	public function __construct(
		private Connection $db,
		private string $root,
	) {
	}

	public function root(): string {
		return rtrim($this->root, '/');
	}

	public function uploadTarget(string $userId, string $name, string $kind): array {
		$folder = '/' . (self::FOLDERS[$kind] ?? self::FOLDERS[FileRules::KIND_OTHER]);
		$dir = $this->absolute($userId, $folder);
		self::ensureDir($dir);
		$fileName = self::nonExistingName($dir, FileRules::sanitizeName($name, 'Merlin ' . date('Y-m-d H-i-s')));
		$path = $folder . '/' . $fileName;
		return [
			'path'        => $path,
			'davPath'     => '/remote.php/dav/files/' . rawurlencode($userId) . FileRules::encodePath($path),
			'uploadsPath' => '/remote.php/dav/uploads/' . rawurlencode($userId),
			'kind'        => $kind,
		];
	}

	public function get(string $userId, string $path): ?StoredFile {
		$path = self::normalize($path);
		if ($path === null || !is_file($this->absolute($userId, $path))) {
			return null;
		}
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*stored_files WHERE user_id = :user_id AND path = :path',
			['user_id' => $userId, 'path' => $path],
		);
		if ($row === null) {
			// Liegt da, kam aber nicht über DavController (z. B. von Hand kopiert).
			$this->remember($userId, $path, null);
			return $this->get($userId, $path);
		}
		return $this->toFile($row);
	}

	public function find(string $userId, int $fileId): ?StoredFile {
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*stored_files WHERE id = :id AND user_id = :user_id',
			['id' => $fileId, 'user_id' => $userId],
		);
		if ($row === null || !is_file($this->absolute($userId, (string) $row['path']))) {
			return null;
		}
		return $this->toFile($row);
	}

	public function rename(string $userId, StoredFile $file, string $newName): void {
		$row = $this->db->fetchRow('SELECT * FROM *PREFIX*stored_files WHERE id = :id AND user_id = :user_id', ['id' => $file->getId(), 'user_id' => $userId]);
		if ($row === null || str_contains($newName, '/') || $newName === '.' || $newName === '..') {
			throw new \InvalidArgumentException('Invalid name');
		}
		$oldPath = (string) $row['path'];
		$newPath = rtrim(dirname($oldPath), '/') . '/' . $newName;
		$from = $this->absolute($userId, $oldPath);
		$to = $this->absolute($userId, $newPath);
		// Groß-/Kleinschreibung allein ändern ist erlaubt (gleiche Datei).
		if (file_exists($to) && strcasecmp($newName, basename($oldPath)) !== 0) {
			throw new FileNameTakenException($newName);
		}
		if (!@rename($from, $to)) {
			throw new \RuntimeException('Rename failed');
		}
		$this->db->execute('UPDATE *PREFIX*stored_files SET path = :path WHERE id = :id', ['path' => $newPath, 'id' => $file->getId()]);
	}

	public function entryUrl(string $userId, StoredFile $file): ?string {
		return null;
	}

	// ── Für DavController ───────────────────────────────────────────────────

	/**
	 * Absoluter Pfad zu $path im Ordner des Nutzers, null bei ungültigem Pfad
	 * (leer, „..“, Steuerzeichen).
	 */
	public function resolve(string $userId, string $path): ?string {
		$path = self::normalize($path);
		return $path === null ? null : $this->absolute($userId, $path);
	}

	/**
	 * Legt die fertig geschriebene Datei $temp unter $path ab (ersetzt eine
	 * vorhandene) und merkt sie in stored_files.
	 *
	 * @return array{id: int, created: bool}
	 */
	public function store(string $userId, string $path, string $temp, ?string $mime): array {
		$path = self::normalize($path) ?? throw new \InvalidArgumentException('Invalid path');
		$target = $this->absolute($userId, $path);
		$created = !file_exists($target);
		if (!@rename($temp, $target)) {
			@unlink($temp);
			throw new \RuntimeException('Could not store file');
		}
		@chmod($target, 0640);
		return ['id' => $this->remember($userId, $path, $mime), 'created' => $created];
	}

	/** Ordner für die Stücke eines Chunked Uploads (null bei ungültiger ID). */
	public function uploadDir(string $userId, string $transferId): ?string {
		if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $transferId) || $transferId[0] === '.') {
			return null;
		}
		return $this->root() . '/.uploads/' . self::userSegment($userId) . '/' . $transferId;
	}

	/**
	 * Belegter Platz eines Kontos in Bytes: seine Dateien (auch halbe
	 * Uploads) und die Stücke laufender Chunked Uploads, für das
	 * Kontingent in Controller\DavController.
	 */
	public function usedBytes(string $userId): int {
		$total = 0;
		foreach ([$this->root() . '/' . self::userSegment($userId), $this->root() . '/.uploads/' . self::userSegment($userId)] as $dir) {
			if (!is_dir($dir)) {
				continue;
			}
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
			foreach ($files as $file) {
				if ($file->isFile() && !$file->isLink()) {
					$total += (int) $file->getSize();
				}
			}
		}
		return $total;
	}

	public function previewDir(): string {
		return $this->root() . '/.previews';
	}

	/**
	 * Täglicher Lauf: Dateien ohne Eintrag (Eintrag gelöscht, Löschfrist,
	 * Konto gelöscht oder nie registriert) und liegen gebliebene Uploads, die
	 * älter als $maxAge Sekunden sind, sowie Zeilen ohne Datei.
	 *
	 * @return int Anzahl entfernter Dateien
	 */
	public function cleanup(int $maxAge = 86400): int {
		$cutoff = time() - $maxAge;
		$removed = 0;
		$rows = $this->db->fetchAll(
			'SELECT f.* FROM *PREFIX*stored_files f WHERE f.created_at < :cutoff AND NOT EXISTS ('
			. 'SELECT 1 FROM *PREFIX*merlin_articles a WHERE a.file_id = f.id AND a.user_id = f.user_id)',
			['cutoff' => $cutoff],
		);
		foreach ($rows as $row) {
			$file = $this->absolute((string) $row['user_id'], (string) $row['path']);
			if (is_file($file) && @unlink($file)) {
				$removed++;
			}
			$this->db->execute('DELETE FROM *PREFIX*stored_files WHERE id = :id', ['id' => (int) $row['id']]);
			foreach (glob($this->previewDir() . '/' . (int) $row['id'] . '-*') ?: [] as $preview) {
				@unlink($preview);
			}
		}
		// Halbe Uploads (DavController::tempFile), z. B. nach Verbindungsabbruch
		foreach (glob($this->root() . '/*/*/.upload-*.part') ?: [] as $part) {
			if ((int) @filemtime($part) < $cutoff) {
				@unlink($part);
			}
		}
		foreach (glob($this->root() . '/.uploads/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
			if ((int) @filemtime($dir) < $cutoff) {
				self::removeDir($dir);
			}
		}
		return $removed;
	}

	public static function removeDir(string $dir): void {
		// scandir statt glob(GLOB_BRACE): das gibt es mit musl (Alpine) nicht.
		foreach (@scandir($dir) ?: [] as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}
			$entry = $dir . '/' . $name;
			is_dir($entry) && !is_link($entry) ? self::removeDir($entry) : @unlink($entry);
		}
		@rmdir($dir);
	}

	public static function ensureDir(string $dir): void {
		if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
			throw new \RuntimeException("Could not create {$dir}");
		}
	}

	/** MIME-Typ nach Endung, sonst nach Inhalt. */
	public static function detectMime(string $file, string $name): string {
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (isset(self::MIME_BY_EXTENSION[$extension])) {
			return self::MIME_BY_EXTENSION[$extension];
		}
		if (function_exists('finfo_open') && is_file($file)) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mime = $finfo === false ? false : finfo_file($finfo, $file);
			if (is_string($mime) && $mime !== '') {
				return $mime;
			}
		}
		return 'application/octet-stream';
	}

	// ── Hilfen ──────────────────────────────────────────────────────────────

	private function remember(string $userId, string $path, ?string $mime): int {
		$absolute = $this->absolute($userId, $path);
		$generic = $mime === null || $mime === '' || $mime === 'application/octet-stream';
		$mime = $generic ? self::detectMime($absolute, basename($path)) : strtolower(trim(explode(';', $mime)[0]));
		$existing = $this->db->fetchValue('SELECT id FROM *PREFIX*stored_files WHERE user_id = :user_id AND path = :path', ['user_id' => $userId, 'path' => $path]);
		if ($existing !== null && $existing !== false) {
			$this->db->execute('UPDATE *PREFIX*stored_files SET mime = :mime WHERE id = :id', ['mime' => $mime, 'id' => (int) $existing]);
			return (int) $existing;
		}
		return $this->db->insert('stored_files', ['user_id' => $userId, 'path' => $path, 'mime' => $mime, 'created_at' => time()]);
	}

	/** @param array<string, mixed> $row */
	private function toFile(array $row): LocalStoredFile {
		return new LocalStoredFile((int) $row['id'], $this->absolute((string) $row['user_id'], (string) $row['path']), (string) $row['mime']);
	}

	private function absolute(string $userId, string $path): string {
		return $this->root() . '/' . self::userSegment($userId) . $path;
	}

	private static function userSegment(string $userId): string {
		if (!preg_match('/^[A-Za-z0-9._@-]+$/', $userId) || $userId[0] === '.') {
			throw new \InvalidArgumentException('Invalid user id');
		}
		return $userId;
	}

	/** „/Ordner/Datei“ ohne „.“, „..“, leere Teile und Steuerzeichen; null wenn ungültig. */
	private static function normalize(string $path): ?string {
		$parts = [];
		foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..' || preg_match('/[\x00-\x1f\x7f]/', $part) || $part[0] === '.') {
				return null;
			}
			$parts[] = $part;
		}
		return $parts === [] ? null : '/' . implode('/', $parts);
	}

	/** Freier Name wie Nextclouds getNonExistingName(): „Foto (2).jpg“. */
	private static function nonExistingName(string $dir, string $name): string {
		if (!file_exists($dir . '/' . $name)) {
			return $name;
		}
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
		$base = (string) preg_replace('/ \(\d+\)$/', '', $base);
		for ($i = 2; ; $i++) {
			$candidate = $base . ' (' . $i . ')' . ($extension === '' ? '' : '.' . $extension);
			if (!file_exists($dir . '/' . $candidate)) {
				return $candidate;
			}
		}
	}
}
