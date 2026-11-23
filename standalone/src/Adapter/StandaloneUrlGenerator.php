<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\Port\UrlGenerator;

/**
 * UrlGenerator-Port aus der Basis-URL in config.php (in Nextcloud:
 * IURLGenerator). Bilder liegen unter /img/ (dieselben Dateien wie
 * nextcloud/img/, siehe public/index.php).
 */
final class StandaloneUrlGenerator implements UrlGenerator {
	public function __construct(private string $baseUrl) {
		$this->baseUrl = rtrim($baseUrl, '/');
	}

	public function absoluteImageUrl(string $file): string {
		return $this->baseUrl . '/img/' . ltrim($file, '/');
	}

	public function imagePath(string $file): string {
		return rtrim((string) parse_url($this->baseUrl, PHP_URL_PATH), '/') . '/img/' . ltrim($file, '/');
	}

	public function fileContentUrl(array $params): string {
		$id = $params['id'] ?? '';
		unset($params['id']);
		return $this->baseUrl . '/api/files/' . rawurlencode((string) $id) . '/content'
			. ($params === [] ? '' : '?' . http_build_query($params));
	}

	public function shareUrl(string $token): string {
		return $this->baseUrl . '/s/' . rawurlencode($token);
	}

	public function origin(): string {
		$parts = parse_url($this->baseUrl);
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		return ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost') . $port;
	}

	public function absolute(string $path): string {
		return $this->baseUrl . '/' . ltrim($path, '/');
	}
}
