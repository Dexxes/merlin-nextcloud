<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\AppInfo\Application;
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
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private IInitialState $initialState,
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
}
