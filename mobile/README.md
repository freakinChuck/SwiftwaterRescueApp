# Native Apps

Native Apps für Android und iOS (Issue #108), gebaut mit Flutter in `mobile/app`. Sie sprechen dieselbe JSON-API wie die Web-App (`web/api/index.php`). Server-Code gehört nicht hierher, sondern nach `web/api/`.

## Was die App kann

- **Mehrere Backends:** Mit «Backend hinzufügen» meldet man sich mit Server-Adresse (wie die der Web-App, z. B. `https://example.ch/swiftwater/`), Benutzername und Passwort an. Jedes Backend ist einzeln **aktiv** schaltbar. Auf allen aktiven Backends kommen gleichzeitig Alarme, Nachrichten an einen selbst und das Einsatzende an.
- **Einsatz-App nativ (ohne WebView):** Ein Antippen öffnet die Einsatz-App des Backends, schon angemeldet, ganz in Flutter (`lib/inc/`). Karte (flutter_map mit denselben Anbietern wie die Web-App, drehbar), Kartenknöpfe und Seitenfenster (Einsatzkräfte, Nachrichten, Rettlinge, Markierungen & Bereiche, Flussdistanz), Suchgebiet entlang des Gewässers, Fenster «Ansicht», Kamerafokus und Auto-Übersicht, ☰-Menü mit den Registern Einsatz/Leitung/Übung/Log/Info, Einsatz starten und vorbereitete Übungen, Profil. Die Abläufe, Routen und Texte entsprechen der Web-App. Die Verwaltung, «Meine Einsätze» und das Rechtliche öffnen sich in der App in einer WebView (`lib/inc/web.dart`), angemeldet mit dem Konto (die App setzt `sw_token` vor dem ersten Skript); «Zur Einsatz-App» führt zur Karte zurück, Dateien (AAR, CSV) gibt die Web-App über `saveBlob` an die App, die sie zum Teilen bzw. Speichern anbietet. Noch nicht nativ: Gross-Modus des Logs, freie Fragen an den AI-Assistenten und dessen Modus «Begleitend».
- **Demo-Modus (Issue #74):** In der Kontenliste, auch ohne Konto: «Mit Beispieldaten» (ein laufender, fiktiver Einsatz an der Reuss in Luzern) oder «Ohne Daten». Die native Einsatz-App läuft dann mit einem simulierten Backend in der App (`lib/demo.dart`, gleich wie `web/demo.js`): kein Server, keine Überwachung, kein Push, kein Teilen im Hintergrund; simulierte Einsatzkräfte nehmen an, fahren hin, senden Nachrichten und finden den Rettling. Die Kennzeichnung «DEMO» unter dem Menüknopf schaltet Simulation und Leitungsrechte um, startet neu oder beendet das Demo. Der Stand bleibt bis zum Neustart der App. «Ohne Daten» übernimmt die festen Standorte, Einsatztypen, Spezialrollen, Einrückoptionen und Einstellungen des angemeldeten Kontos (bei mehreren Auswahl), nur gelesen.
- **Eigene Anmeldung je Gerät:** Die App erhält eine Gerätesitzung (`sessions` auf dem Server). Sie meldet weder den Browser noch andere Geräte ab. Im Profil der Web-App stehen die angemeldeten Apps unter «Angemeldete Apps».
- **Alarme wie in der Web-App:**

  | Alarmstufe | Verhalten |
  |---|---|
  | kritisch | Vollbild und Weckton (Android: als Wecker mit mind. 80 % Lautstärke, auch lautlos und bei «Nicht stören», bis man die Meldung antippt oder quittiert, höchstens 5 min) |
  | hoch | Vollbild und einmaliger Ton |
  | normal | gewöhnliche Meldung |
  | leise | Meldung ohne Ton |
  | keine | nichts |

  Die Meldungen nutzen dieselben Schlüssel wie die Web-App (`i:`, `m:`, `e:`), deshalb meldet nichts doppelt. Unter iOS zeigt das System Push-Nachrichten selbst an, eine Meldung der App ersetzt sie dort nicht: Ist das Konto für Push angemeldet, meldet die App Alarm, Nachrichten und Einsatzende im Hintergrund deshalb nicht noch selbst (Issue #184). Ist die Einsatz-App eines Kontos offen, zeigt sie Alarme als Vollbild in der Karte (kritisch mit Weckton über dieselbe Meldung, die «Nicht stören» umgeht); «Quittieren» schliesst die Meldung. Die Überwachung bleibt dann für dieses Konto still. Antippen einer Meldung öffnet die Einsatz-App des Kontos auf dem Einsatzgebiet (Suchgebiet und PLS, ohne Einsatzkräfte), ebenso das Annehmen mit einer Einrückoption.

  Ob ein Alarm kritisch ist, bestimmt der Einsatztyp auf dem Server (Verwaltung → Einsatz → «Einsatztypen», Alarmstufe). Angelegt sind «Einsatz» kritisch und «Übung» normal; eine Übung ist nur im persönlichen Übungsmodus kritisch.

  **Ton kritischer Alarme** (Issue #171): im Profil, Karte «Alarmierung», je Backend und Einsatztyp wählbar (Signalhörner, Sirenen, Melodien, Ton des Systems) mit «▶ Probe»; ohne eigene Wahl gilt die Vorgabe des Einsatztyps (Verwaltung → «Einsatztypen»). Die Töne liegen in `assets/sounds/` (erzeugt mit `python3 scripts/sounds/gen.py`, braucht numpy; neue Töne zusätzlich in `lib/sounds.dart` und `ALARM_SOUNDS` in `web/api/index.php` eintragen und `_ver` in `sounds.dart` erhöhen). Android spielt die Datei als Wecker in Schleife, iOS einmal (höchstens 30 s) aus `Library/Sounds`, auch bei Push (der Server nennt den Ton über `push_native.snd`).
- **Standort:** Ist die Einsatz-App offen, teilt sie den Standort selbst (`inc/store.dart`, mit «auf dem Gewässer» aus dem geladenen Gewässernetz). Sonst teilt ihn im Einsatz der Hintergrund (`track.dart`, alle 5 s bei Bewegung, sonst alle 15 s), bis man austritt oder der Einsatz endet. Bei gesperrtem Bildschirm braucht es dafür «Immer zulassen» (Android: der Dienst läuft dann zusätzlich als Standort-Dienst, sonst erhält er ab Android 14 keine Positionen); die App fragt einmal danach und zeigt den Stand in ihren Einstellungen. Im Hintergrund kennt die App kein Gewässernetz: Positionen gelten dort nie als «auf dem Gewässer». Unter iOS gibt es in geolocator nur einen Positionsstrom; wer ihn zuerst öffnet, bestimmt die Einstellungen. Einsatz-App und Hintergrund nutzen deshalb dieselben (`locSettings`, mit `allowBackgroundLocationUpdates`), sonst stoppte iOS die Ortung beim Sperren (Issue #183). Den Stand je Konto (zuletzt gesendet, Fehler) zeigt die Kontenliste (`LocStat`, `trk_<id>`); Fehler der Ortung, Ablehnungen des Servers und Störungen ab 1 min meldet die App ins Applikationslog (`applog/client`, ohne Koordinaten).
- **Fahrzeuge (Issue #177):** Beacons der Fahrzeuge (iBeacon) erkennt `beacon.dart` über das Plugin `sw_sys`: im Einsatz kurze Scans, deren Ergebnis mit der Standortmeldung mitgeht; ausserhalb von Einsätzen (nur mit Zustimmung im Profil) sucht das Betriebssystem selbst (Android: Scan über `PendingIntent` mit Hardware-Filter, iOS: Region Monitoring, braucht Standort «Immer»). Android fragt dafür «Geräte in der Nähe» (`BLUETOOTH_SCAN`) an, iOS nutzt CoreLocation ohne eigene Bluetooth-Berechtigung. Zum Testen ohne Beacon genügt ein zweites Handy, das ein iBeacon sendet (z. B. «Beacon Simulator»).

| | Android | iOS |
|---|---|---|
| Abfrage bei geschlossener App | Vordergrunddienst «Bereit für Alarme», alle 10–60 s, auch nach einem Neustart; nur ohne Push, im Einsatz oder mit «Immer im Hintergrund abfragen» (Issue #179) | nicht möglich (iOS erlaubt es nicht) |
| Push (optional, Firebase Cloud Messaging) | Hauptweg, weckt das Gerät auch im Ruhezustand; mit Push läuft der Dienst (und seine dauerhafte Meldung) nur noch im Einsatz | einziger Weg bei geschlossener App |
| Kritischer Alarm trotz «Nicht stören» | nach Erlaubnis in den Einstellungen der App | als «Kritischer Hinweis» nach Erlaubnis beim ersten Start (Freigabe von Apple liegt vor) |

In den Einstellungen der App gibt es dazu Folgendes:
- Abfrageintervall
- «Immer im Hintergrund abfragen» (Android, Standard aus: mit Push nur ohne dauerhafte Meldung)
- Benachrichtigungen
- «Nicht stören» umgehen
- Ausnahme von der Akku-Optimierung (sonst kann Android den Dienst anhalten)
- Standort («Immer zulassen», Stand: immer, nur während der Nutzung, nicht erlaubt)

## Aufbau (`mobile/app/lib`)

| Datei | Inhalt |
|---|---|
| `main.dart` | Start (öffnet das zuletzt verwendete Backend, Issue #166), Navigation, Abfrage bei offener App (iOS, Android ohne Dienst) |
| `home.dart` | Kontenliste, Anmeldung, Einstellungen |
| `inc/store.dart` | Einsatz-App: Zustand, Polling `incident/state` (4 s), Aktionen, Ansicht, Standort teilen, Alarm |
| `inc/map_page.dart` | Kartenseite: Ebenen, Kartenknöpfe, Seitenfenster, Punktwahl/Bereich zeichnen, Alarm-Vollbild, Startansicht |
| `inc/menu.dart` | ☰-Menü mit Registern, Einsatz starten, vorbereitete Übungen |
| `inc/people.dart`, `msgs.dart`, `res.dart`, `pois.dart`, `rd.dart` | Seitenfenster Einsatzkräfte, Nachrichten, Rettlinge (mit «PLS setzen»), Markierungen & Bereiche (mit festen Standorten), Flussdistanz |
| `inc/hydro.dart` | Pegel & Abfluss (Issue #165): Messstationen auf der Karte, Seitenfenster «Messstationen», Menükarte, Auswahl im Profil |
| `inc/voice.dart` | Funk (Issue #113): Durchsagen aufnehmen (`record`, AAC) und abspielen (`just_audio`), Mithören, Nachsenden, Live-Konferenz über LiveKit (`livekit_client`), Seitenfenster «Funk», Sprechtaste auf der Karte, Leitung (Kanäle, Links für Externe), Profil-Karte |
| `inc/prefs.dart` | Profil (Konto, Alarmierung, Anzeige, Pegel & Abfluss, Funk, AI-Assistent, Rechtliches) |
| `inc/area.dart` | Suchgebiet entlang des Gewässers, Gewässernetz über `overpass` (mit Rückfall im Gerät) |
| `inc/ctx.dart`, `inc/util.dart` | gemeinsame Bausteine (Karten, Dialoge, Navigation, Koordinaten) |
| `demo.dart`, `demo_ui.dart` | Demo-Modus: simuliertes Backend (`Api.call` für `demoBase`), Einstieg in der Kontenliste, Kennzeichnung und Steuerung; Symbole aus `assets/sym` |
| `track.dart` | Standort im Einsatz teilen, solange die Einsatz-App nicht im Vordergrund ist |
| `accounts.dart` | Konten im sicheren Speicher (Keychain bzw. verschlüsselt) |
| `monitor.dart` | Abfrage `incident/state` je Konto, meldet Neues |
| `notify.dart` | Benachrichtigungen, Kanäle je Alarmstufe |
| `service.dart` | Android-Vordergrunddienst, nur wenn nötig (`serviceNeeded`); während einer Funk-Konferenz mit Typ `microphone` |
| `push.dart` | Firebase Cloud Messaging (optional) |
| `sys.dart` | Akku-Optimierung, Standortberechtigung (auch «Immer»), App-Einstellungen, Weckton (Android über das Plugin `packages/sw_sys`, auch im Dienst) |
| `beacon.dart`, `inc/veh.dart` | Fahrzeuge mit BLE-Beacons: Scans und Meldungen, Anzeige auf der Karte und im Fenster «Einsatzkräfte» |

## Bauen

Voraussetzungen:
- Flutter 3.47 (Version wie in `.github/workflows/mobile.yml`)
- Android: JDK 17 und das Android SDK
- iOS: macOS mit Xcode 27 oder neuer (ab April 2027 verlangt App Store Connect das iOS-27-SDK). Die Pipeline `ios.yml` baut im GitHub-Abbild `xcode-27` mit dem neusten Xcode 27.x; über die Repo-Variablen `IOS_RUNNER` (Runner) und `IOS_XCODE_MAJOR` (Hauptversion) lässt sich das ohne Codeänderung umstellen.

```bash
cd mobile/app
flutter pub get
flutter run                      # auf einem angeschlossenen Gerät
flutter build apk --release      # Android
flutter build ipa --release      # iOS (Signatur in Xcode einrichten)
```

### Pipeline (GitHub Actions)

`.github/workflows/mobile.yml` läuft bei Änderungen unter `mobile/` (Push auf `main` und Pull Requests), bei Release-Tags `v<Version>` (vom Workflow «Release» gestartet, siehe unten; ältere Tags `mobile-v<Version>` gehen weiterhin) und von Hand («Run workflow»). Die Pipeline prüft zuerst (`flutter analyze`, `flutter test`) und baut dann:

- **Android:** `swiftwater-rescue-<Version>.apk` (direkt installierbar) und `.aab` (für Google Play)
- **iOS:** `swiftwater-rescue-<Version>.ipa`

Die Pakete stehen als Artefakte beim Lauf. Version (Issue #175): Web, API und App haben eine gemeinsame Version in der Datei `VERSION` im Hauptordner; `pubspec.yaml` wird nicht mehr von Hand geändert. Die Pipeline übergibt Version und Build-Nummer zusätzlich mit `--dart-define=APP_VERSION/APP_BUILD` (`lib/version.dart`, Anzeige im Profil und in den Einstellungen, Header `X-App-Version` an den Server). Ein Release entsteht mit dem Workflow «Release» (Actions → «Release» → «Run workflow» auf `main`): Er erhöht `VERSION`, schreibt «Was ist neu» in `web/changes.json`, setzt den Tag `v<Version>`, erstellt den GitHub-Release und startet diese Pipeline auf dem Tag; sie lädt dann wie auf `main` nach TestFlight bzw. Google Play und hängt die Pakete an den Release. Android und iOS erhalten dieselbe Build-Nummer (Issue #230, siehe unten). Lokale Builds ohne `--dart-define` zeigen «Entwicklungsstand ohne Version».

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

Upload nach TestFlight: Läuft die Pipeline auf `main` (Push oder «Run workflow» auf `main`), ist das IPA signiert, gilt `IOS_EXPORT_METHOD` `app-store-connect` und sind die drei `ASC_*`-Geheimnisse gesetzt, lädt sie das IPA mit `xcrun altool` nach App Store Connect. Den Schlüssel erzeugt man in App Store Connect unter Benutzer und Zugriff → Integrationen → App Store Connect API (Team-Schlüssel, Rolle «App Manager»); die `.p8`-Datei lässt sich nur einmal herunterladen. Die App muss in App Store Connect angelegt sein (Bundle-ID `ch.swiftwaterrescue.app`). Die Build-Nummer (Issue #230) ist für Android und iOS dieselbe: `BUILD_BASE` (611212000) plus die Laufnummer von «Native Apps» (`github.run_number` von `mobile.yml`), sie steigt mit jedem Lauf um 1 und wird an `ios.yml` übergeben (Eingabe `build`). `testflight.yml` startet `mobile.yml` mit `ios_only`, damit auch die von Hand ausgelösten iOS-Builds denselben Zähler nutzen. Ein Datum als Nummer warnte Google Play als «deutlich höher als der vorherige Versionscode»; Version 1.3.1 ging trotzdem mit 611211921 live, danach lehnt Play kleinere Nummern ab. Deshalb die Basis, die nie verkleinert werden darf. Uploads nach Google Play laufen nacheinander (`concurrency` im Job `android`), sonst verfallen gleichzeitige Edits («This edit has expired»). Pull Requests und Tags laden nichts hoch. Der iOS-Teil steht in `.github/workflows/ios.yml` (wiederverwendbar).

Nur iOS nach TestFlight, von Hand: Actions → «TestFlight» → «Run workflow», Branch wählen (`.github/workflows/testflight.yml`; gleich: «Native Apps» → «Run workflow» mit «Nur iOS»). Startet «Native Apps» mit `ios_only`, baut und lädt hoch ohne Prüfung und ohne Android, auch von anderen Branches als `main`. `ITSAppUsesNonExemptEncryption` = `false` in `Info.plist` (nur HTTPS), damit App Store Connect nicht bei jedem Build nach der Verschlüsselung fragt.

Upload in Google Play: Läuft die Pipeline auf `main` (Push oder «Run workflow» auf `main`), ist das AAB mit dem Release-Schlüssel signiert und ist `PLAY_SERVICE_ACCOUNT_JSON` gesetzt, lädt sie das AAB mit `r0adkll/upload-google-play` hoch. Spur und Status über die Repository-Variablen (Actions → Variables, nicht Secrets) `PLAY_TRACK` (`internal` Standard, `alpha`, `beta`, `production`) und `PLAY_STATUS` (`completed` Standard, `draft`, `inProgress`). Pull Requests und Tags laden nichts hoch. Einrichten, einmalig:

1. In der [Play Console](https://play.google.com/console) die App mit der Paket-ID `ch.swiftwaterrescue.app` anlegen und das erste AAB (aus den Artefakten eines Laufs auf `main`) **von Hand** hochladen, zum Beispiel in den internen Test. Erst danach nimmt die API Uploads an. Dabei «Play App Signing» annehmen: Der Schlüssel aus `ANDROID_KEYSTORE_BASE64` wird zum Upload-Schlüssel, Google signiert die ausgelieferte App.
2. In der [Google Cloud Console](https://console.cloud.google.com/) im selben oder einem neuen Projekt die «Google Play Android Developer API» aktivieren, ein Dienstkonto anlegen und dafür einen JSON-Schlüssel erzeugen.
3. In der Play Console unter Nutzer und Berechtigungen das Dienstkonto (E-Mail-Adresse aus der JSON-Datei) einladen, mit Zugriff auf die App und den Rechten «Releases verwalten» bzw. «Releases in Test-Tracks veröffentlichen».
4. Den ganzen Inhalt der JSON-Datei als Geheimnis `PLAY_SERVICE_ACCOUNT_JSON` hinterlegen. Die Datei nie einchecken.
5. Solange die App in Google Play noch nie veröffentlicht wurde (Status «Entwurf»), nimmt Google nur Releases im Status `draft` an: Variable `PLAY_STATUS` = `draft` setzen und den Entwurf in der Play Console freigeben. Nach der ersten Veröffentlichung die Variable entfernen.

Die Android-Build-Nummer (`versionCode`) ist dieselbe wie bei iOS (siehe oben, die Laufnummer) und steigt mit jedem Lauf um 1. Ein «Re-run» behält die Nummer; Google lehnt sie dann als schon verwendet ab, falls der Upload schon gelungen war, deshalb in diesem Fall den Workflow neu starten.

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

Die Freigabe liegt vor: `ios/Runner/Runner.entitlements` enthält «Critical Alerts» und, für die Alarmstufe «hoch», «Time Sensitive Notifications». Beide Fähigkeiten sind bei der App-ID eingeschaltet; das Provisioning-Profil im Secret `IOS_PROFILE_BASE64` muss danach neu erstellt worden sein (eine Änderung der Fähigkeiten macht das alte ungültig). `ios.yml` prüft das vor dem Build und bricht mit einem Hinweis ab, wenn eine Berechtigung im Profil fehlt. Beim ersten Start fragt iOS separat nach «Kritische Hinweise» (Einstellungen → Mitteilungen → Swiftwater Rescue). Ohne Erlaubnis kommen Alarme als gewöhnliche Meldung mit Ton.

Push to Talk ist bei der App-ID ebenfalls eingeschaltet, steht aber noch nicht in den Entitlements: Es braucht das PushToTalk-Framework, den Hintergrundmodus `push-to-talk` und APNs direkt vom Server (`apns-push-type: pushtotalk`, Topic `ch.swiftwaterrescue.app.voip-ptt`, nicht über FCM).

## Regeln

- Was nur für die App gilt, kommt in `mobile/app/.gitignore`: Build-Dateien, `local.properties`, Schlüssel. Schlüssel, Zertifikate, Profile und Firebase-Dateien nie einchecken.
- Neue Bibliotheken der App in `web/legal.html` unter «Native Apps» nachführen.
