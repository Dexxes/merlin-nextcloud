<?php

declare(strict_types=1);

namespace OCA\Merlin\Settings;

use OCA\Merlin\AppInfo\Application;
use OCA\Merlin\Service\RetentionService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Verwaltungseinstellungen: Löschfrist für archivierte Artikel (Maximum für
 * normale Artikel und für Favoriten). Eigene ISettings-Klasse in der
 * Merlin-Sektion, damit die Content-Filter-Seite (AdminSettings) unberührt
 * bleibt; beide teilen sich das Skript merlin-admin (admin-main.js montiert
 * je nach vorhandenem Container).
 */
class RetentionAdminSettings implements ISettings {
	public function __construct(
		private IL10N $l,
		private IInitialState $initialState,
		private RetentionService $retentionService,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('retentionAdmin', $this->retentionService->getAdminLimits());
		Util::addTranslations(Application::APP_ID);

		return new TemplateResponse(Application::APP_ID, 'admin-retention');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		// Vor den Content-Filtern (AdminSettings, 10): kurz, betrifft alle Nutzer.
		return 5;
	}

	public function getName(): ?string {
		return $this->l->t('Retention');
	}
}
