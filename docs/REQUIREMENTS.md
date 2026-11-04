# Swiftwater Rescue – Anforderungen

Systematische Liste aller Anforderungen an die App (Issue #38). Grundlage ist die Anforderungsspezifikation «SwiftwaterRescue App Specs V0.1» vom 01.11.2026, ergänzt um die Entscheide des Auftraggebers und die umgesetzten Issues.

**Dieses Dokument wird bei jeder Änderung nachgeführt.** Neue oder geänderte Anforderungen erhalten eine fortlaufende ID im passenden Bereich; entfallene bleiben mit «entfallen» stehen, damit IDs nicht neu vergeben werden. Technische Details (Routen, Tabellen, Funktionsnamen) stehen in `CLAUDE.md`, hier steht, *was* die App können muss.

Aufbau je Anforderung: **ID** – Anforderung (Herkunft). Herkunft: «Spec» = Spezifikation V0.1, «AG» = Entscheid des Auftraggebers, «#n» = Issue.

Status: alle aufgeführten Anforderungen sind umgesetzt, ausser sie sind mit **offen** oder **Grenze** markiert.

---

## 1. Rahmenbedingungen (RB)

- **RB-01** – Die App läuft als Web-App im Browser und am Handy, ohne Installation aus einem App-Store (Spec).
- **RB-02** – Betrieb auf cyon «Webhosting Single»: PHP und MySQL bzw. SQLite, keine WebSockets, kein Dauerprozess, kein Build-Schritt (AG).
- **RB-03** – Aktualisierung der Lage per Polling (alle 4 s) statt Push-Verbindung (AG).
- **RB-04** – Oberfläche, Texte und Meldungen auf Deutsch in Schweizer Rechtschreibung (AG).
- **RB-05** – Die Datenbank wird beim ersten Aufruf automatisch angelegt und bei Updates automatisch migriert (AG).
- **RB-06** – Berechtigungen werden immer auf dem Server geprüft; die Oberfläche blendet nur aus (AG).
- **RB-07** – Nur ein aktiver Einsatz gleichzeitig (**Grenze**).
- **RB-08** – Nach einem Update laden alle Geräte die neue Version (kein Mischstand aus alten und neuen Dateien) (#19).
- **RB-09** – Zugangsdaten werden nie ins Repository eingecheckt (AG).
- **RB-10** – Auslieferung per Skript aus einem eingecheckten Git-Stand, mit Syntaxprüfung auf dem Server; Konfiguration und Datenbank werden nie überschrieben (AG).
- **RB-11** – Test-, Entwicklungs- und Produktivsystem sind an unterschiedlich umrandeten App-Symbolen erkennbar (#56).

## 2. Anmeldung und Konto (KO)

- **KO-01** – Anmeldung mit Benutzername und Passwort (Spec).
- **KO-02** – Jede Person kann ihr eigenes Passwort ändern (aktuelles Passwort nötig) (AG).
- **KO-03** – Administratoren setzen Passwörter zurück; die Person wird abgemeldet und muss beim nächsten Login ein eigenes Passwort setzen. Gilt auch für neue Benutzer und das erste Admin-Konto (AG).
- **KO-04** – Persönliche Einstellungen auf einer eigenen Seite «Profil»: Profilbild, Passwort, Abmelden, Übungsmodus, Push, App installieren, Koordinatensystem, Navigations-App (#58).
- **KO-05** – Abmelden löscht den auf dem Gerät gemerkten Stand (#67).
- Zugriffsschutz bzw. Rate-Limit für den Login (**offen**).

## 3. Benutzer und Berechtigungen (BE)

- **BE-01** – Frei definierbare Benutzerrollen mit einer Auswahl an Rechten; eine Person kann mehrere Rollen haben und erhält alle Rechte (#63).
- **BE-02** – Rechte: Einsätze starten, Übungen starten, jeden Einsatz leiten, Übungen vorbereiten, Benutzer verwalten, Benutzerrollen verwalten, Einstellungen je Kategorie (Einsatz, Funktionen, Karte & Daten), Statistik/Log/Replay aller Einsätze, Anonymisieren und Aufzeichnungen löschen, Einsicht für Einsatzkräfte, Einsatzgruppen verwalten (#63, #90).
- **BE-03** – Die Rolle Systemadministrator hat immer alle Rechte, auch künftige, und ist weder änderbar noch entfernbar; mindestens eine aktive Person behält sie (#63).
- **BE-04** – Niemand kann Rechte vergeben oder Personen bearbeiten, deren Rechte über die eigenen hinausgehen (#63).
- **BE-05** – Eine Rolle kann bei neuen Benutzern vorausgewählt sein (#63).
- **BE-06** – Benutzer entfernen = deaktivieren: abgemeldet, aus dem laufenden Einsatz ausgetragen, Daten bleiben, reaktivierbar (AG).
- **BE-07** – Löschen nur für Konten, die sich nie angemeldet haben und in keinen Einsatz- oder Übungsdaten vorkommen (AG).
- **BE-08** – Die Verwaltung zeigt jeder Person nur die Bereiche, für die sie ein Recht hat (#63).

## 4. Alarmierung und Einsatz (EI)

- **EI-01** – Einsatz bzw. Übung starten mit Alarmmeldung; getrennte Knöpfe «Einsatz starten» und «Übung starten», je nach Recht (Spec, #64).
- **EI-02** – Der Zeitpunkt des Alarmeingangs kann beim Start übersteuert werden (AG).
- **EI-03** – Scharfer Einsatz = immer kritischer Alarm (Vollbild, Dauerton, Vibration); Übung = normale Benachrichtigung, ausser die Person hat den Übungsmodus eingeschaltet (Spec, AG).
- **EI-04** – Annehmen mit einer Einrückoption (z. B. Basis, Anfahrt direkt, Logistik, Koordinator KP Rück); die Optionen sind in der Verwaltung konfigurierbar, werden deaktiviert statt gelöscht, mindestens eine bleibt aktiv (Spec, AG).
- **EI-05** – Je Einrückoption einstellbar: Standort teilen, Leitungsrechte, Zählung im Zähler der Einsatzkräfte (AG, #30).
- **EI-06** – Ablehnen gibt es nicht mehr; ältere Ablehnungen bleiben in Log und Statistik (AG).
- **EI-07** – Austreten und Wiedereinstieg sind möglich (Spec).
- **EI-08** – Quittierte Alarme bleiben auf dem Gerät quittiert (AG).
- **EI-09** – Spezialrollen (z. B. Einsatzleitung, Sicherungsposten, Beobachtungsposten) in der Verwaltung definierbar, mit Reihenfolge, Farbe, Flag «Posten» (begrenzt das Suchgebiet) und Recht «Nachrichten senden» (Spec, AG).
- **EI-10** – Einsatzleitung = Rolle Einsatzleitung, Einrückoption mit Leitungsrechten, Recht «Jeden Einsatz leiten» oder Ersteller des Einsatzes, jeweils nur, solange die Person teilnimmt (Ausnahme Übungsleitung) (AG, #82).
- **EI-11** – Die Einsatzleitung kann den Einsatz beenden, Einsatzkräfte entfernen und deren Spezialrollen ändern (AG).
- **EI-12** – Einsatzart sichtbar und unterscheidbar: «Einsatz» rot, «Übung» blau (AG).
- **EI-13** – Einsatzgruppen in der Verwaltung: Name, Farbe, Mitglieder, ob für scharfe Einsätze aufbietbar oder nur für Übungen; deaktivieren statt löschen; jede Person sieht ihre Gruppen im Profil (#90).
- **EI-14** – Beim Start werden Einsatzgruppen aufgeboten; nur ihre Mitglieder werden alarmiert. Bei Übungen zusätzlich einzelne Personen. Ohne Einsatzgruppen werden alle alarmiert (#90).
- **EI-15** – Welche Einsatzgruppen eine Person aufbieten darf, legen ihre Benutzerrollen fest; «Alle Personen» nur, wer alle Gruppen aufbieten darf (#90).
- **EI-16** – Einsatztypen (z. B. «Person im Wasser») mit vorausgewählten Einsatzgruppen, je für scharfe Einsätze, Übungen oder beide; der Typ steht im Einsatz, im Log und in der Statistik (filterbar) (#90).
- **EI-17** – Nicht Aufgebotene sehen einen scharfen Einsatz nicht und können nicht beitreten, ausser die Einsatzleitung erlaubt allen den Beitritt (ohne Alarm) (#90).
- **EI-18** – Die Einsatzleitung kann weitere Gruppen (bei Übungen auch Personen) oder alle nachbieten; alarmiert werden nur die neu Aufgebotenen (#90).

## 5. Karte (KA)

- **KA-01** – Karte im Vollbild; alle Bedienelemente in einem versteckten Menü (☰) (AG).
- **KA-02** – Grundkarte swisstopo Luftbild (Standard), umschaltbar auf Landeskarte und OpenStreetMap; Anbieter leicht austauschbar; Wahl pro Gerät gemerkt (AG, #34).
- **KA-03** – Fenster «Ansicht»: Grundkarte und je Kategorie ein Häkchen (Einsatzkräfte, Externe, Rettlinge, PLS, Suchgebiet, Markierungen, Bereiche, Nachrichten-Markierungen, Helikopter), «Alle ein-/ausblenden», pro Gerät gemerkt (#34).
- **KA-04** – Karte drehbar (zwei Finger, Shift + Mausrad, Kompass); Klick auf den Kompass richtet nach Norden aus (AG).
- **KA-05** – Startansicht beim Öffnen eines Einsatzes: Übersicht über alle aktiven Positionen (AG).
- **KA-06** – Kamerafokus: Suchgebiet (ganz und je Teilgebiet), Einsatzkräfte, Auto-Übersicht (hält alle sichtbaren aktiven Positionen im Bild) (AG, #51).
- **KA-07** – Kartenknöpfe rechts unten einklappbar; eingeklappt zeigen sie die Summe der roten Zähler (#42).
- **KA-08** – Die Seite selbst scrollt und zoomt nie, nur die Karte (#84).
- **KA-09** – Am Handy decken Seitenfenster die Kartenknöpfe ab (AG).

## 6. Einsatzkräfte und Standort (EK)

- **EK-01** – Teilnehmende teilen ihren Standort (sofern die Einrückoption es vorsieht) und können ihn manuell setzen (Spec).
- **EK-02** – Fenster «Einsatzkräfte»: Liste nach Spezialrolle gruppiert (Reihenfolge wie in der Verwaltung, ohne Rolle zuletzt), Gruppen «mit App» und «Extern» einklappbar, Zähler am Knopf (#30, #39).
- **EK-03** – «Zentrieren» und «Folgen» je Person; Folgen endet über ✕, beim Verschieben von Hand und beim Austreten (AG).
- **EK-04** – «Mein Standort» unten im Menü immer in LV95 und WGS84 Grad/Min/Sek (#76).
- **EK-05** – Fahrten mit Fahrzeug werden erkannt und im Log festgehalten («fährt von A nach B, ca. X km Luftlinie, Ø Y km/h»); Bewegungen auf dem Gewässer und manuell gesetzte Positionen zählen nicht (AG).
- **EK-06** – «Suchgebiet erreicht» wird geloggt (AG).

## 7. Externe Einsatzkräfte (EX)

- **EX-01** – Einsatzkräfte ohne App-Konto mit festem Standort (z. B. Posten anderer Organisationen), erfasst, bearbeitet, verschoben und entfernt von der Einsatzleitung (#39).
- **EX-02** – Vorlagen in der Verwaltung (Name, Beschreibung, Spezialrolle, Kürzel, Farbe, optional Standort in LV95/LV03/WGS84); von Hand Erfasste stehen für den Rest des Einsatzes als Vorlage bereit (#39).
- **EX-03** – Eigene Darstellung auf der Karte (eckig, gestrichelt, Farbe, Kürzel); Posten-Rollen begrenzen das Suchgebiet (#39).
- **EX-04** – Ein- und ausschaltbar als Funktion (#35).

## 8. Suchgebiet und PLS (SG)

- **SG-01** – Point last seen (PLS) des Einsatzes optional; die Einsatzleitung setzt, verschiebt oder entfernt ihn (#24).
- **SG-02** – Jeder Rettling kann einen eigenen PLS mit Zeitpunkt haben (#24).
- **SG-03** – Ein PLS gilt immer mit Zeitpunkt (Beginn der Drift), Standard Alarmeingang (#24).
- **SG-04** – Fliessgeschwindigkeit: von allen Teilnehmenden manuell setzbar (AG; BAFU-Vorschlag auf Wunsch entfernt).
- **SG-05** – Das Suchgebiet folgt dem Flusslauf (OSM-Gewässernetz), Ufergebiet 30 m je Seite, Länge aus Fliessgeschwindigkeit × Zeit seit PLS (Spec, AG).
- **SG-06** – An Gabelungen folgt das Suchgebiet allen Armen (#40).
- **SG-07** – Das Suchgebiet endet am ersten Sicherungs- bzw. Beobachtungsposten auf dem jeweiligen Arm (Spec).
- **SG-08** – Mehrere PLS ergeben die Vereinigung ihrer Gebiete; es zählen nur vermisste Rettlinge; Hinweis mit Kurzfassung, aufklappbar je PLS (#24).
- **SG-09** – Rückfall ohne Gewässerdaten: Kreis um den PLS (AG).
- **SG-10** – Gewässerdaten über den Server mit Cache, Sperre gegen doppelte Abfragen, abgelaufene Daten als Rückfall, konfigurierbare Overpass-Server mit Reihenfolge, Abfrage notfalls direkt vom Gerät (#28).
- **SG-11** – Statistik der Overpass-Abfragen je Server und Quelle, zurücksetzbar (#45).
- **SG-12** – Zugriff auf Overpass gesamt und je Server abschaltbar (#75).
- Gewässerwahl am PLS korrigierbar (Auswahlschritt) (**offen**).

## 9. Rettlinge (RE)

- **RE-01** – Mehrere Rettlinge pro Einsatz mit Name und Beschreibung; ohne Namen «Unbekannter Rettling» fortlaufend nummeriert (AG, #86).
- **RE-02** – Erfassen, bearbeiten, Fundort markieren (eigener Standort oder Karte) und «gerettet» melden bzw. zurücknehmen dürfen alle Teilnehmenden; entfernen nur die Einsatzleitung (AG).
- **RE-03** – Fenster «Rettlinge» gruppiert Vermisst → Standort bekannt → Gerettet (einklappbar), Zähler = nicht gerettete (AG).
- **RE-04** – Je Rettling eine Knopfzeile (Fundort, Gerettet, Suchgebiet, Zeigen, Navigation, ⋯); unter ⋯ Fundort zurücksetzen, PLS setzen, Bearbeiten, Entfernen (#85).

## 10. Nachrichten (NA)

- **NA-01** – Nachrichten an Personen, die den Einsatz angenommen haben; alle Teilnehmenden sehen alle Nachrichten, benachrichtigt werden nur die gewählten Empfänger (keine Auswahl = alle) (Spec, AG).
- **NA-02** – Senden pro Spezialrolle berechtigbar; für Personen ohne Rolle über eine Einstellung; die Einsatzleitung darf immer (AG).
- **NA-03** – Optional lesen auch nicht Teilnehmende alle Nachrichten (nur lesend, ohne Benachrichtigung) (AG).
- **NA-04** – Eigenes Seitenfenster mit Zähler für Ungelesenes; an einen selbst gerichtete sind markiert (AG).
- **NA-05** – Nachrichten speichern den Standort der sendenden Person und können eine Kartenmarkierung tragen (eigener Standort oder gewählter Punkt), entfernbar durch Absender oder Einsatzleitung (AG).

## 11. Markierungen und Bereiche (MB)

- **MB-01** – Die Einsatzleitung setzt Markierungen (Punkt) und Bereiche (Polygon, 3–200 Eckpunkte) mit Bezeichnung und Farbe; für alle sichtbar (AG).
- **MB-02** – Fenster «Markierungen»: PLS zuoberst, danach Markierungen und Bereiche, je Eintrag und Gruppe ein-/ausblendbar, «Zeigen» (AG).
- PLS auf eine Markierung verschieben (**offen**).

## 12. Werkzeuge (WZ)

- **WZ-01** – Flussdistanz: zwei Punkte antippen, Distanz entlang Gewässer und Luftlinie, Richtung, Koordinaten, Treibzeit und Dauer für Vergleichsgeschwindigkeiten (in der Verwaltung einstellbar); auch ohne Einsatz; höchstens 50 km Luftlinie (AG).
- **WZ-02** – Navigation: Knopf 🧭 bei allen Positionen öffnet die Navigations-App (Google Maps, Apple Karten, Waze, andere App); Apps in der Verwaltung ausschaltbar mit Standard, pro Person wählbar; versehentliches Antippen von Markern fragt nach (#79).
- **WZ-03** – Koordinaten in allen Listen im gewählten System (LV95 Standard, LV03, WGS84 dezimal, WGS84 Grad/Min/Sek, ausblenden); Standard in der Verwaltung, pro Person übersteuerbar (AG).

## 13. Helikopter (HE)

- **HE-01** – Luftfahrzeuge aus offenen ADS-B-Daten auf der Karte, über den Server abgefragt (#73).
- **HE-02** – Hauptschalter, Ein/Aus je Einsatz mit Vorauswahl je Art; die Einsatzleitung schaltet im Einsatz um (#73).
- **HE-03** – Konfigurierbar: Quellen (nur gratis und ohne Schlüssel), Filter (Rettungshelikopter, Helikopter, alle), Sichtbarkeit in App, öffentlicher Ansicht und Replay, Umkreis, Flughöhe, Alter, Spur, Abfrageintervall, Liste der Rettungshelikopter (#73).
- **HE-04** – Abfrage nur in der Relevanzzone um PLS, Einsatzkräfte, Rettlinge, Markierungen und Bereiche (#73).
- Tief fliegende Helikopter in Tälern fehlen oft (keine Empfänger in Sicht) (**Grenze**).

## 14. Funktionen ein-/ausschalten (FU)

- **FU-01** – In der Verwaltung je für scharfe Einsätze und Übungen ein- und ausschaltbar: Suchgebiet, Rettlinge, Nachrichten, Markierungen & Bereiche, Externe Einsatzkräfte, Flussdistanz, öffentliche Ansicht, Aufzeichnung, Navigation (#35, #79).
- **FU-02** – Gilt sofort, auch im laufenden Einsatz; der Server liefert keine Daten ausgeschalteter Funktionen und lehnt deren Aktionen ab; Daten bleiben erhalten (#35).
- **FU-03** – Neue Funktionen sind standardmässig eingeschaltet (#35).

## 15. Einsatzlog und Auswertung (LO)

- **LO-01** – Einsatzlog mit Beitritt, Rollen, Fahrten, Suchgebiet erreicht, Nachrichten im Volltext, Markierungen, Rettlingen, Entfernen u. a.; live im Einsatz und in der Auswertung, mit Gross-Modus (Spec, AG).
- **LO-02** – Einsatzstatistik in der Verwaltung mit Filter Alle / Scharfe Einsätze / Übungen (gemerkt), Kennzahlen je Art getrennt (AG).
- **LO-03** – Anonymisierte Anzeige von Log und Replay sowie endgültiges Anonymisieren beendeter Einsätze (getrennt für Replay und Log, nicht umkehrbar) (#54); erweitert zu Stufen (LO-07).
- **LO-04** – «Meine Einsätze»: Teilnehmende sehen Log und Replay ihrer beendeten Einsätze, je nach Einstellung aus, anonym (Standard) oder vollständig (#61).
- **LO-05** – Statistik pro Person: Filter nach Art, Zeitraum (gesamt, Kalenderjahr, von–bis) und Einsatz; je Person Anzahl, Einsatzzeit, Reaktionszeit, Suchgebiet erreicht, Fahrten mit Kilometern, Weg laut Aufzeichnung, Einrückoptionen; Grafiken pro Monat und je Person; CSV-Export; bleibt nach dem endgültigen Anonymisieren personenbezogen (#87).
- **LO-06** – Meine Statistik: Jede Einsatzkraft sieht unter «Meine Einsätze» die eigene Statistik (Einsätze, Übungen, Einsatzzeit, Reaktionszeit, Suchgebiet erreicht, Fahrten, Weg, Einrückoptionen, Einsätze pro Monat) mit Filter nach Art und Zeitraum, auch für endgültig anonymisierte Einsätze (#87).
- **LO-07** – Anonymisierung in Stufen, einzeln in der Verwaltung einstellbar (angeboten ja/nein, automatisch nach N Tagen getrennt für scharfe Einsätze und Übungen): Anzeige anonymisieren (umkehrbar), Replay pseudonymisieren, Einsatzprotokoll pseudonymisieren, Personenangaben und Freitexte entfernen, Teilnahmen vom Konto lösen (danach auch in der Statistik pro Person und in «Meine Einsätze» nicht mehr zugeordnet), Aufzeichnung löschen (endgültig). Im Dialog je Einsatz sind erledigte Stufen angehakt und gesperrt. Das After Action Review bleibt unverändert (#87).
- **LO-08** – Massenanonymisierung: beendete Einsätze nach Art und Zeitraum filtern, mehrere oder alle wählen und gewählte Stufen auf einmal anwenden; anonymisierte Anzeige mehrerer Einsätze aufheben (#87).
- **LO-09** – Automatische Anonymisierung: fällige Stufen werden ohne Cron bei Aufrufen der App angewendet und im Einsatzlog vermerkt (#87).
- **LO-11** – Vor der ersten Stufe der Anonymisierung (von Hand, als Masse oder automatisch) wird ein freigegebenes After Action Review vollständig als PDF gesichert. Das PDF bleibt unverändert erhalten und ist nur mit dem eigenen Recht «Gesicherte After Action Reviews abrufen» zugänglich. Ab der Sicherung ist das Review nur noch als dieses PDF verfügbar: Ansehen, Herunterladen und Bearbeiten entfallen, auch in «Meine Einsätze» und nach dem Aufheben der anonymisierten Anzeige (#87).
- **LO-12** – Die Verwaltung stellt ein, welche Knöpfe Einsatzkräfte unter «Meine Einsätze» sehen: Log, Replay, After Action Review ansehen, als Word, als PDF (#87).
- **LO-13** – Ob das Einsatzlog im After Action Review steht, legt allein die Einrichtung fest; Ansicht und Herunterladen fragen nicht danach. Einsatzkräfte laden Word und PDF ohne Rückfrage herunter (#87).
- **LO-14** – Wer das Recht auf gesicherte After Action Reviews hat, kann das gesicherte PDF auch unter «Meine Einsätze» abrufen. Die Oberfläche zeigt es wie jedes andere PDF, ohne Unterscheidung (#87).
- **LO-16** – «Meine Einsätze» zeigt keine Stufen der Anonymisierung: Ein Einsatz ist für Einsatzkräfte entweder anonymisiert oder nicht, und ab der ersten Stufe (ausser «Aufzeichnung löschen») sind Log und Replay dort anonym. Ein gesichertes Review erscheint als freigegeben (#87).
- **LO-15** – Ist ein Einsatz anonymisiert (jede Stufe ausser «Aufzeichnung löschen»), ist bei nicht freigegebenem Review kein After Action Review mehr möglich: kein Erfassen, Bearbeiten, Ansehen oder Herunterladen. Wird nur die anonymisierte Anzeige aufgehoben, ist es wieder möglich (#87).
- **LO-10** – Vorgegebene Fristen der automatischen Anonymisierung (scharf/Übung, Tage nach Ende): Anzeige 90/30, Replay 180/90, Einsatzprotokoll 365/180, Freitexte und Teilnahmen 730/365, Aufzeichnung 365/180; gelten ohne eigene Einstellung und lassen sich wieder einsetzen (#87).
- Einsatzarchiv-Export (**offen**).

## 16. Aufzeichnung und Replay (AR)

- **AR-01** – Aufzeichnung beim Start wählbar, Vorauswahl je Art in der Verwaltung (Standard: nur Übungen) (#27).
- **AR-02** – Die Einsatzleitung startet, pausiert, setzt fort und beendet die Aufzeichnung jederzeit; der Zustand ist für alle sichtbar (AG).
- **AR-03** – Aufgezeichnet werden Standortmeldungen, Rettling-Standorte und Helikopter (AG, #73).
- **AR-04** – Replay in der Verwaltung: Karte mit Zeitregler, Abspielen 1–120×, Balken der aufgezeichneten Zeiträume, interpolierte Bewegungen mit wählbarer Spurlänge, Lage und Log zum Zeitpunkt, Zentrieren/Folgen, Fenster «Ansicht» mit Grösse der Positionen, Auto-Übersicht (AG, #53).
- **AR-05** – Aufzeichnung je Einsatz löschbar; Log und übrige Daten bleiben (AG).

## 17. Öffentliche Ansicht (OE)

- **OE-01** – Die Einsatzleitung schaltet eine öffentliche Ansicht ohne Login ein und aus; Ausschalten macht den Link ungültig (#18).
- **OE-02** – Gezeigt werden PLS, Fliessgeschwindigkeit, Suchgebiet, Einsatzkräfte mit Standort, Markierungen & Bereiche, Rettlinge, optional das Einsatzprotokoll; keine Profilbilder, keine Benutzer-IDs (#18, #54).
- **OE-03** – Standardmässig anonym (Spezialrolle bzw. Einrückoption statt Namen) (#18).
- **OE-04** – Fenster «Ansicht», Zentrieren/Folgen und Auto-Übersicht wie in der Einsatz-App (#48, #51).
- **OE-05** – Nach dem Einsatzende zeigt der Link nur «beendet»; bei vorbereiteten Übungen vor dem Start eine Warteseite (#18, #29).

## 18. Vorbereitete Übungen (UE)

- **UE-01** – Übungen in der Verwaltung vorbereiten: Titel, Aufgebotsmeldung, Übungsleitung, aufgebotene Personen, Aufzeichnung, vorbereiteter öffentlicher Link (#29).
- **UE-02** – Elemente: PLS, neuer Rettling, Sichtung, neue externe Einsatzkraft, Entfernen einer externen Einsatzkraft; je mit Standort, PLS-Zeitpunkt und optionaler Meldung; Vorschau auf der Karte (#29).
- **UE-03** – Freigabe von Hand oder automatisch nach Minuten ab Start; erst dann sichtbar; Meldung geht als Nachricht an alle (#29).
- **UE-04** – Die Übungsleitung hat die Rechte der Einsatzleitung, wird nicht alarmiert und kann zusätzlich teilnehmen (#29).
- **UE-05** – Nur Aufgebotene (Einsatzgruppen und Einzelpersonen) werden alarmiert; teilnehmen dürfen alle (#29, #90).
- **UE-06** – Gestartete Übungen sind gesperrt, können kopiert und nach dem Ende zurückgesetzt werden; alle Durchführungen mit Log und Replay (#29).

## 19. PWA, Push und Offline (PW)

- **PW-01** – Installierbar als App (Manifest, Service Worker, Symbole für alle Systeme) (#67).
- **PW-02** – Web-Push für Alarme, Nachrichten an einen selbst und das Einsatzende, auch bei geschlossener App; ein/aus pro Gerät; Hinweis, solange Push aus ist (#67).
- **PW-03** – Keine doppelten Benachrichtigungen zwischen App und Push (#67).
- **PW-04** – Offline: letzter Stand mit Hinweis auf die letzte Verbindung, Start auch ohne Verbindung, Neuladen bei Rückkehr der Verbindung (#67).
- **PW-05** – Updates gelten sofort (eigene Dateien zuerst übers Netz) (#67).
- Kritischer Alarm trotz «Nicht stören»/Lautlos (braucht native App) (**Grenze**).
- Push auf iPhone/iPad nur als installierte App ab iOS 16.4 (**Grenze**).

## 20. Verwaltung (VW)

- **VW-01** – Verwaltung ohne Karte, nach Kategorien gegliedert (Personen, Einsatz, Funktionen, Karte & Daten, Auswertung, Übungen), am Handy als Auswahlliste; immer nur ein Abschnitt sichtbar, direkt verlinkbar (#32).
- **VW-02** – Neue Einstellungen erscheinen als Unterpunkt einer Kategorie mit eigenem Recht (#32, #63).
- **VW-03** – Hinweise verschwinden von selbst; nur Rückfragen brauchen einen Klick (AG).
