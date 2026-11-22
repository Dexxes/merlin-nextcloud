<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Extraction\Login\LoginConfig;

/**
 * Paywall-Logins je Nutzer und Domain, soweit die Extraktion sie braucht:
 * Login-Konfiguration der Domain und gültige Session-Cookies.
 *
 * Nextcloud: Merlin\Core\Service\SiteCredentialService (verschlüsselte
 * Zugangsdaten und Cookie-Cache in merlin_site_credentials).
 */
interface SiteSessions {
	/**
	 * <login>-Konfiguration aus dem Bundle-Filter der Domain oder null, wenn
	 * die Domain keinen Paywall-Login unterstützt.
	 */
	public function loadLoginConfig(string $domain): ?LoginConfig;

	/**
	 * Gültige, gecachte Session-Cookies oder null. Löst keinen Login aus.
	 *
	 * @return array<string,string>|null
	 */
	public function getCachedCookies(string $userId, string $domain): ?array;

	/**
	 * Cookies aus dem Cache oder, wenn nötig und Zugangsdaten hinterlegt
	 * sind, aus einem frischen Login. null, wenn keine nutzbaren Zugangsdaten
	 * hinterlegt sind oder der Login fehlschlug.
	 *
	 * @return array<string,string>|null
	 */
	public function ensureValidCookies(string $userId, string $domain, LoginConfig $config): ?array;

	/**
	 * Ob der Nutzer für $domain Zugangsdaten hinterlegt hat und der letzte
	 * Login erfolgreich war (abgelaufene Cookies zählen als aktiv).
	 */
	public function hasActiveLogin(string $userId, string $domain): bool;
}
