<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\ArticleShareMapper;
use OCA\Merlin\Db\Comment;
use OCA\Merlin\Db\CommentMapper;
use OCA\Merlin\Service\CommentException;
use OCA\Merlin\Service\CommentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * REST-API der Kommentare für den Besitzer eines Artikels (Web-Reader, iOS).
 *
 * Der Besitzer schreibt unter seinem Nextcloud-Anzeigenamen, bearbeitet nur
 * eigene Kommentare und löscht jeden Kommentar an seinem Artikel (Moderation,
 * auch Gast-Beiträge). Gast-Zugriffe laufen über PublicCommentController.
 *
 * Security note – NoCSRFRequired: siehe HighlightController (gleiches Muster:
 * native Clients nutzen Basic Auth statt Requesttoken; CsrfCookieAuthMiddleware
 * schützt den Cookie-Pfad).
 */
class CommentController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private CommentService $comments,
		private CommentMapper $commentMapper,
		private ArticleMapper $articleMapper,
		private ArticleShareMapper $shareMapper,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	private function unauthenticated(): DataResponse {
		return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
	}

	private function ownsArticle(int $articleId): bool {
		try {
			$this->articleMapper->find($articleId, (string) $this->userId);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	/**
	 * Threads und Markierungen eines Artikels samt Änderungsmarke für stream().
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(int $articleId): DataResponse {
		if ($this->userId === null) {
			return $this->unauthenticated();
		}
		if (!$this->ownsArticle($articleId)) {
			return new DataResponse(['error' => 'Article not found'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($this->comments->payload($articleId, $this->userId));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(int $articleId, string $body = '', ?int $highlightId = null, ?int $parentId = null): DataResponse {
		if ($this->userId === null) {
			return $this->unauthenticated();
		}
		if (!$this->ownsArticle($articleId)) {
			return new DataResponse(['error' => 'Article not found'], Http::STATUS_NOT_FOUND);
		}
		try {
			$comment = $this->comments->createComment(
				$articleId,
				$this->userId,
				Comment::AUTHOR_OWNER,
				$this->comments->ownerDisplayName($this->userId),
				$body,
				$highlightId,
				$parentId,
			);
		} catch (CommentException $e) {
			return new DataResponse(['error' => $e->getErrorCode()], $e->getStatus());
		}
		return new DataResponse($comment->jsonSerialize(), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(int $id, string $body = ''): DataResponse {
		if ($this->userId === null) {
			return $this->unauthenticated();
		}
		try {
			$comment = $this->commentMapper->findForOwner($id, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		// Gast-Texte verändert der Besitzer nicht, er kann sie nur löschen.
		if ($comment->getAuthorType() !== Comment::AUTHOR_OWNER) {
			return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}
		try {
			$saved = $this->comments->updateComment($comment, $body);
		} catch (CommentException $e) {
			return new DataResponse(['error' => $e->getErrorCode()], $e->getStatus());
		}
		return new DataResponse($saved->jsonSerialize());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(int $id): DataResponse {
		if ($this->userId === null) {
			return $this->unauthenticated();
		}
		try {
			$comment = $this->commentMapper->findForOwner($id, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		$this->comments->deleteComment($comment);
		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * Push-Kanal (Server-Sent Events) für den Reader des Besitzers: liefert
	 * Gast-Kommentare und -Markierungen sofort, ohne Polling. Ohne Share-Link
	 * kann niemand sonst schreiben – dann endet der Kanal gleich mit `closed`,
	 * und der Client verbindet erst nach dem Anlegen eines Links neu.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stream(int $articleId, string $since = ''): void {
		if ($this->userId === null || !$this->ownsArticle($articleId)) {
			http_response_code($this->userId === null ? 401 : 404);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'Not found']);
			exit();
		}
		$userId = $this->userId;
		$lastEventId = $this->request->getHeader('Last-Event-ID');
		$this->comments->stream(
			$articleId,
			$userId,
			$lastEventId !== '' ? $lastEventId : $since,
			function () use ($articleId, $userId): bool {
				try {
					$this->shareMapper->findByArticleId($articleId, $userId);
					return true;
				} catch (DoesNotExistException) {
					return false;
				}
			},
		);
	}
}
