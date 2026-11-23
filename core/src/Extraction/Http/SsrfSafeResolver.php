<?php

declare(strict_types=1);

namespace Merlin\Core\Extraction\Http;

/**
 * SSRF-Guard für ausgehende HTTP-Requests: löst einen Host auf und prüft, dass
 * KEINE der zurückgegebenen Adressen privat/reserviert ist (RFC1918, Loopback,
 * Link-Local inkl. 169.254.169.254 Cloud-Metadata, …), plus das dazugehörige
 * CURLOPT_RESOLVE-Pinning gegen DNS-Rebinding zwischen Prüfung und Connect.
 *
 * Extrahiert aus ContentExtractorService (dort seit jeher für den
 * Artikel-Fetch verwendet), damit Service\Login\* denselben Schutz nutzen
 * kann, ohne die curl-/Redirect-Logik von ContentExtractorService
 * mitzuschleppen. Reine Extraktion, keine Verhaltensänderung.
 */
trait SsrfSafeResolver {

	/**
	 * @return list<string> geprüfte IPs
	 * @throws \Exception bei unerlaubtem Schema, nicht auflösbarem Host oder
	 *                     privater/reservierter Ziel-IP
	 */
	private function assertPublicHostAndResolve(string $url): array {
		$parsed = parse_url($url);
		$scheme = strtolower($parsed['scheme'] ?? '');
		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new \Exception('Nicht unterstütztes URL-Schema: ' . ($scheme ?: '(keins)'));
		}

		$host = $parsed['host'] ?? '';
		if ($host === '') {
			throw new \Exception('URL ohne Host: ' . $url);
		}

		$ips = $this->resolveHostIps($host);
		if (empty($ips)) {
			throw new \Exception('Host konnte nicht aufgelöst werden: ' . $host);
		}

		foreach ($ips as $ip) {
			if (!$this->isPublicIp($ip)) {
				throw new \Exception('Host löst auf eine private/reservierte Adresse auf: ' . $host . ' -> ' . $ip);
			}
		}

		return $ips;
	}

	/**
	 * Löst einen Hostnamen (oder eine IP-Literal) zu allen bekannten IPv4-/IPv6-
	 * Adressen auf. Bei einer IP-Literal wird diese direkt zurückgegeben, ohne
	 * DNS-Lookup.
	 *
	 * @return list<string>
	 */
	private function resolveHostIps(string $host): array {
		$bareHost = trim($host, '[]'); // IPv6-Literale kommen aus parse_url() ohne eckige Klammern,
										// zur Sicherheit trotzdem defensiv strippen.

		if (filter_var($bareHost, FILTER_VALIDATE_IP) !== false) {
			return [$bareHost];
		}

		$ips = [];

		$v4 = @gethostbynamel($bareHost);
		if (is_array($v4)) {
			$ips = array_merge($ips, $v4);
		}

		$v6records = @dns_get_record($bareHost, DNS_AAAA);
		if (is_array($v6records)) {
			foreach ($v6records as $record) {
				if (!empty($record['ipv6'])) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return array_values(array_unique($ips));
	}

	/**
	 * true, wenn $ip weder in einem privaten (RFC1918 / IPv6 Unique-Local) noch in
	 * einem reservierten Range liegt (Loopback, Link-Local inkl. 169.254.169.254
	 * Cloud-Metadata, Multicast, Broadcast, Dokumentations-Ranges, …).
	 */
	private function isPublicIp(string $ip): bool {
		return self::isPublicAddress($ip);
	}

	/**
	 * Wie isPublicIp(), statisch für Tests (core/tests/test-ssrf-guard.php).
	 *
	 * FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE allein reicht nicht:
	 * PHP lässt IPv6-Adressen durch, die eine IPv4-Adresse einbetten
	 * (::ffff:127.0.0.1, ::127.0.0.1, 64:ff9b::7f00:1, 2002:7f00:1::), über
	 * die ein Dual-Stack-Socket bzw. NAT64/6to4 doch lokal oder intern
	 * verbindet. Solche Adressen werden deshalb auf ihre IPv4-Adresse
	 * zurückgeführt und die geprüft. Dazu Bereiche, die PHP nicht kennt:
	 * Carrier-Grade-NAT 100.64.0.0/10 (u. a. Tailscale), 192.0.0.0/24
	 * (IETF-Protokolle), 198.18.0.0/15 (Benchmark-Netze, oft intern genutzt).
	 */
	public static function isPublicAddress(string $ip): bool {
		$ip = trim($ip, '[]');
		$binary = @inet_pton($ip);
		if ($binary === false) {
			return false;
		}
		if (strlen($binary) === 16) {
			$embedded = self::embeddedIpv4($binary);
			if ($embedded !== null) {
				return self::isPublicAddress($embedded);
			}
		}
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return false;
		}
		if (strlen($binary) === 4) {
			foreach (['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15'] as $cidr) {
				[$net, $bits] = explode('/', $cidr);
				$mask = -1 << (32 - (int) $bits);
				if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * IPv4-Adresse in einer IPv6-Adresse: IPv4-mapped (::ffff:a.b.c.d),
	 * IPv4-compatible (::a.b.c.d), NAT64 (64:ff9b::/96 und 64:ff9b:1::/48),
	 * 6to4 (2002:AABB:CCDD::/16); 0.0.0.0 (also nie öffentlich) für lokales
	 * NAT64 (64:ff9b:1::/48) und Teredo (2001::/32); sonst null.
	 */
	private static function embeddedIpv4(string $binary): ?string {
		$hex = bin2hex($binary);
		$v4 = static fn (string $h): string => (string) inet_ntop((string) hex2bin($h));
		if (str_starts_with($hex, '00000000000000000000ffff')) {
			return $v4(substr($hex, 24, 8));
		}
		if (str_starts_with($hex, '000000000000000000000000') && substr($hex, 24, 8) !== '00000000' && substr($hex, 24, 8) !== '00000001') {
			return $v4(substr($hex, 24, 8));
		}
		if (str_starts_with($hex, '0064ff9b0000000000000000')) {
			return $v4(substr($hex, 24, 8));
		}
		// Lokales NAT64 (/48, IPv4 nicht an fester Stelle) und Teredo
		// (2001:0::/32, IPv4 verschleiert): nie öffentlich behandeln.
		if (str_starts_with($hex, '0064ff9b0001') || str_starts_with($hex, '20010000')) {
			return '0.0.0.0';
		}
		if (str_starts_with($hex, '2002')) {
			return $v4(substr($hex, 4, 8));
		}
		return null;
	}

	/**
	 * Baut CURLOPT_RESOLVE-Einträge, die $host:$port fest auf die geprüften $ips
	 * pinnen. Schließt die TOCTOU-Lücke zwischen assertPublicHostAndResolve() und
	 * dem eigentlichen curl-Connect: ohne Pinning könnte ein zweiter, vom
	 * Angreifer kontrollierter DNS-Lookup (DNS-Rebinding) zwischen Prüfung und
	 * Verbindungsaufbau eine private Adresse liefern. TLS-SNI und der Host-Header
	 * bleiben unberührt, da curl den Hostnamen für beides weiterverwendet.
	 *
	 * Alle IPs gehen in EINEN Eintrag ("host:port:ip1,ip2,[v6]"). Getrennte
	 * Einträge für denselben host:port überschreiben sich in libcurl - es gewinnt
	 * der letzte, und das ist wegen der Reihenfolge (IPv4, dann IPv6) eine IPv6-
	 * Adresse. Auf Hosts ohne funktionierendes IPv6 (z. B. Docker-Standard-
	 * Netzwerk) scheiterte dann JEDER Abruf sofort mit "Failed to connect ...
	 * after 0 ms". Mit einer Liste probiert curl alle Adressen und weicht aus.
	 *
	 * @param list<string> $ips
	 * @return list<string>
	 */
	private function buildResolvePin(string $host, int $port, array $ips): array {
		$bareHost  = trim($host, '[]');
		$addresses = [];
		foreach ($ips as $ip) {
			// IPv6-Adressen müssen in CURLOPT_RESOLVE in eckigen Klammern stehen.
			$addresses[] = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
		}
		if ($addresses === []) {
			return [];
		}
		return [$bareHost . ':' . $port . ':' . implode(',', $addresses)];
	}
}
