# Merlin Standalone

Eigener Merlin-Server ohne Nextcloud, auf Basis des gemeinsamen Kerns
(`../core`). Ersetzt [`merlin-standalone-server`](https://github.com/Dexxes/merlin-standalone-server)
und wird hier neu aufgebaut (Plan: Etappe 3 ff.). Noch nicht für den Einsatz
gedacht: bisher gibt es nur Schema, Datenbank und Kommandozeile.

```sh
cd standalone
composer install
cp config/config.sample.php config/config.php   # anpassen
bin/merlin migrate                              # SQLite-Datenbank anlegen
```

Vorerst nur SQLite. Das Schema der `merlin_*`-Tabellen ist das der
Nextcloud-App, siehe `migrations/001_initial.sql` und STRUCTURE.md im Repo.
