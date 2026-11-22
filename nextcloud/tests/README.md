# Tests in einer echten Nextcloud

Die Testharnesses in `nextcloud/tools/` und `core/tests/` prüfen einzelne
Klassen ohne Nextcloud. Die Tests hier laufen gegen eine installierte
Nextcloud mit aktivierter Merlin-App, so wie sie im App-Store-Tarball
ausgeliefert wird. Sie gehören nicht in den Tarball (`export-ignore` in
`nextcloud/.gitattributes`).

| Verzeichnis | Was | Braucht |
|---|---|---|
| `Integration/` | läuft im Nextcloud-Prozess: jeder Port aus `Application::CORE_PORTS`, jede Klasse aus `CORE_SERVICES`, alle Controller, Hintergrundjob, occ-Befehl, Einstellungen, Listener und Middleware lassen sich aus dem App-Container bauen; der Kern kommt aus `vendor/merlin/core` der App; die Tabellen der Migrationen existieren | `NEXTCLOUD_ROOT` |
| `Api/` | HTTP wie die Apps und die Browser-Erweiterung (Basic Auth, JSON): Artikel speichern (mit mitgeschicktem HTML, also ohne Internet), Zustände, Schlagworte, Suche, Export, Löschen, Trennung zwischen Nutzern, Markierungen und Kommentare, Share-Links mit Gast-Kommentar und Passwort, Content-Filter speichern und anwenden, „Merlin Dateien“ per WebDAV, Einstellungen, Löschfrist, PWA | `MERLIN_TEST_BASE_URL` |
| `e2e/` | Playwright im Browser: anmelden, Artikel in der Liste sehen und öffnen, Share-Link abgemeldet öffnen, keine JavaScript-Fehler | `MERLIN_TEST_BASE_URL` |

Jede API-Testklasse legt eigene Nutzer an (`ci-<klasse>`, über die
OCS-Provisioning-API, vorher gelöscht). Ein zweiter Lauf gegen dieselbe
Instanz startet deshalb leer.

## In CI

`.github/workflows/nextcloud.yml` liest `appinfo/info.xml` und testet jede
unterstützte Nextcloud-Version mit der niedrigsten und höchsten PHP-Version,
die App und Nextcloud gemeinsam haben (`.github/scripts/nextcloud-matrix.py`),
dazu MariaDB und PostgreSQL mit der ältesten und neuesten Version und das
Docker-Setup des Repos mit Browser-Tests. Nachts kommt `master` von
nextcloud/server dazu (darf fehlschlagen). Für den Branch-Schutz genügt der
Check „Nextcloud tests passed“. Neue Versionen in `info.xml` landen
automatisch in der Matrix.

## Lokal

Mit dem Docker-Setup des Repos:

```sh
docker compose up --build -d --wait
cd nextcloud/tests
composer install
vendor/bin/phpunit -c phpunit.api.xml            # http://localhost:8080, admin/admin
cd e2e && npm install && npx playwright install chromium && npx playwright test
```

Mit einem Checkout von nextcloud/server (die Integrationstests brauchen den
Nextcloud-Code im selben Prozess):

```sh
git clone --depth 1 --branch stable35 --recurse-submodules --shallow-submodules \
    https://github.com/nextcloud/server.git ~/nc
(cd frontend && npm install && npm run build)
(cd nextcloud && make build-source && cp -r build/artifacts/merlin ~/nc/apps/merlin)
(cd ~/nc && php occ maintenance:install --database sqlite --admin-user admin --admin-pass admin \
    && php occ app:enable merlin)
(cd ~/nc && php -S localhost:8080 &)
cd nextcloud/tests && composer install
NEXTCLOUD_ROOT=~/nc vendor/bin/phpunit -c phpunit.integration.xml
vendor/bin/phpunit -c phpunit.api.xml
```

Umgebungsvariablen: `MERLIN_TEST_BASE_URL` (Standard `http://localhost:8080`),
`MERLIN_TEST_ADMIN_USER` und `MERLIN_TEST_ADMIN_PASSWORD` (Standard
`admin`/`admin`), `NEXTCLOUD_ROOT` (Server-Verzeichnis).
