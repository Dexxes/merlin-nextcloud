<?php

declare(strict_types=1);

/**
 * Testharness für Service\CommentRules (Gast-Namen, Kommentartext, Threads).
 *
 * Aufruf: php tools/test-comments.php
 *
 * Ohne Composer/Nextcloud: CommentRules ist bewusst frei von
 * Nextcloud-Abhängigkeiten. Gäste weisen sich allein über ihren Namen aus,
 * daher prüft der Test vor allem den Namensvergleich.
 */

require_once __DIR__ . '/../lib/Service/CommentRules.php';

use OCA\Merlin\Service\CommentRules;

$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
	echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
	if (!$ok) {
		$failed++;
	}
};

echo "Namen\n";
$check(CommentRules::normalizeName("  Anna \t  Schmidt ") === 'Anna Schmidt', 'Leerraum zusammengefasst und getrimmt');
$check(CommentRules::nameKey(' ANNA ') === CommentRules::nameKey('anna'), 'Groß-/Kleinschreibung und Rand egal');
$check(CommentRules::nameKey('Jürgen') === 'jürgen', 'Umlaute bleiben, nur klein');
$check(CommentRules::normalizeName("Ann\u{202E}a") === 'Ann a', 'Bidi-Steuerzeichen entfernt');
$check(CommentRules::validateName('A') === 'name_too_short', 'Ein Zeichen zu kurz');
$check(CommentRules::validateName('  ') === 'name_too_short', 'Nur Leerraum zu kurz');
$check(CommentRules::validateName('Al') === null, 'Zwei Zeichen gültig');
$check(CommentRules::validateName(str_repeat('ä', 50)) === null, '50 Zeichen (Multibyte) gültig');
$check(CommentRules::validateName(str_repeat('a', 51)) === 'name_too_long', '51 Zeichen zu lang');
$check(CommentRules::isReservedName(' julian von bülow', ['jvb', 'Julian von Bülow']), 'Anzeigename des Besitzers reserviert');
$check(CommentRules::isReservedName('JVB', ['jvb', 'Julian']), 'Nutzer-ID des Besitzers reserviert');
$check(!CommentRules::isReservedName('Anna', ['jvb', 'Julian']), 'Anderer Name frei');

echo "Bearbeiten nach Name\n";
$key = CommentRules::nameKey('Anna');
$check(CommentRules::guestMayEdit('guest', $key, 'anna '), 'Gleicher Name darf');
$check(!CommentRules::guestMayEdit('guest', $key, 'Anne'), 'Anderer Name darf nicht');
$check(!CommentRules::guestMayEdit('guest', $key, ''), 'Leerer Name darf nicht');
$check(!CommentRules::guestMayEdit('owner', $key, 'Anna'), 'Besitzer-Beitrag nie durch Gast');
$check(!CommentRules::guestMayEdit('guest', null, 'Anna'), 'Ohne Schlüssel nie');

echo "Text\n";
$check(CommentRules::normalizeBody("  Hallo\r\nWelt  ") === "Hallo\nWelt", 'CRLF zu LF, getrimmt');
$check(CommentRules::normalizeBody("a\n\n\n\nb") === "a\n\nb", 'Höchstens eine Leerzeile');
$check(CommentRules::normalizeBody("a\x07b\tc") === "ab\tc", 'Steuerzeichen raus, Tab bleibt');
$check(CommentRules::normalizeBody("👩‍💻") === "👩‍💻", 'Emoji mit ZWJ bleibt ganz');
$check(CommentRules::validateBody(" \n ") === 'body_empty', 'Leerer Text abgelehnt');
$check(CommentRules::validateBody(str_repeat('x', 5000)) === null, '5000 Zeichen gültig');
$check(CommentRules::validateBody(str_repeat('x', 5001)) === 'body_too_long', '5001 Zeichen zu lang');

echo "Threads\n";
$check(CommentRules::threadPosition(7, null) === [7, 7], 'Antwort auf Wurzel hängt an der Wurzel');
$check(CommentRules::threadPosition(9, 7) === [7, 9], 'Antwort auf Antwort hängt an derselben Wurzel');

$flat = [
	['id' => 1, 'parentId' => null],
	['id' => 2, 'parentId' => 1],
	['id' => 3, 'parentId' => null],
	['id' => 4, 'parentId' => 1],
	['id' => 5, 'parentId' => 99],
];
$nested = CommentRules::nest($flat);
$check(array_column($nested, 'id') === [1, 3], 'Wurzeln in Reihenfolge');
$check(array_column($nested[0]['replies'], 'id') === [2, 4], 'Antworten unter ihrer Wurzel');
$check($nested[1]['replies'] === [], 'Wurzel ohne Antworten hat leere Liste');
$check(count($nested) === 2, 'Antwort ohne Wurzel verworfen');

echo $failed === 0 ? "\nAlle Tests bestanden.\n" : "\n$failed Test(s) fehlgeschlagen.\n";
exit($failed === 0 ? 0 : 1);
