# Swiftwater Rescue – Projektkontext für Claude Code

Sprache: Oberfläche, Code-Kommentare, Commits und Kommunikation auf Deutsch (Schweizer Rechtschreibung, «ss» statt «ß»).

## Zweck
Web-App/PWA für Einsatzgruppen der Wasserrettung: Alarmierung, Koordination und Lageübersicht bei Einsätzen (vermisste Person im Fliessgewässer). Grundlage ist die Anforderungsspezifikation «SwiftwaterRescue App Specs V0.1» vom 01.11.2026 (PDF, im Claude-Projekt «Swiftwater Rescue App»).

## Architektur
- `index.html`: Einsatz-App mit Karte (Vanilla JS, Leaflet via cdnjs, kein Build-Schritt). Läuft am Handy und im Browser.
- `admin.html`: Verwaltung ohne Karte (Benutzer, Spezialrollen, Einsatzstatistik/-log), nur für die Benutzerrolle Administrator.
- `common.js` / `common.css`: gemeinsamer Code beider Seiten (`api()`, Anmeldung, Dialoge inkl. Passwort ändern, Einsatzlog im Gross-Modus).
- `api/index.php`: JSON-API (PHP, PDO). Routen über `?r=<route>`, Token im Header `X-Token`.
- `api/config.php`: DB-Konfiguration. Standard SQLite (`api/data/`), MySQL über DSN möglich. Nur Platzhalter-Passwort einchecken, echte Zugangsdaten nie committen.
- Hosting-Vorgabe: cyon «Webhosting Single» (PHP, MySQL, keine WebSockets, kein Node-Dauerprozess). Alles muss dort laufen.
- Aktualisierung per Polling (4 s, `incident/state`).
- Das Schema wird beim ersten Aufruf automatisch angelegt und per `ALTER TABLE` migriert (Migrationen schlagen bei vorhandenen Spalten still fehl).

## Entscheide des Auftraggebers
- Karte: swisstopo, Standard **Luftbild** (`swisstopo_sat`), umschaltbar (Landeskarte, OSM). Anbieter müssen leicht austauschbar bleiben (`MAP_PROVIDERS`, `DEFAULT_BASE` oben in `index.html`).
- Karte im Vollbild, alle Bedienelemente in einem versteckten Menü (☰).
- Suchgebiet folgt dem Flusslauf (OSM-Gewässernetz via Overpass, `loadNet`), Ufergebiet `BANK_M` = 30 m je Seite, endet am ersten Sicherungs-/Beobachtungsposten (Rolle mit Flag `is_post`). Rückfall: Kreis um die PLS.
- Fliessgeschwindigkeit: darf von allen Teilnehmenden gesetzt werden; Vorschlag aus BAFU-Abflussdaten (`flow/suggest`, via api.existenz.ch, Quelle BAFU nennen; v ≈ 0.8·Q^0.18 nur grobe Schätzung).
- Nachrichten nur an Personen, die den Einsatz angenommen haben.
- Alarmeingang kann beim Einsatzstart übersteuert werden.
- «Einsatz verwalten» (beenden) darf: Rolle Einsatzleitung, Administratoren, Ersteller des Einsatzes (`canManage`). Einsatzleitung darf Einsatzkräfte entfernen (Wiedereinstieg bleibt möglich) und Rettling-Markierungen entfernen/manuell setzen.
- Rettling-Markierung: alle Teilnehmenden markieren mit eigenem Standort (eine Markierung pro Person).
- Einsatzlog (Beitritt, Rollen, Fahrten, Suchgebiet erreicht, Nachrichten, Markierungen, Entfernen), Gross-Modus im Einsatz und in der History; Einsatzstatistik im Admin-Bereich.
- Fahrten im Einsatzlog: nur Bewegungen mit Fahrzeug, Format «fährt von Ort A nach Ort B (ca. X km Luftlinie, Ø Y km/h)». Erkennung serverseitig in `incident/location`: Geschwindigkeit zwischen zwei Standortmeldungen ≥ `VEHICLE_MS` (7 m/s ≈ 25 km/h), Ende nach `STOP_S` (90 s) ohne schnelle Meldung, mind. `TRIP_MIN_M` (500 m), lange Fahrten alle `TRIP_LOG_S` (10 min) als Zwischenstand «Richtung». Nicht gezählt: Positionen auf dem Gewässer (Gerät meldet `water=1`, wenn es weniger als `WATER_M` = 80 m von der Gewässerachse des geladenen Gewässernetzes entfernt ist, z. B. Boot) und manuell gesetzte Positionen (`manual=1`). Ortsnamen: swisstopo-Ortschaftenverzeichnis (`placeName`), ausserhalb der Schweiz Nominatim, sonst Koordinaten.
- Quittierte Alarme bleiben pro Gerät quittiert (`localStorage` `sw_acked`).
- Benutzerrollen: «Einsatzkraft» und «Administrator» (`users.is_admin`). Nur Administratoren erreichen `admin.html` und die Verwaltungsrouten (`admin()`). Die eigene Administratorrolle und das eigene Konto lassen sich nicht entfernen, damit immer ein Administrator bleibt.
- Passwort: jede Person kann das eigene ändern (`me/password`, aktuelles Passwort nötig, keine Passwortregeln ausser nicht leer). Administratoren setzen Passwörter zurück (`user/password`): Person wird abgemeldet und muss beim nächsten Login ein eigenes setzen (`must_change`, serverseitig erzwungen). Gilt auch für neue Benutzer und das Erst-Admin-Konto.
- Benutzer entfernen = deaktivieren (`user/active`): abgemeldet, aus laufendem Einsatz ausgetragen, Daten bleiben erhalten, reaktivierbar.
- Einsatzart: «Scharfer Einsatz» oder «Übung» (`incidents.is_exercise`). Scharf = immer kritischer Alarm (Vollbild, Dauerton). Übung = normale Benachrichtigung (Hinweis, kurzer Ton), ausser die Person hat den «Übungsmodus» aktiviert (`users.exercise_mode`). Der Server entscheidet (`critical()` → `critical` in `incident/state`), das Frontend folgt; eine spätere Push-Lösung soll dieselbe Regel nutzen.

## Wichtige Konventionen
- Frontend: keine Neuzeichnung des Menüs, solange ein Eingabefeld Fokus hat oder das Formular «Einsatz starten» offen ist (`draw()`-Guards). Bei Änderungen am Polling darauf achten.
- Server liefert Zahlen als Zahlen (`ints()`, Float-Casts); MySQL liefert sonst Strings, was im JS zu falschen Wahrheitswerten führt.
- Berechtigungen immer serverseitig prüfen, das Frontend blendet nur aus.

## Stand und bekannte Lücken
- **Nie ausgeführt/getestet:** Die Entwicklung fand ohne PHP-Laufzeit und ohne Netzzugriff auf Overpass/BAFU statt. Das JS wurde nur auf Syntaxfehler geprüft. Erste Aufgabe: lokal mit `php -S` starten und die Abläufe durchtesten (Login, Einsatz, Beitritt, Standort, Suchgebiet, Log, Statistik, Markierung, Entfernen).
- BAFU-Vorschlag: Struktur der Antwort von `api.existenz.ch/apiv1/hydro/locations` und `/latest` ist nur aus Dokumentationsschnipseln abgeleitet, Parsing in `flow/suggest` prüfen.
- Kritischer Alarm: als Web-App nur Vollbild-Alarm mit Ton/Vibration solange die App offen ist. Echte kritische Alarme (App geschlossen, Nicht stören) brauchen eine native App mit Push (FCM/APNs, Critical Alerts) oder zumindest Web-Push.
- Nur ein aktiver Einsatz gleichzeitig.
- Gewässerwahl am PLS: nächstgelegener Wasserlauf, nicht korrigierbar. Auswahlschritt wäre sinnvoll.
- Offen/Ideen: Web-Push, PLS auf Markierung verschieben, Zugriffsschutz/Rate-Limit für Login, Einsatzarchiv-Export, Sperre für entfernte Personen.
