<?php

declare(strict_types=1);

namespace Merlin\Core\Service;

use Merlin\Core\Domain\Article;
use Merlin\Core\Extraction\ContentExtractorService;
use Merlin\Core\Extraction\Login\PaywallLoginRequiredException;
use Merlin\Core\Extraction\UnsupportedSiteException;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\Scheduler;

/**
 * Inhalt eines gespeicherten Artikels nach der Antwort holen (POST
 * /api/articles und „Erneut versuchen“). Am Ende ist is_processing immer 0:
 * mit Inhalt, mit requiresLoginDomain (Paywall ohne gültige Zugangsdaten),
 * mit unsupportedSiteDomain ($unsupported.xml) oder ohne Inhalt bei einem
 * Fehler.
 */
final class ArticleExtractionService {
	public function __construct(
		private ContentExtractorService $extractor,
		private ArticleStore $articles,
		private Scheduler $scheduler,
	) {
	}

	public function schedule(int $articleId, string $url, string $userId): void {
		$this->scheduler->afterResponse(fn () => $this->run($articleId, $url, $userId));
	}

	public function run(int $articleId, string $url, string $userId): void {
		try {
			$extracted = $this->extractor->extract($url, null, $userId);
			$article = $this->articles->find($articleId, $userId);
			if ($article === null) {
				return; // inzwischen gelöscht
			}
			$article->setUrl($extracted['url'] ?? $url);
			$article->setTitle((string) $extracted['title']);
			$article->setContent((string) $extracted['content']);
			$article->setExcerpt($extracted['excerpt']);
			$article->setAuthor($extracted['author']);
			$article->setAuthorUrl($extracted['authorUrl'] ?? null);
			$article->setAuthors(Article::encodeAuthors($extracted['authors'] ?? null));
			$article->setSiteName($extracted['siteName']);
			$article->setImageUrl($extracted['imageUrl']);
			$article->setReadingTime((int) $extracted['readingTime']);
			if (!empty($extracted['publishedAt'])) {
				$article->setPublishedAt($extracted['publishedAt']);
			}
			if (!empty($extracted['category'])) {
				$article->setCategory($extracted['category']);
			}
			$article->setIsPaywalled(!empty($extracted['isPaywalled']) ? 1 : 0);
			$article->setPaywallSubscribeUrl($extracted['paywallSubscribeUrl'] ?? null);
			// Ein erneuter Versuch darf ein vorhandenes Seiten-Icon nicht löschen.
			if (!empty($extracted['siteIconUrl'])) {
				$article->setSiteIconUrl($extracted['siteIconUrl']);
			}
			$article->setUpdatedAt(new \DateTime());
			$article->setIsProcessing(0);
			$this->articles->update($article);
		} catch (PaywallLoginRequiredException $e) {
			// Kein Fehlschlag: der Client bietet einen Login-Dialog an.
			$this->finish($articleId, $userId, static function (Article $a) use ($e): void {
				$a->setRequiresLoginDomain($e->domain);
				$a->setRequiresLoginPage($e->loginPage);
			});
		} catch (UnsupportedSiteException $e) {
			// Domain steht in $unsupported.xml: Hinweis statt „Erneut versuchen“.
			$this->finish($articleId, $userId, static function (Article $a) use ($e): void {
				$a->setUnsupportedSiteDomain($e->domain);
			});
		} catch (\Throwable) {
			// Fehlgeschlagen (der Extraktor protokolliert selbst): Spinner beenden.
			$this->finish($articleId, $userId, static function (): void {
			});
		}
	}

	private function finish(int $articleId, string $userId, callable $apply): void {
		try {
			$article = $this->articles->find($articleId, $userId);
			if ($article === null) {
				return;
			}
			$article->setIsProcessing(0);
			$apply($article);
			$this->articles->update($article);
		} catch (\Throwable) {
			// Nichts mehr zu tun.
		}
	}
}
