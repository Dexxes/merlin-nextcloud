<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

/**
 * Antwort eines API-Handlers, unabhängig von der Variante. Die Adapter machen
 * daraus eine Nextcloud-Response bzw. Http\Response des Standalone-Servers.
 *
 * - json: Daten werden als JSON ausgegeben
 * - body: fertiger Text mit eigenem Content-Type (HTML-Export)
 * - stream: Server-Sent Events; der Emitter schreibt selbst mit echo/flush,
 *   der Adapter setzt vorher die Header und beendet danach den Request
 * - passthrough: der Emitter schreibt die ganze Antwort selbst (Status,
 *   Header, Inhalt: MP3 vom Vorlese-Dienst, durchgereichte PDF, Datei); der
 *   Adapter leert nur die Puffer, gibt die Session frei und beendet danach
 */
final class ApiResult {
	public const JSON = 'json';
	public const BODY = 'body';
	public const STREAM = 'stream';
	public const PASSTHROUGH = 'passthrough';

	/** Header einer Server-Sent-Events-Antwort. */
	public const SSE_HEADERS = [
		'Content-Type' => 'text/event-stream; charset=utf-8',
		'Cache-Control' => 'no-cache',
		'Connection' => 'keep-alive',
		// nginx soll den Stream nicht puffern.
		'X-Accel-Buffering' => 'no',
	];

	/**
	 * @param array<string, string> $headers
	 */
	private function __construct(
		public readonly string $kind,
		public readonly int $status,
		public readonly mixed $data,
		public readonly array $headers = [],
		public readonly ?\Closure $emitter = null,
	) {
	}

	public static function json(mixed $data, int $status = 200): self {
		return new self(self::JSON, $status, $data);
	}

	/** 204 ohne Inhalt (Nextcloud: DataResponse([], 204), wie bisher). */
	public static function noContent(): self {
		return new self(self::JSON, 204, []);
	}

	public static function error(string $message, int $status, array $extra = []): self {
		return new self(self::JSON, $status, ['error' => $message] + $extra);
	}

	/** @param array<string, string> $headers */
	public static function body(string $body, string $contentType, int $status = 200, array $headers = []): self {
		return new self(self::BODY, $status, $body, ['Content-Type' => $contentType] + $headers);
	}

	/** @param callable(): void $emitter */
	public static function stream(callable $emitter): self {
		return new self(self::STREAM, 200, null, self::SSE_HEADERS, \Closure::fromCallable($emitter));
	}

	/** @param callable(): void $emitter */
	public static function passthrough(callable $emitter): self {
		return new self(self::PASSTHROUGH, 200, null, [], \Closure::fromCallable($emitter));
	}
}
