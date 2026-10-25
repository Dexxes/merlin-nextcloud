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
 * (newInstanceWithoutConstructor), ein Nextcloud-Bootstrap ist deshalb nicht
 * nötig. Das Repository (ebenfalls ohne Konstruktor) liefert nur die
 * Signalwörter aus content-filters/$caption-credits.xml und braucht dafür
 * keine Datenbank. Composer-Autoload wird von ContentExtractorService.php
 * selbst geladen.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use OCA\Merlin\Service\ContentExtractorService;
use OCA\Merlin\Service\ContentFilterRepository;

require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';
require_once __DIR__ . '/../lib/Service/ContentFilterRepository.php';

$service    = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();
$repository = (new ReflectionClass(ContentFilterRepository::class))->newInstanceWithoutConstructor();
foreach (['contentFilters' => $repository, 'logger' => new \Psr\Log\NullLogger()] as $prop => $value) {
	$ref = new ReflectionProperty(ContentExtractorService::class, $prop);
	$ref->setAccessible(true);
	$ref->setValue($service, $value);
}
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
	'Ein Bild <cite>Foto: dpa</cite>'
);

$check(
	'Block-Elemente werden zum Trenner',
	'<figcaption><p>Titel des Bildes</p><p>Foto: dpa</p></figcaption>',
	'Titel des Bildes <cite>Foto: dpa</cite>'
);

$check(
	'Einrückung im Quell-HTML bleibt ein Leerzeichen',
	"<figcaption>\n\t\tEin Bild,\n\t\tzweite Quellzeile\n\t</figcaption>",
	'Ein Bild, zweite Quellzeile'
);

$check(
	'Kein Trenner am Ende',
	'<figcaption>Foto: dpa<br></figcaption>',
	'<cite>Foto: dpa</cite>'
);

$check(
	'Kein Trenner am Anfang',
	'<figcaption><br>Foto: dpa</figcaption>',
	'<cite>Foto: dpa</cite>'
);

$check(
	'Mehrere <br> ergeben einen Trenner',
	'<figcaption>A<br><br>B</figcaption>',
	'A <cite>B</cite>'
);

$check(
	'Leerer Block erzeugt keinen doppelten Trenner',
	'<figcaption><p>A</p><p>   </p><p>B</p></figcaption>',
	'A <cite>B</cite>'
);

$check(
	'Verschachtelte Blöcke',
	'<figcaption><div><p>A</p><span>B</span></div><p>C</p></figcaption>',
	'A • <span>B</span> <cite>C</cite>'
);

$check(
	'Inline-Auszeichnung bleibt erhalten',
	'<figcaption>Foto: <a href="https://example.org">dpa</a><br><em>Mehr</em> dazu</figcaption>',
	'Foto: <a href="https://example.org">dpa</a> <cite><em>Mehr</em> dazu</cite>'
);

$check(
	'Einzeilige Caption bleibt unverändert',
	'<figcaption>dpa</figcaption>',
	'dpa'
);

$check(
	'Caption mit Quellen-Präfix ohne Trenner: ganze Caption ist Quelle',
	'<figcaption>Foto: <a href="https://example.org">dpa</a></figcaption>',
	'<cite>Foto: <a href="https://example.org">dpa</a></cite>'
);

$check(
	'Copyright-Zeichen ohne Trenner: ganze Caption ist Quelle',
	'<figcaption>© nomadnes/adobe</figcaption>',
	'<cite>© nomadnes/adobe</cite>'
);

$check(
	'Klammerzusatz "(Foto: …)" am Ende wird Quelle, Klammern entfallen',
	'<figcaption>Uwe Steimle in Pasewalk (Foto: Andy Bünning/imago)</figcaption>',
	'Uwe Steimle in Pasewalk <cite>Foto: Andy Bünning/imago</cite>'
);

$check(
	'Klammerzusatz "(Quelle: …)" am Ende wird Quelle, auch mit Leerraum dahinter',
	"<figcaption>Ein Diagramm. (Quelle: Statistisches Bundesamt) \n </figcaption>",
	'Ein Diagramm. <cite>Quelle: Statistisches Bundesamt</cite>'
);

$check(
	'Klammerzusatz nach Trenner: nur der Zusatz wird Quelle, nicht der ganze letzte Abschnitt',
	'<figcaption>Titel<br>Beschreibung des Bildes (Foto: dpa)</figcaption>',
	'Titel • Beschreibung des Bildes <cite>Foto: dpa</cite>'
);

$check(
	'Klammerzusatz hinter Auszeichnung: Text davor bleibt erhalten',
	'<figcaption><em>Kunstwerk</em> von Anna (Foto: privat)</figcaption>',
	'<em>Kunstwerk</em> von Anna <cite>Foto: privat</cite>'
);

$check(
	'Nur Klammerzusatz: ganze Caption ist Quelle',
	'<figcaption>(Foto: dpa)</figcaption>',
	'<cite>Foto: dpa</cite>'
);

$check(
	'Englischer Klammerzusatz "(Photo: …)" (Signalwort aus content-filters/$caption-credits.xml)',
	'<figcaption>Protesters in Paris (Photo: Reuters)</figcaption>',
	'Protesters in Paris <cite>Photo: Reuters</cite>'
);

$check(
	'Englisches Präfix "Source:" ohne Trenner: ganze Caption ist Quelle',
	'<figcaption>Source: Federal Statistical Office</figcaption>',
	'<cite>Source: Federal Statistical Office</cite>'
);

$check(
	'Schlusspunkt der Caption bleibt vor der Quelle stehen',
	'<figcaption><div>Der Saal ist voll.</div><div>© Agentur</div></figcaption>',
	'Der Saal ist voll. <cite>© Agentur</cite>'
);

$check(
	'Quelle als Satz am Ende hinter <span> (theguardian.com)',
	'<figcaption><span>Isabella Weber in Berlin’s Institute for Advanced Study in September.</span> Photograph: Steffen Roth/The Guardian</figcaption>',
	'<span>Isabella Weber in Berlin’s Institute for Advanced Study in September.</span> <cite>Photograph: Steffen Roth/The Guardian</cite>'
);

$check(
	'Quelle als Satz am Ende im selben Textknoten',
	'<figcaption>Der Saal ist voll. Foto: dpa</figcaption>',
	'Der Saal ist voll. <cite>Foto: dpa</cite>'
);

$check(
	'Quelle nach "/ ©" am Satzende, Lizenz-Link danach (entwicklungsstadt.de)',
	'<figcaption>Auch am Breitscheidplatz fuhren die Bahnen. / © Foto: Wikimedia Commons, Willy Pragher, <a href="https://creativecommons.org/licenses/by/3.0/deed.de">CC BY 3.0</a></figcaption>',
	'Auch am Breitscheidplatz fuhren die Bahnen. <cite>© Foto: Wikimedia Commons, Willy Pragher, <a href="https://creativecommons.org/licenses/by/3.0/deed.de">CC BY 3.0</a></cite>'
);

$check(
	'Quelle nach "/ ©" am Satzende, nur Text',
	'<figcaption>Der Wagen blieb bis 1967 im Einsatz. / © Foto: Wikimedia Commons, Michael Weinland, CC0</figcaption>',
	'Der Wagen blieb bis 1967 im Einsatz. <cite>© Foto: Wikimedia Commons, Michael Weinland, CC0</cite>'
);

$check(
	'Quelle als Satz am Ende mit Link in der Quelle',
	'<figcaption>Der Saal ist voll. Foto: <a href="https://example.org/">Max Muster</a></figcaption>',
	'Der Saal ist voll. <cite>Foto: <a href="https://example.org/">Max Muster</a></cite>'
);

$check(
	'Signalwort mitten im Satz bleibt Text',
	'<figcaption>Sie nennt als Quelle: das Archiv</figcaption>',
	'Sie nennt als Quelle: das Archiv'
);

$check(
	'Klammer ohne Quellen-Präfix bleibt Text',
	'<figcaption>Angela Merkel (CDU) im Bundestag</figcaption>',
	'Angela Merkel (CDU) im Bundestag'
);

$check(
	'Klammerzusatz nicht am Ende bleibt Text',
	'<figcaption>Ein Bild (Foto: dpa) aus dem Archiv</figcaption>',
	'Ein Bild (Foto: dpa) aus dem Archiv'
);

$check(
	'Trenner am Ende ergibt keine leere <cite>',
	'<figcaption>Ein Bild<br></figcaption>',
	'Ein Bild'
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
