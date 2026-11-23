<?php

declare(strict_types=1);

namespace OCA\Merlin\Tests\Api;

/**
 * Read aloud, PDF pass-through, media sources and the YouTube page
 * (TtsController, PdfController, MediaController, VideoStreamController,
 * YoutubeEmbedController, PublicShareController tts/pdf; handlers in the
 * core: ArticleStreamApi, MediaApi).
 */
class MediaApiTest extends ApiTestCase {
	public function testTtsPdfAndMediaForTheOwner(): void {
		$owner = self::user();
		$id = self::saveArticle($owner, 'Vorlesen')['id'];

		// No Piper daemon in CI: 503; with one the MP3 stream starts.
		$tts = self::api('GET', "/api/articles/{$id}/tts?lang=de", null, $owner);
		$this->assertContains($tts['status'], [200, 503], 'tts: ' . substr($tts['body'], 0, 200));
		if ($tts['status'] === 503) {
			$this->assertSame('TTS daemon unavailable', $tts['json']['error'] ?? null);
		} else {
			$this->assertStringStartsWith('audio/mpeg', $tts['headers']['content-type'] ?? '');
		}
		self::expect(404, self::api('GET', "/api/articles/{$id}/tts", null, self::user('-b')));

		$this->assertSame('Not a PDF article', self::expect(404, self::api('GET', "/api/articles/{$id}/pdf", null, $owner))['error']);
		self::expect(404, self::api('GET', "/api/articles/{$id}/pdf", null, self::user('-b')));

		$this->assertSame(['available' => false], self::expect(200, self::api('GET', "/api/articles/{$id}/media", null, $owner)));
		$this->assertSame(['available' => false], self::expect(200, self::api('GET', "/api/articles/{$id}/video-stream", null, $owner)));
		self::expect(404, self::api('GET', "/api/articles/{$id}/media", null, self::user('-b')));
	}

	public function testPdfFromMerlinFilesAlsoBehindTheShareLink(): void {
		$user = self::user();
		$pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
		$target = self::expect(200, self::api('POST', '/api/files/target', ['name' => 'ci-dokument.pdf', 'mimeType' => 'application/pdf'], $user));
		$upload = self::request('PUT', $target['davPath'], $pdf, $user, ['Content-Type: application/pdf']);
		$this->assertContains($upload['status'], [201, 204], 'WebDAV upload: ' . $upload['body']);
		$id = self::expect(201, self::api('POST', '/api/files', ['path' => $target['path']], $user), 'register')['id'];

		$own = self::api('GET', "/api/articles/{$id}/pdf", null, $user);
		$this->assertSame(200, $own['status'], substr($own['body'], 0, 200));
		$this->assertStringStartsWith('%PDF-', $own['body']);

		$token = self::expect(201, self::api('POST', "/api/articles/{$id}/share", [], $user))['token'];
		$shared = self::request('GET', "/index.php/apps/merlin/s/{$token}/pdf");
		$this->assertSame(200, $shared['status'], substr($shared['body'], 0, 200));
		$this->assertStringStartsWith('%PDF-', $shared['body']);
	}

	public function testShareTtsAndPdfNeedTheUnlock(): void {
		$owner = self::user();
		$id = self::saveArticle($owner, 'Geteilt zum Vorlesen')['id'];
		$token = self::expect(201, self::api('POST', "/api/articles/{$id}/share", ['password' => 'geheim-123'], $owner))['token'];

		$this->assertTrue(self::expect(401, self::request('GET', "/index.php/apps/merlin/s/{$token}/tts"))['locked']);
		$this->assertTrue(self::expect(401, self::request('GET', "/index.php/apps/merlin/s/{$token}/pdf"))['locked']);
		self::expect(404, self::request('GET', '/index.php/apps/merlin/s/' . str_repeat('0', 32) . '/tts'));
	}

	public function testYoutubePageWithoutLogin(): void {
		$page = self::request('GET', '/index.php/apps/merlin/api/youtube-embed?v=dQw4w9WgXcQ&t=42');
		$this->assertSame(200, $page['status']);
		$this->assertStringStartsWith('text/html', $page['headers']['content-type'] ?? '');
		$this->assertStringContainsString('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?', $page['body']);
		$this->assertStringContainsString('start=42', $page['body']);
		$this->assertStringContainsString('origin=' . rawurlencode(rtrim(self::baseUrl(), '/')), $page['body']);
		$csp = $page['headers']['content-security-policy'] ?? '';
		$this->assertStringContainsString('frame-ancestors *', $csp);
		$this->assertStringContainsString('https://www.youtube-nocookie.com', $csp);

		$this->assertSame(400, self::request('GET', '/index.php/apps/merlin/api/youtube-embed?v=' . rawurlencode('"><script>'))['status']);
	}
}
