# Swiftwater Rescue – Prototyp V0.1

## Installation (cyon Webhosting Single)
1. Alles per FTP/Dateimanager in ein Verzeichnis hochladen (z. B. `/public_html/rescue/`). HTTPS aktivieren (Pflicht für GPS und Benachrichtigungen).
2. `api/config.php`: `admin_pass` ändern. Standard ist SQLite (keine Einrichtung nötig). Für MySQL im cyon-Panel eine Datenbank anlegen und DSN/Benutzer/Passwort eintragen.
3. `rescue/admin.html` im Browser öffnen, mit `admin` anmelden, ein eigenes Passwort setzen und Benutzer und Rollen anlegen. Die Einsatz-App liegt unter `rescue/`.
4. Das Admin-Passwort danach nicht mehr in `config.php` stehen lassen (Konto wird nur beim ersten Start angelegt).

## Deployment per SSH (`scripts/deploy.ps1`)
Liefert einen eingecheckten Git-Stand aus (nicht die Arbeitskopie): Dateien per `git archive`, Upload per `scp`, auf dem Server erst PHP-Syntaxprüfung (`php -l`), dann Übernahme. `api/config.php` und `api/data/` (Datenbank) werden nie überschrieben; der ausgelieferte Stand steht in `api/data/deployed.txt`.

1. SSH-Schlüssel erzeugen (`ssh-keygen -t ed25519 -f %USERPROFILE%\.ssh\swiftwater-tst`) und den öffentlichen Teil im cyon-Panel beim Hosting-Benutzer eintragen (FTP-Unterkonten unterstützen keine Schlüssel).
2. `deploy.example.json` nach `deploy.local.json` kopieren und Server, Benutzer, Schlüssel und Zielordner eintragen. Die Datei ist per `.gitignore` ausgeschlossen, Passwörter braucht es keine.
3. Ausliefern:
```
powershell -File scripts\deploy.ps1                                  # origin/main
powershell -File scripts\deploy.ps1 -Ref origin/feature/irgendwas    # anderer Stand, z. B. zum Testen eines PR
```
Beim ersten Mal auf dem Server `api/config.php` von Hand anlegen (Vorlage im Repo), das Skript lädt sie nicht hoch.

## Verwaltung, Passwörter, Übungen
- `admin.html` (ohne Karte) ist nur für die Benutzerrolle Administrator: Benutzer anlegen, Rolle ändern, Passwort zurücksetzen, deaktivieren/reaktivieren, Spezialrollen, Einsatzstatistik.
- Jede Person ändert ihr Passwort im Menü unter «Konto → Passwort ändern».
- Beim Einsatzstart wählen: «Scharfer Einsatz» (immer kritischer Alarm) oder «Übung» (normale Benachrichtigung). Wer unter «Konto» den Übungsmodus aktiviert, erhält auch Übungen als kritischen Alarm.

## iOS- und Android-App
Die Apps (Capacitor 8) enthalten die Oberfläche der Einsatz-App. Beim ersten Start gibt man die Adresse der Installation ein (z. B. `https://wasserrettung.ch/rescue`). Unter «Konto → Installationen» lassen sich weitere Installationen hinzufügen, wechseln oder entfernen. Die nicht aktiven Installationen werden im Hintergrund auf neue Einsätze geprüft. Optional in `api/config.php`: `install_name` als Anzeigename.

Lokal entwickeln (Node.js 22 oder neuer):
```
npm ci
npm run android   # Web-Inhalt kopieren, synchronisieren, Android Studio öffnen
npm run ios       # dasselbe für Xcode (nur auf dem Mac, Xcode 26)
```
Nach Änderungen an `index.html`, `common.js` oder `common.css` jeweils `npm run sync` ausführen.

### CI (GitHub Actions, `.github/workflows/mobile-apps.yml`)
Läuft bei Pull Requests, bei Pushes auf `main` und manuell («Run workflow»). Die Ergebnisse stehen im Lauf unter «Artifacts»:
- `android-debug-apk-…`: installierbare Test-APK (auf dem Handy «Installation aus unbekannten Quellen» erlauben).
- `ios-simulator-app-…`: unsignierte App für den iOS-Simulator (auf dem Mac entpacken, in den Simulator ziehen). Zum Installieren auf iPhones ist eine Signierung mit Apple-Developer-Konto nötig (noch nicht eingerichtet).

Signierter Android-Release (APK und AAB für Google Play): unter GitHub → Settings → Secrets and variables → Actions diese Secrets anlegen, dann baut die CI ihn automatisch mit:
- `ANDROID_KEYSTORE_BASE64`: Keystore-Datei Base64-kodiert
- `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`

Versionsnummer: `version` in `package.json`, Build-Nummer = Nummer des CI-Laufs.

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
