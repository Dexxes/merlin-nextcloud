<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\ContentFilter\ContentFilterMerger;
use Merlin\Core\ContentFilter\ContentFilterSchema;
use Merlin\Core\ContentFilter\ContentFilterSerializer;
use Merlin\Core\ContentFilter\ContentFilterTrace;
use Merlin\Core\ContentFilter\ContentFilterValidator;
use Merlin\Core\Extraction\ContentExtractorService;
use Merlin\Core\Extraction\UnsupportedSiteException;
use Merlin\Core\Port\ContentFilterEditor;
use Psr\Log\LoggerInterface;

/**
 * Content-Filter für beide Varianten: Verwaltung durch Admins
 * (/api/admin/content-filters…, gilt instanzweit) und die persönliche dritte
 * Ebene jedes Nutzers (/api/user/content-filters…, Bundle < Admin-Custom <
 * User-Custom). Antworten wie bisher in ContentFilterController und
 * UserContentFilterController.
 *
 * Beide Gruppen laufen durch denselben ContentFilterValidator (XXE aus,
 * Header-Allowlist, XPath-Kompilierprüfung, Größenlimit) und denselben
 * SSRF-geschützten Abruf im Testlauf. Die Nutzer-Methoden lesen und schreiben
 * nur die Zeile des angemeldeten Nutzers ($request->userId(), nie ein
 * Request-Parameter).
 *
 * Beide Gruppen bedienen nur die Weboberfläche: in der Routentabelle 'csrf',
 * Nextcloud verlangt dafür den requesttoken.
 */
final class ContentFilterApi {
	public function __construct(
		private ContentFilterEditor $repository,
		private ContentFilterValidator $validator,
		private ContentFilterSerializer $serializer,
		private ContentFilterMerger $merger,
		private ContentExtractorService $extractor,
		private LoggerInterface $logger,
	) {
	}

	// ── Admin ────────────────────────────────────────────────────────────────

	/** Alle bekannten Filter plus die Grammatik für den Regel-Builder. */
	public function index(ApiRequest $request): ApiResult {
		return ApiResult::json([
			'filters' => $this->repository->listFilters(),
			'schema' => ContentFilterSchema::describe(),
		]);
	}

	/**
	 * Bundle-, Custom- und Merge-Stand einer Domain. Der Bundle-Teil ist
	 * read-only (die UI zeigt die Regeln an und macht sie einzeln
	 * abschaltbar), der Merge-Teil zeigt, was der Extractor sieht.
	 */
	public function show(ApiRequest $request): ApiResult {
		$domain = self::domain($request);
		if (!$this->repository->isValidDomain($domain)) {
			return self::message('Ungültiger Domainname.', 400);
		}
		return $this->showAdmin($domain);
	}

	/**
	 * Speichert den Custom-Filter einer Domain: Builder-Struktur (rules) oder
	 * rohes XML (xml), beides über denselben Validierungspfad.
	 */
	public function update(ApiRequest $request): ApiResult {
		$domain = self::domain($request);
		if (!$this->repository->isValidDomain($domain)) {
			return self::message('Ungültiger Domainname.', 400);
		}
		try {
			$xml = $this->buildXml($request, $domain);
		} catch (\InvalidArgumentException $e) {
			return self::message($e->getMessage(), 400);
		}
		$errors = $this->validator->validate($xml, $domain);
		if ($errors !== []) {
			return ApiResult::json(['message' => 'Der Filter ist ungültig.', 'errors' => $errors], 400);
		}
		try {
			$this->repository->saveAdminCustom($domain, $xml, $request->userId());
		} catch (\Throwable $e) {
			$this->logger->error('content-filters: Speichern fehlgeschlagen', ['domain' => $domain, 'exception' => $e]);
			return self::message($e->getMessage(), 500);
		}
		return $this->showAdmin($domain);
	}

	/** Löscht den Admin-Custom-Filter einer Domain; der Bundle-Filter bleibt. */
	public function destroy(ApiRequest $request): ApiResult {
		$domain = self::domain($request);
		if (!$this->repository->isValidDomain($domain)) {
			return self::message('Ungültiger Domainname.', 400);
		}
		try {
			$this->repository->deleteAdminCustom($domain);
		} catch (\Throwable $e) {
			return self::message($e->getMessage(), 500);
		}
		return ApiResult::json(['domain' => $domain, 'deleted' => true]);
	}

	/**
	 * Importiert eine Filterdatei. Fehlt der Domainname, kommt er aus dem
	 * name-Attribut des Wurzelelements; dann ersetzt er bestehende eigene
	 * Regeln nur mit overwrite (ein falsch gesetztes name-Attribut würde sonst
	 * die Arbeit an einer ganz anderen Domain überschreiben).
	 */
	public function import(ApiRequest $request): ApiResult {
		$xml = (string) ($request->string('xml') ?? '');
		if (trim($xml) === '') {
			return self::message('Es wurde kein XML übergeben.', 400);
		}
		$domain = trim((string) ($request->string('domain') ?? ''));
		$derived = false;
		if ($domain === '') {
			$domain = self::domainFromXml($xml);
			$derived = true;
		}
		if ($domain === '' || !$this->repository->isValidDomain($domain)) {
			return self::message('Der Domainname konnte nicht bestimmt werden. Erwartet wird <domain name="beispiel.de">.', 400);
		}
		if ($derived
			&& $this->repository->hasCustom($domain)
			&& !filter_var($request->param('overwrite'), FILTER_VALIDATE_BOOLEAN)
		) {
			return ApiResult::json([
				'message' => sprintf(
					'Für "%s" gibt es bereits eigene Regeln. Der Domainname stammt aus dem '
					. 'name-Attribut der Datei – bitte bestätigen, dass sie ersetzt werden sollen.',
					$domain
				),
				'domain' => $domain,
				'code' => 'custom_filter_exists',
			], 409);
		}
		$errors = $this->validator->validate($xml, $domain);
		if ($errors !== []) {
			return ApiResult::json(['message' => 'Die Datei ist ungültig.', 'domain' => $domain, 'errors' => $errors], 400);
		}
		try {
			$this->repository->saveAdminCustom($domain, $xml, $request->userId());
		} catch (\Throwable $e) {
			return self::message($e->getMessage(), 500);
		}
		return $this->showAdmin($domain);
	}

	/**
	 * Admin-Custom-Filter als XML-Datei; gibt es keinen, den Bundle-Filter
	 * (als Ausgangspunkt für eigene Regeln).
	 */
	public function export(ApiRequest $request): ApiResult {
		$domain = self::domain($request);
		if (!$this->repository->isValidDomain($domain)) {
			return self::message('Ungültiger Domainname.', 400);
		}
		$xml = $this->repository->readAdminCustom($domain) ?? $this->repository->readBundle($domain);
		if ($xml === null) {
			return self::message('Für diese Domain existiert kein Filter.', 404);
		}
		return ApiResult::body($xml, 'application/xml', 200, [
			'Content-Disposition' => 'attachment; filename="' . rawurlencode($domain . '.xml') . '"',
		]);
	}

	/**
	 * Testlauf: extrahiert einen Artikel mit dem Filter dieser Domain und
	 * liefert das Ergebnis samt Trefferzahl je Regel, ohne zu speichern. Mit
	 * rules oder xml im Request wird dieser ungespeicherte Stand getestet.
	 * Bewusst ohne Nutzer: geprüft wird Bundle + Admin-Custom, nicht ein
	 * privater Override des testenden Admins.
	 */
	public function test(ApiRequest $request): ApiResult {
		return $this->runTest($request, null);
	}

	// ── Persönliche Overrides ────────────────────────────────────────────────

	/**
	 * Alle Domains (Bundle und/oder Admin-Custom), markiert mit einem eigenen
	 * Override, plus die Grammatik. Dieselbe Liste wie für Admins (nur
	 * Existenz-Flags), damit der Nutzer auch Domains ohne eigenen Override
	 * wählen kann.
	 */
	public function userIndex(ApiRequest $request): ApiResult {
		$own = array_flip($this->repository->listUserOverrideDomains($request->userId()));
		$domains = array_map(static fn (array $entry): array => [
			'domain' => $entry['domain'],
			'hasBundle' => $entry['hasBundle'],
			'hasAdminCustom' => $entry['hasCustom'],
			'hasOwnOverride' => isset($own[$entry['domain']]),
		], $this->repository->listFilters());
		return ApiResult::json([
			'domains' => $domains,
			'schema' => ContentFilterSchema::describe(),
		]);
	}

	/** Bundle + Admin-Custom (read-only Referenz), eigener Override, gemergter Stand. */
	public function userShow(ApiRequest $request): ApiResult {
		$domain = self::domain($request);
		if (!$this->repository->isValidDomain($domain)) {
			return self::message('Ungültiger Domainname.', 400);
		}
		return $this->showUser($domain, $request->userId());
	}

	/** Speichert den eigenen Custom-Filter einer Domain. */
	public function userUpdate(ApiRequest $request): ApiResult {
		$domain = self::domain($request);
		if (!$this->repository->isValidDomain($domain)) {
			return self::message('Ungültiger Domainname.', 400);
		}
		try {
			$xml = $this->buildXml($request, $domain);
		} catch (\InvalidArgumentException $e) {
			return self::message($e->getMessage(), 400);
		}
		$errors = $this->validator->validate($xml, $domain);
		if ($errors !== []) {
			return ApiResult::json(['message' => 'Der Filter ist ungültig.', 'errors' => $errors], 400);
		}
		try {
			$this->repository->saveUserCustom($request->userId(), $domain, $xml);
		} catch (\Throwable $e) {
			$this->logger->error('content-filters: Speichern des User-Overrides fehlgeschlagen', ['domain' => $domain, 'exception' => $e]);
			return self::message($e->getMessage(), 500);
		}
		return $this->showUser($domain, $request->userId());
	}

	/** Löscht den eigenen Custom-Filter einer Domain. */
	public function userDestroy(ApiRequest $request): ApiResult {
		$domain = self::domain($request);
		if (!$this->repository->isValidDomain($domain)) {
			return self::message('Ungültiger Domainname.', 400);
		}
		try {
			$this->repository->deleteUserCustom($request->userId(), $domain);
		} catch (\Throwable $e) {
			return self::message($e->getMessage(), 500);
		}
		return ApiResult::json(['domain' => $domain, 'deleted' => true]);
	}

	/** Wie test(), aber mit allen drei Ebenen des angemeldeten Nutzers. */
	public function userTest(ApiRequest $request): ApiResult {
		return $this->runTest($request, $request->userId());
	}

	// ── Intern ───────────────────────────────────────────────────────────────

	private function showAdmin(string $domain): ApiResult {
		$bundle = $this->repository->readBundle($domain);
		$custom = $this->repository->readAdminCustom($domain);
		if ($bundle === null && $custom === null) {
			return self::message('Für diese Domain existiert kein Filter.', 404);
		}
		$payload = [
			'domain' => $domain,
			'bundle' => $this->describeFilter($bundle),
			'custom' => $this->describeFilter($custom),
			'merged' => null,
		];
		try {
			$payload['merged'] = $this->describeFilter(
				$this->merger->mergeToString($bundle, $custom, $domain, ContentFilterSchema::ORIGIN_ADMIN)
			);
		} catch (\Throwable $e) {
			// Scheitert nur an einer kaputten Custom-Datei: die UI zeigt den Fehler.
			$payload['mergeError'] = $e->getMessage();
		}
		return ApiResult::json($payload);
	}

	private function showUser(string $domain, string $userId): ApiResult {
		$bundle = $this->repository->readBundle($domain);
		$admin = $this->repository->readAdminCustom($domain);
		$own = $this->repository->readUserCustom($domain, $userId);
		if ($bundle === null && $admin === null && $own === null) {
			return self::message('Für diese Domain existiert kein Filter.', 404);
		}
		$payload = [
			'domain' => $domain,
			'reference' => null, // Bundle + Admin-Custom, read-only
			'own' => $this->describeFilter($own),
			'merged' => null,
		];
		try {
			$referenceXml = $this->merger->mergeToString($bundle, $admin, $domain, ContentFilterSchema::ORIGIN_ADMIN);
			$payload['reference'] = $this->describeFilter($referenceXml);
			$payload['merged'] = $this->describeFilter(
				$this->merger->mergeToString($referenceXml, $own, $domain, ContentFilterSchema::ORIGIN_USER)
			);
		} catch (\Throwable $e) {
			$payload['mergeError'] = $e->getMessage();
		}
		return ApiResult::json($payload);
	}

	/**
	 * Testlauf über ContentExtractorService::extract(), also denselben Pfad
	 * wie ein normaler Import samt SSRF-Prüfung und DNS-Pinning, ohne
	 * Ausnahme für interne Adressen. $userId null: Bundle + Admin-Custom
	 * (Entwurf als Admin-Ebene), sonst alle drei Ebenen (Entwurf als
	 * Nutzer-Ebene).
	 */
	private function runTest(ApiRequest $request, ?string $userId): ApiResult {
		$domain = self::domain($request);
		if (!$this->repository->isValidDomain($domain)) {
			return self::message('Ungültiger Domainname.', 400);
		}
		$url = trim((string) ($request->string('url') ?? ''));
		if ($url === '') {
			return self::message('Es wurde keine Test-URL übergeben.', 400);
		}
		// Der Extractor sucht den Filter nach dem Host der URL: eine URL einer
		// anderen Domain testete einen anderen Filter als den geöffneten.
		$urlDomain = $this->repository->normalizeUrlDomain($url);
		if ($urlDomain === '') {
			return self::message('Die URL enthält keinen Hostnamen.', 400);
		}
		if (!$this->repository->domainMatchesFilterKey($urlDomain, $domain)) {
			return self::message(sprintf('Die URL gehört zu "%s", getestet wird der Filter für "%s".', $urlDomain, $domain), 400);
		}

		// Ungespeicherten Entwurf für die Dauer dieses Requests einspielen.
		$draftApplied = false;
		if ($request->param('rules') !== null || $request->param('xml') !== null) {
			try {
				$draftXml = $this->buildXml($request, $domain);
			} catch (\InvalidArgumentException $e) {
				return self::message($e->getMessage(), 400);
			}
			$errors = $this->validator->validate($draftXml, $domain);
			if ($errors !== []) {
				return ApiResult::json(['message' => 'Der zu testende Filter ist ungültig.', 'errors' => $errors], 400);
			}
			if ($userId === null) {
				$this->repository->setPendingAdminCustom($domain, $draftXml);
			} else {
				$this->repository->setPendingUserCustom($userId, $domain, $draftXml);
			}
			$draftApplied = true;
		}

		$trace = new ContentFilterTrace();
		try {
			$article = $this->extractor->extract($url, $trace, $userId);
		} catch (\Throwable $e) {
			// Kein Serverfehler: eine nicht erreichbare Seite, eine Paywall oder
			// eine private Adresse sind erwartbare Ergebnisse eines Testlaufs.
			return ApiResult::json([
				'message' => $e->getMessage(),
				// Maschinenlesbar, damit die Oberfläche übersetzen kann.
				'error' => $e instanceof UnsupportedSiteException ? 'unsupported_site' : null,
				'unsupportedDomain' => $e instanceof UnsupportedSiteException ? $e->domain : null,
				'url' => $url,
				'domain' => $domain,
				'draft' => $draftApplied,
				'trace' => $trace->toArray(),
			], 400);
		}

		$published = $article['publishedAt'] ?? null;
		return ApiResult::json([
			'url' => $url,
			'domain' => $domain,
			'draft' => $draftApplied,
			'result' => [
				'title' => $article['title'] ?? '',
				'author' => $article['author'] ?? null,
				'authorUrl' => $article['authorUrl'] ?? null,
				'authors' => $article['authors'] ?? null,
				'excerpt' => $article['excerpt'] ?? null,
				'siteName' => $article['siteName'] ?? null,
				'imageUrl' => $article['imageUrl'] ?? null,
				'category' => $article['category'] ?? null,
				'readingTime' => $article['readingTime'] ?? 0,
				'publishedAt' => $published instanceof \DateTime ? $published->format(\DateTimeInterface::ATOM) : null,
				'content' => $article['content'] ?? '',
				// Damit sich ein <paywall><marker> im Testlauf prüfen lässt.
				'isPaywalled' => (bool) ($article['isPaywalled'] ?? false),
				'paywallSubscribeUrl' => $article['paywallSubscribeUrl'] ?? null,
			],
			'trace' => $trace->toArray(),
			'summary' => [
				'rules' => count($trace->toArray()),
				'misses' => $trace->countMisses(),
				'errors' => $trace->countErrors(),
			],
		]);
	}

	/**
	 * Das zu speichernde XML: direkt aus xml oder serialisiert aus rules.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function buildXml(ApiRequest $request, string $domain): string {
		$xml = $request->param('xml');
		if (is_string($xml) && trim($xml) !== '') {
			return $xml;
		}
		$rules = $request->param('rules');
		if (!is_array($rules)) {
			throw new \InvalidArgumentException('Es wurde weder "rules" noch "xml" übergeben.');
		}
		return $this->serializer->toXml($rules, $domain);
	}

	/**
	 * Rohes XML plus geparste Builder-Struktur (Formular und Quelltext ohne
	 * zweiten Request).
	 *
	 * @return array{xml: string, rules: array<string, mixed>, parseError?: string}|null
	 */
	private function describeFilter(?string $xml): ?array {
		if ($xml === null) {
			return null;
		}
		try {
			return ['xml' => $xml, 'rules' => $this->serializer->toArray($xml)];
		} catch (\Throwable $e) {
			return ['xml' => $xml, 'rules' => [], 'parseError' => $e->getMessage()];
		}
	}

	/** name-Attribut des Wurzelelements, ohne den vollen Parser (noch nicht validiert). */
	private static function domainFromXml(string $xml): string {
		if (preg_match('/<domain\b[^>]*\bname\s*=\s*"([^"]*)"/i', $xml, $m) === 1) {
			return strtolower(trim($m[1]));
		}
		if (preg_match("/<domain\b[^>]*\bname\s*=\s*'([^']*)'/i", $xml, $m) === 1) {
			return strtolower(trim($m[1]));
		}
		return '';
	}

	private static function domain(ApiRequest $request): string {
		return (string) ($request->string('domain') ?? '');
	}

	private static function message(string $message, int $status): ApiResult {
		return ApiResult::json(['message' => $message], $status);
	}
}
