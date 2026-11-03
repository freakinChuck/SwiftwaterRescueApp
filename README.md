# Swiftwater Rescue – Prototyp V0.1

## Installation (cyon Webhosting Single)
1. Alles per FTP/Dateimanager in ein Verzeichnis hochladen (z. B. `/public_html/rescue/`). HTTPS aktivieren (Pflicht für GPS und Benachrichtigungen).
2. `api/config.php`: `admin_pass` ändern. Standard ist SQLite (keine Einrichtung nötig). Für MySQL im cyon-Panel eine Datenbank anlegen und DSN/Benutzer/Passwort eintragen.
3. `rescue/admin.html` im Browser öffnen, mit `admin` anmelden, ein eigenes Passwort setzen und Benutzer und Rollen anlegen. Die Einsatz-App liegt unter `rescue/`.
4. Das Admin-Passwort danach nicht mehr in `config.php` stehen lassen (Konto wird nur beim ersten Start angelegt).

## Deployment per SSH (`scripts/deploy.ps1`)
Liefert einen eingecheckten Git-Stand aus (nicht die Arbeitskopie): Dateien per `git archive`, Upload per `scp`, auf dem Server erst PHP-Syntaxprüfung (`php -l`), dann Übernahme. `api/config.php` und `api/data/` (Datenbank) werden nie überschrieben; der ausgelieferte Stand steht in `api/data/deployed.txt`.

1. SSH-Schlüssel erzeugen (`ssh-keygen -t ed25519 -f %USERPROFILE%\.ssh\swiftwater-tst`) und den öffentlichen Teil im cyon-Panel beim Hosting-Benutzer eintragen (FTP-Unterkonten unterstützen keine Schlüssel).
2. `deploy.example.json` nach `deploy.local.json` (Testsystem) bzw. `deploy.prod.json` (Produktivsystem) kopieren und Server, Benutzer, Schlüssel und Zielordner eintragen. Alle `deploy.*.json` ausser der Vorlage sind per `.gitignore` ausgeschlossen, Passwörter braucht es keine. `env` (`dev`, `test` oder `prod`) wählt die App-Icons: DEV grün umrandet (so liegen sie im Repo), Test orange (`img/test/`), Prod ohne Rand (`img/prod/`). Ohne `env` gilt `prod`, wenn der Dateiname «prod» enthält, sonst `test`.
3. Ausliefern:
```
powershell -File scripts\deploy.ps1                                  # origin/main
powershell -File scripts\deploy.ps1 -Ref origin/feature/irgendwas    # anderer Stand, z. B. zum Testen eines PR
powershell -File scripts\deploy.ps1 -Config deploy.prod.json         # Produktivsystem (origin/main)
```
Beim ersten Mal auf dem Server `api/config.php` von Hand anlegen (Vorlage im Repo), das Skript lädt sie nicht hoch.

## Verwaltung, Passwörter, Übungen
- `admin.html` (ohne Karte) ist nur für die Benutzerrolle Administrator: Benutzer anlegen, Rolle ändern, Passwort zurücksetzen, deaktivieren/reaktivieren, Einrückoptionen, Spezialrollen, Einsatzstatistik.
- Einrückoptionen (z. B. «Anfahrt direkt», «Koordinator KP Rück») lassen sich umbenennen, ergänzen und deaktivieren. Pro Option wird festgelegt, ob der Standort geteilt wird; bei «Koordinator KP Rück» ist das standardmässig aus.
- Jede Person ändert ihr Passwort im Menü unter «Konto → Passwort ändern».
- Beim Einsatzstart wählen: «Scharfer Einsatz» (immer kritischer Alarm) oder «Übung» (normale Benachrichtigung). Wer unter «Konto» den Übungsmodus aktiviert, erhält auch Übungen als kritischen Alarm.

## Öffentliche Ansicht (Public Mode)
Die Einsatzleitung kann einen laufenden Einsatz oder eine Übung im Menü unter «Einsatz verwalten» öffentlich machen. Wer den Link (`public.html?k=…`) hat, sieht ohne Anmeldung die Karte mit Standorten, Positionen, Bereichen und Rettlingen, aber keine Nachrichten und kein Einsatzlog. Standardmässig anonym: statt Namen nur Spezialrolle bzw. Einrückoption. Ausschalten macht den Link ungültig.

## App installieren und Push-Benachrichtigungen
Die Einsatz-App ist eine PWA: Im Browser über «App installieren» (Menü → Konto) bzw. auf dem iPhone über «Teilen → Zum Home-Bildschirm» installieren. Unter «Konto» lassen sich Push-Benachrichtigungen pro Gerät einschalten. Alarme, Nachrichten an einen selbst und das Einsatzende kommen dann auch bei geschlossener App. Auf iPhone und iPad gilt das nur für die installierte App, ab iOS 16.4. Ohne Verbindung zeigt die App den letzten Stand mit einem Hinweis, wann die letzte Verbindung bestand.
Voraussetzung auf dem Server: HTTPS sowie PHP mit openssl und curl. Bei cyon ist beides vorhanden.

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
- Einsatzlog (Beitritt, Rollen, Fliessgeschwindigkeit, Nachrichten, Fahrten mit Fahrzeug mit Ortsnamen, Suchgebiet erreicht) und Statistik im Admin-Bereich.
