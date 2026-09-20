# Merlin – Installation Guide

Merlin is a read-it-later app for Nextcloud: save articles, read them in a clean interface, optionally have them read aloud via Piper TTS.

## Requirements

- **Nextcloud**: version 30–35
- **PHP**: 8.0–8.4 (extensions: `php-xml`, `php-mbstring`, `php-curl`, `php-json`)
- **Database**: MySQL, PostgreSQL, or SQLite

## Installation

Merlin is distributed via the Nextcloud App Store as a ready-built release package –
no Composer or npm steps required, the package already contains all dependencies and
the built frontend assets.

1. Open **Administration settings → Apps**
2. Search for **Merlin**
3. Click **Download and enable**

Alternatively via the command line, if the package has already been manually
extracted to `/path/to/nextcloud/apps/merlin`:

```bash
cd /path/to/nextcloud
php occ app:enable merlin
```

### Verify the installation

1. Reload the Nextcloud page
2. A **Merlin** icon should appear in the app menu
3. Open the app and add a first article

## Content filters (recommended)

Content filters clean up saved articles per website: they strip ads and navigation
before the article is extracted, and correct title, author, or date. About 60 filters
are bundled; administrators can add more under
**Administration settings → Merlin → Content filters**.

### Custom filters (admin and personal)

Custom filters live in the Nextcloud database (table `merlin_cfilter`), not in a
filesystem path – a `merlin.custom_filters_dir` entry in `config.php` is no longer
needed and can be removed if it's still present from an older version. There are two
levels:

* **Admin custom** (instance-wide, under Administration settings → Merlin →
  Content filters): applies to all users who don't have their own override.
* **Personal override** (under Settings → Merlin, for each logged-in user): private,
  visible only to that account, takes precedence over both the bundled and the admin
  filter.

Because both levels are part of the database, they're automatically included in
regular Nextcloud database backups – no separate directory backup needed. Each filter
can also be downloaded as XML in the admin UI and imported on another instance.

### Testing the merge logic

After changes to filter processing, a standalone script verifies the merging of
bundled and custom filters (needs neither Composer nor a running Nextcloud
environment, just PHP – for administrators with SSH access to the server):

```bash
cd /path/to/nextcloud/apps/merlin
php tools/test-content-filter-merge.php
```

Exit code 0 means all checks passed.

## TTS read-aloud feature (optional)

The audio read-aloud feature (used by iOS/iPad via `PiperAudioService.swift`) requires a separate Piper daemon that runs **locally on the Nextcloud server** and is not part of the App Store package – this part still needs to be set up manually.

```
iOS → GET /index.php/apps/merlin/api/articles/{id}/tts?lang=de
       → TtsController.php (load article, HTML→plaintext, expand German abbreviations)
       → POST http://127.0.0.1:5051/synthesize  (Piper daemon)
       → GET  http://127.0.0.1:5051/stream/{session_id}  (MP3 stream, relayed to iOS)
```

### Setting up the daemon

Source: `server-tools/merlin-tts/merlin-piper-server.py`

```bash
cd /opt/merlin-tts   # or your target directory of choice
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt   # fastapi, uvicorn, piper-tts, pydantic
```

Set it up as a systemd service (template: `server-tools/merlin-tts/merlin-tts.service`):

```bash
cp server-tools/merlin-tts/merlin-tts.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now merlin-tts
```

The daemon listens on `127.0.0.1:5051` by default and exposes `POST /synthesize`, `GET /stream/{id}`, and `DELETE /stream/{id}`.

### Important: PHP-FPM timeout

If `request_terminate_timeout` is set in the PHP-FPM pool (Synology default: 30 s), the TTS stream will cut off after 30 seconds. This **cannot** be worked around in PHP code – set `request_terminate_timeout` to `0` in the FPM pool configuration and restart PHP-FPM.

### Important: request body size limit (HTTP 413 when saving an article)

The browser extension sends the client-rendered page HTML for some sites instead of just the URL (`html` field on the save request, e.g. for JS-heavy SPAs where a server-side fetch alone wouldn't see the real content) – for a page like ARD Mediathek or ZDF this can easily be several MB. If saving such an article fails with `413 Payload Too Large`, Nextcloud's own web server/PHP-FPM in front of it is rejecting the request body before it reaches this app. Raise `client_max_body_size` in nginx (or the Apache equivalent) and `post_max_size`/`upload_max_filesize` in `php.ini` to a few times that (e.g. 20 MB), then reload/restart the web server and PHP-FPM.

## Next steps

1. **Add your first article**: click "Add article" and paste a URL
2. **Configure RSS feeds**: set up feeds for automatic article import
3. **Adjust settings**: customize reading settings in the settings panel
4. **Set up the browser extension**: use the Pocket-compatible API for the Firefox/Chrome extension

## Troubleshooting

### App doesn't appear after activation
- Clear the browser cache
- Run `php occ maintenance:repair`
- Check the Nextcloud log: `tail -f /path/to/nextcloud/data/nextcloud.log`

### PHP errors
- Check the PHP version (8.0–8.4): `php --version`
- Check required extensions: `php-xml`, `php-mbstring`, `php-curl`, `php-json`

### Database errors
- Run migrations manually: `php occ migrations:execute merlin`
- Check database permissions

### TTS doesn't work / cuts off after 30 s
- Is the Piper daemon running? `systemctl status merlin-tts`
- Is it reachable? `curl http://127.0.0.1:5051/synthesize`
- Set `request_terminate_timeout` to `0` in the PHP-FPM pool config (see above)

---

**Need more help?** See [README.md](README.md) and `Structure.md` in this directory.
