<?php

declare(strict_types=1);

/**
 * Testharness für die Zitaterkennung
 * (ContentExtractorService::normalizeQuotes(), Pass 2: Standard-<blockquote>).
 *
 * Aufruf (auf dem Server, im App-Verzeichnis):
 *   php tools/test-quotes.php
 *
 * Geprüft wird normalizeQuotes() mit leerer Domain (keine domainspezifischen
 * <quotes>-Regeln, keine Datenbank nötig) und anschließend sanitizeHtml(), so
 * wie der Reader den Inhalt später bekommt. Der Service wird ohne Konstruktor
 * instanziiert (newInstanceWithoutConstructor), ein Nextcloud-Bootstrap ist
 * deshalb nicht nötig.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use OCA\Merlin\Service\ContentExtractorService;
use OCA\Merlin\Service\ContentFilterRepository;

require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';
require_once __DIR__ . '/../lib/Service/ContentFilterRepository.php';

$service    = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();
$repository = (new ReflectionClass(ContentFilterRepository::class))->newInstanceWithoutConstructor();
foreach (['contentFilters' => $repository, 'logger' => new \Psr\Log\NullLogger(), 'currentUserId' => null] as $prop => $value) {
	$ref = new ReflectionProperty(ContentExtractorService::class, $prop);
	$ref->setAccessible(true);
	$ref->setValue($service, $value);
}
$normalize = new ReflectionMethod(ContentExtractorService::class, 'normalizeQuotes');
$normalize->setAccessible(true);
$sanitize  = new ReflectionMethod(ContentExtractorService::class, 'sanitizeHtml');
$sanitize->setAccessible(true);

$passed   = 0;
$failures = [];

/**
 * Prüft den <body>-Inhalt nach normalizeQuotes() + sanitizeHtml() gegen das
 * erwartete Markup (Leerraum zwischen Tags wird ignoriert).
 */
$check = function (string $label, string $input, string $expected) use ($service, $normalize, $sanitize, &$passed, &$failures): void {
	$html = (string) $normalize->invoke($service, '<html><body>' . $input . '</body></html>', '', null);
	$out  = (string) $sanitize->invoke($service, $html);
	$actual = trim((string) preg_replace(['/^<\?xml[^>]*>/', '/>\s+</'], ['', '><'], $out));

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

$check(
	'figure.quote mit figcaption > cite (swr.de)',
	'<figure class="quote">
<blockquote class="quote-blockquote">
<p>Die Kernessenz von Die Ärzte ist es, immer anders zu sein und immer etwas zu machen, was keiner erwartet!</p>
</blockquote>
<figcaption class="quote-footer"><cite>George Lindt, Musikjournalist, über Die Ärzte im Podcast </cite></figcaption>
</figure>',
	'<blockquote class="merlin-quote quote-blockquote"><p>Die Kernessenz von Die Ärzte ist es, immer anders zu sein und immer etwas zu machen, was keiner erwartet!</p><cite class="merlin-quote__source">George Lindt, Musikjournalist, über Die Ärzte im Podcast </cite></blockquote>'
);

$check(
	'figure mit figcaption ohne cite',
	'<figure><blockquote><p>Zitat</p></blockquote><figcaption>— Jane Doe</figcaption></figure>',
	'<blockquote class="merlin-quote"><p>Zitat</p><cite class="merlin-quote__source">Jane Doe</cite></blockquote>'
);

$check(
	'figure nur mit blockquote',
	'<figure><blockquote><p>Zitat</p></blockquote></figure>',
	'<blockquote class="merlin-quote"><p>Zitat</p></blockquote>'
);

$check(
	'blockquote mit cite (unverändert)',
	'<blockquote><p>Zitat</p><cite>Jane Doe</cite></blockquote>',
	'<blockquote class="merlin-quote"><p>Zitat</p><cite class="merlin-quote__source">Jane Doe</cite></blockquote>'
);

$check(
	'blockquote mit footer (unverändert)',
	'<blockquote><p>Zitat</p><footer>Jane Doe</footer></blockquote>',
	'<blockquote class="merlin-quote"><p>Zitat</p><cite class="merlin-quote__source">Jane Doe</cite></blockquote>'
);

$check(
	'blockquote mit folgendem <p><cite> (unverändert)',
	'<blockquote><p>Zitat</p></blockquote><p><cite>Jane Doe</cite></p>',
	'<blockquote class="merlin-quote"><p>Zitat</p><cite class="merlin-quote__source">Jane Doe</cite></blockquote>'
);

$check(
	'figure mit Bild neben blockquote bleibt figure',
	'<figure><img src="https://example.org/a.jpg"><blockquote><p>Zitat</p></blockquote><figcaption>Bildunterschrift</figcaption></figure>',
	'<figure><img src="https://example.org/a.jpg"><blockquote class="merlin-quote"><p>Zitat</p></blockquote><figcaption>Bildunterschrift</figcaption></figure>'
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
