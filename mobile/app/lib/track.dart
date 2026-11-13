// Standort im Einsatz teilen, auch bei gesperrtem Bildschirm oder geschlossener App (Feedback zu Issue #108). Ist die Web-App eines Kontos
// im Vordergrund, teilt sie selbst (mit Erkennung «auf dem Gewässer»); sonst sendet die App die Position an incident/location, für jedes
// Konto, das am laufenden Einsatz teilnimmt (AccState.joined aus monitor.dart). Läuft dort, wo pollAll läuft: unter Android im
// Vordergrunddienst (service.dart), unter iOS in der App (im Hintergrund weiter, solange der Standort «Immer» erlaubt ist).
// Im Hintergrund braucht es «Immer zulassen» (ACCESS_BACKGROUND_LOCATION bzw. iOS «Immer»), sonst kommen nur Positionen bei offener App.
// Antwortet der Server mit shared:false (Einrückoption ohne Standort) oder lehnt er ab (Einsatz beendet), ruht das Konto für diesen Einsatz.
// Das Gewässernetz lädt das Gerät hier nur, wenn ein Boot (Fahrzeug, Issue #177) in der Nähe ist; sonst ist water 0, Fahrten mit einem Boot
// ohne Beacon können also als Fahrt im Log erscheinen.
import 'dart:async';
import 'dart:io';

import 'package:geolocator/geolocator.dart';

import 'accounts.dart';
import 'api.dart';
import 'beacon.dart';
import 'monitor.dart';
import 'sys.dart';

const _minGapMs = 5000, _beatMs = 15000;

class Tracker {
  static StreamSubscription<Position>? _sub;
  static Position? _last;
  static final _sent = <String, int>{}; // Konto → letzte Meldung (ms)
  static final _off = <String>{}; // «Konto|Einsatz»: teilt nicht
  static var _accs = <Account>[];
  static var _inc = <String, int>{};
  static bool _busy = false;

  /// Teilt die App gerade einen Standort? (Anzeige im Dienst)
  static bool get active => _sub != null && _accs.isNotEmpty;

  /// Nach jeder Abfrage: Konten im Einsatz bestimmen, Positionen starten oder beenden, Herzschlag senden.
  static Future<void> sync() async {
    final l = (await Accounts.load()).where((a) => a.active && !a.loggedOut).toList();
    final on = <Account>[], inc = <String, int>{};
    for (final a in l) {
      final st = await accState(a.id);
      if (st.joined && st.inc != null && !_off.contains('${a.id}|${st.inc}')) {
        on.add(a);
        inc[a.id] = st.inc!;
      }
    }
    _accs = on;
    _inc = inc;
    if (on.isEmpty || await locLevel() == locNone) {
      stop();
      return;
    }
    _sub ??= Geolocator.getPositionStream(locationSettings: _settings()).listen((p) {
      _last = p;
      _push(p, _minGapMs);
    }, onError: (_) {});
    final p = _last;
    if (p != null) await _push(p, _beatMs);
  }

  static LocationSettings _settings() {
    if (Platform.isAndroid) return AndroidSettings(accuracy: LocationAccuracy.best, distanceFilter: 0, intervalDuration: const Duration(seconds: 5));
    if (Platform.isIOS) {
      return AppleSettings(
          accuracy: LocationAccuracy.best,
          distanceFilter: 0,
          activityType: ActivityType.otherNavigation,
          allowBackgroundLocationUpdates: true,
          pauseLocationUpdatesAutomatically: false,
          showBackgroundLocationIndicator: true);
    }
    return const LocationSettings(accuracy: LocationAccuracy.best);
  }

  static Future<void> _push(Position p, int gap) async {
    if (_busy) return;
    _busy = true;
    try {
      final now = DateTime.now().millisecondsSinceEpoch;
      for (final a in List<Account>.from(_accs)) {
        if (await isForeground(a.id)) continue; // die Web-App teilt selbst
        if (now - (_sent[a.id] ?? 0) < gap) continue;
        _sent[a.id] = now;
        final key = '${a.id}|${_inc[a.id]}';
        try {
          // Fahrzeuge (Issue #177): Beacons des letzten Scans; water nur, wenn ein Boot in der Nähe ist (sonst lädt die App kein Gewässernetz)
          final bs = await Beacons.forReport(a);
          final r = await a.api.call('incident/location',
              body: {'lat': p.latitude, 'lon': p.longitude, 'acc': p.accuracy, 'water': await Beacons.water(a, p.latitude, p.longitude), 'manual': 0, 'beacons': ?bs},
              timeout: const Duration(seconds: 15));
          if (r['shared'] == false) _off.add(key);
        } on ApiError catch (e) {
          if (e.status >= 400 && e.status < 500) _off.add(key); // kein laufender Einsatz, nicht mehr dabei, abgemeldet
        } catch (_) {}
      }
      _accs = _accs.where((a) => !_off.contains('${a.id}|${_inc[a.id]}')).toList();
      if (_accs.isEmpty) stop();
    } finally {
      _busy = false;
    }
  }

  static void stop() {
    _sub?.cancel();
    _sub = null;
    _last = null;
  }
}
