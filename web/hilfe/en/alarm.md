# Alarm and participation

## The alarm {#alarm}

When someone starts an operation or exercise, the app alerts all people called out. How loud the alarm is depends on the operation type (alarm level):

| Alarm level | What happens |
|---|---|
| Critical | Red notice with triple tone, push stays. In the native app full screen and continuous tone, even with “Do not disturb”. |
| High | Red notice with tone, push stays. In the native app full screen. |
| Normal | Red notice with a short tone. In the native app full screen. |
| Low | Red notice without sound, silent push notification. In the native app full screen. |
| None | Red notice only in the open app, without sound and without push. In the native app full screen. |

If the app is open, the notice appears at the top right until you tap it; this applies to every alarm level, also for secondary operations. With the app closed it arrives as a push notification. Tapping it opens the app and shows the operation area.

The alarm notice (full screen in the native app) already contains the response options: with **Accept: …** you accept the operation directly; the map then shows the operation area. **Just acknowledge** (in the web app: tap next to the buttons) closes the alarm without accepting; you can then accept in the menu as described below.

With **exercise mode** in the [profile](#profil/alarmierung) you can also be alerted critically for exercises, if the operation type provides for it. If you are [reported absent](#profil/abwesenheit), you are not alerted, but you see the operation and can take part voluntarily.

## Accepting an operation {#annehmen}

It is quickest directly in the alarm (see [The alarm](#alarm)). While you are not taking part, the map shows the yellow bar **Operation not yet accepted** below the operation chip. Tapping it opens the menu at the “Participation” card.

![Notice “Operation not yet accepted”](img/hinweis-annehmen.jpg)
![Participation with the response options](img/teilnahme.jpg)

Choose there how you respond, for example “Approach via base” or “Direct approach”. You are then taking part in the operation: the operation lead sees you in the list, you see all messages and can collaborate. After accepting, the map shows the search area.

You do not need to decline an operation: whoever does not accept does not take part.

## Response options {#einrueckoption}

The response options are defined by the administration. They determine:

- whether the app **shares your location** (options “without location” share nothing),
- whether you receive **operation lead rights** (for example “Rear CP coordinator”; then without special role),
- whether you count in the responder counter.

## My operation {#mein-einsatz}

After accepting, the menu shows the **My operation** card in the “Operation” tab: response option, location sharing status and your **special role** (for example lifeguard, boat driver, safety post). You choose the special role yourself; the operation lead can change it.

![“Operation” tab in the menu](img/menu-einsatz.jpg)

## Sharing your location {#standort}

With response options that include location, the app shares your location while you take part and the app is open. The browser asks for permission the first time. The operation lead then sees you on the map, and trips appear in the operation log.

- On a phone the web app only shares the location while it is in the foreground. To share with the screen locked you need the [native app](#app/standort).
- **Set location manually (test)** sets your location to a point on the map, for example for desk exercises. “Use GPS location” switches back.
- Positions on the water (boat) do not count as trips.

## Leaving the operation {#verlassen}

**Leave operation** in the “My operation” card ends your participation, for example when you are relieved. You can rejoin later. If the operation lead has removed you from the operation, you receive a notice; rejoining is possible then too.

## Several operations at once {#parallel}

If several operations are running, the menu shows the **Running operations** card above the tabs. There you switch the displayed operation. You only ever take part in one: if you accept another, you leave the previous one. The other operations appear faded on the map; in the [View](#karte/ansicht) window you show and hide them under “Other operations”.

## End of operation {#ende}

When the operation lead ends the operation, you receive a notice and the notifications about the operation disappear. You will then find log and replay under [My operations](#auswertung/meine).
