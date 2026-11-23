# Merlin Standalone

Eigener Merlin-Server ohne Nextcloud, auf Basis des gemeinsamen Kerns
(`../core`). Ersetzt [`merlin-standalone-server`](https://github.com/Dexxes/merlin-standalone-server)
(alte Instanzen werden nicht übernommen). Jedes Release bringt ihn als
Docker-Image `ghcr.io/dexxes/merlin-standalone` und als Tarball, mit derselben
Version wie die Nextcloud-App; Installation siehe INSTALLATION.md im Repo.
Es gibt Konten, Login, Login Flow v2, API-Tokens, die Admin-Nutzerverwaltung,
`/api/capabilities` und aus dem Kern die API der Nextcloud-App mit
denselben Pfaden und Antworten (ohne `/index.php/apps/merlin`): Artikel,
Schlagworte, Markierungen, Kommentare, Share-Links, Vorlesen, PDF, Medien,
Einstellungen, Löschfrist, Speicherverbrauch, Content-Filter,
Abo-Zugangsdaten, Meldungen und die Browser-Erweiterung (`/api/v1/…`). Nach
dem Login öffnet `/` die Weboberfläche (dieselbe Vue-App wie in Nextcloud),
Share-Links `/s/{token}` die öffentliche Artikelansicht, `/settings` die
eigenen Content-Filter und `/settings/admin` (nur Admins) Löschfrist und
Content-Filter der Instanz. Der Reader lässt sich als App installieren (PWA,
Manifest und Service-Worker wie in Nextcloud, noch ohne Offline-Cache).

Merlin Dateien (Uploads aus der iOS-Share-Extension) liegen unter
`files_path` (Vorgabe `data/files/`, im Docker-Image `/data/files`), je Konto
in den Ordnern Images, Videos, Audio, PDFs und Other files. Hochgeladen wird
über dieselben WebDAV-Pfade wie in Nextcloud (`/remote.php/dav/files/…`,
Chunked Upload unter `/remote.php/dav/uploads/…`), die Apps brauchen dafür
nichts Eigenes. Vorschaubilder baut der Server mit GD für JPEG, PNG, GIF und
WebP. Anders als in Nextcloud, wo Dateien in Files bleiben, löscht der
tägliche Lauf Dateien, deren Eintrag gelöscht wurde, nach einem Tag.
`tests/test-files.php` spielt Upload, Eintrag, Links, Umbenennen,
Chunked Upload, Vorschaubild und Aufräumen durch.

## Docker

Das Image enthält Weboberfläche, nginx und PHP-FPM; Datenbank, Log und das
Instanz-Geheimnis liegen im Volume `/data`. Beim Start laufen die Migrationen,
beim ersten Start legt der Container mit `MERLIN_ADMIN_*` ein Admin-Konto an,
und die täglichen Aufgaben ruft er stündlich selbst auf.

```sh
docker build -f standalone/Dockerfile -t merlin-standalone .    # aus dem Repo-Wurzelordner
docker run -d -p 8080:8080 -v merlin-data:/data \
  -e MERLIN_BASE_URL=https://merlin.example.com \
  -e MERLIN_ADMIN_USER=admin -e MERLIN_ADMIN_EMAIL=admin@example.org \
  -e MERLIN_ADMIN_PASSWORD=… merlin-standalone
```

Oder mit `standalone/docker-compose.yml`. Variablen: `MERLIN_BASE_URL`
(öffentliche Adresse, Pflicht hinter einem Reverse-Proxy), `MERLIN_BASE_PATH`,
`MERLIN_MAIL_FROM`, `MERLIN_MAIL_FROM_NAME`, `MERLIN_SECRET` (sonst erzeugt
der Container `/data/secret`), `MERLIN_LOG_LEVEL`, `MERLIN_PHP_WORKERS`
(Vorgabe 16), `MERLIN_TRUSTED_PROXIES` (IPs/CIDR des Reverse-Proxys, z. B.
`172.16.0.0/12`; erst damit zählen Rate-Limits und Login-Bremse je Client
statt gemeinsam für alle), `MERLIN_USER_QUOTA` (Speicherkontingent je
Konto für Merlin Dateien, Vorgabe `100M`, `0` = unbegrenzt) und
`MERLIN_SESSION_IDLE_TIMEOUT` (Abmeldung nach so vielen Sekunden ohne
Anfrage, Vorgabe `28800` = 8 Stunden). `MERLIN_SECRET` und die
`MERLIN_ADMIN_*`-Variablen nimmt der Container nach dem Start aus der
Umgebung von PHP und nginx; das Geheimnis steht dann in `/data/secret`. Wer mehr einstellen will, legt `/data/config.php` an (Vorlage
`config/config.sample.php`, Pfade auf `/data/…`); dann gelten die Variablen
nicht. Mails verschickt der Container ohne eigenen Mailserver nicht
(`mail()`), Passwort-Reset und die E-Mail-Bestätigung offener Registrierungen
brauchen deshalb einen eigenen Weg.
HTTPS übernimmt ein Reverse-Proxy davor. `docker/smoke-test.sh <url> <admin>
<passwort>` prüft einen laufenden Container.

`tools/assemble.sh <ziel>` baut dasselbe Paket ohne Docker (nach
`npm run build:standalone`), so entsteht der Release-Tarball.

## Ohne Docker

Tägliche Aufgaben (Löschfrist für archivierte Artikel, abgelaufene
Share-Links, Merlin Dateien ohne Eintrag) holt der Server ohne weitere Einrichtung beim Öffnen des
Readers nach, sobald der letzte Lauf 24 Stunden zurückliegt. Mit
`'background_jobs' => 'cron'` in `config.php` übernimmt das der System-Cron:

```sh
0 * * * * php /pfad/zu/standalone/bin/merlin cron   # arbeitet höchstens einmal am Tag
```

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
