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
 * RateLimitMiddleware), userRateLimit [limit, period] dasselbe je
 * angemeldetem Nutzer (#[UserRateLimit]), requirements wie in Nextcloud
 * (Regex je Platzhalter), csrf true für Routen nur der Weboberfläche:
 * Nextcloud verlangt dort den requesttoken (kein #[NoCSRFRequired]), 'admin'
 * ist immer so. Reihenfolge zählt: feste Pfade
 * vor Platzhaltern (/api/articles/search vor /api/articles/{id}).
 *
 * Die Datei ist ein reines Array (::class braucht keinen Autoloader), damit
 * Nextcloud sie beim Lesen der Routen ohne die App laden kann.
 * tools/test-route-parity.php prüft beide Varianten dagegen.
 */

use Merlin\Core\Api\ArticleApi;
use Merlin\Core\Api\ArticleStreamApi;
use Merlin\Core\Api\CapabilitiesApi;
use Merlin\Core\Api\CommentApi;
use Merlin\Core\Api\ContentFilterApi;
use Merlin\Core\Api\ExtensionApi;
use Merlin\Core\Api\FileApi;
use Merlin\Core\Api\HighlightApi;
use Merlin\Core\Api\MediaApi;
use Merlin\Core\Api\PwaApi;
use Merlin\Core\Api\PublicCommentApi;
use Merlin\Core\Api\PublicShareApi;
use Merlin\Core\Api\ReportApi;
use Merlin\Core\Api\RetentionApi;
use Merlin\Core\Api\SettingsApi;
use Merlin\Core\Api\ShareApi;
use Merlin\Core\Api\SiteCredentialApi;
use Merlin\Core\Api\StorageApi;
use Merlin\Core\Api\TagApi;

// Gast-Schreibzugriffe hinter einem Share-Link: 20 je IP in 5 Minuten.
$guestLimit = [20, 300];
// Testlauf eines Content-Filters (Abruf der fremden Seite): 20 je Nutzer in 5 Minuten.
$filterTestLimit = [20, 300];
// Platzhalter {domain}: nur Domainzeichen, alles andere endet als 404 im Router.
$filterDomain = ['domain' => '[a-z0-9._\-]+'];
$loginDomain = ['domain' => '[a-z0-9.\-]+'];

return [
	['name' => 'manifest#index', 'verb' => 'GET', 'url' => '/manifest.webmanifest', 'handler' => [PwaApi::class, 'manifest'], 'access' => 'public'],
	['name' => 'service_worker#index', 'verb' => 'GET', 'url' => '/sw.js', 'handler' => [PwaApi::class, 'serviceWorker'], 'access' => 'public'],
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

	// Vorlesen (MP3-Stream), PDF-Durchreichung, Audio-/Videoquellen
	['name' => 'tts#synthesize', 'verb' => 'GET', 'url' => '/api/articles/{id}/tts', 'handler' => [ArticleStreamApi::class, 'synthesize'], 'access' => 'user'],
	['name' => 'pdf#show', 'verb' => 'GET', 'url' => '/api/articles/{id}/pdf', 'handler' => [ArticleStreamApi::class, 'show'], 'access' => 'user'],
	['name' => 'media#resolve', 'verb' => 'GET', 'url' => '/api/articles/{id}/media', 'handler' => [MediaApi::class, 'resolve'], 'access' => 'user'],
	// „Merlin Dateien“: Upload-Ziel (Upload selbst per WebDAV), Eintrag anlegen,
	// umbenennen, Datei über signierten Link (ohne Anmeldung, Token t)
	['name' => 'file#target', 'verb' => 'POST', 'url' => '/api/files/target', 'handler' => [FileApi::class, 'target'], 'access' => 'user'],
	['name' => 'file#register', 'verb' => 'POST', 'url' => '/api/files', 'handler' => [FileApi::class, 'register'], 'access' => 'user'],
	['name' => 'file#content', 'verb' => 'GET', 'url' => '/api/articles/{id}/file', 'handler' => [FileApi::class, 'content'], 'access' => 'public'],
	['name' => 'file#rename', 'verb' => 'PUT', 'url' => '/api/articles/{id}/file-name', 'handler' => [FileApi::class, 'rename'], 'access' => 'user'],
	// Veraltet, nur für bestehende Clients: altes Format, nur HLS-Video
	['name' => 'videoStream#resolve', 'verb' => 'GET', 'url' => '/api/articles/{id}/video-stream', 'handler' => [MediaApi::class, 'videoStream'], 'access' => 'user'],
	// YouTube-Seite mit echter https-Origin für die nativen Reader (ohne Anmeldung)
	['name' => 'youtube_embed#show', 'verb' => 'GET', 'url' => '/api/youtube-embed', 'handler' => [MediaApi::class, 'youtubeEmbed'], 'access' => 'public'],

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
	['name' => 'public_share#tts', 'verb' => 'GET', 'url' => '/s/{token}/tts', 'handler' => [ArticleStreamApi::class, 'tts'], 'access' => 'public'],
	['name' => 'public_share#pdf', 'verb' => 'GET', 'url' => '/s/{token}/pdf', 'handler' => [ArticleStreamApi::class, 'pdf'], 'access' => 'public'],

	// Gäste markieren und kommentieren hinter dem Link, events: Server-Sent Events
	['name' => 'public_comment#index', 'verb' => 'GET', 'url' => '/s/{token}/comments', 'handler' => [PublicCommentApi::class, 'index'], 'access' => 'public'],
	['name' => 'public_comment#guest', 'verb' => 'POST', 'url' => '/s/{token}/guest', 'handler' => [PublicCommentApi::class, 'guest'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#create', 'verb' => 'POST', 'url' => '/s/{token}/comments', 'handler' => [PublicCommentApi::class, 'create'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#update', 'verb' => 'PUT', 'url' => '/s/{token}/comments/{id}', 'handler' => [PublicCommentApi::class, 'update'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#destroy', 'verb' => 'DELETE', 'url' => '/s/{token}/comments/{id}', 'handler' => [PublicCommentApi::class, 'destroy'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#createHighlight', 'verb' => 'POST', 'url' => '/s/{token}/highlights', 'handler' => [PublicCommentApi::class, 'createHighlight'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#destroyHighlight', 'verb' => 'DELETE', 'url' => '/s/{token}/highlights/{id}', 'handler' => [PublicCommentApi::class, 'destroyHighlight'], 'access' => 'public', 'rateLimit' => $guestLimit],
	['name' => 'public_comment#events', 'verb' => 'GET', 'url' => '/s/{token}/events', 'handler' => [PublicCommentApi::class, 'events'], 'access' => 'public'],

	// Leseeinstellungen, Löschfrist (Hinweis bestätigen, Admin-Maximum), Speicherverbrauch
	['name' => 'settings#get', 'verb' => 'GET', 'url' => '/api/settings', 'handler' => [SettingsApi::class, 'get'], 'access' => 'user'],
	['name' => 'settings#update', 'verb' => 'PUT', 'url' => '/api/settings', 'handler' => [SettingsApi::class, 'update'], 'access' => 'user'],
	['name' => 'retention#acknowledgeNotice', 'verb' => 'POST', 'url' => '/api/retention/notice', 'handler' => [RetentionApi::class, 'acknowledgeNotice'], 'access' => 'user'],
	['name' => 'retentionAdmin#show', 'verb' => 'GET', 'url' => '/api/admin/retention', 'handler' => [RetentionApi::class, 'adminShow'], 'access' => 'admin'],
	['name' => 'retentionAdmin#update', 'verb' => 'PUT', 'url' => '/api/admin/retention', 'handler' => [RetentionApi::class, 'adminUpdate'], 'access' => 'admin'],
	['name' => 'retentionAdmin#preview', 'verb' => 'GET', 'url' => '/api/admin/retention/preview', 'handler' => [RetentionApi::class, 'adminPreview'], 'access' => 'admin'],
	['name' => 'storage#get', 'verb' => 'GET', 'url' => '/api/storage', 'handler' => [StorageApi::class, 'get'], 'access' => 'user'],

	// Content-Filter: Verwaltung (nur Admins, instanzweit) …
	['name' => 'contentFilter#index', 'verb' => 'GET', 'url' => '/api/admin/content-filters', 'handler' => [ContentFilterApi::class, 'index'], 'access' => 'admin'],
	['name' => 'contentFilter#import', 'verb' => 'POST', 'url' => '/api/admin/content-filters/import', 'handler' => [ContentFilterApi::class, 'import'], 'access' => 'admin'],
	['name' => 'contentFilter#show', 'verb' => 'GET', 'url' => '/api/admin/content-filters/{domain}', 'handler' => [ContentFilterApi::class, 'show'], 'access' => 'admin', 'requirements' => $filterDomain],
	['name' => 'contentFilter#update', 'verb' => 'PUT', 'url' => '/api/admin/content-filters/{domain}', 'handler' => [ContentFilterApi::class, 'update'], 'access' => 'admin', 'requirements' => $filterDomain],
	['name' => 'contentFilter#destroy', 'verb' => 'DELETE', 'url' => '/api/admin/content-filters/{domain}', 'handler' => [ContentFilterApi::class, 'destroy'], 'access' => 'admin', 'requirements' => $filterDomain],
	['name' => 'contentFilter#test', 'verb' => 'POST', 'url' => '/api/admin/content-filters/{domain}/test', 'handler' => [ContentFilterApi::class, 'test'], 'access' => 'admin', 'requirements' => $filterDomain, 'userRateLimit' => $filterTestLimit],
	['name' => 'contentFilter#export', 'verb' => 'GET', 'url' => '/api/admin/content-filters/{domain}/export', 'handler' => [ContentFilterApi::class, 'export'], 'access' => 'admin', 'requirements' => $filterDomain],
	// … und persönliche Overrides (jeder Nutzer, nur die Weboberfläche)
	['name' => 'userContentFilter#index', 'verb' => 'GET', 'url' => '/api/user/content-filters', 'handler' => [ContentFilterApi::class, 'userIndex'], 'access' => 'user', 'csrf' => true],
	['name' => 'userContentFilter#show', 'verb' => 'GET', 'url' => '/api/user/content-filters/{domain}', 'handler' => [ContentFilterApi::class, 'userShow'], 'access' => 'user', 'csrf' => true, 'requirements' => $filterDomain],
	['name' => 'userContentFilter#update', 'verb' => 'PUT', 'url' => '/api/user/content-filters/{domain}', 'handler' => [ContentFilterApi::class, 'userUpdate'], 'access' => 'user', 'csrf' => true, 'requirements' => $filterDomain],
	['name' => 'userContentFilter#destroy', 'verb' => 'DELETE', 'url' => '/api/user/content-filters/{domain}', 'handler' => [ContentFilterApi::class, 'userDestroy'], 'access' => 'user', 'csrf' => true, 'requirements' => $filterDomain],
	['name' => 'userContentFilter#test', 'verb' => 'POST', 'url' => '/api/user/content-filters/{domain}/test', 'handler' => [ContentFilterApi::class, 'userTest'], 'access' => 'user', 'csrf' => true, 'requirements' => $filterDomain, 'userRateLimit' => $filterTestLimit],

	// Paywall-Abo-Zugangsdaten (eigene, private Ebene); Speichern meldet sich gleich an
	['name' => 'siteCredential#index', 'verb' => 'GET', 'url' => '/api/user/site-credentials', 'handler' => [SiteCredentialApi::class, 'index'], 'access' => 'user'],
	['name' => 'siteCredential#update', 'verb' => 'PUT', 'url' => '/api/user/site-credentials/{domain}', 'handler' => [SiteCredentialApi::class, 'update'], 'access' => 'user', 'requirements' => $loginDomain, 'userRateLimit' => [10, 300]],
	['name' => 'siteCredential#destroy', 'verb' => 'DELETE', 'url' => '/api/user/site-credentials/{domain}', 'handler' => [SiteCredentialApi::class, 'destroy'], 'access' => 'user', 'requirements' => $loginDomain],

	// merlin-reports-Meldungsbackend, serverseitig (kein CORS am Backend nötig)
	['name' => 'report#ping', 'verb' => 'GET', 'url' => '/api/report-backend/ping', 'handler' => [ReportApi::class, 'ping'], 'access' => 'user', 'userRateLimit' => [30, 300]],
	['name' => 'report#report', 'verb' => 'POST', 'url' => '/api/report-backend/report', 'handler' => [ReportApi::class, 'report'], 'access' => 'user', 'userRateLimit' => [20, 300]],

	// Browser-Erweiterungen (Pocket-kompatibel, Basic Auth)
	['name' => 'extension#add', 'verb' => 'POST', 'url' => '/api/v1/add', 'handler' => [ExtensionApi::class, 'add'], 'access' => 'user'],
	['name' => 'extension#get', 'verb' => 'POST', 'url' => '/api/v1/get', 'handler' => [ExtensionApi::class, 'get'], 'access' => 'user'],
	['name' => 'extension#modify', 'verb' => 'POST', 'url' => '/api/v1/send', 'handler' => [ExtensionApi::class, 'modify'], 'access' => 'user'],
];
