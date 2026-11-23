<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Domain\Article;
use Merlin\Core\Port\ArticleFiles;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Service\PdfProxyService;
use Merlin\Core\Service\ShareAccessDenied;
use Merlin\Core\Service\ShareAccessService;
use Merlin\Core\Service\TtsStreamService;

/**
 * Vorlesen (MP3 vom Piper-Daemon) und PDF-Durchreichung, für den Besitzer
 * (/api/articles/{id}/tts, /pdf) und hinter einem Share-Link (/s/{token}/tts,
 * /pdf). Antworten wie bisher in TtsController, PdfController und
 * PublicShareController. Die sicherheitskritische Proxy-Logik steht in
 * TtsStreamService und PdfProxyService; beide schreiben die Antwort selbst
 * (ApiResult::passthrough).
 *
 * Hinter dem Link ist der Token die einzige Berechtigung (ShareAccessService,
 * samt Passwort-Entsperren in der Session), nie eine Artikel- oder Nutzer-ID
 * vom Client.
 */
final class ArticleStreamApi {
	public function __construct(
		private ArticleStore $articles,
		private ShareAccessService $access,
		private TtsStreamService $tts,
		private PdfProxyService $pdf,
		private ArticleFiles $files,
	) {
	}

	/** GET /api/articles/{id}/tts: lang (de, en, es, fr, it), speaker. */
	public function synthesize(ApiRequest $request): ApiResult {
		$article = $this->articles->find((int) $request->int('id'), $request->userId());
		return $article === null ? self::articleNotFound() : $this->speak($article, $request);
	}

	/** GET /api/articles/{id}/pdf: PDF des Artikels (Quellserver oder Merlin Dateien). */
	public function show(ApiRequest $request): ApiResult {
		$article = $this->articles->find((int) $request->int('id'), $request->userId());
		return $article === null ? self::articleNotFound() : $this->servePdf($article);
	}

	/** GET /s/{token}/tts: wie synthesize() für den geteilten Artikel. */
	public function tts(ApiRequest $request): ApiResult {
		$article = $this->sharedArticle($request);
		return $article instanceof ApiResult ? $article : $this->speak($article, $request);
	}

	/** GET /s/{token}/pdf: wie show() für den geteilten Artikel. */
	public function pdf(ApiRequest $request): ApiResult {
		$article = $this->sharedArticle($request);
		return $article instanceof ApiResult ? $article : $this->servePdf($article);
	}

	private function speak(Article $article, ApiRequest $request): ApiResult {
		$lang = (string) $request->string('lang', 'de');
		$speaker = (int) $request->int('speaker', -1);
		$snapshot = $article->toSnapshot();
		return ApiResult::passthrough(fn () => $this->tts->stream($snapshot, $lang, $speaker));
	}

	private function servePdf(Article $article): ApiResult {
		if ($article->getFileId() !== null) {
			// PDF aus „Merlin Dateien“: direkt aus der Ablage statt über den Proxy.
			return ApiResult::passthrough(fn () => $this->files->stream($article));
		}
		$snapshot = $article->toSnapshot();
		return ApiResult::passthrough(fn () => $this->pdf->stream($snapshot));
	}

	private function sharedArticle(ApiRequest $request): Article|ApiResult {
		$share = $this->access->resolveAccessibleShare((string) $request->string('token', ''));
		if ($share instanceof ShareAccessDenied) {
			return ApiResult::json($share->getData(), $share->getStatus());
		}
		return $this->articles->find($share->getArticleId(), $share->getUserId()) ?? self::articleNotFound();
	}

	private static function articleNotFound(): ApiResult {
		return ApiResult::error('Article not found', 404);
	}
}
