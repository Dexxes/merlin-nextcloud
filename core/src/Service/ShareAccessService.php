<?php

declare(strict_types=1);

namespace Merlin\Core\Service;

use Merlin\Core\Domain\ArticleShare;
use Merlin\Core\Port\Session;
use Merlin\Core\Port\ShareStore;

/**
 * Zugriffsprüfung hinter einem öffentlichen Share-Link, gemeinsam für
 * PublicShareController (Lesen, TTS, PDF) und PublicCommentController
 * (Markieren, Kommentieren, Push-Kanal).
 *
 * Der Token aus der URL ist die einzige Berechtigung; alle Lookups gehen über
 * ShareStore::findByToken(), nie über eine vom Client geschickte
 * article_id/user_id (IDOR-Schutz). Ein Passwort-Unlock wird in der
 * Session gemerkt (Port Session).
 */
class ShareAccessService {
	private const SESSION_KEY = 'merlin_unlocked_share_tokens';

	public function __construct(
		private ShareStore $shares,
		private Session $session,
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
	 * Rückgabe ist entweder der gültige Share ODER ein ShareAccessDenied mit
	 * Status und Body der Fehlerantwort (404 nicht gefunden, 410 abgelaufen,
	 * 401 gesperrt) – Aufrufer muss nur `instanceof ShareAccessDenied` prüfen.
	 */
	public function resolveAccessibleShare(string $token): ArticleShare|ShareAccessDenied {
		$share = $this->shares->findByToken($token);
		if ($share === null) {
			return new ShareAccessDenied(404, ['error' => 'Not found']);
		}

		if ($share->isExpired()) {
			return new ShareAccessDenied(410, ['error' => 'Expired']);
		}

		if ($share->hasPassword() && !$this->isUnlocked($share)) {
			return new ShareAccessDenied(401, ['locked' => true, 'hasPassword' => true]);
		}

		return $share;
	}

	/**
	 * Gilt der Link noch (für den Push-Kanal, der lange offen bleibt)? Prüft
	 * gegen die Datenbank, ob Token und Ablauf noch passen;
	 * der Passwort-Unlock wurde beim Verbindungsaufbau geprüft.
	 */
	public function stillValid(string $token): bool {
		$share = $this->shares->findByToken($token);
		return $share !== null && !$share->isExpired();
	}
}
