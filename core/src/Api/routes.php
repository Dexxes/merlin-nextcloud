<?php

declare(strict_types=1);

/**
 * Die eine Routentabelle der gemeinsamen API (Plan „gemeinsames Repo“,
 * Etappe 4). Beide Varianten bauen ihre Routen daraus:
 *
 * - Nextcloud: nextcloud/appinfo/routes.php übernimmt jede Zeile als
 *   ['name', 'url', 'verb'] (plus 'requirements'); der Controller zu
 *   'name' (z. B. article#index → ArticleController::index()) ruft nur den
 *   Handler auf (Controller\CoreApiController).
 * - Standalone: Http\Routes registriert jede Zeile beim Router, Pfade ohne
 *   /index.php/apps/merlin-Präfix.
 *
 * Felder: name (Nextcloud-Routenname), verb, url, handler [Klasse, Methode]
 * mit Signatur (ApiRequest): ApiResult, access: 'user' (angemeldet),
 * 'admin' oder 'public' (ohne Anmeldung), optional rateLimit [limit, period]
 * für öffentliche Schreibrouten: höchstens limit Anfragen je IP in period
 * Sekunden (Nextcloud: #[AnonRateLimit] am Controller, Standalone:
 * RateLimitMiddleware). Reihenfolge zählt: feste Pfade
 * vor Platzhaltern (/api/articles/search vor /api/articles/{id}).
 *
 * Die Datei ist ein reines Array (::class braucht keinen Autoloader), damit
 * Nextcloud sie beim Lesen der Routen ohne die App laden kann.
 * tools/test-route-parity.php prüft beide Varianten dagegen.
 */

use Merlin\Core\Api\ArticleApi;
use Merlin\Core\Api\CapabilitiesApi;
use Merlin\Core\Api\CommentApi;
use Merlin\Core\Api\HighlightApi;
use Merlin\Core\Api\PublicCommentApi;
use Merlin\Core\Api\PublicShareApi;
use Merlin\Core\Api\ShareApi;
use Merlin\Core\Api\TagApi;

// Gast-Schreibzugriffe hinter einem Share-Link: 20 je IP in 5 Minuten.
$guestLimit = [20, 300];

return [
	['name' => 'capabilities#show', 'verb' => 'GET', 'url' => '/api/capabilities', 'handler' => [CapabilitiesApi::class, 'show'], 'access' => 'public'],

	// Artikel
	['name' => 'article#counts', 'verb' => 'GET', 'url' => '/api/articles/counts', 'handler' => [ArticleApi::class, 'counts'], 'access' => 'user'],
	['name' => 'article#index', 'verb' => 'GET', 'url' => '/api/articles', 'handler' => [ArticleApi::class, 'index'], 'access' => 'user'],
	['name' => 'article#search', 'verb' => 'GET', 'url' => '/api/articles/search', 'handler' => [ArticleApi::class, 'search'], 'access' => 'user'],
	['name' => 'article#show', 'verb' => 'GET', 'url' => '/api/articles/{id}', 'handler' => [ArticleApi::class, 'show'], 'access' => 'user'],
	['name' => 'article#create', 'verb' => 'POST', 'url' => '/api/articles', 'handler' => [ArticleApi::class, 'create'], 'access' => 'user'],
	['name' => 'article#update', 'verb' => 'PUT', 'url' => '/api/articles/{id}', 'handler' => [ArticleApi::class, 'update'], 'access' => 'user'],
	['name' => 'article#destroy', 'verb' => 'DELETE', 'url' => '/api/articles/{id}', 'handler' => [ArticleApi::class, 'destroy'], 'access' => 'user'],
	['name' => 'article#toggleRead', 'verb' => 'PUT', 'url' => '/api/articles/{id}/read', 'handler' => [ArticleApi::class, 'toggleRead'], 'access' => 'user'],
	['name' => 'article#toggleFavorite', 'verb' => 'PUT', 'url' => '/api/articles/{id}/favorite', 'handler' => [ArticleApi::class, 'toggleFavorite'], 'access' => 'user'],
	['name' => 'article#toggleArchive', 'verb' => 'PUT', 'url' => '/api/articles/{id}/archive', 'handler' => [ArticleApi::class, 'toggleArchive'], 'access' => 'user'],
	['name' => 'article#updateProgress', 'verb' => 'PUT', 'url' => '/api/articles/{id}/progress', 'handler' => [ArticleApi::class, 'updateProgress'], 'access' => 'user'],
	['name' => 'article#retryExtraction', 'verb' => 'POST', 'url' => '/api/articles/{id}/retry-extraction', 'handler' => [ArticleApi::class, 'retryExtraction'], 'access' => 'user'],
	['name' => 'article#exportHtml', 'verb' => 'GET', 'url' => '/api/articles/{id}/export/html', 'handler' => [ArticleApi::class, 'exportHtml'], 'access' => 'user'],
	// Server-Sent Events: article-ready, sobald die Extraktion fertig ist
	['name' => 'article#stream', 'verb' => 'GET', 'url' => '/api/events', 'handler' => [ArticleApi::class, 'stream'], 'access' => 'user'],

	// Schlagworte (verschachtelt)
	['name' => 'tag#index', 'verb' => 'GET', 'url' => '/api/tags', 'handler' => [TagApi::class, 'index'], 'access' => 'user'],
	['name' => 'tag#create', 'verb' => 'POST', 'url' => '/api/tags', 'handler' => [TagApi::class, 'create'], 'access' => 'user'],
	['name' => 'tag#update', 'verb' => 'PUT', 'url' => '/api/tags/{id}', 'handler' => [TagApi::class, 'update'], 'access' => 'user'],
	['name' => 'tag#destroy', 'verb' => 'DELETE', 'url' => '/api/tags/{id}', 'handler' => [TagApi::class, 'destroy'], 'access' => 'user'],
	['name' => 'tag#addToArticle', 'verb' => 'POST', 'url' => '/api/articles/{articleId}/tags/{tagId}', 'handler' => [TagApi::class, 'addToArticle'], 'access' => 'user'],
	['name' => 'tag#removeFromArticle', 'verb' => 'DELETE', 'url' => '/api/articles/{articleId}/tags/{tagId}', 'handler' => [TagApi::class, 'removeFromArticle'], 'access' => 'user'],

	// Markierungen des Besitzers
	['name' => 'highlight#index', 'verb' => 'GET', 'url' => '/api/articles/{articleId}/highlights', 'handler' => [HighlightApi::class, 'index'], 'access' => 'user'],
	['name' => 'highlight#create', 'verb' => 'POST', 'url' => '/api/articles/{articleId}/highlights', 'handler' => [HighlightApi::class, 'create'], 'access' => 'user'],
	['name' => 'highlight#update', 'verb' => 'PUT', 'url' => '/api/highlights/{id}', 'handler' => [HighlightApi::class, 'update'], 'access' => 'user'],
	['name' => 'highlight#destroy', 'verb' => 'DELETE', 'url' => '/api/highlights/{id}', 'handler' => [HighlightApi::class, 'destroy'], 'access' => 'user'],

	// Kommentare des Besitzers, stream: Server-Sent Events mit Gast-Beiträgen
	['name' => 'comment#index', 'verb' => 'GET', 'url' => '/api/articles/{articleId}/comments', 'handler' => [CommentApi::class, 'index'], 'access' => 'user'],
	['name' => 'comment#create', 'verb' => 'POST', 'url' => '/api/articles/{articleId}/comments', 'handler' => [CommentApi::class, 'create'], 'access' => 'user'],
	['name' => 'comment#stream', 'verb' => 'GET', 'url' => '/api/articles/{articleId}/comments/stream', 'handler' => [CommentApi::class, 'stream'], 'access' => 'user'],
	['name' => 'comment#update', 'verb' => 'PUT', 'url' => '/api/comments/{id}', 'handler' => [CommentApi::class, 'update'], 'access' => 'user'],
	['name' => 'comment#destroy', 'verb' => 'DELETE', 'url' => '/api/comments/{id}', 'handler' => [CommentApi::class, 'destroy'], 'access' => 'user'],

	// Share-Link eines Artikels verwalten
	['name' => 'share#show', 'verb' => 'GET', 'url' => '/api/articles/{articleId}/share', 'handler' => [ShareApi::class, 'show'], 'access' => 'user'],
	['name' => 'share#create', 'verb' => 'POST', 'url' => '/api/articles/{articleId}/share', 'handler' => [ShareApi::class, 'create'], 'access' => 'user'],
	['name' => 'share#update', 'verb' => 'PUT', 'url' => '/api/articles/{articleId}/share', 'handler' => [ShareApi::class, 'update'], 'access' => 'user'],
	['name' => 'share#regenerate', 'verb' => 'POST', 'url' => '/api/articles/{articleId}/share/regenerate', 'handler' => [ShareApi::class, 'regenerate'], 'access' => 'user'],
	['name' => 'share#destroy', 'verb' => 'DELETE', 'url' => '/api/articles/{articleId}/share', 'handler' => [ShareApi::class, 'destroy'], 'access' => 'user'],

	// Öffentliche Ansicht hinter dem Link (ohne Anmeldung)
	['name' => 'public_share#unlock', 'verb' => 'POST', 'url' => '/s/{token}/unlock', 'handler' => [PublicShareApi::class, 'unlock'], 'access' => 'public'],
	['name' => 'public_share#data', 'verb' => 'GET', 'url' => '/s/{token}/data', 'handler' => [PublicShareApi::class, 'data'], 'access' => 'public'],

	// Gäste markieren und kommentieren hinter dem Link, events: Server-Sent Events
	['name' => 'public_comment#index', 'verb' => 'GET', 'url' => '/s/{token}/comments', 'handler' => [PublicCommentApi::class, 'index'], 'access' => 'public'],
	['name' => 'public_comment#guest', 'verb' => 'POST', 'url' => '/s/{token}/guest', 'handler' => [PublicCommentApi::class, 'guest'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#create', 'verb' => 'POST', 'url' => '/s/{token}/comments', 'handler' => [PublicCommentApi::class, 'create'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#update', 'verb' => 'PUT', 'url' => '/s/{token}/comments/{id}', 'handler' => [PublicCommentApi::class, 'update'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#destroy', 'verb' => 'DELETE', 'url' => '/s/{token}/comments/{id}', 'handler' => [PublicCommentApi::class, 'destroy'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#createHighlight', 'verb' => 'POST', 'url' => '/s/{token}/highlights', 'handler' => [PublicCommentApi::class, 'createHighlight'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#destroyHighlight', 'verb' => 'DELETE', 'url' => '/s/{token}/highlights/{id}', 'handler' => [PublicCommentApi::class, 'destroyHighlight'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#events', 'verb' => 'GET', 'url' => '/s/{token}/events', 'handler' => [PublicCommentApi::class, 'events'], 'access' => 'public'],
];
