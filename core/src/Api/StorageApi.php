<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Port\Connection;

/**
 * GET /api/storage: Speicherverbrauch des Nutzers in der Datenbank (Text-
 * spalten der Artikel und Markierungen) für die „Speicher“-Anzeige in den
 * iOS-Einstellungen. Antwort wie bisher in StorageController.
 *
 * Gezählt wird mit strlen() in PHP statt LENGTH()/OCTET_LENGTH() in SQL: das
 * liefert auf MySQL/MariaDB, PostgreSQL und SQLite gleich die Bytelänge.
 * Gelesen wird seitenweise nach id, damit nicht alle Inhalte auf einmal im
 * Speicher liegen.
 */
final class StorageApi {
	private const PAGE = 200;

	private const ARTICLE_COLUMNS = ['title', 'content', 'excerpt', 'author', 'site_name', 'url', 'image_url'];
	private const HIGHLIGHT_COLUMNS = ['highlighted_text', 'start_xpath', 'end_xpath', 'pdf_rects'];

	public function __construct(private Connection $db) {
	}

	public function get(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$articles = $this->stats('merlin_articles', self::ARTICLE_COLUMNS, $userId);
		$highlights = $this->stats('merlin_highlights', self::HIGHLIGHT_COLUMNS, $userId);
		return ApiResult::json([
			'articleCount' => $articles['count'],
			'highlightCount' => $highlights['count'],
			'articleBytes' => $articles['bytes'],
			'highlightBytes' => $highlights['bytes'],
			'totalBytes' => $articles['bytes'] + $highlights['bytes'],
		]);
	}

	/**
	 * @param list<string> $columns
	 * @return array{count: int, bytes: int}
	 */
	private function stats(string $table, array $columns, string $userId): array {
		$count = 0;
		$bytes = 0;
		$lastId = 0;
		do {
			$rows = $this->db->fetchAll(
				'SELECT id, ' . implode(', ', $columns) . " FROM *PREFIX*{$table}"
				. ' WHERE user_id = :user_id AND id > :last_id ORDER BY id ASC LIMIT :limit',
				['user_id' => $userId, 'last_id' => $lastId, 'limit' => self::PAGE],
			);
			foreach ($rows as $row) {
				$count++;
				$lastId = (int) $row['id'];
				foreach ($columns as $column) {
					$bytes += strlen((string) ($row[$column] ?? ''));
				}
			}
		} while (count($rows) === self::PAGE);
		return ['count' => $count, 'bytes' => $bytes];
	}
}
