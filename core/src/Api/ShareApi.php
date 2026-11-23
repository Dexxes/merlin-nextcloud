<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Domain\ArticleShare;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\ShareStore;
use Merlin\Core\Port\UrlGenerator;

/**
 * Öffentliche Share-Links eines Artikels verwalten (anlegen, Passwort,
 * Ablauf und Kommentar-Schalter ändern, Token erneuern, widerrufen),
 * Antworten wie bisher in OCA\Merlin\Controller\ShareController. Genau ein
 * Link je Artikel: Erneuern tauscht nur den Token. Die öffentliche Ansicht
 * selbst liefert PublicShareApi.
 */
final class ShareApi {
	public function __construct(
		private ShareStore $shares,
		private ArticleStore $articles,
		private UrlGenerator $urls,
	) {
	}

	/** GET /api/articles/{articleId}/share: { enabled, … } */
	public function show(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$articleId = (int) $request->int('articleId');
		if ($this->articles->find($articleId, $userId) === null) {
			return ApiResult::error('Article not found', 404);
		}
		$share = $this->shares->findByArticleId($articleId, $userId);
		return ApiResult::json($share === null ? ['enabled' => false] : $this->payload($share));
	}

	/**
	 * POST /api/articles/{articleId}/share: password, expiresAt (ISO 8601),
	 * allowComments. Idempotent: ein vorhandener Link kommt unverändert zurück.
	 */
	public function create(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$articleId = (int) $request->int('articleId');
		if ($this->articles->find($articleId, $userId) === null) {
			return ApiResult::error('Article not found', 404);
		}
		$existing = $this->shares->findByArticleId($articleId, $userId);
		if ($existing !== null) {
			return ApiResult::json($this->payload($existing));
		}

		$password = $request->string('password');
		$now = new \DateTime();
		$share = new ArticleShare();
		$share->setUserId($userId);
		$share->setArticleId($articleId);
		$share->setToken(self::newToken());
		$share->setPasswordHash($password !== null && $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null);
		$share->setExpiresAt(self::parseExpiresAt($request->string('expiresAt')));
		$share->setAllowComments((bool) $request->bool('allowComments', true));
		$share->setCreatedAt($now);
		$share->setUpdatedAt($now);

		return ApiResult::json($this->payload($this->shares->insert($share)), 201);
	}

	/**
	 * PUT /api/articles/{articleId}/share: password, expiresAt, allowComments.
	 * Ein fehlendes Feld bleibt unverändert; null oder '' entfernt Passwort
	 * bzw. Ablaufdatum.
	 */
	public function update(ApiRequest $request): ApiResult {
		$share = $this->shares->findByArticleId((int) $request->int('articleId'), $request->userId());
		if ($share === null) {
			return ApiResult::error('Share not found', 404);
		}

		$unset = new \stdClass();
		$password = $request->param('password', $unset);
		if ($password !== $unset) {
			$share->setPasswordHash($password !== null && $password !== '' ? password_hash((string) $password, PASSWORD_DEFAULT) : null);
		}
		$expiresAt = $request->param('expiresAt', $unset);
		if ($expiresAt !== $unset) {
			$share->setExpiresAt(self::parseExpiresAt($expiresAt !== null ? (string) $expiresAt : null));
		}
		// Dürfen Gäste markieren und kommentieren? Lesen geht immer.
		$allowComments = $request->param('allowComments', $unset);
		if ($allowComments !== $unset) {
			$share->setAllowComments(filter_var($allowComments, FILTER_VALIDATE_BOOLEAN));
		}
		$share->setUpdatedAt(new \DateTime());

		return ApiResult::json($this->payload($this->shares->update($share)));
	}

	/**
	 * POST /api/articles/{articleId}/share/regenerate: neuer Token, der alte
	 * Link gilt sofort nicht mehr; Passwort und Ablauf bleiben. Eigene Route,
	 * damit das nicht versehentlich per PUT passiert.
	 */
	public function regenerate(ApiRequest $request): ApiResult {
		$share = $this->shares->findByArticleId((int) $request->int('articleId'), $request->userId());
		if ($share === null) {
			return ApiResult::error('Share not found', 404);
		}
		$share->setToken(self::newToken());
		$share->setUpdatedAt(new \DateTime());
		return ApiResult::json($this->payload($this->shares->update($share)));
	}

	/** DELETE /api/articles/{articleId}/share: Link widerrufen, danach 404. */
	public function destroy(ApiRequest $request): ApiResult {
		$this->shares->deleteByArticleId((int) $request->int('articleId'), $request->userId());
		return ApiResult::noContent();
	}

	/** @return array<string, mixed> */
	private function payload(ArticleShare $share): array {
		return ['enabled' => true] + $share->jsonSerialize() + ['url' => $this->urls->shareUrl($share->getToken())];
	}

	private static function newToken(): string {
		return bin2hex(random_bytes(16));
	}

	/** ISO 8601, leer oder null = kein Ablauf, Unlesbares ebenso. */
	private static function parseExpiresAt(?string $expiresAt): ?\DateTime {
		if ($expiresAt === null || $expiresAt === '') {
			return null;
		}
		try {
			return new \DateTime($expiresAt);
		} catch (\Exception) {
			return null;
		}
	}
}
