<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\Db\Comment;
use OCA\Merlin\Db\CommentMapper;
use OCA\Merlin\Db\Highlight;
use OCA\Merlin\Db\HighlightMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * Gemeinsame Logik für Kommentare und Gast-Markierungen, benutzt vom
 * eingeloggten CommentController und vom öffentlichen PublicCommentController.
 *
 * Alle Methoden bekommen Artikel-ID und Besitzer vom Aufrufer, der sie aus dem
 * Login bzw. dem Share-Token kennt – nie aus dem Request.
 */
class CommentService {
	/** Pause zwischen zwei Prüfungen im Push-Kanal (Mikrosekunden). */
	private const STREAM_TICK_US = 1_000_000;
	/** Laufzeit einer Push-Verbindung; danach verbindet EventSource neu. */
	private const STREAM_SECONDS = 50;

	public function __construct(
		private CommentMapper $commentMapper,
		private HighlightMapper $highlightMapper,
		private IUserManager $userManager,
	) {
	}

	public function ownerDisplayName(string $ownerId): string {
		$user = $this->userManager->get($ownerId);
		$name = $user !== null ? $user->getDisplayName() : '';
		return $name !== '' ? $name : $ownerId;
	}

	/**
	 * Änderungsmarke über Kommentare und Markierungen eines Artikels.
	 */
	public function signature(int $articleId, string $ownerId): string {
		// Kurz und URL-/Header-tauglich (geht als ?since= und Last-Event-ID zurück).
		return substr(sha1(
			$this->commentMapper->signature($articleId, $ownerId)
			. '|' . $this->highlightMapper->signature($articleId, $ownerId)
		), 0, 20);
	}

	/**
	 * `generatedAt` (Mikrosekunden, vor dem Lesen genommen) ordnet die Stände:
	 * Antwort auf ein Neuladen und Push-Ereignis können sich überholen, und
	 * ein Proxy kann Ereignisse puffern und verspätet ausliefern. Clients
	 * verwerfen deshalb jeden Stand, der älter ist als der zuletzt gezeigte –
	 * sonst taucht z. B. ein gerade gelöschter Kommentar kurz wieder auf.
	 *
	 * @return array{signature: string, generatedAt: int, comments: array, highlights: array}
	 */
	public function payload(int $articleId, string $ownerId): array {
		$generatedAt = (int)floor(microtime(true) * 1000000);
		// Marke vor den Daten lesen: ändert sich dazwischen etwas, ist die
		// Marke älter als die Daten und der nächste Push liefert nach.
		$signature = $this->signature($articleId, $ownerId);
		$comments = array_map(
			fn (Comment $c) => $c->jsonSerialize(),
			$this->commentMapper->findByArticleId($articleId, $ownerId)
		);
		$highlights = array_map(
			fn (Highlight $h) => $h->jsonSerialize(),
			$this->highlightMapper->findByArticleId($articleId, $ownerId)
		);
		return [
			'signature'   => $signature,
			'generatedAt' => $generatedAt,
			'comments'    => CommentRules::nest($comments),
			'highlights'  => $highlights,
		];
	}

	/**
	 * Prüft einen Gast-Namen und gibt ihn normalisiert zurück.
	 *
	 * @throws CommentException
	 */
	public function guestName(string $name, string $ownerId): string {
		$error = CommentRules::validateName($name);
		if ($error !== null) {
			throw new CommentException($error);
		}
		if (CommentRules::isReservedName($name, [$ownerId, $this->ownerDisplayName($ownerId)])) {
			throw new CommentException('name_reserved');
		}
		return CommentRules::normalizeName($name);
	}

	/**
	 * @throws CommentException
	 */
	public function assertGuestCapacity(int $articleId, string $ownerId, bool $highlight): void {
		$since = new \DateTime('-1 day');
		$count = $highlight
			? $this->highlightMapper->countGuestSince($articleId, $ownerId, $since)
			: $this->commentMapper->countGuestSince($articleId, $ownerId, $since);
		if ($count >= CommentRules::GUEST_DAILY_CAP) {
			throw new CommentException('daily_limit', 429);
		}
	}

	/**
	 * Neuer Kommentar: Thread-Wurzel (an einer Markierung, an einer neuen
	 * Textstelle `$anchor` oder am ganzen Artikel) oder Antwort.
	 *
	 * `$anchor` (highlightedText, startXpath, startOffset, endXpath, endOffset)
	 * ist eine Textauswahl, die erst mit diesem Kommentar entsteht ("Kommentieren"
	 * im Markier-Menü): Textstelle und Kommentar werden zusammen gespeichert, die
	 * Textstelle mit der Farbe "comment" (unterstrichen statt eingefärbt). Davor
	 * ist im Text nichts zu sehen.
	 *
	 * @param array<string, mixed>|null $anchor
	 * @throws CommentException
	 */
	public function createComment(
		int $articleId,
		string $ownerId,
		string $authorType,
		string $authorName,
		string $body,
		?int $highlightId,
		?int $parentId,
		?array $anchor = null,
	): Comment {
		$bodyError = CommentRules::validateBody($body);
		if ($bodyError !== null) {
			throw new CommentException($bodyError);
		}
		if ($anchor !== null && $parentId === null) {
			// Erst prüfen, dann speichern: scheitert die Stelle, entsteht nichts.
			$highlight = $this->createHighlight(
				$articleId,
				$ownerId,
				$authorType,
				$authorName,
				(string) ($anchor['highlightedText'] ?? ''),
				(string) ($anchor['startXpath'] ?? ''),
				(int) ($anchor['startOffset'] ?? -1),
				(string) ($anchor['endXpath'] ?? ''),
				(int) ($anchor['endOffset'] ?? -1),
				CommentRules::COLOR_COMMENT,
			);
			$highlightId = $highlight->getId();
		}

		$comment = new Comment();
		$comment->setUserId($ownerId);
		$comment->setArticleId($articleId);

		if ($parentId !== null) {
			try {
				$parent = $this->commentMapper->findInArticle($parentId, $articleId, $ownerId);
			} catch (DoesNotExistException) {
				throw new CommentException('parent_not_found', 404);
			}
			[$rootId, $replyToId] = CommentRules::threadPosition($parent->getId(), $parent->getParentId());
			$comment->setParentId($rootId);
			$comment->setReplyToId($replyToId);
		} elseif ($highlightId !== null) {
			try {
				$highlight = $this->highlightMapper->findInArticle($highlightId, $articleId, $ownerId);
			} catch (DoesNotExistException) {
				throw new CommentException('highlight_not_found', 404);
			}
			$comment->setHighlightId($highlight->getId());
			$comment->setQuotedText(CommentRules::normalizeHighlightText((string) $highlight->getHighlightedText()));
		}

		$now = new \DateTime();
		$comment->setAuthorType($authorType);
		$comment->setAuthorName($authorName);
		$comment->setAuthorNameKey(CommentRules::nameKey($authorName));
		$comment->setBody(CommentRules::normalizeBody($body));
		$comment->setCreatedAt($now);
		$comment->setUpdatedAt($now);

		return $this->commentMapper->insert($comment);
	}

	/**
	 * @throws CommentException
	 */
	public function updateComment(Comment $comment, string $body): Comment {
		if ($comment->isDeleted()) {
			throw new CommentException('comment_deleted', 410);
		}
		$bodyError = CommentRules::validateBody($body);
		if ($bodyError !== null) {
			throw new CommentException($bodyError);
		}
		$comment->setBody(CommentRules::normalizeBody($body));
		$comment->setUpdatedAt(new \DateTime());
		return $this->commentMapper->update($comment);
	}

	/**
	 * Löscht einen Kommentar. Eine Wurzel mit Antworten bleibt als Platzhalter
	 * („Kommentar gelöscht“) stehen, damit der Thread lesbar bleibt; wird die
	 * letzte Antwort unter einem solchen Platzhalter gelöscht, verschwindet er mit.
	 */
	public function deleteComment(Comment $comment): void {
		$parentId = $comment->getParentId();
		if ($parentId === null) {
			if ($this->commentMapper->countReplies($comment->getId()) > 0) {
				$now = new \DateTime();
				$comment->setDeletedAt($now);
				$comment->setUpdatedAt($now);
				$comment->setBody('');
				$this->commentMapper->update($comment);
				return;
			}
			$this->commentMapper->delete($comment);
			$this->dropUnusedCommentHighlight($comment->getHighlightId(), $comment->getUserId());
			return;
		}

		$this->commentMapper->delete($comment);
		try {
			$root = $this->commentMapper->findInArticle($parentId, $comment->getArticleId(), $comment->getUserId());
			if ($root->isDeleted() && $this->commentMapper->countReplies($root->getId()) === 0) {
				$this->commentMapper->delete($root);
				$this->dropUnusedCommentHighlight($root->getHighlightId(), $root->getUserId());
			}
		} catch (DoesNotExistException) {
			// Wurzel schon weg
		}
	}

	/**
	 * Eine nur fürs Kommentieren angelegte Textstelle (Farbe "comment")
	 * verschwindet mit ihrem letzten Kommentar – sonst bliebe eine
	 * Unterstreichung ohne Kommentar stehen. Eingefärbte Markierungen bleiben.
	 */
	private function dropUnusedCommentHighlight(?int $highlightId, string $ownerId): void {
		if ($highlightId === null || $this->commentMapper->countForHighlight($highlightId, $ownerId) > 0) {
			return;
		}
		try {
			$highlight = $this->highlightMapper->findById($highlightId, $ownerId);
		} catch (DoesNotExistException) {
			return;
		}
		if ($highlight->getColor() === CommentRules::COLOR_COMMENT) {
			$this->highlightMapper->deleteById($highlightId, $ownerId);
		}
	}

	/**
	 * Gast-Markierung anlegen (Felder wie bei HighlightController::create()).
	 *
	 * @throws CommentException
	 */
	public function createGuestHighlight(
		int $articleId,
		string $ownerId,
		string $authorName,
		string $highlightedText,
		string $startXpath,
		int $startOffset,
		string $endXpath,
		int $endOffset,
		string $color,
	): Highlight {
		return $this->createHighlight($articleId, $ownerId, Comment::AUTHOR_GUEST, $authorName,
			$highlightedText, $startXpath, $startOffset, $endXpath, $endOffset, $color);
	}

	/**
	 * Markierung bzw. Kommentar-Textstelle prüfen, bereinigen und speichern.
	 *
	 * @throws CommentException
	 */
	private function createHighlight(
		int $articleId,
		string $ownerId,
		string $authorType,
		string $authorName,
		string $highlightedText,
		string $startXpath,
		int $startOffset,
		string $endXpath,
		int $endOffset,
		string $color,
	): Highlight {
		$maxText = $authorType === Comment::AUTHOR_GUEST
			? CommentRules::HIGHLIGHT_TEXT_MAX
			: CommentRules::OWNER_HIGHLIGHT_TEXT_MAX;
		$error = CommentRules::validateHighlight(
			$highlightedText, $startXpath, $startOffset, $endXpath, $endOffset, $maxText);
		if ($error !== null) {
			throw new CommentException($error);
		}

		$highlight = new Highlight();
		$highlight->setUserId($ownerId);
		$highlight->setArticleId($articleId);
		$highlight->setHighlightedText(CommentRules::normalizeHighlightText($highlightedText));
		$highlight->setStartXpath($startXpath);
		$highlight->setStartOffset($startOffset);
		$highlight->setEndXpath($endXpath);
		$highlight->setEndOffset($endOffset);
		$highlight->setColor(CommentRules::sanitizeColor($color));
		$highlight->setCreatedAt(new \DateTime());
		$highlight->setAuthorType($authorType);
		if ($authorType === Comment::AUTHOR_GUEST) {
			$highlight->setAuthorName($authorName);
			$highlight->setAuthorNameKey(CommentRules::nameKey($authorName));
		}

		return $this->highlightMapper->insert($highlight);
	}

	/**
	 * Markierung löschen; ihre Threads bleiben mit dem zitierten Text erhalten.
	 */
	public function deleteHighlight(Highlight $highlight): void {
		$this->commentMapper->detachHighlight($highlight->getId(), $highlight->getUserId());
		$this->highlightMapper->deleteById($highlight->getId(), $highlight->getUserId());
	}

	/**
	 * Push-Kanal (Server-Sent Events): hält die Verbindung offen und schickt
	 * sofort ein `comments`-Ereignis mit allen Threads und Markierungen, sobald
	 * sich die Änderungsmarke gegenüber $since ändert. Die Marke geht als
	 * Ereignis-ID raus, damit EventSource sie beim automatischen Neuverbinden
	 * als Last-Event-ID zurückschickt.
	 *
	 * $stillAllowed wird je Runde geprüft (z. B. Link widerrufen/abgelaufen) und
	 * beendet den Kanal mit einem `closed`-Ereignis.
	 *
	 * Beendet den Prozess selbst (exit), wie ArticleController::stream().
	 *
	 * @param callable(): bool $stillAllowed
	 */
	public function stream(int $articleId, string $ownerId, string $since, callable $stillAllowed): void {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		header('Content-Type: text/event-stream; charset=utf-8');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');
		header('X-Accel-Buffering: no');

		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

		ignore_user_abort(true);
		set_time_limit(self::STREAM_SECONDS + 15);
		@ini_set('output_buffering', 'Off');
		@ini_set('implicit_flush', '1');
		ob_implicit_flush(true);

		// Padding gegen Puffer in PHP-FPM/nginx (siehe ArticleController::stream()),
		// dazu die Wartezeit, nach der EventSource neu verbindet.
		echo ':' . str_repeat(' ', 2048) . "\n\n";
		echo "retry: 1000\n\n";
		flush();

		$deadline = time() + self::STREAM_SECONDS;
		$last = CommentRules::sanitizeSignature($since);
		$beat = 0;

		while (time() < $deadline) {
			if (connection_aborted()) {
				break;
			}
			try {
				if (!$stillAllowed()) {
					echo "event: closed\ndata: {}\n\n";
					flush();
					exit(0);
				}
				$current = $this->signature($articleId, $ownerId);
				if ($current !== $last) {
					$payload = $this->payload($articleId, $ownerId);
					$last = $payload['signature'];
					echo 'id: ' . $last . "\n";
					echo "event: comments\n";
					echo 'data: ' . json_encode($payload) . "\n\n";
					flush();
				} elseif (++$beat >= 15) {
					$beat = 0;
					echo ": heartbeat\n\n";
					flush();
				}
			} catch (\Throwable $e) {
				break;
			}
			usleep(self::STREAM_TICK_US);
		}

		exit(0);
	}
}
