<?php

declare(strict_types=1);

namespace Merlin\Core\Domain;

/**
 * Paywall-Zugangsdaten eines Nutzers für eine Domain. Benutzername, Passwort
 * und Session-Cookies liegen nur verschlüsselt vor (Port Crypto).
 *
 * Getter und Setter heißen wie beim Nextcloud-Entity
 * OCA\Merlin\Db\SiteCredential. Statuswerte: Extraction\Login\LoginStatus.
 */
final class SiteCredential {
	private ?int $id = null;
	private string $userId = '';
	private string $domain = '';
	private string $usernameEnc = '';
	private string $passwordEnc = '';
	private ?string $sessionCookiesEnc = null;
	private ?\DateTimeInterface $cookieExpiresAt = null;
	private string $lastLoginStatus = '';
	private ?\DateTimeInterface $lastLoginAt = null;
	private ?\DateTimeInterface $createdAt = null;

	public function getId(): ?int {
		return $this->id;
	}

	public function setId(?int $id): void {
		$this->id = $id;
	}

	public function getUserId(): string {
		return $this->userId;
	}

	public function setUserId(string $userId): void {
		$this->userId = $userId;
	}

	public function getDomain(): string {
		return $this->domain;
	}

	public function setDomain(string $domain): void {
		$this->domain = $domain;
	}

	public function getUsernameEnc(): string {
		return $this->usernameEnc;
	}

	public function setUsernameEnc(string $usernameEnc): void {
		$this->usernameEnc = $usernameEnc;
	}

	public function getPasswordEnc(): string {
		return $this->passwordEnc;
	}

	public function setPasswordEnc(string $passwordEnc): void {
		$this->passwordEnc = $passwordEnc;
	}

	public function getSessionCookiesEnc(): ?string {
		return $this->sessionCookiesEnc;
	}

	public function setSessionCookiesEnc(?string $sessionCookiesEnc): void {
		$this->sessionCookiesEnc = $sessionCookiesEnc;
	}

	public function getCookieExpiresAt(): ?\DateTimeInterface {
		return $this->cookieExpiresAt;
	}

	public function setCookieExpiresAt(?\DateTimeInterface $cookieExpiresAt): void {
		$this->cookieExpiresAt = $cookieExpiresAt;
	}

	public function getLastLoginStatus(): string {
		return $this->lastLoginStatus;
	}

	public function setLastLoginStatus(string $lastLoginStatus): void {
		$this->lastLoginStatus = $lastLoginStatus;
	}

	public function getLastLoginAt(): ?\DateTimeInterface {
		return $this->lastLoginAt;
	}

	public function setLastLoginAt(?\DateTimeInterface $lastLoginAt): void {
		$this->lastLoginAt = $lastLoginAt;
	}

	public function getCreatedAt(): ?\DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt(?\DateTimeInterface $createdAt): void {
		$this->createdAt = $createdAt;
	}
}
