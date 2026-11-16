#!/bin/sh
# Installs/updates the Merlin app from the image (/usr/src/merlin) into the
# persistent custom_apps directory and enables it. Called by the official
# Nextcloud entrypoint as www-data before every start (also right after the
# first installation) - so rebuilding the image ships new app code.
set -eu

occ() { php /var/www/html/occ "$@"; }

if ! occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
    echo "[merlin] Nextcloud not installed yet, skipping"
    exit 0
fi

echo "[merlin] syncing app into custom_apps/merlin"
mkdir -p /var/www/html/custom_apps
rsync -a --delete /usr/src/merlin/ /var/www/html/custom_apps/merlin/

# A changed app version requires a database upgrade (migrations) first.
if occ status --output=json | grep -q '"needsDbUpgrade":true'; then
    echo "[merlin] running occ upgrade"
    occ upgrade --no-interaction
fi

occ app:enable merlin

# Merlin's retention cleanup is a background job; the compose setup runs a
# dedicated cron container, so switch Nextcloud from AJAX to system cron.
occ background:cron
