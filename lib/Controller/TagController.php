<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\Tag;
use OCA\Merlin\Db\TagMapper;
use OCA\Merlin\Service\TagTree;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * REST API for tags.
 *
 * Security note – NoCSRFRequired:
 * All API routes carry #[NoCSRFRequired] because the same endpoints serve
 * both the Vue web-UI (session cookie) and native clients (iOS, Android,
 * browser extensions) that authenticate via HTTP Basic Auth and cannot
 * supply a Nextcloud requesttoken. Removing the attribute would break all
 * native clients. CSRF protection for the cookie-authenticated web-UI path is
 * instead enforced centrally by CsrfCookieAuthMiddleware, which demands a
 * valid requesttoken for state-changing browser requests while skipping
 * Basic/Bearer-authenticated (native) requests and safe methods. SameSite=Lax
 * session cookies remain as an additional layer of defense.
 */
class TagController extends Controller {
	private TagMapper $tagMapper;
	private ArticleMapper $articleMapper;
	private LoggerInterface $logger;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		TagMapper $tagMapper,
		ArticleMapper $articleMapper,
		LoggerInterface $logger,
		?string $userId
	) {
		parent::__construct($appName, $request);
		$this->tagMapper = $tagMapper;
		$this->articleMapper = $articleMapper;
		$this->logger = $logger;
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
	public function create(string $name, ?string $color = null, ?int $parentId = null): DataResponse {
		try {
			// Nested tags: parentId null/0 = top level, otherwise one of the user's tags.
			$parentId = ($parentId !== null && $parentId > 0) ? $parentId : null;
			if ($parentId !== null) {
				$this->tagMapper->find($parentId, $this->userId);
			}

			$tag = new Tag();
			$tag->setUserId($this->userId);
			$tag->setName($name);
			$tag->setColor($color ?? '#0082c9');
			$tag->setParentId($parentId);
			$tag->setCreatedAt(new \DateTime());

			$savedTag = $this->tagMapper->insert($tag);

			return new DataResponse($savedTag->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			$this->logger->error('Merlin: tag creation failed', ['exception' => $e]);
			return new DataResponse(['error' => 'Bad request'], Http::STATUS_BAD_REQUEST);
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
	public function update(int $id, ?string $name = null, ?string $color = null, ?int $parentId = null): DataResponse {
		try {
			$tag = $this->tagMapper->find($id, $this->userId);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		// Move: parentId 0 = top level, null = leave as is. Neither the tag
		// itself nor one of its descendants may become its parent.
		if ($parentId !== null) {
			$newParent = $parentId > 0 ? $parentId : null;
			try {
				if ($newParent !== null) {
					$this->tagMapper->find($newParent, $this->userId);
				}
			} catch (\Exception $e) {
				return new DataResponse(['error' => 'Parent tag not found'], Http::STATUS_BAD_REQUEST);
			}
			if (!TagTree::canMove($this->tagMapper->findParentMap($this->userId), $id, $newParent)) {
				return new DataResponse(['error' => 'A tag cannot be moved below itself or one of its sub-tags'], Http::STATUS_BAD_REQUEST);
			}
			$tag->setParentId($newParent);
		}

		try {
			if ($name !== null) {
				$tag->setName($name);
			}
			if ($color !== null) {
				$tag->setColor($color);
			}

			$updatedTag = $this->tagMapper->update($tag);

			return new DataResponse($updatedTag->jsonSerialize());
		} catch (\Exception $e) {
			$this->logger->error('Merlin: tag update failed', ['exception' => $e]);
			return new DataResponse(['error' => 'Bad request'], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete tag together with all its sub-tags (nested tags). The articles
	 * stay, only their tag links go. Responds with the ids of all deleted tags.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(int $id): DataResponse {
		try {
			$this->tagMapper->find($id, $this->userId);
			$ids = array_merge([$id], TagTree::descendantIds($this->tagMapper->findParentMap($this->userId), $id));
			$this->tagMapper->deleteWithLinks($ids, $this->userId);

			return new DataResponse(['success' => true, 'deletedIds' => $ids]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
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
			return new DataResponse(['error' => 'Not found'], Http::STATUS_BAD_REQUEST);
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
			return new DataResponse(['error' => 'Not found'], Http::STATUS_BAD_REQUEST);
		}
	}
}
