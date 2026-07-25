<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
	// Platzhalter für den Spenden-Link im About-Block (Settings.vue) – bewusst als
	// Einzeiler-Konstante statt info.xml, da info.xml kein <donate>-Feld kennt.
	private const DONATE_URL = 'https://github.com/sponsors/Dexxes';

	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private IInitialState $initialState,
		private IAppManager $appManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'merlin-main');
		Util::addStyle(Application::APP_ID, 'merlin-main');

		// PWA: pass SW URL to frontend via initial state
		$this->initialState->provideInitialState(
			'swUrl',
			$this->urlGenerator->linkToRoute('merlin.service_worker.index')
		);

		// About-Block (Settings.vue): info.xml bleibt Single Source of Truth für
		// Version/Autor/Lizenz/Links, damit UI und info.xml nie auseinanderlaufen.
		$this->initialState->provideInitialState('appInfo', $this->buildAppInfo());

		// PWA: Web App Manifest
		Util::addHeader('link', [
			'rel'  => 'manifest',
			'href' => $this->urlGenerator->linkToRoute('merlin.manifest.index'),
		]);

		// PWA: Apple / iOS meta tags
		Util::addHeader('meta', ['name' => 'apple-mobile-web-app-capable',          'content' => 'yes']);
		Util::addHeader('meta', ['name' => 'apple-mobile-web-app-status-bar-style', 'content' => 'default']);
		Util::addHeader('meta', ['name' => 'apple-mobile-web-app-title',            'content' => 'Merlin']);
		Util::addHeader('meta', ['name' => 'theme-color',                           'content' => '#0082c9']);
		Util::addHeader('link', [
			'rel'  => 'apple-touch-icon',
			'href' => $this->urlGenerator->imagePath(Application::APP_ID, 'apple-touch-icon.png'),
		]);

		$response = new TemplateResponse(Application::APP_ID, 'main');

		// Allow loading images from any external domain (articles contain images from various sources)
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedImageDomain('*');
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	/**
	 * Liefert die Rohdaten für den About-Block in Settings.vue.
	 *
	 * @return array{version: string, build: ?string, author: ?string, licence: ?string, website: ?string, bugs: ?string, donate: string}
	 */
	private function buildAppInfo(): array {
		$appInfo = $this->appManager->getAppInfo(Application::APP_ID) ?? [];

		return [
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
			'build'   => $this->readBuildStamp(),
			'author'  => is_array($appInfo['author'] ?? null) ? implode(', ', $appInfo['author']) : ($appInfo['author'] ?? null),
			'licence' => $appInfo['licence'] ?? null,
			'website' => $appInfo['website'] ?? null,
			'bugs'    => $appInfo['bugs'] ?? null,
			'donate'  => self::DONATE_URL,
		];
	}

	/**
	 * appinfo/build.txt ist optional (siehe package.json build:stamp) und wird
	 * bewusst nicht mitversioniert – fehlt sie, zeigt die UI nur die info.xml-Version.
	 */
	private function readBuildStamp(): ?string {
		$path = __DIR__ . '/../../appinfo/build.txt';
		if (!is_file($path)) {
			return null;
		}
		$content = trim((string)file_get_contents($path));
		return $content !== '' ? $content : null;
	}
}
