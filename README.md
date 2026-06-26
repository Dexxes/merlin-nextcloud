# Merlin

Merlin ist eine plattformübergreifende Leselisten-App, die Nextcloud als Backend nutzt. Artikel landen per Browser-Erweiterung, RSS-Feed oder direktem Link in einer aufgeräumten, werbefreien Leseansicht – synchron über alle deine Geräte.

![Merlin](screenshots/main.png)

## Plattformen

| Verzeichnis | Plattform | Stack |
|---|---|---|
| `merlin-nextcloud` | Nextcloud-App (Backend + Web-UI) | PHP 8.x, Vue 3, OCP-Framework |
| `merlin-ios` | iOS 17+ | Swift 6, SwiftUI, AVFoundation, SPM |
| `merlin-ipad` | iPadOS | wie iOS |
| `merlin-android` | Android | Kotlin, Jetpack Compose |
| `merlin-firefox` | Firefox-Addon | JS/WebExtension |
| `merlin-chrome` | Chrome/Edge-Erweiterung | JS/WebExtension |
| `merlin-thunderbird` | Thunderbird-Erweiterung | JS |

Dieses Repository (`merlin-nextcloud`) enthält die Nextcloud-App: Backend-API und Web-Oberfläche.

## Features

### Kernfunktionen
- **Artikel speichern**: Per URL hinzufügen, automatische Inhaltsextraktion
- **Distraktionsfreies Lesen**: Typografie-optimierte Leseansicht
- **Tags & Organisation**: Artikel kategorisieren und filtern
- **RSS-Feed-Import**: Artikel automatisch aus Feeds importieren
- **Export**: Artikel als HTML exportieren
- **Volltextsuche**: Über Titel, Inhalt und Autor

### Leseerlebnis
- **Dark Mode**: Hell, Dunkel oder automatisch (Systemeinstellung)
- **Anpassbare Typografie**: Schriftgröße, -familie, Zeilenhöhe, Inhaltsbreite
- **Lesezeit-Schätzung** pro Artikel
- **Responsive Design** für Desktop, Tablet und Mobile

### Vorlesefunktion (TTS)
Artikel können über eine lokale Piper-TTS-Pipeline vorgelesen werden (aktuell über die iOS/iPadOS-App). Der Server extrahiert den Plaintext, löst deutsche Abkürzungen auf und streamt das Audio direkt an den Client.

### Weitere Funktionen
- **Browser-Erweiterungen**: Pocket-kompatible API
- **Favoriten** und **Archiv**
- **Auto-Mark-as-Read**: Artikel automatisch als gelesen markieren, sobald sie geöffnet werden

## Voraussetzungen

- Nextcloud 30–35
- PHP 8.0–8.4
- MySQL, PostgreSQL oder SQLite

Details zur Installation, zum Build-Prozess und zur optionalen TTS-Pipeline stehen in **[INSTALLATION.md](INSTALLATION.md)**.

## API-Dokumentation

### Browser-Erweiterung (Pocket-kompatibel)

```
POST /apps/merlin/api/v1/add
POST /apps/merlin/api/v1/get
POST /apps/merlin/api/v1/send
```

### REST-API

Alle Endpunkte erfordern Authentifizierung über die Nextcloud-Session.

**Artikel**
- `GET /apps/merlin/api/articles` – Alle Artikel abrufen
- `GET /apps/merlin/api/articles/{id}` – Einzelnen Artikel abrufen
- `POST /apps/merlin/api/articles` – Artikel anlegen
- `PUT /apps/merlin/api/articles/{id}` – Artikel aktualisieren
- `DELETE /apps/merlin/api/articles/{id}` – Artikel löschen
- `PUT /apps/merlin/api/articles/{id}/read` – Lesestatus umschalten
- `PUT /apps/merlin/api/articles/{id}/favorite` – Favorit umschalten
- `PUT /apps/merlin/api/articles/{id}/archive` – Archiv umschalten
- `GET /apps/merlin/api/articles/search?query=term` – Artikel durchsuchen
- `GET /apps/merlin/api/articles/{id}/tts?lang=de` – Audio-Stream (TTS)

**Tags**
- `GET /apps/merlin/api/tags`
- `POST /apps/merlin/api/tags`
- `PUT /apps/merlin/api/tags/{id}`
- `DELETE /apps/merlin/api/tags/{id}`

**Feeds**
- `GET /apps/merlin/api/feeds`
- `POST /apps/merlin/api/feeds`
- `PUT /apps/merlin/api/feeds/{id}`
- `DELETE /apps/merlin/api/feeds/{id}`
- `POST /apps/merlin/api/feeds/{id}/refresh`
- `POST /apps/merlin/api/feeds/refresh`

## Architektur

### Backend (PHP)

```
merlin-nextcloud/
├── lib/
│   ├── Controller/          # HTTP-Controller
│   │   ├── ArticleController.php
│   │   ├── TagController.php
│   │   ├── FeedController.php
│   │   ├── TtsController.php
│   │   ├── ExtensionController.php
│   │   └── SettingsController.php
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

### Frontend (Vue 3)

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
    └── SettingsDialog.vue
```

Eine detaillierte `Structure.md` mit den zentralen UI- und Logik-Dateien liegt in diesem Verzeichnis.

## Mitwirken

1. Repository forken
2. Feature-Branch erstellen: `git checkout -b feature/amazing-feature`
3. Änderungen vornehmen
4. Tests und Linting ausführen
5. Committen: `git commit -m 'Add amazing feature'`
6. Branch pushen: `git push origin feature/amazing-feature`
7. Pull Request öffnen

### Code-Stil

- **PHP**: [Nextcloud Coding Standard](https://docs.nextcloud.com/server/latest/developer_manual/getting_started/codingguidelines.html)
- **JavaScript/Vue**: [@nextcloud/eslint-config](https://github.com/nextcloud/eslint-config)
- **CSS**: [@nextcloud/stylelint-config](https://github.com/nextcloud/stylelint-config)

## Lizenz

Dieses Projekt steht unter der AGPL-3.0-Lizenz – siehe [LICENSE](LICENSE).

## Credits

- Aufgebaut mit dem [Nextcloud App Framework](https://docs.nextcloud.com/server/latest/developer_manual/)
- Inhaltsextraktion mit [fivefilters/readability.php](https://github.com/fivefilters/readability.php)
- RSS-Parsing mit [SimplePie](https://simplepie.org/)
- UI-Komponenten von [@nextcloud/vue](https://nextcloud-vue-components.netlify.app/)
- Sprachsynthese mit [Piper TTS](https://github.com/rhasspy/piper)

## Roadmap

- [ ] Annotationen und Hervorhebungen
- [ ] TTS auch für Android/Web
- [ ] Sharing und Zusammenarbeit
- [ ] Offline-Modus mit Service Worker
- [ ] Import aus Pocket, Instapaper, Wallabag

---

Made with ❤️ for Nextcloud
