// Android-Systemfunktionen über den Kanal ch.swiftwaterrescue/sys (MainActivity.kt): Akku-Optimierung, Standortberechtigung, App-Einstellungen.
// iOS: Standort fragt die WebView selbst, die übrigen Funktionen gibt es dort nicht.
import 'dart:io';

import 'package:flutter/services.dart';

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

/// Standortberechtigung (für die Standortfreigabe in der Web-App). true = erteilt.
Future<bool> requestLocation() async => (await _call<bool>('requestLocation')) ?? true;
Future<bool?> hasLocation() => _call<bool>('hasLocation');

Future<void> openAppSettings() => _call<void>('appSettings');
