<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\Article;

/**
 * Ablage der Artikel (Tabelle merlin_articles). Alle Lookups sind auf den
 * Besitzer ($userId) begrenzt.
 */
interface ArticleStore {
	public function find(int $id, string $userId): ?Article;

	/**
	 * Liste für GET /api/articles, neueste zuerst (Favoriten nach
	 * Favorisierung, Archiv nach Archivierung), bei Gleichstand nach id.
	 *
	 * @param array{is_read?: bool, is_favorite?: bool, is_archived?: bool, category?: string,
	 *     not_category?: string|list<string>, has_file?: bool, tag_ids?: list<int>} $filters
	 * @return list<Article>
	 */
	public function findAll(string $userId, array $filters = [], int $limit = 50, int $offset = 0): array;

	/**
	 * Ungefilterte Zähler je Gruppe (pages, videos, audio, files), jeweils
	 * total, unread, favorites und archived.
	 *
	 * @return array<string, array{total: int, unread: int, favorites: int, archived: int}>
	 */
	public function counts(string $userId): array;

	/** Volltextsuche in Titel, Auszug, Autor, Seitenname und erkanntem Text (nicht archiviert). */
	public function search(string $userId, string $term, int $limit = 20, int $offset = 0): array;

	/** @return list<Article> Artikel, deren Extraktion noch läuft */
	public function findProcessing(string $userId): array;

	/** Setzt is_processing zurück, wenn ein Artikel seit $ageMinutes darin hängt. */
	public function clearStuckProcessing(string $userId, int $ageMinutes = 5): void;

	/** Speichert einen neuen Artikel (nur die gesetzten Felder) und setzt dessen id. */
	public function insert(Article $article): Article;

	/** Schreibt die seit dem Laden geänderten Felder. */
	public function update(Article $article): Article;
}
