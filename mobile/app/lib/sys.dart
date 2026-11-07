// Systemfunktionen. Android über den Kanal ch.swiftwaterrescue/sys (MainActivity.kt): Akku-Optimierung, Standortberechtigung, App-Einstellungen.
// iOS: Standortberechtigung über geolocator, die übrigen Funktionen gibt es dort nicht.
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';

const _ch = MethodChannel('ch.swiftwaterrescue/sys');

Future<T?> _call<T>(String m) async {
  if (!Platform.isAndroid) return null;
  try {
    return await _ch.invokeMethod<T>(m);
  } catch (_) {
    return null;
  }
}

/// Ist die App von der Akku-Optimierung ausgenommen? (sonst kann Android den Dienst anhalten)
Future<bool?> batteryExempt() => _call<bool>('batteryExempt');
Future<void> requestBatteryExempt() => _call<void>('requestBatteryExempt');

/// Stufe der Standortberechtigung: 0 = keine, 1 = nur während der Nutzung, 2 = immer (auch bei gesperrtem Bildschirm).
const locNone = 0, locInUse = 1, locAlways = 2;

int _lvl(LocationPermission p) => p == LocationPermission.always ? locAlways : (p == LocationPermission.whileInUse ? locInUse : locNone);

/// Läuft gerade eine Abfrage, warten weitere Aufrufe auf dieselbe (sonst bricht die zweite die erste ab).
Future<int>? _asking;

Future<int> locLevel() async {
  if (Platform.isAndroid) return (await _call<int>('locationLevel')) ?? locNone;
  try {
    return _lvl(await Geolocator.checkPermission());
  } catch (_) {
    return locNone;
  }
}

/// Standortberechtigung während der Nutzung anfragen (für die Standortfreigabe in der Web-App). Gibt die Stufe zurück.
Future<int> requestLocation() => _asking ??= _request().whenComplete(() => _asking = null);

Future<int> _request() async {
  if (Platform.isAndroid) return (await _call<int>('requestLocation')) ?? locNone;
  try {
    var p = await Geolocator.checkPermission();
    if (p == LocationPermission.denied) p = await Geolocator.requestPermission();
    return _lvl(p);
  } catch (_) {
    return locNone;
  }
}

/// «Immer zulassen» anfragen. Android 11+ und iOS zeigen dafür die Systemeinstellung bzw. eine eigene Rückfrage;
/// hat man sie schon einmal abgelehnt, bleibt nur der Weg über die Einstellungen der App.
Future<int> requestBackgroundLocation() async {
  if (Platform.isAndroid) return (await _call<int>('requestBackgroundLocation')) ?? locNone;
  try {
    final p = await Geolocator.requestPermission();
    if (p != LocationPermission.always) await Geolocator.openAppSettings();
    return _lvl(p);
  } catch (_) {
    return locNone;
  }
}

Future<void> openAppSettings() async {
  if (Platform.isAndroid) return _call<void>('appSettings');
  await Geolocator.openAppSettings();
}
