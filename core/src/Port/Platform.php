<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Angaben der Variante für GET /api/capabilities.
 */
interface Platform {
	/** 'nextcloud' oder 'standalone' */
	public function backend(): string;

	/** Gemeinsame Versionsnummer (appinfo/info.xml). */
	public function version(): string;

	/**
	 * Funktionen, die nur diese Variante anbietet (noch nicht über den Kern
	 * laufen oder plattformeigen sind), zusätzlich zu Api\CapabilitiesApi::CORE_FEATURES.
	 *
	 * @return list<string>
	 */
	public function features(): array;
}
