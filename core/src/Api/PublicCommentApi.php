<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Domain\ArticleShare;
use Merlin\Core\Domain\Comment;
use Merlin\Core\Port\CommentStore;
use Merlin\Core\Port\HighlightStore;
use Merlin\Core\Service\CommentException;
use Merlin\Core\Service\CommentRules;
use Merlin\Core\Service\CommentService;
use Merlin\Core\Service\ShareAccessDenied;
use Merlin\Core\Service\ShareAccessService;

/**
 * Markieren und Kommentieren hinter einem öffentlichen Share-Link, ohne
 * Anmeldung (/s/{token}/…), Antworten wie bisher in
 * OCA\Merlin\Controller\PublicCommentController.
 *
 * Gäste weisen sich allein über ihren Namen aus (keine Registrierung, kein
 * Schlüssel): beim Anlegen als `authorName`, beim Bearbeiten und Löschen im
 * Header X-Merlin-Guest-Name. Wer denselben Namen angibt, darf alles ändern,
 * was unter diesem Namen steht (CommentRules::guestMayEdit()). Beiträge des
 * Besitzers ändert kein Gast, und seinen Namen kann kein Gast wählen.
 *
 * Schutz: Token und Passwort-Unlock (ShareAccessService), Schalter
 * allow_comments am Link, Rate-Limit je IP ('rateLimit' in der
 * Routentabelle), Obergrenze je Artikel und Tag
 * (CommentRules::GUEST_DAILY_CAP) und ein Honeypot-Feld `website`, das
 * Menschen nie ausfüllen.
 */
final class PublicCommentApi {
	public function __construct(
		private ShareAccessService $access,
		private CommentService $comments,
		private CommentStore $commentStore,
		private HighlightStore $highlightStore,
	) {
	}

	/** GET /s/{token}/comments: alle Threads und Markierungen (wie in data()). */
	public function index(ApiRequest $request): ApiResult {
		$share = $this->access->resolveAccessibleShare((string) $request->string('token', ''));
		if ($share instanceof ShareAccessDenied) {
			return ApiResult::json($share->getData(), $share->getStatus());
		}
		return ApiResult::json($this->comments->payload($share->getArticleId(), $share->getUserId()));
	}

	/** POST /s/{token}/guest: authorName, color. Prüft den Namen und vergibt seine Farbe. */
	public function guest(ApiRequest $request): ApiResult {
		$share = $this->writableShare($request);
		if ($share instanceof ApiResult) {
			return $share;
		}
		try {
			$name = $this->comments->guestName((string) $request->string('authorName', ''), $share->getUserId());
			$color = $this->comments->claimGuestColor($share->getArticleId(), $share->getUserId(), $name, (string) $request->string('color', ''));
		} catch (CommentException $e) {
			return self::error($e);
		}
		return ApiResult::json(['name' => $name, 'color' => $color]);
	}

	/** POST /s/{token}/comments: authorName, body, highlightId, parentId, anchor. */
	public function create(ApiRequest $request): ApiResult {
		$share = $this->writableShare($request);
		if ($share instanceof ApiResult) {
			return $share;
		}
		$anchor = $request->param('anchor');
		$anchor = is_array($anchor) ? $anchor : null;
		try {
			$name = $this->comments->guestName((string) $request->string('authorName', ''), $share->getUserId());
			$this->comments->assertGuestCapacity($share->getArticleId(), $share->getUserId(), false);
			// Gäste ohne Farbe (z. B. ein Client ohne Farbauswahl) bekommen die erste freie.
			$this->comments->claimGuestColor($share->getArticleId(), $share->getUserId(), $name, null);
			if ($anchor !== null) {
				$this->comments->assertGuestCapacity($share->getArticleId(), $share->getUserId(), true);
			}
			$comment = $this->comments->createComment(
				$share->getArticleId(),
				$share->getUserId(),
				Comment::AUTHOR_GUEST,
				$name,
				(string) $request->string('body', ''),
				$request->int('highlightId'),
				$request->int('parentId'),
				$anchor,
			);
		} catch (CommentException $e) {
			return self::error($e);
		}
		return ApiResult::json($comment->jsonSerialize(), 201);
	}

	/** PUT /s/{token}/comments/{id}: body. Nur unter dem eigenen Namen (Header). */
	public function update(ApiRequest $request): ApiResult {
		$share = $this->writableShare($request);
		if ($share instanceof ApiResult) {
			return $share;
		}
		$comment = $this->commentStore->findInArticle((int) $request->int('id'), $share->getArticleId(), $share->getUserId());
		if ($comment === null) {
			return ApiResult::error('not_found', 404);
		}
		if (!CommentRules::guestMayEdit($comment->getAuthorType(), $comment->getAuthorNameKey(), self::guestHeaderName($request))) {
			return ApiResult::error('forbidden', 403);
		}
		try {
			$saved = $this->comments->updateComment($comment, (string) $request->string('body', ''));
		} catch (CommentException $e) {
			return self::error($e);
		}
		return ApiResult::json($saved->jsonSerialize());
	}

	/** DELETE /s/{token}/comments/{id} */
	public function destroy(ApiRequest $request): ApiResult {
		$share = $this->writableShare($request);
		if ($share instanceof ApiResult) {
			return $share;
		}
		$comment = $this->commentStore->findInArticle((int) $request->int('id'), $share->getArticleId(), $share->getUserId());
		if ($comment === null) {
			return ApiResult::error('not_found', 404);
		}
		if (!CommentRules::guestMayEdit($comment->getAuthorType(), $comment->getAuthorNameKey(), self::guestHeaderName($request))) {
			return ApiResult::error('forbidden', 403);
		}
		$this->comments->deleteComment($comment);
		return ApiResult::noContent();
	}

	/**
	 * POST /s/{token}/highlights: authorName, highlightedText, startXpath,
	 * startOffset, endXpath, endOffset, color.
	 */
	public function createHighlight(ApiRequest $request): ApiResult {
		$share = $this->writableShare($request);
		if ($share instanceof ApiResult) {
			return $share;
		}
		try {
			$name = $this->comments->guestName((string) $request->string('authorName', ''), $share->getUserId());
			$this->comments->assertGuestCapacity($share->getArticleId(), $share->getUserId(), true);
			$this->comments->claimGuestColor($share->getArticleId(), $share->getUserId(), $name, null);
			$highlight = $this->comments->createGuestHighlight(
				$share->getArticleId(),
				$share->getUserId(),
				$name,
				(string) $request->string('highlightedText', ''),
				(string) $request->string('startXpath', ''),
				(int) $request->int('startOffset', 0),
				(string) $request->string('endXpath', ''),
				(int) $request->int('endOffset', 0),
				(string) $request->string('color', 'yellow'),
			);
		} catch (CommentException $e) {
			return self::error($e);
		}
		return ApiResult::json($highlight->jsonSerialize(), 201);
	}

	/** DELETE /s/{token}/highlights/{id} */
	public function destroyHighlight(ApiRequest $request): ApiResult {
		$share = $this->writableShare($request);
		if ($share instanceof ApiResult) {
			return $share;
		}
		$highlight = $this->highlightStore->findInArticle((int) $request->int('id'), $share->getArticleId(), $share->getUserId());
		if ($highlight === null) {
			return ApiResult::error('not_found', 404);
		}
		if (!CommentRules::guestMayEdit((string) $highlight->getAuthorType(), $highlight->getAuthorNameKey(), self::guestHeaderName($request))) {
			return ApiResult::error('forbidden', 403);
		}
		$this->comments->deleteHighlight($highlight);
		return ApiResult::noContent();
	}

	/**
	 * GET /s/{token}/events?since=: Push-Kanal der öffentlichen Ansicht (siehe
	 * CommentService::stream()). `since` ist die Änderungsmarke aus data();
	 * beim Neuverbinden schickt EventSource die zuletzt empfangene als
	 * Last-Event-ID.
	 */
	public function events(ApiRequest $request): ApiResult {
		$token = (string) $request->string('token', '');
		$share = $this->access->resolveAccessibleShare($token);
		if ($share instanceof ShareAccessDenied) {
			return ApiResult::json($share->getData(), $share->getStatus());
		}
		$lastEventId = $request->header('Last-Event-ID');
		$since = $lastEventId !== '' ? $lastEventId : (string) $request->string('since', '');
		return ApiResult::stream(fn () => $this->comments->stream(
			$share->getArticleId(),
			$share->getUserId(),
			$since,
			fn (): bool => $this->access->stillValid($token),
		));
	}

	/** Share für einen Schreibzugriff: gültig, entsperrt und für Gäste offen. */
	private function writableShare(ApiRequest $request): ArticleShare|ApiResult {
		$share = $this->access->resolveAccessibleShare((string) $request->string('token', ''));
		if ($share instanceof ShareAccessDenied) {
			return ApiResult::json($share->getData(), $share->getStatus());
		}
		if (!$share->allowsComments()) {
			return ApiResult::error('comments_closed', 403);
		}
		if (trim((string) $request->string('website', '')) !== '') {
			return ApiResult::error('rejected', 400);
		}
		return $share;
	}

	private static function guestHeaderName(ApiRequest $request): string {
		return rawurldecode($request->header('X-Merlin-Guest-Name'));
	}

	private static function error(CommentException $e): ApiResult {
		return ApiResult::error($e->getErrorCode(), $e->getStatus());
	}
}
