# Merlin Standalone

Eigener Merlin-Server ohne Nextcloud, auf Basis des gemeinsamen Kerns
(`../core`). Ersetzt [`merlin-standalone-server`](https://github.com/Dexxes/merlin-standalone-server)
und wird hier neu aufgebaut. Noch nicht für den Einsatz gedacht: Es gibt
Konten, Login, Login Flow v2, API-Tokens, die Admin-Nutzerverwaltung und
`/api/capabilities`; die Artikel-API kommt mit Etappe 4, die Leseoberfläche
mit Etappe 5.

```sh
cd standalone
composer install
cp config/config.sample.php config/config.php   # anpassen, vor allem 'secret'
bin/merlin migrate                              # SQLite-Datenbank anlegen
bin/merlin create-admin --username=admin --email=admin@example.org
php -S localhost:8080 -t public public/index.php
bin/merlin extract https://netzpolitik.org/… --content   # Extraktion prüfen (JSON)
```

`bin/merlin extract <url> [--user=<id>] [--content]` läuft die Extraktion des
Kerns mit den Standalone-Adaptern (Content-Filter, Zugangsdaten) und gibt Titel,
Autoren, Bild, Paywall-Status und Kennzahlen zum Inhalt als JSON aus.
`tests/test-extraction.php` prüft offline Filter, Nutzer-Filter und abgelehnte
Seiten; mit `--live` vergleicht es echte Artikel-Links mit einer Referenz ohne
Standalone-Adapter (Titel, Autoren, Bild, Paywall, Inhaltslänge ±5 %).

Mit Apache zeigt der DocumentRoot auf `public/` (dort liegt die
`.htaccess`), oder `.htaccess` im Ordner `standalone/` leitet alles nach
`public/` weiter.

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
