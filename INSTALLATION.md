# Merlin – Installationsanleitung

Merlin ist eine Leselisten-App für Nextcloud: Artikel speichern, in einer aufgeräumten Oberfläche lesen, optional per Piper-TTS vorlesen lassen.

## Voraussetzungen

- **Nextcloud**: Version 30–35
- **PHP**: 8.0–8.4 (Extensions: `php-xml`, `php-mbstring`, `php-curl`, `php-json`)
- **Datenbank**: MySQL, PostgreSQL oder SQLite
- **Node.js**: 20.x oder höher, **npm**: 10.x oder höher (nur zum Bauen des Frontends)
- **Composer**: https://getcomposer.org/

## Installation

### 1. App-Verzeichnis vorbereiten

Die App-ID lautet `merlin` (siehe `appinfo/info.xml`). Code in den Nextcloud-Apps-Ordner kopieren:

```bash
cd /path/to/your/merlin-nextcloud
cp -r . /path/to/nextcloud/apps/merlin
cd /path/to/nextcloud/apps/merlin
```

### 2. PHP-Abhängigkeiten installieren

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Frontend bauen

```bash
npm install
npm run build
```

### 4. Berechtigungen setzen

```bash
chown -R www-data:www-data /path/to/nextcloud/apps/merlin
chmod -R 755 /path/to/nextcloud/apps/merlin
```

`www-data` ggf. durch den tatsächlichen Webserver-User ersetzen (z. B. `apache`, `nginx`).

### 5. App aktivieren

```bash
cd /path/to/nextcloud
php occ app:enable merlin
```

Alternativ über die Weboberfläche: **Apps** → **Deine Apps** → **Merlin** → **Aktivieren**.

### 6. Installation prüfen

1. Nextcloud-Seite neu laden
2. Im App-Menü sollte ein **Merlin**-Icon erscheinen
3. App öffnen und einen ersten Artikel hinzufügen

## Content-Filter (empfohlen)

Content-Filter bereiten gespeicherte Artikel pro Website auf: Sie entfernen Werbung
und Navigation, bevor der Artikel extrahiert wird, und korrigieren Titel, Autor oder
Datum. Rund 60 Filter sind mitgeliefert; Administratoren können sie unter
**Verwaltungseinstellungen → Merlin → Content-Filter** ergänzen.

### Eigene Filter (Admin und persönlich)

Eigene Filter liegen in der Nextcloud-Datenbank (Tabelle `merlin_cfilter`), nicht mehr
in einem Dateisystem-Pfad – ein `merlin.custom_filters_dir`-Eintrag in `config.php`
wird nicht mehr benötigt und kann entfernt werden, falls er aus einer älteren Version
noch vorhanden ist. Es gibt zwei Ebenen:

* **Admin-Custom** (instanzweit, unter Verwaltungseinstellungen → Merlin →
  Content-Filter): gilt für alle Nutzer, die keinen eigenen Override haben.
* **Persönlicher Override** (unter Einstellungen → Merlin, für jeden angemeldeten
  Nutzer): privat, nur für den eigenen Account sichtbar, gewinnt gegenüber dem
  mitgelieferten und dem Admin-Filter.

Weil beide Ebenen Teil der Datenbank sind, sind sie automatisch im normalen
Nextcloud-Datenbank-Backup enthalten – kein separates Verzeichnis-Backup nötig. Jeder
Filter lässt sich zusätzlich in der Admin-Oberfläche als XML herunterladen und auf
einer anderen Instanz importieren.

### Merge-Logik testen

Nach Änderungen an der Filter-Verarbeitung prüft ein eigenständiges Skript die
Zusammenführung von mitgelieferten und eigenen Filtern (benötigt weder Composer noch
eine Nextcloud-Umgebung):

```bash
cd /pfad/zu/nextcloud/apps/merlin
php tools/test-content-filter-merge.php
```

Exit-Code 0 bedeutet, dass alle Prüfungen bestanden wurden.

## TTS-Vorlesefunktion (optional)

Die Audio-Vorlesefunktion (genutzt von iOS/iPad über `PiperAudioService.swift`) benötigt einen separaten Piper-Daemon, der **lokal auf dem Nextcloud-Server** läuft und nicht Teil der Nextcloud-App selbst ist.

```
iOS → GET /index.php/apps/merlin/api/articles/{id}/tts?lang=de
       → TtsController.php (Artikel laden, HTML→Plaintext, dt. Abkürzungen auflösen)
       → POST http://127.0.0.1:5051/synthesize  (Piper-Daemon)
       → GET  http://127.0.0.1:5051/stream/{session_id}  (MP3-Stream, an iOS durchgereicht)
```

### Daemon einrichten

Quelle: `server-tools/merlin-tts/merlin-piper-server.py`

```bash
cd /opt/merlin-tts   # oder gewünschtes Zielverzeichnis
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt   # fastapi, uvicorn, piper-tts, pydantic
```

Als systemd-Dienst einrichten (Vorlage: `server-tools/merlin-tts/merlin-tts.service`):

```bash
cp server-tools/merlin-tts/merlin-tts.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now merlin-tts
```

Der Daemon hört standardmäßig auf `127.0.0.1:5051` und stellt `POST /synthesize`, `GET /stream/{id}` sowie `DELETE /stream/{id}` bereit.

### Wichtig: PHP-FPM-Timeout

Wenn `request_terminate_timeout` im PHP-FPM-Pool (Synology-Default: 30 s) gesetzt ist, bricht der TTS-Stream nach 30 Sekunden ab. Das lässt sich **nicht** per PHP-Code umgehen – `request_terminate_timeout` in der FPM-Pool-Konfiguration auf `0` setzen und PHP-FPM neu starten.

## Nächste Schritte

1. **Ersten Artikel hinzufügen**: "Artikel hinzufügen" klicken und eine URL einfügen
2. **RSS-Feeds konfigurieren**: Feeds für automatischen Artikel-Import hinterlegen
3. **Einstellungen anpassen**: Leseeinstellungen im Settings-Panel anpassen
4. **Browser-Erweiterung einrichten**: Pocket-kompatible API für Firefox/Chrome-Erweiterung nutzen

## Troubleshooting

### App erscheint nach Aktivierung nicht
- Browser-Cache leeren
- `php occ maintenance:repair` ausführen
- Nextcloud-Log prüfen: `tail -f /path/to/nextcloud/data/nextcloud.log`

### Build-Fehler
- Node.js-Version prüfen (20+): `node --version`
- npm-Cache leeren: `npm cache clean --force`
- `node_modules` löschen und neu installieren: `rm -rf node_modules && npm install`

### PHP-Fehler
- PHP-Version prüfen (8.0–8.4): `php --version`
- Benötigte Extensions prüfen: `php-xml`, `php-mbstring`, `php-curl`, `php-json`

### Datenbankfehler
- Migrationen manuell ausführen: `php occ migrations:execute merlin`
- Datenbankberechtigungen prüfen

### TTS funktioniert nicht / bricht nach 30 s ab
- Piper-Daemon läuft? `systemctl status merlin-tts`
- Erreichbar? `curl http://127.0.0.1:5051/synthesize`
- `request_terminate_timeout` in der PHP-FPM-Pool-Config auf `0` (siehe oben)

## Entwicklungsmodus

```bash
npm run dev    # Entwicklungs-Build mit Watch-Modus
npm run watch  # Watch-Modus für Vite
```

## Produktions-Optimierung

1. **Autoloader optimieren:**
   ```bash
   composer install --no-dev --optimize-autoloader --classmap-authoritative
   ```
2. **Assets minifizieren:**
   ```bash
   npm run build
   ```
3. **Caching aktivieren:** Redis/Memcached für Nextcloud konfigurieren, Browser-Caching-Header setzen
4. **Performance:** PHP OPcache aktivieren, Datenbank-Query-Caching konfigurieren

---

**Weitere Hilfe?** Siehe [README.md](README.md) sowie `Structure.md` in diesem Verzeichnis.
