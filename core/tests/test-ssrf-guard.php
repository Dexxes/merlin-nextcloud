<?php

declare(strict_types=1);

/**
 * Testharness für den SSRF-Guard (Extraction\Http\SsrfSafeResolver):
 * private, reservierte und in IPv6 eingebettete interne Adressen werden
 * abgelehnt, öffentliche durchgelassen.
 *
 * Aufruf: php core/tests/test-ssrf-guard.php
 */

use Merlin\Core\Extraction\Http\SsrfSafeResolver;

require_once __DIR__ . '/autoload.php';

final class Guard {
	use SsrfSafeResolver;

	public function check(string $url): bool {
		try {
			$this->assertPublicHostAndResolve($url);
			return true;
		} catch (\Exception) {
			return false;
		}
	}
}

$failures = 0;
$expect = static function (bool $want, string $ip) use (&$failures): void {
	$got = (new ReflectionMethod(Guard::class, 'isPublicAddress'))->invoke(null, $ip);
	$ok = $got === $want;
	echo ($ok ? 'ok   ' : 'FAIL ') . ($want ? 'öffentlich: ' : 'gesperrt:   ') . $ip . "\n";
	if (!$ok) {
		$failures++;
	}
};

foreach ([
	'127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0',
	'100.64.0.1', '100.127.255.254', '192.0.0.8', '198.18.0.1', '198.19.255.255',
	'::1', '::', 'fc00::1', 'fd12:3456::1', 'fe80::1',
	'::ffff:127.0.0.1', '::ffff:7f00:1', '::ffff:10.0.0.1', '::ffff:169.254.169.254',
	'::127.0.0.1', '::10.0.0.1',
	'64:ff9b::7f00:1', '64:ff9b::a00:1', '64:ff9b:1::1',
	'2002:7f00:1::', '2002:a00:1::1', '2001:0:4136:e378:8000:63bf:3fff:fdd2',
	'[::ffff:127.0.0.1]', 'kein-ip',
] as $ip) {
	$expect(false, $ip);
}
foreach (['1.1.1.1', '8.8.8.8', '100.63.255.255', '100.128.0.1', '2606:4700:4700::1111', '::ffff:1.1.1.1', '64:ff9b::808:808', '2002:808:808::1'] as $ip) {
	$expect(true, $ip);
}

// URL-Literale gehen ohne DNS durch dieselbe Prüfung.
$guard = new Guard();
foreach (['http://[::ffff:127.0.0.1]/', 'http://[::ffff:7f00:1]:8080/', 'http://100.100.100.100/', 'http://127.0.0.1/', 'file:///etc/passwd'] as $url) {
	$ok = !$guard->check($url);
	echo ($ok ? 'ok   ' : 'FAIL ') . 'URL gesperrt: ' . $url . "\n";
	if (!$ok) {
		$failures++;
	}
}

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
