<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media\Provider;

use OCA\Merlin\Service\Media\MediaContext;
use OCA\Merlin\Service\Media\MediaResult;
use OCA\Merlin\Service\Media\MediaSourceProviderInterface;

/**
 * type="youtube-embed": offizieller YouTube-iframe-Player (youtube-nocookie),
 * KEINE inoffizielle Stream-Auflösung. Die Video-ID steht in der
 * Artikel-URL selbst, der Provider braucht also weder HTML noch Netzwerk und
 * funktioniert auch für Artikel, die vor Einführung der <media>-Sektion
 * gespeichert wurden.
 *
 * Unterstützte Formen: youtube.com/watch?v=ID, youtu.be/ID,
 * youtube.com/shorts/ID, /live/ID, /embed/ID; optionaler Startzeitpunkt
 * über t=/start= (Sekunden oder "1h2m3s").
 */
class YoutubeEmbedProvider implements MediaSourceProviderInterface {
	private const VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{6,32}$/';

	public function type(): string {
		return 'youtube-embed';
	}

	public function resolvesPerRequest(): bool {
		return false;
	}

	public function resolve(MediaContext $context): ?MediaResult {
		$id = $this->extractVideoId($context->articleUrl);
		if ($id === null) {
			return null;
		}

		$embedUrl = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id);
		$start = $this->extractStartSeconds($context->articleUrl);
		if ($start > 0) {
			$embedUrl .= '?start=' . $start;
		}

		return MediaResult::single(MediaResult::KIND_VIDEO, MediaResult::DELIVERY_EMBED, $embedUrl, 'YouTube');
	}

	private function extractVideoId(string $url): ?string {
		$host = strtolower((string) parse_url($url, PHP_URL_HOST));
		$path = (string) parse_url($url, PHP_URL_PATH);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

		$candidate = null;
		if ($host === 'youtu.be' || str_ends_with($host, '.youtu.be')) {
			$candidate = explode('/', trim($path, '/'))[0] ?? null;
		} elseif (preg_match('#^/(?:shorts|live|embed)/([^/?]+)#', $path, $m) === 1) {
			$candidate = $m[1];
		} elseif (isset($query['v']) && is_string($query['v'])) {
			$candidate = $query['v'];
		}

		return is_string($candidate) && preg_match(self::VIDEO_ID_PATTERN, $candidate) === 1 ? $candidate : null;
	}

	private function extractStartSeconds(string $url): int {
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
		$raw = $query['t'] ?? $query['start'] ?? null;
		if (!is_string($raw) || $raw === '') {
			return 0;
		}
		if (ctype_digit($raw)) {
			return (int) $raw;
		}
		if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $raw, $m) === 1) {
			return ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);
		}
		return 0;
	}
}
