<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Domain\Article;
use Merlin\Core\Port\ArticleFiles;

/**
 * ArticleFiles-Port ohne „Merlin Dateien“ (kommt im Standalone-Server
 * später): es gibt keine Datei-Einträge, also nichts neu aufzubauen.
 */
final class NoArticleFiles implements ArticleFiles {
	public function refreshIfNeeded(Article $article): Article {
		return $article;
	}

	public function refresh(Article $article): Article {
		return $article;
	}
}
