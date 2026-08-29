# Merlin

A cross-platform read-it-later app that uses Nextcloud as its backend (and frontend). Articles land in a clean, ad-free reading view via browser extension, mobile apps, or a direct link – synced across all your devices.

Born out of frustration with Mozilla killing off Pocket.

## Platforms

| Directory | Platform | Stack |
|---|---|---|
| [`merlin-nextcloud`](https://github.com/Dexxes/merlin-nextcloud) | Nextcloud app (backend + web UI) | PHP 8.0-8.4, Nextcloud 30-35, Vue 3, OCP framework |
| [`merlin-standalone-server`](https://github.com/Dexxes/merlin-standalone-server) | Standalone server (backend, no Nextcloud) | PHP 8.4, no framework, PDO/SQLite |
| [`merlin-ios`](https://github.com/Dexxes/merlin-ios) | iOS 18+ | Swift 6, SwiftUI, AVFoundation, SPM |
| `merlin-ipad` (unreleased) | iPadOS 15+ | like iOS (own, lower deployment target) |
| `merlin-android` (unreleased) | Android 6.0+ (minSdk 23, target 34) | Kotlin, Jetpack Compose  |
| [`merlin-firefox`](https://github.com/Dexxes/merlin-firefox) | Firefox | JS/WebExtension |
| `merlin-chrome` (unreleased) | Chrome/Edge (Manifest V3) | JS/WebExtension |
| [`merlin-thunderbird`](https://github.com/Dexxes/merlin-thunderbird) | Thunderbird 115+ | JS |

## Features

### Core features
- **Save articles**: Add via URL, automatic content extraction
- **Distraction-free reading**: Text and images; no ads, no clutter
- **Tags & organization**: Categorize and filter articles
- **Full-text search**: Across title, content, and author
- **Favorites** and **archive**

### Reading experience
- **Dark mode**: Light, dark, or automatic (follows system setting)
- **Customizable typography**: Font size, font family, line height, content width
- **Reading time estimate** per article
- **Responsive design** for desktop, tablet, and mobile

### Text-to-speech (TTS)
Articles can be read aloud via a local Piper TTS pipeline (currently via the iOS/iPadOS app). The server extracts the plain text, expands German abbreviations, and streams the audio directly to the client.

### Additional features
- **Browser extensions** for Firefox and Chrome/Edge
- **Thunderbird extension** for sharing links from emails

## Requirements

- Nextcloud 30–35
- PHP 8.0–8.4
- MySQL, PostgreSQL, or SQLite

Details on installation, the build process, and the optional TTS pipeline are in **[INSTALLATION.md](INSTALLATION.md)**, architecture in **[STRUCTURE.md](STRUCTURE.md)**.

## Credits

- Built with the [Nextcloud App Framework](https://docs.nextcloud.com/server/latest/developer_manual/)
- Content extraction with [fivefilters/readability.php](https://github.com/fivefilters/readability.php)
- RSS parsing with [SimplePie](https://simplepie.org/)
- UI components from [@nextcloud/vue](https://nextcloud-vue-components.netlify.app/)
- Speech synthesis with [Piper TTS](https://github.com/rhasspy/piper)
