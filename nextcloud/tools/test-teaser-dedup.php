<?php

declare(strict_types=1);

/**
 * Testharness für ContentExtractorService::stripDuplicateMetadata(), Pass 2:
 * ein Absatz am Anfang des Contents, der den Teaser (og:description bzw.
 * <metadata><excerpt>) wiederholt, wird entfernt - ein echter erster Absatz,
 * der nur ähnlich beginnt, bleibt.
 *
 * Aufruf (im App-Verzeichnis):
 *   php tools/test-teaser-dedup.php
 *
 * Hintergrund: taz.de (z. B. https://taz.de/!vn6218101/) setzt den Teaser als
 * <p class="typo-subline-detail"> in den Artikel-Container; Readability
 * übernimmt ihn in den Content, die Clients zeigen ihn als Excerpt aber
 * schon oberhalb des Artikels.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use OCA\Merlin\Service\ContentExtractorService;

require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';

$service = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();
$strip   = new ReflectionMethod(ContentExtractorService::class, 'stripDuplicateMetadata');
$strip->setAccessible(true);

$passed   = 0;
$failures = [];

$check = function (string $label, string $content, string $title, ?string $excerpt, bool $expectTeaserRemoved, string $marker)
	use ($service, $strip, &$passed, &$failures): void {
	$out     = $strip->invoke($service, $content, $title, $excerpt);
	$removed = !str_contains($out, $marker);
	if ($removed === $expectTeaserRemoved) {
		$passed++;
		echo "\033[32m✓\033[0m " . $label . "\n";
	} else {
		$failures[] = $label;
		echo "\033[31m✗\033[0m " . $label . "\n    Ergebnis: " . $out . "\n";
	}
};

$tazTeaser = 'Die taz-Seitenwende feiert einjähriges Jubiläum. Wir haben mit Abon­nen­t:in­nen gesprochen, wie ihnen das täglich digitale Lesen seither gefällt.';
$tazBody   = '<p>Aus der taz | Gisela Wunnenberg war skeptisch, als die taz die Seitenwende verkündete.</p><p>Zweiter Absatz.</p>';

echo "\033[1mTeaser-Absatz entfernen\033[0m\n";

$check(
	'taz.de: Teaser mit &shy; im Content, Excerpt mit U+00AD -> entfernt',
	'<p class="typo-subline-detail">Die taz-Seitenwende feiert einjähriges Jubiläum. Wir haben mit Abon&shy;nen&shy;t:in&shy;nen gesprochen, wie ihnen das täglich digitale Lesen seither gefällt.</p>' . $tazBody,
	'taz-Leser:innen ziehen Bilanz nach der Seitenwende',
	$tazTeaser,
	true,
	'feiert einjähriges'
);

$check(
	'Teaser-Absatz ohne Klasse, identischer Text -> entfernt',
	'<p>' . $tazTeaser . '</p>' . $tazBody,
	'taz-Leser:innen ziehen Bilanz nach der Seitenwende',
	$tazTeaser,
	true,
	'feiert einjähriges'
);
$out = $strip->invoke($service, '<p>' . $tazTeaser . '</p>' . $tazBody, 'Titel', $tazTeaser);
if (str_contains($out, 'Gisela Wunnenberg') && str_contains($out, 'Zweiter Absatz')) {
	$passed++;
	echo "\033[32m✓\033[0m Nachfolgende Absätze unverändert\n";
} else {
	$failures[] = 'Nachfolgende Absätze unverändert';
	echo "\033[31m✗\033[0m Nachfolgende Absätze unverändert\n    Ergebnis: " . $out . "\n";
}

$check(
	'Teaser in <div> verschachtelt, kleine Abweichung (Satzzeichen, Anführungszeichen) -> entfernt',
	'<div><div><p>„Die Stadt plant neue Radwege“ - entlang der Hauptstraße sollen bis 2028 zwölf Kilometer entstehen.</p></div><p>Body.</p></div>',
	'Neue Radwege',
	'Die Stadt plant neue Radwege: Entlang der Hauptstraße sollen bis 2028 zwölf Kilometer entstehen.',
	true,
	'zwölf Kilometer'
);

$check(
	'Gekürzter Excerpt mit "..." gegen vollständigen Teaser-Absatz (Aufrufer reicht Kurzform) -> entfernt, wenn fast gleich lang',
	'<p>Eins zwei drei vier fünf sechs sieben acht neun zehn elf zwölf dreizehn vierzehn fünfzehn sechzehn siebzehn achtzehn neunzehn zwanzig.</p><p>Body.</p>',
	'Titel',
	'Eins zwei drei vier fünf sechs sieben acht neun zehn elf zwölf dreizehn vierzehn fünfzehn sechzehn siebzehn achtzehn...',
	true,
	'neunzehn'
);

$check(
	'Teaser erst als zweiter Absatz nach kurzer Ortsmarke -> entfernt',
	'<p>Berlin</p><p>' . $tazTeaser . '</p>' . $tazBody,
	'Titel',
	$tazTeaser,
	true,
	'feiert einjähriges'
);

echo "\n\033[1mEchte Absätze behalten\033[0m\n";

$check(
	'og:description = Anfang eines langen ersten Absatzes -> Absatz bleibt',
	'<p>' . $tazTeaser . ' Gisela Wunnenberg war skeptisch, als die taz die Seitenwende verkündete. Die 83-Jährige ist leidenschaftliche Zeitungsleserin und lebt in Hamburg.</p><p>Body.</p>',
	'Titel',
	$tazTeaser,
	false,
	'feiert einjähriges'
);

$check(
	'Gekürzter Excerpt (300 Zeichen + "...") eines langen Absatzes -> Absatz bleibt',
	'<p>' . str_repeat('Wort ', 10) . 'Anfang des Absatzes geht noch sehr lange weiter mit vielen weiteren Worten die nicht im Teaser stehen weil er gekürzt wurde und so weiter und so fort bis zum Ende.</p>',
	'Titel',
	str_repeat('Wort ', 10) . 'Anfang des Absatzes...',
	false,
	'bis zum Ende'
);

$check(
	'Kurzer Excerpt (< 5 Wörter) -> nichts entfernt',
	'<p>Kurzer Text hier.</p><p>Body.</p>',
	'Titel',
	'Kurzer Text hier.',
	false,
	'Kurzer Text'
);

$check(
	'Teaser-Text erst im vierten Absatz -> bleibt (nur Anfang des Artikels zählt)',
	'<p>Eins.</p><p>Zwei.</p><p>Drei.</p><p>' . $tazTeaser . '</p>',
	'Titel',
	$tazTeaser,
	false,
	'feiert einjähriges'
);

$check(
	'Teaser-Text als Bildunterschrift -> bleibt',
	'<figure><img src="x.jpg"><figcaption><p>' . $tazTeaser . '</p></figcaption></figure><p>Body.</p>',
	'Titel',
	$tazTeaser,
	false,
	'feiert einjähriges'
);

$check(
	'Kein Excerpt -> nichts entfernt',
	'<p>' . $tazTeaser . '</p>',
	'Titel',
	null,
	false,
	'feiert einjähriges'
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
