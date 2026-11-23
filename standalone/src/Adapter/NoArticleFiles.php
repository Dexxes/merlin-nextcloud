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

	/** Ohne Datei-Einträge hat kein Artikel eine fileId; nur zur Sicherheit. */
	public function stream(Article $article): never {
		http_response_code(404);
		header('Content-Type: application/json');
		echo json_encode(['error' => 'File not found']);
		exit();
	}
}
