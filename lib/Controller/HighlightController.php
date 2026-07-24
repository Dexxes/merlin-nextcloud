<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\Highlight;
use OCA\Merlin\Db\HighlightMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * REST API for highlights.
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
class HighlightController extends Controller {
	private HighlightMapper $highlightMapper;
	private ArticleMapper $articleMapper;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		HighlightMapper $highlightMapper,
		ArticleMapper $articleMapper,
		?string $userId
	) {
		parent::__construct($appName, $request);
		$this->highlightMapper = $highlightMapper;
		$this->articleMapper = $articleMapper;
		$this->userId = $userId;
	}

	/**
	 * List all highlights for an article.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(int $articleId): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		$highlights = $this->highlightMapper->findByArticleId($articleId, $this->userId);
		return new DataResponse(array_map(fn($h) => $h->jsonSerialize(), $highlights));
	}

	/**
	 * Create a new highlight.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(
		int    $articleId,
		string $highlightedText,
		string $startXpath,
		int    $startOffset,
		string $endXpath,
		int    $endOffset,
		string $color = 'yellow'
	): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Ownership-Prüfung: Der Artikel muss dem Nutzer gehören, bevor ein
		// Highlight daran gespeichert wird (verhindert Highlights auf fremden/
		// geratenen article_ids – analog zu TagController::addToArticle()).
		try {
			$this->articleMapper->find($articleId, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Article not found'], Http::STATUS_NOT_FOUND);
		}

		$highlight = new Highlight();
		$highlight->setUserId($this->userId);
		$highlight->setArticleId($articleId);
		$highlight->setHighlightedText($highlightedText);
		$highlight->setStartXpath($startXpath);
		$highlight->setStartOffset($startOffset);
		$highlight->setEndXpath($endXpath);
		$highlight->setEndOffset($endOffset);
		$highlight->setColor($color);
		$highlight->setCreatedAt(new \DateTime());

		$saved = $this->highlightMapper->insert($highlight);
		return new DataResponse($saved->jsonSerialize(), Http::STATUS_CREATED);
	}

	/**
	 * Delete a highlight.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(int $id): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->highlightMapper->findById($id, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		$this->highlightMapper->deleteById($id, $this->userId);
		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}
}
