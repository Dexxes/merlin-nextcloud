# Struktur – merlin-nextcloud

Zentrale Dateien für UI und Logik der Nextcloud-App.

## Repository-Layout

Das Repo wird schrittweise zum gemeinsamen Repo für die Nextcloud-App und den
Standalone-Server umgebaut. Aktueller Stand (Etappe 4 von 7, erster Teil):

```
merlin-nextcloud/
├── core/           # framework-freier PHP-Kern, Composer-Paket merlin/core, Namespace Merlin\Core\, PHP ≥ 8.4,
│                   # kein OCP: src/ContentFilter/, src/Extraction/ (Extraktor, Medien, Login, Social, SSRF),
│                   # src/Api/ (Handler + Routentabelle der gemeinsamen API), src/Persistence/ (SQL-Stores),
│                   # src/Port/ (Schnittstellen zu den Adaptern), resources/, tests/
├── content-filters/  # mitgelieferte Content-Filter, einzige Kopie für alle Varianten
├── nextcloud/      # die Nextcloud-App (App-ID merlin): appinfo/, lib/, templates/, l10n/, img/, css/,
│                   # tools/ (Testharnesses), tests/ (Tests in echter Nextcloud, siehe tests/README.md),
│                   # composer.json, Makefile (make dist); lib/Adapter/ implementiert die Ports
├── standalone/     # eigener Server ohne Nextcloud (Namespace Merlin\Standalone\, im Aufbau, Etappe 3):
│                   # migrations/ (SQLite, merlin_*-Tabellen im Nextcloud-Schema), src/, bin/merlin, tests/
├── tools/          # Prüfungen über beide Varianten: test-schema-parity.php, test-route-parity.php,
│                   # schema/ (Nextcloud-Schema ohne Nextcloud lesen)
├── frontend/       # Vue-3-Quellen (src/), fonts/, package.json, vite.config.mjs;
│                   # `npm run build` schreibt nach nextcloud/js und nextcloud/css
├── docker/, Dockerfile, docker-compose.yml   # Entwicklungs-Setup der Nextcloud-Variante
├── .github/workflows/ci.yml                  # PHP-Lint, Kern/Standalone ohne OCP, Testharnesses, Schema-Parität, Frontend-Build, Appstore-Tarball
├── .github/workflows/nextcloud.yml           # App in echter Nextcloud: alle Versionen aus info.xml × PHP, MariaDB/Postgres, Docker + Browser
└── README.md, CHANGELOG.md, LICENSE, NOTICE  # werden beim Release in den Tarball kopiert
```

`standalone/` ersetzt `merlin-standalone-server` und entsteht neu (keine
Übernahme der alten Historie oder Daten). Sein Schema ist das der
Nextcloud-App: `standalone/migrations/001_initial.sql` enthält dieselben
`merlin_*`-Tabellen, `user_id` ist Text (die Konto-ID als String). Dazu kommen
nur dort `users`, `api_tokens`, `password_reset_tokens`, `login_flow_tokens`,
`settings` und `user_settings`. `tools/test-schema-parity.php` liest das
Nextcloud-Schema aus `nextcloud/lib/Migration/` (gegen OCP-Stubs in
`tools/schema/`, nur `changeSchema()`) und vergleicht Tabellen, Spalten, Typen,
Nullbarkeit, Defaults und Indizes mit einer frisch migrierten SQLite-Datenbank.
Eine neue Nextcloud-Migration braucht deshalb immer auch eine SQL-Datei in
`standalone/migrations/`; Vorlage: `php tools/schema/print-sqlite-ddl.php <tabelle>`.
Vorerst nur SQLite, MySQL/MariaDB und PostgreSQL folgen später.

Persistenz im Kern: `core/src/Persistence/` enthält SQL-Implementierungen der
Store-Ports (`SqlCommentStore`, `SqlHighlightStore`, `SqlCommentGuestStore`,
`SqlShareStore`, `SqlSiteCredentialStore`, `SqlContentFilterStore`,
`SqlRetentionStore`, `SqlDatabase`, `SqlArticleStore`, `SqlTagStore`) gegen den Port `Connection` (benannte
Parameter, Tabellen als `*PREFIX*merlin_…`, portables SQL, Zeitpunkte als
`Y-m-d H:i:s` in UTC wie Nextclouds Entities). `RowMapper` überträgt Felder
der Domain-Objekte in Spalten, `PdoConnection` ist die PDO-Implementierung
(eindeutige Indizes → `DuplicateEntryException`). Der Standalone-Server nutzt
sie direkt. Die Nextcloud-App nutzt für Artikel und Schlagworte ebenfalls
`SqlArticleStore`/`SqlTagStore`, über `Adapter\NextcloudConnection`
(IDBConnection, Parametertypen aus dem PHP-Wert, damit LIMIT auf MySQL eine
Zahl ist); für die übrigen Tabellen bleibt sie vorerst bei ihren
Mapper-Adaptern. `Domain\Article` merkt sich wie ein Entity die gesetzten
Felder, `SqlArticleStore` schreibt beim Einfügen und Ändern nur diese Spalten
(sonst überschriebe z. B. das Speichern der Leseposition den Inhalt, den die
Extraktion gerade schreibt). Suche: `LOWER(spalte) LIKE LOWER(:term) ESCAPE '!'`
(`SqlValue::likeContains()`), läuft auf allen drei Datenbanken.
`core/tests/test-sql-stores.php` prüft die Stores gegen eine SQLite-Datenbank,
deren Schema aus den Nextcloud-Migrationen entsteht.

Standalone-Verdrahtung: `standalone/src/App.php` ist der Container. `App::PORTS`
ordnet jedem Port einen Adapter zu (`standalone/src/Adapter/`: Einstellungen in
`settings`/`user_settings`, `SodiumCrypto` mit dem Schlüssel aus `secret` in
config.php, Basis-URL, `$_SESSION`, Nutzernamen aus `users`, Content-Filter aus
`content-filters/`); Kern-Klassen baut er per Reflection wie Nextclouds
Autowiring. Die Ports für Merlin Dateien (`FilePreviews`, `StoredFile`) stehen in
`App::UNAVAILABLE_PORTS`. `standalone/tests/test-wiring.php` prüft, dass jeder
Port in einer der beiden Listen steht und alle Kern-Dienste baubar sind.

| Port | Standalone-Implementierung |
|---|---|
| `Connection` | `Merlin\Core\Persistence\PdoConnection` (SQLite über `Db\Sqlite::open()`) |
| `CommentStore`, `HighlightStore`, `CommentGuestStore`, `ShareStore`, `SiteCredentialStore`, `ContentFilterStore`, `RetentionStore`, `Database` | `Merlin\Core\Persistence\Sql…` |
| `AppSettings` | `Adapter\StandaloneAppSettings` (`settings`, Schlüssel mit Präfix `merlin.`) |
| `UserSettings` | `Adapter\StandaloneUserSettings` (`user_settings`) |
| `Crypto` | `Adapter\SodiumCrypto` (secretbox, Schlüssel aus `secret`) |
| `InstanceSecret` | `Adapter\ConfigInstanceSecret` (`secret` aus config.php) |
| `UrlGenerator` | `Adapter\StandaloneUrlGenerator` (`base_url`, Bilder unter `/img/`) |
| `Translator` | `Adapter\StandaloneTranslator` (vorerst Englisch mit Parametern) |
| `Session` | `Adapter\PhpSession` (`$_SESSION`) |
| `UserDirectory` | `Adapter\StandaloneUserDirectory` (Benutzername aus `users`) |
| `ContentFilterSource` | `Adapter\StandaloneContentFilterRepository` |
| `SiteSessions`, `ArticleDeletion` | `SiteCredentialService`, `ArticleDeletionService` aus dem Kern |
| `ArticleStore`, `TagStore` | `Merlin\Core\Persistence\SqlArticleStore`, `SqlTagStore` |
| `Scheduler` | `Merlin\Core\Service\ShutdownScheduler` |
| `Platform` | `Adapter\StandalonePlatform` (Version, Konten/Tokens/Login Flow) |
| `ArticleFiles` | `Adapter\NoArticleFiles` (keine Datei-Einträge) |
| `FilePreviews`, `StoredFile` | nicht verfügbar (Merlin Dateien folgt später) |

Gemeinsame API (Etappe 4): `core/src/Api/routes.php` ist die eine
Routentabelle (Name, Verb, Pfad, Handler, Zugriff `user`/`admin`/`public`).
Handler wie `Api\ArticleApi`, `Api\TagApi`, `Api\CapabilitiesApi`,
`Api\HighlightApi`, `Api\CommentApi`, `Api\ShareApi`, `Api\PublicShareApi`,
`Api\PublicCommentApi`, `Api\ArticleStreamApi` (Vorlesen, PDF) und
`Api\MediaApi` (Medienquellen, YouTube-Seite) bekommen ein
`ApiRequest` (Nutzer, Parameter aus Query, Body und Pfad, umgewandelt wie von
Nextclouds Dispatcher) und liefern ein `ApiResult` (JSON, Text mit eigenem
Content-Type, SSE-Stream oder `passthrough`: der Dienst schreibt die ganze
Antwort selbst, etwa MP3 vom Piper-Daemon oder die durchgereichte PDF). Nextcloud: `appinfo/routes.php` übernimmt die
Tabelle aus `vendor/merlin/core`, je Ressource ein dünner Controller
(`Controller\CoreApiController`) mit den Zugriffsattributen. Standalone:
`Http\Routes` registriert jede Zeile über `Http\CoreApi`, `user`-Routen hinter
`AuthMiddleware`. Extraktion nach der Antwort: `Service\ArticleExtractionService`
über den Port `Scheduler` (`Service\ShutdownScheduler` in beiden Varianten).
`/api/capabilities` meldet `Api\CapabilitiesApi::CORE_FEATURES` plus die Liste
des Ports `Platform` (was nur die Variante hat). Öffentliche Routen können ein
`rateLimit` [Anzahl, Sekunden] tragen: Nextcloud setzt dafür
`#[AnonRateLimit]`, Standalone `Http\Middleware\RateLimitMiddleware`. Das
Entsperren eines Share-Passworts bremst der Port `Throttler`
(`Adapter\NextcloudThrottler` über IThrottler, Standalone
`Adapter\SqlThrottler` mit der Tabelle `security_attempts`).
`tools/test-route-parity.php`
prüft Tabelle, Nextcloud-Routen und -Controller (Methode, Handler-Aufruf,
Attribute) und Standalone-Router; Routen nur einer Variante stehen dort in
`NEXTCLOUD_ONLY`, `NOT_YET_IN_CORE` (wird mit jedem Teil kürzer) oder
`STANDALONE_ONLY`.

Standalone-HTTP: `standalone/public/index.php` baut die Routen aus
`src/Http/Routes.php`: die gemeinsame API aus dem Kern, dazu die
Standalone-eigenen (Login, Registrierung, Passwort-Reset, Konto mit
API-Tokens, Admin-Nutzer, Login Flow v2 unter `/login/v2`, `/img/{datei}` aus
`nextcloud/img/`).
Pfade und Antworten wie in `merlin-standalone-server`, damit Clients mit
`backendKind: 'standalone'` sie unverändert finden. Die merlin_*-Daten eines
gelöschten Kontos räumt `Account\UserDataCleaner` ab (wie `UserDeletedListener`
in Nextcloud). `/api/capabilities` meldet `backend`, die gemeinsame `version`
(aus `nextcloud/appinfo/info.xml`), `apiVersion` und `features`; `files` fehlt
dort bewusst. `standalone/tests/test-article-api.php` spielt die Artikel- und
Schlagwort-API über HTTP durch (`--live`: echte Artikel-Links),
`standalone/tests/test-discussion-api.php` Markierungen, Kommentare,
Share-Links, Gast-Beiträge, Passwortbremse und Rate-Limit,
`standalone/tests/test-media-api.php` Vorlesen, PDF, Medienquellen und die
YouTube-Seite, auch hinter einem Share-Link. `standalone/tests/test-http.php` startet den eingebauten
PHP-Server und spielt Login Flow, Basic Auth, Admin-API und Kontolöschung
durch. `standalone/bin/merlin extract <url>` ruft die Kern-Extraktion mit den
Standalone-Adaptern auf; `standalone/tests/test-extraction.php --live` vergleicht
das für echte Artikel-Links mit einer Referenz ohne diese Adapter (ohne `--live`
nur offline, so läuft es in CI).

Die Nextcloud-App bindet den Kern über ein Composer-Path-Repository ein
(`nextcloud/composer.json`: `"merlin/core": "*@dev"` aus `../core`). Im Checkout
ist `nextcloud/vendor/merlin/core` ein Symlink, Änderungen im Kern wirken also
sofort; nur neue Klassen brauchen ein `composer dump-autoload` (die App nutzt
`classmap-authoritative`). Psr\Log stellt Nextcloud bereit, der Kern verlangt es
deshalb nicht selbst. Den Vendor-Autoloader lädt `Application::__construct()`.

Was der Kern von der Plattform braucht, steht als Schnittstelle in
`core/src/Port/`. Die Nextcloud-App bindet die Implementierungen in
`Application::register()` per `registerServiceAlias()` (Liste `CORE_PORTS`).
Kern-Klassen, die per DI gebaut werden, stehen in `Application::CORE_SERVICES`:
Nextcloud würde Klassen außerhalb von `OCA\Merlin` sonst im Server-Container
bauen, der die Ports nicht kennt. `tools/test-core-wiring.php` prüft, dass
beide Listen vollständig sind.

| Port | Nextcloud-Implementierung |
|---|---|
| `UrlGenerator` | `Adapter\NextcloudUrlGenerator` (IURLGenerator) |
| `ContentFilterSource` | `Service\ContentFilterRepository` (Unterklasse von `Merlin\Core\ContentFilter\ContentFilterRepository` mit den Bundle-Orten der App) |
| `ContentFilterStore` | `Adapter\NextcloudContentFilterStore` (`merlin_cfilter`) |
| `SiteSessions` | `Merlin\Core\Service\SiteCredentialService` (liegt selbst im Kern) |
| `SiteCredentialStore` | `Adapter\NextcloudSiteCredentialStore` (`merlin_site_credentials`) |
| `UserSettings` | `Adapter\NextcloudUserSettings` (IConfig, Nutzerwerte) |
| `AppSettings` | `Adapter\NextcloudAppSettings` (IAppConfig, App-Werte) |
| `Crypto` | `Adapter\NextcloudCrypto` (ICrypto mit dem Instanz-Secret) |
| `RetentionStore` | `Adapter\NextcloudRetentionStore` (ArticleMapper, ArticleShareMapper) |
| `ArticleDeletion` | `Merlin\Core\Service\ArticleDeletionService` (liegt selbst im Kern) |
| `Database` | `Adapter\NextcloudDatabase` (IDBConnection/QueryBuilder; Löschen über mehrere Tabellen) |
| `CommentStore`, `HighlightStore`, `CommentGuestStore`, `ShareStore` | `Merlin\Core\Persistence\SqlCommentStore`, `SqlHighlightStore`, `SqlCommentGuestStore`, `SqlShareStore` (über `Connection`; eindeutiger Index → `DuplicateEntryException`) |
| `Throttler` | `Adapter\NextcloudThrottler` (IThrottler, Brute-Force-Schutz beim Passwort eines Share-Links) |
| `Session` | `Adapter\NextcloudSession` (ISession) |
| `UserDirectory` | `Adapter\NextcloudUserDirectory` (IUserManager, Anzeigenamen) |
| `FilePreviews` | `Adapter\NextcloudFilePreviews` (IPreview) |
| `InstanceSecret` | `Adapter\NextcloudInstanceSecret` (Systemwert `secret`) |
| `Connection` | `Adapter\NextcloudConnection` (IDBConnection) |
| `ArticleStore`, `TagStore` | `Merlin\Core\Persistence\SqlArticleStore`, `SqlTagStore` (über `Connection`) |
| `ArticleFiles` | `Adapter\NextcloudArticleFiles` (Service\MerlinFileService) |
| `Scheduler` | `Merlin\Core\Service\ShutdownScheduler` |
| `Platform` | `Adapter\NextcloudPlatform` (App-Version, Funktionen außerhalb des Kerns) |

Nicht per DI: `StoredFile` (`Adapter\NextcloudStoredFile` um eine Datei in
Nextcloud Files) und `Translator` (`Adapter\NextcloudTranslator` um das IL10N
in der Sprache des Nutzers) baut `MerlinFileService` je Aufruf.

Kern-Services, die einen gespeicherten Artikel ausgeben (Export, PDF, Vorlesen,
Support-Box), bekommen ihn als `Merlin\Core\Domain\ArticleSnapshot`
(`Db\Article::toSnapshot()`), nicht als Nextcloud-Entity. Ebenso arbeitet
`SiteCredentialService` mit `Merlin\Core\Domain\SiteCredential`; der Adapter
`NextcloudSiteCredentialStore` übersetzt zum Entity `Db\SiteCredential`.
Kommentare, Markierungen, Gast-Farben und Share-Links sind im Kern
`Domain\Comment`, `Domain\Highlight`, `Domain\CommentGuest` und
`Domain\ArticleShare` (Getter, Setter und `jsonSerialize()` wie bei den
Entities) und werden über die SQL-Stores des Kerns gelesen und geschrieben.
Die Mapper in `Db/` bleiben für Aufräumen (Löschfrist, gelöschte Nutzer) und
Speicherplatz-Anzeige.

„Merlin Dateien“ ist geteilt: Ordner, Upload-Ziel, Registrieren, Umbenennen
und das Speichern des Eintrags bleiben in `Service\MerlinFileService` der App
(Nextcloud Files und WebDAV). Signierte Links, der Inhalt des Eintrags
(Datei-Marker, erkannter Text, Metadaten) und das Ausliefern mit Range stehen
im Kern (`Merlin\Core\Service\FileEntryService`, Eintrag als
`Domain\FileEntry`).

Release-Tarball: `cd nextcloud && make dist` (Frontend-Build, `git archive`
von `nextcloud/` und `core/`, `content-filters/` nach `merlin/content-filters/`,
`vendor/` ohne Dev-Abhängigkeiten, der Kern dabei kopiert statt verlinkt über
`COMPOSER_MIRROR_PATH_REPOS=1`). Das Docker-Image macht dasselbe.
`ContentFilterRepository::getBundleDir()` sucht die Bundle-Filter zuerst im
App-Verzeichnis (Tarball, Image), dann an der Repository-Wurzel (Checkout);
die Orte gibt `Service\ContentFilterRepository::bundleDirCandidates()` vor.

Migrationen bauen Kern-Klassen selbst (siehe Migration 000028): bei der
Installation laufen sie, bevor `Application::register()` die Kern-Klassen im
App-Container angemeldet hat.

## Backend (PHP)

```
nextcloud/
├── lib/
│   ├── Controller/          # HTTP-Controller
│   │   ├── CoreApiController.php             # Basis der dünnen Controller für Routen aus core/src/Api/routes.php
│   │   ├── ArticleController.php             # → Merlin\Core\Api\ArticleApi
│   │   ├── TagController.php                 # → Merlin\Core\Api\TagApi
│   │   ├── CapabilitiesController.php        # GET /api/capabilities → Merlin\Core\Api\CapabilitiesApi
│   │   ├── ShareController.php               # → Merlin\Core\Api\ShareApi
│   │   ├── PublicShareController.php          # unlock/data → PublicShareApi, tts/pdf → ArticleStreamApi; nur die HTML-Seite noch hier
│   │   ├── HighlightController.php            # → Merlin\Core\Api\HighlightApi
│   │   ├── CommentController.php              # → Merlin\Core\Api\CommentApi
│   │   ├── PublicCommentController.php        # → Merlin\Core\Api\PublicCommentApi (mit #[AnonRateLimit])
│   │   ├── TtsController.php              # → Merlin\Core\Api\ArticleStreamApi
│   │   ├── PdfController.php                  # → Merlin\Core\Api\ArticleStreamApi (Datei-Einträge über den Port ArticleFiles)
│   │   ├── FileController.php                 # Dateien vom Handy: Upload-Ziel, Eintrag anlegen, GET /api/articles/{id}/file (signierter Link)
│   │   ├── ExtensionController.php
│   │   ├── ManifestController.php             # PWA-Manifest
│   │   ├── ServiceWorkerController.php         # Liefert den Service-Worker (PWA)
│   │   ├── YoutubeEmbedController.php          # → Merlin\Core\Api\MediaApi (YouTube-Seite mit eigener CSP)
│   │   ├── MediaController.php                 # → Merlin\Core\Api\MediaApi
│   │   ├── VideoStreamController.php           # → Merlin\Core\Api\MediaApi (veraltet, nur HLS-Video)
│   │   ├── SettingsController.php             # GET/PUT /api/settings (inkl. Löschfrist des Nutzers, nur lesend: Admin-Maximum/effektive Frist)
│   │   ├── RetentionController.php            # POST /api/retention/notice: Löschfrist-Hinweis bestätigen
│   │   ├── RetentionAdminController.php       # Admin-API der Löschfrist (Maximum, Vorschau)
│   │   ├── ContentFilterController.php        # Admin-API für Content-Filter
│   │   ├── UserContentFilterController.php    # Personal-API: eigener Override
│   │   └── PageController.php
│   ├── Adapter/
│   │   ├── NextcloudUrlGenerator.php     # Port UrlGenerator über IURLGenerator
│   │   ├── NextcloudUserSettings.php     # Port UserSettings über IConfig (User-Values)
│   │   ├── NextcloudAppSettings.php      # Port AppSettings über IAppConfig
│   │   ├── NextcloudCrypto.php           # Port Crypto über ICrypto
│   │   ├── NextcloudRetentionStore.php   # Port RetentionStore über ArticleMapper/ArticleShareMapper
│   │   ├── NextcloudSiteCredentialStore.php # Port SiteCredentialStore über SiteCredentialMapper
│   │   ├── NextcloudThrottler.php        # Port Throttler über IThrottler (Passwort eines Share-Links)
│   │   ├── NextcloudSession.php          # Port Session über ISession
│   │   ├── NextcloudUserDirectory.php    # Port UserDirectory über IUserManager
│   │   ├── NextcloudContentFilterStore.php # Port ContentFilterStore (merlin_cfilter)
│   │   ├── NextcloudDatabase.php         # Port Database über IDBConnection/QueryBuilder
│   │   ├── NextcloudStoredFile.php / NextcloudFilePreviews.php  # Ports StoredFile (OCP-Datei) und FilePreviews (IPreview)
│   │   └── NextcloudTranslator.php / NextcloudInstanceSecret.php # Ports Translator (IL10N) und InstanceSecret (config.php)
│   ├── Service/              # Geschäftslogik
│   │   ├── ContentFilterRepository.php   # Kern-Repository mit Ablage und Bundle-Orten der App (Logik im Kern, siehe unten)
│   │   └── MerlinFileService.php         # „Merlin Dateien“: Ordner (lokalisiert, per ID gemerkt), Upload-Ziel, Einträge anlegen/umbenennen (Inhalt und Streamen im Kern)
│   ├── Settings/             # Verwaltungs- und persönliche Einstellungen
│   │   ├── AdminSection.php
│   │   ├── AdminSettings.php
│   │   ├── RetentionAdminSettings.php    # Admin: Löschfrist (eigener Abschnitt vor den Content-Filtern)
│   │   ├── PersonalSection.php
│   │   └── PersonalSettings.php
│   ├── Listener/
│   │   ├── AddContentSecurityPolicyListener.php
│   │   └── UserDeletedListener.php   # Räumt alle Merlin-Daten eines gelöschten Nutzers auf
│   ├── BackgroundJob/
│   │   └── RetentionCleanupJob.php   # Täglich: abgelaufene archivierte Artikel und alte Share-Links löschen
│   ├── Command/
│   │   └── RetentionRun.php          # occ merlin:retention:run [--user] [--dry-run]
│   ├── Db/                   # Datenbankschicht
│   │   ├── Article.php / ArticleMapper.php
│   │   ├── ArticleShare.php / ArticleShareMapper.php   # Öffentliche Share-Links (Token, Passwort, Ablauf)
│   │   ├── Highlight.php / HighlightMapper.php         # Textmarkierungen je Artikel
│   │   ├── Comment.php / CommentMapper.php             # Kommentar-Threads je Artikel
│   │   ├── Tag.php / TagMapper.php
│   │   └── SiteCredential.php / SiteCredentialMapper.php  # 🔜 geplant: verschlüsselte Paywall-Zugangsdaten je Nutzer/Domain
│   └── Migration/            # Datenbank-Migrationen (Version1000Date20240101000000 … 000032)
└── tools/
    ├── test-caption-credits.php       # Testharness: <images><caption credits-xpath> → <cite>
    ├── test-caption-flatten.php       # Testharness: Bildunterschriften einzeilig ("•")
    ├── test-media-providers.php       # Testharness Medien-Provider (--live: gegen echte Sender)
    ├── test-inline-media.php          # Testharness Inline-Videos (rbb24-ARD-Player, ganze Extraktion; braucht composer install)
    ├── test-pdf-article.php           # Testharness PDF-Artikel (URL-Erkennung, Marker, SSRF, Sanitizer)
    ├── test-core-wiring.php           # Testharness: Kern-Klassen und Ports in Application::CORE_SERVICES/CORE_PORTS vollständig
    └── test-site-icon.php             # Testharness ContentExtractorService::extractSiteIconUrl() (Apple > SVG > Bitmap > ICO, <base>, data:/javascript:, kein og:image)
```

```
core/                          # Composer-Paket merlin/core, Namespace Merlin\Core\
├── composer.json
├── resources/
│   └── url-shorteners.json           # bekannte Shortener/Tracker, vor dem Abruf aufgelöst
├── src/
│   ├── ContentFilter/
│   │   ├── ContentFilterSchema.php       # Grammatik der Filter-XML (eine Quelle)
│   │   ├── ContentFilterMerger.php       # Bundle-, Admin- und User-Filter vereinen
│   │   ├── ContentFilterRepository.php   # Bundle (Datei) + Admin-/User-Custom (Port ContentFilterStore), Entwürfe, Merge-Cache
│   │   ├── ContentFilterValidator.php    # Prüfung vor dem Speichern
│   │   ├── ContentFilterSerializer.php   # JSON ↔ XML für den Regel-Builder
│   │   └── ContentFilterTrace.php        # Trefferzähler für den Testlauf
│   ├── Extraction/
│   │   ├── ContentExtractorService.php   # Abruf, Readability, Content-Filter, Metadaten, Bilder, Paywall
│   │   ├── UnsupportedSiteException.php
│   │   ├── Http/SsrfSafeResolver.php     # SSRF-Schutz (Trait), auch für PDF-Proxy und Support-Box
│   │   ├── Media/                        # Audio/Video, siehe Abschnitt "Medien-Provider" unten
│   │   │   ├── MediaResolverService.php       # <media>-Sektion lesen, Provider aufrufen, Marker bauen/lesen
│   │   │   ├── MediaProviderRegistry.php      # type → Provider (einzige Registrierungsstelle)
│   │   │   ├── MediaSourceProviderInterface.php / DescriptionProviderInterface.php
│   │   │   ├── MediaContext.php / MediaResult.php / MediaHttpClient.php / VariantHelper.php
│   │   │   ├── InlineMediaService.php         # <media><inline>: Videos mitten im Text (ARD-Player) → Figure mit Vorschaubild + Quellen-Marker
│   │   │   └── Provider/                      # ard-mediathek, zdf, arte, 3sat, youtube-embed, xpath, json-ld
│   │   ├── Login/                        # Paywall-Abo-Login
│   │   │   ├── LoginProviderInterface.php     # login(username, password): Cookie-Bundle
│   │   │   ├── LoginHttpClient.php            # gemeinsamer SSRF-sicherer Request + Set-Cookie-Parsing
│   │   │   ├── LoginStatus.php                # Werte von last_login_status (ok, invalid_credentials, …)
│   │   │   ├── PianoJsonFormLoginProvider.php # type="piano-json-form" (z. B. tagesspiegel.de)
│   │   │   └── KeycloakFormPostLoginProvider.php # type="keycloak-form-post" (Funke-Keycloak, z. B. morgenpost.de)
│   │   └── Social/                       # Bluesky-Threads, Mastodon, TikTok
│   ├── Api/                          # gemeinsame API (Etappe 4)
│   │   ├── routes.php                    # die eine Routentabelle (Nextcloud und Standalone bauen ihre Routen daraus)
│   │   ├── ApiRequest.php / ApiResult.php # Eingabe (Nutzer, Parameter) und Antwort (JSON, Text, SSE)
│   │   ├── ArticleApi.php                # /api/articles…, /api/events
│   │   ├── TagApi.php                    # /api/tags…, Schlagworte an Artikeln
│   │   ├── HighlightApi.php / CommentApi.php  # Markierungen, Kommentare des Besitzers, Push-Kanal (SSE)
│   │   ├── ShareApi.php                  # Share-Link verwalten (erstellen, Passwort, Ablauf, neuer Token)
│   │   ├── PublicShareApi.php            # Entsperren (Throttler) und Artikeldaten hinter dem Link
│   │   ├── PublicCommentApi.php          # Gast-Kommentare/-Markierungen, Honeypot, Gast-Push-Kanal
│   │   ├── ArticleStreamApi.php          # Vorlesen und PDF, für den Besitzer und hinter dem Link
│   │   ├── MediaApi.php                  # Audio-/Videoquellen, altes /video-stream, YouTube-Seite
│   │   └── CapabilitiesApi.php           # /api/capabilities
│   ├── Persistence/                  # SQL-Stores gegen den Port Connection (Sql…Store, PdoConnection, RowMapper, SqlValue)
│   ├── Domain/
│   │   ├── Article.php / Tag.php         # Artikel (mit Änderungsliste wie ein Entity) und Schlagwort, Wire-Format per jsonSerialize()
│   │   ├── ArticleSnapshot.php           # Artikelfelder für Export, PDF, Vorlesen, Support-Box
│   │   ├── SiteCredential.php            # Paywall-Zugangsdaten (verschlüsselt) eines Nutzers für eine Domain
│   │   ├── Comment.php / Highlight.php / CommentGuest.php  # Kommentar, Markierung, Gast-Farbe (Wire-Format per jsonSerialize())
│   │   ├── ArticleShare.php              # Share-Link für die Zugriffsprüfung (Ablauf, Passwort, Gast-Kommentare erlaubt?)
│   │   └── FileEntry.php / FilePreview.php  # Datei-Eintrag (ID, Datei, Besitzer, erkannter Text), Vorschaubild
│   ├── Service/
│   │   ├── SupportBoxService.php         # Daten der Support-Infobox (<paywall><subscribe> + <metadata><donations>, Seiten-Icon, Akzentfarbe); Reader: entfällt bei aktivem Abo-Login, Share: immer
│   │   ├── PdfProxyService.php           # PDF-Durchreichung für Api\ArticleStreamApi (eigener und geteilter Artikel) (SSRF-Guard je Hop, %PDF-Prüfung, Range, 100-MB-Limit)
│   │   ├── TtsStreamService.php          # gemeinsamer Stream-Pfad für authentifizierten und öffentlichen (Share-)Endpunkt
│   │   ├── ExportService.php             # Artikel als eigenständiges HTML
│   │   ├── ReportBackendService.php / ReportBackendRules.php  # „Fehlerhaft dargestellt melden“
│   │   ├── ArticleExtractionService.php  # Inhalt nach der Antwort holen (Port Scheduler), Paywall-/Unsupported-Zustände
│   │   ├── ShutdownScheduler.php         # Scheduler-Port: register_shutdown_function + fastcgi_finish_request
│   │   ├── ArticleDeletionService.php    # Einziger Löschweg für Artikel (Port Database): mit Highlights, Kommentaren, Tag-Zuordnungen, Shares; Nutzerlöschung; Waisen
│   │   ├── CommentService.php            # Kommentare/Gast-Markierungen anlegen, ändern, löschen; SSE-Schleife
│   │   ├── ShareAccessService.php / ShareAccessDenied.php  # Share-Token + Passwort-Unlock prüfen (Share-Ansicht und Gast-Kommentare)
│   │   ├── CommentRules.php / CommentException.php  # Reine Regeln: Namen, Längen, Thread-Position, Gast-Rechte
│   │   ├── RetentionService.php          # Löschfrist: Admin-/Nutzerwerte, Lauf, Vorschau, Hinweis
│   │   ├── SiteCredentialService.php     # Paywall-Zugangsdaten je Nutzer/Domain (Port SiteSessions): Ablage, Login, Cookie-Cache
│   │   ├── RetentionPolicy.php           # Reine Rechenregeln der Löschfrist (Minimum, Stichtag, Hinweis fällig?)
│   │   ├── TagTree.php                   # Reine Baumregeln verschachtelter Tags (Nachfahren, Kreisprüfung beim Verschieben)
│   │   ├── FileEntryService.php          # Datei-Einträge: signierte Links, Inhalt (Marker, Text, Metadaten), Ausliefern mit Range/Vorschaubild
│   │   ├── FileRules.php                 # „Merlin Dateien“: Dateiart/Kategorie, Dateinamen, signierte Links, Range, Dateigröße
│   │   └── FileMetadata.php              # Eingebettete Metadaten einer Datei (EXIF, GPS, IPTC, XMP, ID3, MP4/MOV, PDF) für die Anzeige im Reader
│   └── Port/                         # UrlGenerator, ContentFilterSource, SiteSessions, SiteCredentialStore,
│                                     # UserSettings, AppSettings, Crypto, RetentionStore, ArticleDeletion,
│                                     # CommentStore, HighlightStore, CommentGuestStore (DuplicateEntryException),
│                                     # ShareStore, Session, UserDirectory, ContentFilterStore, Database,
│                                     # StoredFile, FilePreviews, Translator, InstanceSecret
└── tests/                            # Testharnesses (pures PHP, ohne Composer; autoload.php lädt Merlin\Core\)
    ├── test-content-filter-merge.php
    ├── test-support-box.php           # SupportBoxService (URL-Auswahl, Login-Ausblendung, Share, Seiten-Icon)
    ├── test-pdf-proxy.php             # PdfProxyService (lokaler Quellserver: Range, Redirect, Nicht-PDF, Größe, SSRF)
    ├── test-retention.php             # RetentionPolicy (effektive Frist, Stichtag, Hinweis)
    ├── test-retention-service.php     # RetentionService (Einstellungen, Vorschau, Trockenlauf, Lauf, Share-Karenz)
    ├── test-site-credentials.php      # SiteCredentialService (Login-Config aus dem Bundle, Cookie-Cache, Status, Löschen)
    ├── test-comment-service.php       # CommentService + ShareAccessService (Threads, Gast-Farben, Markierungen, Share-Zugriff)
    ├── test-content-filter-repository.php # ContentFilterRepository (Bundle, Ebenen, Entwürfe, Upsert) + ArticleDeletionService
    ├── test-tag-tree.php              # TagTree (Nachfahren, Kreisprüfung)
    ├── test-file-rules.php            # FileRules (Dateiart, Namen, Token, Range)
    ├── test-file-entries.php          # FileEntryService (Links, Inhalt je Dateiart, Ausliefern: Status, Header, Range, 404/416)
    ├── test-file-metadata.php         # FileMetadata (fixtures/metadata/)
    ├── test-comments.php              # CommentRules
    └── test-report-backend.php        # ReportBackendRules

content-filters/              # Mitgelieferte Filter, eine Datei je Domain (~55 Domains, z. B. spiegel.de, zeit.de, taz.de, youtube.com)
├── $sample.com.xml           # Kommentierte Referenz aller Regeltypen
├── 000dead.xml               # Parkliste toter Domains (kein gültiges XML)
├── $unsupported.xml          # Domains, die grundsätzlich nicht gescrapt werden (siehe UnsupportedSiteException)
└── $caption-credits.xml      # Signalwörter für die Bildquelle in Captions ("Foto:", "Quelle:", …)
```

Hinweis: `FeedController`/`FeedService`/`Feed(Mapper)` aus einer früheren Version existieren nicht mehr.

### Content-Filter-Kette

Drei Ebenen pro Domain verschmelzen zu einer Config (Bundle < Admin-Custom <
User-Custom): das Bundle bleibt eine Datei unter `content-filters/{domain}.xml`,
Admin- und User-Custom liegen in der DB-Tabelle `merlin_cfilter` (Spalte `scope`
unterscheidet `'admin'`/`'user'`, siehe Migration `Version1000Date20240101000020`).

```
ContentExtractorService::loadDomainConfig($domain)   [$currentUserId als Instanzfeld]
  └─ ContentFilterRepository::getMerged($domain, $userId)   [Request-Cache je (domain,userId)]
       ├─ mergeBundleAndAdmin(): ContentFilterMerger::merge($bundle, $adminCustom, …, ORIGIN_ADMIN)
       └─ mergeWithUser():       ContentFilterMerger::merge($withAdmin, $userCustom, …, ORIGIN_USER)
            ├─ <disable> zuerst: Regeln der jeweils darunterliegenden Ebene abschalten
            ├─ Listen additiv (pre-/post-filter, quotes, images)
            ├─ fetch/@name und json/@id ersetzen
            └─ metadata-Felder und category: jeweils höhere Ebene gewinnt
```

Alle acht Aufrufstellen von `loadDomainConfig()` arbeiten unverändert weiter, weil
der Merger wieder ein `SimpleXMLElement` liefert. Die Grammatik steht ausschliesslich
in `ContentFilterSchema` – Validator, Serializer, Merger und die Vue-Builder leiten
sich daraus ab. Das Herkunftsattribut (`data-merlin-origin`) kennt seit der
Drei-Ebenen-Erweiterung drei Werte (`bundle`/`admin`/`user`) statt zwei.

### Medien-Provider (Audio/Video)

Welche Audio-/Video-Quelle eine Domain hat, steht deklarativ in der
`<media>`-Sektion ihres Content-Filters (Schema: `ContentFilterSchema`,
Merge wie alle anderen Sektionen, `<source>` je `type`). Die Logik je
Quellen-Typ liegt in `core/src/Extraction/Media/Provider/`:

```
<media>
  <source type="xpath" kind="audio" xpath="…" host-allow="dradio.de" />
  <description xpath="//meta[@property='og:description']/@content" />
</media>

Speichern:  ContentExtractorService Step 2a
              └─ MediaResolverService::resolveOnSave(url, config, rawHtml)
                   ├─ stabile Quelle (xpath, json-ld, youtube-embed) → Marker MIT URL
                   └─ Mediathek (resolvesPerRequest(): ard, zdf, arte) → Marker OHNE URL
            Step 11b: <div class="merlin-media" data-media-kind data-media-delivery data-media-src>
            (sanitizeMediaMarker() prüft die Attribute, Embeds gegen isAllowedVideoEmbedSrc())
Öffnen:     MediaPlayer.vue
              ├─ Marker mit URL → direkt abspielen (auch in der öffentlichen Share-Ansicht)
              └─ sonst GET /api/articles/{id}/media → resolveOnRequest() → Provider ohne HTML
```

Kategorien: `Video`/`Audio` (feste `<category>`, reine Medienseite, kein
Readability, Content = Marker + `<description>`), `Mixed` (automatisch, wenn
eine Quelle gefunden wurde und die Domain keine feste Kategorie hat; unter 80
Wörtern Text stattdessen `Audio`/`Video`).

Neuer Sender: reichen `xpath` oder `json-ld`, genügt der XML-Eintrag. Sonst
einen Provider anlegen und in `MediaProviderRegistry` sowie
`ContentFilterSchema::MEDIA_SOURCE_TYPES` eintragen
(`tools/test-media-providers.php` prüft, dass beide übereinstimmen).

### Seiten-Icon der Support-Infobox

`ContentExtractorService::extractSiteIconUrl()` liest in `processHtml()` (also auch bei
`extractFromHtml()`, d. h. Browser-Erweiterungen) aus dem rohen HTML das beste Icon der
*konkreten Seite*: `apple-touch-icon` (größtes per `sizes`) > `<link rel="icon">` (SVG >
Bitmap > ICO, jeweils größtes) > `msapplication-TileImage` > `/favicon.ico` der Origin.
`<base href>` wird berücksichtigt, `mask-icon` (einfarbige Silhouette) und
`data:`/`javascript:`-URLs werden ignoriert, `og:image` bewusst nie genommen (meist ein
Artikelbanner). Es findet kein zusätzlicher Request statt. Das Ergebnis liegt in
`merlin_articles.site_icon_url` (Migration `…000025`), steht bewusst NICHT in
`Article::jsonSerialize()` (Listen bleiben schlank) und kommt nur als `supportBox.iconUrl` aus
`SupportBoxService`. Artikel aus der Zeit vor der Spalte fallen dort auf `/favicon.ico` der
Origin zurück; ein erneutes Extrahieren (`retryExtraction`) setzt das echte Icon. Die Clients
laden das Icon direkt von der Quellseite (`referrerpolicy=no-referrer`, wie Artikelbilder) und
blenden es bei einem Ladefehler aus.
### Autor und Autorenprofil

`extractDomainMetadata()` ermittelt die Namen wie gehabt (Domain-`<author>`-Regel >
`article:author` > JSON-LD); `resolveAuthorMetadata()` ergänzt danach domainübergreifend:
Fehlen sie, greifen `GENERIC_AUTHOR_XPATHS` (WordPress-Blöcke
`wp-block-post-author-name`/`__link`/`wp-block-post-author__name`, schema.org-Microdata
`itemprop="author"`, hCard `.author.vcard .fn`, `a[rel=author]`; erster Ausdruck mit
höchstens vier verschiedenen Namen gewinnt, "Von"/"By"-Präfixe werden entfernt). Werte, die
URLs sind (typisch `article:author` = Facebook-Profil), gelten nie als Name. JSON-Pfade
kennen `[*]` (alle Elemente, `resolveJsonPathValues()`).

Profil-Links werden **je Autor** bestimmt: `<author-link>`-Regel (`extractConfiguredAuthorLinks()`;
gleich viele Links wie Autoren → der Reihe nach, bei einem Autor der erste) > `<a href>` am
Treffer der `<author>`-Regel (Knoten, Vorfahre oder Link darin) > JSON-LD `author[].url`
(auch über `@id`-Verweise im `@graph`) mit gleichem Namen > generisches Signal mit gleichem
Namen > bei genau einem Autor eine URL aus `article:author`. Nur http(s)-Links, nie die
Artikel-URL selbst. Gespeichert als JSON-Liste `[{name, url}]` in `merlin_articles.authors`
(Migration `…000027`, nur wenn mindestens ein Link gefunden wurde) und bei genau einem Autor
zusätzlich in `merlin_articles.author_url` (Migration `…000026`). Tests:
`tools/test-author-detection.php`.

### PDF-Artikel

Eine URL, die auf eine PDF zeigt, wird als Artikel mit `category='PDF'` gespeichert,
ohne die Datei zu laden oder abzulegen:

```
ContentExtractorService::extract()
  ├─ isPdfUrl()          Pfad endet auf .pdf  → buildPdfResult() (kein Abruf)
  └─ fetchUrl()          Fortschritts-Callback bricht bei Content-Type
                         application/pdf ab   → buildPdfResult()
buildPdfResult(): SSRF-Prüfung des Hosts, Titel aus dem Dateinamen,
  content = <div class="merlin-pdf" data-pdf-src="…"> + Fallback-Link
```

`data-pdf-src` wird im Sanitizer nur für http(s)-URLs durchgelassen. Der Web-Reader und die
Share-Ansicht zeigen `PdfViewer.vue` (pdf.js, per dynamischem Import; der Worker liegt dank
`worker.rollupOptions` in `js/`): Die PDF kommt nicht direkt vom Quellserver (CORS/X-Frame-Options),
sondern über `PdfProxyService` (`/api/articles/{id}/pdf`, `/s/{token}/pdf`), der pro Request
durchreicht: nur die gespeicherte Artikel-URL, SSRF-Guard je Hop, `%PDF-`-Prüfung, 100-MB-Limit,
Range-Weitergabe für pdf.js, gehärtete Antwort (`nosniff`, `CSP: sandbox`). Schlägt das fehl,
erscheint `PdfCard.vue`. iOS/Android rendern die PDF nativ aus der Quell-URL. Nebenbei
begrenzt der HTML-Abruf den Body jetzt auf 20 MB (`MAX_BODY_BYTES`).

### Markierungen in PDFs

In PDFs gibt es keinen XPath: eine Markierung hat `pdf_page` (ab 1) und `pdf_rects`
(JSON `[[x, y, w, h], …]`, Migration …000034), normiert auf 0..1 bezogen auf die unrotierte
Seite (CropBox), Ursprung oben links. Rechtecke statt Zeichenpositionen, weil pdf.js und
PDFKit den Seitentext unterschiedlich zerlegen. `start_xpath`/`end_xpath` tragen den
Platzhalter `pdf[1]/page[N]` (`CommentRules::pdfXpath()`), Offsets 0: Spalten und Prüfung
bleiben, ältere Clients finden den Pfad nicht und überspringen die Markierung.

```
POST /api/articles/{id}/highlights {highlightedText, pdfPage, pdfRects, color}
POST /api/articles/{id}/comments   {body, anchor: {highlightedText, pdfPage, pdfRects}}
     CommentRules::normalizePdfRects(): Liste, höchstens 200 Rechtecke, Werte 0..1,
     Breite/Höhe > 0, auf 5 Stellen gerundet; Seite 1..100000. Gäste: pdf-Felder werden ignoriert
```

Web: `PdfViewer.vue` legt je gerenderter Seite in `.pdf-viewer__sheet` den Text-Layer von
pdf.js (`TextLayer`, auswählbar) und darunter `.merlin-pdf-hl-layer` an, beide unrotiert in
Seitengröße (`setLayerDimensions`, `--scale-factor`, gedreht per `data-main-rotation`). Steht
die Ebene, meldet die Seite `merlin-pdf-page`; die `HighlightEngine` zeichnet dann die
Rechtecke (`pdf-highlights.js`) als `div.merlin-highlight--pdf`. Klicks auf Markierungen
werden per Koordinaten erkannt, weil die Ebene unter dem Text-Layer liegt. In der
Share-Ansicht liegt die PDF außerhalb von `bodyEl`; dort zeigt eine zweite Engine mit
`pdfReadOnly` die Markierungen nur an (Gäste markieren in PDFs nicht).

### Dateien vom Handy („Merlin Dateien“)

Dateien (Bilder, Videos, Audios, PDFs, Sonstiges), die über die iOS-Share-Extension
gespeichert werden, landen im Nextcloud-Ordner „Merlin Dateien“ und erscheinen
zugleich als Eintrag in der Leseliste:

```
POST /api/files/target {name, mimeType}  → MerlinFileService::uploadTarget()
     Ordner „Merlin Dateien“/<Art> in der Nextcloud-Sprache des Nutzers, IDs in
     den Nutzereinstellungen (merlin/files_folder_ids), freier Dateiname
     Antwort {path, davPath, uploadsPath, kind}
Client: PUT davPath bzw. Chunked Upload v2 unter uploadsPath (große Videos)
POST /api/files {path, tagIds[]}          → MerlinFileService::register()
     Artikel mit file_id/file_mime (Migration …000032), url = /f/{fileId},
     category Image/Video/Audio/PDF/File, content mit denselben Markern wie
     Web-Artikel (Figure, div.merlin-media delivery=file, div.merlin-pdf)
GET /api/articles/{id}/file?t=…[&size=N][&download=1]
     ohne Login, Token = HMAC(Eintrag, Datei, Besitzer) (FileRules::sign),
     Range, gehärtet (nosniff, CSP sandbox, nur Medien/PDF inline)
```

In der Liste stehen Datei-Einträge nur im Tab „Dateien“ (`contentType=file`,
`file_id IS NOT NULL`; Zähler `files` in getCounts()), nicht zusätzlich unter
Text/Video/Audio. Fotos komprimiert die iOS-Share-Extension auf Wunsch vor dem Upload.

Unter der Datei steht ein Abschnitt „Metadaten“ (`section.merlin-file-metadata`,
je Gruppe `<details open>` mit Tabelle), gebaut beim Anlegen bzw. bei „Neu laden“
(MerlinFileService::refresh(), gebaut von FileEntryService::render()). `Service\FileMetadata` liest ohne Abhängigkeiten aus
dem Datei-Stream (Kopf, Ende, gezielte Sprünge): EXIF/GPS (ext-exif), IPTC, XMP,
ID3v2/ID3v1, QuickTime/MP4 (mvhd, tkhd, ilst, keys/mdta, udta ©xyz) und das
PDF-Info-Wörterbuch. Test: `php core/tests/test-file-metadata.php`.

PUT /api/articles/{id}/file-name {name} → MerlinFileService::rename(): benennt die
Datei im selben Ordner um (Endung bleibt, FileRules::renamedName), 409 bei
vorhandenem Namen; Web: src/file-rename.js. Der Titel folgt immer dem Dateinamen;
show() frischt Datei-Einträge auf, wenn der Name in Nextcloud geändert wurde
(needsRefresh). Erkannter Text aus der iOS-Share-Extension (`text` bei POST
/api/files) steht in `file_text` (Migration …000033), als
`section.merlin-file-text` im Inhalt und wird von der Suche erfasst.

Die Datei geht bewusst nicht durch die Merlin-API (PHP-Uploadgrenzen). Löschen des
Eintrags (auch per Löschfrist) lässt die Datei in Nextcloud liegen und macht nur die
signierten Links ungültig. `/api/articles/{id}/pdf` und `/s/{token}/pdf` liefern
PDF-Dateieinträge direkt aus Nextcloud.

### Titel-Duplikat-Heuristik (`stripDuplicateMetadata()`)

Nach Readability-Extraktion + domänenspezifischem Nachfilter läuft
`ContentExtractorService::stripDuplicateMetadata()` (Zeile 3923) über den
extrahierten Inhalt, um CMS-Bugs abzufangen, bei denen der Seitentitel
zusätzlich als Überschrift im Artikeltext steht (z. B. taz.de).

Pass 1 (Zeile 3944 ff.): Von den ersten 5 Überschriften (`h1`–`h4`) im Body
wird die erste gelöscht, deren normalisierte Wörter zu ≥ 70 % im Artikeltitel
vorkommen (`normalizeForComparison()`, Zeile 4006; Schwelle Zeile 3963), danach
`break` (max. eine Entfernung). Pass 1b (Zeile 3973) macht dasselbe für einen
führenden `<p>` statt `<h*>`.

**Bekannte Fehlerquelle:** Bei FAQ-Artikeln, deren erste Zwischenüberschrift
naturgemäß das Titel-Vokabular wiederholt (z. B. mdr.de,
`faq-wahlen-prognose-hochrechnung-100.html`: Titel und erste Frage teilen sich
"ARD", "ZDF", "18 Uhr", "wissen", "Wahl"), reißt der reine Wort-Overlap die
70 %-Schwelle und die erste – inhaltlich echte – Zwischenüberschrift wird
fälschlich als Titel-Dopplung entfernt. Fix noch offen: engere Ähnlichkeit
(z. B. nahezu exakter Textvergleich statt Wort-Overlap) und/oder Beschränkung
auf die tatsächlich erste Content-Node statt Scan der ersten 5 Überschriften.

### Paywall-Abo-Login (🔜 geplant)

Damit `ContentExtractorService` auch Artikel hinter einer Abo-Paywall (z. B. Tagesspiegel Plus)
extrahieren kann, bekommt die content-filter-XML eine neue optionale `<login>`-Sektion pro Domain:

```
<login type="piano-json-form" page="https://mein.tagesspiegel.de/customer/login">
  <ajax-endpoint url="https://mein.tagesspiegel.de/ajax/login" />
  <persist-cookie name="sso_token" />
  <persist-cookie name="sso_user_data" />
  <persist-cookie name="authId" />
  <persist-cookie name="__utp" />
</login>
```

Für Seiten, die über einen Keycloak mit OpenID-Connect-form_post einloggen (Funke-Titel wie
morgenpost.de), gibt es `type="keycloak-form-post"` mit `<authorize-endpoint url client-id>` und
`<redirect-endpoint url>` statt `<ajax-endpoint>`, siehe `content-filters/morgenpost.de.xml`.

`type` wählt eine `core/src/Extraction/Login/*LoginProvider`-Implementierung (Whitelist analog
`FETCH_HEADER_WHITELIST`); `page` dient sowohl als Einstiegs-URL für den CSRF-Cookie-Schritt als
auch als UI-Link ("Zugangsdaten bei Tagesspiegel prüfen"). Nutzername/Passwort werden verschlüsselt
pro Nutzer/Domain in `Db\SiteCredential` abgelegt (`OCP\Security\ICrypto`), der gewonnene
Session-Cookie hält bei Tagesspiegel ca. 365 Tage und wird über `loadFetchOverrides()` in den
bestehenden Fetch-Pfad injiziert.

**UX-Anforderung:** Erkennt `ContentExtractorService` beim erstmaligen Speichern eines
Paywall-Artikels einer Domain ohne (gültige) Zugangsdaten die Paywall weiterhin, muss
`ArticleController` das als eindeutige "Login erforderlich"-Antwort signalisieren statt als
stillen Extraktions-Fehlschlag – Grundlage für einen Login-Dialog in allen Clients (siehe
`PLATFORMS.md`, Abschnitt 3/4 – die Client-seitige Umsetzung ist ein eigener Folgeschritt).

## Frontend (Vue 3)

Vier Vite-Entry-Points (siehe `vite.config.mjs`): Reader, öffentliche Share-Ansicht,
Verwaltungseinstellungen und persönliche Einstellungen.

```
frontend/src/
├── main.js                  # Einstiegspunkt Reader
├── public-main.js           # Einstiegspunkt öffentliche Share-Ansicht
├── admin-main.js            # Einstiegspunkt Verwaltungseinstellungen
├── personal-main.js         # Einstiegspunkt persönliche Einstellungen
├── support-box.js / .css    # Support-Infobox (Abo-/Spendenlink, Seiten-Icon als eigene Spalte über die volle Boxhöhe) zur Lesezeit zwischen zwei Absätze setzen (data-hl-exclude); hideBrokenSupportBoxIcons() entfernt nicht ladbare Icons nach dem Rendern
├── inline-media.js / .css  # Legt auf jede figure.merlin-inline-media (Video mitten im Text) einen MediaPlayer (data-hl-exclude), idempotent nach jedem Rendern
├── highlight-engine.js      # Framework-unabhängige Logik zum Setzen/Wiederfinden von Textmarkierungen im DOM
├── pdf-highlights.js        # Markierungen in PDFs: Seite + normierte Rechtecke aus der Auswahl im Text-Layer, Zeichnen in .merlin-pdf-hl-layer
├── comment-session.js       # Kommentar-Zustand + SSE-Verbindung
├── tag-tree.js              # Verschachtelte Tags im Browser: Kinder, Nachfahren, Baumreihenfolge, Pfad (Gegenstück zu TagTree.php)
├── App.vue                  # Hauptkomponente
├── store/
│   └── index.js             # State Management
├── api/                     # API-Wrapper
│   ├── articles.js
│   ├── tags.js
│   ├── shares.js                 # Share-Link-Endpunkte (/api/articles/{id}/shares)
│   ├── highlights.js             # Highlight-Endpunkte (/api/articles/{id}/highlights)
│   ├── settings.js
│   ├── contentFilters.js         # Admin-Endpunkte (/api/admin/content-filters)
│   └── userContentFilters.js     # Personal-Endpunkte (/api/user/content-filters)
└── components/
    ├── ArticleList.vue
    ├── ArticleCard.vue
    ├── ArticleReader.vue
    ├── AddArticleDialog.vue
    ├── Sidebar.vue                # Navigation; Tags als aufklappbarer Baum
    ├── MoveTagDialog.vue          # „Verschieben nach…“: neuen Eltern-Tag wählen
    ├── DeleteTagDialog.vue        # Rückfrage vor dem Löschen, nennt die mitgelöschten Unter-Tags
    ├── ShareLinkDialog.vue        # Dialog zum Anlegen/Verwalten von Share-Links
    ├── PublicArticleView.vue      # Ansicht für öffentliche Share-Links (public-main.js)
    ├── MediaPlayer.vue            # Audio-/Video-Player (HLS, Datei, Embed), siehe "Medien-Provider"
    ├── PdfViewer.vue              # Eingebettete PDF-Vorschau (pdf.js, lazy) für PDF-Artikel; lädt über den PdfProxyService-Endpunkt; Text-Layer + Markierungs-Ebene je Seite
    ├── PdfCard.vue                # Fallback-Karte „PDF öffnen“, wenn die Vorschau nicht lädt
    ├── Settings.vue
    ├── SettingsPreview.vue
    ├── admin/               # Content-Filter-Verwaltung (instanzweit)
    │   ├── ContentFilterAdmin.vue   # Wurzel: Liste, Editor
    │   ├── FilterList.vue           # Domainliste, neue Domain, XML-Import (showImport-Prop)
    │   ├── FilterEditor.vue         # Sektionen, Notiz, Speichern/Löschen/Export
    │   ├── RuleSection.vue          # Eine Sektion: Referenz read-only + eigene Regeln (von personal/ mitgenutzt)
    │   ├── RuleRow.vue              # Eine Regel, Felder aus dem Schema (von personal/ mitgenutzt)
    │   ├── FilterTestPanel.vue      # Testlauf mit Trefferzähler je Regel
    │   └── draft.js                 # Entwurfsstruktur, disable-Logik (von personal/ mitgenutzt)
    └── personal/            # Content-Filter: eigener, privater Override
        ├── PersonalContentFilters.vue   # Wurzel: Domainliste, Editor
        ├── PersonalFilterEditor.vue     # Wie FilterEditor.vue, aber reference+own statt bundle+custom
        └── PersonalFilterTestPanel.vue  # Wie FilterTestPanel.vue, drei Origin-Labels statt zwei
```
