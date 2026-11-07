# Native Apps

Native Apps für Android und iOS (Issue #108), gebaut mit Flutter in `mobile/app`. Sie sprechen dieselbe JSON-API wie die Web-App (`web/api/index.php`). Server-Code gehört nicht hierher, sondern nach `web/api/`.

## Was die App kann

- **Mehrere Backends:** Mit «Backend hinzufügen» meldet man sich mit Server-Adresse (wie die der Web-App, z. B. `https://example.ch/swiftwater/`), Benutzername und Passwort an. Jedes Backend ist einzeln **aktiv** schaltbar. Auf allen aktiven Backends kommen gleichzeitig Alarme, Nachrichten an einen selbst und das Einsatzende an.
- **Web-App in der App:** Ein Antippen öffnet die Web-App des Backends in einer WebView, schon angemeldet. Karte, Einsatz und Verwaltung sind dieselben wie im Browser. Fremde Adressen öffnen sich ausserhalb: Navigations-Apps, Telefon, Mail.
- **Eigene Anmeldung je Gerät:** Die App erhält eine Gerätesitzung (`sessions` auf dem Server). Sie meldet weder den Browser noch andere Geräte ab. Im Profil der Web-App stehen die angemeldeten Apps unter «Angemeldete Apps».
- **Alarme wie in der Web-App:**

  | Alarmstufe | Verhalten |
  |---|---|
  | kritisch | Vollbild und Dauerton, Android auch bei «Nicht stören», sobald der Zugriff erlaubt ist |
  | hoch | Vollbild und einmaliger Ton |
  | normal | gewöhnliche Meldung |
  | leise | Meldung ohne Ton |
  | keine | nichts |

  Die Meldungen nutzen dieselben Schlüssel wie die Web-App (`i:`, `m:`, `e:`), deshalb meldet nichts doppelt. Ist die Web-App eines Kontos offen, meldet sie selbst.

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
- Standort

## Aufbau (`mobile/app/lib`)

| Datei | Inhalt |
|---|---|
| `main.dart` | Start, Navigation, Abfrage bei offener App (iOS) |
| `home.dart` | Kontenliste, Anmeldung, Einstellungen |
| `web.dart` | WebView: setzt `sw_token` und `sw_acked` vor dem ersten Skript, Handler `swLogout`/`swUnauth` |
| `accounts.dart` | Konten im sicheren Speicher (Keychain bzw. verschlüsselt) |
| `monitor.dart` | Abfrage `incident/state` je Konto, meldet Neues |
| `notify.dart` | Benachrichtigungen, Kanäle je Alarmstufe |
| `service.dart` | Android-Vordergrunddienst |
| `push.dart` | Firebase Cloud Messaging (optional) |
| `sys.dart` | Akku-Optimierung, Standort, App-Einstellungen (Android, `MainActivity.kt`) |

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

Die Pakete stehen als Artefakte beim Lauf. Bei einem Tag `mobile-v1.2.0` übernimmt die Pipeline die Version 1.2.0, hängt die Pakete an ein GitHub-Release an und nimmt die Laufnummer als Build-Nummer.

Geheimnisse (Repository → Settings → Secrets and variables → Actions) sind alle optional:

| Geheimnis | Zweck |
|---|---|
| `ANDROID_KEYSTORE_BASE64` | Signierschlüssel (`.jks`, base64). Ohne ihn signiert die Pipeline mit dem Debug-Schlüssel: installierbar, aber nicht für Google Play. |
| `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD` | Zugang zum Schlüssel |
| `IOS_CERT_P12_BASE64`, `IOS_CERT_PASSWORD` | Verteilerzertifikat (`.p12`, base64) |
| `IOS_PROFILE_BASE64` | Provisioning-Profil für `ch.swiftwaterrescue.app` (base64) |
| `IOS_TEAM_ID` | Team-ID des Apple-Developer-Kontos |
| `IOS_EXPORT_METHOD` | `app-store-connect` (Standard), `ad-hoc` oder `enterprise` |
| `FCM_API_KEY`, `FCM_PROJECT_ID`, `FCM_SENDER_ID`, `FCM_APP_ID_ANDROID`, `FCM_APP_ID_IOS` | Firebase-Angaben für Push (siehe unten) |

Ohne iOS-Signatur entsteht ein unsigniertes `…-unsigned.ipa`. Installieren lässt es sich erst nach dem Nachsignieren.

Lokal liest Android den Signierschlüssel aus `android/key.properties` (`storeFile`, `storePassword`, `keyAlias`, `keyPassword`). Die Datei steht in der `.gitignore`.

### Push einrichten (optional)

1. In der [Firebase-Konsole](https://console.firebase.google.com/) ein Projekt anlegen und darin eine Android-App und eine iOS-App mit der Paket-ID `ch.swiftwaterrescue.app` hinzufügen.
2. iOS: In Firebase unter Cloud Messaging den APNs-Schlüssel (`.p8`) des Apple-Developer-Kontos hinterlegen. In der App-ID «Push Notifications» einschalten.
3. Die Werte API-Schlüssel, Projekt-ID, Sender-ID und App-ID je Plattform als Geheimnisse `FCM_*` hinterlegen, oder lokal mit `--dart-define=FCM_API_KEY=…` usw. bauen. Es braucht weder `google-services.json` noch `GoogleService-Info.plist`.
4. Server: Unter Projekteinstellungen → Dienstkonten einen privaten Schlüssel erzeugen. Die JSON-Datei ausserhalb des Webroots oder in `web/api/data/` ablegen und in `api/config.php` eintragen: `'fcm' => __DIR__ . '/data/firebase-service-account.json'`. Die Datei nie einchecken.

Ein Server ohne Dienstkonto meldet `push/key` mit `native: 0`. Die App zeigt dann «Push auf diesem Server nicht eingerichtet» und überwacht nur per Abfrage. Mehrere Backends können dasselbe Firebase-Projekt nutzen; die Nachricht nennt Server und Person (`srv`, `uid`), damit die App sie dem richtigen Konto zuordnet.

### Kritische Hinweise unter iOS

Damit ein Alarm auch bei «Nicht stören» und Lautlos ertönt, braucht es die Berechtigung «Critical Alerts». Apple vergibt sie nur auf Antrag: https://developer.apple.com/contact/request/notifications-critical-alerts-entitlement/

Nach der Freigabe den Eintrag in `ios/Runner/Runner.entitlements` einkommentieren und das Provisioning-Profil neu erstellen. Für die Alarmstufe «hoch» ebenso «Time Sensitive Notifications». Ohne diese Berechtigungen kommen Alarme als gewöhnliche Meldung mit Ton.

## Regeln

- Was nur für die App gilt, kommt in `mobile/app/.gitignore`: Build-Dateien, `local.properties`, Schlüssel. Schlüssel, Zertifikate, Profile und Firebase-Dateien nie einchecken.
- Neue Bibliotheken der App in `web/legal.html` unter «Native Apps» nachführen.
- Android baut mit dem Android-Gradle-Plugin 8.13 und Gradle 8.14, nicht mit den Versionen 9 aus der Flutter-Vorlage: `flutter_inappwebview_android` 1.1.3 nutzt `proguard-android.txt`, und AGP 9 bricht dann den Build ab. Erst wechseln, wenn das Plugin AGP 9 unterstützt.
