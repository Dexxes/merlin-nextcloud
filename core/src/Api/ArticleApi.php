<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\ContentFilter\ContentFilterSchema;
use Merlin\Core\Domain\Article;
use Merlin\Core\Extraction\ContentExtractorService;
use Merlin\Core\Extraction\UnsupportedSiteException;
use Merlin\Core\Port\ArticleDeletion;
use Merlin\Core\Port\ArticleFiles;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\TagStore;
use Merlin\Core\Port\UrlGenerator;
use Merlin\Core\Service\ArticleExtractionService;
use Merlin\Core\Service\ExportService;
use Merlin\Core\Service\SupportBoxService;
use Merlin\Core\Service\TagTree;
use Psr\Log\LoggerInterface;

/**
 * Artikel-API (/api/articles…, /api/events) für beide Varianten. Antworten
 * und Statuscodes wie bisher in OCA\Merlin\Controller\ArticleController;
 * Routen in Api/routes.php.
 */
final class ArticleApi {
	public function __construct(
		private ArticleStore $articles,
		private TagStore $tags,
		private ArticleExtractionService $extraction,
		private ContentExtractorService $extractor,
		private ExportService $export,
		private SupportBoxService $supportBox,
		private ArticleDeletion $deletion,
		private ArticleFiles $files,
		private UrlGenerator $urls,
		private LoggerInterface $logger,
	) {
	}

	/** GET /api/articles/counts: ungefilterte Zähler für die Seitenleiste. */
	public function counts(ApiRequest $request): ApiResult {
		return ApiResult::json($this->articles->counts($request->userId()));
	}

	/**
	 * GET /api/articles: limit, offset, isRead, isFavorite, isArchived, tagId
	 * (samt Unterschlagworten), category, contentType (page/video/audio/file).
	 */
	public function index(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$filters = array_filter([
			'is_read' => $request->bool('isRead'),
			'is_favorite' => $request->bool('isFavorite'),
			'is_archived' => $request->bool('isArchived'),
			'category' => $request->string('category'),
		], static fn ($value) => $value !== null);

		$tagId = $request->int('tagId');
		if ($tagId !== null) {
			$filters['tag_ids'] = array_merge([$tagId], TagTree::descendantIds($this->tags->parentMap($userId), $tagId));
		}

		// Seiten/Videos/Audio/Dateien: oberste Aufteilung, unabhängig von den
		// Filtern oben. "page" ist alles außer Video und Audio (also auch
		// "Mixed"), Datei-Einträge stehen nur unter "file".
		switch ($request->string('contentType')) {
			case 'video':
				$filters['category'] = 'Video';
				$filters['has_file'] = false;
				break;
			case 'audio':
				$filters['category'] = 'Audio';
				$filters['has_file'] = false;
				break;
			case 'page':
				unset($filters['category']);
				$filters['not_category'] = ContentFilterSchema::MEDIA_CATEGORIES;
				$filters['has_file'] = false;
				break;
			case 'file':
				$filters['has_file'] = true;
				break;
		}

		// Artikel, deren Extraktion abgebrochen ist (Prozess beendet), freigeben.
		$this->articles->clearStuckProcessing($userId);

		$list = $this->articles->findAll($userId, $filters, (int) $request->int('limit', 50), (int) $request->int('offset', 0));
		return ApiResult::json($this->presentAll($list));
	}

	/** GET /api/articles/search?query=…&limit=… */
	public function search(ApiRequest $request): ApiResult {
		$found = $this->articles->search($request->userId(), (string) $request->string('query', ''), (int) $request->int('limit', 50));
		return ApiResult::json($this->presentAll($found));
	}

	/** GET /api/articles/{id}: mit Schlagworten und Daten der Support-Infobox. */
	public function show(ApiRequest $request): ApiResult {
		try {
			$article = $this->find($request);
			if ($article === null) {
				return self::notFound();
			}
			// Datei-Einträge beim Öffnen nachziehen (umbenannt, alter Inhalt).
			if ($article->getFileId() !== null) {
				try {
					$article = $this->files->refreshIfNeeded($article);
				} catch (\Throwable $e) {
					$this->logger->info('Merlin: could not add metadata to file entry', ['exception' => $e]);
				}
			}
			$data = $this->present($article);
			// Nur im Einzelabruf, nicht in Listen (siehe SupportBoxService).
			$data['supportBox'] = $this->supportBox->forReader($article->toSnapshot(), $request->userId());
			return ApiResult::json($data);
		} catch (\Exception) {
			return self::notFound();
		}
	}

	/**
	 * POST /api/articles: url, optional tagIds und rejectUnsupported. Speichert
	 * sofort einen Platzhalter (202) und holt den Inhalt nach der Antwort.
	 */
	public function create(ApiRequest $request): ApiResult {
		if (!$request->hasUser()) {
			return ApiResult::error('Not authenticated', 401);
		}
		$userId = $request->userId();
		$url = $request->string('url');
		if ($url === null || $url === '') {
			return ApiResult::error('Bad request', 400);
		}

		// Opt-in-Vorabprüfung gegen $unsupported.xml (Web-Client), damit der
		// Dialog gleich eine Fehlermeldung zeigen kann. Ohne den Parameter
		// (iOS, Android) bleibt es beim Platzhalter mit unsupportedSiteDomain.
		if ($request->bool('rejectUnsupported', false)) {
			try {
				$this->extractor->assertUrlIsSupported($url);
			} catch (UnsupportedSiteException $e) {
				return ApiResult::json(['error' => 'unsupported_site', 'domain' => $e->domain], 422);
			} catch (\Throwable) {
				// Andere Fehler (z. B. Shortener) prüft die Extraktion ohnehin erneut.
			}
		}

		try {
			$rawTagIds = $request->param('tagIds');
			if (!is_array($rawTagIds)) {
				$rawTagIds = $request->jsonBody()['tagIds'] ?? null;
			}
			$tagIds = is_array($rawTagIds)
				? array_values(array_filter(array_map('intval', $rawTagIds), static fn (int $v) => $v > 0))
				: [];

			$host = parse_url($url, PHP_URL_HOST) ?: '';
			$article = new Article();
			$article->setUserId($userId);
			$article->setUrl($url);
			$article->setTitle($host ?: $url);
			$article->setContent('');
			$article->setExcerpt('');
			$article->setAuthor('');
			$article->setSiteName($host);
			$article->setImageUrl('');
			$article->setReadingTime(0);
			$article->setIsRead(0);
			$article->setIsFavorite(null);
			$article->setIsArchived(0);
			$article->setIsProcessing(1);
			$article->setCreatedAt(new \DateTime());
			$article->setUpdatedAt(new \DateTime());
			$article = $this->articles->insert($article);

			foreach ($tagIds as $tagId) {
				try {
					// Nur eigene Schlagworte.
					if ($this->tags->find($tagId, $userId) !== null) {
						$this->tags->addToArticle((int) $article->getId(), $tagId);
					}
				} catch (\Throwable) {
					// Schlagwort-Fehler ignorieren.
				}
			}

			$this->extraction->schedule((int) $article->getId(), $url, $userId);

			return ApiResult::json($this->present($article, true, []), 202);
		} catch (\Throwable $e) {
			$this->logger->error('Merlin: article creation failed', ['exception' => $e]);
			return ApiResult::error('Bad request', 400);
		}
	}

	/**
	 * POST /api/articles/{id}/retry-extraction: nach einem fehlgeschlagenen
	 * ersten Versuch neu extrahieren („Erneut versuchen“); Datei-Einträge
	 * werden aus der Datei neu aufgebaut.
	 */
	public function retryExtraction(ApiRequest $request): ApiResult {
		try {
			$article = $this->find($request);
			if ($article === null) {
				return self::notFound();
			}
			if ($article->getFileId() !== null) {
				$article = $this->files->refresh($article);
			}
			if ($article->getIsProcessing() || $article->getFileId() !== null) {
				// Läuft schon (oder nichts zu extrahieren): kein zweiter Lauf.
				return ApiResult::json($this->present($article));
			}
			$article->setIsProcessing(1);
			$article->setUpdatedAt(new \DateTime());
			$article = $this->articles->update($article);
			$this->extraction->schedule((int) $article->getId(), $article->getUrl(), $request->userId());
			return ApiResult::json($this->present($article));
		} catch (\Exception) {
			return self::notFound();
		}
	}

	/** PUT /api/articles/{id}: title, isRead, isFavorite, isArchived. */
	public function update(ApiRequest $request): ApiResult {
		try {
			$article = $this->find($request);
			if ($article === null) {
				return self::notFound();
			}
			$title = $request->string('title');
			if ($title !== null) {
				$article->setTitle($title);
			}
			$isRead = $request->bool('isRead');
			if ($isRead !== null) {
				$article->setIsRead($isRead ? 1 : 0);
			}
			$isFavorite = $request->bool('isFavorite');
			if ($isFavorite !== null) {
				$article->setIsFavorite($isFavorite ? new \DateTime() : null);
			}
			$isArchived = $request->bool('isArchived');
			if ($isArchived !== null) {
				$article->applyArchived($isArchived);
			}
			$article->setUpdatedAt(new \DateTime());
			$article = $this->articles->update($article);

			// Wie bisher ohne Platzhalterbild (imageUrl so wie gespeichert).
			return ApiResult::json($this->present($article, false));
		} catch (\Exception) {
			return self::notFound();
		}
	}

	/** DELETE /api/articles/{id}: mit Markierungen, Kommentaren, Zuordnungen, Shares. */
	public function destroy(ApiRequest $request): ApiResult {
		try {
			$article = $this->find($request);
			if ($article === null) {
				return self::notFound();
			}
			$this->deletion->deleteArticles($request->userId(), [(int) $article->getId()]);
			return ApiResult::json(['success' => true]);
		} catch (\Exception) {
			return self::notFound();
		}
	}

	/** PUT /api/articles/{id}/read: Antwort {isRead: 0|1}. */
	public function toggleRead(ApiRequest $request): ApiResult {
		return $this->change($request, static function (Article $article): array {
			$article->setIsRead($article->getIsRead() ? 0 : 1);
			$article->setUpdatedAt(new \DateTime());
			return ['isRead' => $article->getIsRead()];
		});
	}

	/** PUT /api/articles/{id}/favorite: Antwort {isFavorite: false|Zeitpunkt}. */
	public function toggleFavorite(ApiRequest $request): ApiResult {
		return $this->change($request, static function (Article $article): array {
			$article->setIsFavorite($article->getIsFavorite() ? null : new \DateTime());
			$article->setUpdatedAt(new \DateTime());
			return ['isFavorite' => $article->getIsFavorite() ? $article->getIsFavorite()->format('c') : false];
		});
	}

	/** PUT /api/articles/{id}/archive: Antwort {isArchived: 0|1, archivedAt}. */
	public function toggleArchive(ApiRequest $request): ApiResult {
		return $this->change($request, static function (Article $article): array {
			$article->applyArchived(!$article->getIsArchived());
			$article->setUpdatedAt(new \DateTime());
			return [
				'isArchived' => $article->getIsArchived(),
				'archivedAt' => $article->getArchivedAt()?->format('c'),
			];
		});
	}

	/**
	 * PUT /api/articles/{id}/progress: Leseposition als Anteil 0..1 plus
	 * Zeitstempel des Clients (Millisekunden, last write wins). updated_at
	 * bleibt bewusst unverändert, sonst sähen Clients den Artikel bei jedem
	 * Scrollen als geändert an.
	 */
	public function updateProgress(ApiRequest $request): ApiResult {
		return $this->change($request, static function (Article $article) use ($request): array {
			$article->setScrollProgress(max(0.0, min(1.0, (float) $request->float('progress', 0.0))));
			$article->setScrollUpdatedAt($request->int('updatedAt') ?? (int) round(microtime(true) * 1000));
			return [
				'scrollProgress' => $article->getScrollProgress(),
				'scrollUpdatedAt' => $article->getScrollUpdatedAt(),
			];
		});
	}

	/** GET /api/articles/{id}/export/html: eigenständige HTML-Datei zum Herunterladen. */
	public function exportHtml(ApiRequest $request): ApiResult {
		try {
			$article = $this->find($request);
			if ($article === null) {
				return ApiResult::body('Article not found', 'text/plain; charset=utf-8', 404);
			}
			return ApiResult::body($this->export->exportHtml($article->toSnapshot()), 'text/html', 200, [
				'Content-Disposition' => 'attachment; filename="' . self::sanitizeFilename($article->getTitle()) . '.html"',
			]);
		} catch (\Exception) {
			return ApiResult::body('Article not found', 'text/plain; charset=utf-8', 404);
		}
	}

	/**
	 * GET /api/events: Server-Sent Events. Ein `article-ready` je Artikel, der
	 * fertig wird, solange die Verbindung offen ist; endet, wenn nichts mehr
	 * aussteht, spätestens nach etwa 55 Sekunden.
	 */
	public function stream(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		return ApiResult::stream(function () use ($userId): void {
			ignore_user_abort(true);
			set_time_limit(60);

			// Ein Datenbankfehler soll ein gültiges SSE-Ereignis ergeben, keinen
			// JSON-Fehler, an dem EventSource scheitert.
			try {
				$pending = array_map(static fn (Article $a) => (int) $a->getId(), $this->articles->findProcessing($userId));
			} catch (\Throwable) {
				$pending = [];
			}
			if ($pending === []) {
				echo "data: {\"type\":\"idle\"}\n\n";
				flush();
				return;
			}

			// ~2 KB Füllung, damit PHP-FPM seinen Puffer sofort an nginx weitergibt.
			echo ':' . str_repeat(' ', 2048) . "\n\n";
			echo ': connected, ' . count($pending) . " article(s) pending\n\n";
			flush();

			$deadline = time() + 55;
			while ($pending !== [] && time() < $deadline) {
				if (connection_aborted()) {
					break;
				}
				sleep(2);
				foreach ($pending as $key => $articleId) {
					try {
						$article = $this->articles->find($articleId, $userId);
						if ($article === null) {
							unset($pending[$key]);
						} elseif (!$article->getIsProcessing()) {
							echo "event: article-ready\n";
							echo 'data: ' . json_encode($this->present($article)) . "\n\n";
							flush();
							unset($pending[$key]);
						}
					} catch (\Throwable) {
						unset($pending[$key]);
					}
				}
				if ($pending !== []) {
					echo ": heartbeat\n\n";
					flush();
				}
			}
			echo "data: {\"type\":\"done\"}\n\n";
			flush();
		});
	}

	/**
	 * Artikel als JSON mit Schlagworten; ohne Vorschaubild ein Platzhalter.
	 *
	 * @return array<string, mixed>
	 */
	public function present(Article $article, bool $placeholderImage = true, ?array $tags = null): array {
		$data = $article->jsonSerialize();
		if ($placeholderImage) {
			$data['imageUrl'] = $this->imageUrl($article);
		}
		$tags ??= $this->tags->findByArticleId((int) $article->getId());
		$data['tags'] = array_map(static fn ($tag) => $tag->jsonSerialize(), $tags);
		return $data;
	}

	/**
	 * @param list<Article> $articles
	 * @return list<array<string, mixed>>
	 */
	private function presentAll(array $articles): array {
		$tags = $this->tags->findByArticleIds(array_map(static fn (Article $a) => (int) $a->getId(), $articles));
		return array_map(fn (Article $a) => $this->present($a, true, $tags[(int) $a->getId()] ?? []), $articles);
	}

	/**
	 * Vorschaubild oder Platzhalter: PDFs bekommen ein Icon (absolut, weil
	 * iOS/Android es über die normale Bild-Pipeline laden), sonst no-img.png.
	 */
	private function imageUrl(Article $article): string {
		$imageUrl = $article->getImageUrl();
		if ($imageUrl !== null && $imageUrl !== '') {
			return $imageUrl;
		}
		if ($article->getCategory() === ContentExtractorService::PDF_CATEGORY) {
			return $this->urls->absoluteImageUrl('pdf-preview.png');
		}
		return $this->urls->imagePath('no-img.png');
	}

	/** @param callable(Article): array<string, mixed> $apply */
	private function change(ApiRequest $request, callable $apply): ApiResult {
		try {
			$article = $this->find($request);
			if ($article === null) {
				return self::notFound();
			}
			$response = $apply($article);
			$this->articles->update($article);
			return ApiResult::json($response);
		} catch (\Exception) {
			return self::notFound();
		}
	}

	private function find(ApiRequest $request): ?Article {
		return $this->articles->find((int) $request->int('id'), $request->userId());
	}

	private static function notFound(): ApiResult {
		return ApiResult::error('Not found', 404);
	}

	private static function sanitizeFilename(string $filename): string {
		$filename = (string) preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $filename);
		$filename = (string) preg_replace('/\s+/', '_', $filename);
		return substr($filename, 0, 100);
	}
}
