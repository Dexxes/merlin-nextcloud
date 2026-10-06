<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleShare;
use OCA\Merlin\Db\Comment;
use OCA\Merlin\Db\CommentMapper;
use OCA\Merlin\Db\HighlightMapper;
use OCA\Merlin\Service\CommentException;
use OCA\Merlin\Service\CommentRules;
use OCA\Merlin\Service\CommentService;
use OCA\Merlin\Service\ShareAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Markieren und Kommentieren hinter einem öffentlichen Share-Link – ohne Login.
 *
 * Gäste weisen sich allein über ihren Namen aus (Wunsch des Projekts, keine
 * Registrierung, kein Schlüssel): beim Anlegen kommt der Name als
 * `authorName` mit, beim Bearbeiten/Löschen im Header X-Merlin-Guest-Name.
 * Wer denselben Namen angibt, darf alles ändern, was unter diesem Namen steht
 * (CommentRules::guestMayEdit()). Beiträge des Besitzers kann kein Gast
 * ändern, und seinen Namen kann kein Gast wählen.
 *
 * Schutz: Token + Passwort-Unlock (ShareAccessService), Schalter
 * allow_comments am Link, Rate-Limit je IP über #[AnonRateLimit], Obergrenze
 * je Artikel und Tag (CommentRules::GUEST_DAILY_CAP) und ein Honeypot-Feld
 * `website`, das Menschen nie ausfüllen.
 */
class PublicCommentController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ShareAccessService $access,
		private CommentService $comments,
		private CommentMapper $commentMapper,
		private HighlightMapper $highlightMapper,
	) {
		parent::__construct($appName, $request);
	}

	private function error(CommentException $e): DataResponse {
		return new DataResponse(['error' => $e->getErrorCode()], $e->getStatus());
	}

	/**
	 * Share für einen Schreibzugriff: gültig, entsperrt und für Gäste offen.
	 */
	private function writableShare(string $token): ArticleShare|DataResponse {
		$share = $this->access->resolveAccessibleShare($token);
		if ($share instanceof DataResponse) {
			return $share;
		}
		if (!$share->allowsComments()) {
			return new DataResponse(['error' => 'comments_closed'], Http::STATUS_FORBIDDEN);
		}
		if (trim((string) $this->request->getParam('website', '')) !== '') {
			return new DataResponse(['error' => 'rejected'], Http::STATUS_BAD_REQUEST);
		}
		return $share;
	}

	private function guestHeaderName(): string {
		return rawurldecode($this->request->getHeader('X-Merlin-Guest-Name'));
	}

	/**
	 * Alle Threads und Markierungen (wie in data(), für ein Neuladen).
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $token): DataResponse {
		$share = $this->access->resolveAccessibleShare($token);
		if ($share instanceof DataResponse) {
			return $share;
		}
		return new DataResponse($this->comments->payload($share->getArticleId(), $share->getUserId()));
	}

	/**
	 * Namen wählen: prüft ihn und legt seine Farbe fest (bzw. liefert die
	 * schon vergebene). Antwort { name, color }.
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function guest(string $token, string $authorName = '', string $color = ''): DataResponse {
		$share = $this->writableShare($token);
		if ($share instanceof DataResponse) {
			return $share;
		}
		try {
			$name = $this->comments->guestName($authorName, $share->getUserId());
			$color = $this->comments->claimGuestColor($share->getArticleId(), $share->getUserId(), $name, $color);
		} catch (CommentException $e) {
			return $this->error($e);
		}
		return new DataResponse(['name' => $name, 'color' => $color]);
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function create(string $token, string $authorName = '', string $body = '', ?int $highlightId = null, ?int $parentId = null, ?array $anchor = null): DataResponse {
		$share = $this->writableShare($token);
		if ($share instanceof DataResponse) {
			return $share;
		}
		try {
			$name = $this->comments->guestName($authorName, $share->getUserId());
			$this->comments->assertGuestCapacity($share->getArticleId(), $share->getUserId(), false);
			// Gäste ohne Farbe (z. B. ein Client ohne Farbauswahl) bekommen die
			// erste freie.
			$this->comments->claimGuestColor($share->getArticleId(), $share->getUserId(), $name, null);
			if ($anchor !== null) {
				$this->comments->assertGuestCapacity($share->getArticleId(), $share->getUserId(), true);
			}
			$comment = $this->comments->createComment(
				$share->getArticleId(),
				$share->getUserId(),
				Comment::AUTHOR_GUEST,
				$name,
				$body,
				$highlightId,
				$parentId,
				$anchor,
			);
		} catch (CommentException $e) {
			return $this->error($e);
		}
		return new DataResponse($comment->jsonSerialize(), Http::STATUS_CREATED);
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function update(string $token, int $id, string $body = ''): DataResponse {
		$share = $this->writableShare($token);
		if ($share instanceof DataResponse) {
			return $share;
		}
		try {
			$comment = $this->commentMapper->findInArticle($id, $share->getArticleId(), $share->getUserId());
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		if (!CommentRules::guestMayEdit($comment->getAuthorType(), $comment->getAuthorNameKey(), $this->guestHeaderName())) {
			return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}
		try {
			$saved = $this->comments->updateComment($comment, $body);
		} catch (CommentException $e) {
			return $this->error($e);
		}
		return new DataResponse($saved->jsonSerialize());
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function destroy(string $token, int $id): DataResponse {
		$share = $this->writableShare($token);
		if ($share instanceof DataResponse) {
			return $share;
		}
		try {
			$comment = $this->commentMapper->findInArticle($id, $share->getArticleId(), $share->getUserId());
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		if (!CommentRules::guestMayEdit($comment->getAuthorType(), $comment->getAuthorNameKey(), $this->guestHeaderName())) {
			return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}
		$this->comments->deleteComment($comment);
		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function createHighlight(
		string $token,
		string $authorName = '',
		string $highlightedText = '',
		string $startXpath = '',
		int $startOffset = 0,
		string $endXpath = '',
		int $endOffset = 0,
		string $color = 'yellow',
	): DataResponse {
		$share = $this->writableShare($token);
		if ($share instanceof DataResponse) {
			return $share;
		}
		try {
			$name = $this->comments->guestName($authorName, $share->getUserId());
			$this->comments->assertGuestCapacity($share->getArticleId(), $share->getUserId(), true);
			$this->comments->claimGuestColor($share->getArticleId(), $share->getUserId(), $name, null);
			$highlight = $this->comments->createGuestHighlight(
				$share->getArticleId(),
				$share->getUserId(),
				$name,
				$highlightedText,
				$startXpath,
				$startOffset,
				$endXpath,
				$endOffset,
				$color,
			);
		} catch (CommentException $e) {
			return $this->error($e);
		}
		return new DataResponse($highlight->jsonSerialize(), Http::STATUS_CREATED);
	}

	/**
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function destroyHighlight(string $token, int $id): DataResponse {
		$share = $this->writableShare($token);
		if ($share instanceof DataResponse) {
			return $share;
		}
		try {
			$highlight = $this->highlightMapper->findInArticle($id, $share->getArticleId(), $share->getUserId());
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		if (!CommentRules::guestMayEdit((string) $highlight->getAuthorType(), $highlight->getAuthorNameKey(), $this->guestHeaderName())) {
			return new DataResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}
		$this->comments->deleteHighlight($highlight);
		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * Push-Kanal für die öffentliche Ansicht: neue, geänderte und gelöschte
	 * Kommentare und Markierungen kommen ohne Polling an (siehe
	 * CommentService::stream()). `since` ist die Änderungsmarke aus data();
	 * beim automatischen Neuverbinden schickt EventSource stattdessen die
	 * zuletzt empfangene Marke als Last-Event-ID.
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function events(string $token, string $since = ''): void {
		$share = $this->access->resolveAccessibleShare($token);
		if ($share instanceof DataResponse) {
			http_response_code($share->getStatus());
			header('Content-Type: application/json');
			echo json_encode($share->getData());
			exit();
		}
		$lastEventId = $this->request->getHeader('Last-Event-ID');
		$this->comments->stream(
			$share->getArticleId(),
			$share->getUserId(),
			$lastEventId !== '' ? $lastEventId : $since,
			fn () => $this->access->stillValid($token),
		);
	}
}
