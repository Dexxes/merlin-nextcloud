<?php

declare(strict_types=1);

namespace Merlin\Standalone\Auth;

use Merlin\Standalone\Db\UserRepository;

/**
 * PHP-Session für die HTML-Seiten (Login, Account, Admin-UI). Die JSON-API
 * unter /api/* nutzt ausschließlich Basic Auth mit API-Tokens (siehe
 * ApiTokenService) - Sessions spielen dort keine Rolle.
 */
final class SessionService {
    public function __construct(private readonly UserRepository $users) {
    }

    public function start(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                // Secure-Flag nur setzen, wenn der Request tatsächlich über HTTPS
                // kam - sonst würde das Cookie in einem reinen HTTP-Dev-Setup
                // (php -S) nie gesendet und der Login liefe endlos ins Leere.
                // X-Forwarded-Proto zählt mit, weil Synology-Reverse-Proxies TLS
                // typischerweise terminieren und intern per HTTP weiterreichen.
                'cookie_secure' => ($_SERVER['HTTPS'] ?? '') !== ''
                    || ($_SERVER['SERVER_PORT'] ?? '') === '443'
                    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            ]);
        }
    }

    public function login(int $userId): void {
        $this->start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
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
