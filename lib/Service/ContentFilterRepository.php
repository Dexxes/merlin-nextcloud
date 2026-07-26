<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Storage-Layer der Content-Filter: mitgelieferte Bundle-Filter bleiben Dateien
 * im App-Verzeichnis, admin- und nutzererstellte Custom-Filter liegen in der
 * DB-Tabelle merlin_cfilter. Liefert über getMerged() die dreistufig
 * zusammengeführte Config (Bundle < Admin-Custom < User-Custom), die der
 * ContentExtractorService konsumiert.
 *
 * Warum eine Tabelle mit scope-Spalte statt zweier getrennter Tabellen: Admin-
 * und User-Ebene unterscheiden sich nur in der WHERE-Bedingung, nicht im
 * Schema. user_id trägt für Admin-Zeilen den festen Sentinel
 * ADMIN_SENTINEL_USER_ID ('') statt NULL — ein Unique-Index über
 * (scope, domain, user_id) würde mit NULL je nach DB-Backend (MySQL/
 * PostgreSQL/SQLite laufen alle bei Merlin) unterschiedlich behandelt, das
 * wäre eine stille Portabilitätsfalle. Details: Migration
 * Version1000Date20240101000020.
 *
 * Custom-Filter aus dem alten dateibasierten Speicher (content-filters/custom/)
 * werden NICHT migriert (siehe tasks/content-filter-db-scopes-todo.md,
 * Entscheidungstabelle) — sie werden schlicht ignoriert.
 */
class ContentFilterRepository {

	private const TABLE = 'merlin_cfilter';

	/** @see Migration Version1000Date20240101000020 */
	public const SCOPE_ADMIN = 'admin';
	public const SCOPE_USER  = 'user';

	/**
	 * Fester user_id-Wert für Admin-Zeilen, damit der Unique-Index
	 * (scope, domain, user_id) ohne NULL-Sonderfall auskommt (siehe
	 * Klassen-Docblock).
	 */
	public const ADMIN_SENTINEL_USER_ID = '';

	/**
	 * Dateinamen-Präfix für Dateien im Bundle-Ordner, die keine Domain-Config sind.
	 *
	 * Aktuell zwei Fälle: 000.sample.com.xml ist die kommentierte Referenz aller
	 * Regeltypen, 000dead.xml eine Parkliste toter Domains (mehrere
	 * <domain>-Elemente, also bewusst kein gültiges XML-Dokument). Beide dürfen
	 * weder in der Filterliste auftauchen noch geparst werden.
	 */
	private const DOC_FILE_PREFIX = '000';

	/**
	 * Gecachte Merge-Ergebnisse pro (Domain, Nutzer) innerhalb eines Requests.
	 *
	 * Schlüssel: "{domain}|{userId}" (userId leer bei anonymem Aufruf ohne
	 * Nutzerkontext). Warum pro Nutzer und nicht nur pro Domain: seit der
	 * User-Custom-Ebene hinzukam, liefert getMerged() für dieselbe Domain je
	 * nach aufrufendem Nutzer ein anderes Ergebnis. Ein reiner Domain-Schlüssel
	 * würde den privaten Override eines Nutzers fälschlich an einen anderen
	 * Nutzer weiterreichen, falls ein Service je request-übergreifend gehalten
	 * würde (PHP-FPM-Worker-Reuse) — unwahrscheinlich, aber der Cache wäre sonst
	 * ein Datenleck zwischen Nutzern.
	 *
	 * @var array<string,\SimpleXMLElement|null>
	 */
	private array $mergedCache = [];

	/**
	 * Noch nicht gespeicherter Admin-Custom-Entwurf für die Dauer DIESES
	 * Requests, keyed nach Domain.
	 *
	 * Warum: Der Testlauf in der Admin-UI soll den gerade bearbeiteten Filter
	 * ausprobieren können, BEVOR er gespeichert wird.
	 *
	 * @var array<string,string|null> Domain => XML, oder null für "kein Admin-Custom"
	 */
	private array $pendingAdminCustom = [];

	/**
	 * Wie $pendingAdminCustom, aber für die private User-Ebene, keyed nach
	 * "{domain}|{userId}".
	 *
	 * @var array<string,string|null>
	 */
	private array $pendingUserCustom = [];

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
		private ContentFilterMerger $merger,
	) {
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Pfade (Bundle bleibt Datei)
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Absoluter Pfad des Bundle-Ordners oder null, wenn er nicht auflösbar ist.
	 *
	 * realpath() löst die ../..-Traversierung auf, damit open_basedir-
	 * Beschränkungen und symlinkte App-Verzeichnisse nicht stören.
	 */
	public function getBundleDir(): ?string {
		$dir = realpath(__DIR__ . '/../../content-filters');
		return $dir === false ? null : $dir;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Domain-Validierung
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Prüft einen Domainnamen, bevor er zu einem Dateipfad oder DB-Wert wird.
	 *
	 * Der Domainname kommt aus einem HTTP-Request. Die Whitelist-Regex lässt
	 * ausschliesslich Labels aus [a-z0-9-] mit Punkten dazwischen zu – damit
	 * sind Verzeichniswechsel ('/', '\', '..'), Null-Bytes und absolute Pfade
	 * strukturell ausgeschlossen, nicht nur herausgefiltert (relevant für
	 * readBundle(), das den Wert weiterhin an einen Dateipfad anhängt).
	 */
	public function isValidDomain(string $domain): bool {
		if ($domain === '' || strlen($domain) > 253) {
			return false;
		}
		if ($domain !== strtolower($domain)) {
			return false;
		}
		return preg_match(
			'/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',
			$domain
		) === 1;
	}

	/**
	 * Normalisiert einen URL-Host auf den Domainnamen, unter dem der Filter
	 * gesucht wird: kleingeschrieben, ohne www.-Präfix, keine weitere
	 * Subdomain-Reduktion (exakter Match).
	 *
	 * Liegt hier und nicht im ContentExtractorService, weil Admin- und
	 * Personal-UI dieselbe Normalisierung brauchen: sie müssen prüfen, ob eine
	 * Test-URL überhaupt zu dem Filter gehört, der gerade bearbeitet wird.
	 */
	public function normalizeUrlDomain(string $url): string {
		$host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
		return (string) preg_replace('/^www\./i', '', $host);
	}

	/**
	 * @throws \InvalidArgumentException bei ungültigem Domainnamen
	 */
	private function assertValidDomain(string $domain): void {
		if (!$this->isValidDomain($domain)) {
			throw new \InvalidArgumentException('Ungültiger Domainname: ' . $domain);
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Lesen: Bundle
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Rohes Bundle-XML einer Domain oder null.
	 *
	 * Die Dokumentationsdatei 000.sample.com.xml wird über getDocumentationXml()
	 * geladen, nicht hier – sie ist keine Domain-Config.
	 */
	public function readBundle(string $domain): ?string {
		$this->assertValidDomain($domain);
		$dir = $this->getBundleDir();
		return $dir === null ? null : $this->readFile($dir . '/' . $domain . '.xml');
	}

	/** Die kommentierte Referenzdatei 000.sample.com.xml oder null. */
	public function getDocumentationXml(): ?string {
		$dir = $this->getBundleDir();
		return $dir === null ? null : $this->readFile($dir . '/' . self::DOC_FILE_PREFIX . '.sample.com.xml');
	}

	public function hasBundle(string $domain): bool {
		return $this->readBundle($domain) !== null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Lesen: Admin-Custom (scope='admin', DB)
	// ──────────────────────────────────────────────────────────────────────────

	/** Rohes Admin-Custom-XML einer Domain oder null. */
	public function readAdminCustom(string $domain): ?string {
		$this->assertValidDomain($domain);

		// Ein per setPendingAdminCustom() gesetzter Entwurf hat Vorrang, damit
		// der Testlauf den ungespeicherten Stand sieht.
		if (array_key_exists($domain, $this->pendingAdminCustom)) {
			return $this->pendingAdminCustom[$domain];
		}

		return $this->readCustomRow(self::SCOPE_ADMIN, self::ADMIN_SENTINEL_USER_ID, $domain);
	}

	/**
	 * Setzt einen ungespeicherten Admin-Custom-Filter für die Dauer dieses
	 * Requests. $xml === null bedeutet "so tun, als gäbe es keinen".
	 *
	 * @throws \InvalidArgumentException bei ungültigem Domainnamen
	 */
	public function setPendingAdminCustom(string $domain, ?string $xml): void {
		$this->assertValidDomain($domain);
		$this->pendingAdminCustom[$domain] = $xml;
		$this->invalidateMergedCacheForDomain($domain);
	}

	public function hasCustom(string $domain): bool {
		return $this->readAdminCustom($domain) !== null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Lesen: User-Custom (scope='user', DB)
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Rohes User-Custom-XML einer Domain für genau diesen Nutzer, oder null.
	 *
	 * $userId muss serverseitig aus der Session stammen (Controller reicht
	 * IUserSession::getUser()?->getUID() durch) – niemals aus einem
	 * Request-Parameter, sonst könnte ein Nutzer den Filter eines anderen
	 * abfragen.
	 */
	public function readUserCustom(string $domain, string $userId): ?string {
		$this->assertValidDomain($domain);

		$pendingKey = $this->pendingKey($domain, $userId);
		if (array_key_exists($pendingKey, $this->pendingUserCustom)) {
			return $this->pendingUserCustom[$pendingKey];
		}

		return $this->readCustomRow(self::SCOPE_USER, $userId, $domain);
	}

	public function userHasOverride(string $domain, string $userId): bool {
		return $this->readUserCustom($domain, $userId) !== null;
	}

	/**
	 * Setzt einen ungespeicherten User-Custom-Filter für die Dauer dieses
	 * Requests (Testlauf in der Personal-Settings-UI, analog
	 * setPendingAdminCustom()).
	 *
	 * @throws \InvalidArgumentException bei ungültigem Domainnamen
	 */
	public function setPendingUserCustom(string $userId, string $domain, ?string $xml): void {
		$this->assertValidDomain($domain);
		$this->pendingUserCustom[$this->pendingKey($domain, $userId)] = $xml;
		unset($this->mergedCache[$this->cacheKey($domain, $userId)]);
	}

	/**
	 * Domains, für die $userId einen eigenen Override hat, alphabetisch.
	 *
	 * @return list<string>
	 */
	public function listUserOverrideDomains(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('domain')
			->from(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_USER)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('domain');

		$result = $qb->executeQuery();
		$out    = array_column($result->fetchAll(), 'domain');
		$result->closeCursor();
		return $out;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Liste + Merge
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Alle bekannten Domains mit ihrer Herkunft, alphabetisch sortiert. Für die
	 * Admin-UI: userOverrideCount zeigt nur die ANZAHL an Nutzern mit eigenem
	 * Override, nie deren Inhalt (siehe tasks/content-filter-db-scopes-todo.md,
	 * Entscheidungstabelle: User-Filter sind komplett privat).
	 *
	 * @return list<array{domain:string,hasBundle:bool,hasCustom:bool,userOverrideCount:int}>
	 */
	public function listFilters(): array {
		$domains = [];

		$bundleDir = $this->getBundleDir();
		if (is_string($bundleDir) && is_dir($bundleDir)) {
			foreach ((glob($bundleDir . '/*.xml') ?: []) as $path) {
				$name = basename($path, '.xml');
				if (str_starts_with($name, self::DOC_FILE_PREFIX)) {
					continue;
				}
				if (!$this->isValidDomain($name)) {
					$this->logger->warning('content-filters: Datei mit ungültigem Domainnamen übersprungen', [
						'file' => $path,
					]);
					continue;
				}
				$domains[$name] ??= $this->emptyListEntry($name);
				$domains[$name]['hasBundle'] = true;
			}
		}

		foreach ($this->adminCustomDomains() as $name) {
			$domains[$name] ??= $this->emptyListEntry($name);
			$domains[$name]['hasCustom'] = true;
		}

		foreach ($this->userOverrideCounts() as $name => $count) {
			$domains[$name] ??= $this->emptyListEntry($name);
			$domains[$name]['userOverrideCount'] = $count;
		}

		ksort($domains);
		return array_values($domains);
	}

	/** @return array{domain:string,hasBundle:bool,hasCustom:bool,userOverrideCount:int} */
	private function emptyListEntry(string $domain): array {
		return ['domain' => $domain, 'hasBundle' => false, 'hasCustom' => false, 'userOverrideCount' => 0];
	}

	/**
	 * Zusammengeführte Config einer Domain für einen bestimmten Nutzer
	 * (Bundle < Admin-Custom < User-Custom), oder null, wenn es für die Domain
	 * gar keine Config gibt.
	 *
	 * $userId === null (kein Nutzerkontext, z. B. anonymer Aufruf) überspringt
	 * die dritte Ebene – das Ergebnis ist dann Bundle+Admin, wie vor dieser
	 * Erweiterung.
	 *
	 * Rückgabewert ist ein SimpleXMLElement, damit alle bestehenden Konsumenten
	 * in ContentExtractorService unverändert weiterarbeiten.
	 */
	public function getMerged(string $domain, ?string $userId = null): ?\SimpleXMLElement {
		if ($domain === '' || !$this->isValidDomain($domain)) {
			return null;
		}

		$cacheKey = $this->cacheKey($domain, $userId ?? '');
		if (array_key_exists($cacheKey, $this->mergedCache)) {
			return $this->mergedCache[$cacheKey];
		}

		$bundle      = $this->readBundle($domain);
		$adminCustom = $this->readAdminCustom($domain);
		$userCustom  = $userId !== null ? $this->readUserCustom($domain, $userId) : null;

		$withAdminXml = $this->mergeBundleAndAdmin($bundle, $adminCustom, $domain);
		$final        = $this->mergeWithUser($withAdminXml, $userCustom, $domain);

		return $this->mergedCache[$cacheKey] = $final;
	}

	/**
	 * Stufe 1: Bundle + Admin-Custom, als String (Basis für Stufe 2).
	 *
	 * Fail-open wie im ursprünglichen zweistufigen Merge: Ein kaputter
	 * Admin-Custom-Filter darf eine Domain, die vorher funktionierte, nicht
	 * unlesbar machen; ist auch das Bundle unbrauchbar, bleibt am Ende null.
	 */
	private function mergeBundleAndAdmin(?string $bundle, ?string $adminCustom, string $domain): ?string {
		try {
			return $this->merger->mergeToString($bundle, $adminCustom, $domain, ContentFilterSchema::ORIGIN_ADMIN);
		} catch (\Throwable $e) {
			$this->logger->error('content-filters: Admin-Merge fehlgeschlagen, Admin-Custom-Filter wird ignoriert', [
				'domain'    => $domain,
				'exception' => $e,
			]);
			try {
				return $bundle === null
					? null
					: $this->merger->mergeToString($bundle, null, $domain, ContentFilterSchema::ORIGIN_ADMIN);
			} catch (\Throwable $inner) {
				$this->logger->error('content-filters: auch der mitgelieferte Filter ist unlesbar', [
					'domain'    => $domain,
					'exception' => $inner,
				]);
				return null;
			}
		}
	}

	/**
	 * Stufe 2: (Bundle+Admin) + User-Custom. Gleiches Fail-open-Muster wie
	 * Stufe 1, eine Ebene höher: ein kaputter User-Filter fällt auf das
	 * Bundle+Admin-Ergebnis zurück, nie auf eine Exception, die den Artikel-
	 * Import abbricht.
	 */
	private function mergeWithUser(?string $withAdminXml, ?string $userCustom, string $domain): ?\SimpleXMLElement {
		try {
			return $this->merger->merge($withAdminXml, $userCustom, $domain, ContentFilterSchema::ORIGIN_USER);
		} catch (\Throwable $e) {
			$this->logger->error('content-filters: User-Merge fehlgeschlagen, User-Custom-Filter wird ignoriert', [
				'domain'    => $domain,
				'exception' => $e,
			]);
			try {
				return $withAdminXml === null
					? null
					: $this->merger->merge($withAdminXml, null, $domain, ContentFilterSchema::ORIGIN_USER);
			} catch (\Throwable $inner) {
				$this->logger->error('content-filters: auch das Bundle+Admin-Ergebnis ist unlesbar', [
					'domain'    => $domain,
					'exception' => $inner,
				]);
				return null;
			}
		}
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Schreiben: Admin-Custom
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Speichert den Admin-Custom-Filter einer Domain. Das XML muss vorher
	 * validiert worden sein (ContentFilterValidator) – diese Methode prüft nur
	 * noch den Domainnamen.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Domainnamen
	 */
	public function saveAdminCustom(string $domain, string $xml, string $updatedBy): void {
		$this->assertValidDomain($domain);
		$this->upsert(self::SCOPE_ADMIN, self::ADMIN_SENTINEL_USER_ID, $domain, $xml, $updatedBy);
		unset($this->pendingAdminCustom[$domain]);
		$this->invalidateMergedCacheForDomain($domain);
	}

	/** Löscht den Admin-Custom-Filter einer Domain. Kein Fehler, wenn es keinen gibt. */
	public function deleteAdminCustom(string $domain): void {
		$this->assertValidDomain($domain);
		$this->deleteRow(self::SCOPE_ADMIN, self::ADMIN_SENTINEL_USER_ID, $domain);
		unset($this->pendingAdminCustom[$domain]);
		$this->invalidateMergedCacheForDomain($domain);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Schreiben: User-Custom
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Speichert den privaten Custom-Filter einer Domain für genau diesen
	 * Nutzer. $userId MUSS serverseitig aus der Session stammen (siehe
	 * readUserCustom()) – der Aufrufer (UserContentFilterController) ist dafür
	 * verantwortlich, hier nie einen Request-Parameter durchzureichen.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Domainnamen
	 */
	public function saveUserCustom(string $userId, string $domain, string $xml): void {
		$this->assertValidDomain($domain);
		$this->upsert(self::SCOPE_USER, $userId, $domain, $xml, $userId);
		unset($this->pendingUserCustom[$this->pendingKey($domain, $userId)]);
		unset($this->mergedCache[$this->cacheKey($domain, $userId)]);
	}

	/** Löscht den privaten Custom-Filter eines Nutzers für eine Domain. */
	public function deleteUserCustom(string $userId, string $domain): void {
		$this->assertValidDomain($domain);
		$this->deleteRow(self::SCOPE_USER, $userId, $domain);
		unset($this->pendingUserCustom[$this->pendingKey($domain, $userId)]);
		unset($this->mergedCache[$this->cacheKey($domain, $userId)]);
	}

	/**
	 * Entfernt ALLE privaten Overrides eines Nutzers, domainübergreifend.
	 * Aufgerufen von UserDeletedListener bei Nutzerlöschung.
	 */
	public function deleteAllUserCustom(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_USER)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
		// Kein gezieltes Cache-Invalidieren nötig: $mergedCache ist request-lokal
		// und eine Nutzerlöschung läuft nie im selben Request wie ein
		// vorheriger getMerged()-Aufruf desselben Nutzers.
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Intern: DB-Zugriff
	// ──────────────────────────────────────────────────────────────────────────

	private function readCustomRow(string $scope, string $userId, string $domain): ?string {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('xml')
				->from(self::TABLE)
				->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
				->andWhere($qb->expr()->eq('domain', $qb->createNamedParameter($domain)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

			$result = $qb->executeQuery();
			$xml    = $result->fetchOne();
			$result->closeCursor();

			return $xml === false ? null : (string) $xml;
		} catch (\Throwable $e) {
			$this->logger->error('content-filters: Custom-Filter konnte nicht aus der DB gelesen werden', [
				'scope'     => $scope,
				'domain'    => $domain,
				'exception' => $e,
			]);
			return null;
		}
	}

	private function findRowId(string $scope, string $userId, string $domain): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->andWhere($qb->expr()->eq('domain', $qb->createNamedParameter($domain)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$id     = $result->fetchOne();
		$result->closeCursor();

		return $id === false ? null : (int) $id;
	}

	/**
	 * Legt eine Custom-Zeile an oder aktualisiert sie (SELECT-dann-INSERT/UPDATE,
	 * keine native Upsert-Syntax nötig).
	 *
	 * Race-Window zwischen dem SELECT und dem INSERT: schreiben zwei Requests
	 * gleichzeitig dieselbe (scope, domain, user_id)-Kombination zum ersten
	 * Mal, kann der zweite INSERT am Unique-Index (siehe Migration) scheitern.
	 * Statt den Request scheitern zu lassen, wird dann einmalig auf UPDATE
	 * zurückgefallen – der zweite Schreiber gewinnt, wie er es bei einer echten
	 * Race Condition ohnehin täte.
	 */
	private function upsert(string $scope, string $userId, string $domain, string $xml, string $updatedBy): void {
		$existingId = $this->findRowId($scope, $userId, $domain);
		$now        = new \DateTime();

		if ($existingId !== null) {
			$this->updateRow($existingId, $xml, $now, $updatedBy);
			return;
		}

		try {
			$this->insertRow($scope, $userId, $domain, $xml, $now, $updatedBy);
		} catch (\Throwable $e) {
			$retryId = $this->findRowId($scope, $userId, $domain);
			if ($retryId === null) {
				throw $e;
			}
			$this->updateRow($retryId, $xml, $now, $updatedBy);
		}
	}

	private function insertRow(string $scope, string $userId, string $domain, string $xml, \DateTime $now, string $updatedBy): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'scope'      => $qb->createNamedParameter($scope),
				'user_id'    => $qb->createNamedParameter($userId),
				'domain'     => $qb->createNamedParameter($domain),
				'xml'        => $qb->createNamedParameter($xml),
				'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
				'updated_by' => $qb->createNamedParameter($updatedBy),
			]);
		$qb->executeStatement();
	}

	private function updateRow(int $id, string $xml, \DateTime $now, string $updatedBy): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('xml', $qb->createNamedParameter($xml))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			->set('updated_by', $qb->createNamedParameter($updatedBy))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	private function deleteRow(string $scope, string $userId, string $domain): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->andWhere($qb->expr()->eq('domain', $qb->createNamedParameter($domain)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/** @return list<string> */
	private function adminCustomDomains(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('domain')
			->from(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_ADMIN)));

		$result = $qb->executeQuery();
		$out    = array_column($result->fetchAll(), 'domain');
		$result->closeCursor();
		return $out;
	}

	/** @return array<string,int> domain => Anzahl Nutzer mit eigenem Override */
	private function userOverrideCounts(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('domain')
			->selectAlias($qb->func()->count('user_id'), 'cnt')
			->from(self::TABLE)
			->where($qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_USER)))
			->groupBy('domain');

		$result = $qb->executeQuery();
		$out    = [];
		foreach ($result->fetchAll() as $row) {
			$out[(string) $row['domain']] = (int) $row['cnt'];
		}
		$result->closeCursor();
		return $out;
	}

	private function pendingKey(string $domain, string $userId): string {
		return $domain . '|' . $userId;
	}

	private function cacheKey(string $domain, string $userId): string {
		return $domain . '|' . $userId;
	}

	/**
	 * Verwirft alle gecachten Merge-Ergebnisse einer Domain, über alle Nutzer
	 * hinweg. Nötig, weil eine Änderung am Admin-Layer JEDEN nutzerspezifischen
	 * Merge dieser Domain betrifft, nicht nur den anonymen Fall.
	 */
	private function invalidateMergedCacheForDomain(string $domain): void {
		$prefix = $domain . '|';
		foreach (array_keys($this->mergedCache) as $key) {
			if (str_starts_with($key, $prefix)) {
				unset($this->mergedCache[$key]);
			}
		}
	}

	/**
	 * Liest eine Bundle-Datei, oder null wenn sie fehlt bzw. nicht lesbar ist.
	 */
	private function readFile(string $path): ?string {
		if (!is_file($path)) {
			return null;
		}
		$raw = @file_get_contents($path);
		if ($raw === false) {
			$this->logger->warning('content-filters: Datei nicht lesbar', ['file' => $path]);
			return null;
		}
		return $raw;
	}
}
