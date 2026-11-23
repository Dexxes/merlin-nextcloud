<?php

declare(strict_types=1);

/**
 * Request: Client-Adresse und HTTPS hinter vertrauenswürdigen Proxys
 * ('trusted_proxies'), CIDR-Vergleich für IPv4 und IPv6.
 *
 * Aufruf: php standalone/tests/test-request.php
 */

use Merlin\Standalone\Http\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;
function check(bool $ok, string $label): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
	if (!$ok) {
		$failures++;
	}
}

$proxies = ['127.0.0.1', '172.16.0.0/12', 'fd00::/8'];
check(Request::inRanges('172.20.1.5', $proxies), 'CIDR IPv4 trifft');
check(!Request::inRanges('172.32.0.1', $proxies), 'CIDR IPv4 außerhalb');
check(Request::inRanges('fd12::1', $proxies), 'CIDR IPv6 trifft');
check(!Request::inRanges('fe80::1', $proxies), 'CIDR IPv6 außerhalb');
check(Request::inRanges('127.0.0.1', $proxies) && !Request::inRanges('127.0.0.2', $proxies), 'einzelne Adresse');
check(!Request::inRanges('kein-ip', $proxies), 'keine IP');

check(Request::clientAddress('203.0.113.9', '1.2.3.4', []) === '203.0.113.9', 'ohne Liste: X-Forwarded-For zählt nicht');
check(Request::clientAddress('203.0.113.9', '1.2.3.4', $proxies) === '203.0.113.9', 'fremder Absender: X-Forwarded-For zählt nicht');
check(Request::clientAddress('172.18.0.2', '198.51.100.7', $proxies) === '198.51.100.7', 'Proxy: Client aus X-Forwarded-For');
check(Request::clientAddress('172.18.0.2', '6.6.6.6, 198.51.100.7, 127.0.0.1', $proxies) === '198.51.100.7', 'Proxy-Kette: erste fremde Adresse von rechts, vorgetäuschte links ignoriert');
check(Request::clientAddress('172.18.0.2', '', $proxies) === '172.18.0.2', 'Proxy ohne Header: Proxy-Adresse');
check(Request::clientAddress('172.18.0.2', 'unsinn', $proxies) === '172.18.0.2', 'kaputter Header: Proxy-Adresse');

check(Request::forwardedHeadersTrusted('203.0.113.9', []), 'ohne Liste: X-Forwarded-Proto wie bisher');
check(!Request::forwardedHeadersTrusted('203.0.113.9', $proxies), 'mit Liste: X-Forwarded-Proto nur vom Proxy');

echo $failures === 0 ? "\nalle Prüfungen ok\n" : "\n{$failures} Fehler\n";
exit($failures === 0 ? 0 : 1);
