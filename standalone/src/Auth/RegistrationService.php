<?php

declare(strict_types=1);

namespace Merlin\Standalone\Auth;

use Merlin\Standalone\Db\EmailConfirmationRepository;
use Merlin\Standalone\Db\ServerSettings;
use Merlin\Standalone\Db\UserRepository;
use Merlin\Standalone\Mail\MailerInterface;

/**
 * Selbstregistrierung mit Bestätigung per E-Mail. Die Antwort an den Client
 * ist für neue und schon bekannte Adressen gleich ("Mail ist unterwegs"):
 * so lässt sich nicht erraten, welche Adressen ein Konto haben. Eine
 * bekannte Adresse bekommt statt des Links einen Hinweis; eine noch
 * unbestätigte Registrierung wird durch die neue ersetzt.
 *
 * Offen ist die Registrierung nur, wenn der Admin sie freigibt UND ein
 * Mailversand eingerichtet ist (isOpen()).
 */
final class RegistrationService {
    /** Gültigkeit des Bestätigungslinks. */
    public const TTL_SECONDS = 86400;

    public function __construct(
        private readonly UserRepository $users,
        private readonly EmailConfirmationRepository $confirmations,
        private readonly PasswordHasher $hasher,
        private readonly MailerInterface $mailer,
        private readonly string $baseUrl,
        private readonly ServerSettings $settings,
    ) {
    }

    /** Freigegeben (Verwaltung) und Mailversand eingerichtet? */
    public function isOpen(): bool {
        return $this->mailer->isAvailable() && $this->settings->getBool(ServerSettings::ALLOW_SELF_REGISTRATION);
    }

    public function mailAvailable(): bool {
        return $this->mailer->isAvailable();
    }

    /**
     * Legt das Konto inaktiv an und verschickt den Link; bei bekannter
     * Adresse nur eine Mail an diese. Wirft, wenn die Mail nicht rausgeht.
     */
    public function register(string $username, string $email, string $password): void {
        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            if (!(bool) $existing['is_active'] && $this->confirmations->isPending((int) $existing['id'])) {
                // Unbestätigte Registrierung: durch die neue ersetzen. Sonst
                // bestätigte der Inhaber der Adresse ein Konto, dessen
                // Passwort jemand anderes gewählt hat.
                $this->users->delete((int) $existing['id']);
                $existing = null;
            }
        }
        if ($existing !== null) {
            $this->mailer->send(
                $existing['email'],
                'Merlin – Registrierung mit deiner E-Mail-Adresse',
                "Hallo {$existing['username']},\n\n"
                . "jemand wollte mit deiner E-Mail-Adresse ein neues Merlin-Konto anlegen. "
                . "Du hast schon ein Konto. Falls du dein Passwort vergessen hast:\n"
                . rtrim($this->baseUrl, '/') . "/password/forgot\n\n"
                . "Falls du das nicht warst, ignoriere diese E-Mail.\n"
            );
            return;
        }
        $user = $this->users->create($username, $email, $this->hasher->hash($password), 'user', false);
        try {
            $this->sendConfirmation($user);
        } catch (\Throwable $e) {
            // Ohne Mail kein Weg zur Bestätigung: Konto wieder weg, damit
            // Name und Adresse frei bleiben.
            $this->users->delete((int) $user['id']);
            throw $e;
        }
    }

    /**
     * Bestätigt das Konto zum Token.
     *
     * @return int|null Nutzer-ID, null bei ungültigem oder abgelaufenem Link
     */
    public function confirm(string $plainTextToken): ?int {
        $row = $plainTextToken === '' ? null : $this->confirmations->findValidByHash(hash('sha256', $plainTextToken));
        if ($row === null) {
            return null;
        }
        $userId = (int) $row['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return null;
        }
        $this->users->updateRoleAndStatus($userId, (string) $user['role'], true);
        $this->confirmations->deleteForUser($userId);
        return $userId;
    }

    /** @param array<string, mixed> $user */
    private function sendConfirmation(array $user): void {
        $plainText = bin2hex(random_bytes(32));
        $this->confirmations->create((int) $user['id'], hash('sha256', $plainText), gmdate('c', time() + self::TTL_SECONDS));
        $link = rtrim($this->baseUrl, '/') . '/register/confirm?token=' . $plainText;
        $this->mailer->send(
            $user['email'],
            'Merlin – E-Mail-Adresse bestätigen',
            "Hallo {$user['username']},\n\n"
            . "bitte bestätige dein neues Merlin-Konto über diesen Link:\n{$link}\n\n"
            . "Der Link ist 24 Stunden gültig. Falls du dich nicht registriert hast, ignoriere diese E-Mail.\n"
        );
    }
}
