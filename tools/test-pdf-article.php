<?php

declare(strict_types=1);

/**
 * Testharness für PDF-Artikel (ContentExtractorService::isPdfUrl(),
 * buildPdfResult() und die Sanitizer-Prüfung für data-pdf-src).
 *
 * Aufruf (im App-Verzeichnis):
 *   php tools/test-pdf-article.php
 *
 * Es wird nichts geladen: der Service wird ohne Konstruktor instanziiert und
 * nur mit IP-Literalen aufgerufen, damit auch kein DNS nötig ist.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use OCA\Merlin\Service\ContentExtractorService;

require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';

$service = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();
$call = function (string $method, mixed ...$args) use ($service): mixed {
	$m = new ReflectionMethod(ContentExtractorService::class, $method);
	$m->setAccessible(true);
	return $m->invoke($service, ...$args);
};

$passed   = 0;
$failures = [];
$eq = function (string $label, mixed $expected, mixed $actual) use (&$passed, &$failures): void {
	if ($expected === $actual) {
		$passed++;
		echo "  \033[32m✓\033[0m $label\n";
		return;
	}
	$failures[] = $label;
	echo "  \033[31m✗ $label\033[0m\n      erwartet: " . var_export($expected, true) . "\n      erhalten: " . var_export($actual, true) . "\n";
};

echo "\n\033[1misPdfUrl\033[0m\n";
$eq('endet auf .pdf', true, $call('isPdfUrl', 'https://example.org/a/report.pdf'));
$eq('Großbuchstaben + Query', true, $call('isPdfUrl', 'https://example.org/a/REPORT.PDF?download=1#p=2'));
$eq('.pdf nur in der Query', false, $call('isPdfUrl', 'https://example.org/view?file=a.pdf'));
$eq('HTML-Seite', false, $call('isPdfUrl', 'https://example.org/artikel.html'));
$eq('kein Pfad', false, $call('isPdfUrl', 'https://example.org'));

echo "\n\033[1mbuildPdfResult\033[0m\n";
$r = $call('buildPdfResult', 'https://93.184.216.34/docs/Mein_Jahres-Bericht%202025.pdf');
$eq('category', 'PDF', $r['category']);
$eq('Titel aus Dateiname', 'Mein Jahres Bericht 2025', $r['title']);
$eq('Marker enthält URL', true, str_contains($r['content'], 'class="merlin-pdf"') && str_contains($r['content'], 'data-pdf-src="https://93.184.216.34/docs/Mein_Jahres-Bericht%202025.pdf"'));
$eq('kein Bild, keine Lesezeit', [null, 0], [$r['imageUrl'], $r['readingTime']]);
$eq('nicht als Paywall markiert', false, $r['isPaywalled']);

$r = $call('buildPdfResult', 'https://93.184.216.34/.pdf');
$eq('Titel-Fallback Host', '93.184.216.34', $r['title']);

echo "\n\033[1mSSRF\033[0m\n";
foreach (['http://127.0.0.1/x.pdf', 'http://10.0.0.5/x.pdf', 'http://169.254.169.254/x.pdf', 'file:///etc/passwd.pdf'] as $bad) {
	$threw = false;
	try {
		$call('buildPdfResult', $bad);
	} catch (\Throwable $e) {
		$threw = true;
	}
	$eq("lehnt $bad ab", true, $threw);
}

echo "\n\033[1mSanitizer data-pdf-src\033[0m\n";
$out = (string) $call('sanitizeHtml', '<div class="merlin-pdf" data-pdf-src="javascript:alert(1)">x</div>');
$eq('javascript: wird entfernt', false, str_contains($out, 'data-pdf-src'));
$out = (string) $call('sanitizeHtml', '<div class="merlin-pdf" data-pdf-src="ftp://x/a.pdf">x</div>');
$eq('ftp: wird entfernt', false, str_contains($out, 'data-pdf-src'));
$out = (string) $call('sanitizeHtml', '<div class="merlin-pdf" data-pdf-src="https://x.example/a.pdf">x</div>');
$eq('https bleibt erhalten', true, str_contains($out, 'data-pdf-src="https://x.example/a.pdf"'));

echo "\n" . $passed . ' bestanden, ' . count($failures) . " fehlgeschlagen\n";
exit($failures === [] ? 0 : 1);
