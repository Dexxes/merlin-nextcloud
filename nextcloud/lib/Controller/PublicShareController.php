<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use Merlin\Core\Api\ArticleStreamApi;
use Merlin\Core\Api\PublicShareApi;
use OCA\Merlin\AppInfo\Application;
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
 * (Merlin\Core\Api\PublicShareApi), Vorlesen und PDF ebenfalls
 * (Merlin\Core\Api\ArticleStreamApi); nur die HTML-Seite noch von hier.
 * Markieren und Kommentieren hinter dem Link:
 * PublicCommentController.
 */
class PublicShareController extends CoreApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private PublicShareApi $api,
		private ArticleStreamApi $streams,
		private IInitialState $initialState,
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

	/** GET /s/{token}/tts: Vorlesen des geteilten Artikels (Merlin\Core\Api\ArticleStreamApi). */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tts(): Response {
		return $this->respond($this->streams->tts($this->apiRequest()));
	}

	/** GET /s/{token}/pdf: PDF des geteilten PDF-Artikels (Merlin\Core\Api\ArticleStreamApi). */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pdf(): Response {
		return $this->respond($this->streams->pdf($this->apiRequest()));
	}
}
