<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

/**
 * Abgelehnte Kommentar-/Markierungsaktion mit Fehlercode für den Client
 * (z. B. 'name_too_short', 'body_empty') und passendem HTTP-Status.
 */
class CommentException extends \RuntimeException {
	public function __construct(
		private string $errorCode,
		private int $status = 400,
	) {
		parent::__construct($errorCode);
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	public function getStatus(): int {
		return $this->status;
	}
}
