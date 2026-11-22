<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Ablage der Custom-Filter (Tabelle merlin_cfilter): je Zeile ein XML für
 * (scope, domain, user_id), mit eindeutigem Index über diese drei Spalten.
 * Admin-Zeilen tragen als user_id ContentFilterRepository::ADMIN_SENTINEL_USER_ID.
 */
interface ContentFilterStore {
	public function readXml(string $scope, string $userId, string $domain): ?string;

	public function findId(string $scope, string $userId, string $domain): ?int;

	/**
	 * @throws \Throwable wenn die Zeile nicht angelegt werden konnte (z. B.
	 *         eindeutiger Index, weil ein anderer Request schneller war)
	 */
	public function insert(string $scope, string $userId, string $domain, string $xml, \DateTime $now, string $updatedBy): void;

	public function update(int $id, string $xml, \DateTime $now, string $updatedBy): void;

	public function delete(string $scope, string $userId, string $domain): void;

	/** Löscht alle Zeilen eines scope für einen Nutzer. */
	public function deleteAllForUser(string $scope, string $userId): void;

	/**
	 * Domains eines scope, mit $userId nur die dieses Nutzers; alphabetisch,
	 * wenn $sorted.
	 *
	 * @return list<string>
	 */
	public function listDomains(string $scope, ?string $userId = null, bool $sorted = false): array;

	/**
	 * Anzahl Zeilen je Domain in einem scope.
	 *
	 * @return array<string, int>
	 */
	public function countByDomain(string $scope): array;
}
