<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\ArticleShare;

/**
 * Lesender Zugriff auf öffentliche Share-Links (Tabelle merlin_article_shares).
 */
interface ShareStore {
	public function findByToken(string $token): ?ArticleShare;
}
