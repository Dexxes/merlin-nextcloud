<?php

declare(strict_types=1);

/**
 * Testharness für das Glätten von Bildunterschriften
 * (ContentExtractorService::flattenCaptions(), aufgerufen aus sanitizeHtml()).
 *
 * Aufruf (auf dem Server, im App-Verzeichnis):
 *   php tools/test-caption-flatten.php
 *
 * Geprüft wird der komplette letzte Pipeline-Schritt über die private Methode
 * sanitizeHtml() – also inklusive Allowlist-Durchlauf, so wie der Reader den
 * Inhalt später bekommt. Der Service wird ohne Konstruktor instanziiert
 * (newInstanceWithoutConstructor): sanitizeHtml() nutzt weder Logger noch
 * Repository, ein Nextcloud-Bootstrap ist deshalb nicht nötig. Composer-Autoload
 * wird von ContentExtractorService.php selbst geladen.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use OCA\Merlin\Service\ContentExtractorService;

require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';

$service    = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();
$sanitize   = new ReflectionMethod(ContentExtractorService::class, 'sanitizeHtml');
$sanitize->setAccessible(true);

$passed   = 0;
$failures = [];

/**
 * Prüft die erste <figcaption> im Ergebnis (bzw. das ganze Ergebnis, wenn keine
 * vorhanden ist) gegen den erwarteten Wortlaut.
 */
$check = function (string $label, string $input, string $expected) use ($service, $sanitize, &$passed, &$failures): void {
	$out = (string) $sanitize->invoke($service, $input);
	$actual = preg_match('#<figcaption[^>]*>(.*?)</figcaption>#is', $out, $m) === 1 ? $m[1] : $out;

	if ($actual === $expected) {
		$passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
		return;
	}
	$failures[] = $label;
	echo "  \033[31m✗ " . $label . "\033[0m\n";
	echo '      erwartet: "' . $expected . "\"\n";
	echo '      erhalten: "' . $actual . "\"\n";
};

echo "\n\033[1mBildunterschriften: Umbrüche werden zu \"•\"\033[0m\n";

$check(
	'<br> wird zum Trenner',
	'<figure><img src="https://x/y.jpg" alt=""><figcaption>Ein Bild<br>Foto: dpa</figcaption></figure>',
	'Ein Bild • Foto: dpa'
);

$check(
	'Block-Elemente werden zum Trenner',
	'<figcaption><p>Titel des Bildes</p><p>Foto: dpa</p></figcaption>',
	'Titel des Bildes • Foto: dpa'
);

$check(
	'Einrückung im Quell-HTML bleibt ein Leerzeichen',
	"<figcaption>\n\t\tEin Bild,\n\t\tzweite Quellzeile\n\t</figcaption>",
	'Ein Bild, zweite Quellzeile'
);

$check(
	'Kein Trenner am Ende',
	'<figcaption>Foto: dpa<br></figcaption>',
	'Foto: dpa'
);

$check(
	'Kein Trenner am Anfang',
	'<figcaption><br>Foto: dpa</figcaption>',
	'Foto: dpa'
);

$check(
	'Mehrere <br> ergeben einen Trenner',
	'<figcaption>A<br><br>B</figcaption>',
	'A • B'
);

$check(
	'Leerer Block erzeugt keinen doppelten Trenner',
	'<figcaption><p>A</p><p>   </p><p>B</p></figcaption>',
	'A • B'
);

$check(
	'Verschachtelte Blöcke',
	'<figcaption><div><p>A</p><span>B</span></div><p>C</p></figcaption>',
	'A • <span>B</span> • C'
);

$check(
	'Inline-Auszeichnung bleibt erhalten',
	'<figcaption>Foto: <a href="https://example.org">dpa</a><br><em>Mehr</em> dazu</figcaption>',
	'Foto: <a href="https://example.org">dpa</a> • <em>Mehr</em> dazu'
);

$check(
	'Einzeilige Caption bleibt unverändert',
	'<figcaption>dpa</figcaption>',
	'dpa'
);

$check(
	'Umbrüche ausserhalb von Bildunterschriften bleiben',
	'<p>Text<br>bleibt</p>',
	'<p>Text<br>bleibt</p>'
);

echo "\n" . str_repeat('─', 72) . "\n";
if ($failures === []) {
	echo "\033[32mAlle " . $passed . " Prüfungen bestanden.\033[0m\n";
	exit(0);
}
echo "\033[31m" . count($failures) . ' von ' . ($passed + count($failures)) . " Prüfungen fehlgeschlagen:\033[0m\n";
foreach ($failures as $failure) {
	echo '  · ' . $failure . "\n";
}
exit(1);
