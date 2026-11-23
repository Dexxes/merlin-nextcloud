-- Nur Standalone: Fehlversuche und Anfragen je Aktion und IP für den
-- Brute-Force-Schutz (Adapter\SqlThrottler, z. B. Passwort eines
-- Share-Links) und das Rate-Limit öffentlicher Routen
-- (Http\Middleware\RateLimitMiddleware). In Nextcloud übernehmen das
-- IThrottler und #[AnonRateLimit].
CREATE TABLE security_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    action VARCHAR(64) NOT NULL,
    address VARCHAR(64) NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX security_attempts_lookup ON security_attempts (action, address, created_at);
