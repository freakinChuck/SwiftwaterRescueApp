# Konzept Mandantenfähigkeit (Issue #60)

## Ziel
Mehrere Organisationen (z. B. SLRG-Sektionen, Feuerwehren) teilen sich eine Installation. Jede arbeitet für sich, eine Person kann aber in mehreren mitwirken und wird über alle alarmiert. Organisationsübergreifende Einsätze sind möglich.

## Entscheide des Auftraggebers
| Frage | Entscheid |
|---|---|
| Datenhaltung | Variante B: eine Datenbank je Organisation plus zentrale Datenbank |
| Wechsel | wie ein Serverwechsel in der App; URL nur notfalls |
| Konto | ein eindeutiges Konto je Person; Name, Bild, Telefon, Regeln und Geräte für alle |
| Abwesenheit | bei der Erfassung wählbar: für alle Organisationen oder nur die aktuelle |
| Alarm | alles auf einem Gerät; Alarme anderer Organisationen werden gemeldet, die Person entscheidet wie bei parallelen Einsätzen |
| Plattform | neue Rolle «Plattform-Administrator», verwaltet nur die Mandanten |
| Einstellungen | Infrastruktur je Gruppe global oder je Mandant übersteuerbar |
| Übergreifend | Vereinbarung «A darf B aufbieten», es gelten die Einstellungen der führenden Organisation |
| Bestand | bestehende Installationen laufen unverändert weiter, kein Zusammenführen |
| Datenschutz | je Organisation |

## Aufbau
- **Zentral** (`pf_*`): Organisationen mit Datenbankangaben, Konten, Mitgliedschaften, Gerätesitzungen, gemeinsame Einstellungen. Standardmässig in der Datenbank der ersten Organisation, optional eigene Datenbank (`platform_dsn`).
- **Je Organisation**: die bisherige Datenbank unverändert, ergänzt um `users.gid` (Konto). Token und Sitzungen werden gespiegelt, damit der bestehende Code (Anmeldeprüfung, Push, Sitzungen) unverändert bleibt.
- **Wahl der Organisation** je Anfrage: Header `X-Org` (gemerkte Wahl des Geräts), für Links `?o=`; ohne Angabe Org 1, wenn die Person dort Mitglied ist, sonst die erste Mitgliedschaft. Bestehende Geräte senden nichts und landen wie bisher in Org 1.
- **Dateien** (Funk, Helikopter, Sperren) je Organisation unter `data/org<id>`; gemeinsame Zwischenspeicher (Overpass, Geodaten, Pegel) bleiben gemeinsam.

## Wechsel
Web: Auswahl im Profil und im Konto-Menü der Verwaltung, gemerkt in `sw_org`; lokale Daten getrennt je Organisation (Präfix `o<id>:`). Push-Meldungen tragen die Organisation (`o`, Schlüssel `o<id>/…`) und öffnen die App in ihr. Native App: je Konto eine Organisation (PR 2).

## Einstellungen
Gruppen Geodaten-Quellen, Kartenanbieter, Helikopter, Pegel & Abfluss: «Je Organisation», «Plattform-Vorgabe, übersteuerbar» (Vorgabe gilt bis zu eigenen Werten) oder «Global» (nur Plattform-Administratoren ändern, in den Organisationen gesperrt). Der VAPID-Schlüssel ist immer gemeinsam (ein Browser hat nur ein Push-Abonnement).

## Umsetzung in Schritten
1. **PR 1** Server, Web-App, Verwaltung, Plattformverwaltung (`plattform.html`), Push, Hilfe.
2. **PR 2** Native App: Organisation je Konto (`X-Org`), Push-Zuordnung über `o`, Überwachung über alle Organisationen.
3. **PR 3** Organisationsübergreifende Einsätze: Vereinbarungen, Aufgebot fremder Gruppen, Gäste in der Datenbank der führenden Organisation.
4. **PR 4** Demo, Bildschirmfotos, Anforderungen abschliessen.

## Grenzen
- Ein Benutzername ist plattformweit eindeutig.
- Rollen, Einsatzgruppen, Ausbildung und persönliche Einstellungen sind je Organisation.
- Die Plattformverwaltung sieht keine Einsatzdaten; Auszüge und Anonymisierung bleiben je Organisation.
