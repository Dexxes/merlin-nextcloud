<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Domain\Tag;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\TagStore;
use Merlin\Core\Service\TagTree;
use Psr\Log\LoggerInterface;

/**
 * Schlagwort-API (/api/tags…, /api/articles/{articleId}/tags/{tagId}) für
 * beide Varianten, Antworten wie bisher in OCA\Merlin\Controller\TagController.
 * Schlagworte sind verschachtelt (parentId, Service\TagTree).
 */
final class TagApi {
	public function __construct(
		private TagStore $tags,
		private ArticleStore $articles,
		private LoggerInterface $logger,
	) {
	}

	/** GET /api/tags */
	public function index(ApiRequest $request): ApiResult {
		return ApiResult::json(array_map(static fn (Tag $t) => $t->jsonSerialize(), $this->tags->findAll($request->userId())));
	}

	/** POST /api/tags: name, color, parentId (null/0 = oberste Ebene). */
	public function create(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		try {
			$name = $request->string('name');
			if ($name === null) {
				return ApiResult::error('Bad request', 400);
			}
			$parentId = $request->int('parentId');
			$parentId = ($parentId !== null && $parentId > 0) ? $parentId : null;
			if ($parentId !== null && $this->tags->find($parentId, $userId) === null) {
				return ApiResult::error('Bad request', 400);
			}

			$tag = new Tag();
			$tag->setUserId($userId);
			$tag->setName($name);
			$tag->setColor($request->string('color') ?? Tag::DEFAULT_COLOR);
			$tag->setParentId($parentId);
			$tag->setCreatedAt(new \DateTime());

			return ApiResult::json($this->tags->insert($tag)->jsonSerialize(), 201);
		} catch (\Exception $e) {
			$this->logger->error('Merlin: tag creation failed', ['exception' => $e]);
			return ApiResult::error('Bad request', 400);
		}
	}

	/**
	 * PUT /api/tags/{id}: name, color, parentId (0 = oberste Ebene, fehlt =
	 * unverändert). Weder das Schlagwort selbst noch eines seiner
	 * Unterschlagworte darf neues Elternteil werden.
	 */
	public function update(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$id = (int) $request->int('id');
		$tag = $this->tags->find($id, $userId);
		if ($tag === null) {
			return ApiResult::error('Not found', 404);
		}

		$parentId = $request->int('parentId');
		if ($parentId !== null) {
			$newParent = $parentId > 0 ? $parentId : null;
			if ($newParent !== null && $this->tags->find($newParent, $userId) === null) {
				return ApiResult::error('Parent tag not found', 400);
			}
			if (!TagTree::canMove($this->tags->parentMap($userId), $id, $newParent)) {
				return ApiResult::error('A tag cannot be moved below itself or one of its sub-tags', 400);
			}
			$tag->setParentId($newParent);
		}

		try {
			$name = $request->string('name');
			if ($name !== null) {
				$tag->setName($name);
			}
			$color = $request->string('color');
			if ($color !== null) {
				$tag->setColor($color);
			}
			return ApiResult::json($this->tags->update($tag)->jsonSerialize());
		} catch (\Exception $e) {
			$this->logger->error('Merlin: tag update failed', ['exception' => $e]);
			return ApiResult::error('Bad request', 400);
		}
	}

	/**
	 * DELETE /api/tags/{id}: samt Unterschlagworten; die Artikel bleiben.
	 * Antwort mit den ids aller gelöschten Schlagworte.
	 */
	public function destroy(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$id = (int) $request->int('id');
		try {
			if ($this->tags->find($id, $userId) === null) {
				return ApiResult::error('Not found', 404);
			}
			$ids = array_merge([$id], TagTree::descendantIds($this->tags->parentMap($userId), $id));
			$this->tags->deleteWithLinks($ids, $userId);
			return ApiResult::json(['success' => true, 'deletedIds' => $ids]);
		} catch (\Exception) {
			return ApiResult::error('Not found', 404);
		}
	}

	/** POST /api/articles/{articleId}/tags/{tagId} */
	public function addToArticle(ApiRequest $request): ApiResult {
		return $this->link($request, fn (int $articleId, int $tagId) => $this->tags->addToArticle($articleId, $tagId));
	}

	/** DELETE /api/articles/{articleId}/tags/{tagId} */
	public function removeFromArticle(ApiRequest $request): ApiResult {
		return $this->link($request, fn (int $articleId, int $tagId) => $this->tags->removeFromArticle($articleId, $tagId));
	}

	/** Schlagwort und Artikel müssen dem Nutzer gehören (sonst 400 wie bisher). */
	private function link(ApiRequest $request, callable $apply): ApiResult {
		$userId = $request->userId();
		$articleId = (int) $request->int('articleId');
		$tagId = (int) $request->int('tagId');
		try {
			if ($this->tags->find($tagId, $userId) === null || $this->articles->find($articleId, $userId) === null) {
				return ApiResult::error('Not found', 400);
			}
			$apply($articleId, $tagId);
			return ApiResult::json(['success' => true]);
		} catch (\Exception) {
			return ApiResult::error('Not found', 400);
		}
	}
}
