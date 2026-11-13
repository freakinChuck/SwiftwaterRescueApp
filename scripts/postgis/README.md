# Gewässernetz aus PostGIS (Issue #131)

Die App braucht vom OpenStreetMap-Datenbestand nur das Gewässernetz (`waterway=river|stream|canal`). Statt bei jeder Abfrage
öffentliche Overpass-Server zu fragen (Limits, Ausfälle, vom Hosting aus oft abgelehnt), kann der Server eine eigene
PostgreSQL-Datenbank mit PostGIS abfragen. Sie wird aus dem Auszug der Schweiz von Geofabrik gefüllt und regelmässig aktualisiert.

Derselbe Import füllt eine zweite Tabelle `sw_features` mit Objekten am Gewässer (Wehre, Kraftwerke, Brücken, Slipanlagen …,
Issue #182) für die Vorbereitungsansicht «Geodaten übernehmen» und die Geodaten-Ebene der Einsatz-App (siehe unten).

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

`update.sh` lädt den Auszug nur, wenn Geofabrik einen neueren anbietet (täglich neu), importiert in ein Hilfsschema, bereitet
`sw_features` auf (nur Brücken über Gewässer, Name des nächsten Gewässers, Index auf `kind`) und ersetzt beide Tabellen in einer
Transaktion. Kommen zu wenige Gewässer (`SW_MIN_WATER`, Standard 1000) oder Objekte (`SW_MIN_FEAT`, Standard 100) an, bleiben die
bisherigen Tabellen. Unter Windows macht `update.bat` dasselbe (Pfade und Zugang oben in der Datei). Wöchentlich genügt, z. B. per cron:

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

### `sw_features` (Issue #182)

| Spalte     | Typ                     | Inhalt                                                                  |
|------------|-------------------------|-------------------------------------------------------------------------|
| `osm_type` | `char(1)`               | `N` (Knoten), `W` (Weg) oder `R` (Relation)                             |
| `osm_id`   | `bigint`                | OSM-ID, zusammen mit `osm_type` eindeutig (Abgleich mit der Quelle)     |
| `kind`     | `text`                  | Objektart, siehe unten                                                  |
| `name`     | `text`                  | `name`, bei Brücken auch `bridge:name`                                  |
| `water`    | `text`                  | Name des nächsten Gewässers im Umkreis `SW_NEAR` (Standard 0.001°, ca. 75–110 m) |
| `tags`     | `jsonb`                 | ausgewählte Merkmale (Liste `KEEP` in `waterways.lua`)                  |
| `geom`     | `geometry(Geometry,4326)` | Punkt, Linie (z. B. Wehr quer zum Fluss, Brücke) oder Fläche (Kraftwerk, Staumauer) |

| `kind`   | Bezeichnung                 | OSM-Merkmale |
|----------|-----------------------------|--------------|
| `dam`    | Staumauer / Damm            | `waterway=dam` |
| `weir`   | Wehr / Schwelle             | `waterway=weir`, `waterway=drop` |
| `plant`  | Wasserkraftwerk             | `power=plant` + `plant:source=hydro`, `power=generator` + `generator:source=hydro` |
| `lock`   | Schleuse                    | `waterway=lock_gate`, `lock=yes` |
| `bridge` | Brücke über ein Gewässer    | `bridge=*` auf `highway`/`railway`, nur wenn sie einen Weg aus `sw_waterways` kreuzt |
| `slip`   | Slipanlage / Einwasserung   | `leisure=slipway`, `canoe=put_in`, `whitewater=put_in` |
| `egress` | Ausstieg                    | `canoe=egress`, `whitewater=egress` |
| `fall`   | Wasserfall / Stromschnelle  | `waterway=waterfall`, `waterway=rapids`, `whitewater=rapid` |
| `rack`   | Rechen / Treibgutfang       | `waterway=debris_screen`, `man_made=debris_screen` |
| `rescue` | Rettungspunkt               | `highway=emergency_access_point`, `emergency=access_point` |
| `ring`   | Rettungsring                | `emergency=life_ring` |
| `pier`   | Steg / Anlegestelle         | `man_made=pier`, `leisure=marina` |

Für die PostGIS-API ([ELSSwift/PostGISApi](https://github.com/ELSSwift/PostGISApi)) die Tabelle als eigenen Layer
(Standard `sw_features`) freigeben, mit den Eigenschaften `osm_type`, `osm_id`, `kind`, `name`, `water`, `tags`.

Eine andere Quelle (z. B. eigene Daten) geht ebenso, solange die Tabelle diese Spalten hat. Knoten-IDs sind nicht nötig:
Die App verbindet Wege über gleiche Koordinaten.

Daten © OpenStreetMap-Mitwirkende, Lizenz ODbL (Quellenangabe in `legal.html`).
