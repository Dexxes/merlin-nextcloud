<?php

declare(strict_types=1);

namespace OCA\Merlin\Tests\Api;

/**
 * Saving content filters (UserContentFilterController for every user,
 * ContentFilterController for admins; behind the ContentFilterStore and
 * ContentFilterSource ports) and that the saved filter is applied when an
 * article of that domain is saved.
 */
class ContentFilterApiTest extends ApiTestCase {
	private const DOMAIN = 'merlin-ci.example.org';

	private static function filterXml(string $removeClass): string {
		$domain = self::DOMAIN;
		return <<<XML
			<?xml version="1.0" encoding="UTF-8"?>
			<domain name="{$domain}">
			  <post-filter>
			    <remove class="{$removeClass}" />
			  </post-filter>
			</domain>
			XML;
	}

	public function testUserFilterIsSavedAndApplied(): void {
		$user = self::user();
		$domain = self::DOMAIN;

		$saved = self::expect(200, self::api('PUT', "/api/user/content-filters/{$domain}", [
			'xml' => self::filterXml('ci-remove-me'),
		], $user), 'save user filter');
		$this->assertIsArray($saved);
		$this->assertStringContainsString('ci-remove-me', json_encode($saved, JSON_THROW_ON_ERROR));

		$shown = self::expect(200, self::api('GET', "/api/user/content-filters/{$domain}", null, $user));
		$this->assertStringContainsString('ci-remove-me', json_encode($shown, JSON_THROW_ON_ERROR));

		$index = self::expect(200, self::api('GET', '/api/user/content-filters', null, $user));
		$this->assertStringContainsString($domain, json_encode($index, JSON_THROW_ON_ERROR));

		// The filter is applied when saving an article of the domain.
		$html = '<html><head><title>Gefiltert</title></head><body><article><h1>Gefiltert</h1>'
			. '<p>Erster Absatz mit ausreichend Text, damit Readability den Artikel als Inhalt erkennt und nicht verwirft.</p>'
			. '<p class="ci-remove-me">WERBUNG-DIE-DER-FILTER-ENTFERNT mit ausreichend Text, damit der Absatz sonst erhalten bliebe.</p>'
			. '<p>Dritter Absatz mit ausreichend Text, damit Readability den Artikel als Inhalt erkennt und nicht verwirft.</p>'
			. '</article></body></html>';
		$added = self::expect(201, self::api('POST', '/api/v1/add', [
			'url'   => "https://{$domain}/artikel",
			'title' => 'Gefiltert',
			'html'  => $html,
		], $user));
		$article = self::waitForExtraction($user, (int) $added['item']['item_id']);
		$this->assertStringContainsString('Dritter Absatz', $article['content']);
		$this->assertStringNotContainsString('WERBUNG-DIE-DER-FILTER-ENTFERNT', $article['content']);

		// Not visible for anyone else.
		$other = self::api('GET', "/api/user/content-filters/{$domain}", null, self::user('-b'));
		$this->assertStringNotContainsString('ci-remove-me', $other['body']);

		self::expect(200, self::api('DELETE', "/api/user/content-filters/{$domain}", null, $user));
		$this->assertStringNotContainsString('ci-remove-me', self::api('GET', "/api/user/content-filters/{$domain}", null, $user)['body']);
	}

	public function testInvalidFilterIsRejected(): void {
		$user = self::user();
		$domain = self::DOMAIN;
		self::expect(400, self::api('PUT', "/api/user/content-filters/{$domain}", ['xml' => '<domain name="other.example"><nonsense/></domain>'], $user));
		self::expect(400, self::api('PUT', "/api/user/content-filters/{$domain}", [], $user));
		$this->assertContains(self::api('PUT', '/api/user/content-filters/UNG%C3%9CLTIG', ['xml' => self::filterXml('x')], $user)['status'], [400, 404]);
	}

	public function testAdminFilterNeedsAdmin(): void {
		$domain = self::DOMAIN;
		$this->assertContains(self::api('GET', '/api/admin/content-filters', null, self::user())['status'], [403, 412]);

		$admin = self::admin();
		$list = self::expect(200, self::api('GET', '/api/admin/content-filters', null, $admin), 'admin filter list');
		// Bundled filters from content-filters/ are found in the installed app.
		$this->assertStringContainsString('spiegel.de', json_encode($list, JSON_THROW_ON_ERROR));

		self::expect(200, self::api('PUT', "/api/admin/content-filters/{$domain}", ['xml' => self::filterXml('ci-admin')], $admin), 'save admin filter');
		$shown = self::api('GET', "/api/admin/content-filters/{$domain}", null, $admin);
		self::expect(200, $shown);
		$this->assertStringContainsString('ci-admin', $shown['body']);
		$export = self::api('GET', "/api/admin/content-filters/{$domain}/export", null, $admin);
		$this->assertSame(200, $export['status']);
		$this->assertStringContainsString('ci-admin', $export['body']);
		self::expect(200, self::api('DELETE', "/api/admin/content-filters/{$domain}", null, $admin));
	}
}
