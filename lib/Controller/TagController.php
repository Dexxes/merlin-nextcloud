<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\Tag;
use OCA\Merlin\Db\TagMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class TagController extends Controller {
	private TagMapper $tagMapper;
	private ArticleMapper $articleMapper;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		TagMapper $tagMapper,
		ArticleMapper $articleMapper,
		?string $userId
	) {
		parent::__construct($appName, $request);
		$this->tagMapper = $tagMapper;
		$this->articleMapper = $articleMapper;
		$this->userId = $userId;
	}

	/**
	 * Get all tags
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): DataResponse {
		$tags = $this->tagMapper->findAll($this->userId);
		return new DataResponse(array_map(fn($tag) => $tag->jsonSerialize(), $tags));
	}

	/**
	 * Create new tag
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(string $name, ?string $color = null): DataResponse {
		try {
			$tag = new Tag();
			$tag->setUserId($this->userId);
			$tag->setName($name);
			$tag->setColor($color ?? '#0082c9');
			$tag->setCreatedAt(new \DateTime());

			$savedTag = $this->tagMapper->insert($tag);

			return new DataResponse($savedTag->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update tag
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(int $id, ?string $name = null, ?string $color = null): DataResponse {
		try {
			$tag = $this->tagMapper->find($id, $this->userId);

			if ($name !== null) {
				$tag->setName($name);
			}
			if ($color !== null) {
				$tag->setColor($color);
			}

			$updatedTag = $this->tagMapper->update($tag);

			return new DataResponse($updatedTag->jsonSerialize());
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Delete tag
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(int $id): DataResponse {
		try {
			$tag = $this->tagMapper->find($id, $this->userId);
			$this->tagMapper->delete($tag);

			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Add tag to article
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function addToArticle(int $articleId, int $tagId): DataResponse {
		try {
			// Verify tag belongs to user
			$this->tagMapper->find($tagId, $this->userId);
			// Verify article belongs to user (prevents IDOR via guessable article IDs)
			$this->articleMapper->find($articleId, $this->userId);

			$this->tagMapper->addToArticle($articleId, $tagId);

			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Remove tag from article
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function removeFromArticle(int $articleId, int $tagId): DataResponse {
		try {
			// Verify tag belongs to user
			$this->tagMapper->find($tagId, $this->userId);
			// Verify article belongs to user (prevents IDOR via guessable article IDs)
			$this->articleMapper->find($articleId, $this->userId);

			$this->tagMapper->removeFromArticle($articleId, $tagId);

			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
