<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\Db\ArticleShare;
use OCA\Merlin\Db\ArticleShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\ISession;

/**
 * Zugriffsprüfung hinter einem öffentlichen Share-Link, gemeinsam für
 * PublicShareController (Lesen, TTS, PDF) und PublicCommentController
 * (Markieren, Kommentieren, Push-Kanal).
 *
 * Der Token aus der URL ist die einzige Berechtigung; alle Lookups gehen über
 * ArticleShareMapper::findByToken(), nie über eine vom Client geschickte
 * article_id/user_id (IDOR-Schutz). Ein Passwort-Unlock wird in der
 * PHP-Session gemerkt.
 */
class ShareAccessService {
	private const SESSION_KEY = 'merlin_unlocked_share_tokens';

	public function __construct(
		private ArticleShareMapper $shareMapper,
		private ISession $session,
	) {
	}

	public function isUnlocked(ArticleShare $share): bool {
		if (!$share->hasPassword()) {
			return true;
		}
		$unlocked = $this->session->get(self::SESSION_KEY) ?? [];
		return is_array($unlocked) && in_array($share->getToken(), $unlocked, true);
	}

	public function markUnlocked(ArticleShare $share): void {
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
	public function resolveAccessibleShare(string $token): ArticleShare|DataResponse {
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
	 * Gilt der Link noch (für den Push-Kanal, der lange offen bleibt)? Prüft
	 * gegen die Datenbank, ob Token und Ablauf noch passen;
	 * der Passwort-Unlock wurde beim Verbindungsaufbau geprüft.
	 */
	public function stillValid(string $token): bool {
		try {
			$share = $this->shareMapper->findByToken($token);
		} catch (DoesNotExistException) {
			return false;
		}
		return !$share->isExpired();
	}
}
