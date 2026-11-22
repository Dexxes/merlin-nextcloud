<?php

declare(strict_types=1);

/**
 * Testharness für die Bildmaße responsiver Bilder
 * (ContentExtractorService::dropResponsiveImageDimensions(), aufgerufen aus
 * sanitizeHtml()).
 *
 * Aufruf (im App-Verzeichnis):
 *   php tools/test-responsive-images.php
 *
 * Wie tools/test-caption-flatten.php über die private Methode sanitizeHtml(),
 * also inklusive Allowlist-Durchlauf, so wie der Reader den Inhalt bekommt.
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
$sanitize = new ReflectionMethod(ContentExtractorService::class, 'sanitizeHtml');
$sanitize->setAccessible(true);

$passed   = 0;
$failures = [];

/** Prüft das erste <img> im Ergebnis gegen das erwartete Markup. */
$check = function (string $label, string $input, string $expected) use ($service, $sanitize, &$passed, &$failures): void {
	$out    = (string) $sanitize->invoke($service, $input);
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

echo "\n\033[1mResponsive Bilder: keine festen Maße der kleinsten Variante\033[0m\n";

// swr.de (swr1/podcast/die-aerzte-le-frisur-100.html): 720px breites Bild,
// width/height beschreiben nur die 160w-Variante.
$check(
	'srcset mit w-Breiten: width/height entfallen (swr.de)',
	'<figure><img src="https://images.swr.de/x/16x9-M/bild.jpg?width=720" srcset="https://images.swr.de/x/16x9-XS/bild.jpg?width=160 160w, https://images.swr.de/x/16x9-M/bild.jpg?width=720 720w, https://images.swr.de/x/16x9-XL/bild.jpg 1920w" sizes="(min-width: 1200px) 814px, calc(100vw - 32px)" alt="Farin Urlaub" width="160" height="90" loading="lazy"></figure>',
	'<img src="https://images.swr.de/x/16x9-M/bild.jpg?width=720" alt="Farin Urlaub">'
);

$check(
	'Komma in der URL stört die Erkennung nicht',
	'<img src="https://cdn.example/w_100,c_limit/a.jpg" srcset="https://cdn.example/w_100,c_limit/a.jpg 100w, https://cdn.example/w_800,c_limit/a.jpg 800w" width="100" height="50">',
	'<img src="https://cdn.example/w_100,c_limit/a.jpg">'
);

$check(
	'srcset mit x-Dichten: Maße bleiben (Icons)',
	'<img src="https://x.example/icon.png" srcset="https://x.example/icon@2x.png 2x" width="20" height="20">',
	'<img src="https://x.example/icon.png" width="20" height="20">'
);

$check(
	'ohne srcset: Maße bleiben',
	'<img src="https://x.example/a.jpg" width="300" height="200">',
	'<img src="https://x.example/a.jpg" width="300" height="200">'
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
