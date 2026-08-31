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
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Verwaltung der Content-Filter durch Nextcloud-Administratoren.
 *
 * Security note – bewusst OHNE #[NoAdminRequired] und OHNE #[NoCSRFRequired]:
 * Anders als die übrigen Merlin-Controller bedient dieser ausschliesslich die
 * Admin-Weboberfläche, nie einen native Client. Damit greifen beide
 * Standardschutzmechanismen des AppFramework: Adminrechte werden erzwungen und
 * jeder schreibende Request braucht einen gültigen requesttoken (den
 * @nextcloud/axios automatisch mitschickt). Ein Filter gilt instanzweit für alle
 * Nutzer – hier wäre eine Lockerung an der falschen Stelle gespart.
 */
class ContentFilterController extends Controller {

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
	 * Alle bekannten Filter plus der Zustand des Speicherorts und die Grammatik
	 * für den Regel-Builder.
	 */
	public function index(): DataResponse {
		return new DataResponse([
			'filters' => $this->repository->listFilters(),
			'schema'  => ContentFilterSchema::describe(),
		]);
	}

	/**
	 * Bundle-, Custom- und Merge-Stand einer Domain.
	 *
	 * Der Bundle-Teil ist read-only und dient der UI dazu, die vorhandenen Regeln
	 * anzuzeigen und einzeln abschaltbar zu machen. Der Merge-Teil zeigt, was der
	 * Extractor tatsächlich sieht.
	 */
	public function show(string $domain): DataResponse {
		if (!$this->repository->isValidDomain($domain)) {
			return $this->error('Ungültiger Domainname.', Http::STATUS_BAD_REQUEST);
		}

		$bundle = $this->repository->readBundle($domain);
		$custom = $this->repository->readAdminCustom($domain);

		if ($bundle === null && $custom === null) {
			return $this->error('Für diese Domain existiert kein Filter.', Http::STATUS_NOT_FOUND);
		}

		$payload = [
			'domain' => $domain,
			'bundle' => $this->describeFilter($bundle),
			'custom' => $this->describeFilter($custom),
			'merged' => null,
		];

		try {
			$mergedXml = $this->merger->mergeToString($bundle, $custom, $domain, ContentFilterSchema::ORIGIN_ADMIN);
			$payload['merged'] = $this->describeFilter($mergedXml);
		} catch (\Throwable $e) {
			// Der Merge kann nur an einer kaputten Custom-Datei scheitern; die
			// Bundle-Dateien sind mit der App ausgeliefert. Die UI soll den Fehler
			// anzeigen, statt eine leere Seite zu zeigen.
			$payload['mergeError'] = $e->getMessage();
		}

		return new DataResponse($payload);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Schreiben
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Speichert den Custom-Filter einer Domain.
	 *
	 * Nimmt entweder die Builder-Struktur (rules) oder rohes XML (xml) an. Beides
	 * mündet in denselben Validierungspfad, damit es keine zweite, schwächer
	 * geprüfte Tür gibt.
	 */
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
			$this->repository->saveAdminCustom($domain, $xml, (string) $this->userId);
		} catch (\Throwable $e) {
			$this->logger->error('content-filters: Speichern fehlgeschlagen', [
				'domain'    => $domain,
				'exception' => $e,
			]);
			return $this->error($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->show($domain);
	}

	/** Löscht den Admin-Custom-Filter einer Domain; der Bundle-Filter bleibt bestehen. */
	public function destroy(string $domain): DataResponse {
		if (!$this->repository->isValidDomain($domain)) {
			return $this->error('Ungültiger Domainname.', Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->repository->deleteAdminCustom($domain);
		} catch (\Throwable $e) {
			return $this->error($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse(['domain' => $domain, 'deleted' => true]);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Import / Export
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Importiert eine Filterdatei. Fehlt der Domainname, wird er dem
	 * name-Attribut des Wurzelelements entnommen.
	 */
	public function import(): DataResponse {
		$xml = (string) ($this->request->getParam('xml') ?? '');
		if (trim($xml) === '') {
			return $this->error('Es wurde kein XML übergeben.', Http::STATUS_BAD_REQUEST);
		}

		$domain  = trim((string) ($this->request->getParam('domain') ?? ''));
		$derived = false;
		if ($domain === '') {
			$domain  = $this->domainFromXml($xml);
			$derived = true;
		}
		if ($domain === '' || !$this->repository->isValidDomain($domain)) {
			return $this->error(
				'Der Domainname konnte nicht bestimmt werden. Erwartet wird <domain name="beispiel.de">.',
				Http::STATUS_BAD_REQUEST
			);
		}

		// Kommt der Domainname aus dem name-Attribut der Datei, darf er keine
		// bestehenden Regeln kommentarlos ersetzen: Ein falsch gesetztes
		// name-Attribut – in den mitgelieferten Filtern kam das vor – würde sonst
		// beim Import die Arbeit an einer ganz anderen Domain überschreiben.
		if ($derived
			&& $this->repository->hasCustom($domain)
			&& !filter_var($this->request->getParam('overwrite'), FILTER_VALIDATE_BOOLEAN)
		) {
			return new DataResponse([
				'message' => sprintf(
					'Für "%s" gibt es bereits eigene Regeln. Der Domainname stammt aus dem '
					. 'name-Attribut der Datei – bitte bestätigen, dass sie ersetzt werden sollen.',
					$domain
				),
				'domain' => $domain,
				'code'   => 'custom_filter_exists',
			], Http::STATUS_CONFLICT);
		}

		$errors = $this->validator->validate($xml, $domain);
		if ($errors !== []) {
			return new DataResponse(
				['message' => 'Die Datei ist ungültig.', 'domain' => $domain, 'errors' => $errors],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$this->repository->saveAdminCustom($domain, $xml, (string) $this->userId);
		} catch (\Throwable $e) {
			return $this->error($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->show($domain);
	}

	/**
	 * Lädt den Admin-Custom-Filter einer Domain als XML-Datei herunter. Gibt es
	 * keinen, wird der Bundle-Filter geliefert – so lässt sich ein mitgelieferter
	 * Filter als Ausgangspunkt aus der Instanz holen.
	 */
	public function export(string $domain): DataDownloadResponse|DataResponse {
		if (!$this->repository->isValidDomain($domain)) {
			return $this->error('Ungültiger Domainname.', Http::STATUS_BAD_REQUEST);
		}

		$xml = $this->repository->readAdminCustom($domain) ?? $this->repository->readBundle($domain);
		if ($xml === null) {
			return $this->error('Für diese Domain existiert kein Filter.', Http::STATUS_NOT_FOUND);
		}

		return new DataDownloadResponse($xml, $domain . '.xml', 'application/xml');
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Testlauf
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Extrahiert einen Artikel mit dem Filter dieser Domain und liefert das
	 * Ergebnis samt Trefferzahl je Regel zurück, ohne etwas zu speichern.
	 *
	 * Enthält der Request eine rules- oder xml-Struktur, wird DIESER
	 * ungespeicherte Stand getestet – der Admin muss einen Zwischenstand also
	 * nicht instanzweit aktivieren, um einen XPath zu prüfen.
	 *
	 * Der Abruf läuft über ContentExtractorService::extract() und damit über
	 * denselben Pfad wie ein normaler Artikel-Import, inklusive SSRF-Prüfung der
	 * aufgelösten IP-Adressen und DNS-Pinning. Es gibt hier bewusst keine
	 * Ausnahme für interne Adressen.
	 */
	#[UserRateLimit(limit: 20, period: 300)]
	public function test(string $domain): DataResponse {
		if (!$this->repository->isValidDomain($domain)) {
			return $this->error('Ungültiger Domainname.', Http::STATUS_BAD_REQUEST);
		}

		$url = trim((string) ($this->request->getParam('url') ?? ''));
		if ($url === '') {
			return $this->error('Es wurde keine Test-URL übergeben.', Http::STATUS_BAD_REQUEST);
		}

		// Der Extractor sucht die Config anhand des URL-Hosts. Eine URL einer
		// anderen Domain würde also einen anderen Filter testen als den, den der
		// Admin gerade offen hat – das wäre schlicht irreführend.
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

		// Ungespeicherten Entwurf für die Dauer dieses Requests einspielen.
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

			$this->repository->setPendingAdminCustom($domain, $draftXml);
			$draftApplied = true;
		}

		$trace = new ContentFilterTrace();

		// Bewusst ohne $this->userId: Der Admin-Testlauf soll Bundle+Admin-Custom
		// prüfen (denselben Stand, den show() anzeigt), nicht zusätzlich durch
		// einen eventuellen privaten Override des testenden Admins selbst verzerrt
		// werden – sonst sähe der Admin ein Ergebnis, das kein anderer Nutzer je
		// bekommt.
		try {
			$article = $this->extractor->extract($url, $trace, null);
		} catch (\Throwable $e) {
			// Kein Serverfehler: eine nicht erreichbare Seite, eine Paywall oder
			// eine private Adresse sind erwartbare Ergebnisse eines Testlaufs.
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
	 * Erzeugt das zu speichernde XML aus dem Request: entweder direkt aus dem
	 * Feld xml oder durch Serialisieren der Builder-Struktur rules.
	 *
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
	 * Rohes XML plus geparste Builder-Struktur, damit die UI beide Sichten hat
	 * (Formular und Quelltext) ohne einen zweiten Request.
	 *
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

	/**
	 * Liest das name-Attribut des Wurzelelements, ohne den vollen Parser zu
	 * bemühen – zu diesem Zeitpunkt ist noch nicht validiert, ob die Datei
	 * überhaupt brauchbar ist.
	 */
	private function domainFromXml(string $xml): string {
		if (preg_match('/<domain\b[^>]*\bname\s*=\s*"([^"]*)"/i', $xml, $m) === 1) {
			return strtolower(trim($m[1]));
		}
		if (preg_match("/<domain\b[^>]*\bname\s*=\s*'([^']*)'/i", $xml, $m) === 1) {
			return strtolower(trim($m[1]));
		}
		return '';
	}

	private function error(string $message, int $status): DataResponse {
		return new DataResponse(['message' => $message], $status);
	}
}
