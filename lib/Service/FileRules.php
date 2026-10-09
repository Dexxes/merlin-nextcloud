<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

/**
 * Reine Regeln für Dateien in „Merlin Dateien“ (Upload vom Handy, Datei-Einträge
 * in der Leseliste): Dateiart, Kategorie, Dateinamen, signierte Links, Range.
 *
 * Frei von Nextcloud-Abhängigkeiten, damit tools/test-file-rules.php sie ohne
 * Server prüfen kann (wie TagTree, RetentionPolicy). Die Nextcloud-Seite
 * (Ordner, Vorschaubilder, Streamen) steht in MerlinFileService.
 */
class FileRules {
	public const KIND_IMAGE = 'image';
	public const KIND_VIDEO = 'video';
	public const KIND_AUDIO = 'audio';
	public const KIND_PDF   = 'pdf';
	public const KIND_OTHER = 'other';
	public const KINDS = [self::KIND_IMAGE, self::KIND_VIDEO, self::KIND_AUDIO, self::KIND_PDF, self::KIND_OTHER];

	/** Kategorie des Datei-Eintrags je Dateiart. Video/Audio/PDF wie bei Web-Artikeln, damit Filter und Reader greifen. */
	public const CATEGORIES = [
		self::KIND_IMAGE => 'Image',
		self::KIND_VIDEO => 'Video',
		self::KIND_AUDIO => 'Audio',
		self::KIND_PDF   => 'PDF',
		self::KIND_OTHER => 'File',
	];

	/**
	 * Typen, die der Datei-Endpunkt inline ausliefert. Alles andere (HTML, SVG,
	 * XML, Skripte …) kommt nur als Download, damit nichts im Nextcloud-Origin
	 * ausgeführt wird.
	 */
	private const INLINE_MIME_PATTERNS = [
		'#^image/(jpeg|png|gif|webp|heic|heif|avif|bmp|tiff)$#',
		'#^video/[a-z0-9.+-]+$#',
		'#^audio/[a-z0-9.+-]+$#',
		'#^application/pdf$#',
	];

	private const MAX_NAME_LENGTH = 200;

	public static function kindFor(string $mime): string {
		$mime = strtolower(trim($mime));
		if ($mime === 'application/pdf') {
			return self::KIND_PDF;
		}
		// SVG ist XML mit möglichem Skript: wie eine sonstige Datei behandeln.
		if ($mime === 'image/svg+xml') {
			return self::KIND_OTHER;
		}
		foreach ([self::KIND_IMAGE, self::KIND_VIDEO, self::KIND_AUDIO] as $kind) {
			if (str_starts_with($mime, $kind . '/')) {
				return $kind;
			}
		}
		return self::KIND_OTHER;
	}

	public static function categoryFor(string $kind): string {
		return self::CATEGORIES[$kind] ?? self::CATEGORIES[self::KIND_OTHER];
	}

	public static function isInlineMime(string $mime): bool {
		$mime = strtolower(trim($mime));
		foreach (self::INLINE_MIME_PATTERNS as $pattern) {
			if (preg_match($pattern, $mime) === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Dateiname aus dem Client für den Nextcloud-Ordner: keine Pfadtrenner,
	 * keine Steuerzeichen, kein führender Punkt (versteckte Datei), höchstens
	 * 200 Zeichen mit erhaltener Endung. Leer → $fallback.
	 */
	public static function sanitizeName(string $name, string $fallback): string {
		$name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name);
		$name = trim((string) preg_replace('/\s+/u', ' ', $name));
		$name = ltrim($name, '. ');
		if ($name === '') {
			return $fallback;
		}
		if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			$ext = pathinfo($name, PATHINFO_EXTENSION);
			$ext = ($ext !== '' && mb_strlen($ext) <= 10) ? '.' . $ext : '';
			$name = rtrim(mb_substr($name, 0, self::MAX_NAME_LENGTH - mb_strlen($ext))) . $ext;
		}
		return $name;
	}

	/**
	 * Signatur des Datei-Links eines Eintrags. Gebunden an Eintrag, Datei und
	 * Besitzer: Löschen des Eintrags macht alle Links ungültig, ein Link öffnet
	 * nie eine andere Datei.
	 */
	public static function sign(string $secret, int $articleId, int $fileId, string $userId): string {
		return substr(hash_hmac('sha256', 'merlin-file|' . $articleId . '|' . $fileId . '|' . $userId, $secret), 0, 40);
	}

	public static function verify(string $secret, int $articleId, int $fileId, string $userId, string $token): bool {
		return $token !== '' && hash_equals(self::sign($secret, $articleId, $fileId, $userId), $token);
	}

	/**
	 * Wertet einen `Range: bytes=…`-Header gegen eine Datei der Größe $size aus.
	 *
	 * @return array{int, int}|null|false [start, end] (inklusive), null = ganze
	 *         Datei (kein/unbrauchbarer Header, Mehrfach-Ranges), false = nicht
	 *         erfüllbar (416)
	 */
	public static function parseRange(?string $header, int $size): array|null|false {
		if ($header === null || preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) !== 1) {
			return null;
		}
		[, $from, $to] = $m;
		if ($from === '' && $to === '') {
			return null;
		}
		if ($size <= 0) {
			return false;
		}
		if ($from === '') {
			// Suffix: die letzten N Bytes.
			$length = (int) $to;
			if ($length <= 0) {
				return false;
			}
			return [max(0, $size - $length), $size - 1];
		}
		$start = (int) $from;
		$end   = $to === '' ? $size - 1 : min((int) $to, $size - 1);
		if ($start >= $size || $end < $start) {
			return false;
		}
		return [$start, $end];
	}

	/** Pfad unter /remote.php/dav, Segmente einzeln kodiert. */
	public static function encodePath(string $path): string {
		$segments = array_filter(explode('/', $path), static fn(string $s): bool => $s !== '');
		return '/' . implode('/', array_map('rawurlencode', $segments));
	}
}
