<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Domain\Article;
use Merlin\Core\Domain\FileNameTakenException;
use Merlin\Core\Domain\FileNotFoundException;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\TagStore;
use Merlin\Core\Service\FileEntries;
use Psr\Log\LoggerInterface;

/**
 * Dateien vom Handy in „Merlin Dateien“ (Service\FileEntries):
 *
 *   POST /api/files/target             Zielpfad für den WebDAV-Upload holen
 *   POST /api/files                    hochgeladene Datei als Eintrag anlegen
 *   PUT  /api/articles/{id}/file-name  Datei (und Eintrag) umbenennen
 *   GET  /api/articles/{id}/file       Datei bzw. Vorschaubild über signierten Link
 *
 * Antworten wie bisher im FileController der Nextcloud-App.
 */
final class FileApi {
	public function __construct(
		private FileEntries $files,
		private ArticleStore $articles,
		private TagStore $tags,
		private LoggerInterface $logger,
	) {
	}

	/** POST /api/files/target: name, mimeType → {path, davPath, uploadsPath, kind}. */
	public function target(ApiRequest $request): ApiResult {
		if (!$request->hasUser()) {
			return ApiResult::error('Not authenticated', 401);
		}
		try {
			return ApiResult::json($this->files->uploadTarget(
				$request->userId(),
				(string) $request->string('name', ''),
				(string) $request->string('mimeType', ''),
			));
		} catch (\Throwable $e) {
			$this->logger->error('Merlin: could not prepare file upload', ['exception' => $e]);
			return ApiResult::error('Could not prepare the Merlin files folder', 500);
		}
	}

	/**
	 * POST /api/files: path (aus target), optional tagIds (Query tagIds[] oder
	 * JSON) und text (per OCR erkannter Text eines Bildes, durchsuchbar).
	 */
	public function register(ApiRequest $request): ApiResult {
		if (!$request->hasUser()) {
			return ApiResult::error('Not authenticated', 401);
		}
		$userId = $request->userId();
		$path = (string) $request->string('path', '');
		if ($path === '') {
			return ApiResult::error('Missing path', 400);
		}
		try {
			$article = $this->files->register($userId, $path, $request->string('text'));
		} catch (FileNotFoundException) {
			return ApiResult::error('File not found', 404);
		} catch (\Throwable $e) {
			$this->logger->error('Merlin: could not register file', ['exception' => $e]);
			return ApiResult::error('Could not save the file entry', 500);
		}

		$rawTagIds = $request->param('tagIds');
		if (!is_array($rawTagIds)) {
			$rawTagIds = $request->jsonBody()['tagIds'] ?? null;
		}
		$tagIds = is_array($rawTagIds)
			? array_values(array_unique(array_filter(array_map('intval', $rawTagIds), static fn (int $v) => $v > 0)))
			: [];
		$existing = array_map(static fn ($tag) => (int) $tag->getId(), $this->tags->findByArticleId((int) $article->getId()));
		foreach (array_diff($tagIds, $existing) as $tagId) {
			try {
				// Nur eigene Schlagworte, Fehler ignorieren wie bei POST /api/articles.
				if ($this->tags->find($tagId, $userId) !== null) {
					$this->tags->addToArticle((int) $article->getId(), $tagId);
				}
			} catch (\Throwable) {
			}
		}
		return ApiResult::json($this->present($article), 201);
	}

	/**
	 * PUT /api/articles/{id}/file-name: name ohne Endung (die bleibt). 409,
	 * wenn der Name im Ordner schon vergeben ist.
	 */
	public function rename(ApiRequest $request): ApiResult {
		if (!$request->hasUser()) {
			return ApiResult::error('Not authenticated', 401);
		}
		$article = $this->articles->find((int) $request->int('id'), $request->userId());
		if ($article === null) {
			return ApiResult::error('Not found', 404);
		}
		if ($article->getFileId() === null) {
			return ApiResult::error('Not a file entry', 400);
		}
		try {
			$article = $this->files->rename($article, (string) $request->string('name', ''));
		} catch (\InvalidArgumentException) {
			return ApiResult::error('Missing name', 400);
		} catch (FileNameTakenException) {
			return ApiResult::error('A file with this name already exists', 409);
		} catch (FileNotFoundException) {
			return ApiResult::error('File not found', 404);
		} catch (\Throwable $e) {
			$this->logger->error('Merlin: could not rename file', ['exception' => $e]);
			return ApiResult::error('Could not rename the file', 500);
		}
		return ApiResult::json($this->present($article));
	}

	/**
	 * GET /api/articles/{id}/file: ohne Login, nur mit gültigem Token t.
	 * size = Vorschaubild, download=1 = als Anhang. Range für Video und PDF.
	 */
	public function content(ApiRequest $request): ApiResult {
		$article = $this->articles->findById((int) $request->int('id'));
		if ($article === null || !$this->files->verifyToken($article, (string) $request->string('t', ''))) {
			return ApiResult::error('Not found', 404);
		}
		$size = $request->int('size');
		$download = $request->int('download', 0) === 1;
		return ApiResult::passthrough(fn () => $this->files->streamFile($article, $size, $download));
	}

	/** @return array<string, mixed> */
	private function present(Article $article): array {
		$data = $article->jsonSerialize();
		$data['tags'] = array_map(static fn ($tag) => $tag->jsonSerialize(), $this->tags->findByArticleId((int) $article->getId()));
		return $data;
	}
}
