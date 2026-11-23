<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Domain\Article;
use Merlin\Core\Port\ArticleFiles;
use Merlin\Core\Port\ArticleStore;
use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Service\MerlinFileService;
use OCP\Files\NotFoundException;

/**
 * ArticleFiles-Port über Service\MerlinFileService (Nextcloud Files). Der
 * Service arbeitet mit dem Entity; danach wird der Eintrag über den Store
 * neu geladen.
 */
class NextcloudArticleFiles implements ArticleFiles {
	public function __construct(
		private MerlinFileService $files,
		private ArticleMapper $mapper,
		private ArticleStore $articles,
	) {
	}

	public function refreshIfNeeded(Article $article): Article {
		$entity = $this->mapper->find((int) $article->getId(), $article->getUserId());
		if (!$this->files->needsRefresh($entity)) {
			return $article;
		}
		$this->files->refresh($entity);
		return $this->articles->find((int) $article->getId(), $article->getUserId()) ?? $article;
	}

	public function refresh(Article $article): Article {
		try {
			$this->files->refresh($this->mapper->find((int) $article->getId(), $article->getUserId()));
		} catch (NotFoundException) {
			// Datei gelöscht: Eintrag bleibt, wie er ist.
			return $article;
		}
		return $this->articles->find((int) $article->getId(), $article->getUserId()) ?? $article;
	}

	/** Datei aus „Merlin Dateien“ direkt aus Nextcloud (MerlinFileService::stream). */
	public function stream(Article $article): never {
		$this->files->stream($this->mapper->find((int) $article->getId(), $article->getUserId()));
	}
}
