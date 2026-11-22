<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Konten der Plattform (Nextcloud: IUserManager).
 */
interface UserDirectory {
	/** Anzeigename des Kontos, '' wenn es das Konto nicht gibt oder es keinen hat. */
	public function displayName(string $userId): string;
}
