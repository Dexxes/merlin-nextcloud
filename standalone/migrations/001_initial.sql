-- Erstes Schema des Standalone-Servers.
--
-- Die merlin_*-Tabellen entsprechen dem Stand der Nextcloud-Migrationen
-- (nextcloud/lib/Migration/, Version1000Date20240101000035): gleiche Tabellen,
-- Spalten, Nullbarkeit, Defaults und Indizes, damit die Repositories des Kerns
-- in beiden Varianten dasselbe SQL ausführen. tools/test-schema-parity.php
-- vergleicht das bei jedem PR. Vorlage für neue Tabellen oder Spalten:
-- `php tools/schema/print-sqlite-ddl.php merlin_tabelle`.
--
-- user_id ist wie in Nextcloud Text: die Nutzer-ID aus users.id als String.
-- Fremdschlüssel gibt es in den merlin_*-Tabellen deshalb nicht (wie in
-- Nextcloud); beim Löschen eines Nutzers räumt der Server sie selbst auf.
--
-- Darunter die Tabellen, die nur der Standalone-Server hat (Konten, Tokens,
-- Login-Flow, Einstellungen). users, api_tokens, password_reset_tokens und
-- login_flow_tokens sind unverändert aus merlin-standalone-server übernommen
-- (src/Migration/migrations/001_initial.sql und 002_login_flow.sql, Stand
-- fe8ed23).

CREATE TABLE merlin_article_tags (
    article_id BIGINT NOT NULL,
    tag_id BIGINT NOT NULL,
    PRIMARY KEY (article_id, tag_id)
);
CREATE INDEX merlin_article_tags_article_id ON merlin_article_tags (article_id);
CREATE INDEX merlin_article_tags_tag_id ON merlin_article_tags (tag_id);

CREATE TABLE merlin_articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    url TEXT NOT NULL,
    title VARCHAR(512) NOT NULL,
    content TEXT NOT NULL,
    excerpt TEXT,
    author VARCHAR(255),
    site_name VARCHAR(255),
    image_url TEXT,
    is_read SMALLINT NOT NULL DEFAULT 0,
    is_archived SMALLINT NOT NULL DEFAULT 0,
    reading_time INTEGER DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    published_at DATETIME,
    archived_at DATETIME,
    is_processing INTEGER NOT NULL DEFAULT 0,
    category VARCHAR(64),
    scroll_progress DOUBLE PRECISION DEFAULT 0,
    scroll_updated_at BIGINT DEFAULT 0,
    is_favorite DATETIME,
    requires_login_domain VARCHAR(255),
    requires_login_page VARCHAR(2048),
    is_paywalled SMALLINT NOT NULL DEFAULT 0,
    paywall_subscribe_url VARCHAR(2048),
    unsupported_site_domain VARCHAR(255),
    site_icon_url VARCHAR(2048),
    author_url VARCHAR(2048),
    authors TEXT,
    file_id BIGINT,
    file_mime VARCHAR(255),
    file_text TEXT
);
CREATE INDEX merlin_art_user_arch_idx ON merlin_articles (user_id, is_archived, archived_at);
CREATE INDEX merlin_art_user_cat_idx ON merlin_articles (user_id, category);
CREATE INDEX merlin_articles_created_at ON merlin_articles (created_at);
CREATE INDEX merlin_articles_is_archived ON merlin_articles (is_archived);
CREATE INDEX merlin_articles_is_favorite ON merlin_articles (is_favorite);
CREATE INDEX merlin_articles_is_read ON merlin_articles (is_read);
CREATE INDEX merlin_articles_user_file ON merlin_articles (user_id, file_id);
CREATE INDEX merlin_articles_user_id ON merlin_articles (user_id);

CREATE TABLE merlin_cfilter (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    scope VARCHAR(8) NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    xml TEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    updated_by VARCHAR(64) NOT NULL
);
CREATE UNIQUE INDEX merlin_cfilter_sdu_uidx ON merlin_cfilter (scope, domain, user_id);
CREATE INDEX merlin_cfilter_su_idx ON merlin_cfilter (scope, user_id);

CREATE TABLE merlin_comment_guests (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    article_id INTEGER NOT NULL,
    name_key VARCHAR(64) NOT NULL,
    name VARCHAR(64) NOT NULL,
    color VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX merlin_cguest_color_uq ON merlin_comment_guests (article_id, user_id, color);
CREATE UNIQUE INDEX merlin_cguest_name_uq ON merlin_comment_guests (article_id, user_id, name_key);

CREATE TABLE merlin_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    article_id INTEGER NOT NULL,
    highlight_id INTEGER,
    quoted_text TEXT,
    parent_id INTEGER,
    reply_to_id INTEGER,
    author_type VARCHAR(8) NOT NULL,
    author_name VARCHAR(64) NOT NULL,
    author_name_key VARCHAR(64) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME
);
CREATE INDEX merlin_cmt_article_idx ON merlin_comments (article_id, user_id);
CREATE INDEX merlin_cmt_parent_idx ON merlin_comments (parent_id);

CREATE TABLE merlin_highlights (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    article_id INTEGER NOT NULL,
    highlighted_text TEXT NOT NULL,
    start_xpath TEXT NOT NULL,
    start_offset INTEGER NOT NULL,
    end_xpath TEXT NOT NULL,
    end_offset INTEGER NOT NULL,
    color VARCHAR(32) NOT NULL DEFAULT 'yellow',
    created_at DATETIME NOT NULL,
    author_type VARCHAR(8) NOT NULL DEFAULT 'owner',
    author_name VARCHAR(64),
    author_name_key VARCHAR(64),
    pdf_page INTEGER,
    pdf_rects TEXT,
    revision INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX merlin_hl_user_article_idx ON merlin_highlights (user_id, article_id);

CREATE TABLE merlin_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX merlin_settings_user_key ON merlin_settings (user_id, setting_key);

CREATE TABLE merlin_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    article_id INTEGER NOT NULL,
    token VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255),
    expires_at DATETIME,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    allow_comments BOOLEAN DEFAULT 1
);
CREATE UNIQUE INDEX merlin_shares_article_uidx ON merlin_shares (article_id);
CREATE UNIQUE INDEX merlin_shares_token_uidx ON merlin_shares (token);

CREATE TABLE merlin_site_cred (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    username_enc TEXT NOT NULL,
    password_enc TEXT NOT NULL,
    session_cookies_enc TEXT,
    cookie_expires_at DATETIME,
    last_login_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    last_login_at DATETIME,
    created_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX merlin_site_cred_ud_uidx ON merlin_site_cred (user_id, domain);

CREATE TABLE merlin_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) DEFAULT '#0082c9',
    created_at DATETIME NOT NULL,
    parent_id BIGINT
);
CREATE INDEX merlin_tags_user_id ON merlin_tags (user_id);
CREATE UNIQUE INDEX merlin_tags_user_name ON merlin_tags (user_id, name);
CREATE INDEX merlin_tags_user_parent ON merlin_tags (user_id, parent_id);

-- ---------------------------------------------------------------------------
-- Nur Standalone
-- ---------------------------------------------------------------------------

CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('admin', 'user')),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    last_used_at TEXT,
    revoked_at TEXT
);
CREATE INDEX idx_api_tokens_user ON api_tokens(user_id);

CREATE TABLE password_reset_tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT
);
CREATE INDEX idx_password_reset_tokens_user ON password_reset_tokens(user_id);

CREATE TABLE login_flow_tokens (
    id INTEGER PRIMARY KEY,
    flow_token TEXT NOT NULL UNIQUE,
    poll_token TEXT NOT NULL UNIQUE,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    api_token_plaintext TEXT,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);
CREATE INDEX idx_login_flow_tokens_expires ON login_flow_tokens(expires_at);

-- Instanzweite Werte (Port AppSettings, in Nextcloud IAppConfig der App merlin)
-- und die Admin-Schalter der Konto-Verwaltung (z. B. Registrierung offen).
CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

-- Werte je Nutzer (Port UserSettings, in Nextcloud IConfig-Nutzerwerte):
-- section entspricht der App-ID in Nextcloud (meist 'merlin').
CREATE TABLE user_settings (
    user_id TEXT NOT NULL,
    section TEXT NOT NULL,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    PRIMARY KEY (user_id, section, key)
);

