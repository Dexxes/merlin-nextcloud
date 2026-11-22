<?php

declare(strict_types=1);

namespace Merlin\Standalone;

/**
 * Versionsnummer des Servers. Beide Varianten haben eine gemeinsame Version
 * (Entscheidung 22.11.2026), die Quelle ist <version> in
 * nextcloud/appinfo/info.xml. Ein Release-Paket ohne nextcloud/ legt sie als
 * Datei standalone/VERSION ab.
 */
final class Version {
	public static function current(): string {
		$root = dirname(__DIR__);
		$file = $root . '/VERSION';
		if (is_file($file)) {
			return trim((string) file_get_contents($file));
		}
		$info = dirname($root) . '/nextcloud/appinfo/info.xml';
		if (is_file($info) && preg_match('#<version>([^<]+)</version>#', (string) file_get_contents($info), $m)) {
			return trim($m[1]);
		}
		return '0.0.0';
	}
}
