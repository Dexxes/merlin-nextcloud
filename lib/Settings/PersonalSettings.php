<?php

declare(strict_types=1);

namespace OCA\Merlin\Settings;

use OCA\Merlin\AppInfo\Application;
use OCA\Merlin\Service\ContentFilterRepository;
use OCA\Merlin\Service\ContentFilterSchema;
use OCA\Merlin\Service\SiteCredentialService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Persönliche Einstellungen für Merlin: Pflege des eigenen, privaten
 * Content-Filter-Overrides (Pendant zu AdminSettings, aber ISettings war hier
 * schon immer die richtige Wahl – es gibt keine Delegationsfrage, jeder Nutzer
 * sieht ausschliesslich seine eigene Seite).
 */
class PersonalSettings implements ISettings {

	public function __construct(
		private IL10N $l,
		private IInitialState $initialState,
		private ContentFilterRepository $repository,
		private SiteCredentialService $siteCredentials,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		$userId = $this->userSession->getUser()?->getUID() ?? '';

		$ownDomains = array_flip($this->repository->listUserOverrideDomains($userId));
		$domains    = array_map(static function (array $entry) use ($ownDomains) {
			return [
				'domain'         => $entry['domain'],
				'hasBundle'      => $entry['hasBundle'],
				'hasAdminCustom' => $entry['hasCustom'],
				'hasOwnOverride' => isset($ownDomains[$entry['domain']]),
			];
		}, $this->repository->listFilters());

		// Admins verwalten instanzweite Filter auf einer separaten Seite
		// (AdminSettings). Statt sie dorthin suchen zu lassen, verlinken wir
		// direkt aus den persönlichen Einstellungen – nur für Admins sichtbar,
		// da Nicht-Admins dort ohnehin keine funktionierende API vorfänden.
		$isAdmin = $userId !== '' && $this->groupManager->isAdmin($userId);

		// Erststand mitgeben, damit die Oberfläche nicht erst nach einem
		// Roundtrip die Domainliste zeigt.
		$this->initialState->provideInitialState('userContentFilters', [
			'domains'         => $domains,
			'schema'          => ContentFilterSchema::describe(),
			'isAdmin'         => $isAdmin,
			'adminSettingsUrl' => $isAdmin
				? $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID])
				: null,
		]);

		$this->initialState->provideInitialState('siteCredentials', [
			'credentials'      => $this->siteCredentials->listForUser($userId),
			'availableDomains' => $this->siteCredentials->listLoginCapableDomains(),
		]);

		// Die Personal-Settings-Seite läuft unter der Shell der "settings"-App;
		// Core lädt dort automatisch nur deren eigene Übersetzungen. Ohne diesen
		// Aufruf bleibt OC.L10N für "merlin" leer und alle t()-Aufrufe im Vue-Code
		// fallen auf den englischen Quelltext zurück.
		Util::addTranslations(Application::APP_ID);

		return new TemplateResponse(Application::APP_ID, 'personal-settings');
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
