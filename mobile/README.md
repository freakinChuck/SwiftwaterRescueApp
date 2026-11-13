# Native Apps

Native Apps für Android und iOS (Issue #108), gebaut mit Flutter in `mobile/app`. Sie sprechen dieselbe JSON-API wie die Web-App (`web/api/index.php`). Server-Code gehört nicht hierher, sondern nach `web/api/`.

## Was die App kann

- **Mehrere Backends:** Mit «Backend hinzufügen» meldet man sich mit Server-Adresse (wie die der Web-App, z. B. `https://example.ch/swiftwater/`), Benutzername und Passwort an. Jedes Backend ist einzeln **aktiv** schaltbar. Auf allen aktiven Backends kommen gleichzeitig Alarme, Nachrichten an einen selbst und das Einsatzende an.
- **Einsatz-App nativ (ohne WebView):** Ein Antippen öffnet die Einsatz-App des Backends, schon angemeldet, ganz in Flutter (`lib/inc/`). Karte (flutter_map mit denselben Anbietern wie die Web-App, drehbar), Kartenknöpfe und Seitenfenster (Einsatzkräfte, Nachrichten, Rettlinge, Markierungen & Bereiche, Flussdistanz), Suchgebiet entlang des Gewässers, Fenster «Ansicht», Kamerafokus und Auto-Übersicht, ☰-Menü mit den Registern Einsatz/Leitung/Übung/Log/Info, Einsatz starten und vorbereitete Übungen, Profil. Die Abläufe, Routen und Texte entsprechen der Web-App. Die Verwaltung, «Meine Einsätze» und das Rechtliche öffnen sich in der App in einer WebView (`lib/inc/web.dart`), angemeldet mit dem Konto (die App setzt `sw_token` vor dem ersten Skript); «Zur Einsatz-App» führt zur Karte zurück, Dateien (AAR, CSV) gibt die Web-App über `saveBlob` an die App, die sie zum Teilen bzw. Speichern anbietet. Noch nicht nativ: Gross-Modus des Logs, freie Fragen an den AI-Assistenten und dessen Modus «Begleitend».
- **Eigene Anmeldung je Gerät:** Die App erhält eine Gerätesitzung (`sessions` auf dem Server). Sie meldet weder den Browser noch andere Geräte ab. Im Profil der Web-App stehen die angemeldeten Apps unter «Angemeldete Apps».
- **Alarme wie in der Web-App:**

  | Alarmstufe | Verhalten |
  |---|---|
  | kritisch | Vollbild und Weckton (Android: als Wecker mit mind. 80 % Lautstärke, auch lautlos und bei «Nicht stören», bis man die Meldung antippt oder quittiert, höchstens 5 min) |
  | hoch | Vollbild und einmaliger Ton |
  | normal | gewöhnliche Meldung |
  | leise | Meldung ohne Ton |
  | keine | nichts |

  Die Meldungen nutzen dieselben Schlüssel wie die Web-App (`i:`, `m:`, `e:`), deshalb meldet nichts doppelt. Ist die Einsatz-App eines Kontos offen, zeigt sie Alarme als Vollbild in der Karte (kritisch mit Weckton über dieselbe Meldung, die «Nicht stören» umgeht); «Quittieren» schliesst die Meldung. Die Überwachung bleibt dann für dieses Konto still. Antippen einer Meldung öffnet die Einsatz-App des Kontos auf dem Einsatzgebiet (Suchgebiet und PLS, ohne Einsatzkräfte), ebenso das Annehmen mit einer Einrückoption.

  Ob ein Alarm kritisch ist, bestimmt der Einsatztyp auf dem Server (Verwaltung → Einsatz → «Einsatztypen», Alarmstufe). Angelegt sind «Einsatz» kritisch und «Übung» normal; eine Übung ist nur im persönlichen Übungsmodus kritisch.

  **Ton kritischer Alarme** (Issue #171): im Profil, Karte «Alarmierung», je Backend und Einsatztyp wählbar (Signalhörner, Sirenen, Melodien, Ton des Systems) mit «▶ Probe»; ohne eigene Wahl gilt die Vorgabe des Einsatztyps (Verwaltung → «Einsatztypen»). Die Töne liegen in `assets/sounds/` (erzeugt mit `python3 scripts/sounds/gen.py`, braucht numpy; neue Töne zusätzlich in `lib/sounds.dart` und `ALARM_SOUNDS` in `web/api/index.php` eintragen und `_ver` in `sounds.dart` erhöhen). Android spielt die Datei als Wecker in Schleife, iOS einmal (höchstens 30 s) aus `Library/Sounds`, auch bei Push (der Server nennt den Ton über `push_native.snd`).
- **Standort:** Ist die Einsatz-App offen, teilt sie den Standort selbst (`inc/store.dart`, mit «auf dem Gewässer» aus dem geladenen Gewässernetz). Sonst teilt ihn im Einsatz der Hintergrund (`track.dart`, alle 5 s bei Bewegung, sonst alle 15 s), bis man austritt oder der Einsatz endet. Bei gesperrtem Bildschirm braucht es dafür «Immer zulassen» (Android: der Dienst läuft dann zusätzlich als Standort-Dienst, sonst erhält er ab Android 14 keine Positionen); die App fragt einmal danach und zeigt den Stand in ihren Einstellungen. Im Hintergrund kennt die App kein Gewässernetz: Positionen gelten dort nie als «auf dem Gewässer».

| | Android | iOS |
|---|---|---|
| Abfrage bei geschlossener App | Vordergrunddienst «Bereit für Alarme», alle 10–60 s, auch nach einem Neustart | nicht möglich (iOS erlaubt es nicht) |
| Push (optional, Firebase Cloud Messaging) | zusätzlich, weckt das Gerät auch im Ruhezustand | einziger Weg bei geschlossener App |
| Kritischer Alarm trotz «Nicht stören» | nach Erlaubnis in den Einstellungen der App | nur mit Freigabe «Critical Alerts» von Apple |

In den Einstellungen der App gibt es dazu Folgendes:
- Abfrageintervall
- Benachrichtigungen
- «Nicht stören» umgehen
- Ausnahme von der Akku-Optimierung (sonst kann Android den Dienst anhalten)
- Standort («Immer zulassen», Stand: immer, nur während der Nutzung, nicht erlaubt)

## Aufbau (`mobile/app/lib`)

| Datei | Inhalt |
|---|---|
| `main.dart` | Start, Navigation, Abfrage bei offener App (iOS) |
| `home.dart` | Kontenliste, Anmeldung, Einstellungen |
| `inc/store.dart` | Einsatz-App: Zustand, Polling `incident/state` (4 s), Aktionen, Ansicht, Standort teilen, Alarm |
| `inc/map_page.dart` | Kartenseite: Ebenen, Kartenknöpfe, Seitenfenster, Punktwahl/Bereich zeichnen, Alarm-Vollbild, Startansicht |
| `inc/menu.dart` | ☰-Menü mit Registern, Einsatz starten, vorbereitete Übungen |
| `inc/people.dart`, `msgs.dart`, `res.dart`, `pois.dart`, `rd.dart` | Seitenfenster Einsatzkräfte, Nachrichten, Rettlinge (mit «PLS setzen»), Markierungen & Bereiche (mit festen Standorten), Flussdistanz |
| `inc/prefs.dart` | Profil (Konto, Alarmierung, Anzeige, AI-Assistent, Rechtliches) |
| `inc/area.dart` | Suchgebiet entlang des Gewässers, Gewässernetz über `overpass` (mit Rückfall im Gerät) |
| `inc/ctx.dart`, `inc/util.dart` | gemeinsame Bausteine (Karten, Dialoge, Navigation, Koordinaten) |
| `track.dart` | Standort im Einsatz teilen, solange die Einsatz-App nicht im Vordergrund ist |
| `accounts.dart` | Konten im sicheren Speicher (Keychain bzw. verschlüsselt) |
| `monitor.dart` | Abfrage `incident/state` je Konto, meldet Neues |
| `notify.dart` | Benachrichtigungen, Kanäle je Alarmstufe |
| `service.dart` | Android-Vordergrunddienst |
| `push.dart` | Firebase Cloud Messaging (optional) |
| `sys.dart` | Akku-Optimierung, Standortberechtigung (auch «Immer»), App-Einstellungen, Weckton (Android über das Plugin `packages/sw_sys`, auch im Dienst) |

## Bauen

Voraussetzungen:
- Flutter 3.47 (Version wie in `.github/workflows/mobile.yml`)
- Android: JDK 17 und das Android SDK
- iOS: macOS mit Xcode

```bash
cd mobile/app
flutter pub get
flutter run                      # auf einem angeschlossenen Gerät
flutter build apk --release      # Android
flutter build ipa --release      # iOS (Signatur in Xcode einrichten)
```

### Pipeline (GitHub Actions)

`.github/workflows/mobile.yml` läuft bei Änderungen unter `mobile/` (Push auf `main` und Pull Requests), bei Tags `mobile-v<Version>` und von Hand («Run workflow»). Die Pipeline prüft zuerst (`flutter analyze`, `flutter test`) und baut dann:

- **Android:** `swiftwater-rescue-<Version>.apk` (direkt installierbar) und `.aab` (für Google Play)
- **iOS:** `swiftwater-rescue-<Version>.ipa`

Die Pakete stehen als Artefakte beim Lauf. Bei einem Tag `mobile-v1.2.0` übernimmt die Pipeline die Version 1.2.0, hängt die Pakete an ein GitHub-Release an und nimmt die Laufnummer als Build-Nummer (Android; iOS siehe unten).

Geheimnisse (Repository → Settings → Secrets and variables → Actions) sind alle optional:

| Geheimnis | Zweck |
|---|---|
| `ANDROID_KEYSTORE_BASE64` | Signierschlüssel (`.jks`, base64). Ohne ihn signiert die Pipeline mit dem Debug-Schlüssel: installierbar, aber nicht für Google Play. |
| `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD` | Zugang zum Schlüssel |
| `IOS_CERT_P12_BASE64`, `IOS_CERT_PASSWORD` | Verteilerzertifikat (`.p12`, base64) |
| `IOS_PROFILE_BASE64` | Provisioning-Profil für `ch.swiftwaterrescue.app` (base64) |
| `IOS_TEAM_ID` | Team-ID des Apple-Developer-Kontos |
| `IOS_EXPORT_METHOD` | `app-store-connect` (Standard), `ad-hoc` oder `enterprise` |
| `ASC_KEY_ID`, `ASC_ISSUER_ID` | Schlüssel-ID und Issuer-ID des App-Store-Connect-API-Schlüssels |
| `ASC_KEY_P8` | Inhalt der Datei `AuthKey_<ID>.p8` (direkt oder base64) |
| `PLAY_SERVICE_ACCOUNT_JSON` | Inhalt der JSON-Datei des Google-Cloud-Dienstkontos für den Upload in Google Play |
| `FCM_API_KEY`, `FCM_PROJECT_ID`, `FCM_SENDER_ID`, `FCM_APP_ID_ANDROID`, `FCM_APP_ID_IOS` | Firebase-Angaben für Push (siehe unten) |

Ohne iOS-Signatur entsteht ein unsigniertes `…-unsigned.ipa`. Installieren lässt es sich erst nach dem Nachsignieren.

Upload nach TestFlight: Läuft die Pipeline auf `main` (Push oder «Run workflow» auf `main`), ist das IPA signiert, gilt `IOS_EXPORT_METHOD` `app-store-connect` und sind die drei `ASC_*`-Geheimnisse gesetzt, lädt sie das IPA mit `xcrun altool` nach App Store Connect. Den Schlüssel erzeugt man in App Store Connect unter Benutzer und Zugriff → Integrationen → App Store Connect API (Team-Schlüssel, Rolle «App Manager»); die `.p8`-Datei lässt sich nur einmal herunterladen. Die App muss in App Store Connect angelegt sein (Bundle-ID `ch.swiftwaterrescue.app`). Die iOS-Build-Nummer ist Datum und Zeit des Builds (UTC, `JJMMTThhmm`, z. B. 2611081530) und steigt deshalb über beide Workflows hinweg. Pull Requests und Tags laden nichts hoch. Der iOS-Teil steht in `.github/workflows/ios.yml` (wiederverwendbar).

Nur iOS nach TestFlight, von Hand: Actions → «TestFlight» → «Run workflow», Branch wählen (`.github/workflows/testflight.yml`). Baut und lädt hoch ohne Prüfung und ohne Android, auch von anderen Branches als `main`. `ITSAppUsesNonExemptEncryption` = `false` in `Info.plist` (nur HTTPS), damit App Store Connect nicht bei jedem Build nach der Verschlüsselung fragt.

Upload in Google Play: Läuft die Pipeline auf `main` (Push oder «Run workflow» auf `main`), ist das AAB mit dem Release-Schlüssel signiert und ist `PLAY_SERVICE_ACCOUNT_JSON` gesetzt, lädt sie das AAB mit `r0adkll/upload-google-play` hoch. Spur und Status über die Repository-Variablen (Actions → Variables, nicht Secrets) `PLAY_TRACK` (`internal` Standard, `alpha`, `beta`, `production`) und `PLAY_STATUS` (`completed` Standard, `draft`, `inProgress`). Pull Requests und Tags laden nichts hoch. Einrichten, einmalig:

1. In der [Play Console](https://play.google.com/console) die App mit der Paket-ID `ch.swiftwaterrescue.app` anlegen und das erste AAB (aus den Artefakten eines Laufs auf `main`) **von Hand** hochladen, zum Beispiel in den internen Test. Erst danach nimmt die API Uploads an. Dabei «Play App Signing» annehmen: Der Schlüssel aus `ANDROID_KEYSTORE_BASE64` wird zum Upload-Schlüssel, Google signiert die ausgelieferte App.
2. In der [Google Cloud Console](https://console.cloud.google.com/) im selben oder einem neuen Projekt die «Google Play Android Developer API» aktivieren, ein Dienstkonto anlegen und dafür einen JSON-Schlüssel erzeugen.
3. In der Play Console unter Nutzer und Berechtigungen das Dienstkonto (E-Mail-Adresse aus der JSON-Datei) einladen, mit Zugriff auf die App und den Rechten «Releases verwalten» bzw. «Releases in Test-Tracks veröffentlichen».
4. Den ganzen Inhalt der JSON-Datei als Geheimnis `PLAY_SERVICE_ACCOUNT_JSON` hinterlegen. Die Datei nie einchecken.
5. Solange die App in Google Play noch nie veröffentlicht wurde (Status «Entwurf»), nimmt Google nur Releases im Status `draft` an: Variable `PLAY_STATUS` = `draft` setzen und den Entwurf in der Play Console freigeben. Nach der ersten Veröffentlichung die Variable entfernen.

Die Android-Build-Nummer (`versionCode`) ist die Laufnummer des Workflows «Native Apps» und steigt mit jedem Lauf. Ein erneut gestarteter Lauf («Re-run») hat dieselbe Nummer; Google lehnt sie dann als schon verwendet ab.

Lokal liest Android den Signierschlüssel aus `android/key.properties` (`storeFile`, `storePassword`, `keyAlias`, `keyPassword`). Die Datei steht in der `.gitignore`.

### Push einrichten (optional)

1. In der [Firebase-Konsole](https://console.firebase.google.com/) ein Projekt anlegen und darin eine Android-App und eine iOS-App mit der Paket-ID `ch.swiftwaterrescue.app` hinzufügen.
2. iOS: In Firebase unter Cloud Messaging den APNs-Schlüssel (`.p8`) des Apple-Developer-Kontos hinterlegen. In der App-ID «Push Notifications» einschalten.
3. Die Werte API-Schlüssel, Projekt-ID, Sender-ID und App-ID je Plattform als Geheimnisse `FCM_*` hinterlegen, oder lokal mit `--dart-define=FCM_API_KEY=…` usw. bauen. Es braucht weder `google-services.json` noch `GoogleService-Info.plist`.
4. Server: Unter Projekteinstellungen → Dienstkonten einen privaten Schlüssel erzeugen. Die JSON-Datei ausserhalb des Webroots oder in `web/api/data/` ablegen und in `api/config.php` eintragen: `'fcm' => __DIR__ . '/data/firebase-service-account.json'`. Die Datei nie einchecken.

Die Startseite der App nennt je Konto, woran Push scheitert (nicht eingebaut, Mitteilungen ausgeschaltet, kein APNs-Token, kein Firebase-Token, Anmeldung beim Server). Die Pipeline warnt bei fehlenden `FCM_*`-Geheimnissen und meldet `aps-environment` des signierten IPA.

Ein Server ohne Dienstkonto meldet `push/key` mit `native: 0`. Die App zeigt dann «Push auf diesem Server nicht eingerichtet» und überwacht nur per Abfrage. Sie fragt höchstens alle 6 Stunden erneut nach und meldet sich an, sobald der Server FCM hat. Ist das Gerät auf dem Server nicht mehr angemeldet (`push/key` mit Token liefert `registered: 0`, z. B. nach dem Entfernen der Sitzung), meldet sie sich beim nächsten Start oder bei der nächsten Rückkehr in die App neu an. Mehrere Backends können dasselbe Firebase-Projekt nutzen; die Nachricht nennt Server und Person (`srv`, `uid`), damit die App sie dem richtigen Konto zuordnet.

### Kritische Hinweise unter iOS

Damit ein Alarm auch bei «Nicht stören» und Lautlos ertönt, braucht es die Berechtigung «Critical Alerts». Apple vergibt sie nur auf Antrag: https://developer.apple.com/contact/request/notifications-critical-alerts-entitlement/

Nach der Freigabe den Eintrag in `ios/Runner/Runner.entitlements` einkommentieren und das Provisioning-Profil neu erstellen. Für die Alarmstufe «hoch» ebenso «Time Sensitive Notifications». Ohne diese Berechtigungen kommen Alarme als gewöhnliche Meldung mit Ton.

## Regeln

- Was nur für die App gilt, kommt in `mobile/app/.gitignore`: Build-Dateien, `local.properties`, Schlüssel. Schlüssel, Zertifikate, Profile und Firebase-Dateien nie einchecken.
- Neue Bibliotheken der App in `web/legal.html` unter «Native Apps» nachführen.
