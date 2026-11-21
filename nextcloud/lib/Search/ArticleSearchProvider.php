<?php

declare(strict_types=1);

namespace OCA\Merlin\Search;

use OCA\Merlin\AppInfo\Application;
use OCA\Merlin\Db\ArticleMapper;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class ArticleSearchProvider implements IProvider {

	public function __construct(
		private ArticleMapper $articleMapper,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {}

	public function getId(): string {
		return 'merlin_articles';
	}

	public function getName(): string {
		return $this->l->t('Merlin articles');
	}

	public function getOrder(string $route, array $routeParameters): int {
		// Show near the top when inside the Reader app, lower priority elsewhere
		if (str_starts_with($route, 'merlin.')) {
			return -5;
		}
		return 50;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term   = $query->getTerm();
		$offset = $query->getCursor() ?? 0;
		$limit  = $query->getLimit();

		$articles = $this->articleMapper->search(
			$user->getUID(),
			$term,
			(int) $limit + 1,   // fetch one extra to know if there are more
			(int) $offset,
		);

		$hasMore = count($articles) > $limit;
		if ($hasMore) {
			array_pop($articles);
		}

		$entries = array_map(function ($article) {
			$appUrl = $this->urlGenerator->linkToRoute('merlin.page.index');

			return new SearchResultEntry(
				thumbnailUrl: $article->getImageUrl() ?? '',
				title:        $article->getTitle() ?? '',
				subline:      $article->getSiteName() ?? $article->getUrl() ?? '',
				// Deep-link: open Reader and immediately show the article
				resourceUrl:  $appUrl . '#article-' . $article->getId(),
				icon:         $this->urlGenerator->imagePath(Application::APP_ID, 'icon-32.png'),
				rounded:      false,
			);
		}, $articles);

		return SearchResult::paginated(
			$this->getName(),
			$entries,
			$hasMore ? $offset + $limit : null,
		);
	}
}
