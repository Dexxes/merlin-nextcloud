<?php

declare(strict_types=1);

namespace OCA\Merlin\Tests\Api;

/**
 * Public share links (ShareController, PublicShareController,
 * PublicCommentController; behind the ShareStore, Session and
 * CommentGuestStore ports): create, open anonymously, comment as a guest,
 * protect with a password, revoke.
 */
class ShareApiTest extends ApiTestCase {
	private ?string $cookieJar = null;

	protected function tearDown(): void {
		if ($this->cookieJar !== null && is_file($this->cookieJar)) {
			unlink($this->cookieJar);
		}
		$this->cookieJar = null;
	}

	public function testShareLinkWithGuestComment(): void {
		$owner = self::user();
		$id = self::saveArticle($owner, 'Geteilter Artikel')['id'];

		self::expect(200, self::api('GET', "/api/articles/{$id}/share", null, $owner));
		$share = self::expect(201, self::api('POST', "/api/articles/{$id}/share", ['allowComments' => true], $owner), 'create share');
		$this->assertTrue($share['enabled']);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{16,}$/', $share['token']);
		$this->assertFalse($share['hasPassword']);
		$this->assertStringContainsString('/apps/merlin/s/' . $share['token'], $share['url']);
		$token = $share['token'];

		// Creating again returns the existing link.
		$this->assertSame($token, self::expect(200, self::api('POST', "/api/articles/{$id}/share", [], $owner))['token']);

		$page = self::anonymous('GET', "/s/{$token}");
		$this->assertSame(200, $page['status'], 'public share page');
		$this->assertStringContainsString('<html', strtolower($page['body']));

		$data = self::expect(200, self::anonymous('GET', "/s/{$token}/data"), 'public share data');
		$this->assertSame('Geteilter Artikel', $data['title']);
		$this->assertStringContainsString('Zweiter Absatz', $data['content']);
		$this->assertTrue($data['allowComments']);
		$this->assertSame($owner[0], $data['ownerName']);

		$guest = self::expect(200, self::anonymous('POST', "/s/{$token}/guest", ['authorName' => 'Gast CI']), 'guest');
		$this->assertSame('Gast CI', $guest['name']);
		$this->assertNotEmpty($guest['color']);

		$comment = self::expect(201, self::anonymous('POST', "/s/{$token}/comments", [
			'authorName' => 'Gast CI',
			'body'       => 'Kommentar eines Gasts',
		]), 'guest comment');
		$this->assertSame('guest', $comment['authorType']);
		$this->assertSame('Gast CI', $comment['authorName']);

		$public = self::expect(200, self::anonymous('GET', "/s/{$token}/comments"));
		$this->assertContains('Kommentar eines Gasts', array_column($public['comments'] ?? $public, 'body'));

		$ownerView = self::expect(200, self::api('GET', "/api/articles/{$id}/comments", null, $owner));
		$this->assertContains('Kommentar eines Gasts', array_column($ownerView['comments'] ?? $ownerView, 'body'));

		// Comments switched off: guests can read, not write.
		self::expect(200, self::api('PUT', "/api/articles/{$id}/share", ['allowComments' => false], $owner));
		$this->assertFalse(self::expect(200, self::anonymous('GET', "/s/{$token}/data"))['allowComments']);
		$this->assertGreaterThanOrEqual(400, self::anonymous('POST', "/s/{$token}/comments", [
			'authorName' => 'Gast CI',
			'body'       => 'nicht erlaubt',
		])['status']);

		// Revoked: the link is gone.
		self::expect(204, self::api('DELETE', "/api/articles/{$id}/share", null, $owner));
		self::expect(404, self::anonymous('GET', "/s/{$token}/data"));
	}

	public function testPasswordProtectedShare(): void {
		$owner = self::user();
		$id = self::saveArticle($owner, 'Mit Passwort')['id'];

		$share = self::expect(201, self::api('POST', "/api/articles/{$id}/share", ['password' => 'geheim-123'], $owner));
		$this->assertTrue($share['hasPassword']);
		$token = $share['token'];

		$locked = self::expect(401, self::anonymous('GET', "/s/{$token}/data"));
		$this->assertTrue($locked['locked']);

		$this->assertGreaterThanOrEqual(400, self::anonymous('POST', "/s/{$token}/unlock", ['password' => 'falsch'])['status']);
		self::expect(401, self::anonymous('GET', "/s/{$token}/data"));

		self::expect(200, self::anonymous('POST', "/s/{$token}/unlock", ['password' => 'geheim-123']), 'unlock');
		$this->assertSame('Mit Passwort', self::expect(200, self::anonymous('GET', "/s/{$token}/data"))['title']);

		// A new token invalidates the old link.
		$new = self::expect(200, self::api('POST', "/api/articles/{$id}/share/regenerate", null, $owner));
		$this->assertNotSame($token, $new['token']);
		self::expect(404, self::anonymous('GET', "/s/{$token}/data"));
	}

	public function testGuestEditsOnlyUnderTheirName(): void {
		$owner = self::user();
		$id = self::saveArticle($owner, 'Gast-Rechte')['id'];
		$token = self::expect(201, self::api('POST', "/api/articles/{$id}/share", [], $owner))['token'];

		$comment = self::expect(201, self::anonymous('POST', "/s/{$token}/comments", ['authorName' => 'Gast Zwei', 'body' => 'Erster Text']));
		self::expect(403, self::anonymous('PUT', "/s/{$token}/comments/{$comment['id']}", ['body' => 'fremd'], ['X-Merlin-Guest-Name: ' . rawurlencode('Jemand')]));
		$edited = self::expect(200, self::anonymous('PUT', "/s/{$token}/comments/{$comment['id']}", ['body' => 'Selbst geändert'], ['X-Merlin-Guest-Name: ' . rawurlencode('Gast Zwei')]));
		$this->assertSame('Selbst geändert', $edited['body']);

		// Honeypot: ein ausgefülltes Feld website kommt von keinem Menschen.
		self::expect(400, self::anonymous('POST', "/s/{$token}/comments", ['authorName' => 'Gast Zwei', 'body' => 'Spam', 'website' => 'https://spam.example']));

		$highlight = self::expect(201, self::anonymous('POST', "/s/{$token}/highlights", [
			'authorName' => 'Gast Zwei', 'highlightedText' => 'Zweiter Absatz', 'startXpath' => 'p[1]', 'startOffset' => 0, 'endXpath' => 'p[1]', 'endOffset' => 14,
		]));
		$this->assertSame('guest', $highlight['authorType']);
		self::expect(403, self::anonymous('DELETE', "/s/{$token}/highlights/{$highlight['id']}", null, ['X-Merlin-Guest-Name: ' . rawurlencode('Jemand')]));
		self::expect(204, self::anonymous('DELETE', "/s/{$token}/highlights/{$highlight['id']}", null, ['X-Merlin-Guest-Name: ' . rawurlencode('Gast Zwei')]));

		// Der Besitzer moderiert: er löscht auch Gast-Beiträge, ändert sie aber nicht.
		self::expect(403, self::api('PUT', "/api/comments/{$comment['id']}", ['body' => 'Besitzer'], $owner));
		self::expect(204, self::api('DELETE', "/api/comments/{$comment['id']}", null, $owner));
	}

	public function testOnlyTheOwnerManagesTheShare(): void {
		$owner = self::user();
		$id = self::saveArticle($owner)['id'];
		self::expect(404, self::api('POST', "/api/articles/{$id}/share", [], self::user('-b')));
		self::expect(404, self::anonymous('GET', '/s/' . str_repeat('0', 32) . '/data'));
	}

	/** Anonymous request with a cookie session that lasts for the test. */
	private function anonymous(string $method, string $path, ?array $body = null, array $headers = []): array {
		$this->cookieJar ??= (string) tempnam(sys_get_temp_dir(), 'merlin-ci-cookies');
		return self::request($method, '/index.php/apps/merlin' . $path, $body, null, $headers, $this->cookieJar);
	}
}
