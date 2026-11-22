<?php

declare(strict_types=1);

namespace OCA\Merlin\Tests\Api;

/**
 * Highlights and comments of the owner (HighlightController,
 * CommentController; behind the HighlightStore and CommentStore ports).
 */
class CommentApiTest extends ApiTestCase {
	public function testHighlightWithCommentThread(): void {
		$user = self::user();
		$id = self::saveArticle($user)['id'];

		$highlight = self::expect(201, self::api('POST', "/api/articles/{$id}/highlights", [
			'highlightedText' => 'Dies ist ein Absatz',
			'startXpath'      => 'p[1]',
			'startOffset'     => 0,
			'endXpath'        => 'p[1]',
			'endOffset'       => 19,
			'color'           => 'yellow',
		], $user), 'create highlight');
		$this->assertSame('owner', $highlight['authorType']);

		$comment = self::expect(201, self::api('POST', "/api/articles/{$id}/comments", [
			'body'        => 'Kommentar an der Markierung',
			'highlightId' => $highlight['id'],
		], $user), 'comment on highlight');
		$this->assertSame($highlight['id'], $comment['highlightId']);
		$this->assertSame('Dies ist ein Absatz', $comment['quotedText']);

		$reply = self::expect(201, self::api('POST', "/api/articles/{$id}/comments", [
			'body'     => 'Antwort',
			'parentId' => $comment['id'],
		], $user), 'reply');
		$this->assertSame($comment['id'], $reply['parentId']);

		$edited = self::expect(200, self::api('PUT', "/api/comments/{$comment['id']}", ['body' => 'Geändert'], $user));
		$this->assertSame('Geändert', $edited['body']);
		$this->assertTrue($edited['edited']);

		$highlights = self::expect(200, self::api('GET', "/api/articles/{$id}/highlights", null, $user));
		$this->assertSame([$highlight['id']], array_column($highlights, 'id'));

		$recolored = self::expect(200, self::api('PUT', "/api/highlights/{$highlight['id']}", [
			'highlightedText' => 'Dies ist ein Absatz',
			'startXpath'      => 'p[1]',
			'startOffset'     => 0,
			'endXpath'        => 'p[1]',
			'endOffset'       => 19,
			'color'           => 'green',
		], $user), 'recolor highlight');
		$this->assertSame('green', $recolored['color'] ?? $recolored['highlight']['color'] ?? null);

		self::expect(204, self::api('DELETE', "/api/comments/{$reply['id']}", null, $user));
		$bodies = array_column(self::commentsOf($user, $id), 'body', 'id');
		$this->assertArrayHasKey($comment['id'], $bodies);
		$this->assertSame('Geändert', $bodies[$comment['id']]);
	}

	public function testArticleCommentAndValidation(): void {
		$user = self::user();
		$id = self::saveArticle($user)['id'];

		$comment = self::expect(201, self::api('POST', "/api/articles/{$id}/comments", ['body' => 'Am ganzen Artikel'], $user));
		$this->assertNull($comment['highlightId']);

		self::expect(400, self::api('POST', "/api/articles/{$id}/highlights", [
			'highlightedText' => 'x',
			'startXpath'      => '/html/body',
			'endXpath'        => '/html/body',
		], $user), 'invalid xpath');
		$this->assertGreaterThanOrEqual(400, self::api('POST', "/api/articles/{$id}/comments", ['body' => ''], $user)['status']);

		$stranger = self::user('-b');
		self::expect(404, self::api('GET', "/api/articles/{$id}/comments", null, $stranger));
		self::expect(404, self::api('POST', "/api/articles/{$id}/comments", ['body' => 'fremd'], $stranger));
	}

	/** @return list<array<string, mixed>> */
	private static function commentsOf(array $user, int $articleId): array {
		$json = self::expect(200, self::api('GET', "/api/articles/{$articleId}/comments", null, $user));
		return $json['comments'] ?? $json;
	}
}
