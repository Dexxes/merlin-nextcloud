<?php

declare(strict_types=1);

/**
 * Testharness für ContentExtractorService::extractSiteIconUrl() (Icon der
 * konkreten Artikelseite für die Support-Infobox).
 *
 * Aufruf (im App-Verzeichnis, nach `composer install`):
 *   php tools/test-site-icon.php
 *
 * Der Service wird ohne Konstruktor instanziiert (newInstanceWithoutConstructor):
 * die Methode nutzt weder Logger noch Repository, ein Nextcloud-Bootstrap ist
 * deshalb nicht nötig (gleiches Muster wie test-hero-image-dedup.php).
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use OCA\Merlin\Service\ContentExtractorService;

require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';

$service = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();
$method  = new ReflectionMethod(ContentExtractorService::class, 'extractSiteIconUrl');
$method->setAccessible(true);

$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
	echo ($ok ? "  ✓ " : "  ✗ ") . $label . "\n";
	if (!$ok) {
		$failed++;
	}
};
$icon = static fn (string $html, string $url): ?string => $method->invoke($service, $html, $url);

$page = 'https://www.example.org/politik/artikel/x.html';

echo "extractSiteIconUrl\n";

$check($icon('<head><link rel="icon" href="/f.png" sizes="32x32"><link rel="apple-touch-icon" sizes="120x120" href="/a120.png"><link rel="apple-touch-icon" sizes="180x180" href="/a180.png"></head>', $page) === 'https://www.example.org/a180.png',
	'apple-touch-icon (größtes) schlägt rel=icon');
$check($icon('<link rel="apple-touch-icon-precomposed" href="/pre.png">', $page) === 'https://www.example.org/pre.png',
	'apple-touch-icon-precomposed wird erkannt');
$check($icon('<link rel="shortcut icon" href="/f.ico"><link rel="icon" href="/big.png" sizes="192x192"><link rel="icon" type="image/svg+xml" href="/i.svg">', $page) === 'https://www.example.org/i.svg',
	'SVG-Icon schlägt PNG und ICO');
$check($icon('<link rel="shortcut icon" href="/f.ico"><link rel="icon" href="/p.png" sizes="32x32">', $page) === 'https://www.example.org/p.png',
	'Bitmap schlägt ICO');
$check($icon('<link rel="icon" href="/16.png" sizes="16x16"><link rel="icon" href="/96.png" sizes="96x96">', $page) === 'https://www.example.org/96.png',
	'größtes Bitmap gewinnt innerhalb der Klasse');
$check($icon('<link rel="icon" href="img/i.png">', $page) === 'https://www.example.org/politik/artikel/img/i.png',
	'relative URL ohne führenden Slash wird gegen die Seite aufgelöst');
$check($icon('<base href="https://cdn.example.org/assets/"><link rel="icon" href="i.png">', $page) === 'https://cdn.example.org/assets/i.png',
	'<base href> beeinflusst relative Icon-URLs');
$check($icon('<link rel="icon" href="//static.example.org/i.png">', $page) === 'https://static.example.org/i.png',
	'protokollrelative URL');
$check($icon('<link rel="mask-icon" href="/m.svg">', $page) === 'https://www.example.org/favicon.ico',
	'mask-icon (einfarbige Silhouette) wird ignoriert');
$check($icon('<link rel="icon" href="data:image/png;base64,AAAA">', $page) === 'https://www.example.org/favicon.ico',
	'data:-Icon wird ignoriert');
$check($icon('<link rel="icon" href="javascript:alert(1)">', $page) === 'https://www.example.org/favicon.ico',
	'javascript:-Icon wird ignoriert');
$check($icon('<meta name="msapplication-TileImage" content="/tile.png">', $page) === 'https://www.example.org/tile.png',
	'msapplication-TileImage als Fallback');
$check($icon('<html><head><title>x</title></head></html>', 'http://example.org:8080/a') === 'http://example.org:8080/favicon.ico',
	'ohne Angabe: /favicon.ico der Origin (inkl. Port)');
$check($icon('<meta property="og:image" content="https://x.org/banner.jpg">', $page) === 'https://www.example.org/favicon.ico',
	'og:image wird nie als Icon genommen');
$check($icon('<link REL="Apple-Touch-Icon" href="/up.png">', $page) === 'https://www.example.org/up.png',
	'rel-Werte sind case-insensitiv');
$check($icon('<link rel="icon" href="/a.png"><link rel="icon" href="/b.png">', $page) === 'https://www.example.org/a.png',
	'bei Gleichstand gewinnt der erste Eintrag');
$check($icon('<link rel="icon" href="/x.png">', 'ftp://example.org/a') === null,
	'kein http(s)-Basis-URL: null');

echo $failed === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$failed Prüfung(en) fehlgeschlagen.\n";
exit($failed === 0 ? 0 : 1);
