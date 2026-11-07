# Gewässernetz aus PostGIS (Issue #131)

Die App braucht vom OpenStreetMap-Datenbestand nur das Gewässernetz (`waterway=river|stream|canal`). Statt bei jeder Abfrage
öffentliche Overpass-Server zu fragen (Limits, Ausfälle, vom Hosting aus oft abgelehnt), kann der Server eine eigene
PostgreSQL-Datenbank mit PostGIS abfragen. Sie wird aus dem Auszug der Schweiz von Geofabrik gefüllt und regelmässig aktualisiert.

Ablauf in der App (`overpassServe` in `web/api/index.php`): Zwischenspeicher → PostGIS → Overpass-Server → ältere Daten → Rückfall im Gerät.
Antwortet PostGIS nicht, läuft alles wie bisher über Overpass.

## Voraussetzungen

- Ein Server mit PostgreSQL ≥ 13, PostGIS ≥ 3 und osm2pgsql ≥ 1.9 (Flex-Ausgabe mit `--schema`), z. B. ein kleiner VPS.
  Das Hosting cyon «Webhosting Single» bietet kein PostgreSQL; die Datenbank läuft deshalb getrennt.
- Auf dem Webserver der App die PHP-Erweiterung `pdo_pgsql` (in der Verwaltung unter «Gewässerdaten» sichtbar, falls sie fehlt).
- Platz: Download rund 500 MB, Tabelle rund 100 MB, osm2pgsql braucht beim Import 1–2 GB RAM.

## Einrichten (Debian/Ubuntu)

```bash
sudo apt install postgresql postgis osm2pgsql wget
sudo -u postgres createdb swiftwater_osm
sudo -u postgres psql -d swiftwater_osm -c "CREATE EXTENSION postgis;"
# Benutzer der App: nur Leserechte, eigenes Passwort
sudo -u postgres psql -c "CREATE ROLE swiftwater_app LOGIN PASSWORD '…';"
sudo -u postgres psql -d swiftwater_osm -c "GRANT USAGE ON SCHEMA public TO swiftwater_app;"
# erster Import (als Benutzer mit Schreibrechten, z. B. postgres)
sudo -u postgres PGDATABASE=swiftwater_osm ./update.sh
```

Zugriff von aussen: in `postgresql.conf` `listen_addresses`, in `pg_hba.conf` eine Zeile `hostssl swiftwater_osm swiftwater_app <IP des Hostings>/32 scram-sha-256`,
Firewall nur für die IP-Adresse des Hostings öffnen. Verbindung möglichst verschlüsselt (in der Verwaltung «Nur verschlüsselt»).

## Aktualisieren

`update.sh` lädt den Auszug nur, wenn Geofabrik einen neueren anbietet (täglich neu), importiert in ein Hilfsschema und ersetzt
die Tabelle in einer Transaktion. Wöchentlich genügt, z. B. per cron:

```
15 3 * * 1 /opt/swiftwater/postgis/update.sh >> /var/log/swiftwater-postgis.log 2>&1
```

Der Zwischenspeicher der App (Verwaltung → Gewässerdaten, Haltedauer) hält Antworten weiter so lange wie eingestellt.

## In der App einschalten

Verwaltung → Karte & Daten → Gewässerdaten → «PostGIS (Hauptquelle)»: Host, Port, Datenbank, Benutzer, Passwort, Tabelle
(Standard `sw_waterways`), Verschlüsselung. «Verbindung testen» prüft Verbindung, PostGIS, Tabelle, SRID (4326) und fragt
5 km um Bern ab. Die Statistik darunter zeigt PostGIS als eigene Zeile.

## Tabelle

| Spalte     | Typ                        | Inhalt                                  |
|------------|----------------------------|-----------------------------------------|
| `id`       | `bigint`                   | OSM-Weg                                 |
| `waterway` | `text`                     | `river`, `stream` oder `canal`          |
| `name`     | `text`                     | Name des Gewässers (falls vorhanden)    |
| `geom`     | `geometry(LineString,4326)`| Verlauf, Richtung wie in OSM (flussabwärts) |

Eine andere Quelle (z. B. eigene Daten) geht ebenso, solange die Tabelle diese Spalten hat. Knoten-IDs sind nicht nötig:
Die App verbindet Wege über gleiche Koordinaten.

Daten © OpenStreetMap-Mitwirkende, Lizenz ODbL (Quellenangabe in `legal.html`).
