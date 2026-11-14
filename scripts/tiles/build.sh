#!/usr/bin/env bash
# Eigene OSM-Karte (Issue #106): PMTiles-Datei mit Vektorkacheln für die Kartenanbieter-Option «OSM-Karte (eigener Server)».
# Schneidet aus dem täglichen Protomaps-Build (OpenStreetMap, ODbL) einen Ausschnitt aus. Es werden nur die nötigen Teile geladen, nicht der ganze Planet.
# Ergebnis: osm.pmtiles im aktuellen Ordner (bzw. OUT), danach mit upload.sh oder upload.ps1 nach <Webroot>/tiles/osm.pmtiles laden.
#
#   ./build.sh                                   # Schweiz mit Grenzgebieten, Zoom bis 15
#   BBOX=5.9,45.8,10.5,47.8 MAXZOOM=14 ./build.sh   # kleinerer Ausschnitt, kleinere Datei
#
# Braucht: pmtiles (https://github.com/protomaps/go-pmtiles/releases, eine einzelne Datei), curl.
set -euo pipefail
BBOX="${BBOX:-5.6,45.6,10.9,48.0}"   # West, Süd, Ost, Nord (Grad WGS84): Schweiz, Liechtenstein und rund 25 km Grenzgebiet
MAXZOOM="${MAXZOOM:-15}"             # 15 = höchste Stufe des Protomaps-Builds; darüber zeichnet die App die Daten vergrössert
OUT="${OUT:-osm.pmtiles}"
command -v pmtiles >/dev/null || { echo "pmtiles fehlt: https://github.com/protomaps/go-pmtiles/releases" >&2; exit 1; }

# neuester Build laut Protomaps (Liste der Builds), sonst die letzten Tage der Reihe nach versuchen
SRC="${SRC:-}"
if [ -z "$SRC" ]; then
  KEY=$(curl -fsS https://build-metadata.protomaps.dev/builds.json 2>/dev/null | grep -o '"key": *"[0-9]\{8\}\.pmtiles"' | tail -1 | grep -o '[0-9]\{8\}\.pmtiles' || true)
  if [ -n "$KEY" ]; then SRC="https://build.protomaps.com/$KEY"; else
    for d in 0 1 2 3 4 5 6 7; do U="https://build.protomaps.com/$(date -u -d "-$d day" +%Y%m%d).pmtiles"; if curl -fsSI "$U" >/dev/null 2>&1; then SRC="$U"; break; fi; done
  fi
fi
[ -n "$SRC" ] || { echo "Kein Protomaps-Build gefunden, bitte SRC=<Adresse> setzen" >&2; exit 1; }
echo "Quelle $SRC, Ausschnitt $BBOX, Zoom bis $MAXZOOM"
TMP="$OUT.tmp"
pmtiles extract "$SRC" "$TMP" --bbox="$BBOX" --maxzoom="$MAXZOOM"
pmtiles show "$TMP" | head -20
mv -f "$TMP" "$OUT"
echo "Fertig: $OUT ($(du -h "$OUT" | cut -f1))"
