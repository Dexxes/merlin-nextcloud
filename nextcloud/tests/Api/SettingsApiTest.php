<?php

declare(strict_types=1);

namespace OCA\Merlin\Tests\Api;

/**
 * Reader settings, retention (UserSettings, AppSettings, RetentionStore
 * ports), storage usage and the PWA endpoints.
 */
class SettingsApiTest extends ApiTestCase {
	public function testSettingsRoundTrip(): void {
		$user = self::user();
		$defaults = self::expect(200, self::api('GET', '/api/settings', null, $user));
		$this->assertSame('auto', $defaults['theme']);
		$this->assertSame(17, $defaults['fontSize']);

		$saved = self::expect(200, self::api('PUT', '/api/settings', [
			'theme'        => 'sepia',
			'fontSize'     => 21,
			'saveProgress' => false,
			'unknownKey'   => 'ignored',
		], $user));
		$this->assertTrue($saved['success']);
		$this->assertSame(21, $saved['settings']['fontSize']);
		$this->assertArrayNotHasKey('unknownKey', $saved['settings']);

		$reloaded = self::expect(200, self::api('GET', '/api/settings', null, $user));
		$this->assertSame('sepia', $reloaded['theme']);
		$this->assertSame(21, $reloaded['fontSize']);
		$this->assertFalse($reloaded['saveProgress']);
	}

	public function testRetentionAdminAndStorage(): void {
		$this->assertContains(self::api('GET', '/api/admin/retention', null, self::user())['status'], [403, 412]);
		self::expect(200, self::api('GET', '/api/admin/retention', null, self::admin()));
		self::expect(200, self::api('GET', '/api/admin/retention/preview', null, self::admin()));

		$user = self::user();
		self::saveArticle($user);
		$storage = self::expect(200, self::api('GET', '/api/storage', null, $user));
		$this->assertIsArray($storage);
	}

	public function testPageAndPwaEndpoints(): void {
		$user = self::user();
		$page = self::api('GET', '/', null, $user);
		$this->assertSame(200, $page['status']);
		$this->assertStringContainsString('merlin-main', $page['body'], 'page loads the built frontend (js/merlin-main.mjs)');

		$manifest = self::expect(200, self::api('GET', '/manifest.webmanifest', null, $user));
		$this->assertSame('Merlin', $manifest['name'] ?? $manifest['short_name'] ?? null);

		$sw = self::api('GET', '/sw.js', null, $user);
		$this->assertSame(200, $sw['status']);
		$this->assertStringContainsString('javascript', $sw['headers']['content-type'] ?? '');
	}
}
