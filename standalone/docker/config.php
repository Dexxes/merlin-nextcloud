<?php

declare(strict_types=1);

// Konfiguration des Docker-Images aus Umgebungsvariablen. Wer mehr einstellen
// will, legt /data/config.php an (Vorlage: config/config.sample.php); dann
// gilt nur diese Datei.
$env = static fn (string $name, string $default = ''): string => (string) (getenv($name) ?: $default);

return [
	'db_path' => '/data/merlin.sqlite',
	'log_path' => '/data/merlin.log',
	'base_url' => rtrim($env('MERLIN_BASE_URL', 'http://localhost:8080'), '/'),
	'base_path' => $env('MERLIN_BASE_PATH', '/'),
	'mail' => [
		'from_address' => $env('MERLIN_MAIL_FROM', 'merlin@localhost'),
		'from_name' => $env('MERLIN_MAIL_FROM_NAME', 'Merlin'),
	],
	// Ohne MERLIN_SECRET erzeugt der Container beim ersten Start /data/secret.
	'secret' => $env('MERLIN_SECRET') ?: trim((string) @file_get_contents('/data/secret')),
	// Der Container ruft bin/merlin cron stündlich selbst auf.
	'background_jobs' => 'cron',
	'log_level' => $env('MERLIN_LOG_LEVEL', 'info'),
];
