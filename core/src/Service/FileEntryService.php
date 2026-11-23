<?php

declare(strict_types=1);

namespace Merlin\Core\Service;

use Merlin\Core\Domain\FileEntry;
use Merlin\Core\Extraction\ContentExtractorService;
use Merlin\Core\Extraction\Media\MediaResolverService;
use Merlin\Core\Extraction\Media\MediaResult;
use Merlin\Core\Port\FilePreviews;
use Merlin\Core\Port\InstanceSecret;
use Merlin\Core\Port\StoredFile;
use Merlin\Core\Port\Translator;
use Merlin\Core\Port\UrlGenerator;
use Psr\Log\LoggerInterface;

/**
 * Datei-Einträge der Leseliste: signierte Links, Inhalt des Eintrags und
 * Auslieferung der Datei.
 *
 * Datei-Links: /api/articles/{id}/file?t=… ist ohne Login abrufbar, das Token
 * (FileRules::sign) bindet ihn an Eintrag, Datei und Besitzer. So funktionieren
 * dieselben Links in der Web-Oberfläche, im iOS-Reader (Bild-Cache, Player,
 * PDF-Cache laden ohne Zugangsdaten) und hinter öffentlichen Share-Links.
 * Löschen des Eintrags macht die Links ungültig.
 *
 * Ordner, Upload-Ziel und das Anlegen des Eintrags hängen am Speicher der
 * Plattform (Port\FileStorage); den Ablauf steuert Service\FileEntries.
 */
class FileEntryService {
	/** Kantenlänge der Vorschaubilder: Listen/Karten bzw. Bild im Reader. */
	public const THUMBNAIL_SIZE = 512;
	public const READER_IMAGE_SIZE = 2048;

	public function __construct(
		private UrlGenerator $urlGenerator,
		private InstanceSecret $instanceSecret,
		private FilePreviews $previews,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Signierter, absoluter Link auf die Datei eines Eintrags.
	 *
	 * @param int|null $size Vorschaubild mit dieser Kantenlänge statt der Originaldatei
	 */
	public function fileUrl(FileEntry $entry, ?int $size = null, bool $download = false): string {
		$params = [
			'id' => $entry->articleId,
			't'  => FileRules::sign($this->secret(), $entry->articleId, (int) $entry->fileId, $entry->userId),
		];
		if ($size !== null) {
			$params['size'] = $size;
		}
		if ($download) {
			$params['download'] = 1;
		}
		return $this->urlGenerator->fileContentUrl($params);
	}

	public function verifyToken(FileEntry $entry, string $token): bool {
		return $entry->fileId !== null
			&& FileRules::verify($this->secret(), $entry->articleId, $entry->fileId, $entry->userId, $token);
	}

	/**
	 * Vorschaubild-Link (leer ohne Vorschaubild) und HTML-Inhalt des Eintrags:
	 * die Datei, der erkannte Text und die Metadaten.
	 *
	 * @return array{imageUrl: string, content: string}
	 */
	public function render(FileEntry $entry, StoredFile $file, Translator $l): array {
		$name = $file->getName();
		$hasPreview = $this->hasPreview($file);
		return [
			'imageUrl' => $hasPreview ? $this->fileUrl($entry, self::THUMBNAIL_SIZE) : '',
			'content'  => $this->buildContent($entry, FileRules::kindFor($file->getMimetype()), $name, FileRules::humanSize($file->getSize()), $hasPreview, $l)
				. $this->textHtml($entry, $l)
				. $this->metadataHtml($entry, $file, $l),
		];
	}

	/**
	 * Gibt die Datei (oder ein Vorschaubild) aus und beendet den Prozess wie
	 * PdfProxyService/TtsStreamService per exit(), damit das Framework keine
	 * eigenen Bytes anhängt. Unterstützt einfache Range-Anfragen
	 * (Video-Spulen, pdf.js). $file null = Datei nicht mehr da (404).
	 */
	public function stream(?StoredFile $file, ?int $size = null, bool $download = false): never {
		if ($file === null) {
			$this->fail(404, 'File not found');
		}

		if ($size !== null && $size > 0 && !$download) {
			$this->streamPreview($file, min($size, self::READER_IMAGE_SIZE));
		}

		$mime = $file->getMimetype();
		$inline = !$download && FileRules::isInlineMime($mime);
		$total = $file->getSize();
		$range = FileRules::parseRange($_SERVER['HTTP_RANGE'] ?? null, $total);
		if ($range === false) {
			http_response_code(416);
			header('Content-Range: bytes */' . $total);
			exit();
		}

		$handle = $file->open('rb');
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
		header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode($file->getName()));
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

	// ── Inhalt des Eintrags ─────────────────────────────────────────────────

	/**
	 * HTML des Eintrags: dieselben Marker wie bei Web-Artikeln, damit Web-Reader,
	 * Share-Ansicht und iOS die Datei ohne Sonderweg anzeigen (Bild als Figure,
	 * Audio/Video als Medien-Marker mit Auslieferung "file", PDF als .merlin-pdf).
	 */
	private function buildContent(FileEntry $entry, string $kind, string $name, string $size, bool $hasPreview, Translator $l): string {
		$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$fileUrl = $this->fileUrl($entry);
		$poster = $hasPreview
			? '<figure><img src="' . $esc($this->fileUrl($entry, self::READER_IMAGE_SIZE)) . '" alt="' . $esc($name) . '"></figure>'
			: '';

		switch ($kind) {
			case FileRules::KIND_IMAGE:
				// Vorschaubild (JPEG, auch für HEIC), sonst die Originaldatei.
				$src = $hasPreview ? $this->fileUrl($entry, self::READER_IMAGE_SIZE) : $fileUrl;
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
				return '<p><a class="merlin-file-download" href="' . $esc($this->fileUrl($entry, null, true)) . '">'
					. $esc($l->t('Download file')) . '</a> · ' . $esc($name) . ' · ' . $esc($size) . '</p>';
		}
	}

	/**
	 * Abschnitt „Metadaten“ unter der Datei: Dateiangaben und alle
	 * eingebetteten Metadaten (FileMetadata). Jede Gruppe als aufgeklappte
	 * <details> mit einer Tabelle, damit Web-Reader, Share-Ansicht und iOS sie
	 * ohne eigenen Code anzeigen (iOS liest den Abschnitt bei PDFs zusätzlich
	 * nativ aus, siehe FileMetadataSection.swift).
	 *
	 * data-download-src: signierter Download-Link der Originaldatei, mit dem
	 * iOS beim Teilen die Datei selbst statt des Links weitergibt.
	 */
	private function metadataHtml(FileEntry $entry, StoredFile $file, Translator $l): string {
		$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$size = $file->getSize();
		$groups = [[
			'key' => 'file',
			'title' => $l->t('File'),
			'entries' => array_values(array_filter([
				['label' => $l->t('Name'), 'value' => $file->getName()],
				['label' => $l->t('Type'), 'value' => $file->getMimetype()],
				['label' => $l->t('Size'), 'value' => FileRules::humanSize($size) . ' (' . number_format($size, 0, ',', '.') . ' B)'],
				['label' => $l->t('Modified'), 'value' => $file->getMTime() > 0 ? gmdate('Y-m-d H:i:s', $file->getMTime()) . ' UTC' : ''],
			], static fn(array $e): bool => $e['value'] !== '')),
		]];

		$handle = null;
		try {
			$handle = $file->open('r');
			if (is_resource($handle)) {
				array_push($groups, ...FileMetadata::extract($handle, $size, $file->getMimetype()));
			}
		} catch (\Throwable $e) {
			// Metadaten sind Beiwerk: ohne sie wird der Eintrag trotzdem angelegt.
			$this->logger->info('Merlin: could not read file metadata', ['exception' => $e]);
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}

		$html = '<section class="merlin-file-metadata" data-download-src="' . $esc($this->fileUrl($entry, null, true)) . '"><h2>' . $esc($l->t('Metadata')) . '</h2>';
		foreach ($groups as $group) {
			$html .= '<details open data-group="' . $esc($group['key']) . '"><summary>' . $esc($group['title']) . '</summary><table><tbody>';
			foreach ($group['entries'] as $item) {
				$value = $esc($item['value']);
				// GPS-Position: Link auf die Karte.
				if ($group['key'] === 'gps' && $item['label'] === 'Position'
					&& preg_match('/^(-?\d+\.\d+), (-?\d+\.\d+)$/', $item['value'], $m) === 1) {
					$value = '<a href="' . $esc('https://www.openstreetmap.org/?mlat=' . $m[1] . '&mlon=' . $m[2] . '#map=16/' . $m[1] . '/' . $m[2])
						. '" target="_blank" rel="noopener noreferrer">' . $value . '</a>';
				}
				$html .= '<tr><th>' . $esc($item['label']) . '</th><td>' . $value . '</td></tr>';
			}
			$html .= '</tbody></table></details>';
		}
		return $html . '</section>';
	}

	/**
	 * Abschnitt „Erkannter Text“ (OCR der iOS-Share-Extension) zwischen Datei
	 * und Metadaten; leer ohne Text.
	 */
	private function textHtml(FileEntry $entry, Translator $l): string {
		$text = (string) $entry->fileText;
		if ($text === '') {
			return '';
		}
		$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$paragraphs = preg_split('/\n\s*\n/u', $text) ?: [$text];
		$html = '<section class="merlin-file-text"><h2>' . $esc($l->t('Recognized text')) . '</h2>';
		foreach ($paragraphs as $paragraph) {
			$paragraph = trim($paragraph);
			if ($paragraph !== '') {
				$html .= '<p>' . nl2br($esc($paragraph), false) . '</p>';
			}
		}
		return $html . '</section>';
	}

	private function hasPreview(StoredFile $file): bool {
		try {
			return $this->previews->isAvailable($file);
		} catch (\Throwable) {
			return false;
		}
	}

	private function streamPreview(StoredFile $file, int $size): never {
		try {
			$preview = $this->previews->preview($file, $size);
		} catch (\Throwable $e) {
			$this->logger->debug('Merlin: no preview for file entry', ['exception' => $e]);
			$this->fail(404, 'No preview');
		}
		$this->prepareOutput();
		http_response_code(200);
		header('Content-Type: ' . $preview->mimeType);
		header('Content-Length: ' . $preview->size);
		$this->hardeningHeaders();
		echo $preview->content();
		exit();
	}

	// ── Hilfen ──────────────────────────────────────────────────────────────

	private function secret(): string {
		return $this->instanceSecret->get() . '|merlin-file-links';
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
