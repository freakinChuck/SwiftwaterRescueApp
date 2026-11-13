// Standort im Einsatz teilen, auch bei gesperrtem Bildschirm oder geschlossener App (Feedback zu Issue #108). Ist die Einsatz-App eines Kontos
// im Vordergrund, teilt sie selbst (mit Erkennung «auf dem Gewässer»); sonst sendet die App die Position an incident/location, für jedes
// Konto, das am laufenden Einsatz teilnimmt (AccState.joined aus monitor.dart). Läuft dort, wo pollAll läuft: unter Android im
// Vordergrunddienst (service.dart), unter iOS in der App (im Hintergrund weiter, solange der Standort «Immer» erlaubt ist).
// Im Hintergrund braucht es «Immer zulassen» (ACCESS_BACKGROUND_LOCATION bzw. iOS «Immer»), sonst kommen nur Positionen bei offener App.
// Antwortet der Server mit shared:false (Einrückoption ohne Standort) oder lehnt er ab (Einsatz beendet), ruht das Konto für diesen Einsatz.
// Das Gerät kennt hier kein Gewässernetz: water ist immer 0, Fahrten mit dem Boot können also als Fahrt im Log erscheinen.
//
// Issue #183: geolocator hält unter iOS einen einzigen Positionsstrom und übergeht die Einstellungen späterer Zuhörer. Die Einsatz-App
// (inc/store.dart) nutzt deshalb dieselben Einstellungen (locSettings), sonst lief der Strom ohne Hintergrund-Flags weiter und iOS hielt
// die App bei gesperrtem Bildschirm an. Fehler werden nicht mehr verschluckt: Stand je Konto in trk_<Konto> (zuletzt gesendet, letzter Fehler),
// angezeigt in der Kontenliste, Fehler zusätzlich im Applikationslog des Servers (applog/client, ohne Koordinaten).
import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:geolocator/geolocator.dart';

import 'accounts.dart';
import 'api.dart';
import 'monitor.dart';
import 'sys.dart';

const _minGapMs = 5000, _beatMs = 15000;

/// Einstellungen des Positionsstroms, gemeinsam für das Teilen im Hintergrund und die Einsatz-App (Issue #183).
/// interval: Abstand unter Android (dort laufen beide in getrennten Isolates mit eigenem Strom).
LocationSettings locSettings({Duration interval = const Duration(seconds: 5)}) {
  if (Platform.isAndroid) return AndroidSettings(accuracy: LocationAccuracy.best, distanceFilter: 0, intervalDuration: interval);
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

/// Stand des Teilens im Hintergrund je Konto (für die Kontenliste): at = zuletzt gesendet (ms), err/errAt = letzter Fehler.
class TrackState {
  TrackState(this.j);
  final Map<String, dynamic> j;
  int get at => (j['at'] as num?)?.toInt() ?? 0;
  String? get err => j['err'] as String?;
  int get errAt => (j['errAt'] as num?)?.toInt() ?? 0;
}

Future<TrackState> trackState(String id) async {
  try {
    final s = await prefs.getString('trk_$id');
    if (s != null) return TrackState(Map<String, dynamic>.from(jsonDecode(s) as Map));
  } catch (_) {}
  return TrackState({});
}

Future<void> _setTrack(String id, Map<String, dynamic> v) async {
  final j = (await trackState(id)).j..addAll(v);
  await prefs.setString('trk_$id', jsonEncode(j));
}

class Tracker {
  static StreamSubscription<Position>? _sub;
  static Position? _last;
  static final _sent = <String, int>{}; // Konto → letzte Meldung (ms)
  static final _off = <String>{}; // «Konto|Einsatz»: teilt nicht
  static final _logged = <String>{}; // schon ins Applikationslog gemeldete Fehler (je Konto und Text)
  static var _accs = <Account>[];
  static var _inc = <String, int>{};
  static bool _busy = false;

  /// Teilt die App gerade einen Standort? (Anzeige im Dienst)
  static bool get active => _sub != null && _accs.isNotEmpty;

  /// Nach jeder Abfrage, nach dem Annehmen und beim Wechsel in den Hintergrund: Konten im Einsatz bestimmen, Positionen starten
  /// oder beenden, Herzschlag senden.
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
    if (on.isEmpty) {
      stop();
      return;
    }
    if (await locLevel() == locNone) {
      stop();
      await _fail(on, 'Standort nicht zugelassen (Einstellungen der App)');
      return;
    }
    _sub ??= Geolocator.getPositionStream(locationSettings: locSettings()).listen((p) {
      _last = p;
      _push(p, _minGapMs);
    }, onError: (Object e) {
      // Strom beenden, damit der nächste sync neu startet (sonst blieb _sub gesetzt und es kam nichts mehr)
      stop();
      _fail(_accs, e is LocationServiceDisabledException ? 'Ortungsdienste ausgeschaltet' : (e is PermissionDeniedException ? 'Standort nicht zugelassen' : 'Standort nicht verfügbar ($e)'));
    }, cancelOnError: true);
    final p = _last;
    if (p != null) await _push(p, _beatMs);
  }

  /// Fehler merken (Anzeige) und einmal je Text und Konto ins Applikationslog des Servers melden.
  static Future<void> _fail(List<Account> l, String err, {bool log = true}) async {
    final now = DateTime.now().millisecondsSinceEpoch;
    for (final a in l) {
      await _setTrack(a.id, {'err': err, 'errAt': now});
      if (!log || !_logged.add('${a.id}|$err')) continue;
      unawaited(a.api
          .call('applog/client', body: {'lvl': 'warn', 'msg': 'Standort im Hintergrund: $err', 'page': 'app:${Platform.isIOS ? 'ios' : 'android'}', 'src': 'track.dart'}, timeout: const Duration(seconds: 10))
          .then((_) {}, onError: (_) {}));
    }
  }

  static Future<void> _push(Position p, int gap) async {
    if (_busy) return;
    _busy = true;
    try {
      final now = DateTime.now().millisecondsSinceEpoch;
      for (final a in List<Account>.from(_accs)) {
        if (await isForeground(a.id)) continue; // die Einsatz-App teilt selbst
        if (now - (_sent[a.id] ?? 0) < gap) continue;
        _sent[a.id] = now;
        final key = '${a.id}|${_inc[a.id]}';
        try {
          final r = await a.api.call('incident/location', body: {'lat': p.latitude, 'lon': p.longitude, 'water': 0, 'manual': 0}, timeout: const Duration(seconds: 15));
          if (r['shared'] == false) _off.add(key);
          await _setTrack(a.id, {'at': now, 'err': null});
        } on ApiError catch (e) {
          // Nur Antworten des Servers selbst (JSON mit error: kein laufender Einsatz, nicht mehr dabei, abgemeldet) lassen das Konto ruhen;
          // eine 4xx-Seite ohne JSON (Captive Portal, Proxy im Mobilnetz) gilt als vorübergehend (Issue #183)
          if (e.status >= 400 && e.status < 500 && e.data != null) {
            _off.add(key);
            await _setTrack(a.id, {'err': 'Server: ${e.message}', 'errAt': now});
          } else {
            await _fail([a], e.message, log: false); // Verbindungsfehler: nur anzeigen, das Log erreicht man ohnehin nicht
          }
        } catch (e) {
          await _fail([a], 'Senden fehlgeschlagen ($e)');
        }
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
