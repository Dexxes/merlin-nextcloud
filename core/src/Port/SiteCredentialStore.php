<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\SiteCredential;

/**
 * Gespeicherte Paywall-Zugangsdaten je Nutzer und Domain.
 *
 * Nextcloud: OCA\Merlin\Adapter\NextcloudSiteCredentialStore
 * (SiteCredentialMapper, Tabelle merlin_site_credentials).
 */
interface SiteCredentialStore {
	public function find(string $userId, string $domain): ?SiteCredential;

	/**
	 * @return list<SiteCredential>
	 */
	public function findAllForUser(string $userId): array;

	/**
	 * Legt den Eintrag an (getId() === null, setzt danach die ID) oder
	 * schreibt ihn zurück.
	 */
	public function save(SiteCredential $credential): void;

	public function deleteByUserAndDomain(string $userId, string $domain): void;
}
