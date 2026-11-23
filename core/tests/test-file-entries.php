<?php

declare(strict_types=1);

/**
 * Testharness für Service\FileEntryService (Datei-Einträge der Leseliste).
 *
 * Aufruf: php core/tests/test-file-entries.php
 *
 * Ohne Composer und ohne Nextcloud: die Ports UrlGenerator, InstanceSecret,
 * FilePreviews, StoredFile und Translator sind Stubs über den Dateien in
 * fixtures/metadata. Geprüft werden signierte Links, der Inhalt je Dateiart
 * (Bild, Video, Audio, PDF, sonstige Datei), erkannter Text, Metadaten und
 * das Ausliefern (Status, Header, Range, Vorschaubild, 404/416). stream()
 * beendet den Prozess, deshalb läuft jeder Stream-Fall in einem eigenen
 * PHP-Prozess (Aufruf dieses Skripts mit „stream …“).
 */

namespace Psr\Log {
	if (!class_exists(AbstractLogger::class)) {
		interface LoggerInterface {
			public function emergency($message, array $context = []);
			public function alert($message, array $context = []);
			public function critical($message, array $context = []);
			public function error($message, array $context = []);
			public function warning($message, array $context = []);
			public function notice($message, array $context = []);
			public function info($message, array $context = []);
			public function debug($message, array $context = []);
			public function log($level, $message, array $context = []);
		}
		abstract class AbstractLogger implements LoggerInterface {
			public function emergency($message, array $context = []) { $this->log('emergency', $message, $context); }
			public function alert($message, array $context = []) { $this->log('alert', $message, $context); }
			public function critical($message, array $context = []) { $this->log('critical', $message, $context); }
			public function error($message, array $context = []) { $this->log('error', $message, $context); }
			public function warning($message, array $context = []) { $this->log('warning', $message, $context); }
			public function notice($message, array $context = []) { $this->log('notice', $message, $context); }
			public function info($message, array $context = []) { $this->log('info', $message, $context); }
			public function debug($message, array $context = []) { $this->log('debug', $message, $context); }
		}
	}
}

namespace Merlin\Core\Service {
	// Header und Status landen in der CLI sonst im Nichts: hier mitschreiben
	// (unqualifizierte Aufrufe in FileEntryService finden zuerst diese).
	function header(string $header, bool $replace = true, int $code = 0): void {
		fwrite(STDERR, 'H ' . $header . "\n");
	}
	function http_response_code(int $code = 0): int|bool {
		fwrite(STDERR, 'S ' . $code . "\n");
		return true;
	}
}

namespace Merlin\FileEntriesTest {
	require_once __DIR__ . '/autoload.php';

	use Merlin\Core\Domain\FileEntry;
	use Merlin\Core\Domain\FilePreview;
	use Merlin\Core\Port\FilePreviews;
	use Merlin\Core\Port\InstanceSecret;
	use Merlin\Core\Port\StoredFile;
	use Merlin\Core\Port\Translator;
	use Merlin\Core\Port\UrlGenerator;
	use Merlin\Core\Service\FileEntryService;
	use Merlin\Core\Service\FileRules;

	final class Urls implements UrlGenerator {
		public function absoluteImageUrl(string $file): string {
			return 'https://cloud.test/img/' . $file;
		}
		public function imagePath(string $file): string {
			return '/img/' . $file;
		}
		public function fileContentUrl(array $params): string {
			return 'https://cloud.test/file?' . http_build_query($params);
		}
	}

	final class Secret implements InstanceSecret {
		public function get(): string {
			return 'geheim';
		}
	}

	final class Previews implements FilePreviews {
		/** @param array<string, bool> $available */
		public function __construct(private array $available) {
		}
		public function isAvailable(StoredFile $file): bool {
			if ($file->getName() === 'kaputt.jpg') {
				throw new \RuntimeException('Vorschau-Backend weg');
			}
			return $this->available[$file->getName()] ?? false;
		}
		public function preview(StoredFile $file, int $size): FilePreview {
			if ($file->getName() === 'video.mp4') {
				throw new \RuntimeException('kein Vorschaubild');
			}
			return new FilePreview('image/jpeg', strlen("JPEG$size"), static fn (): string => "JPEG$size");
		}
	}

	final class Fixture implements StoredFile {
		public function __construct(private string $name, private string $mime, private string $path, private int $id = 5) {
		}
		public function getId(): int { return $this->id; }
		public function getName(): string { return $this->name; }
		public function getMimetype(): string { return $this->mime; }
		public function getSize(): int { return (int) filesize($this->path); }
		public function getMTime(): int { return 1700000000; }
		public function open(string $mode) { return fopen($this->path, $mode); }
	}

	final class Brackets implements Translator {
		public function t(string $text, array $parameters = []): string {
			return '«' . vsprintf($text, $parameters) . '»';
		}
	}

	final class Log extends \Psr\Log\AbstractLogger {
		/** @var list<string> */
		public array $lines = [];
		public function log($level, $message, array $context = []): void {
			$this->lines[] = $level . ' ' . $message;
		}
	}

	$dir = __DIR__ . '/fixtures/metadata';
	$fixture = static function (string $name) use ($dir): Fixture {
		$mimes = ['photo.jpg' => 'image/jpeg', 'kaputt.jpg' => 'image/jpeg', 'doc.pdf' => 'application/pdf', 'song.mp3' => 'audio/mpeg', 'video.mp4' => 'video/mp4', 'notes.bin' => 'application/x-thing'];
		$path = $dir . '/' . ($name === 'kaputt.jpg' ? 'photo.jpg' : $name);
		if (!is_file($path)) {
			$path = $dir . '/doc.pdf';
		}
		return new Fixture($name, $mimes[$name], $path);
	};
	$log = new Log();
	$service = new FileEntryService(new Urls(), new Secret(), new Previews(['photo.jpg' => true, 'video.mp4' => true]), $log);

	// ── Kindprozess: ein Stream-Fall ────────────────────────────────────────
	if (($argv[1] ?? '') === 'stream') {
		[, , $name, $size, $download, $range] = $argv;
		if ($range !== '-') {
			$_SERVER['HTTP_RANGE'] = $range;
		}
		$service->stream($name === 'gone' ? null : $fixture($name), $size === '-' ? null : (int) $size, $download === '1');
	}

	$failed = 0;
	$check = static function (bool $ok, string $label) use (&$failed): void {
		echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
		if (!$ok) {
			$failed++;
		}
	};
	$l = new Brackets();
	$entry = new FileEntry(41, 5, 'anna', null);

	echo "Links\n";
	$url = $service->fileUrl($entry);
	parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
	$check($q['id'] === '41' && $q['t'] === FileRules::sign('geheim|merlin-file-links', 41, 5, 'anna'), 'Token aus Instanz-Geheimnis, Eintrag, Datei, Besitzer');
	$check(!isset($q['size']) && !isset($q['download']), 'Originaldatei ohne size/download');
	$check(str_contains($service->fileUrl($entry, 512), 'size=512') && str_contains($service->fileUrl($entry, null, true), 'download=1'), 'size und download im Link');
	$check($service->verifyToken($entry, $q['t']), 'Eigenes Token gültig');
	$check(!$service->verifyToken($entry, 'x' . $q['t']) && !$service->verifyToken($entry, ''), 'Fremdes/leeres Token ungültig');
	$check(!$service->verifyToken(new FileEntry(41, null, 'anna'), $q['t']), 'Eintrag ohne Datei: nie gültig');
	$check(!$service->verifyToken(new FileEntry(41, 5, 'bert'), $q['t']), 'Anderer Besitzer: ungültig');

	echo "Inhalt je Dateiart\n";
	$photo = $service->render(new FileEntry(41, 5, 'anna', "Erster\nAbsatz\n\nZweiter <b>"), $fixture('photo.jpg'), $l);
	$check($photo['imageUrl'] === $service->fileUrl($entry, FileEntryService::THUMBNAIL_SIZE), 'Bild: Vorschaubild 512');
	$check(str_starts_with($photo['content'], '<figure><img src="' . htmlspecialchars($service->fileUrl($entry, FileEntryService::READER_IMAGE_SIZE), ENT_QUOTES | ENT_HTML5) . '" alt="photo.jpg"></figure>'), 'Bild: Figure mit Reader-Vorschau 2048');
	$check(str_contains($photo['content'], "<section class=\"merlin-file-text\"><h2>«Recognized text»</h2><p>Erster<br>\nAbsatz</p><p>Zweiter &lt;b&gt;</p></section>"), 'Erkannter Text: Absätze, Umbrüche, maskiert');
	$check(str_contains($photo['content'], '<section class="merlin-file-metadata" data-download-src="' . htmlspecialchars($service->fileUrl($entry, null, true), ENT_QUOTES | ENT_HTML5) . '"><h2>«Metadata»</h2>'), 'Metadaten mit Download-Link');
	$check(str_contains($photo['content'], '<tr><th>«Modified»</th><td>2023-11-14 22:13:20 UTC</td></tr>'), 'Änderungszeit in UTC');
	$size = filesize($dir . '/photo.jpg');
	$check(str_contains($photo['content'], '<tr><th>«Size»</th><td>' . FileRules::humanSize($size) . ' (' . number_format($size, 0, ',', '.') . ' B)</td></tr>'), 'Größe lesbar und in Bytes');

	$noPreview = $service->render($entry, $fixture('kaputt.jpg'), $l);
	$check($noPreview['imageUrl'] === '' && str_starts_with($noPreview['content'], '<figure><img src="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5) . '"'), 'Vorschau-Fehler: kein Vorschaubild, Bild als Original');
	$check(!str_contains($noPreview['content'], 'merlin-file-text'), 'Ohne Text kein Text-Abschnitt');

	$video = $service->render($entry, $fixture('video.mp4'), $l);
	$check(str_starts_with($video['content'], '<figure><img') && str_contains($video['content'], 'data-media-delivery="file"'), 'Video: Poster und Medien-Marker (Auslieferung file)');
	$song = $service->render($entry, $fixture('song.mp3'), $l);
	$check($song['imageUrl'] === '' && !str_starts_with($song['content'], '<figure>') && str_contains($song['content'], 'data-media-delivery="file"'), 'Audio ohne Vorschau: nur Medien-Marker');
	$pdf = $service->render($entry, $fixture('doc.pdf'), $l);
	$check(str_contains($pdf['content'], 'class="merlin-pdf" data-pdf-src="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5) . '"'), 'PDF: .merlin-pdf-Marker');
	$other = $service->render($entry, $fixture('notes.bin'), $l);
	$check(str_starts_with($other['content'], '<p><a class="merlin-file-download" href="' . htmlspecialchars($service->fileUrl($entry, null, true), ENT_QUOTES | ENT_HTML5) . '">«Download file»</a> · notes.bin · '), 'Sonstige Datei: Download-Link mit Name und Größe');

	echo "humanSize (wie Nextclouds humanFileSize)\n";
	$check(FileRules::humanSize(-1) === '?' && FileRules::humanSize(0) === '0 B' && FileRules::humanSize(1023) === '1023 B', 'Bytes');
	$check(FileRules::humanSize(1646) === '2 KB' && FileRules::humanSize(1024 * 1023) === '1023 KB', 'KB ganzzahlig');
	$check(FileRules::humanSize(1024 * 1700) === '1.7 MB' && FileRules::humanSize(3 * 1024 ** 3) === '3 GB', 'MB/GB mit einer Stelle');
	$check(FileRules::humanSize(1024 ** 4 * 2) === '2 TB' && FileRules::humanSize(1024 ** 5 * 5) === '5 PB', 'TB/PB');

	echo "Ausliefern\n";
	$run = static function (string ...$args): array {
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' stream ' . implode(' ', array_map('escapeshellarg', $args));
		$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$body = stream_get_contents($pipes[1]);
		$meta = stream_get_contents($pipes[2]);
		proc_close($proc);
		return [$body, $meta];
	};
	$video = file_get_contents($dir . '/video.mp4');
	$total = strlen($video);
	[$body, $meta] = $run('photo.jpg', '-', '0', '-');
	$check($body === file_get_contents($dir . '/photo.jpg') && str_starts_with($meta, "S 200\nH Content-Type: image/jpeg\nH Content-Disposition: inline; filename*=UTF-8''photo.jpg\n"), 'Bild inline, ganze Datei');
	$check(str_contains($meta, "H X-Content-Type-Options: nosniff\nH Content-Security-Policy: sandbox; default-src 'none'\nH Cache-Control: private, max-age=86400\nH X-Accel-Buffering: no"), 'Härtungs-Header');
	[$body, $meta] = $run('photo.jpg', '512', '0', '-');
	$check($body === 'JPEG512' && str_contains($meta, "H Content-Type: image/jpeg\nH Content-Length: 7\n"), 'Vorschaubild 512');
	[$body] = $run('photo.jpg', '9999', '0', '-');
	$check($body === 'JPEG2048', 'Vorschaubild höchstens 2048');
	[$body, $meta] = $run('photo.jpg', '512', '1', '-');
	$check(strlen($body) === filesize($dir . '/photo.jpg') && str_contains($meta, 'Content-Disposition: attachment'), 'download schlägt size: Originaldatei als Anhang');
	[$body, $meta] = $run('video.mp4', '512', '0', '-');
	$check($body === '{"error":"No preview"}' && str_starts_with($meta, "S 404\n") && $log->lines === [], 'Kein Vorschaubild: 404');
	[$body, $meta] = $run('video.mp4', '-', '0', 'bytes=100-199');
	$check($body === substr($video, 100, 100) && str_contains($meta, "S 206\n") && str_contains($meta, 'Content-Range: bytes 100-199/' . $total) && str_contains($meta, 'Content-Length: 100'), 'Range: 206 mit Ausschnitt');
	[$body, $meta] = $run('video.mp4', '-', '0', 'bytes=-50');
	$check($body === substr($video, -50), 'Suffix-Range');
	[$body, $meta] = $run('video.mp4', '-', '0', 'bytes=999999999-');
	$check($body === '' && $meta === "S 416\nH Content-Range: bytes */$total\n", 'Range hinter dem Ende: 416');
	[$body, $meta] = $run('notes.bin', '-', '0', '-');
	$check(str_contains($meta, 'Content-Type: application/octet-stream') && str_contains($meta, 'attachment'), 'Unbekannter Typ nur als Download');
	[$body, $meta] = $run('gone', '-', '0', '-');
	$check($body === '{"error":"File not found"}' && $meta === "S 404\nH Content-Type: application/json\n", 'Datei weg: 404');

	echo $failed === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$failed Prüfung(en) fehlgeschlagen.\n";
	exit($failed === 0 ? 0 : 1);
}
