<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Service\ContentExtractorService;
use OCA\Merlin\Service\ContentFilterMerger;
use OCA\Merlin\Service\ContentFilterRepository;
use OCA\Merlin\Service\ContentFilterSchema;
use OCA\Merlin\Service\ContentFilterSerializer;
use OCA\Merlin\Service\ContentFilterTrace;
use OCA\Merlin\Service\ContentFilterValidator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Persönliche Content-Filter-Overrides: jeder authentifizierte Nutzer darf
 * seine eigene, private dritte Ebene bearbeiten (Bundle < Admin-Custom <
 * User-Custom, siehe ContentFilterRepository). Bundle- und Admin-Regeln sind
 * hier read-only Referenz; geschrieben wird ausschliesslich die eigene Zeile.
 *
 * Security note – Threat-Model-Wechsel: Anders als ContentFilterController
 * (admin-only) kann HIER jeder eingeloggte Nutzer Fetch-Header/XPath/Regeln
 * definieren. ContentFilterValidator gilt unverändert (XXE aus, Header-
 * Allowlist, XPath-Kompilierprüfung, Größenlimit) und ist scope-agnostisch –
 * dieselbe Prüfung wie beim Admin-Pfad. test() nutzt denselben SSRF-geschützten
 * Fetch-Pfad wie der Admin-Testlauf (identisches Rate-Limit), jetzt aber von
 * jedem Nutzer erreichbar statt nur von Admins – bewusst kein Unterschied im
 * Fetch-Verhalten, nur in der Erreichbarkeit.
 *
 * Isolation: JEDE Lese-/Schreib-/Löschoperation hier geht über
 * ContentFilterRepository-Methoden, die $userId als Pflichtparameter verlangen
 * und ihn serverseitig aus $this->userId beziehen (Nextcloud-Magic-Parameter,
 * aus der Session aufgelöst) – niemals aus einem Request-Parameter. Ein Nutzer
 * kann so über keinen Endpunkt dieser Klasse den Override eines anderen
 * Nutzers lesen, überschreiben oder löschen.
 *
 * Bewusst OHNE #[NoCSRFRequired]: anders als ArticleController/
 * ExtensionController bedient dieser Controller ausschliesslich die
 * Personal-Settings-Weboberfläche, nie einen nativen Client – der normale
 * requesttoken-Schutz des AppFramework soll greifen.
 */
class UserContentFilterController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ContentFilterRepository $repository,
		private ContentFilterValidator $validator,
		private ContentFilterSerializer $serializer,
		private ContentFilterMerger $merger,
		private ContentExtractorService $extractor,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Liste
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Alle Domains (Bundle und/oder Admin-Custom vorhanden), markiert mit einem
	 * eigenen Override, plus die Grammatik für den Regel-Builder.
	 *
	 * Zeigt bewusst dieselbe Domainliste wie die Admin-UI (hasBundle/hasCustom
	 * sind reine Existenz-Flags, kein Inhalt) – der Nutzer muss ja eine Domain
	 * auswählen können, für die er noch KEINEN eigenen Override hat.
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		$userId       = (string) $this->userId;
		$ownDomains   = array_flip($this->repository->listUserOverrideDomains($userId));
		$allDomains   = $this->repository->listFilters();

		$domains = array_map(static function (array $entry) use ($ownDomains) {
			return [
				'domain'         => $entry['domain'],
				'hasBundle'      => $entry['hasBundle'],
				'hasAdminCustom' => $entry['hasCustom'],
				'hasOwnOverride' => isset($ownDomains[$entry['domain']]),
			];
		}, $allDomains);

		return new DataResponse([
			'domains' => $domains,
			'schema'  => ContentFilterSchema::describe(),
		]);
	}

	/**
	 * Bundle- und Admin-Custom-Regeln (read-only Referenz, zu einer Config
	 * zusammengeführt), der eigene Override und der vollständig gemergte Stand.
	 */
	#[NoAdminRequired]
	public function show(string $domain): DataResponse {
		if (!$this->repository->isValidDomain($domain)) {
			return $this->error('Ungültiger Domainname.', Http::STATUS_BAD_REQUEST);
		}

		$userId = (string) $this->userId;
		$bundle = $this->repository->readBundle($domain);
		$admin  = $this->repository->readAdminCustom($domain);
		$own    = $this->repository->readUserCustom($domain, $userId);

		if ($bundle === null && $admin === null && $own === null) {
			return $this->error('Für diese Domain existiert kein Filter.', Http::STATUS_NOT_FOUND);
		}

		$payload = [
			'domain'    => $domain,
			'reference' => null, // Bundle + Admin-Custom, read-only
			'own'       => $this->describeFilter($own),
			'merged'    => null,
		];

		try {
			$referenceXml = $this->merger->mergeToString($bundle, $admin, $domain, ContentFilterSchema::ORIGIN_ADMIN);
			$payload['reference'] = $this->describeFilter($referenceXml);

			$mergedXml = $this->merger->mergeToString($referenceXml, $own, $domain, ContentFilterSchema::ORIGIN_USER);
			$payload['merged'] = $this->describeFilter($mergedXml);
		} catch (\Throwable $e) {
			$payload['mergeError'] = $e->getMessage();
		}

		return new DataResponse($payload);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Schreiben
	// ──────────────────────────────────────────────────────────────────────────

	/** Speichert den eigenen Custom-Filter einer Domain. */
	#[NoAdminRequired]
	public function update(string $domain): DataResponse {
		if (!$this->repository->isValidDomain($domain)) {
			return $this->error('Ungültiger Domainname.', Http::STATUS_BAD_REQUEST);
		}

		try {
			$xml = $this->buildXmlFromRequest($domain);
		} catch (\InvalidArgumentException $e) {
			return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
		}

		$errors = $this->validator->validate($xml, $domain);
		if ($errors !== []) {
			return new DataResponse(
				['message' => 'Der Filter ist ungültig.', 'errors' => $errors],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$this->repository->saveUserCustom((string) $this->userId, $domain, $xml);
		} catch (\Throwable $e) {
			$this->logger->error('content-filters: Speichern des User-Overrides fehlgeschlagen', [
				'domain'    => $domain,
				'exception' => $e,
			]);
			return $this->error($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->show($domain);
	}

	/** Löscht den eigenen Custom-Filter einer Domain. */
	#[NoAdminRequired]
	public function destroy(string $domain): DataResponse {
		if (!$this->repository->isValidDomain($domain)) {
			return $this->error('Ungültiger Domainname.', Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->repository->deleteUserCustom((string) $this->userId, $domain);
		} catch (\Throwable $e) {
			return $this->error($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse(['domain' => $domain, 'deleted' => true]);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Testlauf
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Wie ContentFilterController::test(), aber testet den EIGENEN Override
	 * gegen Bundle+Admin (nicht den Admin-Layer selbst) – über
	 * ContentExtractorService::extract() mit $this->userId, damit
	 * ContentFilterRepository::getMerged() alle drei Ebenen zusammenführt.
	 * Gleiches Rate-Limit wie der Admin-Testlauf: derselbe SSRF-geschützte
	 * Fetch-Pfad, jetzt aber von jedem Nutzer erreichbar.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function test(string $domain): DataResponse {
		if (!$this->repository->isValidDomain($domain)) {
			return $this->error('Ungültiger Domainname.', Http::STATUS_BAD_REQUEST);
		}

		$url = trim((string) ($this->request->getParam('url') ?? ''));
		if ($url === '') {
			return $this->error('Es wurde keine Test-URL übergeben.', Http::STATUS_BAD_REQUEST);
		}

		$urlDomain = $this->repository->normalizeUrlDomain($url);
		if ($urlDomain === '') {
			return $this->error('Die URL enthält keinen Hostnamen.', Http::STATUS_BAD_REQUEST);
		}
		if (!$this->repository->domainMatchesFilterKey($urlDomain, $domain)) {
			return $this->error(
				sprintf('Die URL gehört zu "%s", getestet wird der Filter für "%s".', $urlDomain, $domain),
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId       = (string) $this->userId;
		$draftApplied = false;
		if ($this->request->getParam('rules') !== null || $this->request->getParam('xml') !== null) {
			try {
				$draftXml = $this->buildXmlFromRequest($domain);
			} catch (\InvalidArgumentException $e) {
				return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
			}

			$errors = $this->validator->validate($draftXml, $domain);
			if ($errors !== []) {
				return new DataResponse(
					['message' => 'Der zu testende Filter ist ungültig.', 'errors' => $errors],
					Http::STATUS_BAD_REQUEST
				);
			}

			$this->repository->setPendingUserCustom($userId, $domain, $draftXml);
			$draftApplied = true;
		}

		$trace = new ContentFilterTrace();

		try {
			$article = $this->extractor->extract($url, $trace, $userId);
		} catch (\Throwable $e) {
			return new DataResponse([
				'message' => $e->getMessage(),
				'url'     => $url,
				'domain'  => $domain,
				'draft'   => $draftApplied,
				'trace'   => $trace->toArray(),
			], Http::STATUS_BAD_REQUEST);
		}

		$published = $article['publishedAt'] ?? null;

		return new DataResponse([
			'url'    => $url,
			'domain' => $domain,
			'draft'  => $draftApplied,
			'result' => [
				'title'       => $article['title'] ?? '',
				'author'      => $article['author'] ?? null,
				'excerpt'     => $article['excerpt'] ?? null,
				'siteName'    => $article['siteName'] ?? null,
				'imageUrl'    => $article['imageUrl'] ?? null,
				'category'    => $article['category'] ?? null,
				'readingTime' => $article['readingTime'] ?? 0,
				'publishedAt' => $published instanceof \DateTime ? $published->format(\DateTimeInterface::ATOM) : null,
				'content'     => $article['content'] ?? '',
			],
			'trace' => $trace->toArray(),
			'summary' => [
				'rules'  => count($trace->toArray()),
				'misses' => $trace->countMisses(),
				'errors' => $trace->countErrors(),
			],
		]);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Intern
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * @throws \InvalidArgumentException
	 */
	private function buildXmlFromRequest(string $domain): string {
		$xml = $this->request->getParam('xml');
		if (is_string($xml) && trim($xml) !== '') {
			return $xml;
		}

		$rules = $this->request->getParam('rules');
		if (!is_array($rules)) {
			throw new \InvalidArgumentException('Es wurde weder "rules" noch "xml" übergeben.');
		}

		return $this->serializer->toXml($rules, $domain);
	}

	/**
	 * @return array{xml:string,rules:array<string,mixed>,parseError?:string}|null
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

	private function error(string $message, int $status): DataResponse {
		return new DataResponse(['message' => $message], $status);
	}
}
