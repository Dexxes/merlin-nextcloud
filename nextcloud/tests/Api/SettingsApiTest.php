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

	public function testRetentionNoticeSiteCredentialsAndReports(): void {
		$user = self::user();
		$info = self::expect(200, self::api('POST', '/api/retention/notice', null, $user));
		$this->assertFalse($info['retentionNoticeRequired']);

		$credentials = self::expect(200, self::api('GET', '/api/user/site-credentials', null, $user));
		$this->assertSame([], $credentials['credentials']);
		$this->assertIsArray($credentials['availableDomains']);
		self::expect(400, self::api('PUT', '/api/user/site-credentials/tagesspiegel.de', ['username' => 'ci'], $user));
		self::expect(200, self::api('DELETE', '/api/user/site-credentials/tagesspiegel.de', null, $user));

		$report = self::expect(400, self::api('POST', '/api/report-backend/report', ['url' => 'https://example.org/a'], $user));
		$this->assertSame(['status' => 'unconfigured'], $report);
	}

	public function testPageAndPwaEndpoints(): void {
		$user = self::user();
		$page = self::api('GET', '/', null, $user);
		$this->assertSame(200, $page['status']);
		$this->assertStringContainsString('merlin-main', $page['body'], 'page loads the built frontend (js/merlin-main.mjs)');

		$manifest = self::expect(200, self::api('GET', '/manifest.webmanifest', null, $user));
		$this->assertSame('Merlin', $manifest['name'] ?? $manifest['short_name'] ?? null);

		// Browsers fetch the manifest without cookies: no login needed (shared
		// core handler PwaApi since Etappe 6). start_url resolves against the
		// manifest URL, i.e. /index.php/apps/merlin/.
		$anonymous = self::api('GET', '/manifest.webmanifest');
		$this->assertSame(200, $anonymous['status']);
		$this->assertStringContainsString('application/manifest+json', $anonymous['headers']['content-type'] ?? '');
		$this->assertSame('./', $anonymous['json']['start_url'] ?? null);
		$this->assertStringEndsWith('/apps/merlin/img/icon-192.png', $anonymous['json']['icons'][0]['src'] ?? '');

		$sw = self::api('GET', '/sw.js', null, $user);
		$this->assertSame(200, $sw['status']);
		$this->assertStringContainsString('javascript', $sw['headers']['content-type'] ?? '');
		$this->assertStringContainsString('respondWith', $sw['body']);
	}
}
