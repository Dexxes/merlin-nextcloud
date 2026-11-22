<?php

declare(strict_types=1);

/**
 * Testharness für Service\CommentService und Service\ShareAccessService.
 *
 * Aufruf: php core/tests/test-comment-service.php
 *
 * Ohne Composer und ohne Nextcloud: die Ports CommentStore, HighlightStore,
 * CommentGuestStore, UserDirectory, ShareStore und Session sind In-Memory-
 * Stubs. Geprüft werden Threads (Antworten, Platzhalter, Aufräumen der
 * Kommentar-Textstelle), Gast-Namen und -Farben (auch bei gleichzeitiger
 * Vergabe), Markierungen ändern/löschen und die Share-Zugriffsprüfung.
 * Die Regeln selbst prüft test-comments.php.
 */

namespace Merlin\CommentServiceTest {
	require_once __DIR__ . '/autoload.php';

	use Merlin\Core\Domain\ArticleShare;
	use Merlin\Core\Domain\Comment;
	use Merlin\Core\Domain\CommentGuest;
	use Merlin\Core\Domain\Highlight;
	use Merlin\Core\Port\CommentGuestStore;
	use Merlin\Core\Port\CommentStore;
	use Merlin\Core\Port\DuplicateEntryException;
	use Merlin\Core\Port\HighlightStore;
	use Merlin\Core\Port\Session;
	use Merlin\Core\Port\ShareStore;
	use Merlin\Core\Port\UserDirectory;

	/** Gemeinsame In-Memory-Ablage: speichert Kopien, wie eine Datenbank. */
	abstract class Memory {
		/** @var array<int, object> */
		public array $rows = [];
		private int $next = 1;
		protected function add(object $o): object {
			$o->setId($this->next++);
			$this->rows[$o->getId()] = clone $o;
			return $o;
		}
		protected function pick(callable $f): array {
			return array_values(array_map(static fn ($o) => clone $o, array_filter($this->rows, $f)));
		}
		public function signature(int $articleId, string $userId): string {
			return md5(serialize($this->pick(static fn ($o) => $o->getArticleId() === $articleId && $o->getUserId() === $userId)));
		}
	}

	final class Comments extends Memory implements CommentStore {
		public function findByArticleId(int $articleId, string $userId): array { return $this->pick(static fn (Comment $c) => $c->getArticleId() === $articleId && $c->getUserId() === $userId); }
		public function findInArticle(int $id, int $articleId, string $userId): ?Comment { return $this->pick(static fn (Comment $c) => $c->getId() === $id && $c->getArticleId() === $articleId && $c->getUserId() === $userId)[0] ?? null; }
		public function findForOwner(int $id, string $userId): ?Comment { return $this->pick(static fn (Comment $c) => $c->getId() === $id && $c->getUserId() === $userId)[0] ?? null; }
		public function insert(Comment $comment): Comment { return $this->add($comment); }
		public function update(Comment $comment): Comment { $this->rows[$comment->getId()] = clone $comment; return $comment; }
		public function delete(Comment $comment): void { unset($this->rows[$comment->getId()]); }
		public function countForHighlight(int $highlightId, string $userId): int { return count($this->pick(static fn (Comment $c) => $c->getHighlightId() === $highlightId)); }
		public function countReplies(int $rootId): int { return count($this->pick(static fn (Comment $c) => $c->getParentId() === $rootId)); }
		public function countGuestSince(int $articleId, string $userId, \DateTime $since): int { return count($this->pick(static fn (Comment $c) => $c->getAuthorType() === Comment::AUTHOR_GUEST && $c->getCreatedAt() >= $since)); }
		public function detachHighlight(int $highlightId, string $userId): void { foreach ($this->rows as $c) { if ($c->getHighlightId() === $highlightId) { $c->setHighlightId(null); } } }
		public function updateQuotedText(int $highlightId, string $userId, string $quotedText): void { foreach ($this->rows as $c) { if ($c->getHighlightId() === $highlightId) { $c->setQuotedText($quotedText); } } }
	}

	final class Highlights extends Memory implements HighlightStore {
		public function findByArticleId(int $articleId, string $userId): array { return $this->pick(static fn (Highlight $h) => $h->getArticleId() === $articleId && $h->getUserId() === $userId); }
		public function findById(int $id, string $userId): ?Highlight { return $this->pick(static fn (Highlight $h) => $h->getId() === $id && $h->getUserId() === $userId)[0] ?? null; }
		public function findInArticle(int $id, int $articleId, string $userId): ?Highlight { return $this->pick(static fn (Highlight $h) => $h->getId() === $id && $h->getArticleId() === $articleId)[0] ?? null; }
		public function insert(Highlight $highlight): Highlight { return $this->add($highlight); }
		public function update(Highlight $highlight): Highlight { $this->rows[$highlight->getId()] = clone $highlight; return $highlight; }
		public function deleteById(int $id, string $userId): void { unset($this->rows[$id]); }
		public function countGuestSince(int $articleId, string $userId, \DateTime $since): int { return count($this->pick(static fn (Highlight $h) => $h->getAuthorType() === Comment::AUTHOR_GUEST)); }
	}

	final class Guests extends Memory implements CommentGuestStore {
		/** Simuliert einen zweiten Request, der denselben Namen zuerst speichert. */
		public ?string $raceColor = null;
		public function findByArticleId(int $articleId, string $userId): array { return $this->pick(static fn (CommentGuest $g) => $g->getArticleId() === $articleId); }
		public function insert(CommentGuest $guest): CommentGuest {
			foreach ($this->rows as $g) {
				if ($g->getNameKey() === $guest->getNameKey() || $g->getColor() === $guest->getColor()) {
					throw new DuplicateEntryException('belegt');
				}
			}
			if ($this->raceColor !== null) {
				$other = clone $guest;
				$other->setColor($this->raceColor);
				$this->raceColor = null;
				$this->add($other);
				throw new DuplicateEntryException('belegt');
			}
			return $this->add($guest);
		}
	}

	final class Users implements UserDirectory {
		public function displayName(string $userId): string { return $userId === 'bob' ? 'Bob B.' : ''; }
	}

	final class Shares implements ShareStore {
		/** @var array<string, ArticleShare> */
		public array $byToken = [];
		public function findByToken(string $token): ?ArticleShare { return $this->byToken[$token] ?? null; }
	}

	final class ArraySession implements Session {
		public array $data = [];
		public function get(string $key): mixed { return $this->data[$key] ?? null; }
		public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
	}
}

namespace {
	use Merlin\CommentServiceTest\ArraySession;
	use Merlin\CommentServiceTest\Comments;
	use Merlin\CommentServiceTest\Guests;
	use Merlin\CommentServiceTest\Highlights;
	use Merlin\CommentServiceTest\Shares;
	use Merlin\CommentServiceTest\Users;
	use Merlin\Core\Domain\ArticleShare;
	use Merlin\Core\Domain\Comment;
	use Merlin\Core\Domain\Highlight;
	use Merlin\Core\Service\CommentException;
	use Merlin\Core\Service\CommentRules;
	use Merlin\Core\Service\CommentService;
	use Merlin\Core\Service\ShareAccessDenied;
	use Merlin\Core\Service\ShareAccessService;

	$failed = 0;
	$check = static function (bool $ok, string $label, string $detail = '') use (&$failed): void {
		echo ($ok ? "  ✓ " : "  ✗ ") . $label . ($ok || $detail === '' ? '' : "\n      $detail") . "\n";
		if (!$ok) {
			$failed++;
		}
	};
	$error = static function (callable $f): ?string {
		try {
			$f();
			return null;
		} catch (CommentException $e) {
			return $e->getErrorCode() . '/' . $e->getStatus();
		}
	};

	$comments = new Comments();
	$highlights = new Highlights();
	$guests = new Guests();
	$service = new CommentService($comments, $highlights, $guests, new Users());
	$anchor = ['highlightedText' => 'Anker  Text', 'startXpath' => 'p[2]/text()[1]', 'startOffset' => 1, 'endXpath' => 'p[2]/text()[1]', 'endOffset' => 6];

	echo "Namen und Farben\n";
	$check($service->ownerDisplayName('bob') === 'Bob B.' && $service->ownerDisplayName('ghost') === 'ghost', 'Anzeigename, sonst Konto-ID');
	$name = $service->guestName('  Gast  Eins ', 'bob');
	$check($name === 'Gast Eins', 'Gast-Name normalisiert');
	$check($error(fn () => $service->guestName('bob b.', 'bob')) === 'name_reserved/400', 'Name des Besitzers ist reserviert');
	$first = $service->claimGuestColor(7, 'bob', $name, null);
	$check($first === CommentRules::GUEST_COLORS[0], 'erste freie Farbe');
	$check($service->claimGuestColor(7, 'bob', 'gast eins', CommentRules::GUEST_COLORS[3]) === $first, 'derselbe Name behält seine Farbe');
	$check($error(fn () => $service->claimGuestColor(7, 'bob', 'Zwei', $first)) === 'color_taken/409', 'belegte Wunschfarbe: 409');
	$guests->raceColor = '#123456';
	$check($service->claimGuestColor(7, 'bob', 'Drei', null) === '#123456', 'gleichzeitig vergeben: Farbe des Gewinners');

	echo "Threads\n";
	$own = new Highlight();
	$own->setUserId('bob');
	$own->setArticleId(7);
	$own->setHighlightedText('Ein Satz');
	$own->setStartXpath('p[1]/text()[1]');
	$own->setEndXpath('p[1]/text()[1]');
	$own->setEndOffset(8);
	$own->setColor('yellow');
	$own->setCreatedAt(new \DateTime());
	$highlights->insert($own);

	$c1 = $service->createComment(7, 'bob', Comment::AUTHOR_OWNER, 'Bob B.', "Erster\r\nKommentar", $own->getId(), null);
	$check($c1->getId() !== null && $c1->getQuotedText() === 'Ein Satz' && $c1->getBody() === "Erster\nKommentar", 'Kommentar an Markierung: Zitat und normalisierter Text');
	$root = $service->createComment(7, 'bob', Comment::AUTHOR_GUEST, $name, 'Mit Anker', null, null, $anchor);
	$anchorHighlight = $highlights->findById((int) $root->getHighlightId(), 'bob');
	$check($anchorHighlight !== null && $anchorHighlight->getColor() === CommentRules::COLOR_COMMENT && $anchorHighlight->getAuthorNameKey() === CommentRules::nameKey($name),
		'Kommentar mit neuer Textstelle legt eine "comment"-Markierung des Gasts an');
	$reply = $service->createComment(7, 'bob', Comment::AUTHOR_OWNER, 'Bob B.', 'Antwort', null, $root->getId());
	$reply2 = $service->createComment(7, 'bob', Comment::AUTHOR_GUEST, $name, 'Antwort auf Antwort', null, $reply->getId());
	$check($reply2->getParentId() === $root->getId() && $reply2->getReplyToId() === $reply->getId(), 'Antwort auf Antwort hängt an der Wurzel, replyTo zeigt auf die Antwort');
	$check($error(fn () => $service->createComment(7, 'bob', Comment::AUTHOR_GUEST, $name, 'x', null, 999)) === 'parent_not_found/404', 'unbekannte Wurzel: 404');
	$check($error(fn () => $service->createComment(7, 'bob', Comment::AUTHOR_GUEST, $name, 'x', 999, null)) === 'highlight_not_found/404', 'unbekannte Markierung: 404');

	$payload = $service->payload(7, 'bob');
	$thread = array_values(array_filter($payload['comments'], static fn ($c) => $c['id'] === $root->getId()))[0] ?? [];
	$check(count($payload['comments']) === 2 && count($thread['replies'] ?? []) === 2, 'payload: zwei Wurzeln, Antworten verschachtelt', json_encode($payload['comments']));
	$check(($thread['authorColor'] ?? null) === $first && $payload['guests'][0] === ['name' => 'Gast Eins', 'color' => $first], 'payload: Gast-Farbe an Kommentar und in guests');
	$check($payload['highlightUpdate'] === true && is_int($payload['generatedAt']), 'payload: highlightUpdate und generatedAt');

	$sig = $service->signature(7, 'bob');
	$updated = $service->updateComment($comments->findForOwner($c1->getId(), 'bob'), 'Geändert');
	$check($updated->getBody() === 'Geändert' && $comments->findForOwner($c1->getId(), 'bob')->getBody() === 'Geändert', 'Kommentar ändern speichert');
	$check($service->signature(7, 'bob') !== $sig, 'Änderung bewegt die Änderungsmarke');

	$service->deleteComment($comments->findForOwner($root->getId(), 'bob'));
	$placeholder = $comments->findForOwner($root->getId(), 'bob');
	$check($placeholder !== null && $placeholder->isDeleted() && $placeholder->getBody() === '', 'Wurzel mit Antworten bleibt als Platzhalter');
	$check($placeholder->jsonSerialize()['authorName'] === '' && $placeholder->jsonSerialize()['deleted'] === true, 'Platzhalter zeigt weder Text noch Verfasser');
	$check($error(fn () => $service->updateComment($placeholder, 'x')) === 'comment_deleted/410', 'Platzhalter ist nicht mehr änderbar');
	$service->deleteComment($comments->findForOwner($reply2->getId(), 'bob'));
	$service->deleteComment($comments->findForOwner($reply->getId(), 'bob'));
	$check($comments->findForOwner($root->getId(), 'bob') === null, 'letzte Antwort weg: Platzhalter verschwindet');
	$check($highlights->findById($anchorHighlight->getId(), 'bob') === null, '... und mit ihm die Kommentar-Textstelle');

	echo "Markierungen\n";
	$guestHighlight = $service->createGuestHighlight(7, 'bob', $name, 'Gast markiert', 'p[3]/text()[1]', 0, 'p[3]/text()[1]', 4, 'green');
	$check($guestHighlight->getAuthorType() === Comment::AUTHOR_GUEST && $guestHighlight->getColor() === 'green', 'Gast-Markierung');
	$check($error(fn () => $service->createGuestHighlight(7, 'bob', $name, 'x', '/p', 0, '/p', 1, 'green')) === 'highlight_invalid/400', 'ungültiger XPath: highlight_invalid');
	$pdf = $service->createComment(7, 'bob', Comment::AUTHOR_OWNER, 'Bob B.', 'PDF', null, null, ['highlightedText' => 'PDF-Text', 'pdfPage' => 2, 'pdfRects' => [[0.1, 0.2, 0.3, 0.04]]]);
	$pdfHighlight = $highlights->findById((int) $pdf->getHighlightId(), 'bob');
	$check($pdfHighlight !== null && $pdfHighlight->getPdfPage() === 2 && $pdfHighlight->getStartXpath() === CommentRules::pdfXpath(2)
		&& $pdfHighlight->jsonSerialize()['pdfRects'] === [[0.1, 0.2, 0.3, 0.04]], 'PDF-Textstelle: Seite, Platzhalter-XPath, Rechtecke');

	$edit = $highlights->findById($own->getId(), 'bob');
	$edit->setHighlightedText('Ein Satz länger');
	$saved = $service->updateHighlight($edit);
	$check($saved->getRevision() === 1 && $comments->findForOwner($c1->getId(), 'bob')->getQuotedText() === 'Ein Satz länger', 'Markierung ändern: revision +1, Zitat zieht mit');
	$service->deleteHighlight($highlights->findById($own->getId(), 'bob'));
	$kept = $comments->findForOwner($c1->getId(), 'bob');
	$check($highlights->findById($own->getId(), 'bob') === null && $kept !== null && $kept->getHighlightId() === null && $kept->getQuotedText() === 'Ein Satz länger',
		'Markierung löschen: Thread bleibt mit Zitat');

	echo "Share-Zugriff\n";
	$shares = new Shares();
	$session = new ArraySession();
	$access = new ShareAccessService($shares, $session);
	$share = static function (string $token, ?string $hash, ?string $expires, ?bool $allow) use ($shares): ArticleShare {
		$s = new ArticleShare();
		$s->setUserId('bob');
		$s->setArticleId(7);
		$s->setToken($token);
		$s->setPasswordHash($hash);
		$s->setExpiresAt($expires !== null ? new \DateTime($expires) : null);
		$s->setAllowComments($allow);
		return $shares->byToken[$token] = $s;
	};
	$share('offen', null, '+1 day', null);
	$share('alt', null, '-1 minute', true);
	$locked = $share('gesperrt', password_hash('geheim', PASSWORD_DEFAULT), null, false);
	$denied = static fn ($r) => $r instanceof ShareAccessDenied ? [$r->getStatus(), $r->getData()] : null;

	$open = $access->resolveAccessibleShare('offen');
	$check($open instanceof ArticleShare && $open->allowsComments(), 'gültiger Link; allow_comments NULL zählt als erlaubt');
	$check($denied($access->resolveAccessibleShare('fehlt')) === [404, ['error' => 'Not found']], 'unbekannter Token: 404');
	$check($denied($access->resolveAccessibleShare('alt')) === [410, ['error' => 'Expired']], 'abgelaufen: 410');
	$check($denied($access->resolveAccessibleShare('gesperrt')) === [401, ['locked' => true, 'hasPassword' => true]], 'Passwort, nicht entsperrt: 401');
	$access->markUnlocked($locked);
	$access->markUnlocked($locked);
	$check($access->resolveAccessibleShare('gesperrt') instanceof ArticleShare && $session->data === ['merlin_unlocked_share_tokens' => ['gesperrt']],
		'nach Unlock offen, Token nur einmal in der Session');
	$check(!$access->resolveAccessibleShare('gesperrt')->allowsComments(), 'allow_comments false bleibt false');
	$check($access->stillValid('offen') && !$access->stillValid('alt') && !$access->stillValid('fehlt'), 'stillValid für den Push-Kanal');

	echo $failed === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$failed Prüfung(en) fehlgeschlagen.\n";
	exit($failed === 0 ? 0 : 1);
}
