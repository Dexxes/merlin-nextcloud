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

        // HTML-Seite der öffentlichen Ansicht eines geteilten Artikels (ohne
        // Anmeldung). Entsperren, Daten, Vorlesen, PDF und Gast-Kommentare kommen aus dem Kern.
        ['name' => 'public_share#show',       'url' => '/s/{token}',              'verb' => 'GET'],
    ]
];
