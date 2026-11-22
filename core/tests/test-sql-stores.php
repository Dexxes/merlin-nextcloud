<?php

declare(strict_types=1);

/**
 * Testharness für Merlin\Core\Persistence: die SQL-Repositories des Kerns
 * gegen eine SQLite-Datenbank im Speicher, deren Schema direkt aus den
 * Nextcloud-Migrationen entsteht (tools/schema/). Geprüft wird jeder Store
 * einzeln (Rundweg der Felder, Zeitpunkte in UTC, eindeutige Indizes) und
 * CommentService und ArticleDeletionService darauf.
 *
 * Aufruf: php core/tests/test-sql-stores.php (ohne Composer, ohne Nextcloud)
 */

namespace Merlin\SqlStoresTest {
	require_once __DIR__ . '/autoload.php';
	require_once dirname(__DIR__, 2) . '/tools/schema/NextcloudSchema.php';
	require_once dirname(__DIR__, 2) . '/tools/schema/SqliteDdl.php';

	use Merlin\Core\Port\UserDirectory;

	final class Users implements UserDirectory {
		public function displayName(string $userId): string { return $userId === '1' ? 'Anna' : ''; }
	}
}

namespace {
	use Merlin\Core\Domain\Article;
	use Merlin\Core\Domain\Comment;
	use Merlin\Core\Domain\CommentGuest;
	use Merlin\Core\Domain\Highlight;
	use Merlin\Core\Domain\SiteCredential;
	use Merlin\Core\Domain\Tag;
	use Merlin\Core\Persistence\SqlArticleStore;
	use Merlin\Core\Persistence\PdoConnection;
	use Merlin\Core\Persistence\SqlCommentGuestStore;
	use Merlin\Core\Persistence\SqlCommentStore;
	use Merlin\Core\Persistence\SqlContentFilterStore;
	use Merlin\Core\Persistence\SqlDatabase;
	use Merlin\Core\Persistence\SqlHighlightStore;
	use Merlin\Core\Persistence\SqlRetentionStore;
	use Merlin\Core\Persistence\SqlShareStore;
	use Merlin\Core\Persistence\SqlSiteCredentialStore;
	use Merlin\Core\Persistence\SqlTagStore;
	use Merlin\Core\Persistence\SqlValue;
	use Merlin\Core\Port\DuplicateEntryException;
	use Merlin\Core\Service\ArticleDeletionService;
	use Merlin\Core\Service\CommentService;
	use Merlin\SqlStoresTest\Users;
	use Merlin\Tools\Schema\NextcloudSchema;
	use Merlin\Tools\Schema\SqliteDdl;

	// Ortszeit absichtlich nicht UTC: gespeichert wird trotzdem UTC.
	date_default_timezone_set('Europe/Berlin');

	$failed = 0;
	$check = static function (bool $ok, string $label, string $detail = '') use (&$failed): void {
		echo ($ok ? '  ✓ ' : '  ✗ ') . $label . ($ok || $detail === '' ? '' : "\n      {$detail}") . "\n";
		if (!$ok) {
			$failed++;
		}
	};

	$pdo = new PDO('sqlite::memory:');
	foreach (NextcloudSchema::load() as $name => $table) {
		$pdo->exec(SqliteDdl::createTable($name, $table));
	}
	$db = new PdoConnection($pdo);

	$article = static function (string $userId, array $extra = []) use ($db): int {
		return $db->insert('merlin_articles', $extra + [
			'user_id' => $userId, 'url' => 'https://example.org/' . bin2hex(random_bytes(3)), 'title' => 'T', 'content' => '',
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		]);
	};

	echo "Connection\n";
	$check($db->tableExists('merlin_comments') && !$db->tableExists('merlin_nope'), 'tableExists');
	$check(SqlValue::dateTime(new DateTimeImmutable('2026-03-01 12:00:00', new DateTimeZone('Europe/Berlin'))) === '2026-03-01 11:00:00', 'Zeitpunkte werden in UTC gespeichert');
	$read = SqlValue::toDateTime('2026-03-01 11:00:00');
	$check($read !== null && $read->format('U') === (string) (new DateTimeImmutable('2026-03-01 11:00:00', new DateTimeZone('UTC')))->getTimestamp(), 'und als UTC gelesen');
	try {
		$db->transactional(static function () use ($db, $article): void {
			$article('tx');
			throw new RuntimeException('abbrechen');
		});
	} catch (RuntimeException) {
	}
	$check((int) $db->fetchValue("SELECT COUNT(*) FROM *PREFIX*merlin_articles WHERE user_id = 'tx'") === 0, 'Transaktion rollt bei Ausnahme zurück');

	echo "Markierungen\n";
	$highlights = new SqlHighlightStore($db);
	$a1 = $article('1');
	$h = new Highlight();
	$h->setUserId('1');
	$h->setArticleId($a1);
	$h->setHighlightedText('Satz');
	$h->setStartXpath('/p[1]');
	$h->setEndXpath('/p[1]');
	$h->setEndOffset(4);
	$h->setColor('yellow');
	$h->setCreatedAt(new DateTime('2026-02-01 10:00:00'));
	$h->setPdfPage(3);
	$h->setPdfRects('[[0,0,1,1]]');
	$highlights->insert($h);
	$loaded = $highlights->findById((int) $h->getId(), '1');
	$check($loaded !== null && $loaded->getPdfPage() === 3 && $loaded->getPdfRects() === '[[0,0,1,1]]' && $loaded->getEndOffset() === 4 && $loaded->getAuthorType() === 'owner', 'Felder überstehen den Rundweg');
	$check($loaded !== null && $loaded->getCreatedAt()?->format('Y-m-d H:i') === '2026-02-01 10:00', 'Zeitpunkt in Ortszeit zurück');
	$check($highlights->findById((int) $h->getId(), '2') === null && $highlights->findInArticle((int) $h->getId(), $a1 + 1, '1') === null, 'fremder Nutzer oder Artikel: null');
	$sig = $highlights->signature($a1, '1');
	$loaded->setRevision(1);
	$loaded->setColor('green');
	$highlights->update($loaded);
	$check($highlights->findById((int) $h->getId(), '1')?->getColor() === 'green' && $highlights->signature($a1, '1') !== $sig, 'update speichert und ändert die Signatur');

	echo "Kommentare und Gäste\n";
	$comments = new SqlCommentStore($db);
	$guests = new SqlCommentGuestStore($db);
	$service = new CommentService($comments, $highlights, $guests, new Users());
	$c1 = $service->createComment($a1, '1', Comment::AUTHOR_OWNER, 'Anna', 'Erster', $h->getId(), null);
	$check($comments->findForOwner((int) $c1->getId(), '1')?->getQuotedText() === 'Satz', 'Kommentar an Markierung mit Zitat');
	$guestName = $service->guestName('Gast', '1');
	$color = $service->claimGuestColor($a1, '1', $guestName, null);
	$check($color !== '' && count($guests->findByArticleId($a1, '1')) === 1, 'Gast-Farbe vergeben und gespeichert');
	$check($service->claimGuestColor($a1, '1', 'gast', null) === $color, 'derselbe Name behält seine Farbe');
	$dup = new CommentGuest();
	$dup->setUserId('1');
	$dup->setArticleId($a1);
	$dup->setNameKey('anders');
	$dup->setName('Anders');
	$dup->setColor($color);
	$dup->setCreatedAt(new DateTime());
	try {
		$guests->insert($dup);
		$check(false, 'gleiche Farbe zweimal: DuplicateEntryException');
	} catch (DuplicateEntryException) {
		$check(true, 'gleiche Farbe zweimal: DuplicateEntryException');
	}
	$root = $service->createComment($a1, '1', Comment::AUTHOR_GUEST, $guestName, 'Frage', null, null);
	$reply = $service->createComment($a1, '1', Comment::AUTHOR_OWNER, 'Anna', 'Antwort', null, $root->getId());
	$check($comments->countReplies((int) $root->getId()) === 1 && $reply->getParentId() === $root->getId(), 'Antwort hängt an der Wurzel');
	$check($comments->countGuestSince($a1, '1', new DateTime('-1 minute')) === 1, 'Gast-Kommentare seit einem Zeitpunkt');
	$payload = $service->payload($a1, '1');
	$check(count($payload['comments']) === 2 && count($payload['guests']) === 1, 'payload aus der Datenbank', json_encode($payload['comments']));
	$service->deleteComment($comments->findForOwner((int) $root->getId(), '1'));
	$check($comments->findForOwner((int) $root->getId(), '1')?->isDeleted() === true, 'Wurzel mit Antwort bleibt als Platzhalter');
	$service->deleteHighlight($highlights->findById((int) $h->getId(), '1'));
	$check($highlights->findById((int) $h->getId(), '1') === null && $comments->findForOwner((int) $c1->getId(), '1')?->getHighlightId() === null,
		'Markierung löschen löst sie vom Kommentar');

	echo "Share-Links\n";
	$shares = new SqlShareStore($db);
	$db->insert('merlin_shares', ['user_id' => '1', 'article_id' => $a1, 'token' => 'tok', 'expires_at' => '2026-01-10 00:00:00',
		'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', 'allow_comments' => false]);
	$share = $shares->findByToken('tok');
	$check($share !== null && $share->getArticleId() === $a1 && $share->getAllowComments() === false && $share->getExpiresAt() !== null, 'findByToken');
	$check($shares->findByToken('nope') === null, 'unbekanntes Token: null');
	try {
		$db->insert('merlin_shares', ['user_id' => '1', 'article_id' => $a1, 'token' => 'tok2',
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
		$check(false, 'zweiter Link für denselben Artikel: DuplicateEntryException');
	} catch (DuplicateEntryException) {
		$check(true, 'zweiter Link für denselben Artikel: DuplicateEntryException');
	}

	echo "Paywall-Zugangsdaten\n";
	$creds = new SqlSiteCredentialStore($db);
	$cred = new SiteCredential();
	$cred->setUserId('1');
	$cred->setDomain('example.org');
	$cred->setUsernameEnc('u');
	$cred->setPasswordEnc('p');
	$cred->setLastLoginStatus('pending');
	$creds->save($cred);
	$check($cred->getId() !== null && $creds->find('1', 'example.org')?->getCreatedAt() !== null, 'neu: ID und created_at');
	$cred->setSessionCookiesEnc('c');
	$cred->setLastLoginStatus('ok');
	$cred->setCookieExpiresAt(new DateTimeImmutable('+1 day'));
	$creds->save($cred);
	$again = $creds->find('1', 'example.org');
	$check($again?->getSessionCookiesEnc() === 'c' && $again->getLastLoginStatus() === 'ok' && $again->getCookieExpiresAt() !== null, 'aktualisieren');
	$check(count($creds->findAllForUser('1')) === 1 && $creds->findAllForUser('2') === [], 'findAllForUser');
	$creds->deleteByUserAndDomain('1', 'example.org');
	$check($creds->find('1', 'example.org') === null, 'löschen');

	echo "Content-Filter\n";
	$filters = new SqlContentFilterStore($db);
	$filters->insert('user', '1', 'b.example', '<x/>', new DateTime(), '1');
	$filters->insert('user', '1', 'a.example', '<y/>', new DateTime(), '1');
	$filters->insert('user', '2', 'a.example', '<z/>', new DateTime(), '2');
	$filters->insert('admin', '', 'a.example', '<admin/>', new DateTime(), '1');
	$check($filters->readXml('user', '1', 'a.example') === '<y/>' && $filters->readXml('user', '3', 'a.example') === null, 'readXml je Ebene und Nutzer');
	$check($filters->listDomains('user', '1', true) === ['a.example', 'b.example'], 'listDomains sortiert');
	$check($filters->countByDomain('user') === ['a.example' => 2, 'b.example' => 1], 'countByDomain');
	$id = (int) $filters->findId('user', '1', 'a.example');
	$filters->update($id, '<neu/>', new DateTime(), '1');
	$check($filters->readXml('user', '1', 'a.example') === '<neu/>', 'update');
	$filters->deleteAllForUser('user', '1');
	$check($filters->listDomains('user') === ['a.example'] && $filters->readXml('admin', '', 'a.example') === '<admin/>', 'deleteAllForUser lässt andere Ebenen');

	echo "Löschen und Löschfrist\n";
	$deletion = new ArticleDeletionService(new SqlDatabase($db));
	$a2 = $article('1');
	$foreign = $article('2');
	$db->insert('merlin_tags', ['user_id' => '1', 'name' => 'x', 'created_at' => '2026-01-01 00:00:00']);
	$db->insert('merlin_article_tags', ['article_id' => $a2, 'tag_id' => 1], false);
	$check($deletion->deleteArticles('1', [$a2, $foreign]) === 1, 'nur eigene Artikel werden gelöscht');
	$check((int) $db->fetchValue('SELECT COUNT(*) FROM *PREFIX*merlin_article_tags') === 0 && (int) $db->fetchValue('SELECT COUNT(*) FROM *PREFIX*merlin_articles WHERE id = :id', ['id' => $foreign]) === 1,
		'Tag-Zuordnungen weg, fremder Artikel bleibt');
	$check($deletion->deleteArticles('1', []) === 0, 'leere Liste löscht nichts');

	$retention = new SqlRetentionStore($db);
	$old = $article('3', ['is_archived' => 1, 'archived_at' => '2026-01-01 00:00:00']);
	$fav = $article('3', ['is_archived' => 1, 'archived_at' => '2026-01-01 00:00:00', 'is_favorite' => '2026-01-01 00:00:00']);
	$article('3', ['is_archived' => 1, 'archived_at' => '2026-06-01 00:00:00']);
	$article('3', ['is_archived' => 1, 'archived_at' => '2026-01-01 00:00:00', 'is_processing' => 1]);
	$cutoff = new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC'));
	$check(in_array('3', $retention->findUserIdsWithArchived(), true), 'Nutzer mit Archiv');
	$check($retention->findExpiredArchivedIds('3', $cutoff, false, 10) === [$old] && $retention->countExpiredArchived('3', $cutoff, true) === 1, 'abgelaufen, getrennt nach Favoriten');
	$check($retention->findExpiredArchivedIds('3', $cutoff, true, 10) === [$fav], 'Favoriten eigene Frist');
	$check($retention->deleteSharesExpiredBefore(new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'))) === 1, 'abgelaufene Share-Links löschen');

	$deletion->deleteAllForUser('1');
	$check((int) $db->fetchValue("SELECT COUNT(*) FROM *PREFIX*merlin_comments WHERE user_id = '1'") === 0
		&& (int) $db->fetchValue("SELECT COUNT(*) FROM *PREFIX*merlin_articles WHERE user_id = '1'") === 0
		&& (int) $db->fetchValue("SELECT COUNT(*) FROM *PREFIX*merlin_articles WHERE user_id = '2'") === 1, 'deleteAllForUser trifft nur den Nutzer');

	echo "Artikel und Schlagworte\n";
	$articles = new SqlArticleStore($db);
	$tagStore = new SqlTagStore($db);
	$new = static function (string $userId, string $title, array $set = []) use ($articles): Article {
		$a = new Article();
		$a->setUserId($userId);
		$a->setUrl('https://example.org/' . rawurlencode($title));
		$a->setTitle($title);
		$a->setContent('<p>' . $title . '</p>');
		$a->setCreatedAt(new DateTime('2026-02-01 10:00:00'));
		$a->setUpdatedAt(new DateTime('2026-02-01 10:00:00'));
		foreach ($set as $field => $value) {
			$a->{'set' . ucfirst($field)}($value);
		}
		return $articles->insert($a);
	};
	$a1 = $new('7', 'Erster Artikel', ['author' => 'Erika', 'excerpt' => '100% sicher']);
	$check($a1->getId() > 0 && $a1->changedFields() === [], 'insert setzt id und leert die Änderungsliste');
	$row = $db->fetchRow('SELECT * FROM *PREFIX*merlin_articles WHERE id = :id', ['id' => $a1->getId()]);
	$check((float) $row['scroll_progress'] === 0.0 && (int) $row['scroll_updated_at'] === 0 && $row['category'] === null,
		'nicht gesetzte Spalten behalten ihre Defaults', json_encode($row));
	$loaded = $articles->find((int) $a1->getId(), '7');
	$check($loaded !== null && $loaded->getTitle() === 'Erster Artikel' && $loaded->getCreatedAt()->format('c') === $a1->getCreatedAt()->format('c'), 'find: Rundweg');
	$check($articles->find((int) $a1->getId(), '8') === null, 'find: nur eigener Artikel');
	$json = $loaded->jsonSerialize();
	$check($json['isRead'] === false && $json['isFavorite'] === false && $json['scrollProgress'] === 0.0 && $json['fileId'] === null
		&& array_keys($json) === array_keys((new Article())->jsonSerialize()), 'jsonSerialize: Wire-Format');

	// Nur geänderte Spalten: eine parallele Änderung am Inhalt bleibt erhalten.
	$stale = $articles->find((int) $a1->getId(), '7');
	$db->execute("UPDATE *PREFIX*merlin_articles SET content = 'neu extrahiert' WHERE id = :id", ['id' => $a1->getId()]);
	$stale->setScrollProgress(0.5);
	$articles->update($stale);
	$after = $articles->find((int) $a1->getId(), '7');
	$check($after->getContent() === 'neu extrahiert' && $after->getScrollProgress() === 0.5, 'update schreibt nur geänderte Spalten');

	$a2 = $new('7', 'Video', ['category' => 'Video', 'isRead' => 1]);
	$a3 = $new('7', 'Favorit', ['isFavorite' => new DateTime('2026-02-03 08:00:00')]);
	$a4 = $new('7', 'Archiviert', ['isArchived' => 1, 'archivedAt' => new DateTime('2026-02-04 08:00:00')]);
	$a5 = $new('7', 'Datei', ['fileId' => 42, 'fileMime' => 'image/jpeg', 'fileText' => 'Kassenbon']);
	$new('8', 'Fremd');
	$ids = static fn (array $list) => array_map(static fn (Article $a) => $a->getId(), $list);
	$check($ids($articles->findAll('7')) === [$a5->getId(), $a4->getId(), $a3->getId(), $a2->getId(), $a1->getId()], 'findAll: neueste zuerst, nur eigene');
	$check($ids($articles->findAll('7', ['is_read' => true])) === [$a2->getId()], 'Filter is_read');
	$check($ids($articles->findAll('7', ['is_favorite' => true])) === [$a3->getId()], 'Filter is_favorite');
	$check($ids($articles->findAll('7', ['is_archived' => true])) === [$a4->getId()], 'Filter is_archived');
	$check($ids($articles->findAll('7', ['has_file' => true])) === [$a5->getId()], 'Filter has_file');
	$check($ids($articles->findAll('7', ['not_category' => ['Video', 'Audio'], 'has_file' => false])) === [$a4->getId(), $a3->getId(), $a1->getId()], 'Filter not_category');
	$check($ids($articles->findAll('7', [], 2, 1)) === [$a4->getId(), $a3->getId()], 'limit/offset');
	$counts = $articles->counts('7');
	$check($counts['pages'] === ['total' => 2, 'unread' => 2, 'favorites' => 1, 'archived' => 1]
		&& $counts['videos'] === ['total' => 1, 'unread' => 0, 'favorites' => 0, 'archived' => 0]
		&& $counts['files']['total'] === 1, 'counts', json_encode($counts));
	$check($ids($articles->search('7', 'erika')) === [$a1->getId()], 'search: Groß-/Kleinschreibung egal');
	$check($ids($articles->search('7', '100%')) === [$a1->getId()] && $articles->search('7', '_') === [], 'search: % und _ wörtlich');
	$check($ids($articles->search('7', 'kassenbon')) === [$a5->getId()] && $articles->search('7', 'Archiviert') === [], 'search: erkannter Text, ohne Archiv');

	$stuck = $new('7', 'Hängt', ['isProcessing' => 1, 'updatedAt' => new DateTime('-10 minutes')]);
	$fresh = $new('7', 'Läuft', ['isProcessing' => 1, 'updatedAt' => new DateTime()]);
	$check($ids($articles->findProcessing('7')) === [$fresh->getId(), $stuck->getId()], 'findProcessing');
	$articles->clearStuckProcessing('7');
	$check($ids($articles->findProcessing('7')) === [$fresh->getId()], 'clearStuckProcessing nur nach 5 Minuten');

	$parent = new Tag();
	$parent->setUserId('7');
	$parent->setName('Politik');
	$parent->setCreatedAt(new DateTime());
	$tagStore->insert($parent);
	$child = new Tag();
	$child->setUserId('7');
	$child->setName('Europa');
	$child->setParentId($parent->getId());
	$child->setCreatedAt(new DateTime());
	$tagStore->insert($child);
	$check($parent->getId() > 0 && $tagStore->find((int) $parent->getId(), '7')?->getColor() === '#0082c9', 'Schlagwort anlegen mit Standardfarbe');
	$dup = new Tag();
	$dup->setUserId('7');
	$dup->setName('Politik');
	$dup->setCreatedAt(new DateTime());
	try {
		$tagStore->insert($dup);
		$check(false, 'doppelter Schlagwortname');
	} catch (DuplicateEntryException) {
		$check(true, 'doppelter Schlagwortname: DuplicateEntryException');
	}
	$check(array_map(static fn (Tag $t) => $t->getName(), $tagStore->findAll('7')) === ['Europa', 'Politik'], 'findAll nach Name');
	$check($tagStore->parentMap('7') === [(int) $parent->getId() => null, (int) $child->getId() => (int) $parent->getId()], 'parentMap');
	$tagStore->addToArticle((int) $a1->getId(), (int) $child->getId());
	$tagStore->addToArticle((int) $a1->getId(), (int) $parent->getId());
	$tagStore->addToArticle((int) $a3->getId(), (int) $parent->getId());
	try {
		$tagStore->addToArticle((int) $a3->getId(), (int) $parent->getId());
		$check(false, 'Zuordnung doppelt');
	} catch (DuplicateEntryException) {
		$check(true, 'Zuordnung doppelt: DuplicateEntryException');
	}
	$byArticle = $tagStore->findByArticleIds([(int) $a1->getId(), (int) $a3->getId(), (int) $a2->getId()]);
	$check(array_map(static fn (Tag $t) => $t->getName(), $byArticle[$a1->getId()] ?? []) === ['Europa', 'Politik']
		&& count($byArticle[$a3->getId()] ?? []) === 1 && !isset($byArticle[$a2->getId()]), 'findByArticleIds');
	$check($ids($articles->findAll('7', ['tag_ids' => [(int) $parent->getId(), (int) $child->getId()]])) === [$a3->getId(), $a1->getId()], 'Filter tag_ids: jeder Artikel einmal');
	$tagStore->removeFromArticle((int) $a3->getId(), (int) $parent->getId());
	$check($tagStore->findByArticleId((int) $a3->getId()) === [], 'removeFromArticle');
	$tagStore->deleteWithLinks([(int) $child->getId()], '8');
	$check($tagStore->find((int) $child->getId(), '7') !== null, 'deleteWithLinks: fremde Schlagworte bleiben');
	$tagStore->deleteWithLinks([(int) $child->getId(), (int) $parent->getId()], '7');
	$check($tagStore->findAll('7') === [] && $tagStore->findByArticleId((int) $a1->getId()) === [], 'deleteWithLinks samt Zuordnungen');
	$check($articles->find((int) $a1->getId(), '7') !== null, 'die Artikel bleiben');

	echo $failed === 0 ? "\nalle Prüfungen ok\n" : "\n{$failed} Fehler\n";
	exit($failed === 0 ? 0 : 1);
}
