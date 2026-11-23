<?php

declare(strict_types=1);

namespace Merlin\Standalone\Auth;

use Merlin\Standalone\Db\UserRepository;
use Merlin\Standalone\Http\Request;

/**
 * PHP-Session für die HTML-Seiten (Login, Account, Admin-UI). Die JSON-API
 * unter /api/* nutzt ausschließlich Basic Auth mit API-Tokens (siehe
 * ApiTokenService) - Sessions spielen dort keine Rolle.
 */
final class SessionService {
    /** Vorgabe für 'session_idle_timeout': nach 8 Stunden ohne Anfrage abgemeldet. */
    public const DEFAULT_IDLE_TIMEOUT = 8 * 3600;

    /**
     * @param list<string> $trustedProxies wie Request::fromGlobals(): nur von
     *        dort zählt X-Forwarded-Proto für das Secure-Flag
     * @param int $idleTimeout Sekunden ohne Anfrage, nach denen die Anmeldung
     *        endet ('session_idle_timeout', 0 = ohne)
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly array $trustedProxies = [],
        private readonly int $idleTimeout = self::DEFAULT_IDLE_TIMEOUT,
    ) {
    }

    public function start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            // Keine Session-IDs übernehmen, die der Server nicht selbst
            // vergeben hat (Session Fixation).
            'use_strict_mode' => true,
            // PHP soll die Datei nicht vor dem Leerlauf-Timeout wegräumen.
            'gc_maxlifetime' => max(1440, $this->idleTimeout),
            // Secure-Flag nur setzen, wenn der Request tatsächlich über HTTPS
            // kam - sonst würde das Cookie in einem reinen HTTP-Dev-Setup
            // (php -S) nie gesendet und der Login liefe endlos ins Leere.
            // X-Forwarded-Proto zählt mit (Reverse-Proxy terminiert TLS), mit
            // 'trusted_proxies' aber nur von diesen.
            'cookie_secure' => ($_SERVER['HTTPS'] ?? '') !== ''
                || ($_SERVER['SERVER_PORT'] ?? '') === '443'
                || (Request::forwardedHeadersTrusted($remote, $this->trustedProxies)
                    && strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https'),
        ]);
        // Leerlauf: zu lange keine Anfrage, dann ist die Anmeldung vorbei.
        $now = time();
        if (isset($_SESSION['user_id']) && $this->idleTimeout > 0
            && $now - (int) ($_SESSION['last_activity'] ?? $now) > $this->idleTimeout) {
            unset($_SESSION['user_id'], $_SESSION['session_version'], $_SESSION['logout_token']);
        }
        if (isset($_SESSION['user_id'])) {
            $_SESSION['last_activity'] = $now;
        }
    }

    /**
     * Token für den Abmelde-Link (GET /logout?token=…): ohne ihn meldet ein
     * fremder Link oder ein Bild nicht ab, sondern es erscheint eine
     * Rückfrage (PageController::logout()).
     */
    public function logoutToken(): string {
        $this->start();
        if (!isset($_SESSION['logout_token']) || !is_string($_SESSION['logout_token'])) {
            $_SESSION['logout_token'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['logout_token'];
    }

    public function isValidLogoutToken(string $token): bool {
        $this->start();
        return $token !== '' && isset($_SESSION['logout_token']) && is_string($_SESSION['logout_token'])
            && hash_equals($_SESSION['logout_token'], $token);
    }

    public function login(int $userId): void {
        $this->start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['last_activity'] = time();
        $_SESSION['session_version'] = (int) ($this->users->findById($userId)['session_version'] ?? 0);
    }

    public function logout(): void {
        $this->start();
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Angemeldetes Konto der Session, null ohne Login oder wenn die Session
     * vor dem letzten Passwort-Reset entstand (users.session_version höher
     * als beim Login gemerkt): dann endet sie hier.
     */
    public function currentUserId(): ?int {
        $this->start();
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        $userId = (int) $_SESSION['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null || (int) ($user['session_version'] ?? 0) !== (int) ($_SESSION['session_version'] ?? 0)) {
            unset($_SESSION['user_id'], $_SESSION['session_version']);
            return null;
        }
        return $userId;
    }

    /** Sprachwahl für ausgeloggte Seiten (Login/Registrierung/Passwort/Public-Share) - siehe I18n\LocaleResolver. */
    public function language(): ?string {
        $this->start();
        return isset($_SESSION['language']) ? (string) $_SESSION['language'] : null;
    }

    public function setLanguage(string $locale): void {
        $this->start();
        $_SESSION['language'] = $locale;
    }
}
