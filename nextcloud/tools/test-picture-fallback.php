<?php

declare(strict_types=1);

/**
 * Testharness für ContentExtractorService::fillEmptyPictureFallbacks().
 *
 * Aufruf (im App-Verzeichnis):
 *   php tools/test-picture-fallback.php
 *
 * Hintergrund: medium.com liefert Artikelbilder als <picture>, dessen
 * Fallback-<img> keine eigene Bildquelle hat. Readability entfernte diese
 * <img> (unwrapNoscriptImages()), im gespeicherten Artikel stand nur noch
 * das Hero-Bild. Die letzte Prüfung lässt deshalb Readability selbst über
 * das Ergebnis laufen.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;
use OCA\Merlin\Service\ContentExtractorService;

require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';

$service = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();
$fill    = new ReflectionMethod(ContentExtractorService::class, 'fillEmptyPictureFallbacks');
$fill->setAccessible(true);

$passed   = 0;
$failures = [];

/** Prüft das erste <img> im Ergebnis gegen das erwartete Markup. */
$check = function (string $label, string $input, string $expected) use ($service, $fill, &$passed, &$failures): void {
	$out    = (string) $fill->invoke($service, $input);
	$actual = preg_match('#<img[^>]*>#i', $out, $m) === 1 ? $m[0] : $out;

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

/** Markup eines Medium-Artikelbilds (verkürzt). */
$mediumFigure = static fn(string $id): string =>
	'<figure><div role="button" tabindex="0"><div><picture>'
	. '<source srcSet="https://miro.medium.com/v2/resize:fit:640/format:webp/1*' . $id . '.jpeg 640w, https://miro.medium.com/v2/resize:fit:1400/format:webp/1*' . $id . '.jpeg 1400w" sizes="700px" type="image/webp">'
	. '<source data-testid="og" srcSet="https://miro.medium.com/v2/resize:fit:640/1*' . $id . '.jpeg 640w, https://miro.medium.com/v2/resize:fit:1100/1*' . $id . '.jpeg 1100w" sizes="700px">'
	. '<img alt="" class="bh ms mt c" width="700" height="525" loading="lazy" role="presentation"></picture></div></div>'
	. '<figcaption>Bild ' . $id . '</figcaption></figure>';

echo "\n\033[1m<picture> ohne Bildquelle am <img>\033[0m\n";

$check(
	'Medium: src aus der breitesten <source>-Variante',
	$mediumFigure('A'),
	'<img src="https://miro.medium.com/v2/resize:fit:1400/format:webp/1*A.jpeg" alt="" class="bh ms mt c" width="700" height="525" loading="lazy" role="presentation">'
);

$check(
	'<img> mit src bleibt unverändert',
	'<picture><source srcset="https://x.example/b.webp 1200w"><img src="https://x.example/a.jpg" alt="a"></picture>',
	'<img src="https://x.example/a.jpg" alt="a">'
);

$check(
	'<img> mit data-src bleibt unverändert',
	'<picture><source srcset="https://x.example/b.webp 1200w"><img data-src="https://x.example/a.jpg"></picture>',
	'<img data-src="https://x.example/a.jpg">'
);

$check(
	'Entities in der URL bleiben codiert',
	'<picture><source srcset="https://x.example/a.jpg?w=800&amp;q=80 800w"><img alt=""></picture>',
	'<img src="https://x.example/a.jpg?w=800&amp;q=80" alt="">'
);

$check(
	'ohne <source srcset> kein src',
	'<picture><source type="image/webp"><img alt=""></picture>',
	'<img alt="">'
);

$check(
	'<img> außerhalb von <picture> bleibt unverändert',
	'<p><img alt="" width="10"></p>',
	'<img alt="" width="10">'
);

echo "\n\033[1mReadability behält die Bilder\033[0m\n";

$paragraph = '<p>Ein Absatz mit genug Text, Kommas, und weiterem Inhalt, damit Readability ihn sicher als Artikeltext wertet, ja.</p>';
$page      = '<!doctype html><html><head><title>Test</title></head><body><article>'
	. $paragraph . $mediumFigure('A') . $paragraph . $mediumFigure('B') . $paragraph . $mediumFigure('C') . $paragraph
	. '</article></body></html>';

$readability = new Readability(new Configuration([
	'fixRelativeURLs' => true,
	'originalURL'     => 'https://medium.com/@someone/some-post-123',
	'summonCthulhu'   => true,
	'keepClasses'     => true,
]));
$readability->parse((string) $fill->invoke($service, $page));
$images = substr_count((string) $readability->getContent(), '<img');

if ($images === 3) {
	$passed++;
	echo "  \033[32m✓\033[0m alle 3 Medium-Bilder überstehen Readability\n";
} else {
	$failures[] = 'Medium-Bilder nach Readability';
	echo "  \033[31m✗ Medium-Bilder nach Readability: erwartet 3, erhalten " . $images . "\033[0m\n";
}

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
