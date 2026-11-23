#!/bin/sh
# Start des Standalone-Images: Konfiguration wählen, Instanz-Geheimnis anlegen,
# Migrationen, beim ersten Start optional ein Admin-Konto, stündlicher
# Cron-Lauf, PHP-FPM im Hintergrund, dann das Kommando (nginx).
set -eu
cd /var/www/merlin

as_www() { su-exec www-data "$@"; }

mkdir -p /data
chown www-data:www-data /data

if [ -f /data/config.php ]; then
	export MERLIN_CONFIG=/data/config.php
else
	export MERLIN_CONFIG=/var/www/merlin/config/docker.php
	if [ -n "${MERLIN_SECRET:-}" ]; then
		# Geheimnis aus der Umgebung in die Datei, damit es nicht in der
		# Umgebung von PHP-FPM, nginx und Cron steht (phpinfo(), /proc/*/environ).
		umask 077
		printf '%s\n' "$MERLIN_SECRET" > /data/secret
		umask 022
		chown www-data:www-data /data/secret
		chmod 600 /data/secret
	elif [ ! -s /data/secret ]; then
		php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > /data/secret
		chown www-data:www-data /data/secret
		chmod 600 /data/secret
		echo "merlin: Instanz-Geheimnis in /data/secret angelegt"
	fi
	# SMTP-Passwort wie das Geheimnis: in die Datei, nicht in die Umgebung von PHP.
	if [ -n "${MERLIN_SMTP_PASSWORD:-}" ]; then
		umask 077
		printf '%s\n' "$MERLIN_SMTP_PASSWORD" > /data/smtp-password
		umask 022
		chown www-data:www-data /data/smtp-password
		chmod 600 /data/smtp-password
	fi
fi

as_www php bin/merlin migrate

if [ -n "${MERLIN_ADMIN_USER:-}" ] && [ ! -f /data/.admin-created ]; then
	if [ -z "${MERLIN_ADMIN_EMAIL:-}" ] || [ -z "${MERLIN_ADMIN_PASSWORD:-}" ]; then
		echo "merlin: MERLIN_ADMIN_USER braucht MERLIN_ADMIN_EMAIL und MERLIN_ADMIN_PASSWORD" >&2
		exit 1
	fi
	# Das Beispielpasswort aus docker-compose.yml nie für ein echtes Konto.
	if [ "$MERLIN_ADMIN_PASSWORD" = "bitte-aendern" ] || [ "${#MERLIN_ADMIN_PASSWORD}" -lt 8 ]; then
		echo "merlin: MERLIN_ADMIN_PASSWORD ist das Beispielpasswort oder kürzer als 8 Zeichen, bitte ändern" >&2
		exit 1
	fi
	# Gibt es das Konto schon (Volume aus einer älteren Installation), meldet
	# create-admin das und der Start geht weiter.
	if as_www php bin/merlin create-admin --username="$MERLIN_ADMIN_USER" \
		--email="$MERLIN_ADMIN_EMAIL" --password="$MERLIN_ADMIN_PASSWORD"; then
		as_www touch /data/.admin-created
	fi
fi

# Zugangsdaten nur für diesen Start: nicht an Cron, PHP-FPM (clear_env = no)
# und nginx weiterreichen.
unset MERLIN_ADMIN_USER MERLIN_ADMIN_EMAIL MERLIN_ADMIN_PASSWORD
if [ "$MERLIN_CONFIG" = /var/www/merlin/config/docker.php ]; then
	unset MERLIN_SECRET MERLIN_SMTP_PASSWORD
fi

if [ "${1:-}" = "nginx" ]; then
	# Tägliche Aufgaben: bin/merlin cron arbeitet höchstens einmal in 24 Stunden.
	(
		while true; do
			as_www php bin/merlin cron || true
			sleep 3600
		done
	) &
	php-fpm --daemonize
fi

exec "$@"
