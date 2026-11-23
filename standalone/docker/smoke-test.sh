#!/bin/sh
# Kurzer Test eines laufenden Standalone-Containers (CI und von Hand):
#   standalone/docker/smoke-test.sh http://127.0.0.1:8080 admin <passwort>
# Prüft Capabilities, Login, API-Token mit Basic Auth (Header muss bei PHP-FPM
# ankommen), einen schreibenden Aufruf, Weboberfläche, Manifest und .mjs-Typ.
set -eu
base=${1:?Basis-URL}
user=${2:?Benutzer}
pass=${3:?Passwort}
jar=$(mktemp)
trap 'rm -f "$jar"' EXIT
fail() { echo "FEHLER: $*" >&2; exit 1; }
ok() { echo "ok  $*"; }

curl -fsS "$base/api/capabilities" | grep -q '"backend":"standalone"' || fail "/api/capabilities"
ok "capabilities"

code=$(curl -s -o /dev/null -w '%{http_code}' -c "$jar" -b "$jar" -d "username=$user" -d "password=$pass" "$base/login")
case "$code" in 302|303) ;; *) fail "Login: HTTP $code" ;; esac
ok "login"

token=$(curl -fsS -b "$jar" -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' \
	-d '{"name":"smoke-test"}' "$base/account/tokens" | sed -n 's/.*"plainText":"\([^"]*\)".*/\1/p')
[ -n "$token" ] || fail "API-Token anlegen"
ok "api token"

code=$(curl -s -o /dev/null -w '%{http_code}' -u "$user:$token" "$base/api/articles")
[ "$code" = 200 ] || fail "GET /api/articles mit Basic Auth: HTTP $code"
code=$(curl -s -o /dev/null -w '%{http_code}' -u "$user:$token" -H 'Content-Type: application/json' -d "{\"name\":\"smoke-$(date +%s)-$$\"}" "$base/api/tags")
case "$code" in 200|201) ;; *) fail "POST /api/tags: HTTP $code" ;; esac
ok "basic auth api"

page=$(curl -fsS -b "$jar" "$base/")
echo "$page" | grep -q 'id="merlin-state"' || fail "Reader-Seite ohne Startwerte"
echo "$page" | grep -q 'rel="manifest"' || fail "Reader-Seite ohne Manifest"
module=$(echo "$page" | sed -n 's/.*src="\([^"]*\.mjs\)".*/\1/p' | head -n 1)
[ -n "$module" ] || fail "kein Modul im Reader"
case "$module" in http*) url=$module ;; *) url="$base$module" ;; esac
type=$(curl -s -o /dev/null -w '%{content_type}' "$url")
case "$type" in text/javascript*|application/javascript*) ;; *) fail "$module: Content-Type $type" ;; esac
ok "reader ($module)"

curl -fsS "$base/manifest.webmanifest" | grep -q '"start_url"' || fail "Manifest"
code=$(curl -s -o /dev/null -w '%{http_code}' "$base/img/icon-192.png")
[ "$code" = 200 ] || fail "Icon: HTTP $code"
ok "pwa"
echo "Alles in Ordnung."
