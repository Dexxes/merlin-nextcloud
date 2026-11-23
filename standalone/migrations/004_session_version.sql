-- Nur Standalone: Zähler für die Browser-Sitzungen eines Kontos. Die Session
-- merkt sich den Wert beim Login (Auth\SessionService); ein Passwort-Reset
-- erhöht ihn und meldet damit alle bestehenden Sitzungen ab.
ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 0;
