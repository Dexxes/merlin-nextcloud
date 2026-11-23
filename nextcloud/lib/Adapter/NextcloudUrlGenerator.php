<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\UrlGenerator;
use OCA\Merlin\AppInfo\Application;
use OCP\IURLGenerator;

/**
 * UrlGenerator-Port des Kerns über Nextclouds IURLGenerator.
 */
class NextcloudUrlGenerator implements UrlGenerator {
	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function absoluteImageUrl(string $file): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, $file)
		);
	}

	public function imagePath(string $file): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, $file);
	}

	public function fileContentUrl(array $params): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.file.content', $params);
	}
}
