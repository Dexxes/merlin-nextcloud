<?php

declare(strict_types=1);

namespace OCA\Merlin\Tests\Api;

/**
 * "Merlin files": upload into Nextcloud Files over WebDAV, register the
 * entry, fetch it over the signed link, rename it (FileController,
 * MerlinFileService, core FileEntryService; StoredFile and FilePreviews ports).
 */
class FileApiTest extends ApiTestCase {
	public function testUploadRegisterDownloadRename(): void {
		$user = self::user();
		$content = "Hallo aus der CI\n";

		$target = self::expect(200, self::api('POST', '/api/files/target', ['name' => 'ci-notiz.txt', 'mimeType' => 'text/plain'], $user), 'upload target');
		$this->assertStringEndsWith('/ci-notiz.txt', $target['path']);
		$this->assertStringStartsWith('/remote.php/dav/files/' . $user[0] . '/', $target['davPath']);

		$upload = self::request('PUT', $target['davPath'], $content, $user, ['Content-Type: text/plain']);
		$this->assertContains($upload['status'], [201, 204], 'WebDAV upload: ' . $upload['body']);

		$entry = self::expect(201, self::api('POST', '/api/files', ['path' => $target['path']], $user), 'register');
		$this->assertSame('File', $entry['category']);
		$this->assertSame('ci-notiz.txt', $entry['title']);
		$this->assertSame('text/plain', $entry['fileMime']);
		$this->assertGreaterThan(0, $entry['fileId']);

		// Signed link from the entry's content. For a text file that is the
		// download link (file?download=1&amp;t=…), see FileEntryService::buildContent.
		$link = '~/api/articles/' . $entry['id'] . '/file\?(?:download=1&amp;)?t=([a-f0-9]+)~';
		$this->assertMatchesRegularExpression($link, $entry['content']);
		preg_match($link, $entry['content'], $m);
		$download = self::api('GET', "/api/articles/{$entry['id']}/file?t={$m[1]}&download=1", null, $user);
		$this->assertSame(200, $download['status']);
		$this->assertSame($content, $download['body']);

		// A wrong signature is refused, even for the owner.
		$this->assertGreaterThanOrEqual(400, self::api('GET', "/api/articles/{$entry['id']}/file?t=" . str_repeat('0', 40), null, $user)['status']);

		$renamed = self::expect(200, self::api('PUT', "/api/articles/{$entry['id']}/file-name", ['name' => 'umbenannt'], $user), 'rename');
		$this->assertSame('umbenannt.txt', $renamed['title']);

		$counts = self::expect(200, self::api('GET', '/api/articles/counts', null, $user));
		$this->assertSame(1, $counts['files']['total']);

		self::expect(404, self::api('POST', '/api/files', ['path' => '/gibt-es-nicht.txt'], $user));
	}
}
