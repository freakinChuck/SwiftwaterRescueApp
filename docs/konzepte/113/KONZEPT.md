# Konzept: Sprachchat / Konferenzgespräch für Einsatz und Übung (Issue #113)

Stand: 13.11.2026, nur Konzept, noch kein Code. Die Bilder sind Mockups: in die laufende Einsatz-App bzw. Verwaltung eingesetzt, Kartenhintergrund und Fluss nachgebildet (ohne Kartenkacheln).

## Ausgangslage und Rahmen

- **Hosting cyon «Webhosting Single»:** PHP und MySQL, keine WebSockets, kein Dauerprozess. Echtzeit-Audio (WebRTC-Medien, Signalisierung mit Sekundenbruchteilen) kann **nicht** über den eigenen Server laufen.
- Die App kennt bereits Nachrichten mit Empfängerwahl nach Spezialrolle, Push (Web und FCM/APNs), Polling alle 4 s, Einsatzlog, Replay, Anonymisierung in Stufen und einen AI-Assistenten mit frei wählbarem Anbieter.
- Im Einsatz am Wasser: Lärm, Handschuhe, nasse Hände, schlechte Netzabdeckung in Schluchten. Das Funkgerät bleibt das primäre Mittel; die App **ergänzt** es (z. B. wenn Kräfte ausserhalb der Funkreichweite sind, für die Koordination mit Externen ohne Funk, für nachvollziehbare Durchsagen im Log).

## Varianten

| | A: Durchsagen (Sprechtaste, «Store and forward») | B: Live-Konferenz (WebRTC über Medienserver) | C: WebRTC Peer-to-Peer, Signalisierung über PHP-Polling |
|---|---|---|---|
| Läuft auf cyon allein | **ja** | nein, braucht eigenen Medienserver (LiveKit) | ja, aber nur bis ca. 4 Personen |
| Verzögerung | 2–6 s (Upload + Polling, im offenen Funk-Fenster 1.5 s) | < 0.5 s | < 0.5 s nach 5–20 s Verbindungsaufbau |
| Funkloch | Aufnahme wird nachgesendet | Abbruch, Wiederverbinden | Abbruch, neuer Aufbau |
| Nachvollziehbar (Log, Replay, AAR) | **ja**, Aufnahme + Transkript | nein (Aufzeichnung bewusst nicht vorgesehen) | nein |
| Akku / Daten | gering (nur beim Senden/Empfangen) | hoch, solange verbunden | hoch, wächst mit jeder Person |
| Bedienung | wie Funk: drücken, sprechen, loslassen | wie Telefonkonferenz | wie Telefonkonferenz |
| Aufwand | mittel | gross (Server, Betrieb, nativ) | gross und fehleranfällig (NAT, TURN) |

**Empfehlung:** Stufe 1 = Variante A (deckt den Einsatzalltag ab, passt zum Hosting und zum Funkverhalten). Stufe 2 = Variante B als Option für Organisationen, die einen eigenen Medienserver betreiben (wie heute schon PostGIS). Variante C wird nicht weiterverfolgt.

## Stufe 1: Durchsagen mit Sprechtaste

![Karte mit Sprechtaste und eingehender Durchsage](1-karte-sprechtaste.png)
![Fenster Funk](2-fenster-funk.png)
![Aufnahme läuft](3-aufnahme.png)

### Bedienung (Einsatz-App, Web und nativ)
- Neuer Kartenknopf **«🎙 Funk»** (Zähler = ungehörte Durchsagen) öffnet das Seitenfenster `panel` `'vx'` wie «Nachrichten».
- **Sprechtaste** unten in der Mitte der Karte (einblendbar im Fenster «Ansicht», pro Gerät gemerkt) und im Funk-Fenster: gedrückt halten = Aufnahme, loslassen = senden, zur Seite wischen = verwerfen. Höchstlänge einstellbar (Standard 60 s). Für Handschuhe: Einstellung «Antippen zum Starten/Stoppen» im Profil.
- **Kanäle** als Register: «Alle» (alle Teilnehmenden), «Leitung» (`canManage`), je Spezialrolle (optional), in Übungen «Übungsleitung». Kanalwahl über den Chip links unten. Gehört wird «Alle», die eigene Spezialrolle und, für die Leitung, «Leitung».
- **Mithören** (Schalter im Fenster, Standard aus der Verwaltung): Bei offener App werden neue Durchsagen sofort nacheinander abgespielt, oben erscheint die Leiste «🔊 Name · Rolle · Kanal» mit Stopp. Ohne Mithören: Zähler und Wiedergabe von Hand.
- Verlauf je Kanal mit ▶, Dauer, Wellenform, Transkript (✨) und Hinweis «aufgenommen ohne Verbindung, gesendet …».
- Funkloch: Aufnahmen bleiben im Gerät (IndexedDB bzw. Datei) und gehen beim nächsten erfolgreichen Polling raus, mit dem Aufnahmezeitpunkt.

### Server (`api/index.php`)
- Funktion `voice` in `FEATURES` (je Einsatztyp), Routen in `FEATURE_ROUTES`: `incident/voice` (Upload, Base64 oder `multipart/form-data`, Prüfung Länge/Grösse/Typ), `incident/voice/get` (Audio-Datei mit Rechteprüfung, nie direkt aus `api/data/`), `incident/voice/heard`.
- Tabelle `voice` (`incident_id`, `user_id`, `channel`, `rec_at`, `sent_at`, `dur_ms`, `mime`, `bytes`, `file`, `transcript`, `tr_state`, `removed`). Dateien in `api/data/voice/<Einsatz>/<id>.m4a|webm` (`.htaccess` sperrt den Ordner).
- `incident/state` liefert neue Durchsagen inkrementell als `voice` (ohne Audio, nur Metadaten), dazu `can_voice` und `voice_ch`. Während das Funk-Fenster offen ist oder Mithören läuft, pollt die App alle 1.5 s statt 4 s (nur `incident/state&since=`; vertretbar für cyon, weil nur wenige Geräte gleichzeitig mithören).
- Sprechrecht analog Nachrichten: `roles.can_voice`, Einstellung `voice_no_role`, `canManage` immer; Kanäle nur für die eigene Rolle bzw. die Leitung.
- Push: Durchsagen an mich bzw. meinen Kanal mit Schlüssel `v:` und Stufe `inc_kinds.voice_lvl` (wie `msg_lvl`, Issue #168). Nicht bei offener App.
- Log: Eintrag je Durchsage «spricht auf Kanal «Alle» (0:11)» mit Transkript; Gross-Modus und Verwaltung mit ▶. Replay: Durchsage als Ereignis am Zeitstrahl, abspielbar.
- **Transkription** (optional, über den AI-Assistenten): im Nachlauf `after()` an einen Anbieter mit OpenAI-kompatiblem `/audio/transcriptions` (Whisper, auch selbst betrieben). Neue AI-Funktion `tr` in `AI_FN`, Freigabe je Einsatztyp, Datenschutz wie heute (`aiPriv`). Das Transkript macht Durchsagen im Lärm lesbar und im Log durchsuchbar.
- Applikationslog: Upload abgelehnt, Transkription fehlgeschlagen (ohne Inhalt).

### Audioformat
- Nativ (Flutter, Paket `record`): AAC-LC mono 24 kbit/s in m4a – Android und iOS spielen das ab. 60 s ≈ 180 KB.
- Web: `MediaRecorder` mit `audio/mp4` wo verfügbar (Safari, neuere Chrome), sonst `audio/webm;codecs=opus`. **Offen:** Wiedergabe von webm/Opus auf älteren iPhones prüfen. Der Server wandelt nicht um (kein ffmpeg auf cyon); falls nötig, nimmt die Web-App nur noch mp4 auf, was alle aktuellen Browser können.

### Native App (Issue #108)
- Wiedergabe bei gesperrtem Bildschirm: Android über den bestehenden Vordergrunddienst (`service.dart`) mit Audio-Fokus; iOS über das **PushToTalk-Framework** (ab iOS 16, `PTChannelManager`, APNs-Typ `pushtotalk`): Systemoberfläche für den Kanal, Empfang im Hintergrund, Sprechtaste auch auf dem Sperrbildschirm. Braucht die Berechtigung «Push to Talk» und eine Erweiterung von `fcmMsg` bzw. direkten APNs-Versand – **Abklärung nötig** (FCM unterstützt den Typ `pushtotalk` nicht direkt).
- Bluetooth-Sprechtaste / Headset-Knopf als Sprechtaste (Android `MEDIA_BUTTON`, iOS über PushToTalk).

### Verwaltung, Datenschutz, Anonymisierung
![Verwaltung Funk & Konferenz](5-verwaltung.png)

- Verwaltung → Konnektivität → **«Funk & Konferenz»** (Recht `cfg_conn`, `NAV`/`NAV_PERM`, `SETTING_PERMS`): Höchstlänge, Format, Kanäle je Spezialrolle, Sprechen ohne Rolle, Mithören-Standard, Transkription, Konferenz-Anbieter, je Einsatztyp Durchsagen/Konferenz.
- Stimme ist ein Personendatum: neue Anonymisierungsstufe **`voice` «Sprachaufnahmen löschen»** in `ANON_STEPS` (Vorgabe 90/30 Tage, vor `replay`); Transkripte werden mit `log`/`texts` wie Nachrichten behandelt. «Aufzeichnung löschen» lässt Durchsagen stehen (sie sind Kommunikation, keine Standortverläufe).
- `legal.html`: Sprachaufnahmen, Aufbewahrung, Transkriptionsanbieter und Medienserver als Empfänger.
- Öffentliche Ansicht: nie Audio, höchstens «Durchsage (0:11)» ohne Inhalt bei `public_log` – Vorschlag: gar nicht.

## Stufe 2: Live-Konferenz (optional)

![Live-Konferenz](4-live-konferenz.png)

- **LiveKit** (Open Source, Apache 2.0) als Medienserver (SFU) mit eingebautem TURN über 443, betrieben auf einem eigenen Server (Docker, wie die PostGIS-API) oder als LiveKit Cloud. Alternative Jitsi Meet wäre mit eigener Oberfläche (iFrame) einfacher, passt aber schlechter in die Karte und in die native App.
- PHP stellt nur Zugangstokens aus (JWT HS256 mit API-Key/Secret, wie `vapidJwt`/`fcmToken` ohne Bibliothek): Route `incident/voice/join` prüft Teilnahme und Recht, Raum `sw-<Server>-<Einsatz>[-<Kanal>]`, Name und Rolle als Metadaten, Gültigkeit kurz. Secret getrennt in `settings` (nie an Geräte, leer = behalten).
- Clients: Web `livekit-client` über jsDelivr (in `legal.html` nachführen), Flutter `livekit_client`. Standard stumm, Sprechtaste auch in der Konferenz, Sprechanzeige je Person, Lautsprecher/Hörer, Verlassen. Wer nicht verbunden ist, erhält weiter Durchsagen.
- Keine Aufzeichnung der Konferenz (Datenschutz, Speicher). Log nur «tritt der Konferenz bei / verlässt sie».
- Native Hintergrund: Android Vordergrunddienst Typ `microphone`/`mediaPlayback`, iOS `CallKit` (Konferenz erscheint wie ein Anruf). 

## Anforderungen (Vorschlag für `docs/REQUIREMENTS.md`, neuer Bereich FU)
- FU-01 Teilnehmende senden Durchsagen per Sprechtaste an einen Kanal (Alle, Leitung, Spezialrolle, Übungsleitung).
- FU-02 Durchsagen werden bei offener App mit «Mithören» automatisch abgespielt, sonst gemeldet (Stufe je Einsatztyp).
- FU-03 Ohne Verbindung aufgenommene Durchsagen werden nachgesendet und mit Aufnahmezeit angezeigt.
- FU-04 Durchsagen stehen mit Dauer und optionalem Transkript im Einsatzlog und im Replay.
- FU-05 Funk ist je Einsatztyp ein-/ausschaltbar, Sprechrecht je Spezialrolle.
- FU-06 Sprachaufnahmen werden nach einstellbarer Frist gelöscht (Anonymisierungsstufe).
- FU-07 Optional Live-Konferenz je Einsatz über einen eigenen Medienserver.

## Etappen und Aufwand (grob)
1. Durchsagen Web + Server (Upload, Kanäle, Polling, Log, Rechte, Verwaltung, Anonymisierung): mittel.
2. Durchsagen nativ (Aufnahme, Hintergrund Android, Push `v:`): mittel.
3. Transkription über AI-Assistent: klein.
4. iOS PushToTalk-Framework: mittel bis gross, abhängig von APNs ohne FCM.
5. Live-Konferenz (LiveKit-Server, Tokens, Web, nativ, CallKit): gross.

## Offene Fragen an den Auftraggeber
1. Reichen Durchsagen (Stufe 1) für den Einsatz, oder ist die Live-Konferenz von Anfang an nötig?
2. Kann die Organisation einen eigenen Server für LiveKit betreiben (z. B. derselbe wie PostGIS)?
3. Sollen Durchsagen aufbewahrt werden (Log/AAR) – und wie lange? Vorschlag 90 Tage Einsatz, 30 Tage Übung.
4. Transkription gewünscht? Wenn ja, eigener Whisper-Server oder externer Anbieter?
5. Kanäle je Spezialrolle oder nur «Alle» und «Leitung»?
6. Sollen Externe ohne App (z. B. Polizei) per Link zuhören/sprechen können (wie öffentliche Ansicht)? Nicht Teil dieses Konzepts.
