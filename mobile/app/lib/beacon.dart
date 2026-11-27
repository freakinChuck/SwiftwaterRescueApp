// Fahrzeuge mit BLE-Beacons (Issue #177). Das Beacon sendet nur seine Kennung (iBeacon: UUID, Major, Minor); die App meldet den Standort des
// Fahrzeugs mit dem eigenen Standort. Was je Konto gesucht wird, liefert der Server in me.beacon_cfg (UUIDs, Fahrzeuge, Abstände), die eigene
// Zustimmung für ausserhalb von Einsätzen in me.beacon. Beides hier je Konto zwischengespeichert (30 min), damit es auch im Dienst bereitsteht.
//
// Akku: Im Einsatz läuft der Standort ohnehin; die App scannt dann höchstens alle inc_s Sekunden 5 s lang (forReport) und schickt die Beacons mit
// der nächsten Standortmeldung (incident/location mit beacons), also ohne zusätzliche Anfragen. Ausserhalb von Einsätzen (nur mit home und eigener
// Zustimmung) sucht das Betriebssystem selbst (Android: Scan mit Hardware-Filter über PendingIntent, iOS: Region Monitoring, sw_sys); die App holt
// die Sichtungen ab (flush: im Dienst bei jeder Abfrage, unter iOS beim Wecken über beaconEvent), nimmt den letzten bekannten Standort statt
// laufendem GPS und meldet je Fahrzeug höchstens alle home_min Minuten über beacon/report.
//
// Batteriestand (Issue #224): Der aktive Scan liefert zusätzlich den Frame «Device Info» von DX-Smart (mac, batt). Unter Android hängt das Plugin
// ihn an das iBeacon desselben Geräts, unter iOS kommt er als eigener Eintrag nur mit MAC; zugeordnet wird er dann über die MAC des Fahrzeugs
// (me.beacon_cfg.list[].mac, lernt der Server von Android-Geräten oder trägt die Verwaltung ein). Gemeldet wird er als batt [{id, pct, mac, mv}].
// Eddystone-TLM liefert nur die Spannung (mv); daraus schätzt battPct die Prozent (Lithium 3 V: 2.0 V = 0 %, 3.0 V = 100 %, linear).
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
  static final _battOut = <String, List<J>>{}; // Konto → Batteriestände des Scans, den forReport zuletzt geliefert hat
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
    if (u.isEmpty) { // nur Frame «Device Info» (iOS bzw. Gerät ohne iBeacon im Scan): über die MAC
      final m = js(b['mac']).toUpperCase();
      if (m.isEmpty) return null;
      for (final v in jl(c['list'])) {
        if (js(v['mac']).toUpperCase() == m) return ji(v['id']);
      }
      return null;
    }
    for (final v in jl(c['list'])) {
      if (js(v['uuid']).toUpperCase() == u && ji(v['major']) == ji(b['major']) && ji(v['minor']) == ji(b['minor'])) return ji(v['id']);
    }
    return null;
  }

  /// Prozent aus der Spannung des TLM-Frames (Schätzung, siehe oben)
  static int? battPct(int? mv) => mv == null ? null : ((mv - 2000) / 10).round().clamp(0, 100);

  /// Batteriestände eines Scans für dieses Konto: [{id, pct, mac, mv}]
  static List<J> _batt(J c, List<J> scan) {
    final o = <int, J>{};
    for (final b in scan) {
      final mv = ji(b['mv']), p = ji(b['batt']) ?? battPct(mv), id = _vid(c, b);
      if (p == null || id == null) continue;
      o[id] = {'id': id, 'pct': p, if (js(b['mac']).isNotEmpty) 'mac': js(b['mac']), 'mv': ?mv};
    }
    return o.values.toList();
  }

  /// Batteriestände zu den Beacons, die forReport eben geliefert hat (einmal abholen, null = keine)
  static List<J>? battFor(Account a) {
    final l = _battOut.remove(a.id);
    return l == null || l.isEmpty ? null : l;
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
    _battOut[a.id] = _batt(c, _last);
    final o = <J>[];
    for (final b in _last) {
      final id = _vid(c, b);
      if (id != null) o.add({'id': id, 'rssi': ji(b['rssi'])});
    }
    return o;
  }

  /// «Fahrzeuge jetzt scannen»: Einstellung neu laden, sofort scannen und die gefundenen Fahrzeuge dieses Kontos liefern ([{id, rssi, name,
  /// batt?}], stärkstes zuerst), danach Beacons mit Frame «Device Info», die zu keinem Fahrzeug gehören ({mac, batt, rssi}, ohne id).
  /// Der nächste forReport gibt das Ergebnis mit (Einsatz), sonst meldet reportNow.
  static Future<List<J>> scanNow(Account a) async {
    final c = _c(await cfg(a, force: true));
    if (c == null || jl(c['list']).isEmpty) return [];
    for (var i = 0; _scanning && i < 50; i++) {
      await Future<void>.delayed(const Duration(milliseconds: 200));
    }
    await _scan(ms: 6000);
    _used.remove(a.id);
    final o = <int, J>{};
    for (final b in _last) {
      final id = _vid(c, b);
      if (id == null) continue;
      final r = ji(b['rssi']) ?? -127;
      if ((ji(o[id]?['rssi']) ?? -128) >= r) continue;
      final v = jl(c['list']).where((v) => ji(v['id']) == id).firstOrNull;
      o[id] = {'id': id, 'rssi': r, 'name': js(v?['name'])}; // ältere Server liefern keinen Namen, vehScanDlg ergänzt ihn;
    }
    for (final b in _batt(c, _last)) {
      o[ji(b['id'])]?['batt'] = b['pct'];
      if (o[ji(b['id'])] != null && b['mac'] != null) o[ji(b['id'])]!['mac'] = b['mac'];
      if (o[ji(b['id'])] != null && b['mv'] != null) o[ji(b['id'])]!['mv'] = b['mv'];
    }
    int rs(J x) => ji(x['rssi']) ?? -127;
    final l = o.values.toList()..sort((x, y) => rs(y).compareTo(rs(x)));
    final unk = <String, J>{};
    for (final b in _last) {
      final m = js(b['mac']).toUpperCase();
      final p = ji(b['batt']) ?? battPct(ji(b['mv']));
      if (p == null || m.isEmpty || _vid(c, b) != null) continue;
      if (rs(b) > (ji(unk[m]?['rssi']) ?? -128)) unk[m] = {'mac': m, 'batt': p, 'mv': ji(b['mv']), 'rssi': rs(b)};
    }
    return l + (unk.values.toList()..sort((x, y) => rs(y).compareTo(rs(x))));
  }

  /// Ausserhalb eines Einsatzes von Hand melden (beacon/report mit manual, ohne Person): Anzahl gemeldeter Fahrzeuge; Fehler als Ausnahme
  static Future<int> reportNow(Account a, List<J> found) async {
    Position? p;
    try {
      p = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 15)));
    } catch (_) {
      p = await Geolocator.getLastKnownPosition();
    }
    if (p == null) throw Exception('Ohne Standort kann die App die Fahrzeuge nicht melden.');
    final now = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    final r = await a.api.call('beacon/report', body: {
      'manual': 1,
      'seen': [for (final b in found.where((b) => b['id'] != null)) {'id': b['id'], 'rssi': b['rssi'], 'lat': p.latitude, 'lon': p.longitude, 'acc': p.accuracy, 'at': now}],
      'batt': [for (final b in found.where((b) => b['id'] != null && b['batt'] != null)) {'id': b['id'], 'pct': b['batt'], if (b['mac'] != null) 'mac': b['mac'], if (b['mv'] != null) 'mv': b['mv']}]
    }, timeout: const Duration(seconds: 15));
    return ji(r['n']) ?? 0;
  }

  static Future<void> _scan({int ms = _scanMs}) async {
    _scanning = true;
    try {
      final u = <String>{};
      for (final a in await _accs()) {
        final x = _c(await cfg(a));
        if (x != null) u.addAll(jl(x['list']).map((v) => js(v['uuid']).toUpperCase()));
      }
      _last = await beaconScan(u.toList(), ms: ms);
      _lastAt = DateTime.now().millisecondsSinceEpoch;
    } finally {
      _scanning = false;
    }
  }

  /// Wasserzeit (Issue #241): Abstand zur Gewässerachse in m für die automatische Erkennung im Hintergrund (lädt das Gewässernetz wie water,
  /// aber ohne Boot in der Nähe); null = kein Gewässernetz
  static Future<int?> waterD(Account a, double lat, double lon) async {
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
    return net == null ? null : waterDist(LatLng(lat, lon), [net]).clamp(0, 9999).round();
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
