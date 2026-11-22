<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Domain\SiteCredential;
use Merlin\Core\Port\SiteCredentialStore;
use OCA\Merlin\Db\SiteCredential as SiteCredentialEntity;
use OCA\Merlin\Db\SiteCredentialMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * SiteCredentialStore-Port des Kerns über SiteCredentialMapper.
 *
 * save() schreibt einen bestehenden Eintrag über das frisch geladene Entity
 * zurück: Nextclouds Entity merkt sich geänderte Felder an den Settern, ein
 * neu gebautes Entity würde ein auf null gesetztes Feld (z. B. die Cookies
 * nach einem fehlgeschlagenen Login) nicht als geändert erkennen.
 */
class NextcloudSiteCredentialStore implements SiteCredentialStore {
	public function __construct(
		private SiteCredentialMapper $mapper,
	) {
	}

	public function find(string $userId, string $domain): ?SiteCredential {
		$entity = $this->findEntity($userId, $domain);
		return $entity === null ? null : self::toDomain($entity);
	}

	public function findAllForUser(string $userId): array {
		return array_values(array_map(self::toDomain(...), $this->mapper->findAllForUser($userId)));
	}

	public function save(SiteCredential $credential): void {
		if ($credential->getId() === null) {
			$entity = new SiteCredentialEntity();
			self::apply($credential, $entity);
			$entity = $this->mapper->insert($entity);
			$credential->setId((int) $entity->getId());
			return;
		}
		$entity = $this->findEntity($credential->getUserId(), $credential->getDomain());
		if ($entity === null || (int) $entity->getId() !== $credential->getId()) {
			throw new \RuntimeException('Zugangsdaten nicht mehr vorhanden: ' . $credential->getDomain());
		}
		self::apply($credential, $entity);
		$this->mapper->update($entity);
	}

	public function deleteByUserAndDomain(string $userId, string $domain): void {
		$this->mapper->deleteByUserAndDomain($userId, $domain);
	}

	private function findEntity(string $userId, string $domain): ?SiteCredentialEntity {
		try {
			return $this->mapper->find($userId, $domain);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	private static function toDomain(SiteCredentialEntity $entity): SiteCredential {
		$credential = new SiteCredential();
		$credential->setId($entity->getId() === null ? null : (int) $entity->getId());
		$credential->setUserId((string) $entity->getUserId());
		$credential->setDomain((string) $entity->getDomain());
		$credential->setUsernameEnc((string) $entity->getUsernameEnc());
		$credential->setPasswordEnc((string) $entity->getPasswordEnc());
		$credential->setSessionCookiesEnc($entity->getSessionCookiesEnc());
		$credential->setCookieExpiresAt($entity->getCookieExpiresAt());
		$credential->setLastLoginStatus((string) $entity->getLastLoginStatus());
		$credential->setLastLoginAt($entity->getLastLoginAt());
		$credential->setCreatedAt($entity->getCreatedAt());
		return $credential;
	}

	private static function apply(SiteCredential $credential, SiteCredentialEntity $entity): void {
		$entity->setUserId($credential->getUserId());
		$entity->setDomain($credential->getDomain());
		$entity->setUsernameEnc($credential->getUsernameEnc());
		$entity->setPasswordEnc($credential->getPasswordEnc());
		$entity->setSessionCookiesEnc($credential->getSessionCookiesEnc());
		$entity->setLastLoginStatus($credential->getLastLoginStatus());
		// Zeitpunkte nur setzen, wenn sie sich geändert haben: so schreibt
		// update() dieselben Spalten wie vorher mit dem Entity direkt.
		if (!self::sameInstant($entity->getCookieExpiresAt(), $credential->getCookieExpiresAt())) {
			$entity->setCookieExpiresAt(self::dateTime($credential->getCookieExpiresAt()));
		}
		if (!self::sameInstant($entity->getLastLoginAt(), $credential->getLastLoginAt())) {
			$entity->setLastLoginAt(self::dateTime($credential->getLastLoginAt()));
		}
		if ($credential->getCreatedAt() !== null && !self::sameInstant($entity->getCreatedAt(), $credential->getCreatedAt())) {
			$entity->setCreatedAt(self::dateTime($credential->getCreatedAt()));
		}
	}

	private static function sameInstant(?\DateTimeInterface $a, ?\DateTimeInterface $b): bool {
		if ($a === null || $b === null) {
			return $a === $b;
		}
		return $a->format('U.u') === $b->format('U.u');
	}

	private static function dateTime(?\DateTimeInterface $value): ?\DateTime {
		if ($value === null || $value instanceof \DateTime) {
			return $value;
		}
		return \DateTime::createFromInterface($value);
	}
}
