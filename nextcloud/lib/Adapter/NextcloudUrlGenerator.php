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

	public function shareUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.public_share.show', ['token' => $token]);
	}

	/** Wie bisher im YoutubeEmbedController: Protokoll und Host des Requests (samt overwritehost). */
	public function origin(): string {
		$parts = parse_url($this->urlGenerator->getAbsoluteURL('/'));
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $port;
	}
}
