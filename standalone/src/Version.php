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

	/**
	 * Angaben für den About-Block der App (Settings.vue, Startwert appInfo),
	 * wie in Nextclouds PageController aus info.xml. Ohne info.xml (Release-
	 * Paket ohne nextcloud/) nur die Version.
	 *
	 * @return array{version: string, build: ?string, author: ?string, licence: ?string, website: ?string, bugs: ?string, donate: string}
	 */
	public static function appInfo(): array {
		$info = dirname(__DIR__, 2) . '/nextcloud/appinfo/info.xml';
		$xml = is_file($info) ? (string) file_get_contents($info) : '';
		$field = static function (string $tag) use ($xml): ?string {
			preg_match_all('#<' . $tag . '(?:\s[^>]*)?>([^<]+)</' . $tag . '>#', $xml, $m);
			return $m[1] === [] ? null : implode(', ', array_map('trim', $m[1]));
		};
		return [
			'version' => self::current(),
			'build' => null,
			'author' => $field('author'),
			'licence' => $field('licence'),
			'website' => $field('website'),
			'bugs' => $field('bugs'),
			'donate' => 'https://github.com/sponsors/Dexxes',
		];
	}
}
