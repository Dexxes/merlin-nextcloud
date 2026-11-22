<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\Comment;

/**
 * Ablage der Kommentare (Tabelle merlin_comments). Alle Lookups sind auf
 * den Besitzer des Artikels ($userId) begrenzt.
 */
interface CommentStore {
	/** @return list<Comment> Wurzeln und Antworten eines Artikels, älteste zuerst */
	public function findByArticleId(int $articleId, string $userId): array;

	public function findInArticle(int $id, int $articleId, string $userId): ?Comment;

	public function findForOwner(int $id, string $userId): ?Comment;

	/** Speichert einen neuen Kommentar und setzt dessen id. */
	public function insert(Comment $comment): Comment;

	public function update(Comment $comment): Comment;

	public function delete(Comment $comment): void;

	public function countForHighlight(int $highlightId, string $userId): int;

	public function countReplies(int $rootId): int;

	public function countGuestSince(int $articleId, string $userId, \DateTime $since): int;

	/** Kommentare an der Markierung behalten ihr Zitat, verlieren aber den Bezug. */
	public function detachHighlight(int $highlightId, string $userId): void;

	public function updateQuotedText(int $highlightId, string $userId, string $quotedText): void;

	/** Änderungsmarke über alle Kommentare eines Artikels. */
	public function signature(int $articleId, string $userId): string;
}
