<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\Article;
use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\TagMapper;
use OCA\Merlin\Service\ContentExtractorService;
use OCA\Merlin\Service\ExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * REST API for articles.
 *
 * Security note – NoCSRFRequired:
 * All API routes carry #[NoCSRFRequired] because the same endpoints serve
 * both the Vue web-UI (session cookie) and native clients (iOS, Android,
 * browser extensions) that authenticate via HTTP Basic Auth and cannot
 * supply a Nextcloud requesttoken. Removing the attribute would break all
 * native clients; splitting routes into separate web/API prefixes is the
 * clean long-term fix (tracked). Residual CSRF risk for the web-UI path is
 * mitigated by Nextcloud's SameSite=Lax session cookie, which prevents
 * cross-site POST/PUT/DELETE in all modern browsers.
 */
class ArticleController extends Controller {
	private ArticleMapper $articleMapper;
	private TagMapper $tagMapper;
	private ContentExtractorService $contentExtractor;
	private ExportService $exportService;
	private IURLGenerator $urlGenerator;
	private LoggerInterface $logger;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		ArticleMapper $articleMapper,
		TagMapper $tagMapper,
		ContentExtractorService $contentExtractor,
		ExportService $exportService,
		IURLGenerator $urlGenerator,
		LoggerInterface $logger,
		?string $userId
	) {
		parent::__construct($appName, $request);
		$this->articleMapper = $articleMapper;
		$this->tagMapper = $tagMapper;
		$this->contentExtractor = $contentExtractor;
		$this->exportService = $exportService;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->userId = $userId;
	}

	/**
	 * Returns the article's imageUrl, or the no-img.png placeholder if none is set.
	 */
	private function resolveImageUrl(?string $imageUrl): string {
		if (!empty($imageUrl)) {
			return $imageUrl;
		}
		return $this->urlGenerator->imagePath('merlin', 'no-img.png');
	}

	/**
	 * Get unfiltered article counts (total / unread / favorites)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function counts(): DataResponse {
		return new DataResponse($this->articleMapper->getCounts($this->userId));
	}

	/**
	 * Get all articles
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(
		int $limit = 50,
		int $offset = 0,
		?bool $isRead = null,
		?bool $isFavorite = null,
		?bool $isArchived = null,
		?int $tagId = null,
		?string $category = null
	): DataResponse {
		$filters = array_filter([
			'is_read'    => $isRead,
			'is_favorite' => $isFavorite,
			'is_archived' => $isArchived,
			'tag_id'     => $tagId,
			'category'   => $category,
		], fn($value) => $value !== null);

		// Clear articles stuck in processing state from crashed/previous sessions.
		$this->articleMapper->clearStuckProcessing($this->userId);

		$articles = $this->articleMapper->findAll($this->userId, $filters, $limit, $offset);

		// Attach tags to each article
		$articlesWithTags = array_map(function ($article) {
			$tags = $this->tagMapper->findByArticleId($article->getId());
			$articleData = $article->jsonSerialize();
			$articleData['imageUrl'] = $this->resolveImageUrl($articleData['imageUrl']);
			$articleData['tags'] = array_map(fn($tag) => $tag->jsonSerialize(), $tags);
			return $articleData;
		}, $articles);

		return new DataResponse($articlesWithTags);
	}

	/**
	 * Get single article
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): DataResponse {
		try {
			$article = $this->articleMapper->find($id, $this->userId);
			$tags = $this->tagMapper->findByArticleId($article->getId());

			$articleData = $article->jsonSerialize();
			$articleData['imageUrl'] = $this->resolveImageUrl($articleData['imageUrl']);
			$articleData['tags'] = array_map(fn($tag) => $tag->jsonSerialize(), $tags);

			return new DataResponse($articleData);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Create new article from URL.
	 *
	 * The article is stored immediately with the URL as a placeholder title so
	 * the client receives HTTP 202 without waiting for content extraction.
	 * Content is fetched asynchronously after the response is sent.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(string $url): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			// Resolve tagIds – check query-string params first (most reliable), then JSON body.
			// Both paths are inside try so any parsing exception is caught rather than becoming a 500.
			$rawTagIds = $this->request->getParam('tagIds');
			if (!is_array($rawTagIds)) {
				$body      = (array) json_decode((string) file_get_contents('php://input'), true);
				$rawTagIds = $body['tagIds'] ?? null;
			}
			$tagIds = is_array($rawTagIds)
				? array_values(array_filter(array_map('intval', $rawTagIds), fn($v) => $v > 0))
				: [];
			// 1. Save a placeholder article immediately so the client is not blocked.
			$article = new Article();
			$article->setUserId($this->userId);
			$article->setUrl($url);
			$article->setTitle(parse_url($url, PHP_URL_HOST) ?: $url);
			$article->setContent('');
			$article->setExcerpt('');
			$article->setAuthor('');
			$article->setSiteName(parse_url($url, PHP_URL_HOST) ?: '');
			$article->setImageUrl('');
			$article->setReadingTime(0);
			$article->setIsRead(0);
			$article->setIsFavorite(null);
			$article->setIsArchived(0);
			$article->setIsProcessing(1);
			$article->setCreatedAt(new \DateTime());
			$article->setUpdatedAt(new \DateTime());

			$savedArticle = $this->articleMapper->insert($article);

			// Add tags if provided
			foreach ($tagIds as $tagId) {
				try {
					$this->tagMapper->addToArticle($savedArticle->getId(), $tagId);
				} catch (\Throwable $e) {
					// Ignore tag errors
				}
			}

			// 2. Schedule content extraction to run after the HTTP response is sent.
			$articleId    = $savedArticle->getId();
			$extractor    = $this->contentExtractor;
			$mapper       = $this->articleMapper;
			$userId       = $this->userId;

			register_shutdown_function(static function () use ($articleId, $url, $extractor, $mapper, $userId): void {
				// Release the HTTP connection so the client is unblocked immediately.
				if (function_exists('fastcgi_finish_request')) {
					fastcgi_finish_request();
				}

				// Release the PHP session file lock immediately.
				// Without this, the SSE /api/events request from the same browser
				// blocks on session_start() for the entire duration of extraction.
				if (session_status() === PHP_SESSION_ACTIVE) {
					session_write_close();
				}

				// Give the extraction a generous timeout without affecting the
				// already-sent web request.
				set_time_limit(120);

				try {
					$extracted = $extractor->extract($url);

					$article = $mapper->find($articleId, $userId);

					$article->setUrl($extracted['url'] ?? $url);
					$article->setTitle($extracted['title']);
					$article->setContent($extracted['content']);
					$article->setExcerpt($extracted['excerpt']);
					$article->setAuthor($extracted['author']);
					$article->setSiteName($extracted['siteName']);
					$article->setImageUrl($extracted['imageUrl']);
					$article->setReadingTime($extracted['readingTime']);
					if (!empty($extracted['publishedAt'])) {
						$article->setPublishedAt($extracted['publishedAt']);
					}
					if (!empty($extracted['category'])) {
						$article->setCategory($extracted['category']);
					}
					$article->setUpdatedAt(new \DateTime());
					$article->setIsProcessing(0);
					$mapper->update($article);
				} catch (\Throwable $e) {
					// Extraction failed – clear the processing flag so the spinner stops.
					try {
						$failed = $mapper->find($articleId, $userId);
						$failed->setIsProcessing(0);
						$mapper->update($failed);
					} catch (\Throwable $e2) {
						// Ignore – nothing more we can do.
					}
				}
			});

			// 3. Return 202 Accepted immediately.
			$articleData             = $savedArticle->jsonSerialize();
			$articleData['imageUrl'] = $this->resolveImageUrl($articleData['imageUrl']);
			$articleData['tags']     = [];
			return new DataResponse($articleData, Http::STATUS_ACCEPTED);
		} catch (\Throwable $e) {
			$this->logger->error('Merlin: article creation failed', ['exception' => $e]);
			return new DataResponse(['error' => 'Bad request'], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update article
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(
		int $id,
		?string $title = null,
		?bool $isRead = null,
		?bool $isFavorite = null,
		?bool $isArchived = null
	): DataResponse {
		try {
			$article = $this->articleMapper->find($id, $this->userId);

			if ($title !== null) {
				$article->setTitle($title);
			}
			if ($isRead !== null) {
				$article->setIsRead($isRead);
			}
			if ($isFavorite !== null) {
				$article->setIsFavorite($isFavorite ? new \DateTime() : null);
			}
			if ($isArchived !== null) {
				$article->setIsArchived($isArchived);
			}

			$article->setUpdatedAt(new \DateTime());
			$updatedArticle = $this->articleMapper->update($article);

			$tags = $this->tagMapper->findByArticleId($updatedArticle->getId());
			$articleData = $updatedArticle->jsonSerialize();
			$articleData['tags'] = array_map(fn($tag) => $tag->jsonSerialize(), $tags);

			return new DataResponse($articleData);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Delete article
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(int $id): DataResponse {
		try {
			$article = $this->articleMapper->find($id, $this->userId);
			$this->articleMapper->delete($article);

			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Toggle read status
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function toggleRead(int $id): DataResponse {
		try {
			$article = $this->articleMapper->find($id, $this->userId);
			$article->setIsRead(!$article->getIsRead());
			$article->setUpdatedAt(new \DateTime());
			$this->articleMapper->update($article);

			return new DataResponse(['isRead' => $article->getIsRead()]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Toggle favorite status
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function toggleFavorite(int $id): DataResponse {
		try {
			$article = $this->articleMapper->find($id, $this->userId);
			$article->setIsFavorite($article->getIsFavorite() ? null : new \DateTime());
			$article->setUpdatedAt(new \DateTime());
			$this->articleMapper->update($article);

			return new DataResponse([
				'isFavorite' => $article->getIsFavorite() ? $article->getIsFavorite()->format('c') : false,
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Toggle archive status
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function toggleArchive(int $id): DataResponse {
		try {
			$article = $this->articleMapper->find($id, $this->userId);
			$isNowArchived = !$article->getIsArchived();
			$article->setIsArchived($isNowArchived);
			$article->setArchivedAt($isNowArchived ? new \DateTime() : null);
			$article->setUpdatedAt(new \DateTime());
			$this->articleMapper->update($article);

			return new DataResponse([
				'isArchived' => $article->getIsArchived(),
				'archivedAt' => $article->getArchivedAt() ? $article->getArchivedAt()->format('c') : null,
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Update the cross-device reading/scroll position.
	 *
	 * Stores the reading progress as a fraction (0..1, NOT a pixel offset –
	 * pixels are device-specific) plus a client-supplied epoch-millis timestamp
	 * that drives last-write-wins reconciliation on the clients.
	 *
	 * The article's own `updated_at` is deliberately NOT bumped here: this is a
	 * high-frequency, low-importance write, and bumping `updated_at` would make
	 * clients treat the row as "changed" (re-render / re-sort the list) on every
	 * scroll save.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updateProgress(int $id, float $progress = 0.0, ?int $updatedAt = null): DataResponse {
		try {
			$article = $this->articleMapper->find($id, $this->userId);

			// Defensiv klemmen – ein fehlerhafter Client darf keine Werte außerhalb 0..1 persistieren.
			$clamped = max(0.0, min(1.0, $progress));
			$article->setScrollProgress($clamped);
			// Client-Zeitstempel verbatim übernehmen (einheitliche Uhr über alle
			// Plattformen, funktioniert auch offline); fehlt er, Server-Zeit als Fallback.
			$article->setScrollUpdatedAt($updatedAt ?? (int) round(microtime(true) * 1000));

			$this->articleMapper->update($article);

			return new DataResponse([
				'scrollProgress'  => $article->getScrollProgress(),
				'scrollUpdatedAt' => $article->getScrollUpdatedAt(),
			]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Search articles
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function search(string $query, int $limit = 50): DataResponse {
		$articles = $this->articleMapper->search($this->userId, $query, $limit);

		$articlesWithTags = array_map(function ($article) {
			$tags = $this->tagMapper->findByArticleId($article->getId());
			$articleData = $article->jsonSerialize();
			$articleData['imageUrl'] = $this->resolveImageUrl($articleData['imageUrl']);
			$articleData['tags'] = array_map(fn($tag) => $tag->jsonSerialize(), $tags);
			return $articleData;
		}, $articles);

		return new DataResponse($articlesWithTags);
	}

	/**
	 * Export article as HTML
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportHtml(int $id): DataDisplayResponse {
		try {
			$article = $this->articleMapper->find($id, $this->userId);
			$htmlContent = $this->exportService->exportHtml($article);

			$response = new DataDisplayResponse($htmlContent, Http::STATUS_OK, ['Content-Type' => 'text/html']);
			$response->addHeader('Content-Disposition', 'attachment; filename="' . $this->sanitizeFilename($article->getTitle()) . '.html"');

			return $response;
		} catch (\Exception $e) {
			return new DataDisplayResponse('Article not found', Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Server-Sent Events endpoint.
	 *
	 * Opens a persistent HTTP connection and pushes one `article-ready` event for
	 * every article that finishes processing while the client is connected.
	 * Closes automatically once all pending articles are done or after ~55 s.
	 *
	 * The method calls exit() to bypass AppFramework response handling; this is
	 * intentional and a common pattern for streaming responses in Nextcloud apps.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function stream(): void {
		// 1. Clear ALL output buffers first, before any code that can throw.
		//    Some of these buffers are set by Nextcloud's AppFramework before
		//    the controller is invoked.
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		// 2. Set SSE response headers immediately – before any code that can
		//    throw an exception.  If we set these first and then an exception
		//    occurs, our exit(0) below prevents the AppFramework exception
		//    handler from ever running, so these headers remain in effect.
		//    Previously the headers were set AFTER the DB query, which meant
		//    a DB exception caused the AppFramework to override Content-Type
		//    with application/json → EventSource received a non-SSE response
		//    → "Firefox cannot connect" error.
		header('Content-Type: text/event-stream; charset=utf-8');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');
		header('X-Accel-Buffering: no'); // Prevent nginx from buffering the stream.

		// 3. Release the session lock so the browser can make parallel requests
		//    (e.g. adding another article) while this long-lived connection waits.
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

		ignore_user_abort(true);
		set_time_limit(60);

		@ini_set('output_buffering', 'Off');
		@ini_set('implicit_flush', '1');
		ob_implicit_flush(true);

		// 4. Query for pending articles.  Wrapped in try-catch so any DB error
		//    sends a valid SSE idle event rather than allowing the AppFramework
		//    to emit a JSON 500 error (which would break the EventSource).
		try {
			$pendingIds = array_map(
				fn($a) => $a->getId(),
				$this->articleMapper->findProcessing($this->userId)
			);
		} catch (\Throwable $e) {
			echo "data: {\"type\":\"idle\"}\n\n";
			flush();
			exit(0);
		}

		if (empty($pendingIds)) {
			echo "data: {\"type\":\"idle\"}\n\n";
			flush();
			exit(0);
		}

		// Send ~2 KB of padding so PHP-FPM's SAPI buffer is filled and flushed
		// to nginx immediately – without this some setups buffer the entire
		// response and the client only receives events after exit().
		echo ':' . str_repeat(' ', 2048) . "\n\n";
		echo ': connected, ' . count($pendingIds) . " article(s) pending\n\n";
		flush();

		$deadline = time() + 55;

		while (!empty($pendingIds) && time() < $deadline) {
			if (connection_aborted()) {
				break;
			}
			sleep(2);

			foreach ($pendingIds as $key => $articleId) {
				try {
					$article = $this->articleMapper->find($articleId, $this->userId);
					if (!$article->getIsProcessing()) {
						$tags = $this->tagMapper->findByArticleId($articleId);
						$data = $article->jsonSerialize();
						$data['imageUrl'] = $this->resolveImageUrl($data['imageUrl']);
						$data['tags'] = array_map(fn($t) => $t->jsonSerialize(), $tags);

						echo "event: article-ready\n";
						echo 'data: ' . json_encode($data) . "\n\n";
						flush();

						unset($pendingIds[$key]);
					}
				} catch (\Throwable $e) {
					// Article deleted or inaccessible – stop waiting for it.
					unset($pendingIds[$key]);
				}
			}

			if (!empty($pendingIds)) {
				echo ": heartbeat\n\n";
				flush();
			}
		}

		echo "data: {\"type\":\"done\"}\n\n";
		flush();
		exit(0);
	}

	/**
	 * Sanitize filename for download
	 */
	private function sanitizeFilename(string $filename): string {
		$filename = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $filename);
		$filename = preg_replace('/\s+/', '_', $filename);
		return substr($filename, 0, 100);
	}
}
