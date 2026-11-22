<?php

declare(strict_types=1);

/**
 * Testharness für ContentFilter\ContentFilterRepository und
 * Service\ArticleDeletionService (die beiden Klassen mit eigener Ablage).
 *
 * Aufruf: php core/tests/test-content-filter-repository.php
 *
 * Ohne Composer und ohne Nextcloud: die Ports ContentFilterStore und
 * Database sind In-Memory-Stubs, die Bundle-Filter kommen aus dem echten
 * content-filters/. Die Merge-Regeln selbst prüft test-content-filter-merge.php.
 */

namespace Psr\Log {
	if (!interface_exists(LoggerInterface::class)) {
		interface LoggerInterface {
			public function emergency(string|\Stringable $message, array $context = []): void;
			public function alert(string|\Stringable $message, array $context = []): void;
			public function critical(string|\Stringable $message, array $context = []): void;
			public function error(string|\Stringable $message, array $context = []): void;
			public function warning(string|\Stringable $message, array $context = []): void;
			public function notice(string|\Stringable $message, array $context = []): void;
			public function info(string|\Stringable $message, array $context = []): void;
			public function debug(string|\Stringable $message, array $context = []): void;
			public function log($level, string|\Stringable $message, array $context = []): void;
		}
	}
}

namespace Merlin\RepositoryTest {
	require_once __DIR__ . '/autoload.php';

	use Merlin\Core\Port\ContentFilterStore;
	use Merlin\Core\Port\Database;
	use Psr\Log\LoggerInterface;

	final class Store implements ContentFilterStore {
		/** @var array<int, array{scope:string,userId:string,domain:string,xml:string,updatedBy:string}> */
		public array $rows = [];
		private int $next = 1;
		public bool $failReads = false;
		/** Simuliert einen zweiten Request, der dieselbe Zeile zuerst anlegt. */
		public bool $raceOnInsert = false;

		public function readXml(string $scope, string $userId, string $domain): ?string {
			if ($this->failReads) {
				throw new \RuntimeException('DB weg');
			}
			$id = $this->findId($scope, $userId, $domain);
			return $id === null ? null : $this->rows[$id]['xml'];
		}
		public function findId(string $scope, string $userId, string $domain): ?int {
			foreach ($this->rows as $id => $r) {
				if ($r['scope'] === $scope && $r['userId'] === $userId && $r['domain'] === $domain) {
					return $id;
				}
			}
			return null;
		}
		public function insert(string $scope, string $userId, string $domain, string $xml, \DateTime $now, string $updatedBy): void {
			if ($this->raceOnInsert) {
				$this->raceOnInsert = false;
				$this->rows[$this->next++] = compact('scope', 'userId', 'domain') + ['xml' => '<fremd/>', 'updatedBy' => 'andere'];
				throw new \RuntimeException('unique');
			}
			$this->rows[$this->next++] = compact('scope', 'userId', 'domain', 'xml', 'updatedBy');
		}
		public function update(int $id, string $xml, \DateTime $now, string $updatedBy): void {
			$this->rows[$id]['xml'] = $xml;
			$this->rows[$id]['updatedBy'] = $updatedBy;
		}
		public function delete(string $scope, string $userId, string $domain): void {
			$id = $this->findId($scope, $userId, $domain);
			unset($this->rows[$id]);
		}
		public function deleteAllForUser(string $scope, string $userId): void {
			$this->rows = array_filter($this->rows, static fn ($r) => !($r['scope'] === $scope && $r['userId'] === $userId));
		}
		public function listDomains(string $scope, ?string $userId = null, bool $sorted = false): array {
			$out = array_values(array_map(static fn ($r) => $r['domain'], array_filter($this->rows,
				static fn ($r) => $r['scope'] === $scope && ($userId === null || $r['userId'] === $userId))));
			if ($sorted) {
				sort($out);
			}
			return $out;
		}
		public function countByDomain(string $scope): array {
			$out = [];
			foreach ($this->rows as $r) {
				if ($r['scope'] === $scope) {
					$out[$r['domain']] = ($out[$r['domain']] ?? 0) + 1;
				}
			}
			return $out;
		}
	}

	/** Tabellen als Listen von Zeilen (Spalte => Wert). */
	final class Tables implements Database {
		/** @var array<string, list<array<string, mixed>>> */
		public array $tables = [];
		public int $transactions = 0;
		public int $rollbacks = 0;
		public ?string $failOn = null;

		public function transactional(callable $fn): mixed {
			$this->transactions++;
			$snapshot = $this->tables;
			try {
				return $fn();
			} catch (\Throwable $e) {
				$this->tables = $snapshot;
				$this->rollbacks++;
				throw $e;
			}
		}
		public function tableExists(string $table): bool {
			return isset($this->tables[$table]);
		}
		private function matches(array $row, array $equals, array $in): bool {
			foreach ($equals as $c => $v) {
				if (($row[$c] ?? null) !== $v) {
					return false;
				}
			}
			foreach ($in as $c => $ids) {
				if (!in_array($row[$c] ?? null, $ids, true)) {
					return false;
				}
			}
			return true;
		}
		public function selectIds(string $table, string $column, array $equals, array $in = []): array {
			return array_values(array_map(static fn ($r) => (int) $r[$column], array_filter($this->tables[$table] ?? [], fn ($r) => $this->matches($r, $equals, $in))));
		}
		public function delete(string $table, array $equals = [], array $in = []): int {
			if ($table === $this->failOn) {
				throw new \RuntimeException("Fehler in $table");
			}
			$before = count($this->tables[$table] ?? []);
			$this->tables[$table] = array_values(array_filter($this->tables[$table] ?? [], fn ($r) => !$this->matches($r, $equals, $in)));
			return $before - count($this->tables[$table]);
		}
		public function deleteWhereInSelect(string $table, string $column, string $subTable, array $subEquals): int {
			return $this->delete($table, [], [$column => $this->selectIds($subTable, 'id', $subEquals)]);
		}
		public function deleteWhereNotInSelect(string $table, string $column, string $subTable): int {
			$ids = $this->selectIds($subTable, 'id', []);
			$before = count($this->tables[$table]);
			$this->tables[$table] = array_values(array_filter($this->tables[$table], static fn ($r) => in_array($r[$column], $ids, true)));
			return $before - count($this->tables[$table]);
		}
	}

	final class Logger implements LoggerInterface {
		/** @var list<string> */
		public array $lines = [];
		public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message); }
		public function alert(string|\Stringable $message, array $context = []): void { $this->log('alert', $message); }
		public function critical(string|\Stringable $message, array $context = []): void { $this->log('critical', $message); }
		public function error(string|\Stringable $message, array $context = []): void { $this->log('error', $message); }
		public function warning(string|\Stringable $message, array $context = []): void { $this->log('warning', $message); }
		public function notice(string|\Stringable $message, array $context = []): void { $this->log('notice', $message); }
		public function info(string|\Stringable $message, array $context = []): void { $this->log('info', $message); }
		public function debug(string|\Stringable $message, array $context = []): void { $this->log('debug', $message); }
		public function log($level, string|\Stringable $message, array $context = []): void { $this->lines[] = "$level: $message"; }
	}
}

namespace {
	use Merlin\Core\ContentFilter\ContentFilterMerger;
	use Merlin\Core\ContentFilter\ContentFilterRepository;
	use Merlin\Core\Service\ArticleDeletionService;
	use Merlin\RepositoryTest\Logger;
	use Merlin\RepositoryTest\Store;
	use Merlin\RepositoryTest\Tables;

	$failed = 0;
	$check = static function (bool $ok, string $label, string $detail = '') use (&$failed): void {
		echo ($ok ? "  ✓ " : "  ✗ ") . $label . ($ok || $detail === '' ? '' : "\n      $detail") . "\n";
		if (!$ok) {
			$failed++;
		}
	};

	$bundle = dirname(__DIR__, 2) . '/content-filters';
	$store = new Store();
	$logger = new Logger();
	$repo = new ContentFilterRepository($store, $logger, new ContentFilterMerger($logger), ['/gibt/es/nicht', $bundle]);
	$titleOf = static fn (?\SimpleXMLElement $x): ?string => $x === null ? null : (string) ($x->metadata->title['xpath'] ?? '');

	echo "Bundle\n";
	$check($repo->getBundleDir() === realpath($bundle), 'erster vorhandener Bundle-Ordner gilt');
	$check($repo->readBundle('tagesspiegel.de') !== null && $repo->readBundle('gibt-es-nicht.example') === null, 'readBundle: Datei oder null');
	$check($repo->isUnsupportedDomain('www.t3n.de') === false && $repo->isUnsupportedDomain('t3n.de') === true, 'nicht unterstützte Domain aus $unsupported.xml');
	$check(in_array('Foto', $repo->getCaptionCreditPrefixes(), true), 'Signalwörter aus $caption-credits.xml');
	$check($repo->getMerged('foo.substack.com') !== null, 'Wildcard-Bundle _.substack.com greift für Subdomains');
	$threw = false;
	try {
		$repo->readBundle('../etc/passwd');
	} catch (\InvalidArgumentException) {
		$threw = true;
	}
	$check($threw, 'ungültiger Domainname wird abgelehnt');
	$noDir = new ContentFilterRepository($store, $logger, new ContentFilterMerger($logger), ['/gibt/es/nicht']);
	$check($noDir->getBundleDir() === null && $noDir->readBundle('spiegel.de') === null, 'ohne Bundle-Ordner: nichts, kein Fehler');

	echo "Ebenen\n";
	$domain = '<domain name="beispiel.example"><metadata><title xpath="//h1[@class=\'%s\']" /></metadata></domain>';
	$repo->saveAdminCustom('beispiel.example', sprintf($domain, 'admin'), 'root');
	$check($titleOf($repo->getMerged('beispiel.example')) === "//h1[@class='admin']", 'Admin-Custom ohne Bundle');
	$repo->saveUserCustom('alice', 'beispiel.example', sprintf($domain, 'alice'));
	$check($titleOf($repo->getMerged('beispiel.example', 'alice')) === "//h1[@class='alice']", 'User-Custom gewinnt für diesen Nutzer');
	$check($titleOf($repo->getMerged('beispiel.example', 'bob')) === "//h1[@class='admin']", '... aber nicht für andere');
	$repo->setPendingAdminCustom('beispiel.example', sprintf($domain, 'entwurf'));
	$check($titleOf($repo->getMerged('beispiel.example')) === "//h1[@class='entwurf']", 'ungespeicherter Admin-Entwurf gilt sofort (Testlauf)');
	$repo->saveAdminCustom('beispiel.example', sprintf($domain, 'admin2'), 'root');
	$check($titleOf($repo->getMerged('beispiel.example')) === "//h1[@class='admin2']" && count($store->rows) === 2, 'Speichern aktualisiert die Zeile und verwirft den Entwurf');
	$store->raceOnInsert = true;
	$repo->saveUserCustom('bob', 'beispiel.example', sprintf($domain, 'bob'));
	$bobId = $store->findId('user', 'bob', 'beispiel.example');
	$check($bobId !== null && $store->rows[$bobId]['xml'] === sprintf($domain, 'bob'), 'INSERT scheitert am Unique-Index: Rückfall auf UPDATE');
	$check($repo->listUserOverrideDomains('alice') === ['beispiel.example'], 'listUserOverrideDomains');
	$list = array_values(array_filter($repo->listFilters(), static fn ($e) => in_array($e['domain'], ['beispiel.example', 'spiegel.de'], true)));
	$check($list === [
		['domain' => 'beispiel.example', 'hasBundle' => false, 'hasCustom' => true, 'userOverrideCount' => 2],
		['domain' => 'spiegel.de', 'hasBundle' => true, 'hasCustom' => false, 'userOverrideCount' => 0],
	], 'listFilters: Herkunft und Anzahl Nutzer-Overrides', json_encode($list));
	$check(!in_array('$sample.com', array_column($repo->listFilters(), 'domain'), true), '$-Dateien stehen nicht in der Liste');

	$repo->deleteUserCustom('alice', 'beispiel.example');
	$check($titleOf($repo->getMerged('beispiel.example', 'alice')) === "//h1[@class='admin2']", 'User-Custom löschen: wieder Admin-Stand');
	$repo->deleteAllUserCustom('bob');
	$repo->deleteAdminCustom('beispiel.example');
	$check($store->rows === [] && $repo->getMerged('beispiel.example') === null, 'alles gelöscht: keine Config');

	$repo->saveAdminCustom('spiegel.de', '<domain name="spiegel.de"><metadata><title xpath="//kaputt[" /></metadata></domain>', 'root');
	$store->failReads = true;
	$fresh = new ContentFilterRepository($store, $logger, new ContentFilterMerger($logger), [$bundle]);
	$check($fresh->getMerged('spiegel.de') !== null && in_array('error: content-filters: Custom-Filter konnte nicht aus der DB gelesen werden', $logger->lines, true),
		'DB-Fehler beim Lesen: Bundle gilt weiter, Fehler im Log');
	$store->failReads = false;

	echo "Artikel löschen\n";
	$db = new Tables();
	$db->tables = [
		'merlin_articles' => [['id' => 1, 'user_id' => 'alice'], ['id' => 2, 'user_id' => 'alice'], ['id' => 3, 'user_id' => 'bob']],
		'merlin_highlights' => [['id' => 1, 'article_id' => 1, 'user_id' => 'alice'], ['id' => 2, 'article_id' => 3, 'user_id' => 'bob'], ['id' => 3, 'article_id' => 99, 'user_id' => 'alice']],
		'merlin_comments' => [['id' => 1, 'article_id' => 2, 'user_id' => 'alice']],
		'merlin_comment_guests' => [],
		'merlin_article_tags' => [['article_id' => 1, 'tag_id' => 10], ['article_id' => 3, 'tag_id' => 20], ['article_id' => 98, 'tag_id' => 20]],
		'merlin_shares' => [['id' => 1, 'article_id' => 2, 'user_id' => 'alice'], ['id' => 2, 'article_id' => 3, 'user_id' => 'bob']],
		'merlin_tags' => [['id' => 10, 'user_id' => 'alice'], ['id' => 20, 'user_id' => 'bob']],
	];
	$deletion = new ArticleDeletionService($db);
	$check($deletion->deleteArticles('alice', [1, 3, 3]) === 1, 'nur eigene Artikel werden gelöscht (3 gehört bob)');
	$check($db->selectIds('merlin_articles', 'id', []) === [2, 3] && $db->selectIds('merlin_highlights', 'id', []) === [2, 3]
		&& count($db->tables['merlin_article_tags']) === 2, '... samt Markierungen und Tag-Zuordnungen von Artikel 1');
	$db->failOn = 'merlin_shares';
	$threw = false;
	try {
		$deletion->deleteArticles('alice', [2]);
	} catch (\RuntimeException) {
		$threw = true;
	}
	$check($threw && $db->rollbacks === 1 && $db->selectIds('merlin_comments', 'id', []) === [1], 'Fehler mitten in der Kaskade: alles zurückgerollt');
	$db->failOn = null;
	$check($deletion->deleteOrphans() === 2, 'Waisen: Markierung an Artikel 99 und Tag-Zuordnung an 98');
	unset($db->tables['merlin_comment_guests']);
	$check($deletion->deleteOrphans() === 0, 'fehlende Tabelle (frühe Migration) wird übersprungen');
	$db->tables['merlin_comment_guests'] = [];
	$deletion->deleteAllForUser('bob');
	$check($db->selectIds('merlin_articles', 'id', []) === [2] && $db->selectIds('merlin_tags', 'id', []) === [10]
		&& $db->tables['merlin_article_tags'] === [] && $db->selectIds('merlin_shares', 'id', []) === [1], 'Nutzer löschen: Artikel, Tags, Zuordnungen, Shares von bob weg, alice bleibt');

	echo $failed === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$failed Prüfung(en) fehlgeschlagen.\n";
	exit($failed === 0 ? 0 : 1);
}
