// Töne für den kritischen Alarm (Issue #171). Die Dateien (assets/sounds/alarm_<Schlüssel>.wav, erzeugt mit scripts/sounds/gen.py)
// kopiert die App beim Start an den Ort, von dem das System sie spielt: Android in den App-Ordner (Weckton über sw_sys, alarmStart),
// iOS nach Library/Sounds (dort sucht iOS den Ton einer lokalen Meldung und von APNs, «sound.name»).
// Wahl je Konto (Backend) und Einsatztyp, gespeichert auf dem Gerät (snd_<Konto> = {Typ-ID: Schlüssel}); fehlt sie, gilt die Vorgabe
// des Einsatztyps aus der Verwaltung (kindPub snd, Push ksnd), sonst der Ton des Systems. Gleicher Katalog: ALARM_SOUNDS in web/api/index.php.
import 'dart:convert';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:path_provider/path_provider.dart';

import 'monitor.dart' show prefs;

/// Ton des Systems (Android Wecker, iOS Standardton); auch Rückfall für unbekannte Schlüssel.
const sndSystem = 'system';

class AlarmSound {
  const AlarmSound(this.key, this.name, this.group);
  final String key, name, group;
}

const sndGroups = {'horn': 'Signalhörner', 'siren': 'Sirenen', 'tone': 'Melodien und Signale'};

const alarmSounds = [
  AlarmSound('horn2', 'Zweiklanghorn (Martinshorn)', 'horn'),
  AlarmSound('horn2_fast', 'Zweiklanghorn schnell', 'horn'),
  AlarmSound('hilo', 'Hi-Lo', 'horn'),
  AlarmSound('wail', 'Heulton (Wail)', 'horn'),
  AlarmSound('yelp', 'Yelp', 'horn'),
  AlarmSound('airhorn', 'Presslufthorn', 'horn'),
  AlarmSound('siren_general', 'Sirene «Allgemeiner Alarm»', 'siren'),
  AlarmSound('siren_water', 'Sirene «Wasseralarm» (verkürzt)', 'siren'),
  AlarmSound('siren_mech', 'Mechanische Sirene', 'siren'),
  AlarmSound('beep3', 'Dreifachton', 'tone'),
  AlarmSound('clock', 'Digitalwecker', 'tone'),
  AlarmSound('bell', 'Alarmglocke', 'tone'),
  AlarmSound('gong', 'Gong-Dreiklang', 'tone'),
  AlarmSound('pager', 'Pager', 'tone'),
  AlarmSound('rising', 'Aufsteigende Tonfolge', 'tone'),
];

bool sndKnown(String? k) => k != null && alarmSounds.any((s) => s.key == k);
String sndName(String? k) => k == null || k == sndSystem ? 'Ton des Systems' : (alarmSounds.where((s) => s.key == k).firstOrNull?.name ?? 'Ton des Systems');

/// Dateiname im System (iOS: Library/Sounds, APNs «sound.name»).
String sndFile(String k) => 'alarm_$k.wav';

// Stand der Dateien: bei neuen oder geänderten Tönen erhöhen, dann kopiert die App alle neu
const _ver = 1;

Future<Directory> _dir() async =>
    Platform.isIOS ? Directory('${(await getLibraryDirectory()).path}/Sounds') : Directory('${(await getApplicationSupportDirectory()).path}/sounds');

/// Beim Start (Oberfläche): fehlende oder veraltete Dateien aus den Assets kopieren.
Future<void> ensureSounds() async {
  try {
    final d = await _dir();
    final stamp = await prefs.getInt('snd_ver');
    if (!await d.exists()) await d.create(recursive: true);
    for (final s in alarmSounds) {
      final f = File('${d.path}/${sndFile(s.key)}');
      if (stamp == _ver && await f.exists()) continue;
      final b = await rootBundle.load('assets/sounds/${sndFile(s.key)}');
      await f.writeAsBytes(b.buffer.asUint8List(b.offsetInBytes, b.lengthInBytes), flush: true);
    }
    await prefs.setInt('snd_ver', _ver);
  } catch (_) {
    // ohne Dateien spielt das System seinen eigenen Ton
  }
}

/// Pfad der Datei für den Weckton (Android); null = Ton des Systems.
Future<String?> sndPath(String? k) async {
  if (!sndKnown(k)) return null;
  try {
    final f = File('${(await _dir()).path}/${sndFile(k!)}');
    return await f.exists() ? f.path : null;
  } catch (_) {
    return null;
  }
}

/// Eigene Wahl je Einsatztyp für ein Konto: {Typ-ID: Schlüssel}; fehlt ein Typ, gilt die Vorgabe des Einsatztyps.
Future<Map<String, String>> sndChoice(String acc) async {
  try {
    final s = await prefs.getString('snd_$acc');
    if (s != null) return Map<String, String>.from(jsonDecode(s) as Map);
  } catch (_) {}
  return {};
}

/// k null = Vorgabe des Einsatztyps.
Future<void> setSndChoice(String acc, int kind, String? k) async {
  final m = await sndChoice(acc);
  if (k == null) {
    m.remove('$kind');
  } else {
    m['$kind'] = k;
  }
  await prefs.setString('snd_$acc', jsonEncode(m));
}

Future<void> forgetSnd(String acc) => prefs.remove('snd_$acc');

/// Ton eines kritischen Alarms: eigene Wahl für den Typ, sonst die Vorgabe des Typs (def), sonst der Ton des Systems.
Future<String> alarmSnd(String acc, int? kind, String? def) async {
  final own = kind == null ? null : (await sndChoice(acc))['$kind'];
  final k = own ?? def;
  return sndKnown(k) ? k! : sndSystem;
}
