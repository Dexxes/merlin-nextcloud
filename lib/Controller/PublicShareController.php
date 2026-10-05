<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\AppInfo\Application;
use OCA\Merlin\Db\Article;
use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\ArticleShareMapper;
use OCA\Merlin\Service\CommentService;
use OCA\Merlin\Service\PdfProxyService;
use OCA\Merlin\Service\ShareAccessService;
use OCA\Merlin\Service\SupportBoxService;
use OCA\Merlin\Service\TtsStreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;

/**
 * Öffentliche Auslieferung eines per Share-Link freigegebenen Artikels –
 * KEIN Login nötig (#[PublicPage]). Der Token aus der URL ist die einzige
 * Berechtigung; alle Lookups gehen über ArticleShareMapper::findByToken(),
 * NIE über eine vom Client mitgeschickte article_id/user_id (IDOR-Schutz).
 *
 * Passwort-Unlock wird in der PHP-Session gemerkt (analog Nextclouds eigenem
 * Datei-Freigabe-Passwortschutz in files_sharing, siehe ShareAccessService),
 * Brute-Force-Schutz über den Bordmittel-Dienst IThrottler. Markieren und
 * Kommentieren hinter dem Link: PublicCommentController.
 */
class PublicShareController extends Controller {
	private const THROTTLE_ACTION = 'merlin_public_share_unlock';

	public function __construct(
		string $appName,
		IRequest $request,
		private ArticleShareMapper $shareMapper,
		private ArticleMapper $articleMapper,
		private TtsStreamService $ttsStream,
		private PdfProxyService $pdfProxy,
		private ShareAccessService $access,
		private CommentService $comments,
		private IThrottler $throttler,
		private IInitialState $initialState,
		private SupportBoxService $supportBox,
	) {
		parent::__construct($appName, $request);
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
		$this->access->markUnlocked($share);

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
		$share = $this->access->resolveAccessibleShare($token);
		if ($share instanceof DataResponse) {
			return $share;
		}

		try {
			$article = $this->articleMapper->find($share->getArticleId(), $share->getUserId());
		} catch (DoesNotExistException) {
			// Artikel wurde gelöscht, Share-Zeile aber (noch) nicht aufgeräumt.
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		// Markierungen, Threads und Änderungsmarke für den Push-Kanal
		// (PublicCommentController::events) in einem Rutsch.
		$discussion = $this->comments->payload($article->getId(), $share->getUserId());

		return new DataResponse([
			'title'       => $article->getTitle(),
			'excerpt'     => $article->getExcerpt(),
			'author'      => $article->getAuthor(),
			'authorUrl'   => $article->getAuthorUrl(),
			'authors'     => Article::decodeAuthors($article->getAuthors()),
			'siteName'    => $article->getSiteName(),
			'content'     => $article->getContent(),
			'url'         => $article->getUrl(),
			'category'    => $article->getCategory(),
			'publishedAt' => $article->getPublishedAt() ? $article->getPublishedAt()->format('c') : null,
			'readingTime' => $article->getReadingTime(),
			'highlights'  => $discussion['highlights'],
			'comments'    => $discussion['comments'],
			'signature'   => $discussion['signature'],
			'allowComments' => $share->allowsComments(),
			'ownerName'   => $this->comments->ownerDisplayName($share->getUserId()),
			// Akzentfarbe des Erstellers für die Dachzeile, wie im App-Reader.
			'accentColor' => $this->supportBox->accentColor($share->getUserId()),
			// Abo-/Spendenlink der Quelle; anders als im Reader immer, auch wenn der
			// Ersteller dort ein Abo hat (Empfänger sind keine Abonnenten).
			'supportBox'  => $this->supportBox->forShare($article, $share->getUserId()),
		]);
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

		// Läuft nie normal zurück: PdfProxyService::stream() beendet den Prozess selbst per exit().
		$this->pdfProxy->stream($article);
	}
}
