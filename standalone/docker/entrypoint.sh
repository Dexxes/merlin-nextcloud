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
	if [ -z "${MERLIN_SECRET:-}" ] && [ ! -s /data/secret ]; then
		php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > /data/secret
		chown www-data:www-data /data/secret
		chmod 600 /data/secret
		echo "merlin: Instanz-Geheimnis in /data/secret angelegt"
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
