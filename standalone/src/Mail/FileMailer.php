<?php

declare(strict_types=1);

namespace Merlin\Standalone\Mail;

/**
 * Schreibt Mails in eine Datei statt sie zu verschicken ('mail' =>
 * ['file' => …] in config.php): für Entwicklung und Tests, eine Mail je
 * Zeile als JSON.
 */
final class FileMailer implements MailerInterface {
    public function __construct(private readonly string $file) {
    }

    public function isAvailable(): bool {
        return true;
    }

    public function send(string $toAddress, string $subject, string $bodyText): void {
        $line = json_encode(['to' => $toAddress, 'subject' => $subject, 'body' => $bodyText], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Could not write mail to {$this->file}");
        }
    }
}
