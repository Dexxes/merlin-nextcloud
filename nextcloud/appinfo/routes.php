<?php

/**
 * Routen der Nextcloud-App. Die gemeinsame API (Artikel, Schlagworte,
 * Markierungen, Kommentare, Share-Links, Vorlesen, PDF, Medien,
 * Einstellungen, Löschfrist, Content-Filter, Abo-Logins, Meldungen,
 * Browser-Erweiterung, /api/capabilities …) steht in core/src/Api/routes.php und wird hier
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
    ]
];
