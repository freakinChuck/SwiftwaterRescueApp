# Operation lead

## Who leads? {#rechte}

The rights of the operation lead are held by everyone who has accepted the operation and

- holds the special role **Operation lead**,
- takes part with a response option with lead rights (for example “Coordinator rear CP”),
- has the right “Lead any operation”, or
- started the operation.

Without participation there are no rights of the operation lead (exception: the exercise lead). The **Info** tab in the menu shows which rights you currently have. The operation lead additionally sees the **Lead** tab in the menu.

![“Lead” tab](img/menu-leitung.jpg)

## Starting an operation {#starten}

Without a running operation the menu shows one button per operation type you may start, for example **Start operation** or **Start exercise**.

![Menu without operation](img/start.jpg)
![“Start operation” form](img/start-formular.jpg)

In the form:

- **Message** (required): what happened. It appears in the alarm.
- **PLS** (optional): enter coordinates or choose on the map. Without a PLS there is only a search area once a casualty has a PLS.
- **Alarm received**: empty = now; otherwise the actual time (at most 7 days back).
- **Call-out**: response groups, for some types also individual persons, or all persons who can be called out.
- **Record** (for the replay) and **Helicopters on the map**, preselected per operation type.

For a follow-up operation (secondary operation) you choose the operation it refers to and, if desired, take over PLS, flow speed and missing casualties.

If an operation is already running, you find the card **Start another operation** in the “Operation” tab.

## Setting the PLS {#pls}

In the “Lead” tab you set, move or remove the **PLS of the operation** together with its time (start of the drift). It applies to all casualties without their own PLS. All participants set a separate PLS per casualty in the [Casualties](#einsatz/rettlinge) window.

## Call-out and additional call-out {#aufgebot}

The **Call-out** card shows who has been called out. **Call out more** alarms further response groups, individual persons or everyone; only the newly called-out are alarmed. **Allow everyone to join** lets persons who were not called out take part as well, without alarming them.

If your organisation has an **agreement** with a partner organisation (Administration → Connectivity → [Partner organisations](#verwaltung/partner)) and you may call out all response groups, the call-out at the start and for additional call-outs is split into two parts: **Own organisation** and **Partner organisations** with the response groups the partner has released to you. Each organisation has its own server with its own address; the agreement applies per operation type, as the partner has mapped it. If you choose only groups of partners, nobody in your own organisation is alarmed. The partner gets its own **linked operation** on its server with its own settings, roles and response options; its people are alarmed there and take part in their app. The menu shows the card **Joint operation** with the organisations involved, the joint channel and, if set up everywhere, the joint conference. The partners' situation (responders, markers, fixed locations, vehicles) appears faded on the map and can be hidden per organisation in the “View” window under “Other operations”. In the “Responders” and “Markers” windows each partner organisation has its own group with its responders (also without location) and external responders or its PLS, markers, areas, lines and fixed locations; there you can centre on them and navigate to them, but not edit them. You can **follow** partner responders like your own, open their **Info** (also by tapping on the map) and, if the partner has enabled direct calls for everyone, **call** them. **Casualties are shared:** whoever records, edits, sets the place found, reports as rescued or removes a casualty in one of the organisations changes it for everyone; the operation log shows the person with their organisation. When the triggering organisation ends the operation, the linked ones end too. If a partner is unreachable, this is shown next to it; its operation continues on its server.

## External responders {#externe}

External responders have no app account, for example safety posts of the fire brigade. In the “Responders” window you record them with **+ External responder**:

1. Choose a template (it brings name, special role, abbreviation and colour) or “New without template”.
2. Tap the location on the map. Templates with a location are placed there directly.

External responders appear square and dashed on the map. With the special role of a post they limit the search area like a person. Edit (✎) and remove (✕) in the list.

## Drawing the situation {#lage}

In the “Markers” window the operation lead sets:

- **+ Marker**: a point with name, colour and optionally a tactical symbol.
- **+ Area**: a surface. Tap the corners, then “Done” (Undo and Cancel in the bar at the top).
- **+ Line**: for example a barrier line or rescue axis, points in drawing order.

Everyone sees the situation immediately. Remove via ✕ in the list. An object of the geodata layer (weir, bridge) can be taken over directly as a marker via its popup.

## Operation concepts {#konzepte}

An operation concept is a prepared situation for a stretch of water: posts with an assignment, markers, areas, lines, external responders, a message to all, documents, river barriers, radio channels, additional call-outs, helicopters and recording. Administration creates the concepts ([Operation concepts](#verwaltung/konzepte)).

- **Suggestion at the PLS**: If a PLS (of the operation or of a casualty) lies within the scope of a concept, the map shows the notice «Operation concept available» with «Preview …», «Load» and «Later». Depending on the operation type the app also loads the concept automatically; messages, assignments and call-outs then remain pending until the operation command sends them with «Trigger now» on the «Command» tab.
- **Preview**: The elements appear dashed on the map. Tick what is loaded; elements already loaded and elements of disabled functions are greyed out. «Load N of M elements» adds them.
- **Loaded**: The elements are ordinary markers, areas, lines and external responders. In the «Markers» window they appear in the situation as a separate group «Operation concept «Name»». Posts show «not yet staffed» or «staffed by …» as soon as a responder with the special role of the assignment is nearby. Assigned persons receive their assignment as a message, even if they accept later.
- **«Command» tab, card «Operation concepts»**: loaded concepts with «Show», «Elements …» (load missing ones) and «Remove» (the markers and external responders of the concept disappear; messages, radio channels, river barriers and call-outs remain), suggestions with «Do not load», **follow-on concepts** (the search area reaches the scope of another concept downstream) and «Load another concept …».

![Suggestion at the PLS](img/konzept-vorschlag.jpg)
![Preview of an operation concept](img/konzept-vorschau.jpg)
![Card «Operation concepts» on the «Command» tab](img/konzept-leitung.jpg)

## Managing responders {#einsatzkraefte}

In the “Responders” window the operation lead changes a person's **special role** and **removes** persons from the operation (rejoining remains possible). The “Lead” tab also has the “Responders” card for this.

## Recording {#aufzeichnung}

The **Recording** card starts, pauses, resumes and stops the recording of locations for the [replay](#auswertung/replay). Everyone sees in the menu whether recording is on (“● recording”). Without recording there is a log but no replay.

## Public view {#oeffentlich}

The **Public view (without login)** card creates a link through which others can watch without an account, for example the police or a partner organisation. They see the map, PLS, search area, responders, markers and casualties, optionally also the log.

- **Anonymous** (default): the special role instead of names, casualties as “Casualty N” without description.
- **Switch off** invalidates the link immediately; switching on again creates a new one.
- After the end of the operation the link only shows “ended”.

## River barriers {#flusssperren}

Fixed locations with the property “limits search area” (for example a weir or a debris trap) end the search area. In the **River barriers** card you switch them off for this operation entirely or individually, for example when a weir is open. Locations with a warning (danger to life, hydraulic) are highlighted in the search area.

## Helicopters {#helikopter}

If the display is set up, the map shows helicopters and other aircraft from open ADS-B data: red = rescue helicopters (for example Rega), yellow = other helicopters. In the **Helicopters** card you switch the display on and off for the operation. Low-flying helicopters in valleys are often missing because no receiver sees them.

## Radio for external parties {#funk-extern}

With the **Radio for external parties (link)** card you create a link through which an external person without an account hears a radio channel and, if allowed, speaks. In **Radio & conference** you choose the channels of the operation.

## Ending the operation {#beenden}

**End operation** in the “Lead” tab closes the operation for everyone. The readings of the stations at the PLS go into the log, a running recording ends, and, if set up, the participants receive a request for [operation feedback](#auswertung/feedback).
