<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\Translations;
use Merlin\Core\Port\Translator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * Translations-Port: IL10N der App in der Nextcloud-Sprache des Nutzers
 * (nicht der des Geräts, das gerade fragt).
 */
class NextcloudTranslations implements Translations {
	public function __construct(
		private IFactory $l10nFactory,
		private IUserManager $userManager,
	) {
	}

	public function forUser(string $userId): Translator {
		$user = $this->userManager->get($userId);
		return new NextcloudTranslator($this->l10nFactory->get('merlin', $this->l10nFactory->getUserLanguage($user)));
	}
}
