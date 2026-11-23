<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Arbeit nach der HTTP-Antwort, damit der Client nicht wartet (Extraktion
 * eines neu gespeicherten Artikels). Beide Varianten nutzen
 * Service\ShutdownScheduler; Testharnesses führen den Auftrag sofort aus.
 */
interface Scheduler {
	public function afterResponse(callable $job): void;
}
