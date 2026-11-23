#!/bin/sh
# Stellt das Release-Paket des Standalone-Servers zusammen, dieselbe Ordner-
# struktur für den Tarball (Release-Workflow) und das Docker-Image:
#
#   <ziel>/            bisher standalone/: bin/, config/, migrations/, public/,
#                      src/, templates/, vendor/ (mit merlin/core als Kopie)
#   <ziel>/content-filters/   mitgelieferte Content-Filter (aus dem Repo-Wurzelordner)
#   <ziel>/l10n/              Übersetzungen der Vue-App (aus nextcloud/l10n/)
#   <ziel>/public/img/        Icons und Bilder (aus nextcloud/img/)
#   <ziel>/VERSION            Version aus nextcloud/appinfo/info.xml
#
# Ohne nextcloud/ daneben findet der Server Übersetzungen, Bilder und die
# Version dort (siehe AppController, AssetController, Version).
#
# Voraussetzungen: composer im PATH und die gebaute Weboberfläche in
# standalone/public/app/ (cd frontend && npm run build:standalone).
#
#   standalone/tools/assemble.sh build/merlin-standalone
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)
out=${1:?Aufruf: assemble.sh <zielordner>}

if [ -z "$(ls "$root/standalone/public/app/js" 2>/dev/null)" ]; then
	echo "Weboberfläche fehlt: zuerst (cd frontend && npm run build:standalone)" >&2
	exit 1
fi
if [ -e "$out" ]; then
	echo "Ziel $out gibt es schon" >&2
	exit 1
fi

# Composer braucht ../core neben dem Paket (Path-Repository); mit
# COMPOSER_MIRROR_PATH_REPOS kopiert es den Kern nach vendor/ statt zu verlinken.
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
cp -R "$root/core" "$stage/core"
rm -rf "$stage/core/tests"
mkdir "$stage/standalone"
for item in .htaccess bin composer.json config migrations public src templates README.md; do
	cp -R "$root/standalone/$item" "$stage/standalone/"
done
rm -f "$stage/standalone/config/config.php"
(cd "$stage/standalone" && COMPOSER_MIRROR_PATH_REPOS=1 composer install \
	--no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet)
# Tests der Abhängigkeiten (readability.php allein bringt 27 MB Fixtures mit).
find "$stage/standalone/vendor" -mindepth 3 -maxdepth 3 -type d \( -name test -o -name tests \) -exec rm -rf {} +
# Source-Maps braucht der Betrieb nicht (rund 18 MB).
find "$stage/standalone/public/app" -name '*.map' -delete

cp -R "$root/content-filters" "$stage/standalone/content-filters"
mkdir "$stage/standalone/l10n"
cp "$root"/nextcloud/l10n/*.json "$stage/standalone/l10n/"
mkdir -p "$stage/standalone/public/img"
cp -R "$root"/nextcloud/img/. "$stage/standalone/public/img/"
sed -n 's#.*<version>\([^<]*\)</version>.*#\1#p' "$root/nextcloud/appinfo/info.xml" | head -n 1 > "$stage/standalone/VERSION"
for doc in LICENSE NOTICE CHANGELOG.md; do
	[ -f "$root/$doc" ] && cp "$root/$doc" "$stage/standalone/"
done

mkdir -p "$(dirname "$out")"
mv "$stage/standalone" "$out"
echo "Paket $(cat "$out/VERSION") in $out"
