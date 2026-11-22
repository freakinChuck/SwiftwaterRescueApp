# First steps

Swiftwater Rescue supports water rescue response groups with alerting, coordination and situation overview when a person is missing in a river, and during exercises. The app runs in the browser on phones and computers. For Android and iOS there is also a separate app (see [Native app](#app)).

This guide describes all functions. Which ones you actually see depends on your rights and your organisation's settings: switched-off functions are missing from the app entirely.

> Everywhere in the app you will find the **?** button. It opens the guide directly at the matching section.

## Logging in {#anmelden}

You receive your account from your organisation's administration: username and an initial password. On first login you must set your own password. After that you stay logged in on the device until you log out.

![Login](img/anmeldung.jpg)

Below the “Log in” button you will find:

- **Try the demo**: get to know the app without an account (see below),
- **User guide**: this page,
- **Legal notice, licences, privacy** of the organisation,
- the **language** selection (“Automatic” uses the device's language).

Forgot your password? The administration resets it. You receive a new initial password and then set your own.

## Demo mode {#demo}

In demo mode you can try out the operations app safely. Everything runs only in this browser tab: nothing is saved on the server, nobody is alerted, and your real operations remain untouched. If you close the tab, the demo state is gone.

![Demo mode welcome](img/demo-start.jpg)

- **With sample data**: an operation on the Reuss in Lucerne is already running. Simulated responders accept the operation, approach, send messages and find the casualty.
- **Without data**: empty app; you start an operation or exercise yourself.
- With the tick **Operation lead rights** you see all functions of the operation lead after accepting.

The purple **DEMO** badge at the top left reopens this dialog at any time, for example to start again or end the demo. The pictures in this guide come from demo mode.

## Installing the app {#installieren}

The web app can be placed on the home screen like an app. It then opens without an address bar and can receive push notifications.

- **Android (Chrome)**: menu ⋮ → “Install app” or “Add to home screen”. The profile also has an “Install app” button.
- **iPhone and iPad (Safari)**: Share icon → “Add to Home Screen”. On the iPhone push only works this way (iOS 16.4 or later).
- **Computer**: in Chrome and Edge an install icon appears in the address bar.

For reliable alarms even with a silenced phone we recommend the [native app](#app).

## Push notifications {#push}

For an alarm to reach you even with the app closed, push notifications are needed. While push is off on your device, a notice with **Switch on push** appears at the top of the menu. You can also switch push on and off in the [profile](#profil/alarmierung). “Later” hides the notice for seven days.

> ! The web app cannot bypass “Do not disturb” or silent mode. For critical alarms that ring even at night, use the [native app](#app/kritisch).

## The interface {#oberflaeche}

The operations app shows the map full screen. All controls lie on top of it:

- **☰ Menu** at the top left: operation, participation, lead, log and tools (see [The menu](#einsatz/menu)).
- **Operation chip** at the top centre: the running operation. Tapping it opens the menu.
- **View** at the top right (layers icon): base map and what appears on the map (see [View](#karte/ansicht)).
- **Map buttons** at the bottom right: camera focus, responders, casualties, messages, markers, gauging stations, radio and river distance. Each opens a side window.
- **Search area notice** at the bottom left.

![Map during an operation with the map buttons](img/karte.jpg)

In the menu header you will find:

| Button | Meaning |
|---|---|
| 👤 | Profile: your personal settings |
| 📋 | My operations: log and replay of your finished operations |
| ⚙ | Administration (only with the corresponding rights) |
| ? | This guide |
| ✕ | Close menu |

## Without connection {#offline}

After every update the app remembers the last state. If the connection fails, it shows a notice at the top with the time of the last connection and continues working with that state. Actions such as sending messages then fail with “No connection to the server”. As soon as the connection is back, the app reloads everything. Map sections you have already seen remain visible offline.

When a new version has been installed, “New version available” appears at the bottom with the **Reload** button.
