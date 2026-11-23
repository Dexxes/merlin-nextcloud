<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\Tag;

/**
 * Ablage der Schlagworte (merlin_tags) und ihrer Zuordnung zu Artikeln
 * (merlin_article_tags).
 */
interface TagStore {
	public function find(int $id, string $userId): ?Tag;

	/** @return list<Tag> alle Schlagworte des Nutzers, nach Name */
	public function findAll(string $userId): array;

	/** @return array<int, ?int> id => parentId (null = oberste Ebene) für Service\TagTree */
	public function parentMap(string $userId): array;

	/** @throws DuplicateEntryException wenn der Name schon vergeben ist */
	public function insert(Tag $tag): Tag;

	/** @throws DuplicateEntryException wenn der Name schon vergeben ist */
	public function update(Tag $tag): Tag;

	/**
	 * Löscht Schlagworte samt ihren Artikel-Zuordnungen (die Artikel bleiben).
	 *
	 * @param list<int> $tagIds
	 */
	public function deleteWithLinks(array $tagIds, string $userId): void;

	/** @return list<Tag> Schlagworte eines Artikels, nach Name */
	public function findByArticleId(int $articleId): array;

	/**
	 * @param list<int> $articleIds
	 * @return array<int, list<Tag>> articleId => Schlagworte, nach Name
	 */
	public function findByArticleIds(array $articleIds): array;

	/** @throws DuplicateEntryException wenn die Zuordnung schon besteht */
	public function addToArticle(int $articleId, int $tagId): void;

	public function removeFromArticle(int $articleId, int $tagId): void;
}
