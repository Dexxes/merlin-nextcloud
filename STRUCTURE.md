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
│   │   └── PageController.php
│   ├── Service/              # Geschäftslogik
│   │   ├── ContentExtractorService.php
│   │   ├── FeedService.php
│   │   └── ExportService.php
│   ├── Db/                   # Datenbankschicht
│   │   ├── Article.php / ArticleMapper.php
│   │   ├── Tag.php / TagMapper.php
│   │   └── Feed.php / FeedMapper.php
│   └── Migration/            # Datenbank-Migrationen
```

## Frontend (Vue 3)

```
src/
├── main.js                  # Einstiegspunkt
├── App.vue                  # Hauptkomponente
├── store/                   # State Management
├── api/                     # API-Wrapper
└── components/
    ├── ArticleList.vue
    ├── ArticleCard.vue
    ├── ArticleReader.vue
    ├── AddArticleDialog.vue
    └── Settings.vue
```