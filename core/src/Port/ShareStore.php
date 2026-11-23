<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\ArticleShare;

/**
 * Öffentliche Share-Links (Tabelle merlin_shares), höchstens einer je Artikel.
 */
interface ShareStore {
	/**
	 * Auflösung eines öffentlichen Links: bewusst ohne Nutzer- oder
	 * Artikelfilter, der anonyme Besucher kennt nur den Token.
	 */
	public function findByToken(string $token): ?ArticleShare;

	public function findByArticleId(int $articleId, string $userId): ?ArticleShare;

	public function insert(ArticleShare $share): ArticleShare;

	public function update(ArticleShare $share): ArticleShare;

	public function deleteByArticleId(int $articleId, string $userId): void;
}
