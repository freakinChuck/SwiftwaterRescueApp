#!/usr/bin/env bash
# Lädt osm.pmtiles nach <Webroot>/tiles/osm.pmtiles (Issue #106). Erst unter neuem Namen, dann umbenannt: Geräte sehen nie eine halbe Datei.
#   ./upload.sh benutzer@host /pfad/zum/webroot [-i ~/.ssh/schluessel]
set -euo pipefail
DEST="$1"; ROOT="$2"; shift 2; F="${F:-osm.pmtiles}"
[ -f "$F" ] || { echo "$F fehlt (zuerst build.sh)" >&2; exit 1; }
ssh "$@" "$DEST" "mkdir -p '$ROOT/tiles'"
scp "$@" "$F" "$DEST:$ROOT/tiles/.osm.pmtiles.neu"
ssh "$@" "$DEST" "mv -f '$ROOT/tiles/.osm.pmtiles.neu' '$ROOT/tiles/osm.pmtiles' && ls -l '$ROOT/tiles/osm.pmtiles'"
