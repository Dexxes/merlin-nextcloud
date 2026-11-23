-- Nur Standalone.
-- Login Flow v2: Name des Clients (User-Agent beim Start), damit die
-- Anmeldeseite zeigt, wer Zugriff bekommt. api_token_plaintext hält das
-- Token ab jetzt verschlüsselt (Port Crypto), bis der Client es abholt.
ALTER TABLE login_flow_tokens ADD COLUMN client_name TEXT;

-- Selbstregistrierung mit Bestätigung per E-Mail: das Konto bleibt
-- inaktiv, bis der Link aus der Mail geöffnet wurde. Gespeichert wird nur
-- der SHA-256-Hash des Tokens.
CREATE TABLE email_confirmations (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL
);
