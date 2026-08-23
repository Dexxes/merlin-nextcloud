# Changelog

All notable changes to Merlin are documented here. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning based on
[SemVer](https://semver.org/).

## [1.0.5]

### Added
- Paywall subscription login (e.g. Tagesspiegel Plus): encrypted per-user
  credentials, automatic login and session-cookie injection when fetching
  articles, plus a "Paywall subscriptions" section in Personal Settings to
  connect/disconnect an account. Version bump is required for Nextcloud to
  pick up the new `/api/user/site-credentials*` routes (route table is
  cached and keyed by app version).

## [1.0.4]

First version that will be submitted to the Nextcloud App Store.

### Added
- Save articles via URL with automatic content extraction (around 60 bundled
  content filters)
- Distraction-free reading view with dark mode and customizable typography
- Tags, favorites, archive, and full-text search
- Export articles as HTML
- Pocket-compatible API for browser extensions
- Text-to-speech (TTS) via a local Piper pipeline
- `author` override parameter for the Pocket-compatible add endpoint
  (`ExtensionController::add()`), alongside the existing `title` parameter

### Fixed
- A `title` sent by a browser extension when saving an article was discarded
  once content extraction finished (it only ever reached the transient
  placeholder article); caller-supplied `title`/`author` now win over the
  extracted values