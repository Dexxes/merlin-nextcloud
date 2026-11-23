<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

/**
 * Eingabe eines API-Handlers: angemeldeter Nutzer und Parameter.
 *
 * Die Parameter sind wie in Nextclouds Request zusammengeführt: Query-String,
 * dann Body (JSON oder Formular), dann Pfad-Platzhalter (gewinnen). Die
 * typisierten Getter wandeln so um wie Nextclouds Dispatcher anhand der
 * Parametertypen eines Controllers: "false" ist false, sonst settype().
 */
final class ApiRequest {
	/**
	 * Header, die Handler lesen. Adapter, die nicht alle Header durchreichen
	 * (Nextcloud: IRequest::getHeader()), geben mindestens diese mit.
	 */
	public const HEADERS = ['Last-Event-ID', 'X-Merlin-Guest-Name'];

	/** @var \Closure(): string|null */
	private ?\Closure $rawBody;
	private ?array $decodedBody = null;
	/** @var array<string, string> */
	private array $headers = [];

	/**
	 * @param array<string, mixed> $params
	 * @param (\Closure(): string)|null $rawBody roher Body, erst bei Bedarf gelesen
	 * @param array<string, string> $headers Name => Wert (Groß-/Kleinschreibung egal)
	 * @param string $remoteAddress IP des Clients (Brute-Force-Schutz)
	 */
	public function __construct(
		private ?string $userId,
		private array $params = [],
		?\Closure $rawBody = null,
		array $headers = [],
		private string $remoteAddress = '',
	) {
		$this->rawBody = $rawBody;
		foreach ($headers as $name => $value) {
			$this->headers[strtolower((string) $name)] = (string) $value;
		}
	}

	/** Wert eines Request-Headers, '' wenn er fehlt (wie IRequest::getHeader()). */
	public function header(string $name): string {
		return $this->headers[strtolower($name)] ?? '';
	}

	public function remoteAddress(): string {
		return $this->remoteAddress;
	}

	public function userId(): string {
		if ($this->userId === null || $this->userId === '') {
			throw new \LogicException('No authenticated user on this request');
		}
		return $this->userId;
	}

	public function hasUser(): bool {
		return $this->userId !== null && $this->userId !== '';
	}

	public function has(string $name): bool {
		return array_key_exists($name, $this->params) && $this->params[$name] !== null;
	}

	public function param(string $name, mixed $default = null): mixed {
		return array_key_exists($name, $this->params) ? $this->params[$name] : $default;
	}

	public function string(string $name, ?string $default = null): ?string {
		$value = $this->param($name);
		if ($value === null) {
			return $default;
		}
		return is_scalar($value) ? (string) $value : $default;
	}

	public function int(string $name, ?int $default = null): ?int {
		$value = $this->param($name);
		if ($value === null) {
			return $default;
		}
		return is_scalar($value) ? (int) $value : $default;
	}

	public function float(string $name, ?float $default = null): ?float {
		$value = $this->param($name);
		if ($value === null) {
			return $default;
		}
		return is_scalar($value) ? (float) $value : $default;
	}

	public function bool(string $name, ?bool $default = null): ?bool {
		$value = $this->param($name);
		if ($value === null) {
			return $default;
		}
		if ($value === 'false') {
			return false;
		}
		return is_scalar($value) ? (bool) $value : $default;
	}

	/** @return array<mixed> JSON-Body unabhängig vom Content-Type, [] wenn keiner */
	public function jsonBody(): array {
		if ($this->decodedBody === null) {
			$raw = $this->rawBody !== null ? ($this->rawBody)() : '';
			$decoded = json_decode($raw, true);
			$this->decodedBody = is_array($decoded) ? $decoded : [];
		}
		return $this->decodedBody;
	}
}
