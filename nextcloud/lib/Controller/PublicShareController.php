<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\PublicShareApi;
use Merlin\Core\Service\PdfProxyService;
use Merlin\Core\Service\ShareAccessDenied;
use Merlin\Core\Service\ShareAccessService;
use Merlin\Core\Service\TtsStreamService;
use OCA\Merlin\AppInfo\Application;
use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Service\MerlinFileService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

/**
 * Öffentliche Auslieferung eines per Share-Link freigegebenen Artikels –
 * KEIN Login nötig (#[PublicPage]). Der Token aus der URL ist die einzige
 * Berechtigung; alle Lookups gehen über ShareAccessService/ShareStore,
 * NIE über eine vom Client mitgeschickte article_id/user_id (IDOR-Schutz).
 *
 * Entsperren und Daten (unlock, data) kommen aus dem Kern
 * (Merlin\Core\Api\PublicShareApi), die HTML-Seite, Vorlesen und PDF noch
 * von hier. Markieren und Kommentieren hinter dem Link:
 * PublicCommentController.
 */
class PublicShareController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private PublicShareApi $api,
		private ArticleMapper $articleMapper,
		private TtsStreamService $ttsStream,
		private PdfProxyService $pdfProxy,
		private ShareAccessService $access,
		private IInitialState $initialState,
		private MerlinFileService $files,
		?string $userId,
	) {
		parent::__construct($appName, $request, $userId);
	}

	/**
	 * HTML-Shell für die öffentliche Ansicht. Die eigentliche Zustandslogik
	 * (Passwort-Gate / Inhalt / Fehler) läuft im Vue-Frontend über data(),
	 * damit hier keine zweite Fehlerseiten-Logik gepflegt werden muss.
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $token): TemplateResponse {
		$this->initialState->provideInitialState('shareToken', $token);

		$response = new TemplateResponse(Application::APP_ID, 'public', [], TemplateResponse::RENDER_AS_BASE);

		// Artikelbilder kommen von beliebigen externen Domains (wie im
		// authentifizierten Reader, siehe PageController).
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedImageDomain('*');
		$policy->addAllowedMediaDomain('*'); // TTS-Audio-Stream (audio/mpeg vom eigenen Origin, aber img/media teilen sich hier die Policy)
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function unlock(): Response {
		return $this->respond($this->api->unlock($this->apiRequest()));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function data(): Response {
		return $this->respond($this->api->data($this->apiRequest()));
	}

	/**
	 * TTS-Streaming für den geteilten Artikel – nutzt denselben
	 * TtsStreamService (und damit dieselbe Piper-Daemon-Proxy-Logik) wie der
	 * authentifizierte Endpunkt in TtsController.
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tts(string $token, string $lang = 'de', int $speaker = -1): void {
		$share = $this->access->resolveAccessibleShare($token);
		if ($share instanceof ShareAccessDenied) {
			http_response_code($share->getStatus());
			header('Content-Type: application/json');
			echo json_encode($share->getData());
			exit();
		}

		try {
			$article = $this->articleMapper->find($share->getArticleId(), $share->getUserId());
		} catch (DoesNotExistException) {
			http_response_code(404);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'Article not found']);
			exit();
		}

		// Läuft nie normal zurück: TtsStreamService::stream() beendet den
		// Prozess selbst per exit().
		$this->ttsStream->stream($article->toSnapshot(), $lang, $speaker);
	}

	/**
	 * PDF-Durchreichung für den geteilten PDF-Artikel – dieselbe Proxy-Logik
	 * (PdfProxyService) wie der authentifizierte Endpunkt in PdfController, damit
	 * die Share-Ansicht die PDF vom eigenen Origin laden kann.
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pdf(string $token): void {
		$share = $this->access->resolveAccessibleShare($token);
		if ($share instanceof ShareAccessDenied) {
			http_response_code($share->getStatus());
			header('Content-Type: application/json');
			echo json_encode($share->getData());
			exit();
		}

		try {
			$article = $this->articleMapper->find($share->getArticleId(), $share->getUserId());
		} catch (DoesNotExistException) {
			http_response_code(404);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'Article not found']);
			exit();
		}

		// PDF aus „Merlin Dateien“: direkt aus Nextcloud statt über den Proxy.
		if ($article->getFileId() !== null) {
			$this->files->stream($article);
		}

		// Läuft nie normal zurück: PdfProxyService::stream() beendet den Prozess selbst per exit().
		$this->pdfProxy->stream($article->toSnapshot());
	}
}
