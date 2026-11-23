# Merlin Standalone

Eigener Merlin-Server ohne Nextcloud, auf Basis des gemeinsamen Kerns
(`../core`). Ersetzt [`merlin-standalone-server`](https://github.com/Dexxes/merlin-standalone-server)
und wird hier neu aufgebaut. Noch nicht für den Einsatz gedacht: Es gibt
Konten, Login, Login Flow v2, API-Tokens, die Admin-Nutzerverwaltung,
`/api/capabilities` und aus dem Kern die API der Nextcloud-App mit
denselben Pfaden und Antworten (ohne `/index.php/apps/merlin`): Artikel,
Schlagworte, Markierungen, Kommentare, Share-Links, Vorlesen, PDF, Medien,
Einstellungen, Löschfrist, Speicherverbrauch, Content-Filter,
Abo-Zugangsdaten, Meldungen und die Browser-Erweiterung (`/api/v1/…`). Nach
dem Login öffnet `/` die Weboberfläche (dieselbe Vue-App wie in Nextcloud),
Share-Links `/s/{token}` die öffentliche Artikelansicht, `/settings` die
eigenen Content-Filter und `/settings/admin` (nur Admins) Löschfrist und
Content-Filter der Instanz. PWA/Offline folgt mit Etappe 6.

```sh
cd standalone
composer install
cp config/config.sample.php config/config.php   # anpassen, vor allem 'secret'
bin/merlin migrate                              # SQLite-Datenbank anlegen
bin/merlin create-admin --username=admin --email=admin@example.org
(cd ../frontend && npm install && npm run build:standalone)   # Weboberfläche nach public/app/
php -S localhost:8080 -t public public/index.php
bin/merlin extract https://netzpolitik.org/… --content   # Extraktion prüfen (JSON)
```

`bin/merlin extract <url> [--user=<id>] [--content]` läuft die Extraktion des
Kerns mit den Standalone-Adaptern (Content-Filter, Zugangsdaten) und gibt Titel,
Autoren, Bild, Paywall-Status und Kennzahlen zum Inhalt als JSON aus.
`tests/test-extraction.php` prüft offline Filter, Nutzer-Filter und abgelehnte
Seiten; mit `--live` vergleicht es echte Artikel-Links mit einer Referenz ohne
Standalone-Adapter (Titel, Autoren, Bild, Paywall, Inhaltslänge ±5 %).

`tests/test-article-api.php` spielt die Artikel- und Schlagwort-API über HTTP
durch; mit `--live [url …]` speichert es zusätzlich echte Artikel-Links über
`POST /api/articles` und wartet auf die Extraktion. Unter `php -S` kommt die
Antwort auf `POST /api/articles` erst nach der Extraktion (kein
`fastcgi_finish_request()`), unter PHP-FPM sofort.

Mit Apache zeigt der DocumentRoot auf `public/` (dort liegt die
`.htaccess`), oder `.htaccess` im Ordner `standalone/` leitet alles nach
`public/` weiter. Die Weboberfläche lädt `public/app/js/*.mjs` als
ES-Module: der Webserver muss `.mjs` als `text/javascript` ausliefern
(`public/.htaccess` setzt das für Apache, nginx braucht einen Eintrag in
`mime.types`).

Schreibende Anfragen, die nur mit dem Session-Cookie angemeldet sind,
brauchen den Header `X-Requested-With: XMLHttpRequest` (CSRF-Schutz,
`CsrfHeaderMiddleware`); mit API-Token (Basic Auth) nicht.

Vorerst nur SQLite. Das Schema der `merlin_*`-Tabellen ist das der
Nextcloud-App, siehe `migrations/001_initial.sql` und STRUCTURE.md im Repo.

Übernommen aus dem Altrepo (Stand `fe8ed23`, angepasst an die Ports und das
neue Schema): `src/Http/` (Request, Response, Router, Middleware),
`src/Auth/`, `src/Db/UserRepository.php`, `ApiTokenRepository.php`,
`PasswordResetRepository.php`, `LoginFlowRepository.php`, `ServerSettings.php`
(vorher `SettingsRepository.php`), `src/Controller/PageController.php`,
`AccountController.php`, `AdminController.php`, `LoginFlowController.php`,
`src/I18n/`, `src/Mail/`, `src/Logging/FileLogger.php`, die Templates für
Login, Registrierung, Passwort, Konto, Admin-Nutzer und Login-Flow,
`public/css/merlin.css`, `bin/merlin create-admin` (vorher
`tools/create-admin.php`). Ihre Historie steht im archivierten Altrepo.
