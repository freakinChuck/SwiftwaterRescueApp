#!/usr/bin/env bash
# Geodaten für Swiftwater Rescue in PostGIS laden bzw. aktualisieren: Gewässernetz (Issue #131) und Objekte am Gewässer (Issue #182).
# Lädt den OSM-Auszug der Schweiz von Geofabrik, importiert mit osm2pgsql (waterways.lua) in ein Hilfsschema,
# bereitet sw_features auf (nur Brücken über Gewässer, Name des nächsten Gewässers) und ersetzt danach beide Tabellen
# in einer Transaktion. Während des Imports antwortet die App weiter mit dem alten Stand.
# Für cron, z. B. wöchentlich: 15 3 * * 1 /opt/swiftwater/postgis/update.sh >> /var/log/swiftwater-postgis.log 2>&1
#
# Umgebung (Standardwerte in Klammern):
#   PGDATABASE (swiftwater_osm), PGHOST, PGPORT, PGUSER, PGPASSWORD wie bei psql
#   SW_PBF_URL (https://download.geofabrik.de/europe/switzerland-latest.osm.pbf)
#   SW_WORKDIR (/var/tmp/swiftwater-osm), SW_TABLE (sw_waterways), SW_FTABLE (sw_features),
#   SW_READER (swiftwater_app: Benutzer der App, erhält Leserechte),
#   SW_MIN_WATER (1000) und SW_MIN_FEAT (100): Mindestanzahl, sonst bleiben die bisherigen Tabellen
#   SW_NEAR (0.001): Umkreis in Grad (ca. 75–110 m) für den Namen des nächsten Gewässers
set -euo pipefail

export PGDATABASE="${PGDATABASE:-swiftwater_osm}"
URL="${SW_PBF_URL:-https://download.geofabrik.de/europe/switzerland-latest.osm.pbf}"
WORK="${SW_WORKDIR:-/var/tmp/swiftwater-osm}"
TABLE="${SW_TABLE:-sw_waterways}"
FTABLE="${SW_FTABLE:-sw_features}"
MIN_WATER="${SW_MIN_WATER:-1000}"
MIN_FEAT="${SW_MIN_FEAT:-100}"
NEAR="${SW_NEAR:-0.001}"
READER="${SW_READER:-swiftwater_app}"
DIR="$(cd "$(dirname "$0")" && pwd)"
STAGE=sw_import

mkdir -p "$WORK"
cd "$WORK"
echo "$(date -Is) Lade $URL"
# -N: nur laden, wenn Geofabrik einen neueren Stand hat
wget -q -N "$URL"
PBF="$WORK/$(basename "$URL")"

echo "$(date -Is) Importiere $PBF nach $STAGE"
psql -v ON_ERROR_STOP=1 -q -c "CREATE EXTENSION IF NOT EXISTS postgis; DROP SCHEMA IF EXISTS $STAGE CASCADE; CREATE SCHEMA $STAGE;"
osm2pgsql --create --output=flex --style="$DIR/waterways.lua" --schema="$STAGE" --cache=1000 "$PBF"

N=$(psql -tA -c "SELECT count(*) FROM $STAGE.sw_waterways")
echo "$(date -Is) $N Gewässer importiert"
if [ "$N" -lt "$MIN_WATER" ]; then
  echo "Zu wenige Gewässer, die bisherigen Tabellen bleiben." >&2
  exit 1
fi

# sw_features aufbereiten: Brücken nur über Gewässer, Name des nächsten Gewässers, Index auf kind
echo "$(date -Is) Bereite $STAGE.sw_features auf"
psql -v ON_ERROR_STOP=1 -q <<SQL
DELETE FROM $STAGE.sw_features f
  WHERE f.kind = 'bridge'
    AND NOT EXISTS (SELECT 1 FROM $STAGE.sw_waterways w WHERE w.geom && f.geom AND ST_Intersects(w.geom, f.geom));
UPDATE $STAGE.sw_features f SET water = (
  SELECT w.name FROM $STAGE.sw_waterways w
  WHERE w.name IS NOT NULL AND ST_DWithin(w.geom, f.geom, $NEAR)
  ORDER BY w.geom <-> f.geom LIMIT 1);
CREATE INDEX ON $STAGE.sw_features (kind);
SQL
psql -tA -F ' ' -c "SELECT kind, count(*) FROM $STAGE.sw_features GROUP BY kind ORDER BY kind" | sed "s/^/$(date -Is)   /"
F=$(psql -tA -c "SELECT count(*) FROM $STAGE.sw_features")
echo "$(date -Is) $F Objekte am Gewässer"
if [ "$F" -lt "$MIN_FEAT" ]; then
  echo "Zu wenige Objekte am Gewässer, die bisherigen Tabellen bleiben." >&2
  exit 1
fi

# Tabellen in einer Transaktion ersetzen; Leserechte für den Benutzer der App (falls vorhanden)
RENAME=""
if [ "$TABLE" != "sw_waterways" ]; then RENAME="ALTER TABLE $STAGE.sw_waterways RENAME TO $TABLE;"; fi
if [ "$FTABLE" != "sw_features" ]; then RENAME="$RENAME ALTER TABLE $STAGE.sw_features RENAME TO $FTABLE;"; fi
psql -v ON_ERROR_STOP=1 -q <<SQL
BEGIN;
$RENAME
DROP TABLE IF EXISTS public.$TABLE;
DROP TABLE IF EXISTS public.$FTABLE;
ALTER TABLE $STAGE.$TABLE SET SCHEMA public;
ALTER TABLE $STAGE.$FTABLE SET SCHEMA public;
DO \$\$ BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$READER') THEN
    EXECUTE 'GRANT SELECT ON public.$TABLE, public.$FTABLE TO $READER';
  END IF;
END \$\$;
COMMIT;
DROP SCHEMA $STAGE CASCADE;
ANALYZE public.$TABLE;
ANALYZE public.$FTABLE;
SQL
echo "$(date -Is) Fertig: public.$TABLE, public.$FTABLE"
