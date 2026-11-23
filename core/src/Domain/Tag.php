<?php

declare(strict_types=1);

namespace Merlin\Core\Domain;

/**
 * Schlagwort eines Nutzers (Tabelle merlin_tags), verschachtelt über
 * parentId (null = oberste Ebene, siehe Service\TagTree).
 *
 * Getter und Setter heißen wie beim Nextcloud-Entity OCA\Merlin\Db\Tag,
 * jsonSerialize() liefert dasselbe Wire-Format.
 */
final class Tag implements \JsonSerializable {
	public const DEFAULT_COLOR = '#0082c9';

	private ?int $id = null;
	private string $userId = '';
	private string $name = '';
	private ?string $color = self::DEFAULT_COLOR;
	private ?int $parentId = null;
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

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $name): void {
		$this->name = $name;
	}

	public function getColor(): ?string {
		return $this->color;
	}

	public function setColor(?string $color): void {
		$this->color = $color;
	}

	public function getParentId(): ?int {
		return $this->parentId;
	}

	public function setParentId(?int $parentId): void {
		$this->parentId = $parentId;
	}

	public function getCreatedAt(): ?\DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt(?\DateTimeInterface $createdAt): void {
		$this->createdAt = $createdAt;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'name' => $this->name,
			'color' => $this->color,
			// null = oberste Ebene (verschachtelte Tags, siehe Service\TagTree)
			'parentId' => $this->parentId,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
