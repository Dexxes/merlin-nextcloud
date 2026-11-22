<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\UserDirectory;
use OCP\IUserManager;

/**
 * UserDirectory-Port des Kerns über IUserManager.
 */
class NextcloudUserDirectory implements UserDirectory {
	public function __construct(
		private IUserManager $userManager,
	) {
	}

	public function displayName(string $userId): string {
		return $this->userManager->get($userId)?->getDisplayName() ?? '';
	}
}
