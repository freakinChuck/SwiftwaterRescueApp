# Eigene OSM-Karte als PMTiles (Issue #106)

Die Kartenanbieter-Option «OSM-Karte (eigener Server)» zeigt eine Vektorkarte aus OpenStreetMap-Daten, die als **eine Datei**
auf dem eigenen Webserver liegt: `<Webroot>/tiles/osm.pmtiles`. Die App liest daraus über HTTP-Teilabfragen (Range) nur die
Kacheln, die sie braucht. Es braucht keinen Kachelserver und keinen Dauerprozess, das läuft auch auf cyon «Webhosting Single».
Kartenausschnitte gehen dabei an niemanden sonst. Dargestellt wird die Karte mit protomaps-leaflet (Stil «light», Gewässer kräftiger,
siehe `vecLayer` in `web/common.js`).

Solange die Datei fehlt, steht die Karte in der Verwaltung als «nicht wählbar» und erscheint in keiner App.
Die Verwaltung zeigt unter Karte & Daten → «Kartenanbieter» Grösse, Stand, Zoomstufen und Ausschnitt der Datei;
«Testen» prüft, ob der Webserver Teilabfragen beantwortet (HTTP 206).

Die Datei kommt **nicht** ins Git und nicht in `$files` von `deploy.ps1` (zu gross, `web/tiles/` steht in `.gitignore`).
Ein Deployment lässt sie stehen (es kopiert nur darüber).

## Erzeugen

### Einfach: Ausschnitt aus dem Protomaps-Build (`build.sh`)

Protomaps veröffentlicht täglich einen Build des ganzen Planeten (OpenStreetMap, ODbL). `pmtiles extract` lädt daraus nur die
Teile für den gewünschten Ausschnitt.

```bash
# pmtiles: eine Datei von https://github.com/protomaps/go-pmtiles/releases
./build.sh                                      # Schweiz, Liechtenstein und rund 25 km Grenzgebiet, Zoom bis 15
BBOX=5.9,45.8,10.5,47.8 MAXZOOM=14 ./build.sh   # kleiner
```

Grösse: geschätzt einige hundert MB für die Schweiz mit Grenzgebiet bis Zoom 15 (Zoom 14 etwa die Hälfte). Vor dem ersten Upload
den freien Speicher beim Hosting prüfen.

### Unabhängig: selbst bauen mit planetiler

Wer nicht vom Protomaps-Build abhängen will, baut die Datei aus dem Geofabrik-Auszug selbst, z. B. auf dem Server der
PostGIS-Datenbank aus Issue #131 (gleicher Datenstand wie das Gewässernetz). Dafür das Profil «basemap» von Protomaps nehmen
(<https://github.com/protomaps/basemaps>, Ordner `tiles/`, planetiler, Java ≥ 21), damit Ebenen und Attribute zum Stil der App
passen; die Standardprofile von planetiler (OpenMapTiles-Schema) passen **nicht**. Ablauf gemäss README des Repos, sinngemäss:

```bash
git clone https://github.com/protomaps/basemaps && cd basemaps/tiles && mvn clean package -DskipTests
java -Xmx4g -jar target/protomaps-basemap-HEAD-with-deps.jar --area=switzerland --download --output=osm.pmtiles
```

Dieser Weg ist hier nicht ausprobiert; Namen der Datei und Optionen bitte im README von protomaps/basemaps nachsehen.

## Hochladen

```bash
./upload.sh benutzer@host /pfad/zum/webroot -i ~/.ssh/schluessel
```

oder unter Windows mit der Deploy-Konfiguration (`host`, `user`, `key`, `path`):

```powershell
powershell -File scripts\tiles\upload.ps1 -File osm.pmtiles -Config scripts\deploy.local.json
```

Beide laden zuerst unter einem anderen Namen hoch und benennen erst dann um, damit Geräte nie eine halbe Datei lesen.
Der Service Worker speichert gelesene Abschnitte für den Offline-Betrieb; nach einer neuen Datei (neues ETag) lädt die App neu.

## Aktualisieren

Monatlich genügt, z. B. per cron auf einem Linux-Server:

```
30 4 1 * * cd /opt/swiftwater/tiles && ./build.sh && ./upload.sh benutzer@host /pfad/zum/webroot -i /root/.ssh/swiftwater >> /var/log/swiftwater-tiles.log 2>&1
```

## Webserver

- Apache/LiteSpeed (cyon): Teilabfragen funktionieren für statische Dateien von selbst. `web/.htaccess` setzt den Typ
  `application/vnd.pmtiles` und schaltet die Komprimierung für `.pmtiles` aus (sonst keine Teilabfragen).
- Lokales DEV (IIS): IIS liefert unbekannte Dateiendungen nicht aus. Im IIS-Manager unter «MIME-Typen» die Endung `.pmtiles`
  mit `application/vnd.pmtiles` ergänzen und die Datei nach `C:\inetpub\swiftwaterdev\<PR>\tiles\osm.pmtiles` kopieren.
- `php -S` (lokal): beantwortet keine Teilabfragen; die Verwaltung meldet das beim «Testen».

## Lizenzen

Daten © OpenStreetMap-Mitwirkende (ODbL), Quellenangabe auf der Karte. protomaps-leaflet (BSD-3-Clause), Protomaps-Basemap bzw.
planetiler (Apache-2.0). Die Seite «Impressum, Lizenzen & Datenschutz» führt die Karte auf, sobald sie eingeschaltet ist und die Datei vorliegt.
