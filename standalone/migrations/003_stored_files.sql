-- Nur Standalone: Dateien aus „Merlin Dateien“ im Datenverzeichnis
-- (Adapter\LocalFileStorage). Die ID ist die file_id der Datei-Einträge in
-- merlin_articles; in Nextcloud ist es die ID der Datei in Nextcloud Files.
-- path ist relativ zum Ordner des Nutzers, z. B. /Images/Foto.jpg.
CREATE TABLE stored_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    path VARCHAR(4000) NOT NULL,
    mime VARCHAR(255) NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX stored_files_path ON stored_files (user_id, path);
