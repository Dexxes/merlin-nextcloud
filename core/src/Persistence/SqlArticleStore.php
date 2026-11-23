<?php

declare(strict_types=1);

namespace Merlin\Core\Persistence;

use Merlin\Core\Domain\Article;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\Connection;

/**
 * ArticleStore-Port in SQL (Tabelle merlin_articles), dieselben Abfragen wie
 * ArticleMapper der Nextcloud-App. Einfügen und Ändern schreiben nur die
 * gesetzten Felder (Article::changedFields()), wie Nextclouds Entities.
 */
final class SqlArticleStore implements ArticleStore {
	private const TABLE = 'merlin_articles';
	/** @var RowMapper<Article> */
	private RowMapper $rows;

	public function __construct(private Connection $db) {
		$this->rows = new RowMapper(Article::class, Article::FIELDS);
	}

	public function find(int $id, string $userId): ?Article {
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*merlin_articles WHERE id = :id AND user_id = :user_id',
			['id' => $id, 'user_id' => $userId],
		);
		return $row === null ? null : $this->toArticle($row);
	}

	public function findById(int $id): ?Article {
		$row = $this->db->fetchRow('SELECT * FROM *PREFIX*merlin_articles WHERE id = :id', ['id' => $id]);
		return $row === null ? null : $this->toArticle($row);
	}

	public function findByFileId(string $userId, int $fileId): ?Article {
		$row = $this->db->fetchRow(
			'SELECT * FROM *PREFIX*merlin_articles WHERE user_id = :user_id AND file_id = :file_id ORDER BY id ASC',
			['user_id' => $userId, 'file_id' => $fileId],
		);
		return $row === null ? null : $this->toArticle($row);
	}

	public function findAll(string $userId, array $filters = [], int $limit = 50, int $offset = 0): array {
		$where = ['a.user_id = :user_id'];
		$params = ['user_id' => $userId];

		// tag_ids: das Schlagwort samt Unterschlagworten. Unterabfrage statt
		// Join, damit ein Artikel mit mehreren davon nur einmal erscheint.
		if (!empty($filters['tag_ids'])) {
			[$in, $inParams] = SqlValue::inList('tag', array_map('intval', array_values($filters['tag_ids'])));
			$where[] = "a.id IN (SELECT mat.article_id FROM *PREFIX*merlin_article_tags mat WHERE mat.tag_id IN {$in})";
			$params += $inParams;
		}
		if (isset($filters['is_read'])) {
			$where[] = 'a.is_read = :is_read';
			$params['is_read'] = $filters['is_read'] ? 1 : 0;
		}
		// is_favorite ist NULL (nicht favorisiert) oder der Zeitpunkt der Favorisierung.
		if (isset($filters['is_favorite'])) {
			$where[] = $filters['is_favorite'] ? 'a.is_favorite IS NOT NULL' : 'a.is_favorite IS NULL';
		}
		if (isset($filters['is_archived'])) {
			$where[] = 'a.is_archived = :is_archived';
			$params['is_archived'] = $filters['is_archived'] ? 1 : 0;
		}
		// has_file: Einträge aus „Merlin Dateien“ (Tab „Dateien“) bzw. alles andere.
		if (isset($filters['has_file'])) {
			$where[] = $filters['has_file'] ? 'a.file_id IS NOT NULL' : 'a.file_id IS NULL';
		}
		if (isset($filters['category'])) {
			$where[] = 'a.category = :category';
			$params['category'] = (string) $filters['category'];
		}
		// not_category: einzelner Wert oder Liste (Seiten = alles außer Video und Audio).
		if (isset($filters['not_category'])) {
			[$in, $inParams] = SqlValue::inList('notcat', array_map('strval', (array) $filters['not_category']));
			$where[] = "(a.category IS NULL OR a.category NOT IN {$in})";
			$params += $inParams;
		}

		// Favoriten nach Favorisierung, Archiv nach Archivierung, sonst nach
		// Erstellung; id als zweiter Schlüssel, damit Seiten (limit/offset)
		// nichts doppelt liefern oder auslassen.
		$order = match (true) {
			!empty($filters['is_favorite']) => 'a.is_favorite DESC',
			!empty($filters['is_archived']) => 'a.archived_at DESC',
			default => 'a.created_at DESC',
		};

		$rows = $this->db->fetchAll(
			'SELECT a.* FROM *PREFIX*merlin_articles a WHERE ' . implode(' AND ', $where)
			. " ORDER BY {$order}, a.id DESC LIMIT :limit OFFSET :offset",
			$params + ['limit' => max(0, $limit), 'offset' => max(0, $offset)],
		);
		return array_map($this->toArticle(...), $rows);
	}

	public function counts(string $userId): array {
		// Seiten/Videos/Audio sind die obersten Kategorien ("Mixed", Text mit
		// Medium, zählt zu den Seiten), Datei-Einträge zählen nur unter "files".
		// Gezählt wird in PHP, wie in ArticleMapper::getCounts().
		$empty = ['total' => 0, 'unread' => 0, 'favorites' => 0, 'archived' => 0];
		$counts = ['pages' => $empty, 'videos' => $empty, 'audio' => $empty, 'files' => $empty];

		$rows = $this->db->fetchAll(
			'SELECT is_read, is_favorite, is_archived, category, file_id FROM *PREFIX*merlin_articles WHERE user_id = :user_id',
			['user_id' => $userId],
		);
		foreach ($rows as $row) {
			$group = $row['file_id'] !== null ? 'files' : match ($row['category'] ?? '') {
				'Video' => 'videos',
				'Audio' => 'audio',
				default => 'pages',
			};
			if ((int) $row['is_archived']) {
				$counts[$group]['archived']++;
			} else {
				$counts[$group]['total']++;
				if (!(int) $row['is_read']) {
					$counts[$group]['unread']++;
				}
			}
			if ($row['is_favorite'] !== null) {
				$counts[$group]['favorites']++;
			}
		}
		return $counts;
	}

	public function search(string $userId, string $term, int $limit = 20, int $offset = 0): array {
		// LOWER() … LIKE statt ILIKE: läuft auf SQLite, MySQL/MariaDB und PostgreSQL.
		$columns = ['title', 'excerpt', 'author', 'site_name', 'file_text'];
		$like = implode(' OR ', array_map(
			static fn (string $c) => "LOWER({$c}) LIKE LOWER(:term) ESCAPE '!'",
			$columns,
		));
		$rows = $this->db->fetchAll(
			'SELECT * FROM *PREFIX*merlin_articles WHERE user_id = :user_id AND is_archived = 0'
			. " AND ({$like}) ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset",
			['user_id' => $userId, 'term' => SqlValue::likeContains($term), 'limit' => max(0, $limit), 'offset' => max(0, $offset)],
		);
		return array_map($this->toArticle(...), $rows);
	}

	public function findProcessing(string $userId): array {
		return array_map($this->toArticle(...), $this->db->fetchAll(
			'SELECT * FROM *PREFIX*merlin_articles WHERE user_id = :user_id AND is_processing = 1 ORDER BY created_at DESC, id DESC',
			['user_id' => $userId],
		));
	}

	public function clearStuckProcessing(string $userId, int $ageMinutes = 5): void {
		$this->db->execute(
			'UPDATE *PREFIX*merlin_articles SET is_processing = 0'
			. ' WHERE user_id = :user_id AND is_processing = 1 AND updated_at < :cutoff',
			['user_id' => $userId, 'cutoff' => SqlValue::dateTime(new \DateTimeImmutable("-{$ageMinutes} minutes"))],
		);
	}

	public function insert(Article $article): Article {
		$article->setId($this->db->insert(self::TABLE, $this->changedRow($article)));
		$article->resetChanges();
		return $article;
	}

	public function update(Article $article): Article {
		$row = $this->changedRow($article);
		if ($row !== []) {
			$this->db->execute(
				'UPDATE *PREFIX*merlin_articles SET ' . RowMapper::assignments($row) . ' WHERE id = :id AND user_id = :owner',
				$row + ['id' => (int) $article->getId(), 'owner' => $article->getUserId()],
			);
		}
		$article->resetChanges();
		return $article;
	}

	/** @return array<string, mixed> nur die gesetzten Spalten */
	private function changedRow(Article $article): array {
		$row = $this->rows->toRow($article);
		$changed = [];
		foreach ($article->changedFields() as $field) {
			$column = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
			$changed[$column] = $row[$column];
		}
		return $changed;
	}

	/** @param array<string, mixed> $row */
	private function toArticle(array $row): Article {
		$article = $this->rows->toObject($row);
		$article->resetChanges();
		return $article;
	}
}
