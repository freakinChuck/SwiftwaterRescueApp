# Mobile Apps

Platz für die künftigen nativen Apps (Issue #109). Bisher gibt es keine: Die Einsatz-App läuft als PWA aus `web/`.

Vorgesehene Ablage:

| Ordner | Inhalt |
|---|---|
| `mobile/android/` | Android-App (z. B. Kotlin oder Capacitor-Hülle um die PWA) |
| `mobile/ios/` | iOS-App (z. B. Swift oder Capacitor-Hülle), für Critical Alerts (siehe PR #2) |
| `mobile/shared/` | gemeinsamer Code beider Apps, falls nötig |

Die Apps sprechen dieselbe JSON-API wie die Web-App (`web/api/index.php`, Routen über `?r=<route>`, Token im Header `X-Token`). Server-Code gehört nicht hierher, sondern nach `web/api/`.
Was nur für eine App gilt (Build-Dateien, Signaturschlüssel, `local.properties` usw.), kommt in die `.gitignore` des jeweiligen Unterordners; Schlüssel und Zertifikate nie einchecken.
