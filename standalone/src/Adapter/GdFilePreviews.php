<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Domain\FilePreview;
use Merlin\Core\Port\FilePreviews;
use Merlin\Core\Port\StoredFile;

/**
 * FilePreviews-Port des Standalone-Servers: JPEG-Vorschaubilder für JPEG,
 * PNG, GIF und WebP mit der GD-Erweiterung (in Nextcloud: IPreview, das
 * auch HEIC, Videos und PDFs kann). Ohne GD gibt es keine Vorschaubilder;
 * die Einträge zeigen dann ein Symbol statt des Bildes.
 *
 * Fertige Bilder liegen in LocalFileStorage::previewDir() als
 * {fileId}-{size}-{mtime}.jpg und werden mit der Datei weggeräumt.
 */
final class GdFilePreviews implements FilePreviews {
	/** Größere Dateien werden nicht in den Speicher geladen. */
	public const MAX_SOURCE_BYTES = 40 * 1024 * 1024;
	/** Und keine Bilder mit mehr Pixeln (ca. 50 Megapixel). */
	public const MAX_PIXELS = 50_000_000;
	public const QUALITY = 85;

	private const SUPPORTED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

	public function __construct(private LocalFileStorage $storage) {
	}

	public function isAvailable(StoredFile $file): bool {
		if (!$file instanceof LocalStoredFile || !function_exists('imagecreatetruecolor')) {
			return false;
		}
		if (!in_array($file->getMimetype(), self::SUPPORTED, true) || $file->getSize() > self::MAX_SOURCE_BYTES) {
			return false;
		}
		if ($file->getMimetype() === 'image/webp' && !function_exists('imagecreatefromwebp')) {
			return false;
		}
		$info = @getimagesize($file->absolutePath());
		return is_array($info) && $info[0] > 0 && $info[1] > 0 && $info[0] * $info[1] <= self::MAX_PIXELS;
	}

	public function preview(StoredFile $file, int $size): FilePreview {
		if (!$this->isAvailable($file)) {
			throw new \RuntimeException('No preview available');
		}
		/** @var LocalStoredFile $file */
		$size = max(16, min($size, 4096));
		$dir = $this->storage->previewDir();
		$cached = $dir . '/' . $file->getId() . '-' . $size . '-' . $file->getMTime() . '.jpg';
		if (!is_file($cached)) {
			LocalFileStorage::ensureDir($dir);
			// Ältere Fassungen derselben Größe (Datei ersetzt) wegräumen.
			foreach (glob($dir . '/' . $file->getId() . '-' . $size . '-*.jpg') ?: [] as $old) {
				@unlink($old);
			}
			$this->render($file, $size, $cached);
		}
		return new FilePreview('image/jpeg', (int) filesize($cached), static fn (): string => (string) file_get_contents($cached));
	}

	private function render(LocalStoredFile $file, int $size, string $target): void {
		$path = $file->absolutePath();
		$source = match ($file->getMimetype()) {
			'image/jpeg' => @imagecreatefromjpeg($path),
			'image/png' => @imagecreatefrompng($path),
			'image/gif' => @imagecreatefromgif($path),
			'image/webp' => @imagecreatefromwebp($path),
			default => false,
		};
		if ($source === false) {
			throw new \RuntimeException('Could not read image');
		}
		if ($file->getMimetype() === 'image/jpeg') {
			$source = self::applyOrientation($source, $path);
		}
		$width = imagesx($source);
		$height = imagesy($source);
		$scale = min(1, $size / max($width, $height));
		$newWidth = max(1, (int) round($width * $scale));
		$newHeight = max(1, (int) round($height * $scale));
		$preview = imagecreatetruecolor($newWidth, $newHeight);
		// Transparenz auf Weiß (JPEG kennt keinen Alphakanal).
		imagefill($preview, 0, 0, (int) imagecolorallocate($preview, 255, 255, 255));
		imagecopyresampled($preview, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
		$temp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
		$ok = imagejpeg($preview, $temp, self::QUALITY);
		if (!$ok || !@rename($temp, $target)) {
			@unlink($temp);
			throw new \RuntimeException('Could not write preview');
		}
	}

	/** Handyfotos stehen oft per EXIF-Orientation „auf der Seite“. */
	private static function applyOrientation(\GdImage $image, string $path): \GdImage {
		if (!function_exists('exif_read_data')) {
			return $image;
		}
		$exif = @exif_read_data($path);
		$orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
		$rotated = match ($orientation) {
			3, 4 => imagerotate($image, 180, 0),
			5, 6 => imagerotate($image, -90, 0),
			7, 8 => imagerotate($image, 90, 0),
			default => $image,
		};
		if ($rotated === false) {
			return $image;
		}
		if (in_array($orientation, [2, 4, 5, 7], true)) {
			imageflip($rotated, IMG_FLIP_HORIZONTAL);
		}
		return $rotated;
	}
}
