<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Domain\Article;
use Merlin\Core\Extraction\ContentExtractorService;
use Merlin\Core\Extraction\UnsupportedSiteException;
use Merlin\Core\Port\ArticleDeletion;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Service\ArticleExtractionService;
use Psr\Log\LoggerInterface;

/**
 * Pocket-kompatible API der Browser-Erweiterungen (/api/v1/add, /get,
 * /send) für beide Varianten, nur mit Basic Auth. Antworten wie bisher in
 * ExtensionController.
 */
final class ExtensionApi {
	public function __construct(
		private ArticleStore $articles,
		private ArticleExtractionService $extraction,
		private ContentExtractorService $extractor,
		private ArticleDeletion $deletion,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * POST /api/v1/add: url, optional title, html, author. Speichert sofort
	 * einen Platzhalter (201) und holt den Inhalt nach der Antwort; mit html
	 * wird die mitgeschickte Seite verwendet statt sie abzurufen.
	 */
	public function add(ApiRequest $request): ApiResult {
		if (!$request->hasUser()) {
			return ApiResult::json(['status' => 0, 'error' => 'Not authenticated'], 401);
		}
		$userId = $request->userId();
		$url = (string) ($request->string('url') ?? '');
		if ($url === '') {
			return ApiResult::json(['status' => 0, 'error' => 'Bad request'], 400);
		}
		$title = $request->string('title');
		$html = $request->string('html');
		$author = $request->string('author');

		// Domains aus $unsupported.xml hier (vor dem Platzhalter) ablehnen: die
		// Erweiterungen fragen das Extraktions-Ergebnis nie ab und zeigten sonst
		// immer Erfolg. Nur wenn die Seite abgerufen würde (kein html).
		if ($html === null) {
			try {
				$this->extractor->assertUrlIsSupported($url);
			} catch (UnsupportedSiteException $e) {
				return ApiResult::json(['status' => 0, 'error' => 'unsupported_site', 'domain' => $e->domain], 422);
			} catch (\Throwable) {
				// Andere Fehler (z. B. Shortener) prüft die Extraktion ohnehin erneut.
			}
		}

		try {
			$host = parse_url($url, PHP_URL_HOST) ?: '';
			$article = new Article();
			$article->setUserId($userId);
			$article->setUrl($url);
			$article->setTitle($title ?? ($host ?: $url));
			$article->setContent('');
			$article->setExcerpt('');
			$article->setAuthor($author ?? '');
			$article->setSiteName($host);
			$article->setImageUrl('');
			$article->setCategory(null);
			$article->setReadingTime(0);
			$article->setIsRead(0);
			$article->setIsFavorite(null);
			$article->setIsArchived(0);
			$article->setIsProcessing(1);
			$article->setCreatedAt(new \DateTime());
			$article->setUpdatedAt(new \DateTime());
			$article = $this->articles->insert($article);

			$this->extraction->schedule((int) $article->getId(), $url, $userId, $html, $title, $author);

			$id = (string) $article->getId();
			return ApiResult::json([
				'item' => [
					'item_id' => $id,
					'resolved_id' => $id,
					'given_url' => $url,
					'resolved_url' => $url,
					'given_title' => $article->getTitle(),
					'resolved_title' => $article->getTitle(),
					'status' => '0', // 0 = normal, 1 = archived, 2 = deleted
				],
				'status' => 1,
			], 201);
		} catch (\Throwable $e) {
			$this->logger->error('Merlin: extension article creation failed', ['exception' => $e]);
			return ApiResult::json(['status' => 0, 'error' => 'Bad request'], 400);
		}
	}

	/** POST /api/v1/get: state (unread, archive, all), count, offset. */
	public function get(ApiRequest $request): ApiResult {
		$filters = match ($request->string('state')) {
			'unread' => ['is_read' => false, 'is_archived' => false],
			'archive' => ['is_archived' => true],
			default => [],
		};
		$list = $this->articles->findAll($request->userId(), $filters, (int) $request->int('count', 10), (int) $request->int('offset', 0));

		$items = [];
		foreach ($list as $article) {
			$id = (string) $article->getId();
			$items[$id] = [
				'item_id' => $id,
				'resolved_id' => $id,
				'given_url' => $article->getUrl(),
				'resolved_url' => $article->getUrl(),
				'given_title' => $article->getTitle(),
				'resolved_title' => $article->getTitle(),
				'favorite' => $article->getIsFavorite() ? '1' : '0',
				'status' => $article->getIsArchived() ? '1' : '0',
				'excerpt' => $article->getExcerpt(),
				'time_added' => $article->getCreatedAt()?->getTimestamp(),
				'time_updated' => $article->getUpdatedAt()?->getTimestamp(),
			];
		}
		return ApiResult::json(['status' => 1, 'list' => $items]);
	}

	/**
	 * POST /api/v1/send: actions [{action, item_id}], action archive, readd,
	 * favorite, unfavorite oder delete. Ein Ergebnis je Aktion.
	 */
	public function modify(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$actions = $request->param('actions');
		$results = [];
		foreach (is_array($actions) ? $actions : [] as $action) {
			$itemId = is_array($action) ? ($action['item_id'] ?? null) : null;
			try {
				$article = $this->articles->find((int) $itemId, $userId);
				if ($article === null) {
					throw new \RuntimeException('Not found');
				}
				switch ($action['action'] ?? null) {
					case 'archive':
						$article->applyArchived(true);
						break;
					case 'readd':
						$article->applyArchived(false);
						break;
					case 'favorite':
						$article->setIsFavorite(new \DateTime());
						break;
					case 'unfavorite':
						$article->setIsFavorite(null);
						break;
					case 'delete':
						$this->deletion->deleteArticles($userId, [(int) $article->getId()]);
						$results[] = ['success' => true, 'item_id' => (int) $itemId];
						continue 2;
				}
				$article->setUpdatedAt(new \DateTime());
				$this->articles->update($article);
				$results[] = ['success' => true, 'item_id' => (int) $itemId];
			} catch (\Throwable) {
				$results[] = ['success' => false, 'item_id' => $itemId, 'error' => 'Not found'];
			}
		}
		return ApiResult::json(['status' => 1, 'action_results' => $results]);
	}
}
