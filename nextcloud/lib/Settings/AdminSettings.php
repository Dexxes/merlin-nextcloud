<?php

declare(strict_types=1);

namespace OCA\Merlin\Settings;

use OCA\Merlin\AppInfo\Application;
use OCA\Merlin\Service\ContentFilterRepository;
use OCA\Merlin\Service\ContentFilterSchema;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Verwaltungseinstellungen für Merlin: Pflege der Content-Filter.
 *
 * Bewusst ISettings und nicht IDelegatedSettings: Die API dahinter
 * (ContentFilterController) verlässt sich auf den Admin-Default des
 * AppFramework. Wäre diese Seite delegierbar, bekäme ein delegierter Nutzer eine
 * vollständig gerenderte Oberfläche – inklusive des absoluten Serverpfads aus
 * getForm() –, während jeder Speicher- oder Testaufruf mit 403 endet. Erst wenn
 * der Controller durchgängig #[AuthorizedAdminSetting] trägt, ergibt die
 * Delegation Sinn.
 */
class AdminSettings implements ISettings {

	public function __construct(
		private IL10N $l,
		private IInitialState $initialState,
		private ContentFilterRepository $repository,
	) {
	}

	public function getForm(): TemplateResponse {
		// Erststand mitgeben, damit die Oberfläche nicht erst nach einem
		// Roundtrip die Filterliste zeigt. Kein storage-Block mehr: Custom-Filter
		// liegen seit der DB-Umstellung in merlin_cfilter, nicht mehr in einem
		// konfigurierbaren Dateisystem-Pfad (siehe
		// tasks/content-filter-db-scopes-todo.md).
		$this->initialState->provideInitialState('contentFilters', [
			'filters' => $this->repository->listFilters(),
			'schema'  => ContentFilterSchema::describe(),
		]);

		// Siehe PersonalSettings::getForm(): ohne diesen Aufruf lädt Core auf der
		// "settings"-Seite nur deren eigene Übersetzungen, nicht die von Merlin.
		Util::addTranslations(Application::APP_ID);

		return new TemplateResponse(Application::APP_ID, 'admin-settings');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}

	public function getName(): ?string {
		return $this->l->t('Content filters');
	}
}
