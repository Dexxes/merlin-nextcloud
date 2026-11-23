<?php

declare(strict_types=1);

namespace OCA\Merlin\Tests\Api;

/**
 * Saving, reading, changing and deleting articles (ArticleController and
 * TagController on the shared core handlers, ExtensionController) and that
 * one user never sees another user's articles. The standalone server runs
 * the same flows in standalone/tests/test-article-api.php.
 */
class ArticleApiTest extends ApiTestCase {
	public function testSaveArticleLikeTheBrowserExtension(): void {
		$user = self::user();
		$article = self::saveArticle($user, 'Gespeichert per Erweiterung');

		$this->assertSame('Gespeichert per Erweiterung', $article['title']);
		$this->assertStringContainsString('Zweiter Absatz', (string) $article['content']);
		$this->assertSame('example.org', $article['siteName']);
		$this->assertFalse($article['isRead']);
		$this->assertFalse($article['isArchived']);
		$this->assertGreaterThanOrEqual(1, $article['readingTime']);

		$list = self::expect(200, self::api('GET', '/api/articles', null, $user));
		$this->assertContains($article['id'], array_column($list, 'id'));

		$counts = self::expect(200, self::api('GET', '/api/articles/counts', null, $user));
		$this->assertSame(1, $counts['pages']['total']);
		$this->assertSame(1, $counts['pages']['unread']);

		// Pocket-compatible list of the extension
		$get = self::expect(200, self::api('POST', '/api/v1/get', [], $user), 'POST /api/v1/get');
		$this->assertSame('0', $get['list'][(string) $article['id']]['favorite']);

		$sent = self::expect(200, self::api('POST', '/api/v1/send', ['actions' => [
			['action' => 'favorite', 'item_id' => (string) $article['id']],
			['action' => 'archive', 'item_id' => (string) $article['id']],
			['action' => 'archive', 'item_id' => '999999999'],
		]], $user), 'POST /api/v1/send');
		$this->assertSame([true, true, false], array_column($sent['action_results'], 'success'));
		$archived = self::expect(200, self::api('POST', '/api/v1/get', ['state' => 'archive'], $user));
		$this->assertSame('1', $archived['list'][(string) $article['id']]['favorite']);
		$this->assertSame('1', $archived['list'][(string) $article['id']]['status']);

		// The test user is shared within this class: leave no archived article behind.
		$deleted = self::expect(200, self::api('POST', '/api/v1/send', ['actions' => [
			['action' => 'delete', 'item_id' => (string) $article['id']],
		]], $user));
		$this->assertTrue($deleted['action_results'][0]['success']);
		self::expect(404, self::api('GET', "/api/articles/{$article['id']}", null, $user));
	}

	public function testToggleStateProgressAndExport(): void {
		$user = self::user();
		$id = self::saveArticle($user, 'Zustände')['id'];

		$this->assertSame(1, self::expect(200, self::api('PUT', "/api/articles/{$id}/read", null, $user))['isRead']);
		$this->assertNotEmpty(self::expect(200, self::api('PUT', "/api/articles/{$id}/favorite", null, $user))['isFavorite']);
		$progress = self::expect(200, self::api('PUT', "/api/articles/{$id}/progress", ['progress' => 0.42], $user));
		$this->assertEqualsWithDelta(0.42, $progress['scrollProgress'], 0.0001);

		$renamed = self::expect(200, self::api('PUT', "/api/articles/{$id}", ['title' => 'Umbenannt'], $user));
		$this->assertSame('Umbenannt', $renamed['title']);

		$found = self::expect(200, self::api('GET', '/api/articles/search?query=Umbenannt', null, $user));
		$this->assertSame([$id], array_column($found, 'id'));

		$archived = self::expect(200, self::api('PUT', "/api/articles/{$id}/archive", null, $user));
		$this->assertSame(1, $archived['isArchived']);
		$this->assertNotEmpty($archived['archivedAt']);
		$onlyArchived = self::expect(200, self::api('GET', '/api/articles?isArchived=true', null, $user));
		$this->assertSame([$id], array_column($onlyArchived, 'id'));

		$export = self::api('GET', "/api/articles/{$id}/export/html", null, $user);
		$this->assertSame(200, $export['status']);
		$this->assertStringStartsWith('text/html', $export['headers']['content-type'] ?? '');
		$this->assertStringContainsString('Zweiter Absatz', $export['body']);
	}

	public function testTagsAndTagFilter(): void {
		$user = self::user();
		$tagged = self::saveArticle($user, 'Mit Schlagwort')['id'];
		$other = self::saveArticle($user, 'Ohne Schlagwort')['id'];

		$parent = self::expect(201, self::api('POST', '/api/tags', ['name' => 'CI'], $user));
		$child = self::expect(201, self::api('POST', '/api/tags', ['name' => 'Unterschlagwort', 'parentId' => $parent['id']], $user));
		$this->assertSame($parent['id'], $child['parentId']);

		self::expect(200, self::api('POST', "/api/articles/{$tagged}/tags/{$child['id']}", null, $user));

		// Filtering by the parent tag includes articles of its sub-tags.
		$byParent = self::expect(200, self::api('GET', "/api/articles?tagId={$parent['id']}", null, $user));
		$this->assertSame([$tagged], array_column($byParent, 'id'));
		$this->assertNotContains($other, array_column($byParent, 'id'));

		self::expect(200, self::api('DELETE', "/api/articles/{$tagged}/tags/{$child['id']}", null, $user));
		$this->assertSame([], self::expect(200, self::api('GET', "/api/articles?tagId={$parent['id']}", null, $user)));

		$tags = self::expect(200, self::api('GET', '/api/tags', null, $user));
		$this->assertEqualsCanonicalizing(['CI', 'Unterschlagwort'], array_column($tags, 'name'));
	}

	public function testOtherUsersCannotSeeOrChangeTheArticle(): void {
		$owner = self::user();
		$stranger = self::user('-b');
		$id = self::saveArticle($owner, 'Privat')['id'];

		self::expect(404, self::api('GET', "/api/articles/{$id}", null, $stranger));
		self::expect(404, self::api('PUT', "/api/articles/{$id}", ['title' => 'Fremd'], $stranger));
		self::expect(404, self::api('DELETE', "/api/articles/{$id}", null, $stranger));
		$this->assertNotContains($id, array_column(self::expect(200, self::api('GET', '/api/articles', null, $stranger)), 'id'));
		$this->assertSame('Privat', self::expect(200, self::api('GET', "/api/articles/{$id}", null, $owner))['title']);

		self::expect(401, self::api('GET', '/api/articles'));
	}

	public function testDeleteArticle(): void {
		$user = self::user();
		$id = self::saveArticle($user, 'Wird gelöscht')['id'];
		self::expect(201, self::api('POST', "/api/articles/{$id}/comments", ['body' => 'hängt am Artikel'], $user));

		self::expect(200, self::api('DELETE', "/api/articles/{$id}", null, $user));
		self::expect(404, self::api('GET', "/api/articles/{$id}", null, $user));
		self::expect(404, self::api('GET', "/api/articles/{$id}/comments", null, $user));
	}

	public function testUnsupportedSiteIsRejected(): void {
		$user = self::user();
		// t3n.de is listed in content-filters/$unsupported.xml.
		$rejected = self::expect(422, self::api('POST', '/api/articles', [
			'url'               => 'https://t3n.de/news/merlin-ci-test/',
			'rejectUnsupported' => true,
		], $user));
		$this->assertSame('unsupported_site', $rejected['error']);
		$this->assertSame('t3n.de', $rejected['domain']);

		$extension = self::expect(422, self::api('POST', '/api/v1/add', ['url' => 'https://t3n.de/news/merlin-ci-test/'], $user));
		$this->assertSame('unsupported_site', $extension['error']);
	}

	public function testCapabilitiesWithoutLogin(): void {
		$caps = self::expect(200, self::api('GET', '/api/capabilities'));
		$this->assertSame('nextcloud', $caps['backend']);
		$this->assertSame(1, $caps['apiVersion']);
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) $caps['version']);
		foreach (['articles', 'tags', 'nestedTags', 'files', 'comments', 'pwa'] as $feature) {
			$this->assertContains($feature, $caps['features']);
		}
	}

	public function testCreateWithTagsUnsupportedSiteAndRetry(): void {
		$user = self::user();
		$tag = self::expect(201, self::api('POST', '/api/tags', ['name' => 'Beim Speichern'], $user));
		$foreign = self::expect(201, self::api('POST', '/api/tags', ['name' => 'Fremd'], self::user('-b')));

		// t3n.de is in $unsupported.xml: without rejectUnsupported the article
		// is stored and the background extraction marks it (no network).
		$placeholder = self::expect(202, self::api('POST', '/api/articles', [
			'url'    => 'https://t3n.de/news/merlin-ci-placeholder/',
			'tagIds' => [$tag['id'], $foreign['id']],
		], $user));
		$this->assertTrue($placeholder['isProcessing']);
		$this->assertSame('t3n.de', $placeholder['title']);
		$this->assertSame([], $placeholder['tags']);
		$this->assertStringEndsWith('/img/no-img.png', $placeholder['imageUrl']);

		$article = self::waitForExtraction($user, $placeholder['id']);
		$this->assertSame('', $article['content']);
		$this->assertSame('t3n.de', $article['unsupportedSiteDomain']);
		$this->assertSame(['Beim Speichern'], array_column($article['tags'], 'name'), 'only the own tag is linked');
		$this->assertArrayHasKey('supportBox', $article);

		$retried = self::expect(200, self::api('POST', "/api/articles/{$placeholder['id']}/retry-extraction", null, $user));
		$this->assertSame($placeholder['id'], $retried['id']);
		self::waitForExtraction($user, $placeholder['id']);

		self::expect(400, self::api('POST', '/api/articles', [], $user), 'POST /api/articles without url');
	}

	public function testTagRulesProgressAndEvents(): void {
		$user = self::user();
		$id = self::saveArticle($user, 'Schlagwort-Regeln')['id'];

		$parent = self::expect(201, self::api('POST', '/api/tags', ['name' => 'Oben'], $user));
		$this->assertSame('#0082c9', $parent['color']);
		$this->assertNull($parent['parentId']);
		$child = self::expect(201, self::api('POST', '/api/tags', ['name' => 'Unten', 'parentId' => $parent['id']], $user));
		self::expect(400, self::api('POST', '/api/tags', ['name' => 'Oben'], $user), 'duplicate tag name');
		self::expect(400, self::api('PUT', "/api/tags/{$parent['id']}", ['parentId' => $child['id']], $user), 'move below own sub-tag');
		$moved = self::expect(200, self::api('PUT', "/api/tags/{$child['id']}", ['parentId' => 0, 'color' => '#ff0000'], $user));
		$this->assertNull($moved['parentId']);
		$this->assertSame('#ff0000', $moved['color']);
		self::expect(200, self::api('POST', "/api/articles/{$id}/tags/{$child['id']}", null, $user));
		self::expect(400, self::api('POST', "/api/articles/{$id}/tags/{$child['id']}", null, $user), 'link twice');
		$deleted = self::expect(200, self::api('DELETE', "/api/tags/{$child['id']}", null, $user));
		$this->assertSame([$child['id']], $deleted['deletedIds']);
		$this->assertSame([], self::expect(200, self::api('GET', "/api/articles/{$id}", null, $user))['tags']);

		$progress = self::expect(200, self::api('PUT', "/api/articles/{$id}/progress", ['progress' => 1.5, 'updatedAt' => 1767225600000], $user));
		$this->assertEqualsWithDelta(1.0, $progress['scrollProgress'], 0.0001);
		$this->assertSame(1767225600000, $progress['scrollUpdatedAt']);
		$article = self::expect(200, self::api('GET', "/api/articles/{$id}", null, $user));
		$this->assertStringContainsString('Zweiter Absatz', $article['content'], 'saving the progress keeps the content');

		$events = self::api('GET', '/api/events', null, $user);
		$this->assertSame(200, $events['status']);
		$this->assertStringStartsWith('text/event-stream', $events['headers']['content-type'] ?? '');
		$this->assertStringContainsString('"type":"idle"', $events['body']);
	}
}
