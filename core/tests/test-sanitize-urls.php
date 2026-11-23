<?php

declare(strict_types=1);

/**
 * Testharness für die URL-Attribute im Sanitizer
 * (ContentExtractorService::sanitizeHtml()): href, src und poster mit
 * javascript:, vbscript: oder data: fallen weg, http(s) bleibt.
 *
 * Aufruf: php core/tests/test-sanitize-urls.php
 */

use Merlin\Core\Extraction\ContentExtractorService;

require_once __DIR__ . '/autoload.php';

$failures = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$failures): void {
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
	if (!$ok) {
		$failures++;
	}
};

$extractor = (new ReflectionClass(ContentExtractorService::class))->newInstanceWithoutConstructor();
$sanitize = static fn (string $html): string => (string) (new ReflectionMethod(ContentExtractorService::class, 'sanitizeHtml'))->invoke($extractor, $html);

$out = $sanitize('<p>x</p><video src="https://example.org/v.mp4" poster="javascript:alert(1)" controls></video>');
$check(!str_contains($out, 'poster='), 'poster mit javascript: entfernt', $out);
$out = $sanitize('<p>x</p><video src="https://example.org/v.mp4" poster=" data:image/svg+xml,&lt;svg&gt;" controls></video>');
$check(!str_contains($out, 'poster='), 'poster mit data: entfernt', $out);
$out = $sanitize('<p>x</p><video src="https://example.org/v.mp4" poster="https://example.org/p.jpg" controls></video>');
$check(str_contains($out, 'poster="https://example.org/p.jpg"'), 'poster mit https bleibt', $out);
$out = $sanitize('<p><a href="java&#9;script:alert(1)">x</a></p>');
$check(!str_contains($out, 'href='), 'href mit javascript: entfernt', $out);

echo $failures === 0 ? "\nalle Prüfungen ok\n" : "\n{$failures} Fehler\n";
exit($failures === 0 ? 0 : 1);
