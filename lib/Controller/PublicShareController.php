<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\AppInfo\Application;
use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\ArticleShare;
use OCA\Merlin\Db\ArticleShareMapper;
use OCA\Merlin\Db\HighlightMapper;
use OCA\Merlin\Service\ExportService;
use OCA\Merlin\Service\TtsStreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\Bruteforce\IThrottler;

/**
 * Öffentliche Auslieferung eines per Share-Link freigegebenen Artikels –
 * KEIN Login nötig (#[PublicPage]). Der Token aus der URL ist die einzige
 * Berechtigung; alle Lookups gehen über ArticleShareMapper::findByToken(),
 * NIE über eine vom Client mitgeschickte article_id/user_id (IDOR-Schutz).
 *
 * Passwort-Unlock wird in der PHP-Session gemerkt (analog Nextclouds eigenem
 * Datei-Freigabe-Passwortschutz in files_sharing), Brute-Force-Schutz über
 * den Bordmittel-Dienst IThrottler.
 */
class PublicShareController extends Controller {
	private const THROTTLE_ACTION = 'merlin_public_share_unlock';
	private const SESSION_KEY = 'merlin_unlocked_share_tokens';

	public function __construct(
		string $appName,
		IRequest $request,
		private ArticleShareMapper $shareMapper,
		private ArticleMapper $articleMapper,
		private HighlightMapper $highlightMapper,
		private ExportService $exportService,
		private TtsStreamService $ttsStream,
		private ISession $session,
		private IThrottler $throttler,
		private IInitialState $initialState,
	) {
		parent::__construct($appName, $request);
	}

	// ── Session-Helfer: pro Browser-Session gemerkter Passwort-Unlock ────────

	private function isUnlocked(ArticleShare $share): bool {
		if (!$share->hasPassword()) {
			return true;
		}
		$unlocked = $this->session->get(self::SESSION_KEY) ?? [];
		return is_array($unlocked) && in_array($share->getToken(), $unlocked, true);
	}

	private function markUnlocked(ArticleShare $share): void {
		$unlocked = $this->session->get(self::SESSION_KEY) ?? [];
		if (!is_array($unlocked)) {
			$unlocked = [];
		}
		$unlocked[] = $share->getToken();
		$this->session->set(self::SESSION_KEY, array_values(array_unique($unlocked)));
	}

	/**
	 * Löst den Token auf und prüft Ablauf + Passwort-Unlock in einem Rutsch.
	 * Rückgabe ist entweder der gültige Share ODER eine fertige Fehler-DataResponse
	 * (404 nicht gefunden, 410 abgelaufen, 401 gesperrt) – Aufrufer muss nur
	 * `instanceof DataResponse` prüfen.
	 */
	private function resolveAccessibleShare(string $token): ArticleShare|DataResponse {
		try {
			$share = $this->shareMapper->findByToken($token);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($share->isExpired()) {
			return new DataResponse(['error' => 'Expired'], Http::STATUS_GONE);
		}

		if ($share->hasPassword() && !$this->isUnlocked($share)) {
			return new DataResponse(['locked' => true, 'hasPassword' => true], Http::STATUS_UNAUTHORIZED);
		}

		return $share;
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

	/**
	 * Passwort prüfen und Unlock in der Session merken.
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function unlock(string $token, string $password = ''): DataResponse {
		try {
			$share = $this->shareMapper->findByToken($token);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($share->isExpired()) {
			return new DataResponse(['error' => 'Expired'], Http::STATUS_GONE);
		}

		if (!$share->hasPassword()) {
			return new DataResponse(['unlocked' => true]);
		}

		// Brute-Force-Schutz: künstliche Verzögerung wächst mit der Zahl
		// vorheriger Fehlversuche von dieser IP (Nextcloud-Bordmittel, gleiches
		// Muster wie beim Login und bei Nextclouds eigenen Datei-Freigaben).
		$ip = $this->request->getRemoteAddress();
		$this->throttler->sleepDelay($ip, self::THROTTLE_ACTION);

		if (!password_verify($password, (string) $share->getPasswordHash())) {
			$this->throttler->registerAttempt(self::THROTTLE_ACTION, $ip, ['token' => $token]);
			return new DataResponse(['error' => 'Invalid password'], Http::STATUS_FORBIDDEN);
		}

		$this->throttler->resetDelay($ip, self::THROTTLE_ACTION, ['token' => $token]);
		$this->markUnlocked($share);

		return new DataResponse(['unlocked' => true]);
	}

	/**
	 * Artikeldaten + Highlights für die öffentliche Ansicht.
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function data(string $token): DataResponse {
		$share = $this->resolveAccessibleShare($token);
		if ($share instanceof DataResponse) {
			return $share;
		}

		try {
			$article = $this->articleMapper->find($share->getArticleId(), $share->getUserId());
		} catch (DoesNotExistException) {
			// Artikel wurde gelöscht, Share-Zeile aber (noch) nicht aufgeräumt.
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$highlights = $this->highlightMapper->findByArticleId($article->getId(), $share->getUserId());

		return new DataResponse([
			'title'       => $article->getTitle(),
			'excerpt'     => $article->getExcerpt(),
			'author'      => $article->getAuthor(),
			'siteName'    => $article->getSiteName(),
			'content'     => $article->getContent(),
			'url'         => $article->getUrl(),
			'publishedAt' => $article->getPublishedAt() ? $article->getPublishedAt()->format('c') : null,
			'readingTime' => $article->getReadingTime(),
			'highlights'  => array_map(fn ($h) => $h->jsonSerialize(), $highlights),
		]);
	}

	/**
	 * HTML-Export des geteilten Artikels zum Download – nutzt denselben
	 * ExportService wie der authentifizierte Export-Endpunkt.
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportHtml(string $token): DataDisplayResponse {
		$share = $this->resolveAccessibleShare($token);
		if ($share instanceof DataResponse) {
			return new DataDisplayResponse('', $share->getStatus(), ['Content-Type' => 'text/plain']);
		}

		try {
			$article = $this->articleMapper->find($share->getArticleId(), $share->getUserId());
		} catch (DoesNotExistException) {
			return new DataDisplayResponse('Article not found', Http::STATUS_NOT_FOUND);
		}

		$htmlContent = $this->exportService->exportHtml($article);
		$response = new DataDisplayResponse($htmlContent, Http::STATUS_OK, ['Content-Type' => 'text/html']);
		$response->addHeader(
			'Content-Disposition',
			'attachment; filename="' . preg_replace('/[^a-zA-Z0-9-_ ]/', '', $article->getTitle()) . '.html"'
		);

		return $response;
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
		$share = $this->resolveAccessibleShare($token);
		if ($share instanceof DataResponse) {
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
		$this->ttsStream->stream($article, $lang, $speaker);
	}
}
