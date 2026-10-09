# Struktur – merlin-nextcloud

Zentrale Dateien für UI und Logik der Nextcloud-App.

## Backend (PHP)

```
merlin-nextcloud/
├── lib/
│   ├── Controller/          # HTTP-Controller
│   │   ├── ArticleController.php
│   │   ├── TagController.php
│   │   ├── ShareController.php               # Öffentliche Share-Links verwalten (erstellen, Passwort/Ablauf)
│   │   ├── PublicShareController.php          # Öffentliche API hinter einem Share-Link (kein Login)
│   │   ├── HighlightController.php            # REST-API für Textmarkierungen
│   │   ├── CommentController.php              # Kommentare des Besitzers + Push-Kanal (SSE)
│   │   ├── PublicCommentController.php        # Gast-Kommentare/-Markierungen hinter Share-Links
│   │   ├── TtsController.php
│   │   ├── PdfController.php                  # GET /api/articles/{id}/pdf: PDF eines PDF-Artikels durchreichen (nichts gespeichert; Datei-Einträge direkt aus Nextcloud)
│   │   ├── FileController.php                 # Dateien vom Handy: Upload-Ziel, Eintrag anlegen, GET /api/articles/{id}/file (signierter Link)
│   │   ├── ExtensionController.php
│   │   ├── ManifestController.php             # PWA-Manifest
│   │   ├── ServiceWorkerController.php         # Liefert den Service-Worker (PWA)
│   │   ├── YoutubeEmbedController.php          # Proxy für eingebettete YouTube-Player (CSP)
│   │   ├── MediaController.php                 # GET /api/articles/{id}/media: Audio-/Video-Quelle eines Artikels
│   │   ├── VideoStreamController.php           # Veraltet: /video-stream im alten Format (nur HLS-Video)
│   │   ├── SettingsController.php             # GET/PUT /api/settings (inkl. Löschfrist des Nutzers, nur lesend: Admin-Maximum/effektive Frist)
│   │   ├── RetentionController.php            # POST /api/retention/notice: Löschfrist-Hinweis bestätigen
│   │   ├── RetentionAdminController.php       # Admin-API der Löschfrist (Maximum, Vorschau)
│   │   ├── ContentFilterController.php        # Admin-API für Content-Filter
│   │   ├── UserContentFilterController.php    # Personal-API: eigener Override
│   │   └── PageController.php
│   ├── Service/              # Geschäftslogik
│   │   ├── ContentExtractorService.php
│   │   ├── ContentFilterSchema.php       # Grammatik der Filter-XML (eine Quelle)
│   │   ├── ContentFilterRepository.php   # Bundle (Datei) + Admin-/User-Custom (DB), Merge-Cache
│   │   ├── ContentFilterMerger.php       # Bundle-, Admin- und User-Filter vereinen
│   │   ├── ContentFilterValidator.php    # Prüfung vor dem Speichern
│   │   ├── ContentFilterSerializer.php   # JSON ↔ XML für den Regel-Builder
│   │   ├── ContentFilterTrace.php        # Trefferzähler für den Testlauf
│   │   ├── SupportBoxService.php         # Daten der Support-Infobox (<paywall><subscribe> + <metadata><donations>, Seiten-Icon, Akzentfarbe); Reader: entfällt bei aktivem Abo-Login, Share: immer
│   │   ├── PdfProxyService.php           # PDF-Durchreichung für PdfController und öffentlichen Share-Endpunkt (SSRF-Guard je Hop, %PDF-Prüfung, Range, 100-MB-Limit)
│   │   ├── TtsStreamService.php          # Ausgelagert aus TtsController: gemeinsamer Stream-Pfad für authentifizierten und öffentlichen (Share-)Endpunkt
│   │   ├── ExportService.php
│   │   ├── ArticleDeletionService.php    # Einziger Löschweg für Artikel: mit Highlights, Tag-Zuordnungen, Shares; Nutzerlöschung; Waisen
│   │   ├── RetentionService.php          # Löschfrist: Admin-/Nutzerwerte, Lauf, Vorschau, Hinweis
│   │   ├── CommentService.php            # Kommentare/Gast-Markierungen anlegen, ändern, löschen; SSE-Schleife
│   │   ├── CommentRules.php              # Reine Regeln: Namen, Längen, Thread-Position, Gast-Rechte
│   │   ├── ShareAccessService.php        # Share-Token + Passwort-Unlock prüfen (Share-Ansicht und Gast-Kommentare)
│   │   ├── RetentionPolicy.php           # Reine Rechenregeln der Löschfrist (Minimum, Stichtag, Hinweis fällig?)
│   │   ├── TagTree.php                   # Reine Baumregeln verschachtelter Tags (Nachfahren, Kreisprüfung beim Verschieben)
│   │   ├── MerlinFileService.php         # „Merlin Dateien“: Ordner (lokalisiert, per ID gemerkt), Datei-Einträge, Streamen mit Range
│   │   ├── FileRules.php                 # Reine Regeln dazu: Dateiart/Kategorie, Dateinamen, signierte Links, Range
│   │   ├── Media/                        # Audio/Video, siehe Abschnitt "Medien-Provider" unten
│   │   │   ├── MediaResolverService.php       # <media>-Sektion lesen, Provider aufrufen, Marker bauen/lesen
│   │   │   ├── MediaProviderRegistry.php      # type → Provider (einzige Registrierungsstelle)
│   │   │   ├── MediaSourceProviderInterface.php / DescriptionProviderInterface.php
│   │   │   ├── MediaContext.php / MediaResult.php / MediaHttpClient.php / VariantHelper.php
│   │   │   ├── InlineMediaService.php         # <media><inline>: Videos mitten im Text (ARD-Player) → Figure mit Vorschaubild + Quellen-Marker
│   │   │   └── Provider/                      # ard-mediathek, zdf, arte, 3sat, youtube-embed, xpath, json-ld
│   │   └── Login/                        # 🔜 geplant: Paywall-Abo-Login (siehe PLATFORMS.md)
│   │       ├── LoginProviderInterface.php     # login(username, password): Cookie-Bundle
│   │       └── PianoJsonFormLoginProvider.php # type="piano-json-form" (z. B. tagesspiegel.de)
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
├── content-filters/          # Mitgelieferte Filter, eine Datei je Domain (~55 Domains, z. B. spiegel.de, zeit.de, taz.de, youtube.com)
│   ├── 000.sample.com.xml    # Kommentierte Referenz aller Regeltypen
│   ├── 000dead.xml           # Parkliste toter Domains (kein gültiges XML)
│   └── $unsupported.xml      # Domains, die grundsätzlich nicht gescrapt werden (siehe UnsupportedSiteException)
└── tools/
    ├── test-content-filter-merge.php  # Testharness (pures PHP, ohne Composer)
    ├── test-caption-credits.php       # Testharness: <images><caption credits-xpath> → <cite>
    ├── test-caption-flatten.php       # Testharness: Bildunterschriften einzeilig ("•")
    ├── test-media-providers.php       # Testharness Medien-Provider (--live: gegen echte Sender)
    ├── test-inline-media.php          # Testharness Inline-Videos (rbb24-ARD-Player, ganze Extraktion; braucht composer install)
    ├── test-pdf-proxy.php             # Testharness PdfProxyService (lokaler Quellserver: Range, Redirect, Nicht-PDF, Größe, SSRF)
    ├── test-pdf-article.php           # Testharness PDF-Artikel (URL-Erkennung, Marker, SSRF, Sanitizer)
    ├── test-support-box.php           # Testharness SupportBoxService (URL-Auswahl, Login-Ausblendung, Share, Seiten-Icon))
    ├── test-retention.php             # Testharness RetentionPolicy (effektive Frist, Stichtag, Hinweis)
    ├── test-tag-tree.php              # Testharness TagTree (Nachfahren, Kreisprüfung)
    ├── test-file-rules.php            # Testharness FileRules (Dateiart, Namen, Token, Range)
    └── test-site-icon.php             # Testharness ContentExtractorService::extractSiteIconUrl() (Apple > SVG > Bitmap > ICO, <base>, data:/javascript:, kein og:image)
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
Quellen-Typ liegt in `lib/Service/Media/Provider/`:

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

`type` wählt eine `Service/Login/*LoginProvider`-Implementierung (Whitelist analog
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
src/
├── main.js                  # Einstiegspunkt Reader
├── public-main.js           # Einstiegspunkt öffentliche Share-Ansicht
├── admin-main.js            # Einstiegspunkt Verwaltungseinstellungen
├── personal-main.js         # Einstiegspunkt persönliche Einstellungen
├── support-box.js / .css    # Support-Infobox (Abo-/Spendenlink, Seiten-Icon als eigene Spalte über die volle Boxhöhe) zur Lesezeit zwischen zwei Absätze setzen (data-hl-exclude); hideBrokenSupportBoxIcons() entfernt nicht ladbare Icons nach dem Rendern
├── inline-media.js / .css  # Legt auf jede figure.merlin-inline-media (Video mitten im Text) einen MediaPlayer (data-hl-exclude), idempotent nach jedem Rendern
├── highlight-engine.js      # Framework-unabhängige Logik zum Setzen/Wiederfinden von Textmarkierungen im DOM
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
    ├── PdfViewer.vue              # Eingebettete PDF-Vorschau (pdf.js, lazy) für PDF-Artikel; lädt über den PdfProxyService-Endpunkt
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
