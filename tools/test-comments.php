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
$check(CommentRules::normalizeName("Ann\u{202E}a") === 'Anna', 'Bidi-Steuerzeichen entfernt');
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

echo "Bereinigung (Schadeingaben)\n";
$check(CommentRules::normalizeName('<script>alert(1)</script>Eve') === 'scriptalert(1)/scriptEve', 'Spitze Klammern aus Namen entfernt');
$check(CommentRules::normalizeName("Ev\u{200B}e\u{FEFF}") === 'Eve', 'Nullbreiten-Zeichen und BOM aus Namen entfernt');
$check(CommentRules::nameKey("A\u{200B}nna") === CommentRules::nameKey('Anna'), 'Unsichtbare Zeichen erlauben keinen zweiten "Anna"');
$check(!class_exists(\Normalizer::class) || CommentRules::nameKey("Ange\u{0301}la") === CommentRules::nameKey("Ang\u{00E9}la"), 'NFC: zusammengesetztes é gleich vorkomponiertem (falls intl da)');
$check(CommentRules::normalizeName("Eve\u{E0041}\u{E0042}") === 'Eve', 'Unicode-Tag-Zeichen entfernt');
$check(CommentRules::normalizeName("Eve\nMallory") === 'Eve Mallory', 'Zeilenumbruch im Namen wird Leerzeichen');
$check(CommentRules::normalizeBody("Hallo\x00Welt\x1B[31m") === 'HalloWelt[31m', 'NUL und Escape-Sequenz entfernt');
$check(CommentRules::normalizeBody("a\u{202E}b\u{2066}c") === 'abc', 'Bidi-Umkehr aus Text entfernt');
$check(CommentRules::normalizeBody("Zeile 1\r\nZeile 2\tx") === "Zeile 1\nZeile 2\tx", 'Zeilenumbruch und Tab bleiben');
$check(CommentRules::normalizeBody('<img src=x onerror=alert(1)> a < b') === '<img src=x onerror=alert(1)> a < b', 'HTML bleibt Klartext (Ausgabe maskiert)');
$check(CommentRules::normalizeBody("\u{1F468}\u{200D}\u{1F469}") === "\u{1F468}\u{200D}\u{1F469}", 'ZWJ-Emoji bleibt');
$check(CommentRules::normalizeBody("ab\xC3\x28cd") !== '' && mb_check_encoding(CommentRules::normalizeBody("ab\xC3\x28cd"), 'UTF-8'), 'Ungültiges UTF-8 wird gültig');
$check(json_encode(CommentRules::normalizeBody("x\xFFy")) !== false, 'Bereinigter Text ist JSON-tauglich');
$check(CommentRules::validateBody("\u{200B}\u{200B}") === 'body_empty', 'Nur unsichtbare Zeichen gilt als leer');

echo "Markierungen\n";
$check(CommentRules::isValidXpath('div[1]/p[3]/text()[1]'), 'Normale XPath gültig');
$check(CommentRules::isValidXpath('merlin-inline-player[1]/text()[2]'), 'Custom Element gültig');
$check(!CommentRules::isValidXpath(''), 'Leere XPath ungültig');
$check(!CommentRules::isValidXpath('p[1]/script[1]"><img src=x>'), 'XPath mit Markup ungültig');
$check(!CommentRules::isValidXpath("p[1]\n/text()[1]"), 'XPath mit Zeilenumbruch ungültig');
$check(!CommentRules::isValidXpath('//p[1]'), 'Absolute/descendant-XPath ungültig');
$check(!CommentRules::isValidXpath('p[0]'), 'Index 0 ungültig');
$check(!CommentRules::isValidXpath('P[1]'), 'Großbuchstaben ungültig (Clients schreiben klein)');
$check(CommentRules::validateHighlight('Text', 'p[1]/text()[1]', 0, 'p[1]/text()[1]', 4, 2000) === null, 'Gültige Markierung');
$check(CommentRules::validateHighlight('Text', 'p[1]/text()[1]', -1, 'p[1]/text()[1]', 4, 2000) === 'highlight_invalid', 'Negativer Offset abgelehnt');
$check(CommentRules::validateHighlight('Text', 'p[1]/text()[1]', 0, 'p[1]/text()[1]', 2_000_000, 2000) === 'highlight_invalid', 'Riesiger Offset abgelehnt');
$check(CommentRules::validateHighlight("\u{200B}", 'p[1]/text()[1]', 0, 'p[1]/text()[1]', 1, 2000) === 'highlight_invalid', 'Unsichtbarer Text abgelehnt');
$check(CommentRules::validateHighlight(str_repeat('a', 2001), 'p[1]/text()[1]', 0, 'p[1]/text()[1]', 1, 2000) === 'highlight_invalid', 'Zu langer Text abgelehnt');
$check(CommentRules::sanitizeColor('green') === 'green', 'Bekannte Farbe bleibt');
$check(CommentRules::sanitizeColor('red;background:url(x)') === 'yellow', 'Unbekannte Farbe wird gelb');
$check(CommentRules::sanitizeColor('comment') === 'comment', 'Kommentar-Unterstreichung ist erlaubt');
$check(CommentRules::sanitizeGuestColor(' #1D4ED8 ') === '#1d4ed8', 'Gast-Farbe aus der Palette wird angenommen');
$check(CommentRules::sanitizeGuestColor('#ffffff') === null, 'Farbe außerhalb der Palette wird abgelehnt');
$check(CommentRules::sanitizeGuestColor(CommentRules::OWNER_COLOR) === null, 'Besitzer-Orange ist für Gäste gesperrt');
$check(CommentRules::sanitizeGuestColor('red;background:url(x)') === null, 'CSS in der Farbe wird abgelehnt');
$check(CommentRules::firstFreeColor([]) === CommentRules::GUEST_COLORS[0], 'Erste freie Farbe ohne Gäste');
$check(CommentRules::firstFreeColor([CommentRules::GUEST_COLORS[0], CommentRules::GUEST_COLORS[2]]) === CommentRules::GUEST_COLORS[1], 'Lücke wird zuerst vergeben');
$check(CommentRules::firstFreeColor(CommentRules::GUEST_COLORS) === null, 'Alle Farben vergeben');
$check(count(array_unique(CommentRules::GUEST_COLORS)) === count(CommentRules::GUEST_COLORS), 'Palette ohne Doppelte');
$check(CommentRules::sanitizeSignature('0123456789abcdef0123') === '0123456789abcdef0123', 'Gültige Änderungsmarke bleibt');
$check(CommentRules::sanitizeSignature("x\nevent: closed") === '', 'Änderungsmarke mit Fremdinhalt verworfen');

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
