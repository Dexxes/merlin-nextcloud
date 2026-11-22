<?php

declare(strict_types=1);

namespace Merlin\Core\Domain;

/**
 * Öffentlicher Share-Link eines Artikels, so weit ihn die Zugriffsprüfung
 * braucht (Service\ShareAccessService). Getter wie beim Nextcloud-Entity
 * OCA\Merlin\Db\ArticleShare.
 */
final class ArticleShare {
	private ?int $id = null;
	private string $userId = '';
	private int $articleId = 0;
	private string $token = '';
	private ?string $passwordHash = null;
	private ?\DateTimeInterface $expiresAt = null;
	private ?bool $allowComments = true;

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

	public function getArticleId(): int {
		return $this->articleId;
	}

	public function setArticleId(int $articleId): void {
		$this->articleId = $articleId;
	}

	public function getToken(): string {
		return $this->token;
	}

	public function setToken(string $token): void {
		$this->token = $token;
	}

	public function getPasswordHash(): ?string {
		return $this->passwordHash;
	}

	public function setPasswordHash(?string $passwordHash): void {
		$this->passwordHash = $passwordHash;
	}

	public function getExpiresAt(): ?\DateTimeInterface {
		return $this->expiresAt;
	}

	public function setExpiresAt(?\DateTimeInterface $expiresAt): void {
		$this->expiresAt = $expiresAt;
	}

	public function getAllowComments(): ?bool {
		return $this->allowComments;
	}

	public function setAllowComments(?bool $allowComments): void {
		$this->allowComments = $allowComments;
	}

	/**
	 * Dürfen Gäste über diesen Link markieren und kommentieren? NULL (Zeilen
	 * vor der Migration ohne Default) zählt wie true.
	 */
	public function allowsComments(): bool {
		return $this->allowComments !== false;
	}

	public function hasPassword(): bool {
		return $this->passwordHash !== null && $this->passwordHash !== '';
	}

	public function isExpired(): bool {
		return $this->expiresAt !== null && $this->expiresAt < new \DateTime();
	}
}
