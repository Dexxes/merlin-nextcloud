<?php

declare(strict_types=1);

namespace Merlin\Standalone\Mail;

/**
 * Kein Mailversand eingerichtet ('mail' => ['transport' => 'none'], im
 * Docker-Image ohne MERLIN_SMTP_HOST). Offene Registrierung ist dann
 * gesperrt, "Passwort vergessen" verweist an den Admin.
 */
final class DisabledMailer implements MailerInterface {
    public function isAvailable(): bool {
        return false;
    }

    public function send(string $toAddress, string $subject, string $bodyText): void {
        throw new \RuntimeException('Kein Mailversand eingerichtet (mail.transport)');
    }
}
