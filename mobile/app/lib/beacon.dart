// Fahrzeuge mit BLE-Beacons (Issue #177). Das Beacon sendet nur seine Kennung (iBeacon: UUID, Major, Minor); die App meldet den Standort des
// Fahrzeugs mit dem eigenen Standort. Was je Konto gesucht wird, liefert der Server in me.beacon_cfg (UUIDs, Fahrzeuge, Abstände), die eigene
// Zustimmung für ausserhalb von Einsätzen in me.beacon. Beides hier je Konto zwischengespeichert (30 min), damit es auch im Dienst bereitsteht.
//
// Akku: Im Einsatz läuft der Standort ohnehin; die App scannt dann höchstens alle inc_s Sekunden 5 s lang (forReport) und schickt die Beacons mit
// der nächsten Standortmeldung (incident/location mit beacons), also ohne zusätzliche Anfragen. Ausserhalb von Einsätzen (nur mit home und eigener
// Zustimmung) sucht das Betriebssystem selbst (Android: Scan mit Hardware-Filter über PendingIntent, iOS: Region Monitoring, sw_sys); die App holt
// die Sichtungen ab (flush: im Dienst bei jeder Abfrage, unter iOS beim Wecken über beaconEvent), nimmt den letzten bekannten Standort statt
// laufendem GPS und meldet je Fahrzeug höchstens alle home_min Minuten über beacon/report.
import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:sw_sys/sw_sys.dart';

import 'accounts.dart';
import 'inc/area.dart';
import 'inc/util.dart';
import 'monitor.dart';

/// Bluetooth: 0 = nicht vorhanden bzw. aus, 1 = keine Berechtigung, 2 = bereit
Future<int> btState() async {
  try {
    return (await swSys.invokeMethod<int>('btState')) ?? 0;
  } catch (_) {
    return 0;
  }
}

Future<int> requestBluetooth() async {
  try {
    return (await swSys.invokeMethod<int>('requestBluetooth')) ?? 0;
  } catch (_) {
    return 0;
  }
}

/// Kurzer aktiver Scan: Liste von {uuid, major, minor, rssi}; uuids leer = alle iBeacons (nur Android, für «Beacon einlernen»)
Future<List<J>> beaconScan(List<String> uuids, {int ms = 5000}) async {
  try {
    final r = await swSys.invokeMethod<List>('beaconScan', {'uuids': uuids, 'ms': ms});
    return (r ?? []).whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
  } catch (_) {
    return [];
  }
}

class Beacons {
  static const _ttlMs = 30 * 60 * 1000, _scanMs = 5000;
  static final _cache = <String, J>{};
  static List<J> _last = []; // letzter Scan im Einsatz
  static int _lastAt = 0;
  static bool _scanning = false;
  static final _used = <String, int>{}; // Konto → Zeitpunkt des Scans, den es schon gemeldet hat
  static final _nets = <String, ({String key, RiverNet? net})>{}; // Gewässernetz je Konto (Boot auf dem Wasser)
  static bool _handler = false;

  /// Stand aus me übernehmen (Einsatz-App lädt me ohnehin)
  static Future<void> putMe(String accId, J? me) async {
    if (me == null) return;
    final j = {'at': DateTime.now().millisecondsSinceEpoch, 'cfg': me['beacon_cfg'], 'on': jb(me['beacon']) ? 1 : 0};
    _cache[accId] = j;
    await prefs.setString('bcfg_$accId', jsonEncode(j));
  }

  /// Einstellung eines Kontos (me.beacon_cfg und Zustimmung), höchstens alle 30 min neu vom Server
  static Future<J?> cfg(Account a, {bool force = false}) async {
    var j = _cache[a.id];
    if (j == null) {
      try {
        final s = await prefs.getString('bcfg_${a.id}');
        if (s != null) j = _cache[a.id] = jm(jsonDecode(s));
      } catch (_) {}
    }
    if (force || j == null || DateTime.now().millisecondsSinceEpoch - (ji(j['at']) ?? 0) > _ttlMs) {
      try {
        await putMe(a.id, jm((await a.api.call('me', timeout: const Duration(seconds: 15)))['me']));
        j = _cache[a.id];
      } catch (_) {}
    }
    return j;
  }

  static J? _c(J? j) => j != null && j['cfg'] is Map ? jm(j['cfg']) : null;

  /// Fahrzeug-ID dieses Kontos zu einem gesehenen Beacon
  static int? _vid(J c, J b) {
    final u = js(b['uuid']).toUpperCase();
    for (final v in jl(c['list'])) {
      if (js(v['uuid']).toUpperCase() == u && ji(v['major']) == ji(b['major']) && ji(v['minor']) == ji(b['minor'])) return ji(v['id']);
    }
    return null;
  }

  static Future<List<Account>> _accs() async => (await Accounts.load()).where((a) => a.active && !a.loggedOut).toList();

  /// Nach jeder Abfrage (pollAll): Hintergrund-Scan auf die UUIDs der Konten mit home und eigener Zustimmung, Sichtungen melden
  /// iOS: Das System weckt die App beim Betreten einer Region; das Plugin meldet dann beaconEvent (in main() registriert)
  static void listen() {
    if (_handler || !Platform.isIOS) return;
    _handler = true;
    try {
      swSys.setMethodCallHandler((MethodCall c) async {
        if (c.method == 'beaconEvent') await flush();
        return null;
      });
    } catch (_) {}
  }

  static Future<void> sync() async {
    listen();
    final w = <String>{};
    for (final a in await _accs()) {
      final j = await cfg(a), c = _c(j);
      if (c != null && jb(c['home']) && jb(j?['on'])) w.addAll(jl(c['list']).map((v) => js(v['uuid']).toUpperCase()));
    }
    try {
      await swSys.invokeMethod('beaconWatch', {'uuids': w.toList()});
    } catch (_) {}
    await flush();
  }

  /// Sichtungen aus dem Hintergrund-Scan melden (ausserhalb von Einsätzen, ohne Person)
  static Future<void> flush() async {
    List<J> l;
    try {
      l = ((await swSys.invokeMethod<List>('beaconPending')) ?? []).whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
    } catch (_) {
      return;
    }
    if (l.isEmpty) return;
    Position? p;
    try {
      p = await Geolocator.getLastKnownPosition();
      final newest = l.map((b) => ji(b['at']) ?? 0).reduce((a, b) => a > b ? a : b);
      if (p == null || newest - p.timestamp.millisecondsSinceEpoch ~/ 1000 > 120) {
        p = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.medium, timeLimit: Duration(seconds: 15)));
      }
    } catch (_) {}
    if (p == null) return; // ohne Standort nichts zu melden
    final now = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    for (final a in await _accs()) {
      final j = await cfg(a), c = _c(j);
      if (c == null || !jb(c['home']) || !jb(j?['on'])) continue;
      if ((await accState(a.id)).joined) continue; // im Einsatz meldet die Standortfreigabe
      J sent = {};
      try {
        sent = jm(jsonDecode(await prefs.getString('bsent_${a.id}') ?? '{}'));
      } catch (_) {}
      final every = (ji(c['home_min']) ?? 15) * 60, seen = <J>[];
      for (final b in l) {
        final id = _vid(c, b);
        if (id == null || now - (ji(sent['$id']) ?? 0) < every) continue;
        seen.add({'id': id, 'rssi': ji(b['rssi']), 'lat': p.latitude, 'lon': p.longitude, 'acc': p.accuracy, 'at': ji(b['at']) ?? now});
        sent['$id'] = now;
      }
      if (seen.isEmpty) continue;
      try {
        await a.api.call('beacon/report', body: {'seen': seen}, timeout: const Duration(seconds: 15));
        await prefs.setString('bsent_${a.id}', jsonEncode(sent));
      } catch (_) {}
    }
  }

  /// Im Einsatz: Beacons für die nächste Standortmeldung dieses Kontos ([{id, rssi}], leer = nichts in der Nähe), null = kein frischer Scan.
  /// Startet bei Bedarf einen neuen Scan im Hintergrund; das Ergebnis geht mit einer der nächsten Meldungen mit.
  static Future<List<J>?> forReport(Account a) async {
    final c = _c(await cfg(a));
    if (c == null || jl(c['list']).isEmpty) return null;
    final now = DateTime.now().millisecondsSinceEpoch, every = ((ji(c['inc_s']) ?? 30).clamp(15, 120)) * 1000;
    if (!_scanning && now - _lastAt > every) unawaited(_scan());
    if (_lastAt == 0 || _used[a.id] == _lastAt || now - _lastAt > every * 2) return null;
    _used[a.id] = _lastAt;
    final o = <J>[];
    for (final b in _last) {
      final id = _vid(c, b);
      if (id != null) o.add({'id': id, 'rssi': ji(b['rssi'])});
    }
    return o;
  }

  static Future<void> _scan() async {
    _scanning = true;
    try {
      final u = <String>{};
      for (final a in await _accs()) {
        final x = _c(await cfg(a));
        if (x != null) u.addAll(jl(x['list']).map((v) => js(v['uuid']).toUpperCase()));
      }
      _last = await beaconScan(u.toList(), ms: _scanMs);
      _lastAt = DateTime.now().millisecondsSinceEpoch;
    } finally {
      _scanning = false;
    }
  }

  /// Auf dem Wasser? Nur wenn im letzten Scan ein Wasserfahrzeug dieses Kontos stark zu sehen war (Boot): dann das Gewässernetz um den
  /// Standort laden (Route overpass, Zelle von 0.01°, gleiche Abfrage für alle Geräte in der Nähe) und wie die Einsatz-App messen.
  static Future<int> water(Account a, double lat, double lon) async {
    final c = _c(_cache[a.id]);
    if (c == null || DateTime.now().millisecondsSinceEpoch - _lastAt > 120000) return 0;
    final th = ji(c['assign_rssi']) ?? -70;
    final near = _last.any((b) {
      final id = _vid(c, b);
      return id != null && (ji(b['rssi']) ?? -127) >= th && jl(c['list']).any((v) => ji(v['id']) == id && jb(v['water']));
    });
    if (!near) return 0;
    final la = (lat * 100).round() / 100, lo = (lon * 100).round() / 100, key = '$la,$lo';
    var n = _nets[a.id];
    if (n == null || n.key != key) {
      RiverNet? net;
      try {
        net = RiverNet.parse(await overpassNet(a.api, {'around': [3000, la, lo]}));
      } catch (_) {}
      n = _nets[a.id] = (key: key, net: net);
    }
    final net = n.net;
    return net != null && waterDist(LatLng(lat, lon), [net]) < waterM ? 1 : 0;
  }
}
