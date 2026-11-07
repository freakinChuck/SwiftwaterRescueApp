// Systemfunktionen. Android über das Plugin packages/sw_sys (Kanal ch.swiftwaterrescue/sys, auch im Vordergrunddienst): Akku-Optimierung,
// Standortberechtigung, App-Einstellungen, Weckton kritischer Alarme. iOS: Standortberechtigung über geolocator, die übrigen Funktionen gibt es dort nicht.
import 'dart:io';

import 'package:geolocator/geolocator.dart';
import 'package:sw_sys/sw_sys.dart';

Future<T?> _call<T>(String m) async {
  if (!Platform.isAndroid) return null;
  try {
    return await swSys.invokeMethod<T>(m);
  } catch (_) {
    return null;
  }
}

/// Ist die App von der Akku-Optimierung ausgenommen? (sonst kann Android den Dienst anhalten)
Future<bool?> batteryExempt() => _call<bool>('batteryExempt');
Future<void> requestBatteryExempt() => _call<void>('requestBatteryExempt');

/// Weckton kritischer Alarme (Android): Alarm-Lautstärke, Wiedergabe als Wecker. Wecker kommen bei «Nicht stören» standardmässig
/// durch und klingeln auch im Lautlos-Modus, anders als der Ton einer Benachrichtigung. Endet nach 5 min von selbst.
Future<bool> alarmStart() async => (await _call<bool>('alarmStart')) ?? false;
Future<void> alarmStop() => _call<void>('alarmStop');

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
