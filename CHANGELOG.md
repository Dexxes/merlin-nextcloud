# Changelog

All notable changes to Merlin are documented here. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning based on
[SemVer](https://semver.org/).

## [Unreleased]

### Added
- Authors: name and profile link are now detected across sites without a
  per-domain rule. New generic signals, used when no domain rule, `article:author`
  or JSON-LD gives a name: WordPress block themes (`wp-block-post-author-name`,
  `wp-block-post-author-name__link`, `wp-block-post-author__name`), schema.org
  microdata (`itemprop="author"`), hCard (`.author.vcard .fn`) and
  `<a rel="author">`; byline prefixes like "Von"/"By" are stripped. JSON-LD authors
  given only as an `@id` reference (Yoast `@graph`) are resolved. `article:author`
  holding a URL is no longer taken as the author name. For a single author the
  profile link (from the matched link, JSON-LD `author.url`, a generic signal with
  the same name, or `article:author`) is stored in the new column
  `merlin_articles.author_url` (migration 26), returned as `authorUrl` in the
  article API and public shares, and the author name links to it in the reader
  and the share view. Content filters can set the link explicitly with
  `<metadata><author-link xpath="…" | json="…"/>`, which takes precedence over
  the automatic detection. Covered by `tools/test-author-detection.php`.
- Video/audio articles: the player now takes the place of the hero image in
  the reader, like in Merlin iOS. Video and embeds use the full article width
  with the hero image as poster; audio shows the hero image as cover with an
  overlay control bar (play/pause, scrubber with remaining time, back 15 s /
  forward 30 s, speed 0.75-2x, version picker). Video (file/HLS) gets the same custom controls with a
  big play button, mute and fullscreen; embeds keep the provider's player. The hero caption moves below
  the player. Articles without a playable source keep the hero image.
- PDF links: saving a URL that points to a PDF (path ends in `.pdf`, or the
  server answers `application/pdf`) creates an article with category `PDF`
  instead of failing in the HTML extractor. The PDF itself is never stored -
  only the URL, as a `<div class="merlin-pdf" data-pdf-src>` marker plus a
  fallback link; the title is derived from the file name. The reader and public
  share links show an embedded, page-by-page preview (pdf.js, loaded on demand)
  with an "Open PDF" button. The PDF is passed through per request by the new
  `GET /api/articles/{id}/pdf` and `GET /s/{token}/pdf` (`PdfProxyService`:
  only the stored article URL, SSRF-checked per hop, `%PDF-` check, 100 MB cap,
  Range support, hardened response headers), because source hosts rarely allow
  CORS or framing. If the preview cannot load, a card with "Open PDF" is shown.
  The clients (iOS/Android) load the document from the source themselves. Public
  share data now includes `category`. The HTML fetch also stops after 20 MB.
- Reader: the "More" menu (desktop dock and mobile toolbar) now offers
  "Open via archive.ph" and "Report faulty rendered article", matching the iOS
  app.
- Support box in articles: a new `<donations url="…"/>` field in the
  `<metadata>` section of a content filter (next to `<paywall><subscribe>`)
  makes the reader show, between two random paragraphs, "Enjoying this article
  from {site}? Consider taking out a subscription or making a donation" with
  links to the publisher's subscription and donation pages. A missing URL drops
  its part of the sentence; with neither URL there is no box. The box is
  hidden when the user has an active subscription login for that site, always
  shown in public share links, and tinted with the user's accent colour
  (the share link uses the accent colour of whoever created it). It is added at
  render time from a new `supportBox` field (`GET /api/articles/{id}` and the
  public share data), so stored content, highlights, TTS and exports are
  unchanged.
- The support box now shows the icon of the article's own page as its own
  column on the left, spanning the full height of the box, with title and text
  beside it. The icon is read from the page while the article is saved
  (`apple-touch-icon`, else `<link rel="icon">` — SVG before bitmap before ICO,
  the largest size — else the MS tile image, else `/favicon.ico`; never
  `og:image`, which is usually a banner) and stored in a new
  `merlin_articles.site_icon_url` column (migration 25). No extra request is
  made; articles saved before the update fall back to the `/favicon.ico` of
  their site until they are extracted again, and an icon that fails to load is
  simply left out.

### Fixed
- Hero image: the caption is now also assigned when og:image points to a
  WordPress "big image" file (`-scaled`/`-rotated` suffix, e.g. netzpolitik.org)
  while the article's figure uses a regular size variant of the original.
- Quotes: attribution is now recognised for standard blockquotes (trailing
  text/`<em>` after the quote paragraph, `<footer>`/`<address>`, a following
  `<p><cite>`) and rendered as `<cite class="merlin-quote__source">`. Author
  names no longer get double-escaped or duplicated inside the quote,
  multi-paragraph quotes from content-filter rules keep all paragraphs, and the
  reader CSS now matches the `merlin-quote*` classes the server emits. The
  author class no longer contains "author", so Readability does not drop it as
  a byline.
- Hero captions on WordPress sites whose image resizer appends a crop suffix
  (`foto-1440x720-1160x580-c-default.jpg`, e.g. juedische-allgemeine.de) are no
  longer dropped: `imagesMatchForDedup()` now strips the `-WxH…-c-<position>`
  suffix, so the captioned header figure matches the og:image again.

## [1.0.11]

### Added
- Extensible audio/video handling modelled on the content filters: a new
  `<media>` section in `content-filters/{domain}.xml` declares which source
  type a domain uses (`<source type="…" kind="video|audio">`, optional
  `<description>`), merged across bundle/admin/user like every other section.
  The per-type logic lives in providers under `lib/Service/Media/Provider/`
  (`ard-mediathek`, `zdf`, `arte`, `youtube-embed`, and the generic `xpath`
  and `json-ld`). A new broadcaster that ships its media file in the page
  markup or in schema.org JSON-LD needs only a `<media>` entry, no code.
- Audio: Deutschlandfunk / Deutschlandfunk Kultur (article audio via
  `data-audio`, live stream excluded) and ARD Sounds (`ardsounds.de.xml`,
  episode file from JSON-LD).
- YouTube articles now play in the reader through the official
  youtube-nocookie embed instead of only linking out.
- Categories `Audio` and `Mixed` next to `Video`: `Mixed` is set automatically
  for text articles with a media source (with fewer than 80 words the article
  counts as a pure `Audio`/`Video` page instead). Sidebar group "Audio",
  `contentType=audio` on `GET /api/articles`, `audio` block in
  `GET /api/articles/counts`; `Mixed` counts as a page.
- `GET /api/articles/{id}/media` → `{available, kind, delivery (hls|file|embed),
  variants, defaultIndex}`. Stable sources (files, embeds) are written into the
  content as a `<div class="merlin-media" data-media-*>` marker at save time, so
  they also play offline and on public share links; mediathek HLS streams are
  still resolved per request.
- `MediaPlayer.vue` replaces `VideoPlayer.vue` (HLS via hls.js, now loaded on
  demand; native `<audio>`/`<video>` for files; iframe for embeds).

### Changed
- `GET /api/articles/{id}/video-stream` is deprecated; it keeps its old response
  format (HLS video only) for existing clients.
- CSP: `media-src https:` for direct audio/video files.
- `content-filters/deutschlandfunk.kultur.de.xml` renamed to
  `deutschlandfunkkultur.de.xml` - under the old name it never matched the
  domain, so its rules were not applied at all until now.

### Fixed
- Video/audio pages without `og:title` (e.g. YouTube's consent page) no longer
  abort the extraction with a TypeError.

## [1.0.9]

### Fixed
- `ExtensionController::add()` (the Pocket-compatible browser-extension save
  endpoint) never persisted `isPaywalled`/`paywallSubscribeUrl` at all - it
  has its own separate copy of the extraction-result-to-Article mapping,
  independent of `ArticleController::create()`, which was already correct.
  Articles saved through this endpoint always got `isPaywalled = false`
  regardless of what the content filter detected. Confirmed no other copies
  of this mapping exist (`grep` for `$extracted['content']` across `lib/`).

## [1.0.8]

### Fixed
- `isPaywalled`/`paywallSubscribeUrl` were only added to the admin content-filter
  test panel (1.0.7), not to the separate Personal-Settings test panel
  (`UserContentFilterController::test()` / `PersonalFilterTestPanel.vue`) -
  the two panels are backed by distinct controllers with their own field
  whitelist, so fixing one silently left the other behind.

## [1.0.7]

### Added
- Generic paywall detection via `<paywall><marker xpath>`/`<subscribe url>` in
  the content-filter schema, for domains without `<login>` credential support:
  `Article.isPaywalled`/`paywallSubscribeUrl`, content no longer persisted for
  such articles, and both fields surfaced in the admin "Test run" panel.
  Version bump is required for Nextcloud to serve the updated admin JS bundle
  and pick up the new fields (assets/routes are cached and keyed by app
  version, see 1.0.6 below).

## [1.0.6]

### Added
- `GET /api/storage`: per-user database storage usage (article + highlight
  text size), used by the iOS app's Settings screen to show server and
  local cache size side by side. Version bump is required for Nextcloud to
  pick up the new route (route table is cached and keyed by app version).

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