# Struktur – merlin-nextcloud

Zentrale Dateien für UI und Logik der Nextcloud-App.

## Backend (PHP)

```
merlin-nextcloud/
├── lib/
│   ├── Controller/          # HTTP-Controller
│   │   ├── ArticleController.php
│   │   ├── TagController.php
│   │   ├── FeedController.php
│   │   ├── TtsController.php
│   │   ├── ExtensionController.php
│   │   ├── SettingsController.php
│   │   ├── ContentFilterController.php       # Admin-API für Content-Filter
│   │   ├── UserContentFilterController.php   # Personal-API: eigener Override
│   │   └── PageController.php
│   ├── Service/              # Geschäftslogik
│   │   ├── ContentExtractorService.php
│   │   ├── ContentFilterSchema.php       # Grammatik der Filter-XML (eine Quelle)
│   │   ├── ContentFilterRepository.php   # Bundle (Datei) + Admin-/User-Custom (DB), Merge-Cache
│   │   ├── ContentFilterMerger.php       # Bundle-, Admin- und User-Filter vereinen
│   │   ├── ContentFilterValidator.php    # Prüfung vor dem Speichern
│   │   ├── ContentFilterSerializer.php   # JSON ↔ XML für den Regel-Builder
│   │   ├── ContentFilterTrace.php        # Trefferzähler für den Testlauf
│   │   ├── FeedService.php
│   │   └── ExportService.php
│   ├── Settings/             # Verwaltungs- und persönliche Einstellungen
│   │   ├── AdminSection.php
│   │   ├── AdminSettings.php
│   │   ├── PersonalSection.php
│   │   └── PersonalSettings.php
│   ├── Listener/
│   │   ├── AddContentSecurityPolicyListener.php
│   │   └── UserDeletedListener.php   # Räumt private Content-Filter-Overrides auf
│   ├── Db/                   # Datenbankschicht
│   │   ├── Article.php / ArticleMapper.php
│   │   ├── Tag.php / TagMapper.php
│   │   └── Feed.php / FeedMapper.php
│   └── Migration/            # Datenbank-Migrationen
├── content-filters/          # Mitgelieferte Filter, eine Datei je Domain
│   ├── 000.sample.com.xml    # Kommentierte Referenz aller Regeltypen
│   └── 000dead.xml           # Parkliste toter Domains (kein gültiges XML)
└── tools/
    └── test-content-filter-merge.php  # Testharness (pures PHP, ohne Composer)
```

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

## Frontend (Vue 3)

Vier Vite-Entry-Points (siehe `vite.config.mjs`): Reader, öffentliche Share-Ansicht,
Verwaltungseinstellungen und persönliche Einstellungen.

```
src/
├── main.js                  # Einstiegspunkt Reader
├── public-main.js           # Einstiegspunkt öffentliche Share-Ansicht
├── admin-main.js            # Einstiegspunkt Verwaltungseinstellungen
├── personal-main.js         # Einstiegspunkt persönliche Einstellungen
├── App.vue                  # Hauptkomponente
├── store/                   # State Management
├── api/                     # API-Wrapper
│   ├── contentFilters.js        # Admin-Endpunkte (/api/admin/content-filters)
│   └── userContentFilters.js    # Personal-Endpunkte (/api/user/content-filters)
└── components/
    ├── ArticleList.vue
    ├── ArticleCard.vue
    ├── ArticleReader.vue
    ├── AddArticleDialog.vue
    ├── Settings.vue
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
