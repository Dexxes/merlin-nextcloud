<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class ManifestController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): DataResponse {
		$startUrl = $this->urlGenerator->linkToRoute('merlin.page.index');

		$manifest = [
			'name'             => 'Merlin',
			'short_name'       => 'Merlin',
			'description'      => 'Save and read web articles',
			'start_url'        => $startUrl,
			'scope'            => $startUrl,
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => '#0082c9',
			'icons'            => [
				[
					'src'   => $this->urlGenerator->imagePath(Application::APP_ID, 'icon-192.png'),
					'sizes' => '192x192',
					'type'  => 'image/png',
				],
				[
					'src'     => $this->urlGenerator->imagePath(Application::APP_ID, 'icon-512.png'),
					'sizes'   => '512x512',
					'type'    => 'image/png',
				],
				[
					'src'     => $this->urlGenerator->imagePath(Application::APP_ID, 'icon-512.png'),
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				],
			],
		];

		$response = new DataResponse($manifest);
		$response->addHeader('Content-Type', 'application/manifest+json');
		return $response;
	}
}
