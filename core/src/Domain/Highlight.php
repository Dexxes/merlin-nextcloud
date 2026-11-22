<?php

declare(strict_types=1);

namespace Merlin\Core\Domain;

/**
 * Markierung oder Kommentar-Textstelle in einem Artikel (Tabelle
 * merlin_highlights).
 *
 * Getter und Setter heißen wie beim Nextcloud-Entity OCA\Merlin\Db\Highlight,
 * jsonSerialize() liefert dasselbe Wire-Format.
 */
final class Highlight implements \JsonSerializable {
	private ?int $id = null;
	private string $userId = '';
	private int $articleId = 0;
	private string $highlightedText = '';
	private string $startXpath = '';
	private int $startOffset = 0;
	private string $endXpath = '';
	private int $endOffset = 0;
	private string $color = '';
	private ?\DateTimeInterface $createdAt = null;
	private ?string $authorType = 'owner';
	private ?string $authorName = null;
	private ?string $authorNameKey = null;
	private ?int $pdfPage = null;
	private ?string $pdfRects = null;
	private int $revision = 0;

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

	public function getHighlightedText(): string {
		return $this->highlightedText;
	}

	public function setHighlightedText(string $highlightedText): void {
		$this->highlightedText = $highlightedText;
	}

	public function getStartXpath(): string {
		return $this->startXpath;
	}

	public function setStartXpath(string $startXpath): void {
		$this->startXpath = $startXpath;
	}

	public function getStartOffset(): int {
		return $this->startOffset;
	}

	public function setStartOffset(int $startOffset): void {
		$this->startOffset = $startOffset;
	}

	public function getEndXpath(): string {
		return $this->endXpath;
	}

	public function setEndXpath(string $endXpath): void {
		$this->endXpath = $endXpath;
	}

	public function getEndOffset(): int {
		return $this->endOffset;
	}

	public function setEndOffset(int $endOffset): void {
		$this->endOffset = $endOffset;
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

	public function getAuthorType(): ?string {
		return $this->authorType;
	}

	public function setAuthorType(?string $authorType): void {
		$this->authorType = $authorType;
	}

	public function getAuthorName(): ?string {
		return $this->authorName;
	}

	public function setAuthorName(?string $authorName): void {
		$this->authorName = $authorName;
	}

	public function getAuthorNameKey(): ?string {
		return $this->authorNameKey;
	}

	public function setAuthorNameKey(?string $authorNameKey): void {
		$this->authorNameKey = $authorNameKey;
	}

	public function getPdfPage(): ?int {
		return $this->pdfPage;
	}

	public function setPdfPage(?int $pdfPage): void {
		$this->pdfPage = $pdfPage;
	}

	public function getPdfRects(): ?string {
		return $this->pdfRects;
	}

	public function setPdfRects(?string $pdfRects): void {
		$this->pdfRects = $pdfRects;
	}

	public function getRevision(): int {
		return $this->revision;
	}

	public function setRevision(int $revision): void {
		$this->revision = $revision;
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id'              => $this->id,
			'articleId'       => $this->articleId,
			'highlightedText' => $this->highlightedText,
			'startXpath'      => $this->startXpath,
			'startOffset'     => $this->startOffset,
			'endXpath'        => $this->endXpath,
			'endOffset'       => $this->endOffset,
			'color'           => $this->color,
			'createdAt'       => $this->createdAt->format('c'),
			'authorType'      => $this->authorType ?? 'owner',
			'authorName'      => $this->authorName,
			'pdfPage'         => $this->pdfPage,
			'pdfRects'        => $this->pdfRects !== null ? json_decode($this->pdfRects, true) : null,
		];
	}
}
