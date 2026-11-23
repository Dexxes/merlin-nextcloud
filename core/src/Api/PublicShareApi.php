<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Domain\Article;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\ShareStore;
use Merlin\Core\Port\Throttler;
use Merlin\Core\Service\CommentService;
use Merlin\Core\Service\ShareAccessDenied;
use Merlin\Core\Service\ShareAccessService;
use Merlin\Core\Service\SupportBoxService;

/**
 * Öffentliche Ansicht eines geteilten Artikels, ohne Anmeldung (/s/{token}/…),
 * Antworten wie bisher in OCA\Merlin\Controller\PublicShareController. Der
 * Token aus der URL ist die einzige Berechtigung: alles läuft über
 * ShareStore::findByToken(), nie über eine vom Client geschickte Artikel- oder
 * Nutzer-ID.
 *
 * Das Passwort-Entsperren merkt sich die Session (ShareAccessService),
 * Fehlversuche bremst der Throttler. Markieren und Kommentieren hinter dem
 * Link: PublicCommentApi.
 */
final class PublicShareApi {
	public const THROTTLE_ACTION = 'merlin_public_share_unlock';

	public function __construct(
		private ShareStore $shares,
		private ArticleStore $articles,
		private ShareAccessService $access,
		private CommentService $comments,
		private SupportBoxService $supportBox,
		private Throttler $throttler,
	) {
	}

	/** POST /s/{token}/unlock: password. Merkt das Entsperren in der Session. */
	public function unlock(ApiRequest $request): ApiResult {
		$token = (string) $request->string('token', '');
		$share = $this->shares->findByToken($token);
		if ($share === null) {
			return ApiResult::error('Not found', 404);
		}
		if ($share->isExpired()) {
			return ApiResult::error('Expired', 410);
		}
		if (!$share->hasPassword()) {
			return ApiResult::json(['unlocked' => true]);
		}

		// Die Wartezeit wächst mit den Fehlversuchen dieser IP.
		$address = $request->remoteAddress();
		$this->throttler->sleepDelay(self::THROTTLE_ACTION, $address);
		if (!password_verify((string) $request->string('password', ''), (string) $share->getPasswordHash())) {
			$this->throttler->registerFailure(self::THROTTLE_ACTION, $address, ['token' => $token]);
			return ApiResult::error('Invalid password', 403);
		}
		$this->throttler->reset(self::THROTTLE_ACTION, $address, ['token' => $token]);
		$this->access->markUnlocked($share);

		return ApiResult::json(['unlocked' => true]);
	}

	/** GET /s/{token}/data: Artikel, Markierungen und Threads für die öffentliche Ansicht. */
	public function data(ApiRequest $request): ApiResult {
		$share = $this->access->resolveAccessibleShare((string) $request->string('token', ''));
		if ($share instanceof ShareAccessDenied) {
			return ApiResult::json($share->getData(), $share->getStatus());
		}
		$article = $this->articles->find($share->getArticleId(), $share->getUserId());
		if ($article === null) {
			// Artikel gelöscht, Link (noch) nicht aufgeräumt.
			return ApiResult::error('Not found', 404);
		}

		// Markierungen, Threads und Änderungsmarke für den Push-Kanal
		// (PublicCommentApi::events) in einem Rutsch.
		$discussion = $this->comments->payload((int) $article->getId(), $share->getUserId());

		return ApiResult::json([
			'title'       => $article->getTitle(),
			'excerpt'     => $article->getExcerpt(),
			'author'      => $article->getAuthor(),
			'authorUrl'   => $article->getAuthorUrl(),
			'authors'     => Article::decodeAuthors($article->getAuthors()),
			'siteName'    => $article->getSiteName(),
			'content'     => $article->getContent(),
			'url'         => $article->getUrl(),
			'category'    => $article->getCategory(),
			'publishedAt' => $article->getPublishedAt()?->format('c'),
			'readingTime' => $article->getReadingTime(),
			'highlights'  => $discussion['highlights'],
			'comments'    => $discussion['comments'],
			'signature'   => $discussion['signature'],
			'generatedAt' => $discussion['generatedAt'],
			'guests'      => $discussion['guests'],
			'guestColors' => $discussion['guestColors'],
			'allowComments' => $share->allowsComments(),
			'ownerName'   => $this->comments->ownerDisplayName($share->getUserId()),
			// Akzentfarbe des Erstellers für die Dachzeile, wie im Reader.
			'accentColor' => $this->supportBox->accentColor($share->getUserId()),
			// Abo-/Spendenlink der Quelle; anders als im Reader immer, auch wenn
			// der Ersteller ein Abo hat (Empfänger sind keine Abonnenten).
			'supportBox'  => $this->supportBox->forShare($article->toSnapshot(), $share->getUserId()),
		]);
	}
}
