<?php

return [
    'routes' => [
        // Page routes
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Article API routes
        ['name' => 'article#counts', 'url' => '/api/articles/counts', 'verb' => 'GET'],
        ['name' => 'article#index', 'url' => '/api/articles', 'verb' => 'GET'],
        ['name' => 'article#show', 'url' => '/api/articles/{id}', 'verb' => 'GET'],
        ['name' => 'article#create', 'url' => '/api/articles', 'verb' => 'POST'],
        ['name' => 'article#update', 'url' => '/api/articles/{id}', 'verb' => 'PUT'],
        ['name' => 'article#destroy', 'url' => '/api/articles/{id}', 'verb' => 'DELETE'],
        ['name' => 'article#toggleRead', 'url' => '/api/articles/{id}/read', 'verb' => 'PUT'],
        ['name' => 'article#toggleFavorite', 'url' => '/api/articles/{id}/favorite', 'verb' => 'PUT'],
        ['name' => 'article#toggleArchive', 'url' => '/api/articles/{id}/archive', 'verb' => 'PUT'],
        ['name' => 'article#updateProgress', 'url' => '/api/articles/{id}/progress', 'verb' => 'PUT'],
        ['name' => 'article#search', 'url' => '/api/articles/search', 'verb' => 'GET'],

        // SSE: push article-ready events when processing finishes
        ['name' => 'article#stream', 'url' => '/api/events', 'verb' => 'GET'],

        // TTS route (kombinierter Proxy-Endpunkt: Synthese + Streaming in einem Request)
        ['name' => 'tts#synthesize', 'url' => '/api/articles/{id}/tts', 'verb' => 'GET'],

        // Export routes
        ['name' => 'article#exportHtml', 'url' => '/api/articles/{id}/export/html', 'verb' => 'GET'],

        // Tag API routes
        ['name' => 'tag#index', 'url' => '/api/tags', 'verb' => 'GET'],
        ['name' => 'tag#create', 'url' => '/api/tags', 'verb' => 'POST'],
        ['name' => 'tag#update', 'url' => '/api/tags/{id}', 'verb' => 'PUT'],
        ['name' => 'tag#destroy', 'url' => '/api/tags/{id}', 'verb' => 'DELETE'],
        ['name' => 'tag#addToArticle', 'url' => '/api/articles/{articleId}/tags/{tagId}', 'verb' => 'POST'],
        ['name' => 'tag#removeFromArticle', 'url' => '/api/articles/{articleId}/tags/{tagId}', 'verb' => 'DELETE'],

        // Highlight API routes
        ['name' => 'highlight#index',   'url' => '/api/articles/{articleId}/highlights', 'verb' => 'GET'],
        ['name' => 'highlight#create',  'url' => '/api/articles/{articleId}/highlights', 'verb' => 'POST'],
        ['name' => 'highlight#destroy', 'url' => '/api/highlights/{id}',                 'verb' => 'DELETE'],

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

        // Settings routes
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],

        // Content-Filter-Verwaltung (nur Admins; ContentFilterController trägt
        // bewusst kein NoAdminRequired/NoCSRFRequired). Die {domain}-Requirement
        // engt den Platzhalter auf zulässige Domainzeichen ein: unpassende Werte
        // enden so als 404 im Router, statt erst im Controller geprüft zu werden.
        ['name' => 'contentFilter#index',   'url' => '/api/admin/content-filters', 'verb' => 'GET'],
        ['name' => 'contentFilter#import',  'url' => '/api/admin/content-filters/import', 'verb' => 'POST'],
        ['name' => 'contentFilter#show',    'url' => '/api/admin/content-filters/{domain}', 'verb' => 'GET',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],
        ['name' => 'contentFilter#update',  'url' => '/api/admin/content-filters/{domain}', 'verb' => 'PUT',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],
        ['name' => 'contentFilter#destroy', 'url' => '/api/admin/content-filters/{domain}', 'verb' => 'DELETE',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],
        ['name' => 'contentFilter#test',    'url' => '/api/admin/content-filters/{domain}/test', 'verb' => 'POST',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],
        ['name' => 'contentFilter#export',  'url' => '/api/admin/content-filters/{domain}/export', 'verb' => 'GET',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],

        // Persönliche Content-Filter-Overrides (jeder eingeloggte Nutzer; eigener
        // Routen-Präfix statt /api/admin/... zu teilen, damit die Berechtigungsgrenze
        // nicht verwischt – siehe UserContentFilterController-Docblock).
        ['name' => 'userContentFilter#index',   'url' => '/api/user/content-filters', 'verb' => 'GET'],
        ['name' => 'userContentFilter#show',    'url' => '/api/user/content-filters/{domain}', 'verb' => 'GET',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],
        ['name' => 'userContentFilter#update',  'url' => '/api/user/content-filters/{domain}', 'verb' => 'PUT',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],
        ['name' => 'userContentFilter#destroy', 'url' => '/api/user/content-filters/{domain}', 'verb' => 'DELETE',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],
        ['name' => 'userContentFilter#test',    'url' => '/api/user/content-filters/{domain}/test', 'verb' => 'POST',
            'requirements' => ['domain' => '[a-z0-9.\-]+']],

        // PWA: Web App Manifest + Service Worker
        ['name' => 'manifest#index',       'url' => '/manifest.webmanifest', 'verb' => 'GET'],
        ['name' => 'service_worker#index', 'url' => '/sw.js',                'verb' => 'GET'],

        // Browser extension API (Pocket-compatible)
        ['name' => 'extension#add', 'url' => '/api/v1/add', 'verb' => 'POST'],
        ['name' => 'extension#get', 'url' => '/api/v1/get', 'verb' => 'POST'],
        ['name' => 'extension#modify', 'url' => '/api/v1/send', 'verb' => 'POST'],
    ]
];
