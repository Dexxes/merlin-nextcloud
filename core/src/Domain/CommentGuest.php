<?php

declare(strict_types=1);

namespace Merlin\Core\Domain;

/**
 * Farbe eines Gast-Namens an einem Artikel (Tabelle merlin_comment_guests).
 * Jeder Name (über nameKey verglichen) hat an einem Artikel genau eine Farbe,
 * und keine Farbe gehört zwei Namen.
 */
final class CommentGuest {
	private ?int $id = null;
	private string $userId = '';
	private int $articleId = 0;
	private string $nameKey = '';
	private string $name = '';
	private string $color = '';
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

	public function getArticleId(): int {
		return $this->articleId;
	}

	public function setArticleId(int $articleId): void {
		$this->articleId = $articleId;
	}

	public function getNameKey(): string {
		return $this->nameKey;
	}

	public function setNameKey(string $nameKey): void {
		$this->nameKey = $nameKey;
	}

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $name): void {
		$this->name = $name;
	}

	public function getColor(): string {
		return $this->color;
	}

	public function setColor(string $color): void {
		$this->color = $color;
	}

	public function getCreatedAt(): ?\DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt(?\DateTimeInterface $createdAt): void {
		$this->createdAt = $createdAt;
	}
}
