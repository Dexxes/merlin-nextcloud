<?php

/**
 * Routen der Nextcloud-App. Die gemeinsame API (Artikel, Schlagworte,
 * Markierungen, Kommentare, Share-Links, Vorlesen, PDF, Medien,
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

        // Dateien vom Handy in „Merlin Dateien“ (FileController, Service\MerlinFileService):
        // Zielpfad für den WebDAV-Upload, Eintrag anlegen, Datei über signierten Link.
        ['name' => 'file#target',   'url' => '/api/files/target',       'verb' => 'POST'],
        ['name' => 'file#register', 'url' => '/api/files',              'verb' => 'POST'],
        ['name' => 'file#content',  'url' => '/api/articles/{id}/file', 'verb' => 'GET'],
        ['name' => 'file#rename',   'url' => '/api/articles/{id}/file-name', 'verb' => 'PUT'],

        // HTML-Seite der öffentlichen Ansicht eines geteilten Artikels (ohne
        // Anmeldung). Entsperren, Daten, Vorlesen, PDF und Gast-Kommentare kommen aus dem Kern.
        ['name' => 'public_share#show',       'url' => '/s/{token}',              'verb' => 'GET'],

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
