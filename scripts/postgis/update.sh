#!/usr/bin/env bash
# Gewässernetz für Swiftwater Rescue in PostGIS laden bzw. aktualisieren (Issue #131).
# Lädt den OSM-Auszug der Schweiz von Geofabrik, importiert mit osm2pgsql (waterways.lua) in ein Hilfsschema
# und ersetzt danach die Tabelle in einer Transaktion. Während des Imports antwortet die App weiter mit dem alten Stand.
# Für cron, z. B. wöchentlich: 15 3 * * 1 /opt/swiftwater/postgis/update.sh >> /var/log/swiftwater-postgis.log 2>&1
#
# Umgebung (Standardwerte in Klammern):
#   PGDATABASE (swiftwater_osm), PGHOST, PGPORT, PGUSER, PGPASSWORD wie bei psql
#   SW_PBF_URL (https://download.geofabrik.de/europe/switzerland-latest.osm.pbf)
#   SW_WORKDIR (/var/tmp/swiftwater-osm), SW_TABLE (sw_waterways), SW_READER (swiftwater_app: Benutzer der App, erhält Leserechte)
set -euo pipefail

export PGDATABASE="${PGDATABASE:-swiftwater_osm}"
URL="${SW_PBF_URL:-https://download.geofabrik.de/europe/switzerland-latest.osm.pbf}"
WORK="${SW_WORKDIR:-/var/tmp/swiftwater-osm}"
TABLE="${SW_TABLE:-sw_waterways}"
READER="${SW_READER:-swiftwater_app}"
DIR="$(cd "$(dirname "$0")" && pwd)"
STAGE=sw_import

mkdir -p "$WORK"
cd "$WORK"
echo "$(date -Is) Lade $URL"
# -N: nur laden, wenn Geofabrik einen neueren Stand hat
wget -q -N "$URL"
PBF="$WORK/$(basename "$URL")"

echo "$(date -Is) Importiere $PBF nach $STAGE.sw_waterways"
psql -v ON_ERROR_STOP=1 -q -c "CREATE EXTENSION IF NOT EXISTS postgis; DROP SCHEMA IF EXISTS $STAGE CASCADE; CREATE SCHEMA $STAGE;"
osm2pgsql --create --output=flex --style="$DIR/waterways.lua" --schema="$STAGE" --cache=1000 "$PBF"

N=$(psql -tA -c "SELECT count(*) FROM $STAGE.sw_waterways")
echo "$(date -Is) $N Gewässer importiert"
if [ "$N" -lt 1000 ]; then
  echo "Zu wenige Gewässer, die bisherige Tabelle bleibt." >&2
  exit 1
fi

# Tabelle in einer Transaktion ersetzen; Leserechte für den Benutzer der App (falls vorhanden)
RENAME=""
if [ "$TABLE" != "sw_waterways" ]; then RENAME="ALTER TABLE $STAGE.sw_waterways RENAME TO $TABLE;"; fi
psql -v ON_ERROR_STOP=1 -q <<SQL
BEGIN;
$RENAME
DROP TABLE IF EXISTS public.$TABLE;
ALTER TABLE $STAGE.$TABLE SET SCHEMA public;
DO \$\$ BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$READER') THEN
    EXECUTE 'GRANT SELECT ON public.$TABLE TO $READER';
  END IF;
END \$\$;
COMMIT;
DROP SCHEMA $STAGE CASCADE;
ANALYZE public.$TABLE;
SQL
echo "$(date -Is) Fertig: public.$TABLE"
