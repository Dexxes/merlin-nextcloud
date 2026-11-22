<?php

declare(strict_types=1);

namespace Merlin\Core\Service;

/**
 * Antwort von ShareAccessService::resolveAccessibleShare(), wenn der Link
 * nicht (mehr) offen ist: HTTP-Status und JSON-Body für den Aufrufer.
 */
final class ShareAccessDenied {
	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct(
		private int $status,
		private array $data,
	) {
	}

	public function getStatus(): int {
		return $this->status;
	}

	/** @return array<string, mixed> */
	public function getData(): array {
		return $this->data;
	}
}
