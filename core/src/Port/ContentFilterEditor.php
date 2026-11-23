<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Lesen und Schreiben der Custom-Ebenen der Content-Filter für die
 * Verwaltungs-API (Api\ContentFilterApi): Admin-Custom (instanzweit) und
 * User-Custom (je Nutzer). Erweitert ContentFilterSource, denn ein Testlauf
 * spielt einen Entwurf ein (setPending…), den die Extraktion über
 * ContentFilterSource sehen muss: beide Ports zeigen auf dieselbe Instanz.
 *
 * Nextcloud: OCA\Merlin\Service\ContentFilterRepository, Standalone:
 * Adapter\StandaloneContentFilterRepository, beide über
 * ContentFilter\ContentFilterRepository.
 */
interface ContentFilterEditor extends ContentFilterSource {
	/** Ob $domain als Filter-Schlüssel zulässig ist (Domainzeichen, Wildcards). */
	public function isValidDomain(string $domain): bool;

	/** Ob ein Host zu einem Filter-Schlüssel gehört (auch über Wildcards). */
	public function domainMatchesFilterKey(string $urlDomain, string $filterDomain): bool;

	public function readAdminCustom(string $domain): ?string;

	public function hasCustom(string $domain): bool;

	public function readUserCustom(string $domain, string $userId): ?string;

	/** @return list<string> Domains mit eigenem Override des Nutzers */
	public function listUserOverrideDomains(string $userId): array;

	public function saveAdminCustom(string $domain, string $xml, string $updatedBy): void;

	public function deleteAdminCustom(string $domain): void;

	public function saveUserCustom(string $userId, string $domain, string $xml): void;

	public function deleteUserCustom(string $userId, string $domain): void;

	/** Ungespeicherter Entwurf der Admin-Ebene für die Dauer des Requests (Testlauf). */
	public function setPendingAdminCustom(string $domain, ?string $xml): void;

	/** Ungespeicherter Entwurf der User-Ebene für die Dauer des Requests (Testlauf). */
	public function setPendingUserCustom(string $userId, string $domain, ?string $xml): void;
}
