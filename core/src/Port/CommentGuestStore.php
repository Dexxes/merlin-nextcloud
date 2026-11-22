<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\CommentGuest;

/**
 * Gast-Farben je Artikel (Tabelle merlin_comment_guests). Name und Farbe
 * sind je Artikel eindeutig.
 */
interface CommentGuestStore {
	/** @return list<CommentGuest> */
	public function findByArticleId(int $articleId, string $userId): array;

	/**
	 * @throws DuplicateEntryException wenn Name oder Farbe an diesem Artikel
	 *         gerade vergeben wurde (eindeutiger Index)
	 */
	public function insert(CommentGuest $guest): CommentGuest;

	/** Änderungsmarke über die Gast-Farben eines Artikels. */
	public function signature(int $articleId, string $userId): string;
}
