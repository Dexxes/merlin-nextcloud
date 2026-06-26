# Merlin

Eine plattformübergreifende Leselisten-App, die Nextcloud als Backend (und Frontend) nutzt. Artikel landen per Browser-Erweiterung, Smartphone-Apps oder direktem Link in einer aufgeräumten, werbefreien Leseansicht – synchron über alle deine Geräte.

Entstanden aus der Frustration darüber, dass Mozilla Pocket beerdigt hat.

## Plattformen

| Verzeichnis | Plattform | Stack |
|---|---|---|
| `merlin-nextcloud` | Nextcloud-App (Backend + Web-UI) | PHP 8.0–8.4, Nextcloud 30–35, Vue 3, OCP-Framework |
| `merlin-ios` | iOS 18+ | Swift 6, SwiftUI, AVFoundation, SPM |
| `merlin-ipad` | iPadOS 15+ | wie iOS (eigenes, niedrigeres Deployment-Target) |
| `merlin-android` | Android 6.0+ (minSdk 23, target 34) | Kotlin, Jetpack Compose |
| `merlin-firefox` | Firefox (Manifest V3) | JS/WebExtension |
| `merlin-chrome` | Chrome/Edge (Manifest V3) | JS/WebExtension |
| `merlin-thunderbird` | Thunderbird 115+ | JS |

Dieses Repository (`merlin-nextcloud`) enthält die Nextcloud-App: Backend-API und Web-Oberfläche.

## Features

### Kernfunktionen
- **Artikel speichern**: Per URL hinzufügen, automatische Inhaltsextraktion
- **Distraktionsfreies Lesen**: Text und Bild; keine Werbung, kein Schnickschnack
- **Tags & Organisation**: Artikel kategorisieren und filtern
- **Volltextsuche**: Über Titel, Inhalt und Autor
- **Favoriten** und **Archiv**

### Leseerlebnis
- **Dark Mode**: Hell, Dunkel oder automatisch (Systemeinstellung)
- **Anpassbare Typografie**: Schriftgröße, -familie, Zeilenhöhe, Inhaltsbreite
- **Lesezeit-Schätzung** pro Artikel
- **Responsive Design** für Desktop, Tablet und Mobile

### Vorlesefunktion (TTS)
Artikel können über eine lokale Piper-TTS-Pipeline vorgelesen werden (aktuell über die iOS/iPadOS-App). Der Server extrahiert den Plaintext, löst deutsche Abkürzungen auf und streamt das Audio direkt an den Client.

### Weitere Funktionen
- **Browser-Erweiterungen** für Firefox und Chrome/Edge
- **Thunderbird-Erweiterung** zum Teilen von Links aus E-Mails

## Voraussetzungen

- Nextcloud 30–35
- PHP 8.0–8.4
- MySQL, PostgreSQL oder SQLite

Details zur Installation, zum Build-Prozess und zur optionalen TTS-Pipeline stehen in **[INSTALLATION.md](INSTALLATION.md)**, Architektur in **[STRUCTURE.md](STRUCTURE.md)**.

## Credits

- Aufgebaut mit dem [Nextcloud App Framework](https://docs.nextcloud.com/server/latest/developer_manual/)
- Inhaltsextraktion mit [fivefilters/readability.php](https://github.com/fivefilters/readability.php)
- RSS-Parsing mit [SimplePie](https://simplepie.org/)
- UI-Komponenten von [@nextcloud/vue](https://nextcloud-vue-components.netlify.app/)
- Sprachsynthese mit [Piper TTS](https://github.com/rhasspy/piper)