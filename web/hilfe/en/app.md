# Native app (Android and iOS)

The native app “Swiftwater Rescue” for Android and iOS offers the same operations app as the browser, plus:

- **critical alarms** with full screen and alarm tone, even with “Do not disturb” and a silenced phone,
- **sharing the location in the background**, even with the screen locked,
- **several organisations** (servers) in one app,
- **vehicles** via Bluetooth beacons.

The app opens the administration, “My operations” and this guide as a web page; you do not need to log in again for this.

## Accounts {#konten}

At the first start you add an account: server address, username and password, optionally your own label. You add further accounts (other organisations) in the account list. For each account you switch on whether it is monitored (“active”).

- At startup the app opens the account used last.
- Back to the account list: tap the server name with ⇄ in the header of the menu, or “Switch accounts / server” in the profile.
- The account list shows for each account whether the location could last be shared.
- With **Try demo** in the account list you also start the [demo mode](#start/demo) in the app.

## Language {#sprache}

Under **Settings** (account list) and in the profile, card “Display”, you choose the language of the app: German, English, French, Italian or “Automatic” (device language). It applies to all accounts and to notifications; the app reports it to the servers so that push arrives in the same language.

## Alarm and monitoring {#hintergrund}

The app regularly polls all active accounts and reports alarms, messages to you, announcements and the end of the operation. With push set up, notifications arrive immediately even when the app is closed.

- **Android**: without push or during an operation, a background service runs with a permanent notification. “Always poll in the background” in the profile keeps it active permanently.
- **iOS**: notifications with the app closed only arrive via push.

## Critical alarms {#kritisch}

For operation types with the alarm level “critical”, the app shows the alarm full screen and plays an alarm tone until you acknowledge it (at most 5 minutes).

- **Android**: allow the app access to **“Do not disturb”** so that the alarm rings even then. The app asks for it.
- **iOS**: critical notifications need the permission for “Critical alerts”.
- You choose the tone in the profile under “Alerting” per operation type (horns, sirens, melodies) and listen to it with “Test”.

## Location in the background {#standort}

For the app to share your location during an operation even with the screen locked, it needs the location permission **“Allow all the time”**. The app asks for it after you accept an operation. Without this permission it only shares the location while it is open.

After the end of the operation or when you leave the operation, the app stops sharing the location.

## Radio {#funk}

Announcements and live conference work in the app as in the browser (see [Radio](#einsatz/funk)). The app needs the microphone permission for this. On Android the conference continues even with the screen locked.

## Vehicles and beacons {#beacons}

In an operation with vehicles the app asks once for Bluetooth. It detects the beacons of nearby vehicles, reports their location and assigns you to the vehicle you are sitting in. Outside operations it only reports beacons with your consent in the profile.

**Scan vehicles now** (in the “Responders” window under “Vehicles” and in the profile, card “Vehicles”) immediately searches for nearby beacons, shows the vehicles found with signal strength and reports them: during an operation with your location, otherwise without your name (only if the administration allows reports outside operations). Handy for checking whether a beacon works.

**Battery level:** DX-Smart beacons send it if the “Device Info” frame is switched on in their configuration app (leave the “iBeacon” frame switched on). Beacons with Eddystone-TLM work as well: they send the voltage, and the app estimates the percentage from it (3.0 V = full, 2.0 V = empty). On iOS this additionally requires the “Device Info” frame. The app reads it with every scan; it is shown with the vehicle (from 20 % with “⚠ Battery low”) and in the overview of “Scan vehicles now”. Nearby beacons that belong to no vehicle also appear there, with their MAC address. On iOS the app assigns the battery level only via the vehicle's MAC address: an Android device learns it at the first scan, otherwise the administration enters it for the vehicle.

> ! On the iPhone the app only finds beacons with **Precise location** (Settings → Swiftwater Rescue → Location). If it is off, the profile shows this under “Vehicles”, with the button **Allow precise location**. Outside operations “Always” is additionally required.

## Updates {#updates}

If your app version is too old for the server, a notice with **Update** appears (Google Play or TestFlight). **What's new** in the profile shows the changes.
