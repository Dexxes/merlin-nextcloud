<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Service\TtsStreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;

/**
 * TTS-Proxy: Leitet Anfragen vom iOS-/Android-Client an den lokalen Piper-Daemon weiter.
 *
 * Warum PHP als Proxy?
 *   - Die Clients kennen nur die Nextcloud-URL und nutzen bestehende Basic-Auth-Credentials.
 *   - Der Piper-Daemon hört nur auf 127.0.0.1 und ist extern nicht erreichbar.
 *   - Nextcloud übernimmt Auth + DB-Zugriff; der Daemon ist ein reiner TTS-Service.
 *
 * Die eigentliche Extraktions-/Streaming-Logik lebt in TtsStreamService, damit sie
 * sich der öffentliche Share-Endpunkt (PublicShareController) teilen kann, ohne
 * den sicherheitskritischen curl-Proxy-Code zu duplizieren. Dieser Controller ist
 * nur noch für die Auth (Basic Auth → $userId) und das Article-Lookup zuständig.
 *
 * Ablauf (ein einziger HTTP-Request):
 *   Client: GET /api/articles/{id}/tts?lang=de
 *     → PHP: Artikel aus DB laden (Basic-Auth-User)
 *     → TtsStreamService: HTML → Plaintext → Daemon-Session → MP3-Stream direkt pipen
 */
class TtsController extends Controller {
	public function __construct(
		string                  $appName,
		IRequest                $request,
		private ArticleMapper   $articleMapper,
		private TtsStreamService $ttsStream,
		private ?string         $userId,
	) {
		parent::__construct($appName, $request);
	}

	// ── GET /api/articles/{id}/tts?lang=de ───────────────────────────────────

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function synthesize(int $id, string $lang = 'de', int $speaker = -1): void {
		try {
			$article = $this->articleMapper->find($id, $this->userId);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			http_response_code(404);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'Article not found']);
			exit();
		}

		// Läuft nie normal zurück: TtsStreamService::stream() beendet den
		// Prozess selbst per exit() (Begründung siehe dort).
		$this->ttsStream->stream($article, $lang, $speaker);
	}
}
