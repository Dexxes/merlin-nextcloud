<?php

declare(strict_types=1);

/**
 * Testharness für den Consent-Cookie im golem.de-Content-Filter.
 *
 * Aufruf: php core/tests/test-golem-consent.php
 *         MERLIN_LIVE_TESTS=1 php core/tests/test-golem-consent.php
 *
 * Golem leitet Server-Requests ohne gültigen Consent-Cookie per 302 auf
 * /sonstiges/zustimmung/auswahl.html um. Gültig ist nur der exakte Wert
 * "cmp" + cookieVersion aus der Konfiguration der Zustimmungsseite; erhöht
 * Golem die Version, landet der Import wieder auf dem Cookie-Banner.
 *
 * Offline (CI) wird nur geprüft, dass content-filters/golem.de.xml den Cookie
 * in der erwarteten Form schickt. Mit MERLIN_LIVE_TESTS=1 wird zusätzlich die
 * aktuelle Version von golem.de gelesen und gegen den Filter verglichen.
 */

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
	echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
	if (!$ok) {
		$failures++;
	}
};

/** Liest cookieName und cookieVersion aus der Golem-Zustimmungsseite. */
$parseConsentConfig = static function (string $html): ?array {
	if (!preg_match('/"cookieName"\s*:\s*"([^"]+)"/', $html, $n)
		|| !preg_match('/"cookieVersion"\s*:\s*"([^"]+)"/', $html, $v)
	) {
		return null;
	}
	return ['name' => $n[1], 'value' => 'cmp' . stripcslashes($v[1])];
};

echo "1. golem.de.xml schickt Consent- und Multipage-Cookie\n";

$xml = simplexml_load_file(__DIR__ . '/../../content-filters/golem.de.xml');
$check($xml !== false, 'golem.de.xml ist gültiges XML');

$cookie = '';
foreach ($xml->fetch->header ?? [] as $header) {
	if (strcasecmp((string) $header['name'], 'Cookie') === 0) {
		$cookie = (string) $header['value'];
	}
}

$pairs = [];
foreach (array_filter(array_map('trim', explode(';', $cookie))) as $pair) {
	[$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
	$pairs[$k] = $v;
}

$consentName = null;
foreach (array_keys($pairs) as $k) {
	if (preg_match('/^golem_consent\d+$/', (string) $k)) {
		$consentName = (string) $k;
	}
}
$check($consentName !== null, 'Cookie enthält golem_consentNN');
$check(
	$consentName !== null && preg_match('/^cmp\|\d{6}$/', $pairs[$consentName]) === 1,
	'Consent-Wert hat die Form cmp|JJMMTT'
);
$check(($pairs['golem_multipage'] ?? null) === 'single', 'golem_multipage=single ist gesetzt');

echo "2. Parser für die Zustimmungsseite\n";

$sample = '<script>GolemConsent.setCustomConfig({"cookieName":"golem_consent20",'
	. '"cookieDateName":"golem_c20date","cookieExpire":90,"cookieVersion":"|261101","fallback":true});</script>';
$check(
	$parseConsentConfig($sample) === ['name' => 'golem_consent20', 'value' => 'cmp|261101'],
	'cookieName/cookieVersion werden zu golem_consent20=cmp|261101'
);
$check($parseConsentConfig('<html></html>') === null, 'Seite ohne Konfiguration liefert null');

if (getenv('MERLIN_LIVE_TESTS') === '1') {
	echo "3. Live: Filter-Cookie entspricht der aktuellen Golem-Version\n";

	$ctx  = stream_context_create(['http' => [
		'header'        => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:149.0) Gecko/20100101 Firefox/150.0\r\n",
		'timeout'       => 15,
		'ignore_errors' => true,
	]]);
	$html = @file_get_contents('https://www.golem.de/sonstiges/zustimmung/auswahl.html', false, $ctx);
	$live = is_string($html) ? $parseConsentConfig($html) : null;
	$check($live !== null, 'Zustimmungsseite geladen und Konfiguration gefunden');

	if ($live !== null) {
		$check(
			$consentName === $live['name'] && ($pairs[$consentName] ?? null) === $live['value'],
			'golem.de.xml nutzt ' . $live['name'] . '=' . $live['value']
		);
	}
} else {
	echo "3. Live-Prüfung übersprungen (MERLIN_LIVE_TESTS=1 setzen)\n";
}

if ($failures > 0) {
	echo "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
	exit(1);
}
echo "\nAlle Prüfungen bestanden.\n";
