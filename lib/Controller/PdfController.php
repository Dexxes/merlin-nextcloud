<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Service\PdfProxyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;

/**
 * GET /api/articles/{id}/pdf – reicht die PDF eines PDF-Artikels vom Quellserver
 * durch (nichts wird gespeichert), damit der Web-Reader sie mit pdf.js vom
 * eigenen Origin laden kann. Nur Auth + Artikel-Lookup; die Proxy-Logik und ihre
 * Sicherheitsbegründung stehen in PdfProxyService (wie bei TtsController/
 * TtsStreamService, mit dem sich der öffentliche Share-Endpunkt sie teilt).
 */
class PdfController extends Controller {
	public function __construct(
		string                 $appName,
		IRequest               $request,
		private ArticleMapper  $articleMapper,
		private PdfProxyService $pdfProxy,
		private ?string        $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): void {
		try {
			$article = $this->articleMapper->find($id, $this->userId);
		} catch (DoesNotExistException) {
			http_response_code(404);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'Article not found']);
			exit();
		}

		// Läuft nie normal zurück: PdfProxyService::stream() beendet den Prozess selbst per exit().
		$this->pdfProxy->stream($article);
	}
}
