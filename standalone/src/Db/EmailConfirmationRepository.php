<?php

declare(strict_types=1);

namespace Merlin\Standalone\Db;

use PDO;

/**
 * Bestätigungslinks der Selbstregistrierung (Tabelle email_confirmations,
 * siehe Auth\RegistrationService). Gespeichert wird nur der SHA-256-Hash.
 */
final class EmailConfirmationRepository {
    public function __construct(private readonly PDO $db) {
    }

    public function create(int $userId, string $tokenHash, string $expiresAt): void {
        $this->db->prepare('DELETE FROM email_confirmations WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        $this->db->prepare(
            'INSERT INTO email_confirmations (user_id, token_hash, created_at, expires_at)
             VALUES (:user_id, :token_hash, :created_at, :expires_at)'
        )->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'created_at' => gmdate('c'),
            'expires_at' => $expiresAt,
        ]);
    }

    public function findValidByHash(string $tokenHash): ?array {
        $stmt = $this->db->prepare('SELECT * FROM email_confirmations WHERE token_hash = :hash AND expires_at > :now');
        $stmt->execute(['hash' => $tokenHash, 'now' => gmdate('c')]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Wartet das Konto noch auf die Bestätigung (gültig oder abgelaufen)? */
    public function isPending(int $userId): bool {
        $stmt = $this->db->prepare('SELECT 1 FROM email_confirmations WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public function deleteForUser(int $userId): void {
        $this->db->prepare('DELETE FROM email_confirmations WHERE user_id = :user_id')->execute(['user_id' => $userId]);
    }

    /**
     * Täglicher Lauf: nie bestätigte Konten mit abgelaufenem Link löschen
     * (nur inaktive; ein vom Admin deaktiviertes Konto hat keine Zeile hier).
     *
     * @return int gelöschte Konten
     */
    public function deleteExpiredUnconfirmed(): int {
        $now = gmdate('c');
        $stmt = $this->db->prepare(
            'DELETE FROM users WHERE is_active = 0 AND id IN (SELECT user_id FROM email_confirmations WHERE expires_at < :now)'
        );
        $stmt->execute(['now' => $now]);
        $deleted = $stmt->rowCount();
        $this->db->prepare('DELETE FROM email_confirmations WHERE expires_at < :now')->execute(['now' => $now]);
        return $deleted;
    }
}
