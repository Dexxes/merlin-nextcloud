<?php

/**
 * Routen der Nextcloud-App. Die gemeinsame API (Artikel, Schlagworte,
 * /api/capabilities …) steht in core/src/Api/routes.php und wird hier
 * übernommen; darunter die Routen, die (noch) nur die Nextcloud-App hat.
 * tools/test-route-parity.php prüft beide Listen gegen die Controller.
 */

// Im Checkout ein Symlink auf ../core, im App-Store-Tarball eine Kopie.
$coreRoutes = array_map(
    static fn (array $route): array => array_intersect_key($route, array_flip(['name', 'url', 'verb', 'requirements'])),
    require __DIR__ . '/../vendor/merlin/core/src/Api/routes.php',
);

return [
    'routes' => [
        ...$coreRoutes,

        // Page routes
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // TTS route (kombinierter Proxy-Endpunkt: Synthese + Streaming in einem Request)
        ['name' => 'tts#synthesize', 'url' => '/api/articles/{id}/tts', 'verb' => 'GET'],
        ['name' => 'pdf#show',       'url' => '/api/articles/{id}/pdf', 'verb' => 'GET'],

        // Dateien vom Handy in „Merlin Dateien“ (FileController, Service\MerlinFileService):
        // Zielpfad für den WebDAV-Upload, Eintrag anlegen, Datei über signierten Link.
        ['name' => 'file#target',   'url' => '/api/files/target',       'verb' => 'POST'],
        ['name' => 'file#register', 'url' => '/api/files',              'verb' => 'POST'],
        ['name' => 'file#content',  'url' => '/api/articles/{id}/file', 'verb' => 'GET'],
        ['name' => 'file#rename',   'url' => '/api/articles/{id}/file-name', 'verb' => 'PUT'],

        // Audio-/Video-Quelle eines Artikels (siehe Service/Media/MediaResolverService)
        ['name' => 'media#resolve', 'url' => '/api/articles/{id}/media', 'verb' => 'GET'],
        // Veraltet, nur für bestehende Clients: altes Format, nur HLS-Video
        ['name' => 'videoStream#resolve', 'url' => '/api/articles/{id}/video-stream', 'verb' => 'GET'],

        // YouTube-Embed-Proxy (siehe YoutubeEmbedController-Docblock): gibt dem
        // iOS/iPad-Reader eine echte https-Origin für YouTube-iframes, damit
        // der Player nicht mit Error 153 abbricht (loadFileURL-Origin ist file://).
        ['name' => 'youtube_embed#show', 'url' => '/api/youtube-embed', 'verb' => 'GET'],

        // Highlight API routes
        ['name' => 'highlight#index',   'url' => '/api/articles/{articleId}/highlights', 'verb' => 'GET'],
        ['name' => 'highlight#create',  'url' => '/api/articles/{articleId}/highlights', 'verb' => 'POST'],
        ['name' => 'highlight#update',  'url' => '/api/highlights/{id}',                 'verb' => 'PUT'],
        ['name' => 'highlight#destroy', 'url' => '/api/highlights/{id}',                 'verb' => 'DELETE'],

        // Kommentare (Besitzer): Threads an Markierungen oder am ganzen Artikel.
        // stream = Push-Kanal (SSE), liefert Gast-Beiträge sofort.
        ['name' => 'comment#index',   'url' => '/api/articles/{articleId}/comments',        'verb' => 'GET'],
        ['name' => 'comment#create',  'url' => '/api/articles/{articleId}/comments',        'verb' => 'POST'],
        ['name' => 'comment#stream',  'url' => '/api/articles/{articleId}/comments/stream', 'verb' => 'GET'],
        ['name' => 'comment#update',  'url' => '/api/comments/{id}',                        'verb' => 'PUT'],
        ['name' => 'comment#destroy', 'url' => '/api/comments/{id}',                        'verb' => 'DELETE'],

        // Share API routes (authenticated: erstellen/verwalten eines Public-Share-Links)
        ['name' => 'share#show',       'url' => '/api/articles/{articleId}/share',            'verb' => 'GET'],
        ['name' => 'share#create',     'url' => '/api/articles/{articleId}/share',            'verb' => 'POST'],
        ['name' => 'share#update',     'url' => '/api/articles/{articleId}/share',            'verb' => 'PUT'],
        ['name' => 'share#regenerate', 'url' => '/api/articles/{articleId}/share/regenerate', 'verb' => 'POST'],
        ['name' => 'share#destroy',    'url' => '/api/articles/{articleId}/share',            'verb' => 'DELETE'],

        // Public Share routes (unauthenticated: öffentliche Ansicht eines geteilten Artikels)
        ['name' => 'public_share#show',       'url' => '/s/{token}',              'verb' => 'GET'],
        ['name' => 'public_share#unlock',     'url' => '/s/{token}/unlock',       'verb' => 'POST'],
        ['name' => 'public_share#data',       'url' => '/s/{token}/data',         'verb' => 'GET'],
        ['name' => 'public_share#tts',        'url' => '/s/{token}/tts',          'verb' => 'GET'],
        ['name' => 'public_share#pdf',        'url' => '/s/{token}/pdf',          'verb' => 'GET'],

        // Markieren und Kommentieren als Gast hinter dem Share-Link (PublicCommentController)
        ['name' => 'public_comment#index',            'url' => '/s/{token}/comments',        'verb' => 'GET'],
        ['name' => 'public_comment#guest',            'url' => '/s/{token}/guest',           'verb' => 'POST'],
        ['name' => 'public_comment#create',           'url' => '/s/{token}/comments',        'verb' => 'POST'],
        ['name' => 'public_comment#update',           'url' => '/s/{token}/comments/{id}',   'verb' => 'PUT'],
        ['name' => 'public_comment#destroy',          'url' => '/s/{token}/comments/{id}',   'verb' => 'DELETE'],
        ['name' => 'public_comment#createHighlight',  'url' => '/s/{token}/highlights',      'verb' => 'POST'],
        ['name' => 'public_comment#destroyHighlight', 'url' => '/s/{token}/highlights/{id}', 'verb' => 'DELETE'],
        ['name' => 'public_comment#events',           'url' => '/s/{token}/events',          'verb' => 'GET'],

        // Settings routes
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],

        // Löschfrist archivierter Artikel: Hinweis bestätigen (Nutzer) und
        // Admin-Maximum pflegen (nur Admins, siehe RetentionAdminController).
        ['name' => 'retention#acknowledgeNotice', 'url' => '/api/retention/notice', 'verb' => 'POST'],
        ['name' => 'retentionAdmin#show',    'url' => '/api/admin/retention',         'verb' => 'GET'],
        ['name' => 'retentionAdmin#update',  'url' => '/api/admin/retention',         'verb' => 'PUT'],
        ['name' => 'retentionAdmin#preview', 'url' => '/api/admin/retention/preview', 'verb' => 'GET'],

        // Speicherverbrauch (für iOS-Einstellungen: DB-Speicher pro Nutzer)
        ['name' => 'storage#get', 'url' => '/api/storage', 'verb' => 'GET'],

        // Content-Filter-Verwaltung (nur Admins; ContentFilterController trägt
        // bewusst kein NoAdminRequired/NoCSRFRequired). Die {domain}-Requirement
        // engt den Platzhalter auf zulässige Domainzeichen ein: unpassende Werte
        // enden so als 404 im Router, statt erst im Controller geprüft zu werden.
        ['name' => 'contentFilter#index',   'url' => '/api/admin/content-filters', 'verb' => 'GET'],
        ['name' => 'contentFilter#import',  'url' => '/api/admin/content-filters/import', 'verb' => 'POST'],
        ['name' => 'contentFilter#show',    'url' => '/api/admin/content-filters/{domain}', 'verb' => 'GET',
            'requirements' => ['domain' => '[a-z0-9._\-]+']],
        ['name' => 'contentFilter#update',  'url' => '/api/admin/content-filters/{domain}', 'verb' => 'PUT',
            'requirements' => ['domain' => '[a-z0-9._\-]+']],
        ['name' => 'contentFilter#destroy', 'url' => '/api/admin/content-filters/{domain}', 'verb' => 'DELETE',
            'requirements' => ['domain' => '[a-z0-9._\-]+']],
        ['name' => 'contentFilter#test',    'url' => '/api/admin/content-filters/{domain}/test', 'verb' => 'POST',
            'requirements' => ['domain' => '[a-z0-9._\-]+']],
        ['name' => 'contentFilter#export',  'url' => '/api/admin/content-filters/{domain}/export', 'verb' => 'GET',
            'requirements' => ['domain' => '[a-z0-9._\-]+']],

        // Persönliche Content-Filter-Overrides (jeder eingeloggte Nutzer; eigener
        // Routen-Präfix statt /api/admin/... zu teilen, damit die Berechtigungsgrenze
        // nicht verwischt – siehe UserContentFilterController-Docblock).
        ['name' => 'userContentFilter#index',   'url' => '/api/user/content-filters', 'verb' => 'GET'],
        ['name' => 'userContentFilter#show',    'url' => '/api/user/content-filters/{domain}', 'verb' => 'GET',
            'requirements' => ['domain' => '[a-z0-9._\-]+']],
        ['name' => 'userContentFilter#update',  'url' => '/api/user/content-filters/{domain}', 'verb' => 'PUT',
            'requirements' => ['domain' => '[a-z0-9._\-]+']],
        ['name' => 'userContentFilter#destroy', 'url' => '/api/user/content-filters/{domain}', 'verb' => 'DELETE',
            'requirements' => ['domain' => '[a-z0-9._\-]+']],
        ['name' => 'userContentFilter#test',    'url' => '/api/user/content-filters/{domain}/test', 'verb' => 'POST',
            'requirements' => ['domain' => '[a-z0-9._\-]+']],

        // Paywall-Abo-Zugangsdaten (jeder eingeloggte Nutzer, eigene private Ebene).
        ['name' => 'siteCredential#index',   'url' => '/api/user/site-credentials', 'verb' => 'GET'],
        ['name' => 'siteCredential#update',  'url' => '/api/user/site-credentials/{domain}', 'verb' => 'PUT',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],
        ['name' => 'siteCredential#destroy', 'url' => '/api/user/site-credentials/{domain}', 'verb' => 'DELETE',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],

        // merlin-reports-Meldungsbackend, serverseitig (kein CORS am Backend nötig).
        ['name' => 'report#ping',   'url' => '/api/report-backend/ping', 'verb' => 'GET'],
        ['name' => 'report#report', 'url' => '/api/report-backend/report', 'verb' => 'POST'],

        // PWA: Web App Manifest + Service Worker
        ['name' => 'manifest#index',       'url' => '/manifest.webmanifest', 'verb' => 'GET'],
        ['name' => 'service_worker#index', 'url' => '/sw.js',                'verb' => 'GET'],

        // Browser extension API (Pocket-compatible)
        ['name' => 'extension#add', 'url' => '/api/v1/add', 'verb' => 'POST'],
        ['name' => 'extension#get', 'url' => '/api/v1/get', 'verb' => 'POST'],
        ['name' => 'extension#modify', 'url' => '/api/v1/send', 'verb' => 'POST'],
    ]
];
