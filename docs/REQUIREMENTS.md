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
- **RB-12** – Übersichtliche Ordnerstruktur im Repository: Web-App und API in einem eigenen Ordner, der dem Webroot auf dem Server entspricht (Adressen bleiben gleich), daneben Platz für künftige native Apps, Dokumentation und Skripte (#109).

## 2. Anmeldung und Konto (KO)

- **KO-01** – Anmeldung mit Benutzername und Passwort (Spec).
- **KO-02** – Jede Person kann ihr eigenes Passwort ändern (aktuelles Passwort nötig) (AG).
- **KO-03** – Administratoren setzen Passwörter zurück; die Person wird abgemeldet und muss beim nächsten Login ein eigenes Passwort setzen. Gilt auch für neue Benutzer und das erste Admin-Konto (AG).
- **KO-04** – Persönliche Einstellungen auf einer eigenen Seite «Profil»: Profilbild, Passwort, Abmelden, Einsatzfähigkeit/Abwesenheiten, Übungsmodus, Push, App installieren, Koordinatensystem, Navigations-App, AI-Assistent (#58, #91, #111).
- **KO-05** – Abmelden löscht den auf dem Gerät gemerkten Stand (#67).
- **KO-06** – Das Profil ist in Karten gegliedert (Konto, Alarmierung, Anzeige, AI-Assistent, Rechtliches), je Thema mit eigenem Titel; Knöpfe stehen als gleich breite Spalten. Unter «Alarmierung» stehen der Stand der Einsatzfähigkeit und die eigenen Abwesenheiten mit Erfassen, Ändern und Beenden (#126).
- **KO-07** – Abmelden meldet auch auf dem Server ab: Der Anmeldeschlüssel des Browsers bzw. die Sitzung der App ist danach ungültig (#108).
- **KO-08** – Die nativen Apps erhalten je Gerät eine eigene Anmeldung: Eine Anmeldung in der App meldet weder den Browser noch andere Geräte ab und umgekehrt. Im Profil stehen die angemeldeten Apps, entfernen meldet sie ab. Passwort zurücksetzen und Deaktivieren melden auch alle Apps ab, das eigene Passwort ändern nicht (#108).
- Zugriffsschutz bzw. Rate-Limit für den Login (**offen**).

## 3. Benutzer und Berechtigungen (BE)

- **BE-01** – Frei definierbare Benutzerrollen mit einer Auswahl an Rechten; eine Person kann mehrere Rollen haben und erhält alle Rechte (#63).
- **BE-02** – Rechte: Einsätze starten, Übungen starten, jeden Einsatz leiten, Übungen vorbereiten, Benutzer verwalten, Benutzerrollen verwalten, Einstellungen je Kategorie (Einsatz, Funktionen, Karte & Daten), Statistik/Log/Replay aller Einsätze, Anonymisieren und Aufzeichnungen löschen, Einsicht für Einsatzkräfte, Einsatzgruppen verwalten, Abwesenheiten verwalten (#63, #90, #91).
- **BE-03** – Die Rolle Systemadministrator hat immer alle Rechte, auch künftige, und ist weder änderbar noch entfernbar; mindestens eine aktive Person behält sie (#63).
- **BE-04** – Niemand kann Rechte vergeben oder Personen bearbeiten, deren Rechte über die eigenen hinausgehen (#63).
- **BE-05** – Eine Rolle kann bei neuen Benutzern vorausgewählt sein (#63).
- **BE-06** – Benutzer entfernen = deaktivieren: abgemeldet, aus dem laufenden Einsatz ausgetragen, Daten bleiben, reaktivierbar (AG).
- **BE-07** – Löschen nur für Konten, die sich nie angemeldet haben und in keinen Einsatz- oder Übungsdaten vorkommen (AG).
- **BE-08** – Die Verwaltung zeigt jeder Person nur die Bereiche, für die sie ein Recht hat (#63).
- **BE-09** – Die Benutzerkarten in der Verwaltung zeigen Status, letzte Anmeldung und Passwort (gesetzt bzw. Wechsel ausstehend), nicht den Übungsmodus, immer in denselben Spalten, egal wie viele Rollen und Gruppen jemand hat; die Benutzerrollen stehen je als Chip auf einer Zeile, die Einsatzgruppen darunter; links vom Namen das Profilbild bzw. ein Platzhalter (#112, #127).
- **BE-10** – Die Benutzerliste ist nach aktiven und deaktivierten Personen getrennt (deaktivierte eingeklappt) und ab sechs Personen durchsuchbar (Name, Benutzername, Rolle, Gruppe) (#127).

## 4. Alarmierung und Einsatz (EI)

- **EI-01** – Einsatz bzw. Übung starten mit Alarmmeldung; getrennte Knöpfe «Einsatz starten» und «Übung starten», je nach Recht (Spec, #64).
- **EI-02** – Der Zeitpunkt des Alarmeingangs kann beim Start übersteuert werden (AG).
- **EI-03** – Die Alarmstufe stellt man je Einsatztyp ein: Kritisch (rote Benachrichtigung, bleibt bis zum Antippen, dreifacher Ton, Vibration, Push bleibt stehen), Hoch (rote Benachrichtigung, bleibt bis zum Antippen, einmaliger Ton, Push bleibt stehen), Normal (Hinweis, kurzer Ton), Leise (Hinweis ohne Ton), Keine (nur in der App). Die Web-App zeigt keinen Vollbild-Alarm mehr (Feedback zu #108); Vollbild und Weckton gibt es nur in der nativen App (NT-03). Je Typ einstellbar, ob Personen mit Übungsmodus kritisch alarmiert werden. Angelegt: Einsatz kritisch, Übung normal mit Übungsmodus (Spec, AG, #90).
- **EI-04** – Annehmen mit einer Einrückoption (z. B. Basis, Anfahrt direkt, Logistik, Koordinator KP Rück); die Optionen sind in der Verwaltung konfigurierbar, werden deaktiviert statt gelöscht, mindestens eine bleibt aktiv (Spec, AG).
- **EI-05** – Je Einrückoption einstellbar: Standort teilen, Leitungsrechte, Zählung im Zähler der Einsatzkräfte (AG, #30).
- **EI-06** – Ablehnen gibt es nicht mehr; ältere Ablehnungen bleiben in Log und Statistik (AG).
- **EI-07** – Austreten und Wiedereinstieg sind möglich (Spec).
- **EI-08** – Quittierte Alarme bleiben auf dem Gerät quittiert (AG).
- **EI-09** – Spezialrollen (z. B. Einsatzleitung, Sicherungsposten, Beobachtungsposten) in der Verwaltung definierbar, mit Reihenfolge, Farbe, Flag «Posten» (begrenzt das Suchgebiet) und Recht «Nachrichten senden» (Spec, AG).
- **EI-10** – Einsatzleitung = Rolle Einsatzleitung, Einrückoption mit Leitungsrechten, Recht «Jeden Einsatz leiten» oder Ersteller des Einsatzes, jeweils nur, solange die Person teilnimmt (Ausnahme Übungsleitung) (AG, #82).
- **EI-11** – Die Einsatzleitung kann den Einsatz beenden, Einsatzkräfte entfernen und deren Spezialrollen ändern (AG).
- **EI-12** – Einsatztyp überall sichtbar und unterscheidbar, in seiner Farbe; angelegt «Einsatz» rot, «Übung» blau (AG, #90).
- **EI-13** – Einsatzgruppen in der Verwaltung: Name, Farbe, Mitglieder; deaktivieren oder löschen (bisherige Einsätze behalten den Namen); neue Gruppen erhalten eine noch nicht verwendete Farbe; Mitglieder aus einer filterbaren Liste aller Personen; jede Person sieht ihre Gruppen im Profil (#90). Die Liste trennt aktive und deaktivierte Gruppen; je Gruppe stehen die Mitglieder über die ganze Breite, Abwesende markiert, deaktivierte Konten getrennt (#127).
- **EI-14** – Beim Start werden Einsatzgruppen aufgeboten; nur ihre Mitglieder werden alarmiert. Bei Einsatztypen, die das erlauben, zusätzlich einzelne Personen. Starten und aufgeboten werden (auch als Einzelperson, über «Alle aufbietbaren Personen» und in vorbereiteten Übungen) kann nur, wer einer beim Einsatztyp aufbietbaren Einsatzgruppe angehört; das gilt auch für den Systemadministrator. Die Übungsleitung startet ihre vorbereitete Übung ohne diese Bedingung. Ohne Einsatzgruppen werden alle alarmiert (#90).
- **EI-15** – Welche Einsatzgruppen eine Person aufbieten darf, legen ihre Benutzerrollen fest; «Alle aufbietbaren Personen» nur, wer alle Gruppen aufbieten darf (#90).
- **EI-16** – Einsatztypen sind frei konfigurierbar und erweiterbar (Name, Farbe, Reihenfolge, aktiv); angelegt «Einsatz» und «Übung» mit den bisherigen Einstellungen. Je Typ einstellbar: Alarmstufe, Startrecht je Benutzerrolle, aufbietbare und vorausgewählte Einsatzgruppen, Einzelpersonen, Sichtbarkeit für Nicht-Aufgebotene, vorbereitete Übungen, Funktionen, Vorauswahl von Aufzeichnung und Helikopter, Fristen der Anonymisierung. Ein neuer Typ übernimmt die Einstellungen von einem bestehenden. Löschen ist möglich, bisherige Einsätze behalten Name, Farbe und Fristen; mindestens ein Typ bleibt aktiv (#90).
- **EI-17** – Nicht Aufgebotene sehen einen Einsatz nicht und können nicht beitreten. Ausnahmen: Beim Einsatztyp ist «für alle zugelassenen Gruppen sichtbar» eingestellt und die Person gehört einer dort aufbietbaren Einsatzgruppe an, oder die Einsatzleitung erlaubt im Einsatz allen den Beitritt (beides ohne Alarm). Die Übungsleitung sieht ihre Übung immer (#90).
- **EI-18** – Die Einsatzleitung kann weitere Gruppen (bei Typen, die das erlauben, auch Personen) oder alle nachbieten; alarmiert werden nur die neu Aufgebotenen (#90).
- **EI-19** – Jede Person kann sich selbst abwesend melden oder ihre Einsatzfähigkeit vorübergehend aussetzen (von–bis oder bis auf Weiteres, mit Bemerkung); beides wirkt gleich: kein Alarm und kein Aufgebot, laufende Einsätze bleiben sichtbar und ein freiwilliger Beitritt ist möglich (#91).
- **EI-20** – Personen mit dem Recht «Abwesenheiten verwalten» erfassen, ändern und beenden Abwesenheiten aller Personen in der Verwaltung (#91).
- **EI-21** – Abwesenheiten sind bei der Zuteilung der Einsatzgruppen und beim Aufbieten ersichtlich (je Person und Anzahl je Gruppe) (#91).
- **EI-22** – entfallen: Die Vorauswahl der Einsatzgruppen steht beim Einsatztyp (EI-16) (#90).
- **EI-23** – Einzelpersonen fürs Aufgebot werden aus einer filterbaren Liste aller Personen gewählt, ohne Texteingabe (#90).
- **EI-24** – In der Verwaltung steht beim Namen einer Person ein Farbpunkt je Einsatzgruppe (Benutzer, Einsatzgruppen, Personenauswahl, Abwesenheiten, Statistik pro Person, Übungen, Replay ausser anonym). Ob eine Gruppe dafür zählt, stellt man an der Gruppe ein («Farbe beim Namen anzeigen», Standard an); das Infofenster der Einsatz-App (EK-09) zeigt immer alle Gruppen (#105).

## 5. Karte (KA)

- **KA-01** – Karte im Vollbild; alle Bedienelemente in einem versteckten Menü (☰) (AG).
- **KA-02** – Grundkarte swisstopo Luftbild (Standard), umschaltbar auf Landeskarte und OpenStreetMap; Anbieter leicht austauschbar; Wahl pro Gerät gemerkt (AG, #34).
- **KA-03** – Fenster «Ansicht»: Grundkarte und je Kategorie ein Häkchen (Einsatzkräfte, Externe, Rettlinge, PLS, Suchgebiet, Markierungen, Bereiche, Nachrichten-Markierungen, Helikopter, Feste Standorte, Messstationen), «Alle ein-/ausblenden», pro Gerät gemerkt (#34); ohne Einsatz feste Standorte, Helikopter und eigene Messstationen (#96, #125, #130).
- **KA-04** – Karte drehbar (zwei Finger, Shift + Mausrad, Kompass); Klick auf den Kompass richtet nach Norden aus (AG).
- **KA-05** – Startansicht beim Öffnen eines Einsatzes: Übersicht über alle aktiven Positionen (AG).
- **KA-06** – Kamerafokus: Suchgebiet (ganz und je Teilgebiet), Einsatzkräfte, Auto-Übersicht (hält alle sichtbaren aktiven Positionen im Bild) (AG, #51).
- **KA-07** – Kartenknöpfe rechts unten einklappbar; eingeklappt zeigen sie die Summe der roten Zähler (#42).
- **KA-08** – Die Seite selbst scrollt und zoomt nie, nur die Karte (#84).
- **KA-09** – Am Handy decken Seitenfenster die Kartenknöpfe ab (AG).
- **KA-10** – Startansicht beim Öffnen der App ohne laufenden Einsatz: eigener Standort und alle eingeblendeten festen Standorte im Bild; trifft der eigene Standort erst später ein, wird die Ansicht in den ersten 20 s nachgeführt, solange man die Karte nicht selbst verschoben oder gezoomt hat (#96).
- **KA-11** – Das Menü (☰) zeigt im Einsatz oben den Einsatz mit Alarmzeit, Alarmstufe, PLS, Fliessgeschwindigkeit, Aufzeichnung und öffentlicher Ansicht, darunter Register: «Einsatz» (AI-Assistent, Teilnahme bzw. Mein Einsatz, Fliessgeschwindigkeit, Werkzeuge), «Leitung» (nur Einsatzleitung: Aufgebot, PLS, Aufzeichnung, Helikopter, öffentliche Ansicht, Einsatzkräfte, Einsatz beenden), «Übung» (Übungsleitung, mit Anzahl offener Elemente), «Log» und «Info» (eigene Rechte). Jedes Thema steht in einer eigenen Karte. Beim Öffnen gilt «Einsatz», für die Übungsleitung «Übung» (#129).
- **KA-12** – Wird eine Benachrichtigung zum laufenden Einsatz geöffnet (Alarm, Nachricht, Entfernen; als Systembenachrichtigung oder in der App), zeigt die Karte das Einsatzgebiet: Suchgebiet mit den PLS, ohne Einsatzkräfte (solange das Suchgebiet noch berechnet wird, die PLS). Nach dem Annehmen mit einer Einrückoption kehrt sie ebenso aufs Einsatzgebiet zurück. Verschieben von Hand beendet es. Web-App und native App (Feedback zu #108).

## 6. Einsatzkräfte und Standort (EK)

- **EK-01** – Teilnehmende teilen ihren Standort (sofern die Einrückoption es vorsieht) und können ihn manuell setzen (Spec).
- **EK-02** – Fenster «Einsatzkräfte»: Liste nach Spezialrolle gruppiert (Reihenfolge wie in der Verwaltung, ohne Rolle zuletzt), Gruppen «mit App» und «Extern» einklappbar, Zähler am Knopf (#30, #39).
- **EK-03** – «Zentrieren» und «Folgen» je Person; Folgen endet über ✕, beim Verschieben von Hand und beim Austreten (AG).
- **EK-04** – «Mein Standort» unten im Menü immer in LV95 und WGS84 Grad/Min/Sek (#76).
- **EK-05** – Fahrten mit Fahrzeug werden erkannt und im Log festgehalten («fährt von A nach B, ca. X km Luftlinie, Ø Y km/h»); Bewegungen auf dem Gewässer und manuell gesetzte Positionen zählen nicht (AG).
- **EK-06** – «Suchgebiet erreicht» wird geloggt (AG).
- **EK-07** – Der eigene Standort ist immer gesondert auf der Karte sichtbar (blauer Punkt mit Genauigkeitskreis), auch ohne laufenden Einsatz; ausgeblendet nur, solange man an einem laufenden Einsatz bzw. einer Übung mit einer Einrückoption ohne Standortfreigabe teilnimmt (#118).
- **EK-08** – Im Einsatz erscheint man selbst nur als blauer Punkt, nicht zusätzlich mit dem eigenen Personen-Icon; ohne Standort des Geräts steht der Punkt am zuletzt gemeldeten Standort (#96).
- **EK-09** – Personeninfo: Antippen einer Einsatzkraft (auch Externe) auf der Karte öffnet ein Infofenster mit Profilbild, Name, Spezialrolle, Einrückoption, allen Einsatzgruppen, Standort, «Folgen» und Navigation; ebenso «Info» im Fenster «Einsatzkräfte». Es blockiert nichts, fährt mit der Position der Person mit und schliesst über ✕, Antippen der leeren Karte oder wenn die Person den Einsatz verlässt. Als Funktion je Einsatztyp ausschaltbar; ausgeschaltet fragt das Antippen wie bisher nach der Navigation (#105).

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
- **SG-13** – Hauptquelle für das Gewässernetz ist eine eigene PostGIS-Datenbank mit dem OSM-Auszug der Schweiz (Geofabrik), regelmässig aktualisiert; Overpass bleibt Rückfall, wenn sie nicht antwortet (#131).
- **SG-14** – PostGIS in der Verwaltung einstellbar (Verbindung, Tabelle, Verschlüsselung, Passwort nie sichtbar), mit Verbindungstest und eigener Zeile in der Statistik; Skripte für Einrichtung und Aktualisierung liegen bei (#131).
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
- **MB-02** – Fenster «Markierungen»: PLS zuoberst, danach feste Standorte, Markierungen und Bereiche, je Eintrag und Gruppe ein-/ausblendbar, «Zeigen» (AG, #96).
- **MB-03** – Feste Standorte: in der Verwaltung gepflegte Orte (Name, Beschreibung, Kürzel, Farbe, Standort, aktiv/deaktiviert), z. B. Bootseinwasserungen, Magazine oder Treffpunkte; erscheinen in jedem Einsatz und jeder Übung (und ohne Einsatz) auf der Karte und im Fenster «Markierungen», mit Navigation (#96).
- **MB-04** – Vorbereitete Übungen wählen, welche festen Standorte gelten (alle aktiven oder eine Auswahl), und können bis zum Start eigene Standorte ergänzen; sichtbar in der Vorschau auf der Karte (#96).
- **MB-05** – Feste Standorte stehen auch ohne laufenden Einsatz auf der Karte der Einsatz-App (alle aktiven permanenten, ausblendbar im Fenster «Ansicht»). Ohne Einsatz öffnet der Kartenknopf «Feste Standorte» eine Übersichtsliste wie im Fenster «Markierungen» (nach Kategorie, je Standort ein-/ausblendbar, «Zeigen», Navigation) (#96).
- **MB-06** – Auswahl- und Vorschaukarten der Verwaltung sind gross genug zum Wählen und bieten alle Kartenanbieter zur Wahl (wie das Fenster «Ansicht») (#96).
- **MB-07** – Kategorien fester Standorte (z. B. Bootseinwasserungen, Magazine): in der Verwaltung angelegt, je Standort zugeordnet, je Kategorie mit Standard «eingeblendet» oder «ausgeblendet». In der Einsatz-App (Fenster «Ansicht» und «Markierungen») einzeln ein- und ausblendbar; die eigene Wahl wird pro Person gespeichert (auf allen Geräten) und lässt sich auf den Standard zurücksetzen (#96).
- **MB-10** – Namen fester Standorte: Im Fenster «Ansicht» lässt sich unter «Feste Standorte» einstellen, ob die Namen auf der Karte stehen («Namen auf der Karte», Standard an). Ausgeschaltet erscheint der Name nur beim Darüberfahren und im Popup. Gespeichert pro Person, «Standard der Verwaltung» lässt die Wahl stehen (#96).
- **MB-09** – Reihenfolge fester Standorte: In der Verwaltung lassen sich die Kategorien und innerhalb jeder Kategorie die Standorte mit ↑/↓ sortieren; die Einsatz-App zeigt sie in dieser Reihenfolge (#96).
- **MB-08** – Feste Bereiche: Ein fester Standort kann statt eines Punkts eine Fläche sein (3–200 Eckpunkte, in der Verwaltung auf der Karte eingezeichnet), z. B. Sperrzonen oder Sammelplätze. In der Einsatz-App gestrichelt in seiner Farbe mit Beschriftung, Navigation zur Mitte, «Zeigen» zeigt die ganze Fläche; gilt auch für Kategorien, vorbereitete Übungen und die Startansicht. Bereiche haben kein Kürzel (#96).
- PLS auf eine Markierung verschieben (**offen**).

## 12. Werkzeuge (WZ)

- **WZ-01** – Flussdistanz: zwei Punkte antippen, Distanz entlang Gewässer und Luftlinie, Richtung, Koordinaten, Treibzeit und Dauer für Vergleichsgeschwindigkeiten (in der Verwaltung einstellbar); auch ohne Einsatz; höchstens 50 km Luftlinie (AG).
- **WZ-02** – Navigation: Knopf 🧭 bei allen Positionen öffnet die Navigations-App (Google Maps, Apple Karten, Waze, andere App); Apps in der Verwaltung ausschaltbar mit Standard, pro Person wählbar; versehentliches Antippen von Markern fragt nach (#79).
- **WZ-03** – Koordinaten in allen Listen im gewählten System (LV95 Standard, LV03, WGS84 dezimal, WGS84 Grad/Min/Sek, ausblenden); Standard in der Verwaltung, pro Person übersteuerbar (AG).

## 13. Helikopter (HE)

- **HE-01** – Luftfahrzeuge aus offenen ADS-B-Daten auf der Karte, über den Server abgefragt (#73).
- **HE-02** – Hauptschalter, Ein/Aus je Einsatz mit Vorauswahl je Art; die Einsatzleitung schaltet im Einsatz um (#73).
- **HE-03** – Konfigurierbar: Quellen (nur gratis und ohne Schlüssel), Filter (Rettungshelikopter, Helikopter, alle), Sichtbarkeit in App, öffentlicher Ansicht und Replay, Umkreis, Flughöhe, Alter, Spur, Abfrageintervall, Liste der Rettungshelikopter (#73).
- **HE-04** – Abfrage nur in der Relevanzzone um PLS, Einsatzkräfte, Rettlinge, Markierungen und Bereiche (#73); weit auseinanderliegende Gebiete werden je einzeln abgefragt, damit alle abgedeckt sind (#107).
- **HE-05** – Auch ohne Einsatz: Helikopter im einstellbaren Umkreis um die aktiven permanenten festen Standorte, für alle Angemeldeten; kein Umkreis (0) schaltet das aus (#130).
- Tief fliegende Helikopter in Tälern fehlen oft (keine Empfänger in Sicht) (**Grenze**).

## 14. Funktionen ein-/ausschalten (FU)

- **FU-01** – In der Verwaltung je Einsatztyp ein- und ausschaltbar: Suchgebiet, Rettlinge, Nachrichten, Markierungen & Bereiche, Externe Einsatzkräfte, Flussdistanz, öffentliche Ansicht, Aufzeichnung, Navigation, Feste Standorte, Pegel & Abfluss, Personeninfo (#35, #79, #96, #125, #105).
- **FU-02** – Gilt sofort, auch im laufenden Einsatz; der Server liefert keine Daten ausgeschalteter Funktionen und lehnt deren Aktionen ab; Daten bleiben erhalten (#35).
- **FU-03** – Neue Funktionen sind standardmässig eingeschaltet (#35).

## 15. Einsatzlog und Auswertung (LO)

- **LO-01** – Einsatzlog mit Beitritt, Rollen, Fahrten, Suchgebiet erreicht, Nachrichten im Volltext, Markierungen, Rettlingen, Entfernen u. a.; live im Einsatz und in der Auswertung, mit Gross-Modus (Spec, AG).
- **LO-02** – Einsatzstatistik in der Verwaltung mit Filter nach Einsatztyp (gemerkt), Kennzahlen je Typ getrennt (AG, #90).
- **LO-03** – Anonymisierte Anzeige von Log und Replay sowie endgültiges Anonymisieren beendeter Einsätze (getrennt für Replay und Log, nicht umkehrbar) (#54); erweitert zu Stufen (LO-07).
- **LO-04** – «Meine Einsätze»: Teilnehmende sehen Log und Replay ihrer beendeten Einsätze, je nach Einstellung aus, anonym (Standard) oder vollständig (#61).
- **LO-05** – Statistik pro Person: Filter nach Art, Zeitraum (gesamt, Kalenderjahr, von–bis) und Einsatz; je Person Anzahl, Einsatzzeit, Reaktionszeit, Suchgebiet erreicht, Fahrten mit Kilometern, Weg laut Aufzeichnung, Einrückoptionen; Grafiken pro Monat und je Person; CSV-Export; bleibt nach dem endgültigen Anonymisieren personenbezogen (#87).
- **LO-06** – Meine Statistik: Jede Einsatzkraft sieht unter «Meine Einsätze» die eigene Statistik (Einsätze, Übungen, Einsatzzeit, Reaktionszeit, Suchgebiet erreicht, Fahrten, Weg, Einrückoptionen, Einsätze pro Monat) mit Filter nach Art und Zeitraum, auch für endgültig anonymisierte Einsätze (#87).
- **LO-07** – Anonymisierung in Stufen, einzeln in der Verwaltung einstellbar (angeboten ja/nein, automatisch nach N Tagen je Einsatztyp): Anzeige anonymisieren (umkehrbar), Replay pseudonymisieren, Einsatzprotokoll pseudonymisieren, Personenangaben und Freitexte entfernen, Teilnahmen vom Konto lösen (danach auch in der Statistik pro Person und in «Meine Einsätze» nicht mehr zugeordnet), Aufzeichnung löschen (endgültig). Im Dialog je Einsatz sind erledigte Stufen angehakt und gesperrt. Das After Action Review bleibt unverändert (#87).
- **LO-08** – Massenanonymisierung: beendete Einsätze nach Art und Zeitraum filtern, mehrere oder alle wählen und gewählte Stufen auf einmal anwenden; anonymisierte Anzeige mehrerer Einsätze aufheben (#87).
- **LO-09** – Automatische Anonymisierung: fällige Stufen werden ohne Cron bei Aufrufen der App angewendet und im Einsatzlog vermerkt (#87).
- **LO-11** – Vor der ersten Stufe der Anonymisierung (von Hand, als Masse oder automatisch) wird ein freigegebenes After Action Review vollständig als PDF gesichert. Das PDF bleibt unverändert erhalten und ist nur mit dem eigenen Recht «Gesicherte After Action Reviews abrufen» zugänglich. Ab der Sicherung ist das Review nur noch als dieses PDF verfügbar: Ansehen, Herunterladen und Bearbeiten entfallen, auch in «Meine Einsätze» und nach dem Aufheben der anonymisierten Anzeige (#87).
- **LO-12** – Die Verwaltung stellt ein, welche Knöpfe Einsatzkräfte unter «Meine Einsätze» sehen: Log, Replay, After Action Review ansehen, als Word, als PDF (#87).
- **LO-13** – Ob das Einsatzlog im After Action Review steht, legt allein die Einrichtung fest; Ansicht und Herunterladen fragen nicht danach. Einsatzkräfte laden Word und PDF ohne Rückfrage herunter (#87).
- **LO-14** – Wer das Recht auf gesicherte After Action Reviews hat, kann das gesicherte PDF auch unter «Meine Einsätze» abrufen. Die Oberfläche zeigt es wie jedes andere PDF, ohne Unterscheidung (#87).
- **LO-16** – «Meine Einsätze» zeigt keine Stufen der Anonymisierung: Ein Einsatz ist für Einsatzkräfte entweder anonymisiert oder nicht, und ab der ersten Stufe (ausser «Aufzeichnung löschen») sind Log und Replay dort anonym. Ein gesichertes Review erscheint als freigegeben (#87).
- **LO-15** – Ist ein Einsatz anonymisiert (jede Stufe ausser «Aufzeichnung löschen»), ist bei nicht freigegebenem Review kein After Action Review mehr möglich: kein Erfassen, Bearbeiten, Ansehen oder Herunterladen. Wird nur die anonymisierte Anzeige aufgehoben, ist es wieder möglich (#87).
- **LO-10** – Vorgegebene Fristen der automatischen Anonymisierung (Einsatz/Übung, Tage nach Ende): Anzeige 90/30, Replay 180/90, Einsatzprotokoll 365/180, Freitexte und Teilnahmen 730/365, Aufzeichnung 365/180; gelten ohne eigene Einstellung und lassen sich wieder einsetzen (#87).
- Einsatzarchiv-Export (**offen**).

## 16. Aufzeichnung und Replay (AR)

- **AR-01** – Aufzeichnung beim Start wählbar, Vorauswahl je Einsatztyp im Dialog des Einsatztyps (Standard: nur Übungen) (#27, #136).
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
- **UE-05** – Nur Aufgebotene (Einsatzgruppen und Einzelpersonen) werden alarmiert; teilnehmen dürfen alle gemäss EI-17 (#29, #90).
- **UE-06** – Gestartete Übungen sind gesperrt, können kopiert und nach dem Ende zurückgesetzt werden; alle Durchführungen mit Log und Replay (#29).

## 19. PWA, Push und Offline (PW)

- **PW-01** – Installierbar als App (Manifest, Service Worker, Symbole für alle Systeme) (#67).
- **PW-02** – Web-Push für Alarme, Nachrichten an einen selbst und das Einsatzende, auch bei geschlossener App; ein/aus pro Gerät; Hinweis, solange Push aus ist (#67).
- **PW-03** – Keine doppelten Benachrichtigungen zwischen App und Push (#67).
- **PW-04** – Offline: letzter Stand mit Hinweis auf die letzte Verbindung, Start auch ohne Verbindung, Neuladen bei Rückkehr der Verbindung (#67).
- **PW-05** – Updates gelten sofort (eigene Dateien zuerst übers Netz) (#67).
- Kritischer Alarm trotz «Nicht stören»/Lautlos (braucht native App) (**Grenze**).
- Push auf iPhone/iPad nur als installierte App ab iOS 16.4 (**Grenze**).
- **PW-04** – In der nativen App zeigt die Web-App keinen Web-Push und kein «App installieren»; Benachrichtigungen kommen über die App (#108).

## 20. Verwaltung (VW)

- **VW-01** – Verwaltung ohne Karte, nach Kategorien gegliedert (Personen, Einsatz, Funktionen, Karte & Daten, Auswertung, Übungen, Rechtliches, System), am Handy als Auswahlliste; immer nur ein Abschnitt sichtbar, direkt verlinkbar (#32).
- **VW-02** – Neue Einstellungen erscheinen als Unterpunkt einer Kategorie mit eigenem Recht (#32, #63).
- **VW-03** – Hinweise verschwinden von selbst; nur Rückfragen brauchen einen Klick (AG).
- **VW-04** – Navigation der Verwaltung: Kategorien mit Symbol und Anzahl einklappbar, die Kategorie des geöffneten Bereichs ist aufgeklappt, von Hand aufgeklappte pro Gerät gemerkt; Kategorien mit nur einem Bereich als direkter Link; Suchfeld über der Navigation (Enter öffnet den ersten Treffer); die Navigation scrollt bei Bedarf selbst (#128).
- **VW-05** – Passwort ändern, Abmelden und Impressum stehen in der Kopfzeile der Verwaltung in einem Konto-Menü mit dem Namen der Person (#128).
- **VW-06** – Technisches Applikationslog in der Verwaltung (Kategorie «System», eigenes Recht): Ereignisse (Anmeldungen, Einsätze starten und beenden, geänderte Benutzer, Rollen, Gruppen und Einstellungen, automatische Anonymisierung), Warnungen (fehlgeschlagene Anmeldungen, Datenquellen ohne Antwort, abgelehnte Push-Nachrichten, fehlgeschlagene AI-Anfragen, PHP-Warnungen) und Fehler (Serverfehler, Abbrüche, Skriptfehler im Browser und in der App-WebView); filterbar nach Stufe, Bereich und Text, neueste zuerst, automatisch aktualisierbar, Export als CSV; ohne Passwörter, Schlüssel und Einsatzinhalte (#139).
- **VW-07** – Das Applikationslog wird nach einer einstellbaren Frist gelöscht (1–365 Tage, Standard 30); die Datenschutzerklärung nennt sie (#139).

## 21. AI-Assistent (AI)

- **AI-01** – Optionaler AI-Assistent über einen externen Anbieter (OpenAI-kompatibel inkl. Mistral, oder Anthropic) oder einen selbst gehosteten Server (z. B. Ollama, ohne Schlüssel, auch über http im eigenen Netz); ohne Anbieter und Freigabe gibt es ihn nicht. Ein Schlüssel bleibt auf dem Server (#111).
- **AI-02** – Funktionen: Entwürfe für leere Textfelder des After Action Review, Zusammenfassung des Einsatzlogs (Verwaltung und Meine Einsätze), Vorschläge zu vorbereiteten Übungen, Einsatzstatistik in Worten, Lagezusammenfassung im laufenden Einsatz (#111).
- **AI-03** – Feste Aufgaben, optional mit einem zusätzlichen Wunsch, dazu freie Fragen (siehe AI-08); Vorschläge erscheinen in einem Fenster und gelten erst mit «Übernehmen» bzw. werden kopiert (#111, geändert: früher ohne freie Eingabe).
- **AI-04** – Modi je Funktion: Aus, «Auf Abruf» (Knopf), «Begleitend» (Vorschlag beim Öffnen vorbereitet). Freigabe je Einsatztyp (bzw. Verwaltung ohne Einsatz), Funktion und Rolle: Benutzerrollen und Spezialrollen im Einsatz (nur mit Teilnahme), der höchste Modus gilt; jede Person kann im Profil weniger wählen. Standard: nur Systemadministratoren «Auf Abruf», Lagezusammenfassung bei Einsatztypen ohne vorbereitete Übungen aus (#111, AG).
- **AI-05** – Datenschutz je Einsatztyp einstellbar: Namen vor dem Senden durch Bezeichnungen ersetzen (Standard ein), Koordinaten runden (Standard ein); Beschreibungen von Rettlingen gehen nie mit; die Daten folgen den Rechten und der Anonymisierung der jeweiligen Ansicht (#111, AG).
- **AI-06** – Grenzen und Kontrolle: Wartezeit, Antwortlänge, Anfragen pro Tag gesamt und je Person, Verbindungstest und Verbrauchsübersicht in der Verwaltung (Recht «AI-Assistent einstellen») (#111).
- **AI-07** – Die Datenschutzerklärung führt den Anbieter des AI-Assistenten als Empfänger auf, solange der Assistent eingerichtet ist, mit dem Datenschutz je Einsatztyp (#111, #102).
- **AI-08** – Freie Fragen: Im Fenster jeder Funktion lassen sich eigene Fragen zu den Daten der Ansicht stellen (mit Verlauf im Fenster, nicht gespeichert); in der Einsatz-App zum laufenden Einsatz, in der Verwaltung auch ohne Einsatzdaten. Eigene Funktion in der Freigabe, nur «Auf Abruf»; Namen in den Fragen werden wie die Daten ersetzt (#111, AG).
- **AI-09** – Übungsvorbereitung: Der AI-Assistent schlägt neben Hinweisen konkrete Elemente vor (Art, Bezeichnung, Standort relativ zu bestehenden Elementen oder festen Standorten, Zeitpunkt, Meldung). Die Übungsleitung wählt sie einzeln an und erstellt sie mit einem Klick; Verweise zwischen den Vorschlägen (Sichtung eines neuen Rettlings, Abzug einer neuen externen Einsatzkraft) werden aufgelöst. Anforderungen an die Übung lassen sich zuoberst als Freitext eingeben; eine Vorschaukarte zeigt die bestehenden Elemente und Standorte zusammen mit den angewählten Vorschlägen. Standorte sind Näherungen und bleiben bearbeitbar (#111).

## 22. Rechtliches (RC)

- **RC-01** – Eine Seite «Impressum, Lizenzen & Datenschutz» ist ohne Anmeldung erreichbar, verlinkt bei der Anmeldung, im Profil der Einsatz-App, in der öffentlichen Ansicht und in der Verwaltung (#102).
- **RC-02** – Impressum mit den Angaben der betreibenden Organisation und den Verwendungseinschränkungen: kein Ersatz für Notrufnummern, Alarmierung ohne Gewähr, Standorte und Suchgebiet sind Schätzungen, Kartendaten und Luftfahrzeuge ohne Gewähr, nur nicht-kommerzielle Nutzung, keine Haftung (#102).
- **RC-03** – Lizenzen und Nutzungsbedingungen aller verwendeten Bibliotheken, Daten und Dienste mit Quellenangabe; Datenquellen gemäss den aktuellen Einstellungen (aktive Overpass-Server, PostGIS, Helikopter-Quellen, BAFU-Hydrodaten) (#102, #125, #131).
- **RC-04** – Datenschutzerklärung nach DSG: bearbeitete Daten, Speicherort, Aufbewahrung und Anonymisierungsfristen gemäss den aktuellen Einstellungen (je aktivem Einsatztyp), Empfänger, Weitergabe an Dritte gemäss eingeschalteten Funktionen, Speicher im Gerät, Rechte der betroffenen Personen (#102).
- **RC-05** – Die Angaben der Organisation (Name, Adresse, Kontakt, Verantwortliche, Datenschutzkontakt, Hosting, Zusatztexte) stellt die Verwaltung ein (Kategorie «Rechtliches», Recht «Impressum & Datenschutz einstellen») (#102).


## 23. Native Apps (NT)

- **NT-01** – Native Apps für Android und iOS (Flutter), die die Einsatz-App des gewählten Backends nativ zeigen (ohne WebView, siehe NT-10) und Alarme selbst melden (#108).
- **NT-02** – In der App lassen sich mehrere Backends (Server der Organisationen) hinzufügen, je mit eigener Anmeldung; auf jedem ist die App einzeln «aktiv» schaltbar, und auf allen aktiven gleichzeitig kommen Alarme, Nachrichten an einen selbst und das Einsatzende an. Die Kontenliste zeigt je Backend den Stand (kein Einsatz, Alarm, angenommen, Fehler, abgemeldet) (#108).
- **NT-03** – Alarmstufen wie in der Web-App (EI-03): kritisch mit Vollbild und Dauerton (Android als Wecker, auch lautlos und bei «Nicht stören», bis angetippt oder quittiert, höchstens 5 min), hoch mit Vollbild, normal, leise, keine. Was schon gemeldet ist, meldet weder die App noch die Web-App ein zweites Mal; ist die Einsatz-App eines Kontos offen, zeigt sie Alarme als Vollbild (kritisch mit Weckton über die Meldung der App), Quittieren schliesst die Meldung (#108).
- **NT-04** – Android: Die App fragt alle aktiven Backends in einem Vordergrunddienst im eingestellten Abstand ab (10–60 s), auch bei geschlossener App und nach einem Neustart («Bereit für Alarme») (#108).
- **NT-05** – Optional Push über Firebase Cloud Messaging (Android und iOS), sobald der Server ein Firebase-Dienstkonto hat und die App mit den Firebase-Angaben gebaut ist; ohne Push meldet iOS Alarme nur bei offener App (**Grenze** von iOS) (#108).
- **NT-06** – Anmeldeschlüssel nur im sicheren Speicher des Geräts; jedes Konto spricht nur mit seinem Backend, fremde Adressen (Navigation, Telefon, Mail) öffnen sich ausserhalb der App (#108).
- **NT-07** – Eine GitHub-Actions-Pipeline baut die fertigen Pakete (Android APK und AAB, iOS IPA), signiert, sofern die Schlüssel als Geheimnisse hinterlegt sind; ein Tag «mobile-v<Version>» erstellt ein Release (#108).
- **NT-08** – Standortfreigabe, eigener Standort und «Mein Standort» nutzen den Standort des Geräts, sobald die App ihn darf (#108).
- **NT-09** – Im Einsatz teilt die App den Standort auch bei gesperrtem Bildschirm oder geschlossener App, solange man teilnimmt und die Einrückoption den Standort teilt; dafür fragt sie nach «Immer zulassen» und zeigt den Stand in ihren Einstellungen. Im Hintergrund (ohne Gewässernetz) gilt dabei keine Position als «auf dem Gewässer» (**Grenze**) (#108).
- **NT-11** – Verwaltung, «Meine Einsätze» und Rechtliches öffnen sich in der nativen App in einer WebView, angemeldet mit dem Konto, ohne eigene Anmeldung. «Zur Einsatz-App» und Zurück führen zur Karte, fremde Adressen öffnen sich ausserhalb. Dateien (AAR als Word/PDF, CSV) bietet die App zum Teilen bzw. Speichern an. Abmelden dort meldet das Konto in der App ab; ein neuer Alarm schliesst die Seite (Feedback zu #108).
- **NT-10** – Die Einsatz-App ist in der nativen App ganz nativ gebaut, ohne WebView (Feedback zu #108): Karte mit denselben Anbietern, Ebenen und Kartenknöpfen, Seitenfenster Einsatzkräfte, Nachrichten, Rettlinge, Markierungen & Bereiche (mit festen Standorten) und Flussdistanz, Suchgebiet entlang des Gewässers, Fenster «Ansicht», Kamerafokus und Auto-Übersicht, ☰-Menü mit den Registern Einsatz/Leitung/Übung/Log/Info, Einsatz starten, vorbereitete Übungen und Profil, mit denselben Abläufen und Rechten wie die Web-App. Verwaltung, «Meine Einsätze» und Rechtliches öffnen sich in der App (siehe NT-11). Noch nicht nativ: Gross-Modus des Logs, freie Fragen an den AI-Assistenten und dessen Modus «Begleitend» (**Grenze**) (#108).
- Kritische Hinweise unter iOS (umgehen «Nicht stören» und Lautlos) verlangen eine Freigabe von Apple (**offen**).

## 24. Pegel und Abfluss (PA)

- **PA-01** – Abfluss, Pegel, Wassertemperatur und Gefahrenstufe der Messstationen des BAFU (offene Daten), über den Server mit Zwischenspeicher abgefragt; Quellenangabe «BAFU» (#125).
- **PA-02** – Zu Beginn eines Einsatzes (sobald ein PLS bekannt ist) und am Einsatzende werden die Werte der nächsten Stationen um jeden PLS automatisch ermittelt und im Einsatzlog festgehalten; am Ende dieselben Stationen wie zu Beginn (#125).
- **PA-03** – Die erfassten Werte sind danach sichtbar: im Einsatzlog (Verwaltung, «Meine Einsätze», After Action Review) und im Menü der Einsatz-App mit Beginn, Ende und aktuellem Wert (#125).
- **PA-04** – Jede Person definiert im Profil persönliche Messstationen (Suche nach Ort, Gewässer oder Nummer, Reihenfolge, höchstens 20); sie erscheinen als Liste im Menü und als Markierung auf der Karte, auch ohne Einsatz (#125).
- **PA-05** – In der Verwaltung einstellbar: Umkreis um jeden PLS, Anzahl Stationen je PLS, Seen ja/nein; Stand des Datenabrufs mit «Jetzt abrufen»; als Funktion je Einsatztyp ein- und ausschaltbar (#125).
- **PA-06** – Je Station die Gefahrenstufe (1–5, aus den Schwellen des BAFU) und die Einordnung (sehr tief … sehr hoch); Marker auf der Karte ab Stufe 2 eingefärbt, Link zur Stationsseite des BAFU (Feedback zu #125).
- **PA-07** – Zwei Datenquellen, je in der Verwaltung ein- und ausschaltbar, mit Lizenz, Hinweis, Stand und Test: die Schnittstelle api.existenz.ch mit Vorrang und die Webseite hydrodaten.admin.ch, die Fehlendes (Gefahrenstufe, Stationen) ergänzt und bei Ausfall der Schnittstelle einspringt; die Seite «Impressum, Lizenzen & Datenschutz» nennt die eingeschalteten (Feedback zu #125).

