<?php

declare(strict_types=1);

// Nach config.php kopieren und anpassen (config.php ist gitignored).
// Statt config/config.php kann die Umgebungsvariable MERLIN_CONFIG auf eine
// andere Datei zeigen (Docker, Tests).
return [
	// SQLite-Datenbank und Logdatei. Das Verzeichnis muss für den Webserver
	// beschreibbar sein (SQLite legt daneben -wal- und -shm-Dateien an).
	'db_path' => __DIR__ . '/../data/merlin.sqlite',
	'log_path' => __DIR__ . '/../data/merlin.log',

	// Ablage für Merlin Dateien (Uploads vom Handy). Ohne Angabe: Ordner
	// files/ neben der Datenbank. Muss für den Webserver beschreibbar sein und
	// darf nicht im Webroot liegen.
	// 'files_path' => __DIR__ . '/../data/files',

	// Basis-URL des Servers ohne Schrägstrich am Ende: für Links in Mails,
	// signierte Links und absolute Bild-URLs (auch bei CLI-Aufrufen).
	'base_url' => 'https://merlin.example.com',

	// Basis-Pfad, falls der Server unter einem Unterpfad läuft (z. B.
	// '/tools/merlin'). Der Router zieht ihn von REQUEST_URI ab.
	'base_path' => '/',

	'mail' => [
		'from_address' => 'merlin@example.com',
		'from_name' => 'Merlin',
	],

	// Gültigkeit von Passwort-Reset-Tokens in Sekunden.
	'password_reset_ttl' => 3600,

	// Instanz-Geheimnis: 32 Byte, base64. Verschlüsselt Paywall-Zugangsdaten
	// (Port Crypto) und signiert Links (Port InstanceSecret). Erzeugen mit
	//   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
	// Nach dem ersten Einsatz nicht mehr ändern, sonst sind gespeicherte
	// Zugangsdaten unlesbar.
	'secret' => '',

	// Tägliche Aufgaben (Löschfrist für archivierte Artikel, abgelaufene
	// Share-Links): 'web' holt sie beim Öffnen des Readers nach, sobald der
	// letzte Lauf 24 Stunden zurückliegt; 'cron', wenn der System-Cron
	//   0 * * * * php /pfad/zu/standalone/bin/merlin cron
	// sie übernimmt (beide Wege laufen höchstens einmal am Tag).
	'background_jobs' => 'web',

	// Mindeststufe für die Logdatei: debug, info, notice, warning, error.
	'log_level' => 'info',
];
