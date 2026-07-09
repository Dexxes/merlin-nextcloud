<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\Article;
use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Service\ContentExtractorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Pocket-compatible API for browser extensions.
 *
 * Security note – NoCSRFRequired:
 * All API routes carry #[NoCSRFRequired] because this endpoint is exclusively
 * consumed by browser extensions via HTTP Basic Auth and cannot supply a
 * Nextcloud requesttoken. CSRF is not applicable to Basic-Auth-only routes.
 */
class ExtensionController extends Controller {
	private ArticleMapper $articleMapper;
	private ContentExtractorService $contentExtractor;
	private LoggerInterface $logger;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		ArticleMapper $articleMapper,
		ContentExtractorService $contentExtractor,
		LoggerInterface $logger,
		?string $userId
	) {
		parent::__construct($appName, $request);
		$this->articleMapper = $articleMapper;
		$this->contentExtractor = $contentExtractor;
		$this->logger = $logger;
		$this->userId = $userId;
	}

	/**
	 * Add article (Pocket-compatible)
	 *
	 * The article is stored immediately with a placeholder title so the client
	 * receives HTTP 201 without waiting for content extraction.
	 * Content is fetched asynchronously after the response is sent.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function add(string $url, ?string $title = null, ?array $tags = null, ?string $html = null): DataResponse {
		try {
			// 1. Save a placeholder article immediately so the client is not blocked.
			$article = new Article();
			$article->setUserId($this->userId);
			$article->setUrl($url);
			$article->setTitle($title ?? (parse_url($url, PHP_URL_HOST) ?: $url));
			$article->setContent('');
			$article->setExcerpt('');
			$article->setAuthor('');
			$article->setSiteName(parse_url($url, PHP_URL_HOST) ?: '');
			$article->setImageUrl('');
			$article->setCategory(null);
			$article->setReadingTime(0);
			$article->setIsRead(false);
			$article->setIsFavorite(null);
			$article->setIsArchived(false);
			$article->setIsProcessing(1);
			$article->setCreatedAt(new \DateTime());
			$article->setUpdatedAt(new \DateTime());

			$savedArticle = $this->articleMapper->insert($article);

			// 2. Schedule content extraction to run after the HTTP response is sent.
			$articleId = $savedArticle->getId();
			$extractor = $this->contentExtractor;
			$mapper    = $this->articleMapper;
			$userId    = $this->userId;

			register_shutdown_function(static function () use ($articleId, $url, $html, $extractor, $mapper, $userId): void {
				// Release the HTTP connection so the client is unblocked immediately.
				if (function_exists('fastcgi_finish_request')) {
					fastcgi_finish_request();
				}
				if (session_status() === PHP_SESSION_ACTIVE) {
					session_write_close();
				}
				set_time_limit(60);
				try {
					// If the extension sent the page HTML, use it directly (avoids a second
					// network fetch and works for paywalled / JS-rendered pages).
					$extracted = $html
						? $extractor->extractFromHtml($url, $html)
						: $extractor->extract($url);
					$article   = $mapper->find($articleId, $userId);
					$article->setUrl($extracted['url'] ?? $url);
					$article->setTitle($extracted['title']);
					$article->setContent($extracted['content']);
					$article->setExcerpt($extracted['excerpt']);
					$article->setAuthor($extracted['author']);
					$article->setSiteName($extracted['siteName']);
					$article->setImageUrl($extracted['imageUrl']);
					$article->setCategory($extracted['category']);
					$article->setReadingTime($extracted['readingTime']);
					if (!empty($extracted['publishedAt'])) {
						$article->setPublishedAt($extracted['publishedAt']);
					}
					$article->setUpdatedAt(new \DateTime());
					$article->setIsProcessing(0);
					$mapper->update($article);
				} catch (\Throwable) {
					// Silently ignore extraction errors — the article is already saved.
					try {
						$article = $mapper->find($articleId, $userId);
						$article->setIsProcessing(0);
						$mapper->update($article);
					} catch (\Throwable) {
					}
				}
			});

			return new DataResponse([
				'item' => [
					'item_id' => (string) $savedArticle->getId(),
					'resolved_id' => (string) $savedArticle->getId(),
					'given_url' => $url,
					'resolved_url' => $url,
					'given_title' => $savedArticle->getTitle(),
					'resolved_title' => $savedArticle->getTitle(),
					'status' => '0', // 0 = normal, 1 = archived, 2 = deleted
				],
				'status' => 1,
			], Http::STATUS_CREATED);
		} catch (\Exception $e) {
			$this->logger->error('Merlin: extension article creation failed', ['exception' => $e]);
			return new DataResponse([
				'status' => 0,
				'error' => 'Bad request',
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Get articles (Pocket-compatible)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(
		?string $state = null,
		?int $count = 10,
		?int $offset = 0
	): DataResponse {
		$filters = [];

		// Map Pocket states to our filters
		switch ($state) {
			case 'unread':
				$filters['is_read'] = false;
				$filters['is_archived'] = false;
				break;
			case 'archive':
				$filters['is_archived'] = true;
				break;
			case 'all':
			default:
				// No filters
				break;
		}

		$articles = $this->articleMapper->findAll($this->userId, $filters, $count, $offset);

		$items = [];
		foreach ($articles as $article) {
			$items[(string) $article->getId()] = [
				'item_id' => (string) $article->getId(),
				'resolved_id' => (string) $article->getId(),
				'given_url' => $article->getUrl(),
				'resolved_url' => $article->getUrl(),
				'given_title' => $article->getTitle(),
				'resolved_title' => $article->getTitle(),
				'favorite' => $article->getIsFavorite() ? '1' : '0',
				'status' => $article->getIsArchived() ? '1' : '0',
				'excerpt' => $article->getExcerpt(),
				'time_added' => $article->getCreatedAt()->getTimestamp(),
				'time_updated' => $article->getUpdatedAt()->getTimestamp(),
			];
		}

		return new DataResponse([
			'status' => 1,
			'list' => $items,
		]);
	}

	/**
	 * Modify article (Pocket-compatible)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function modify(array $actions): DataResponse {
		$results = [];

		foreach ($actions as $action) {
			try {
				$itemId = (int) $action['item_id'];
				$article = $this->articleMapper->find($itemId, $this->userId);

				switch ($action['action']) {
					case 'archive':
						$article->setIsArchived(true);
						break;
					case 'readd':
						$article->setIsArchived(false);
						break;
					case 'favorite':
						$article->setIsFavorite(new \DateTime());
						break;
					case 'unfavorite':
						$article->setIsFavorite(null);
						break;
					case 'delete':
						$this->articleMapper->delete($article);
						$results[] = ['success' => true, 'item_id' => $itemId];
						continue 2;
				}

				$article->setUpdatedAt(new \DateTime());
				$this->articleMapper->update($article);

				$results[] = ['success' => true, 'item_id' => $itemId];
			} catch (\Exception $e) {
				$results[] = [
					'success' => false,
					'item_id' => $action['item_id'] ?? null,
					'error' => 'Not found',
				];
			}
		}

		return new DataResponse([
			'status' => 1,
			'action_results' => $results,
		]);
	}
}
