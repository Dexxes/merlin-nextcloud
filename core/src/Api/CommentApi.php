<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Domain\Comment;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\CommentStore;
use Merlin\Core\Port\ShareStore;
use Merlin\Core\Service\CommentException;
use Merlin\Core\Service\CommentService;

/**
 * Kommentare für den Besitzer eines Artikels (Web-Reader, iOS), Antworten
 * wie bisher in OCA\Merlin\Controller\CommentController.
 *
 * Der Besitzer schreibt unter seinem Anzeigenamen, bearbeitet nur eigene
 * Kommentare und löscht jeden Kommentar an seinem Artikel (Moderation, auch
 * Gast-Beiträge). Gäste schreiben über PublicCommentApi.
 */
final class CommentApi {
	public function __construct(
		private CommentService $comments,
		private CommentStore $commentStore,
		private ArticleStore $articles,
		private ShareStore $shares,
	) {
	}

	/** GET /api/articles/{articleId}/comments: Threads, Markierungen, Änderungsmarke. */
	public function index(ApiRequest $request): ApiResult {
		$articleId = (int) $request->int('articleId');
		if ($this->articles->find($articleId, $request->userId()) === null) {
			return ApiResult::error('Article not found', 404);
		}
		return ApiResult::json($this->comments->payload($articleId, $request->userId()));
	}

	/** POST /api/articles/{articleId}/comments: body, highlightId, parentId, anchor. */
	public function create(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$articleId = (int) $request->int('articleId');
		if ($this->articles->find($articleId, $userId) === null) {
			return ApiResult::error('Article not found', 404);
		}
		$anchor = $request->param('anchor');
		try {
			$comment = $this->comments->createComment(
				$articleId,
				$userId,
				Comment::AUTHOR_OWNER,
				$this->comments->ownerDisplayName($userId),
				(string) $request->string('body', ''),
				$request->int('highlightId'),
				$request->int('parentId'),
				is_array($anchor) ? $anchor : null,
			);
		} catch (CommentException $e) {
			return ApiResult::error($e->getErrorCode(), $e->getStatus());
		}
		return ApiResult::json($comment->jsonSerialize(), 201);
	}

	/** PUT /api/comments/{id}: body. Gast-Texte ändert der Besitzer nicht, er löscht sie nur. */
	public function update(ApiRequest $request): ApiResult {
		$comment = $this->commentStore->findForOwner((int) $request->int('id'), $request->userId());
		if ($comment === null) {
			return ApiResult::error('not_found', 404);
		}
		if ($comment->getAuthorType() !== Comment::AUTHOR_OWNER) {
			return ApiResult::error('forbidden', 403);
		}
		try {
			$saved = $this->comments->updateComment($comment, (string) $request->string('body', ''));
		} catch (CommentException $e) {
			return ApiResult::error($e->getErrorCode(), $e->getStatus());
		}
		return ApiResult::json($saved->jsonSerialize());
	}

	/** DELETE /api/comments/{id} */
	public function destroy(ApiRequest $request): ApiResult {
		$comment = $this->commentStore->findForOwner((int) $request->int('id'), $request->userId());
		if ($comment === null) {
			return ApiResult::error('not_found', 404);
		}
		$this->comments->deleteComment($comment);
		return ApiResult::noContent();
	}

	/**
	 * GET /api/articles/{articleId}/comments/stream?since=: Server-Sent Events
	 * für den Reader des Besitzers, Gast-Kommentare und -Markierungen kommen
	 * ohne Polling. Ohne Share-Link kann niemand sonst schreiben: dann endet
	 * der Kanal mit `closed`, und der Client verbindet erst nach dem Anlegen
	 * eines Links neu. Beim Neuverbinden gilt Last-Event-ID statt since.
	 */
	public function stream(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$articleId = (int) $request->int('articleId');
		if ($this->articles->find($articleId, $userId) === null) {
			return ApiResult::error('Not found', 404);
		}
		$lastEventId = $request->header('Last-Event-ID');
		$since = $lastEventId !== '' ? $lastEventId : (string) $request->string('since', '');
		return ApiResult::stream(fn () => $this->comments->stream(
			$articleId,
			$userId,
			$since,
			fn (): bool => $this->shares->findByArticleId($articleId, $userId) !== null,
		));
	}
}
