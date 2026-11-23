#!/bin/sh
# Gibt den Abschnitt „## [<version>]“ aus CHANGELOG.md aus (ohne die
# Überschrift), für die Release-Notizen. Fehlt er, Exit-Code 1.
#   tools/release-notes.sh 1.1.0
set -eu
version=${1:?Aufruf: release-notes.sh <version>}
root=$(cd "$(dirname "$0")/.." && pwd)
awk -v v="$version" '
	/^## \[/ { if (found) exit; if (index($0, "## [" v "]") == 1) { found = 1; next } }
	found { print }
	END { if (!found) exit 1 }
' "$root/CHANGELOG.md"
