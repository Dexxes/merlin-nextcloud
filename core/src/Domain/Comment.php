<?php

declare(strict_types=1);

namespace Merlin\Core\Domain;

/**
 * Kommentar oder Antwort an einem Artikel (Tabelle merlin_comments).
 *
 * Getter und Setter heißen wie beim Nextcloud-Entity OCA\Merlin\Db\Comment,
 * jsonSerialize() liefert dasselbe Wire-Format.
 */
final class Comment implements \JsonSerializable {
	public const AUTHOR_OWNER = 'owner';
	public const AUTHOR_GUEST = 'guest';

	private ?int $id = null;
	private string $userId = '';
	private int $articleId = 0;
	private ?int $highlightId = null;
	private ?string $quotedText = null;
	private ?int $parentId = null;
	private ?int $replyToId = null;
	private string $authorType = '';
	private string $authorName = '';
	private ?string $authorNameKey = null;
	private string $body = '';
	private ?\DateTimeInterface $createdAt = null;
	private ?\DateTimeInterface $updatedAt = null;
	private ?\DateTimeInterface $deletedAt = null;

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

	public function getHighlightId(): ?int {
		return $this->highlightId;
	}

	public function setHighlightId(?int $highlightId): void {
		$this->highlightId = $highlightId;
	}

	public function getQuotedText(): ?string {
		return $this->quotedText;
	}

	public function setQuotedText(?string $quotedText): void {
		$this->quotedText = $quotedText;
	}

	public function getParentId(): ?int {
		return $this->parentId;
	}

	public function setParentId(?int $parentId): void {
		$this->parentId = $parentId;
	}

	public function getReplyToId(): ?int {
		return $this->replyToId;
	}

	public function setReplyToId(?int $replyToId): void {
		$this->replyToId = $replyToId;
	}

	public function getAuthorType(): string {
		return $this->authorType;
	}

	public function setAuthorType(string $authorType): void {
		$this->authorType = $authorType;
	}

	public function getAuthorName(): string {
		return $this->authorName;
	}

	public function setAuthorName(string $authorName): void {
		$this->authorName = $authorName;
	}

	public function getAuthorNameKey(): ?string {
		return $this->authorNameKey;
	}

	public function setAuthorNameKey(?string $authorNameKey): void {
		$this->authorNameKey = $authorNameKey;
	}

	public function getBody(): string {
		return $this->body;
	}

	public function setBody(string $body): void {
		$this->body = $body;
	}

	public function getCreatedAt(): ?\DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt(?\DateTimeInterface $createdAt): void {
		$this->createdAt = $createdAt;
	}

	public function getUpdatedAt(): ?\DateTimeInterface {
		return $this->updatedAt;
	}

	public function setUpdatedAt(?\DateTimeInterface $updatedAt): void {
		$this->updatedAt = $updatedAt;
	}

	public function getDeletedAt(): ?\DateTimeInterface {
		return $this->deletedAt;
	}

	public function setDeletedAt(?\DateTimeInterface $deletedAt): void {
		$this->deletedAt = $deletedAt;
	}

	public function isDeleted(): bool {
		return $this->deletedAt !== null;
	}

	/**
	 * Wire-Format für Web und iOS. Der Namensschlüssel bleibt serverseitig;
	 * ein gelöschter Kommentar (Platzhalter einer Wurzel mit Antworten) zeigt
	 * weder Text noch Verfasser.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$deleted = $this->isDeleted();
		return [
			'id'          => $this->id,
			'articleId'   => $this->articleId,
			'highlightId' => $this->highlightId,
			'quotedText'  => $this->quotedText,
			'parentId'    => $this->parentId,
			'replyToId'   => $this->replyToId,
			'authorType'  => $this->authorType,
			'authorName'  => $deleted ? '' : $this->authorName,
			'body'        => $deleted ? '' : $this->body,
			'deleted'     => $deleted,
			'createdAt'   => $this->createdAt->format('c'),
			'updatedAt'   => $this->updatedAt->format('c'),
			'edited'      => !$deleted && $this->updatedAt > $this->createdAt,
		];
	}
}
