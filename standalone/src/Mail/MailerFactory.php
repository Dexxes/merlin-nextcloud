<?php

declare(strict_types=1);

namespace Merlin\Standalone\Mail;

/**
 * Wählt den Mailer aus dem Abschnitt 'mail' der config.php:
 *  - 'file' gesetzt: FileMailer (Entwicklung, Tests)
 *  - 'transport' => 'smtp': SmtpMailer mit 'smtp' => [host, port, encryption,
 *    username, password bzw. password_file]
 *  - 'transport' => 'none': DisabledMailer
 *  - sonst ('sendmail', Standard wie bisher): PhpMailMailer (mail())
 */
final class MailerFactory {
    /** @param array<string, mixed> $mail */
    public static function create(array $mail): MailerInterface {
        if (isset($mail['file']) && (string) $mail['file'] !== '') {
            return new FileMailer((string) $mail['file']);
        }
        $from = (string) ($mail['from_address'] ?? 'merlin@localhost');
        $name = (string) ($mail['from_name'] ?? 'Merlin');
        $transport = (string) ($mail['transport'] ?? 'sendmail');
        return match ($transport) {
            'smtp' => self::smtp((array) ($mail['smtp'] ?? []), $from, $name),
            'none', '' => new DisabledMailer(),
            'sendmail' => new PhpMailMailer($from, $name),
            default => throw new \InvalidArgumentException("mail.transport muss smtp, sendmail oder none sein, nicht '{$transport}'"),
        };
    }

    /** @param array<string, mixed> $smtp */
    private static function smtp(array $smtp, string $from, string $name): MailerInterface {
        $host = trim((string) ($smtp['host'] ?? ''));
        if ($host === '') {
            return new DisabledMailer();
        }
        $encryption = (string) ($smtp['encryption'] ?? 'starttls');
        $password = (string) ($smtp['password'] ?? '');
        if ($password === '' && isset($smtp['password_file']) && is_readable((string) $smtp['password_file'])) {
            $password = rtrim((string) file_get_contents((string) $smtp['password_file']), "\r\n");
        }
        return new SmtpMailer(
            $host,
            (int) ($smtp['port'] ?? ($encryption === 'tls' ? 465 : 587)),
            $encryption,
            (string) ($smtp['username'] ?? ''),
            $password,
            $from,
            $name,
            (int) ($smtp['timeout'] ?? 15),
        );
    }
}
