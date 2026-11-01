# Swiftwater Rescue – Prototyp V0.1

## Installation (cyon Webhosting Single)
1. Alles per FTP/Dateimanager in ein Verzeichnis hochladen (z. B. `/public_html/rescue/`). HTTPS aktivieren (Pflicht für GPS und Benachrichtigungen).
2. `api/config.php`: `admin_pass` ändern. Standard ist SQLite (keine Einrichtung nötig). Für MySQL im cyon-Panel eine Datenbank anlegen und DSN/Benutzer/Passwort eintragen.
3. `rescue/` im Browser öffnen, mit `admin` anmelden, unter «Admin» Benutzer und Rollen anlegen.
4. Das Admin-Passwort danach nicht mehr in `config.php` stehen lassen (Konto wird nur beim ersten Start angelegt).

## Karte
Standard ist das swisstopo-Luftbild; über das Ebenen-Symbol oben rechts kann auf Landeskarte oder OpenStreetMap umgeschaltet werden (Wahl wird pro Gerät gemerkt).

## Karte austauschen
In `index.html` oben: `DEFAULT_BASE` ändern oder in `MAP_PROVIDERS` einen Eintrag (Tile-URL mit {z}/{x}/{y}, EPSG:3857) ergänzen.

## Bekannte Lücken gegenüber der Spezifikation
- Kritischer Alarm: Als Web-App nur Vollbild-Alarm mit Ton/Vibration/Benachrichtigung, solange die App offen ist. Echte kritische Alarme (Lautlos/Nicht-stören umgehen, App geschlossen) brauchen eine native App (Push via FCM/APNs mit Critical Alerts-Berechtigung).
- Aktualisierung per Polling (4 s), da auf Shared Hosting keine WebSockets laufen.
- Suchgebiet: folgt dem Flusslauf (OpenStreetMap-Gewässernetz via Overpass, Fliessrichtung = Wegrichtung in OSM), Ufergebiet 30 m je Seite (`BANK_M`), endet am ersten Sicherungs-/Beobachtungsposten (Rolle mit Flag). Ohne Gewässer in 300 m Nähe oder ohne Daten: Kreis als Rückfall. Abflussdaten-Vorschlag (BAFU) fehlt noch.
- Nur ein aktiver Einsatz gleichzeitig.
- BAFU-Vorschlag: Abfluss (m³/s) der nächsten BAFU-Station via api.existenz.ch (inoffiziell, Quelle BAFU angeben), Geschwindigkeit nur grob geschätzt (v ≈ 0.8·Q^0.18). Abfrage ist ungetestet gegen die Live-API.
- Rettling-Markierung: Teilnehmende markieren die Position des Rettlings mit ihrem Standort; Einsatzleitung/Admin/Einsatzersteller kann Markierungen entfernen oder manuell setzen.
- Einsatzlog (Beitritt, Rollen, Fliessgeschwindigkeit, Nachrichten, Verschiebung > 300 m, Suchgebiet erreicht) und Statistik im Admin-Bereich.
