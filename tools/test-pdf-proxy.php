<?php

declare(strict_types=1);

/**
 * Testharness für PdfProxyService (Durchreichung der PDF eines PDF-Artikels).
 *
 * Aufruf (im App-Verzeichnis):
 *   php tools/test-pdf-proxy.php
 *
 * Startet einen lokalen PHP-Server als "Quellserver" (Range-fähige PDF, HTML mit
 * .pdf-Endung, Redirect, zu große und fehlerhafte Antworten) und ruft den Service
 * mit überschriebenem SSRF-Guard auf – der echte Guard würde 127.0.0.1 ablehnen
 * (das wird separat geprüft). Ausgabe und Header werden abgefangen; exit() wird
 * durch eine Exception ersetzt.
 *
 * Exit-Code 0 = alle Prüfungen bestanden, 1 = mindestens eine fehlgeschlagen.
 */

use OCA\Merlin\Service\PdfProxyService;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Service/Http/SsrfSafeResolver.php';
require_once __DIR__ . '/../lib/Service/ContentExtractorService.php';
require_once __DIR__ . '/../lib/Service/PdfProxyService.php';

final class ProxyDone extends \RuntimeException {}

/** Service mit lokalem Quellserver erlaubt und abgefangener Ausgabe. */
final class CapturingProxy extends PdfProxyService {
	public int $status = 200;
	/** @var array<string, string> */
	public array $headers = [];
	public string $body = '';

	protected function guardHost(string $url): array { return ['127.0.0.1']; }
	protected function prepareOutput(): void {}
	protected function emitHeaders(int $status, array $headers): void { $this->status = $status; $this->headers = $headers; }
	protected function fail(int $status, string $message): never { $this->status = $status; $this->body = $message; throw new ProxyDone(); }
	protected function finish(): never { throw new ProxyDone(); }
}

/** Service mit ECHTEM SSRF-Guard, sonst abgefangen. */
final class GuardedProxy extends PdfProxyService {
	public int $status = 0;
	protected function prepareOutput(): void {}
	protected function fail(int $status, string $message): never { $this->status = $status; throw new ProxyDone(); }
	protected function finish(): never { throw new ProxyDone(); }
}

// ── Lokaler Quellserver ──────────────────────────────────────────────────────
$dir = sys_get_temp_dir() . '/merlin-pdf-proxy-' . getmypid();
mkdir($dir);
file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pdf  = "%PDF-1.4\n" . str_repeat('0123456789', 1000) . "\n%%EOF\n";
switch ($path) {
	case '/ok.pdf':
		header('Accept-Ranges: bytes');
		header('Content-Type: application/pdf');
		if (preg_match('/^bytes=(\d+)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
			$from = (int) $m[1];
			$to   = $m[2] !== '' ? (int) $m[2] : strlen($pdf) - 1;
			http_response_code(206);
			header("Content-Range: bytes $from-$to/" . strlen($pdf));
			echo substr($pdf, $from, $to - $from + 1);
		} else {
			echo $pdf;
		}
		break;
	case '/redirect':
		header('Location: /ok.pdf', true, 302);
		break;
	case '/html.pdf':
		header('Content-Type: text/html');
		echo '<html><script>alert(1)</script></html>';
		break;
	case '/big.pdf':
		header('Content-Type: application/pdf');
		header('Content-Length: ' . (200 * 1024 * 1024));
		echo "%PDF-1.4\n"; // Body kürzer als Content-Length – der Service muss vorher ablehnen
		break;
	default:
		http_response_code(404);
		echo 'nope';
}
PHP);
$port = random_int(20000, 40000);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", $dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static function () use ($proc, $dir): void {
	proc_terminate($proc);
	@unlink($dir . '/router.php');
	@rmdir($dir);
});
for ($i = 0; $i < 50; $i++) {
	if (@fsockopen('127.0.0.1', $port)) { break; }
	usleep(100000);
}
$base = "http://127.0.0.1:$port";

$passed = 0;
$failures = [];
$eq = function (string $label, mixed $expected, mixed $actual) use (&$passed, &$failures): void {
	if ($expected === $actual) { $passed++; echo "  \033[32m✓\033[0m $label\n"; return; }
	$failures[] = $label;
	echo "  \033[31m✗ $label\033[0m\n      erwartet: " . var_export($expected, true) . "\n      erhalten: " . var_export($actual, true) . "\n";
};

/** Führt den Service aus und liefert [Service, Body]. */
$run = function (string $url, ?string $category = 'PDF', ?string $range = null) use (&$base): array {
	if ($range === null) { unset($_SERVER['HTTP_RANGE']); } else { $_SERVER['HTTP_RANGE'] = $range; }
	$service = new CapturingProxy();
	$out = '';
	ob_start(static function (string $chunk) use (&$out): string { $out .= $chunk; return ''; }, 0);
	try {
		$service->streamFrom($url, $category);
	} catch (ProxyDone) {
	}
	while (ob_get_level()) { ob_end_flush(); }
	return [$service, $out];
};

echo "\n\033[1mVollständige PDF\033[0m\n";
[$s, $out] = $run("$base/ok.pdf");
$eq('Status 200', 200, $s->status);
$eq('Body ist die PDF', true, str_starts_with($out, "%PDF-1.4\n") && strlen($out) > 10000);
$eq('Content-Type application/pdf', 'application/pdf', $s->headers['Content-Type'] ?? null);
$eq('nosniff + sandbox gesetzt', true, ($s->headers['X-Content-Type-Options'] ?? '') === 'nosniff' && str_starts_with($s->headers['Content-Security-Policy'] ?? '', 'sandbox'));
$eq('Accept-Ranges bytes', 'bytes', $s->headers['Accept-Ranges'] ?? null);

echo "\n\033[1mRange (pdf.js lädt nach)\033[0m\n";
[$s, $out] = $run("$base/ok.pdf", 'PDF', 'bytes=100-199');
$eq('Status 206', 206, $s->status);
$eq('100 Bytes ohne %PDF-Magic werden durchgereicht', 100, strlen($out));
$eq('Content-Range weitergegeben', true, str_starts_with($s->headers['Content-Range'] ?? '', 'bytes 100-199/'));
[$s, $out] = $run("$base/ok.pdf", 'PDF', 'bytes=0-99');
$eq('Range ab Dateianfang: 206 mit Magic', [206, true], [$s->status, str_starts_with($out, '%PDF-')]);
[$s, $out] = $run("$base/ok.pdf", 'PDF', 'bytes=0-1,5-6');
$eq('Mehrfach-Range wird ignoriert (200 komplett)', 200, $s->status);

echo "\n\033[1mRedirect\033[0m\n";
[$s, $out] = $run("$base/redirect", 'PDF');
$eq('Redirect wird verfolgt', [200, true], [$s->status, str_starts_with($out, '%PDF-')]);

echo "\n\033[1mAblehnung\033[0m\n";
[$s, $out] = $run("$base/html.pdf");
$eq('HTML statt PDF: 502, nichts ausgeliefert', [502, ''], [$s->status, $out]);
[$s, $out] = $run("$base/big.pdf");
$eq('Content-Length > 100 MB: 413', [413, ''], [$s->status, $out]);
[$s, $out] = $run("$base/missing.pdf");
$eq('404 der Quelle: 502', 502, $s->status);
[$s, $out] = $run("$base/redirect", 'Video');
$eq('Kein PDF-Artikel (Kategorie Video, keine .pdf-Endung): 404', 404, $s->status);
[$s, $out] = $run("$base/redirect", null);
$eq('Ohne Kategorie, URL ohne .pdf: 404', 404, $s->status);
[$s, $out] = $run("$base/ok.pdf", null);
$eq('Ohne Kategorie, aber .pdf-URL: erlaubt', 200, $s->status);

echo "\n\033[1mSSRF-Guard (echt)\033[0m\n";
foreach (['http://127.0.0.1/x.pdf', 'http://10.0.0.5/x.pdf', 'http://169.254.169.254/x.pdf', 'file:///etc/passwd.pdf'] as $bad) {
	$g = new GuardedProxy();
	try { $g->streamFrom($bad, 'PDF'); } catch (ProxyDone) {}
	$eq("lehnt $bad ab (502)", 502, $g->status);
}

echo "\n" . $passed . ' bestanden, ' . count($failures) . " fehlgeschlagen\n";
exit($failures === [] ? 0 : 1);
