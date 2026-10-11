<?php

declare(strict_types=1);

/**
 * Testharness für Service\FileRules (Dateien in „Merlin Dateien“).
 *
 * Aufruf: php tools/test-file-rules.php
 *
 * Ohne Composer/Nextcloud: FileRules ist bewusst frei von
 * Nextcloud-Abhängigkeiten. Geprüft werden Dateiart/Kategorie, Inline-Typen,
 * Dateinamen, signierte Links, Range-Header und WebDAV-Pfade.
 */

require_once __DIR__ . '/../lib/Service/FileRules.php';

use OCA\Merlin\Service\FileRules;

$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
	echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
	if (!$ok) {
		$failed++;
	}
};

echo "kindFor / categoryFor\n";
$check(FileRules::kindFor('image/heic') === 'image', 'HEIC ist ein Bild');
$check(FileRules::kindFor('IMAGE/JPEG') === 'image', 'Groß-/Kleinschreibung egal');
$check(FileRules::kindFor('image/svg+xml') === 'other', 'SVG gilt als sonstige Datei');
$check(FileRules::kindFor('video/quicktime') === 'video', 'MOV ist ein Video');
$check(FileRules::kindFor('audio/x-m4a') === 'audio', 'M4A ist Audio');
$check(FileRules::kindFor('application/pdf') === 'pdf', 'PDF');
$check(FileRules::kindFor('application/zip') === 'other', 'ZIP ist sonstige Datei');
$check(FileRules::kindFor('') === 'other', 'Leerer Typ ist sonstige Datei');
$check(FileRules::categoryFor('video') === 'Video' && FileRules::categoryFor('pdf') === 'PDF', 'Kategorien wie bei Web-Artikeln');
$check(FileRules::categoryFor('image') === 'Image' && FileRules::categoryFor('other') === 'File', 'Bild und Datei');
$check(FileRules::categoryFor('quatsch') === 'File', 'Unbekannte Art wird Datei');

echo "isInlineMime\n";
$check(FileRules::isInlineMime('image/jpeg') && FileRules::isInlineMime('video/mp4') && FileRules::isInlineMime('application/pdf'), 'Medien und PDF inline');
$check(!FileRules::isInlineMime('text/html'), 'HTML nie inline');
$check(!FileRules::isInlineMime('image/svg+xml'), 'SVG nie inline');
$check(!FileRules::isInlineMime('application/octet-stream'), 'Unbekanntes nie inline');

echo "sanitizeName\n";
$check(FileRules::sanitizeName('IMG_0001.HEIC', 'x') === 'IMG_0001.HEIC', 'Normaler Name bleibt');
$check(FileRules::sanitizeName('../../etc/passwd', 'x') === 'etc passwd', 'Keine Pfadtrenner, kein führender Punkt');
$check(FileRules::sanitizeName(".hidden", 'x') === 'hidden', 'Keine versteckte Datei');
$check(FileRules::sanitizeName("a\x00b\nc.pdf", 'x') === 'a b c.pdf', 'Steuerzeichen entfernt');
$check(FileRules::sanitizeName('  ', 'Fallback') === 'Fallback', 'Leer → Fallback');
$long = FileRules::sanitizeName(str_repeat('ä', 300) . '.mp4', 'x');
$check(mb_strlen($long) === 200 && str_ends_with($long, '.mp4'), 'Lange Namen gekürzt, Endung bleibt');

echo "sign / verify\n";
$token = FileRules::sign('s', 1, 2, 'anna');
$check(strlen($token) === 40, 'Token hat 40 Zeichen');
$check(FileRules::verify('s', 1, 2, 'anna', $token), 'Gültiges Token');
$check(!FileRules::verify('s', 3, 2, 'anna', $token), 'Anderer Eintrag');
$check(!FileRules::verify('s', 1, 9, 'anna', $token), 'Andere Datei');
$check(!FileRules::verify('s', 1, 2, 'bert', $token), 'Anderer Besitzer');
$check(!FileRules::verify('t', 1, 2, 'anna', $token), 'Anderes Geheimnis');
$check(!FileRules::verify('s', 1, 2, 'anna', ''), 'Leeres Token');

echo "parseRange\n";
$check(FileRules::parseRange(null, 100) === null, 'Kein Header → ganze Datei');
$check(FileRules::parseRange('bytes=0-9', 100) === [0, 9], 'Anfang');
$check(FileRules::parseRange('bytes=90-', 100) === [90, 99], 'Offenes Ende');
$check(FileRules::parseRange('bytes=-10', 100) === [90, 99], 'Suffix');
$check(FileRules::parseRange('bytes=50-500', 100) === [50, 99], 'Ende über Dateigröße wird gekappt');
$check(FileRules::parseRange('bytes=100-', 100) === false, 'Start hinter dem Ende → 416');
$check(FileRules::parseRange('bytes=5-2', 100) === false, 'Ende vor Start → 416');
$check(FileRules::parseRange('bytes=0-1,5-6', 100) === null, 'Mehrfach-Range → ganze Datei');
$check(FileRules::parseRange('items=0-1', 100) === null, 'Andere Einheit → ganze Datei');

echo "encodePath\n";
$check(FileRules::encodePath('/Merlin Dateien/Bilder/a#b?.jpg') === '/Merlin%20Dateien/Bilder/a%23b%3F.jpg', 'Segmente kodiert, Schrägstriche bleiben');

echo "renamedName\n";
$check(FileRules::renamedName('IMG_1.HEIC', 'Urlaub') === 'Urlaub.HEIC', 'Endung bleibt');
$check(FileRules::renamedName('IMG_1.HEIC', 'Urlaub.heic') === 'Urlaub.HEIC', 'Mitgetippte Endung zählt nicht doppelt');
$check(FileRules::renamedName('a.jpg', '  ') === null && FileRules::renamedName('a.jpg', '.jpg') === null, 'Leerer Name');
$check(FileRules::renamedName('README', 'Neu') === 'Neu', 'Ohne Endung');
$check(FileRules::renamedName('a.jpg', '../x/y') === 'x y.jpg', 'Keine Pfadtrenner');

echo "sanitizeText\n";
$check(FileRules::sanitizeText("a\r\nb\n\n\n\nc\x01") === "a\nb\n\nc", 'Zeilenumbrüche normalisiert, Steuerzeichen weg');
$check(FileRules::sanitizeText("  \n ") === null && FileRules::sanitizeText(null) === null, 'Leer wird null');
$check(mb_strlen((string) FileRules::sanitizeText(str_repeat('ä', 200000))) === FileRules::MAX_TEXT_LENGTH, 'Gekürzt');

echo $failed === 0 ? "\nAlle Tests bestanden.\n" : "\n$failed Test(s) fehlgeschlagen.\n";
exit($failed === 0 ? 0 : 1);
