<?php

declare(strict_types=1);

namespace OCA\Merlin\Tests\Api;

/**
 * Saving, reading, changing and deleting articles (ArticleController,
 * ExtensionController, TagController) and that one user never sees
 * another user's articles.
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
		$this->assertIsArray($get);
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
}
