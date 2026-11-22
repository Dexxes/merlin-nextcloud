<?php

declare(strict_types=1);

namespace OCA\Merlin\Settings;

use OCA\Merlin\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Eigener Abschnitt "Merlin" in den Verwaltungseinstellungen.
 */
class AdminSection implements IIconSection {

	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Merlin');
	}

	/**
	 * Sortiergewicht in der Seitenleiste. 75 platziert den Abschnitt unter den
	 * Kern-Einstellungen von Nextcloud, aber über den unsortierten Apps.
	 */
	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'icon-32.png');
	}
}
