<?php

declare(strict_types=1);

/**
 * Testharness für die Hero-Bild-Deduplizierung in Step 12 von
 * ContentExtractorService::processHtml() (contentStartsWithMatchingImage(),
 * firstNonWhitespaceElementChild(), imagesMatchForDedup()).
 *
 * Aufruf (auf dem Server, im App-Verzeichnis):
 *   php tools/test-hero-image-dedup.php
 *
 * Hintergrund: #27 hat contentStartsWithMatchingImage() eingeführt, damit ein
 * in <p>/<a>/<div>/<span> verpacktes Hero-Bild (z. B. WordPress-typisch
 * <p><a href="…"><img src="…"></a></p>) nicht zusätzlich zum Voranstellen
 * eines zweiten, identischen merlin-hero-image führt. Der Bild-Abgleich lief
 * dabei per exaktem String-Vergleich der (normalisierten) src-URL gegen die
 * per og:image ermittelte imageUrl. In der Praxis unterscheiden sich beide
 * URLs bei WordPress-Quellen aber sehr häufig NUR durch die von WordPress
 * automatisch angehängte Größenvariante (z. B. "foto-1024x576.jpg" im
 * Content vs. "foto.jpg" als og:image) oder durch CDN-Resize-Parameter in der
 * Query-String - der exakte Vergleich griff dadurch gerade in dem
 * WordPress-Fall, für den die Methode gebaut wurde, unzuverlässig, und ein
 * <figure>-Wrapper (z. B. Gutenberg-Bildblock) wurde gar nicht erst entpackt.
 * Dieses Script deckt beide Lücken ab und prüft zugleich, dass die
 * bestehenden Sicherheitseigenschaften (kein falsches Unterdrücken bei einem
 * echten, andersartigen Bild) erhalten bleiben.
 *
 * Der Service wird ohne Konstruktor instanziiert (newInstanceWithoutConstructor):
 * die geprüften Methoden nutzen weder Logger noch Repository, ein
 * Nextcloud-Bootstrap ist deshalb nicht nötig. Composer-Autoload wird von
 * ContentExtractorService.php selbst geladen.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use OCA\Merlin\Service\ContentExtractorService;

require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';

$service = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();
$method  = new ReflectionMethod(ContentExtractorService::class, 'contentStartsWithMatchingImage');
$method->setAccessible(true);
$normalizeUrl = new ReflectionMethod(ContentExtractorService::class, 'normalizeUrl');
$normalizeUrl->setAccessible(true);

$passed   = 0;
$failures = [];

$baseUrl = 'https://example.com/blog/some-article/';

/**
 * @param string $html       Content, wie es nach cleanHtml() an Step 12 ankommt (ohne führenden Leerraum).
 * @param string $imageUrl   Roh-imageUrl, wie sie z. B. aus og:image stammt (wird wie in processHtml() normalisiert).
 */
$check = function (string $label, string $html, string $imageUrl, bool $expected) use (
	$service, $method, $normalizeUrl, $baseUrl, &$passed, &$failures
): void {
	$normalizedImageUrl = $normalizeUrl->invoke($service, $imageUrl, $baseUrl);
	$actual = $method->invoke($service, ltrim($html), $normalizedImageUrl, $baseUrl);

	if ($actual === $expected) {
		$passed++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
		return;
	}
	$failures[] = $label;
	echo "  \033[31m✗ " . $label . "\033[0m\n";
	echo '      erwartet: ' . var_export($expected, true) . "\n";
	echo '      erhalten: ' . var_export($actual, true) . "\n";
};

echo "\n\033[1mErkannte Wrapper-Fälle (kein zweites Hero-Bild voranstellen)\033[0m\n";

$check(
	'Einfacher WordPress-Wrapper <p><a><img>, exakt gleiche URL',
	'<p><a href="https://example.com/x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a></p><p>Text.</p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Readability-Wrapper-Div mit vielen Geschwister-Absätzen (Regression #27-Nachbesserung)',
	'<div><p><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a></p><p>Absatz 2</p><p>Absatz 3</p></div>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'WordPress-Größenvariante im Content ("-1024x576") vs. Originaldatei als og:image',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto-1024x576.jpg"></a></p><p>Text.</p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Umgekehrt: og:image trägt die Größenvariante, Content die Originaldatei',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a></p><p>Text.</p>',
	'https://example.com/wp-content/uploads/2024/foto-780x439.jpg',
	true
);

$check(
	'CDN-Resize-Query-String (Jetpack-Photon-Stil) unterscheidet sich nur per "?"',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg?resize=780%2C439&ssl=1"></a></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Gutenberg-Bildblock <figure> verschachtelt in Readability-Wrapper-Div',
	'<div><figure><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></figure><p>Text.</p></div>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Altes WP-[caption]-Shortcode-Div: <a><img> plus Geschwister-<p class=wp-caption-text>',
	'<div><a href="x"><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></a><p>Bildunterschrift</p></div><p>Text.</p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Geschütztes Leerzeichen (&nbsp;) vor dem verpackten Bild',
	"<div>\u{00A0}<p><a href=\"x\"><img src=\"https://example.com/wp-content/uploads/2024/foto.jpg\"></a></p></div>",
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

$check(
	'Protokoll-relative src ("//…") vs. https-imageUrl',
	'<p><a href="x"><img src="//example.com/wp-content/uploads/2024/foto.jpg"></a></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	true
);

echo "\n\033[1mWeiterhin korrekt NICHT erkannt (Hero-Bild muss vorangestellt werden)\033[0m\n";

$check(
	'Früh im Fließtext sitzendes, andersartiges Bild (komplett anderer Dateiname)',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/anderes-foto.jpg"></a></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

$check(
	'Größenvariante eines ANDEREN Bildes (unterschiedlicher Basis-Dateiname)',
	'<p><a href="x"><img src="https://example.com/wp-content/uploads/2024/anderes-foto-1024x576.jpg"></a></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

$check(
	'Text vor dem Bild innerhalb desselben Wrapper-Elements',
	'<p>Ein Foto: <img src="https://example.com/wp-content/uploads/2024/foto.jpg"></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

$check(
	'Nicht unterstützter Wrapper-Tag (z. B. <section>) bricht die Entpackung sicher ab',
	'<section><img src="https://example.com/wp-content/uploads/2024/foto.jpg"></section>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
);

$check(
	'Leerer src am Ende der Wrapper-Kette',
	'<p><a href="x"><img src=""></a></p>',
	'https://example.com/wp-content/uploads/2024/foto.jpg',
	false
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
