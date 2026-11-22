# Administration

You open the administration with ⚙ in the header of the menu. It works best on a computer. On the left are the sections by category (on a phone as a selection list); you only see the sections for which you have a right. From nine sections on, the search field “Search section” helps. The **?** button at the top opens the guide for the selected section.

![Administration, section “Users”](img/admin-benutzer.jpg)

Top right: **To the operations app**, **My operations** and the account menu (change password, log out, legal notice, version).

All sections have the same structure:

- Entries appear as cards one below the other, the buttons always in the same order: own actions, **Edit**, ↑/↓ for the order and finally **Remove** or **Deactivate**.
- At the top is **+ New …**, for longer lists with a search next to it.
- Deactivated entries are collapsed under **Deactivated (N)**.
- In settings, **Save** saves the whole card. After a change “Unsaved changes” appears next to it; if you switch section, the administration asks. What is labelled “saved immediately” (e.g. the functions per operation type) applies without saving.

> Rights always apply on the server as well. What the administration hides cannot be changed by other means either.

## People {#personen}

### Users {#benutzer}

Create persons, change name and user roles, reset passwords and deactivate. With a new account or a reset password, the person must set their own password at the next login.

- **Deactivate** logs the person out and removes them from a running operation. Their data remain; they can be reactivated.
- **Delete** only exists for accounts that have never logged in and do not appear in any operation.
- **Delete permanently** exists for all other accounts with the special right “Delete permanently” (see [Delete permanently](#endgueltig)).
- You can only edit persons whose rights you all have yourself.

### User roles {#benutzerrollen}

Roles bundle rights, for example “Responder”, “Exercise leader” or “System administrator”. A person can have several roles and receives all of their rights. The System administrator role always has all rights and cannot be changed. Per role you also define which response groups it may call out.

![User roles](img/admin-benutzerrollen.jpg)

### Response groups {#einsatzgruppen}

Groups such as “Boat group” or “Bank group” with colour and members. When starting an operation, groups are called out. Per operation type the administration defines which groups can be called out and which are preselected. “Show colour next to the name” shows the group as a dot next to names in the administration.

![Response groups](img/admin-einsatzgruppen.jpg)

### Absences {#abwesenheiten}

Current and planned absences of all persons (right “Manage absences”). Absent persons are not alarmed.

Below are the persons who are currently not available according to their [regular availability times](#profil/einsatzzeiten), and all rules. **+ Rule** records a rule for a person, **Change** and **Remove** work as in the profile. Everything together in the calendar: **📅 Calendar** in the header ([Calendar](#profil/kalender)).

### Training {#ausbildung}

Catalogue of skills (for example “Rescue swimmer”, “Boat operator”) with validity, advance warning, requirement per response group and whether the person records the completion themselves. The overview shows all persons with the status per skill, filterable by response group and “only with action required”, with CSV export.

## Operation {#einsatz}

### Operation types {#einsatztypen}

The kinds of operations, for example “Operation”, “Exercise” or “On-call test”. Each active type has its own start button in the operations app. Per type:

- name, colour, category (primary, secondary, free event),
- **alarm level** (critical to none), level for messages and end of operation, tone for critical alarms,
- response groups that can be called out and are preselected, individual persons allowed, visible to those not called out,
- prepared exercises allowed, preselection “Record”.

**Tone for critical alarms** (at the top of the list): the organisation's default for all types without their own tone, by default the two-tone horn. In a type's dialog “Organisation default” applies until you choose another tone or “System tone”. Each person can choose their own tone in the profile of the native app; the web app always plays its own tone.

A new type takes over the settings of an existing one. Deleted types are kept with their operations.

![Operation types](img/admin-einsatztypen.jpg)

### Response options {#einrueckoptionen}

The choice when accepting an operation. Per option: shares the location, rights of the operation lead, counts among the responders. Options are deactivated instead of deleted; at least one remains active.

![Response options](img/admin-einrueckoptionen.jpg)

### Special roles {#spezialrollen}

Roles in the operation, for example operation lead, rescue swimmer or safety post. Per role: order, colour, **safety post** (limits the search area), may send messages, may speak on the radio.

![Special roles](img/admin-spezialrollen.jpg)

### External responders {#externe}

Templates for external responders (name, description, special role, abbreviation, colour, optionally a fixed location). The operation lead chooses them when recording during the operation.

### Operation symbols {#symbole}

Which tactical symbols are available for markers and whether markers without a symbol are allowed.

## Plans {#planungen}

Prepared exercises, planned secondary operations and free events with their elements, exercise schedule and registrations, runs with log and replay. See [Exercises and planning](#planung/vorbereiten).

## Functions {#funktionen-kat}

### Functions {#funktionen}

Can be switched on and off per operation type: search area, casualties, messages, markers, external responders, river distance, public view, recording, navigation, fixed locations, water level & discharge, person info, geodata layer, radio, vehicles. Functions switched off disappear immediately from the operations app, even during a running operation; existing data are kept.

![Functions per operation type](img/admin-funktionen.jpg)

### River distance {#flussdistanz}

The comparison speeds with which the river distance tool calculates travel times (1 to 6 values).

### Navigation {#navigation}

Which navigation apps are available and which one is the default.

### Language {#sprache}

The app is available in German, English, French and Italian. Here you set the **organisation default**. It applies to persons who have not chosen a language themselves and whose device uses none of these languages, as well as to push notifications to persons who have never opened the app.

> The texts of the app are translated. What the organisation records itself (operation types, roles, response options, messages, documents) and the operation log remain in the language in which they were written.

### AI assistant {#ai}

Provider (OpenAI-compatible or Anthropic), model, key, limits and data protection per operation type (replace names, round coordinates). In the matrix you enable per function, role and operation type whether the assistant is off, on demand or alongside.

## Map & data {#karte-daten}

### Map providers {#karten}

Which base maps (aerial images, maps) are available, their order and the default, globally and per operation type. “Test” loads a sample tile.

![Map providers](img/admin-karten.jpg)

### Coordinates {#koordinaten}

The default coordinate system. Each person can override it in the profile.

### Geodata sources {#geoquellen}

Where the river network for the search area and river distance comes from: own PostGIS database, PostGIS API and Overpass servers, in an adjustable order, with cache and statistics per server.

### Helicopters {#helikopter}

Sources of the ADS-B data, filter (rescue helicopters, helicopters, all), who sees the helicopters, radius with and without an operation, list of rescue helicopters.

### Water level & discharge {#pegel}

Radius and number of gauging stations at the PLS, lakes yes or no, data sources with test.

### Fixed locations {#standorte}

Places, areas and lines that appear on the map in every operation, for example boat launches, depots or navigation channels. With categories (shown or hidden by default, prioritised), properties (for example “river barrier”, which limits the search area, or a warning) and navigation points (access roads).

![Fixed locations](img/admin-standorte.jpg)

### Take over geodata {#geodaten}

Search for objects at the water from OpenStreetMap (weirs, power plants, bridges, slipways …) on a map and take them over as fixed locations. The app regularly compares taken-over locations with the source and reports differences.

### Vehicles {#fahrzeuge}

Vehicle types (boat, trailer, towing vehicle) with space and load, vehicles with their beacon (UUID, major, minor, optionally the MAC address for the battery level on iOS; Android devices learn it themselves) and the most recently reported battery level, setting the location by hand and the combination (which vehicle carries what).

## Evaluation {#auswertung-kat}

### Operation statistics and operation log {#einsatzlog}

Key figures and all operations with log, replay, after action review, anonymisation and “Delete recording”. See [Evaluation](#auswertung/statistik).

### Set up after action review {#aarcfg}

Title, logo, fields of the review, included sections, permitted documents per status and the operation feedback with its questions.

### Statistics per person {#personenstatistik}

Evaluation per person with filter, charts and CSV.

### View for responders {#einsicht}

What participants see under “My operations”: off, anonymous (default) or complete, and which buttons (log, replay, AAR, Word, PDF).

### Anonymisation {#anonymisierung}

Remove personal data of ended operations in stages: anonymise display, delete voice recordings, replay, log, free texts, detach participations from the account, delete recording. Per stage and operation type a deadline for automatic execution. Except for the display, the stages cannot be reversed.

![Anonymisation](img/admin-anonymisierung.jpg)

## Legal {#rechtliches}

Information of the organisation for the legal notice and privacy policy. The page “Legal notice, licences & privacy” supplements it with the current state of the settings.

### Data protection extract {#datenschutzauszug}

For access requests under data protection law. Two separate rights, both in the “Data protection” group of the user roles; the system administrator always has them.

- **Extract per person** (right “Create data protection extract per person”): choose the person (also deactivated ones), then “Create PDF” or “Create ZIP”. The PDF contains account and settings, roles and groups, logged-in devices, absences, availability times, training, plans, participations with last location, entries in the operation log, sent and received messages, announcements, operation feedback, recorded items, use of the AI assistant and the application log. The ZIP additionally contains all data as JSON and CSV, the location history as GeoJSON, the profile picture and the person's own voice recordings.
- **Organisation data export** (right “Create organisation data export”): “Overview as PDF” lists all tables with the number of rows and all persons; “Create ZIP” contains a JSON and a CSV file per table, optionally also the voice recordings and the secured after action reviews.

> Passwords, keys and access tokens are never included. The extract is created in the browser and stored nowhere; every extract created appears in the **history** (who, when, which person, which format).

> ! An extract contains names, locations and content. Only pass it on securely to the entitled person.

Operations whose participations have been anonymised and detached from the account are no longer assigned to the person and are therefore missing from the extract.

## Documents {#dokumente-kat}

The document repository holds the documents needed in operations, exercises and training: operation plans, checklists, fact sheets, regulations. Persons with the right “Manage document repository” see everything needed. Whoever belongs to the **editors** of a folder sees the repository and the inbox only for their folders. The “Documents” function can be switched off per operation type under [Functions](#funktionen).

### Repository {#dokumente}

On the left is the **folder tree** with the number of entries per folder (including subfolders), plus “All documents” and “Review due”. 🔒 marks folders that not everyone sees. On the right at the top is the card of the selected folder with its **access** in one line (who sees it, editors, suggestions, for how many persons it is visible), below it its entries as cards: type (PDF, TEXT, LINK …), version, offline, images, read confirmation, “Review due”, file or address, where it appears in operations, and the date.

- **+ Entry** opens the dialog with the tabs File, Page and Link; **Upload …** creates several files at once in the selected folder (title = file name); **+ Folder** creates a subfolder of the selected one.
- **Drag**: dragging entries into another folder moves them; folders (with the right “Manage document repository”) can be dragged into another folder or onto “All documents” (top level). The device remembers expanded folders.
- **Search** “Search in all folders …” finds titles, keywords, descriptions and the text of pages and PDFs, with the match.

An entry is a **file** (with versions), a **page** (text directly in the app, Markdown as in this guide, with images) or a **link**. Per entry: title, description, keywords, operation types (“For this operation”), fixed locations (the document appears in the location's popup), “Review by”, offline and read confirmation.

- **Folder** (“Edit folder”): on the left name, parent folder, description and who sees it (all logged-in users or only certain response groups or user roles, with the number of persons); a subfolder can only restrict further. On the right the **editors** (may create and change entries), who may **suggest**, and whether the folder can be **shared with external parties**.
- **Entry** (“Edit”): on the left type, title, folder, keywords, description and the content (file with versions, text of the page or address), on the right “Offer in operations” (operation types and fixed locations via “+ Location”), “Review by”, read confirmation (“Configure …”, “View status”) and offline (“may be saved offline”, “save offline automatically for all readers”). Deactivate and activate directly on the card.
- **Versions**: a new file replaces the previous one. Earlier versions are kept according to the [settings](#dokumente-einstellungen) and can be restored. The text of PDFs is extracted for the search when uploading.
- **Read confirmation**: by everyone who sees the entry, only by certain response groups or user roles, or like the requirement of a skill in [Training](#ausbildung). Persons joining later must optionally also confirm (with their own deadline from joining) or not. Deadline in days with a push reminder. A new version requires confirmation again, optionally only for significant changes. With a skill, the confirmation records the training for the person. The overview shows open, confirmed and voluntary confirmations, with “Remind” and CSV.

> Who may see an entry is governed by the folder. Who must confirm it is governed by the entry. All other readers can confirm voluntarily.

### Inbox {#dokumente-eingang}

Suggestions from responders: a new entry, a new version or a note (“Radio missing from the checklist”). Only the folder's editors see them. **Apply** creates the entry or version, **Reject** with a reason, notes are marked as **done**. The suggesting person is notified (push) and sees the status in the profile.

### Display {#dokumente-anzeige}

Which sections appear in the “Documents” window of the operations app and in which order: read confirmation open, favourites, at locations in the operation area, for this operation, recently opened, new and changed, folders. There is a default (also without an operation) and, if desired, a separate one per operation type. Plus the number of entries per section, the number in the menu card “Documents” and whether persons may arrange the sections for themselves.

### Settings {#dokumente-einstellungen}

- **File types**: catalogue of fixed types, each switchable, plus free extensions. The app never displays free extensions; they are only downloaded. Executable and active files (e.g. `.exe`, `.js`, `.html`, `.svg`) are never allowed. The server checks the content, not just the extension.
- **Storage**: maximum size per file, total storage, number of earlier versions, offline per device.
- **External parties**: whether the operation lead may share documents with external parties, on the radio guest page and/or via a separate link, whether external parties may download and how many requests per hour are allowed.

## Connectivity {#konnektivitaet}

### E-mail alarm {#emailalarm}

An incoming e-mail starts an operation: mailbox (IMAP), polling, allowed senders and keywords per operation type, call-out and PLS from coordinates in the e-mail. “Check example” tests an e-mail without starting an operation.

### Radio & conference {#funk}

Announcements and live conference (LiveKit server), channels with listening and speaking rights, transcription, retention and links for external parties per operation type.

### Partner organisations {#partner}

Agreements with other organisations that operate their **own installation** with their own address (right “Configure connectivity”). There is no shared database: each organisation keeps its persons, settings and operations on its server and decides itself what a partner may do.

1. One side enters the partner with **+ Partner organisation**: name, address of the installation (e.g. `https://partner.example.ch/swiftwater/`, shown at the partner at the top under “This installation”) and **Generate new key**. The key appears once for copying.
2. The other side enters this installation likewise and enters the **key received** under “Key”. Pass the key on via a secure channel (not by ordinary e-mail); it authorises call-outs.
3. **Test connection** on both sides; afterwards each side knows the partner's operation types.
4. In the partner's dialog define **which response groups** it may call out (all or selected) and **which of your own operation types** follows an operation type of the partner. Operation types without a mapping trigger nothing on our side.

The servers talk directly to each other; each request is signed with the key and is only valid for a short time (the clocks of both servers must be correct). **Deactivate** blocks the partner immediately without deleting the agreement; **Remove** is only possible while no joint operation is running. A new key replaces the old one; the partner must then enter it as well. Process during an operation: see [Call-out and additional call-out](#leitung/aufgebot).

> Partners only see from us what a joint operation needs: name and special role of the participants with location, the situation, fixed locations and vehicles of this operation, shared casualties and the joint channel. Phone numbers only if direct calling is open to all participants on our side.

## System {#system}

### Application log {#applog}

Technical log of server and devices: events, warnings and errors, with filter, search and export. Helps with troubleshooting; it contains no passwords, locations or operation content.

![Application log](img/admin-applog.jpg)

### Versions {#versionen}

Version of the server, minimum version of the apps and all logged-in apps per platform and version.

### Delete permanently {#endgueltig}

Some things can normally only be deactivated or not deleted at all, so that operation data remain complete. Whoever has the special right **“Delete permanently”** (user roles, group System) in addition to the right of the section sees the button **“Delete permanently”** there:

| What | Where | Consequences |
|---|---|---|
| Ended operation | Operation statistics (right “Evaluate all operations”) | Operation with log, messages, recording, casualties, markers, announcements, after action review (also secured PDF) and feedback; a prepared exercise is reset |
| User | Users | Account and all personal details; in past operations the person appears as “Deleted person”, also in log and messages |
| Response option | Response options | past participations only show the abbreviation; at least one remains active per category |
| Special role | Special roles | past responders, templates, exercise elements and radio channels lose the role |
| Template | External responders | external responders already recorded remain |
| Skill with entries | Training | the recorded training of all persons |
| Vehicle type with vehicles, vehicle from operations | Vehicles | the vehicles with sightings and combinations, also in the replay |

Before deleting, a confirmation lists the consequences. **This cannot be undone.** Running operations and your own account cannot be deleted. Every permanent deletion is recorded in the application log (without names).

> ! For personal data in operations, [anonymisation](#anonymisierung) is usually the better way: it keeps statistics and the operation log.
