<?php

declare(strict_types=1);

namespace Merlin\Standalone\Http;

final class Response {
    private static string $basePath = '/';

    public static function setBasePath(string $basePath): void {
        self::$basePath = rtrim($basePath, '/');
    }

    public static function getBasePath(): string {
        return self::$basePath;
    }

    private function __construct(
        private readonly int $status,
        private readonly string $contentType,
        private readonly string $body,
        private readonly array $extraHeaders = [],
        private readonly ?\Closure $emitter = null,
        private readonly bool $passthrough = false,
    ) {
    }

    /**
     * Beliebiger Text mit Status und Headern (Content-Type in $headers).
     *
     * @param array<string, string> $headers
     */
    public static function raw(int $status, array $headers, string $body): self {
        $contentType = $headers['Content-Type'] ?? 'text/plain; charset=utf-8';
        unset($headers['Content-Type']);
        return new self($status, $contentType, $body, $headers);
    }

    /**
     * Server-Sent Events: send() setzt die Header, gibt die Session frei und
     * lässt den Emitter schreiben (echo/flush).
     *
     * @param array<string, string> $headers
     */
    public static function stream(array $headers, \Closure $emitter): self {
        $contentType = $headers['Content-Type'] ?? 'text/event-stream; charset=utf-8';
        unset($headers['Content-Type']);
        return new self(200, $contentType, '', $headers, $emitter);
    }

    /**
     * Der Emitter schreibt Status, Header und Inhalt selbst (MP3 vom
     * Vorlese-Dienst, durchgereichte PDF) und beendet den Request meist selbst.
     */
    public static function passthrough(\Closure $emitter): self {
        return new self(200, '', '', [], $emitter, true);
    }

    public static function json(mixed $data, int $status = 200): self {
        return new self($status, 'application/json', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    public static function html(string $html, int $status = 200, array $extraHeaders = []): self {
        return new self($status, 'text/html; charset=UTF-8', $html, $extraHeaders);
    }

    public static function redirect(string $location, int $status = 303): self {
        // Wenn location mit '/' startet, base_path voranstellen
        if (str_starts_with($location, '/') && self::$basePath !== '') {
            $location = self::$basePath . $location;
        }
        return new self($status, 'text/plain', '', ['Location' => $location]);
    }

    public static function noContent(): self {
        return new self(204, 'text/plain', '');
    }

    /**
     * Datei-Download über Content-Disposition: attachment - genutzt vom
     * HTML-Export (ArticleController) und vom Content-Filter-Export
     * (ContentFilterController).
     */
    public static function download(string $content, string $filename, string $contentType): self {
        $safeName = str_replace(['"', "\r", "\n"], '', $filename);
        return new self(200, $contentType, $content, [
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
        ]);
    }

    public static function file(string $content, string $contentType, array $extraHeaders = []): self {
        return new self(200, $contentType, $content, $extraHeaders);
    }

    /** @return array<string, string> */
    public function headers(): array {
        return ['Content-Type' => $this->contentType] + $this->extraHeaders;
    }

    public function status(): int {
        return $this->status;
    }

    public function body(): string {
        return $this->body;
    }

    public function isStream(): bool {
        return $this->emitter !== null;
    }

    public function send(): void {
        if ($this->emitter !== null) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        if ($this->passthrough) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ($this->emitter)();
            return;
        }
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        foreach ($this->extraHeaders as $name => $value) {
            header("{$name}: {$value}");
        }
        if ($this->emitter !== null) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ob_implicit_flush(true);
            ($this->emitter)();
            return;
        }
        echo $this->body;
    }
}
