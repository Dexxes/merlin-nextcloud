<?php

declare(strict_types=1);

namespace Merlin\Standalone\Mail;

/**
 * Versand über einen SMTP-Server ('mail' => ['smtp' => […]] in config.php,
 * Docker: MERLIN_SMTP_*), ohne Bibliothek: EHLO, STARTTLS oder TLS ab dem
 * Verbindungsaufbau, AUTH PLAIN bzw. LOGIN, eine Mail je Verbindung.
 * Nur Text (UTF-8, base64), wie die übrigen Mailer.
 *
 * encryption: 'starttls' (Standard, Port 587), 'tls' (implizit, Port 465)
 * oder 'none' (nur für einen Relay im eigenen Netz). Zertifikate werden
 * immer geprüft.
 */
final class SmtpMailer implements MailerInterface {
    public const ENCRYPTIONS = ['starttls', 'tls', 'none'];

    /** @var resource|null */
    private $socket = null;

    /** @var array{0: int, 1: string} letzte EHLO-Antwort (Fähigkeiten) */
    private array $lastEhlo = [0, ''];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly int $timeout = 15,
    ) {
        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new \InvalidArgumentException("mail.smtp.encryption muss starttls, tls oder none sein, nicht '{$encryption}'");
        }
    }

    public function isAvailable(): bool {
        return true;
    }

    public function send(string $toAddress, string $subject, string $bodyText): void {
        foreach ([$toAddress, $this->fromAddress] as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException("Ungültige E-Mail-Adresse: {$address}");
            }
        }
        try {
            $this->connect();
            $this->expect(220);
            $this->ehlo();
            if ($this->encryption === 'starttls') {
                $this->command('STARTTLS', 220);
                if (@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    throw new \RuntimeException('SMTP: STARTTLS fehlgeschlagen');
                }
                $this->ehlo();
            }
            if ($this->username !== '') {
                $this->authenticate();
            }
            $this->command('MAIL FROM:<' . $this->fromAddress . '>', 250);
            $this->command('RCPT TO:<' . $toAddress . '>', [250, 251]);
            $this->command('DATA', 354);
            $this->write($this->message($toAddress, $subject, $bodyText) . "\r\n.\r\n");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    /** Die ganze Nachricht (Header und Body), Zeilenenden CRLF, Punkte am Zeilenanfang verdoppelt. */
    public function message(string $toAddress, string $subject, string $bodyText): string {
        $domain = substr((string) strrchr($this->fromAddress, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::encodeHeader($this->fromName) . ' <' . $this->fromAddress . '>',
            'To: <' . $toAddress . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        // base64 enthält keine Punkte, Dot-Stuffing ist damit überflüssig.
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($bodyText), 76, "\r\n"));
    }

    private static function encodeHeader(string $value): string {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function connect(): void {
        $scheme = $this->encryption === 'tls' ? 'tls' : 'tcp';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
        ]]);
        $socket = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("SMTP: keine Verbindung zu {$this->host}:{$this->port} ({$error})");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    private function ehlo(): void {
        $this->command('EHLO ' . (gethostname() ?: 'localhost'), 250);
    }

    private function authenticate(): void {
        [, $reply] = $this->lastEhlo;
        if (preg_match('/^AUTH\b.*\bPLAIN\b/mi', $reply) === 1) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), 235, secret: true);
            return;
        }
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->username), 334, secret: true);
        $this->command(base64_encode($this->password), 235, secret: true);
    }

    /** @param int|list<int> $expected */
    private function command(string $line, int|array $expected, bool $secret = false): void {
        $this->write($line . "\r\n");
        $reply = $this->expect($expected, $secret ? 'AUTH' : $line);
        if (str_starts_with($line, 'EHLO ')) {
            $this->lastEhlo = $reply;
        }
    }

    private function write(string $data): void {
        if (@fwrite($this->socket, $data) !== strlen($data)) {
            throw new \RuntimeException('SMTP: Schreiben fehlgeschlagen');
        }
    }

    /**
     * @param int|list<int> $expected
     * @return array{0: int, 1: string} Code und ganze Antwort ohne Codes
     */
    private function expect(int|array $expected, string $after = 'Verbindung'): array {
        $text = '';
        do {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                throw new \RuntimeException("SMTP: keine Antwort nach {$after}");
            }
            $code = (int) substr($line, 0, 3);
            $text .= substr(rtrim($line, "\r\n"), 4) . "\n";
        } while (($line[3] ?? ' ') === '-');
        if (!in_array($code, (array) $expected, true)) {
            throw new \RuntimeException("SMTP: {$after} -> {$code} " . trim($text));
        }
        return [$code, $text];
    }
}
