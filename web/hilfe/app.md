# Native App (Android und iOS)

Die native App «Swiftwater Rescue» für Android und iOS bietet dieselbe Einsatz-App wie der Browser, dazu:

- **kritische Alarme** mit Vollbild und Weckton, auch bei «Nicht stören» und lautlosem Handy,
- **Standort teilen im Hintergrund**, auch bei gesperrtem Bildschirm,
- **mehrere Organisationen** (Server) in einer App,
- **Fahrzeuge** über Bluetooth-Beacons.

Verwaltung, «Meine Einsätze» und diese Anleitung öffnet die App als Webseite; du musst dich dafür nicht erneut anmelden.

## Konten {#konten}

Beim ersten Start fügst du ein Konto hinzu: Adresse des Servers, Benutzername und Passwort, optional eine eigene Bezeichnung. Weitere Konten (andere Organisationen) fügst du in der Kontenliste hinzu. Je Konto schaltest du ein, ob es überwacht wird («aktiv»).

- Die App öffnet beim Start das zuletzt verwendete Konto.
- Zurück zur Kontenliste: Servername mit ⇄ in der Kopfzeile des Menüs antippen oder im Profil «Konten / Server wechseln».
- Die Kontenliste zeigt je Konto, ob der Standort zuletzt geteilt werden konnte.
- Über **Demo ausprobieren** in der Kontenliste startest du den [Demo-Modus](#start/demo) auch in der App.

## Sprache {#sprache}

Unter **Einstellungen** (Kontenliste) und im Profil, Karte «Anzeige», wählst du die Sprache der App: Deutsch, Englisch, Französisch, Italienisch oder «Automatisch» (Sprache des Geräts). Sie gilt für alle Konten und für die Meldungen; die App meldet sie den Servern, damit Push in derselben Sprache kommt.

## Alarm und Überwachung {#hintergrund}

Die App fragt alle aktiven Konten regelmässig ab und meldet Alarme, Nachrichten an dich, Durchsagen und das Einsatzende. Mit eingerichtetem Push kommen die Meldungen auch bei geschlossener App sofort.

- **Android**: Ohne Push oder während eines Einsatzes läuft ein Hintergrunddienst mit einer dauernden Benachrichtigung. «Immer im Hintergrund abfragen» im Profil hält ihn dauernd aktiv.
- **iOS**: Meldungen bei geschlossener App kommen nur über Push.

## Kritische Alarme {#kritisch}

Bei Einsatztypen mit der Alarmstufe «kritisch» zeigt die App den Alarm im Vollbild und spielt einen Weckton, bis du ihn quittierst (höchstens 5 Minuten).

- **Android**: Erlaube der App den Zugriff auf **«Nicht stören»**, damit der Alarm auch dann klingelt. Die App fragt danach.
- **iOS**: Kritische Meldungen brauchen die Erlaubnis für «Kritische Hinweise».
- Den Ton wählst du im Profil unter «Alarmierung» je Einsatztyp (Signalhörner, Sirenen, Melodien) und hörst ihn mit «Probe» an.

## Standort im Hintergrund {#standort}

Damit die App deinen Standort im Einsatz auch bei gesperrtem Bildschirm teilt, braucht sie die Standort-Erlaubnis **«Immer zulassen»**. Die App fragt nach dem Annehmen eines Einsatzes danach. Ohne diese Erlaubnis teilt sie den Standort nur, solange sie offen ist.

Nach dem Einsatzende oder wenn du den Einsatz verlässt, hört die App auf, den Standort zu teilen.

## Funk {#funk}

Durchsagen und Live-Konferenz funktionieren in der App wie im Browser (siehe [Funk](#einsatz/funk)). Die App braucht dafür die Erlaubnis für das Mikrofon. Unter Android läuft die Konferenz auch bei gesperrtem Bildschirm weiter.

## Fahrzeuge und Beacons {#beacons}

Im Einsatz mit Fahrzeugen fragt die App einmal nach Bluetooth. Sie erkennt die Beacons der Fahrzeuge in der Nähe, meldet ihren Standort und ordnet dich dem Fahrzeug zu, in dem du sitzt. Ausserhalb von Einsätzen meldet sie Beacons nur mit deiner Zustimmung im Profil.

**Fahrzeuge jetzt scannen** (im Fenster «Einsatzkräfte» unter «Fahrzeuge» und im Profil, Karte «Fahrzeuge») sucht sofort nach den Beacons in der Nähe, zeigt die gefundenen Fahrzeuge mit Signalstärke und meldet sie: im Einsatz mit deinem Standort, sonst ohne deinen Namen (nur wenn die Verwaltung Meldungen ausserhalb von Einsätzen erlaubt). Praktisch zum Prüfen, ob ein Beacon funktioniert.

**Batteriestand:** Beacons von DX-Smart senden ihn, wenn in ihrer Konfigurations-App der Frame «Device Info» eingeschaltet ist (den Frame «iBeacon» eingeschaltet lassen). Beacons mit Eddystone-TLM gehen ebenso: Sie senden die Spannung, die App schätzt daraus die Prozent (3.0 V = voll, 2.0 V = leer). Unter iOS braucht es dazu zusätzlich den Frame «Device Info». Die App liest ihn bei jedem Scan mit; er steht beim Fahrzeug (ab 20 % mit «⚠ Batterie schwach») und in der Übersicht von «Fahrzeuge jetzt scannen». Dort erscheinen auch Beacons in der Nähe, die zu keinem Fahrzeug gehören, mit ihrer MAC-Adresse. Unter iOS ordnet die App den Batteriestand nur über die MAC-Adresse des Fahrzeugs zu: Ein Android-Gerät lernt sie beim ersten Scan, sonst trägt die Verwaltung sie beim Fahrzeug ein.

> ! Am iPhone findet die App Beacons nur mit **Genauer Standort** (Einstellungen → Swiftwater Rescue → Standort). Ist er aus, steht das im Profil unter «Fahrzeuge», mit dem Knopf **Genauen Standort erlauben**. Ausserhalb von Einsätzen braucht es zusätzlich «Immer».

## Updates {#updates}

Ist deine App-Version zu alt für den Server, erscheint ein Hinweis mit **Aktualisieren** (Google Play bzw. App Store). **Was ist neu** im Profil zeigt die Änderungen.
