# During an operation

## The menu {#menu}

The menu ☰ shows the operation at the top: message, alarm time, alarm level, PLS, flow speed and whether the operation is being recorded or is publicly visible. Below are tabs:

| Tab | Content |
|---|---|
| Operation | Participation, My operation, flow speed, water level & discharge, tools, AI assistant |
| Lead | only for the operation lead, see [Operation lead](#leitung) |
| Exercise | only for the exercise lead, see [Exercises](#planung/freigabe) |
| Log | Live operation log |
| Info | your rights in this operation, person, server, version |

![“Operation” tab](img/menu-einsatz.jpg)
![“Info” tab with your own rights](img/menu-info.jpg)

When opened, the menu always shows “Operation” (for the exercise lead “Exercise”).

## Responders {#einsatzkraefte}

The **Responders** map button opens the list of all participants, grouped by special role, followed by the external responders and the vehicles. For each person you see the response option, the age of the location and the coordinates.

![List of responders](img/einsatzkraefte.jpg)

- **Centre** moves the map to the person, **Follow** keeps them in view.
- **ℹ Info** shows profile picture, special role, response option and response groups. The same window opens when you tap a responder on the map.
- 🧭 passes the location to your [navigation app](#einsatz/navigation).
- The groups can be expanded and collapsed; the device remembers it.

Here the operation lead can change special roles, remove persons from the operation and record [external responders](#leitung/externe).

## Messages {#nachrichten}

The **Messages** map button opens the history. Everyone who has accepted the operation sees all messages. The recipients only determine who is notified; messages addressed to you are marked. The red number on the button shows unread messages.

![Messages](img/nachrichten.jpg)

1. Enter the text.
2. Optionally choose recipients (grouped by special role). Without a selection everyone is notified.
3. Optionally **As marker on the map**: at your own location or at a point you then tap on the map.
4. **Send**.

Messages store your location if you share it. Message markers appear as a separate category on the map; they can be removed by the sender or the operation lead. Who may send is set by the administration per special role.

## Casualties {#rettlinge}

The **Casualties** map button shows the persons being searched for in three groups: **Missing** (without location), **Location known** and **Rescued**. The red number counts those not yet rescued.

![Casualties](img/rettlinge.jpg)

- **+ Record casualty**: name and description. Without a name it is called “Unknown casualty”.
- **📍 Place found**: at your own location or chosen on the map. The most recently reported place applies.
- **Rescued** or **Undo**.
- **Search area** (for missing ones): camera focus on their sub-area.
- **⋯** expands further buttons: reset place found, **Set PLS** (own PLS for this casualty), edit and, for the operation lead, remove.

Everyone who has accepted the operation may record casualties, report where they were found and report them as rescued.

## Markers {#markierungen}

The **Markers** map button shows three groups:

- **Point last seen (PLS)**: the PLS of the operation and the PLS of the casualties. Faded PLS no longer count for the search area (casualty found).
- **Situation**: markers, areas and lines of the operation lead.
- **Fixed locations**: places of the organisation such as boat launches or depots; with a search area split into “In the operation area” and “Outside”.

![Markers](img/markierungen.jpg)

With the ticks you hide individual entries or whole groups on the map; **Show** moves the map there. New markers, areas and lines are set by the operation lead (see [Drawing the situation](#leitung/lage)).

## Flow speed {#fliessgeschwindigkeit}

In the “Operation” tab all participants set the **flow speed** in m/s. From it the app calculates the length of the search area. The gauging stations at the PLS (see below) help with the estimate.

## River distance {#flussdistanz}

The **River distance** tool measures the distance between two points along the water. You find it as a map button and in the menu under “Tools”, also without an operation.

1. Tap the **River distance** map button.
2. Tap start point A on the map.
3. Tap end point B.

The app snaps both points to the nearest water and shows the distance along the water and as the crow flies, the flow direction, the drift time with the flow speed of the operation (without an operation you enter it) and the duration for some comparison speeds. If the route passes a weir or another hazard, this is shown as well. At most 50 km as the crow flies.

## Navigation {#navigation}

The 🧭 button for responders, casualties, PLS, markers, fixed locations and gauging stations passes the destination to your navigation app (Google Maps, Apple Maps, Waze or another app). You choose which app in your [profile](#profil/anzeige). If a fixed location has several navigation points (for example access roads), you pick one.

Markers without a popup (responders, PLS) ask briefly when tapped, so that the navigation app does not open by mistake.

## Gauging stations {#messstationen}

The **Gauging stations** map button shows water level, discharge and water temperature of the FOEN gauging stations:

- **At the PLS**: the nearest stations, with the values at the start of the operation and the current ones.
- **My gauging stations**: your own selection, also without an operation (in the [profile](#profil/messstationen)).

![Gauging stations](img/messstationen.jpg)

The markers on the map are coloured according to the danger level. The values at the start and end of an operation are in the operation log.

## Radio {#funk}

If radio is set up, the **Radio** map button opens two tabs:

- **Live conference**: real-time voice radio in a channel. **Join** and **Leave** are also available directly on the map at the bottom left. With the live button you talk as long as you hold it; “Mic open” keeps the microphone open.
- **Announcements**: voice messages. Hold the button, speak, release. Others hear the announcement immediately or later; it appears with ▶ in the log and, if set up, also as text.

Which channels you hear and where you may speak depends on your special role and the operation lead. The operation lead can mute persons. In the profile you set whether announcements are played automatically (“Listen in”).

## Vehicles {#fahrzeuge}

Vehicles with a Bluetooth beacon appear on the map as soon as the native app of a participant sees them. The list is in the “Responders” window under “Vehicles”: who is in it, what is loaded (boat on the trailer) and where it was last seen. Loading and unloading is done via the vehicle's popup.

## Emergency call (SOS) {#sos}

If you get into trouble yourself, press and hold the red **SOS** button at the top left of the map for **2 seconds**. A short countdown follows: **Cancel** stops an accidental call, **Send now** sends it without waiting. The button is only shown while you take part in the incident.

Depending on your organisation's settings, the emergency call alerts the incident command and the responders near you with a **critical** alarm showing your name and location. They see a full-screen alert with distance, 🧭 and **Acknowledge**, a red bar at the top and a flashing marker at your location; the map jumps to you. If you are in a live conference, a signal tone sounds there.

As soon as someone acknowledges, your bar shows "seen by …". When the danger is over, tap **END** (or **End** in the bar); the incident command can end it too. Call, acknowledgement and end are recorded in the incident log and in the replay.

> ! The emergency call does not replace calling 112/144/117 when outside help is needed.

## Documents {#dokumente}

The **📚 Documents** map button opens your organisation's documents, also without an operation. At the top is the search: it finds titles, descriptions, keywords, the text of pages and the text of PDFs, with page number. Below are the sections the administration has set up, for example open read confirmations, ★ favourites, documents at locations in the operation area, documents for this operation type and the folders. If allowed, you arrange the sections for yourself with **Arrange sections**.

Each line shows the type on the left (PDF, TEXT, LINK …), next to it title, type, size and date, plus chips such as “⬇ offline” or the location (📍). On the right are **Open** and the star for favourites. For search hits, the folder is shown instead, and below it the match, for example “p. 3: “… hydraulic …”” or “Keyword: hydraulic”; the search words are highlighted.

- **View**: the app shows pages, images and PDFs directly; other files you download or open in a suitable app. Links open in the browser.
- **★** (or ☆) pins an entry. Favourites and entries chosen by the editors are also stored **offline** on the device.
- **Read confirmation**: if the organisation requires you to read a document, it appears with a deadline in the menu (card “📚 Documents”) and with a counter on the map button. At the bottom or top of the document you confirm with **Read and understood**.
- **Suggest**: if something is missing or wrong, you send the editors a suggestion, on an entry or in a folder. In the browser you can also attach a file.
- **Fixed locations**: if a document is attached to a location (e.g. the operation plan of a weir), it appears in the location's popup (in the app in the info sheet) under “Documents for this location”: for each document the type (PDF, TEXT …), the title, “offline” if it is stored on the device, and the date. Tapping opens it.

In the “Lead” tab, under **Documents for external parties**, the operation lead can share individual documents with external parties without the app: on the radio guest page or via a separate link. The sharing ends with the operation.

## Operation log {#log}

The **Log** tab shows the course of the operation: joins, special roles, trips (“drives from A to B”), messages in full text, markers, casualties, measurements and more. **Large mode** shows the log full-screen, for example on a screen in the command post.

![“Log” tab](img/menu-log.jpg)

## AI assistant {#ai}

If your organisation has set up the AI assistant, you find **✨ Situation summary** and **✨ Ask a question** in the “Operation” tab. The assistant only makes suggestions; nothing is taken over automatically. Whether it works on demand or alongside, you set in the [profile](#profil/ai).

## Tools {#werkzeuge}

Under “Tools” in the menu you find the [river distance](#einsatz/flussdistanz) and, depending on your rights, further aids. They also work without a running operation.
