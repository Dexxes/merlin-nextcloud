<?php

declare(strict_types=1);

/**
 * Testharness für Service\FileMetadata (Metadaten unter Dateien aus
 * „Merlin Dateien“).
 *
 * Aufruf: php tools/test-file-metadata.php
 *
 * Die Beispieldateien in tools/fixtures/metadata/ sind mit Pillow (EXIF/GPS/
 * XMP), PHP iptcembed() (IPTC) und ffmpeg (ID3, MP4, MOV) erzeugt, doc.pdf
 * von Hand.
 */

require_once __DIR__ . '/../lib/Service/FileMetadata.php';

use OCA\Merlin\Service\FileMetadata;

$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
	echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
	if (!$ok) {
		$failed++;
	}
};

/** @return array<string, array<string, string>> Gruppentitel → Label → Wert */
$read = static function (string $name, string $mime, bool $seekable = true): array {
	$path = __DIR__ . '/fixtures/metadata/' . $name;
	if ($seekable) {
		$handle = fopen($path, 'rb');
	} else {
		// Nicht seekbarer Stream wie bei manchen externen Speichern.
		$handle = popen('cat ' . escapeshellarg($path), 'r');
	}
	$result = [];
	foreach (FileMetadata::extract($handle, (int) filesize($path), $mime) as $group) {
		foreach ($group['entries'] as $entry) {
			$result[$group['title']][$entry['label']] = $entry['value'];
		}
	}
	return $result;
};

echo "JPEG: EXIF, GPS, IPTC, XMP\n";
$photo = $read('photo.jpg', 'image/jpeg');
if (function_exists('exif_read_data')) {
	$check(($photo['EXIF']['Make'] ?? '') === 'Apple' && ($photo['EXIF']['Model'] ?? '') === 'iPhone 15 Pro', 'Kamera');
	$check(($photo['EXIF']['ExposureTime'] ?? '') === '1/125 s', 'Belichtungszeit als Bruch');
	$check(($photo['EXIF']['FNumber'] ?? '') === 'f/2.8', 'Blende');
	$check(($photo['EXIF']['DateTimeOriginal'] ?? '') === '2024:05:01 12:34:56', 'Aufnahmezeit unverändert');
	$check(($photo['GPS']['Position'] ?? '') === '52.520094, 13.408333', 'GPS als Dezimalgrad');
	$check(isset($photo['GPS']['GPSLatitude']), 'GPS-Rohwerte bleiben sichtbar');
} else {
	echo "  (ext-exif fehlt, EXIF übersprungen)\n";
}
$check(($photo['IPTC']['ObjectName'] ?? '') === 'Fernsehturm', 'IPTC ObjectName');
$check(($photo['IPTC']['CopyrightNotice'] ?? '') === '© Test', 'IPTC mit UTF-8');
$check(($photo['XMP']['dc:subject'] ?? '') === 'Berlin, Urlaub', 'XMP-Liste (rdf:Bag)');
$check(($photo['XMP']['dc:title'] ?? '') === 'Fernsehturm', 'XMP rdf:Alt mit xml:lang');
$check(($photo['XMP']['xmp:Rating'] ?? '') === '4', 'XMP-Attribut');

echo "MP3: ID3v2.4 und ID3v1\n";
$song = $read('song.mp3', 'audio/mpeg');
$check(($song['ID3v2.4']['Title (TIT2)'] ?? '') === 'Lied Ä', 'Titel mit Umlaut');
$check(($song['ID3v2.4']['Artist (TPE1)'] ?? '') === 'Künstler', 'Interpret');
$check(($song['ID3v2.4']['Track (TRCK)'] ?? '') === '3/12', 'Track');
$check(($song['ID3v2.4']['Genre (TCON)'] ?? '') === 'Rock', 'Genre');
$check(($song['ID3v1']['Album'] ?? '') === 'Album' && ($song['ID3v1']['Track'] ?? '') === '3', 'ID3v1 mit Track (v1.1)');
$check(($song['ID3v1']['Genre'] ?? '') === 'Rock', 'ID3v1-Genre aus der Liste');

echo "MP4/MOV\n";
$video = $read('video.mp4', 'video/mp4');
$check(($video['QuickTime / MP4']['Title (©nam)'] ?? '') === 'Video', 'iTunes-Titel');
$check(($video['QuickTime / MP4']['Creation date'] ?? '') === '2024-05-01 12:00:00 UTC', 'Erstellungszeit aus mvhd');
$check(($video['QuickTime / MP4']['Track 1 size'] ?? '') === '32 × 24', 'Bildgröße aus tkhd');
$movie = $read('movie.mov', 'video/quicktime');
$check(($movie['QuickTime / MP4']['com.apple.quicktime.make'] ?? '') === 'Apple', 'Apple-Metadaten (keys/mdta)');
$check(($movie['QuickTime / MP4']['location'] ?? '') === '+52.5200+013.4050/', 'Standort in mdta');
$udta = $read('udta.mov', 'video/quicktime');
$check(($udta['QuickTime / MP4']['Location (ISO 6709) (©xyz)'] ?? '') === '+52.5200+013.4050/', 'Standort in udta ©xyz');

echo "PDF\n";
$pdf = $read('doc.pdf', 'application/pdf');
$check(($pdf['PDF']['Version'] ?? '') === '1.7', 'Version');
$check(($pdf['PDF']['Title'] ?? '') === 'Hallo Welt', 'Titel als UTF-16-Hexstring');
$check(($pdf['PDF']['Author'] ?? '') === 'Max (M) Müller', 'Autor mit Klammern und Oktal-Escape');
$check(($pdf['PDF']['CreationDate'] ?? '') === '2024-05-01 12:34:56 +02:00', 'PDF-Datum');
$check(($pdf['PDF']['Pages'] ?? '') === '1', 'Seitenzahl');

echo "Nicht seekbarer Stream\n";
$song = $read('song.mp3', 'audio/mpeg', false);
$check(($song['ID3v2.4']['Title (TIT2)'] ?? '') === 'Lied Ä', 'ID3 aus dem Dateikopf');
$photo = $read('photo.jpg', 'image/jpeg', false);
$check(($photo['IPTC']['ObjectName'] ?? '') === 'Fernsehturm', 'IPTC aus dem Dateikopf');
if (function_exists('exif_read_data')) {
	$check(($photo['EXIF']['Make'] ?? '') === 'Apple', 'EXIF aus dem Dateikopf');
}

echo "Robustheit\n";
$handle = fopen('php://memory', 'w+b');
fwrite($handle, "ID3\x04\x00\x00\x7F\x7F\x7F\x7F" . str_repeat("\xFF", 100));
$check(FileMetadata::extract($handle, 110, 'audio/mpeg') === [], 'Kaputter ID3-Tag ergibt keine Einträge');
$handle = fopen('php://memory', 'w+b');
fwrite($handle, '<x:xmpmeta><!DOCTYPE x [<!ENTITY a "aaaa">]><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"></rdf:RDF></x:xmpmeta>');
$check(FileMetadata::extract($handle, 150, 'application/octet-stream') === [], 'XMP mit Entities wird nicht geparst');
$handle = fopen('php://memory', 'w+b');
fwrite($handle, "\x00\x00\x00\x18ftypisom\x00\x00\x00\x00isom\xFF\xFF\xFF\xFFmoov");
$check(FileMetadata::extract($handle, 32, 'video/mp4') !== null, 'Abgeschnittenes MP4 ohne Fehler');

echo $failed === 0 ? "\nAlle Tests bestanden.\n" : "\n$failed Test(s) fehlgeschlagen.\n";
exit($failed === 0 ? 0 : 1);
