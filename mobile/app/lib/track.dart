// Standort im Einsatz teilen, auch bei gesperrtem Bildschirm oder geschlossener App (Feedback zu Issue #108). Ist die Einsatz-App eines
// Kontos im Vordergrund, teilt sie selbst (mit Erkennung «auf dem Gewässer»); sonst sendet die App die Position an incident/location, für
// jedes Konto, das am laufenden Einsatz teilnimmt (AccState.joined aus monitor.dart). Läuft dort, wo pollAll läuft: unter Android im
// Vordergrunddienst (service.dart), unter iOS in der App (im Hintergrund weiter, solange die Ortung läuft).
// Im Hintergrund braucht es «Immer zulassen» (ACCESS_BACKGROUND_LOCATION bzw. iOS «Immer»), sonst kommen nur Positionen bei offener App.
// Antwortet der Server mit shared:false (Einrückoption ohne Standort) oder lehnt er ab (Einsatz beendet), ruht das Konto für diesen Einsatz.
// Das Gerät kennt hier kein Gewässernetz: water ist immer 0, Fahrten mit dem Boot können also als Fahrt im Log erscheinen.
//
// Issue #183: geolocator hält unter iOS nur EINEN Positionsstrom; ein zweiter Aufruf von getPositionStream erhält den bestehenden, seine
// Einstellungen gelten nicht. Die Einsatz-App (inc/store.dart) nutzt deshalb dieselben Einstellungen (locSettings), sonst lief der Strom ohne
// allowBackgroundLocationUpdates, und iOS stoppte ihn beim Sperren. Fehler werden nicht mehr verschluckt: Stand je Konto in trk_<id>
// (LocStat, Anzeige in der Kontenliste), wichtige Fehler zusätzlich ins Applikationslog des Servers (applog/client, ohne Koordinaten).
import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

import 'accounts.dart';
import 'api.dart';
import 'monitor.dart';
import 'sys.dart';

const _minGapMs = 5000, _beatMs = 15000, _retryS = 10;

/// Einstellungen des Positionsstroms, gemeinsam für Einsatz-App und Hintergrund (unter iOS gibt es nur einen Strom, siehe oben).
LocationSettings locSettings({Duration android = const Duration(seconds: 5)}) {
  if (Platform.isAndroid) return AndroidSettings(accuracy: LocationAccuracy.best, distanceFilter: 0, intervalDuration: android);
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

/// Stand des Standort-Teilens eines Kontos (Kontenliste): letzte erfolgreiche Meldung, letzter Fehler, Fehlversuche seit dem letzten Erfolg.
class LocStat {
  LocStat(this.j);
  final Map<String, dynamic> j;
  int get ok => (j['ok'] as num?)?.toInt() ?? 0;
  String? get err => j['err'] as String?;
  int get errAt => (j['errAt'] as num?)?.toInt() ?? 0;
  int get fails => (j['fails'] as num?)?.toInt() ?? 0;
  int get failSince => (j['failSince'] as num?)?.toInt() ?? 0;
  int? get inc => (j['inc'] as num?)?.toInt();
  bool get off => j['off'] == true; // ruht für diesen Einsatz (Einrückoption ohne Standort oder Server lehnt ab)
  String? get offWhy => j['offWhy'] as String?;
}

Future<LocStat> locStat(String id) async {
  try {
    final s = await prefs.getString('trk_$id');
    if (s != null) return LocStat(Map<String, dynamic>.from(jsonDecode(s) as Map));
  } catch (e) {
    debugPrint('locStat $e');
  }
  return LocStat({});
}

Future<void> clearLocStat(String id) => prefs.remove('trk_$id');

/// Ergebnis einer Standortmeldung festhalten (auch aus der Einsatz-App). err null = gesendet. Nach einer längeren Störung (ab 3 Fehlversuchen
/// und 1 min) meldet der erste Erfolg die Dauer ins Applikationslog: Dann ist im Nachhinein sichtbar, warum Positionen fehlen.
Future<void> locResult(Account a, int? inc, {String? err}) async {
  final s = await locStat(a.id);
  final j = Map<String, dynamic>.from(s.j);
  final now = DateTime.now().millisecondsSinceEpoch;
  if (s.inc != inc) {
    j.clear();
    j['inc'] = inc;
  }
  if (err == null) {
    final f = (j['fails'] as num?)?.toInt() ?? 0, since = (j['failSince'] as num?)?.toInt() ?? 0;
    if (f >= 3 && since > 0 && now - since >= 60000) {
      unawaited(appLog(a, 'Standort konnte ${((now - since) / 60000).round()} min nicht gesendet werden ($f Fehlversuche, zuletzt: ${j['err'] ?? '?'})'));
    }
    j['ok'] = now;
    j.remove('fails');
    j.remove('failSince');
  } else {
    j['err'] = err;
    j['errAt'] = now;
    j['fails'] = ((j['fails'] as num?)?.toInt() ?? 0) + 1;
    j['failSince'] ??= now;
  }
  await prefs.setString('trk_${a.id}', jsonEncode(j));
}

Future<void> _locOff(Account a, int? inc, String why) async {
  final j = Map<String, dynamic>.from((await locStat(a.id)).j);
  if ((j['inc'] as num?)?.toInt() != inc) j.clear();
  j.addAll({'inc': inc, 'off': true, 'offWhy': why});
  await prefs.setString('trk_${a.id}', jsonEncode(j));
}

/// Fehler ins Applikationslog des Servers (Bereich client, höchstens 10 je Person in 10 min). Nie Koordinaten mitgeben.
/// Ohne Verbindung geht die Meldung verloren; die Dauer der Störung meldet locResult beim nächsten Erfolg.
Future<void> appLog(Account a, String msg, {bool warn = true}) async {
  try {
    await a.api.call('applog/client',
        body: {'msg': msg, 'lvl': warn ? 'warn' : 'err', 'page': 'app/standort', 'ver': '$appUa ${Platform.operatingSystem}'},
        timeout: const Duration(seconds: 10));
  } catch (e) {
    debugPrint('appLog $e');
  }
}

class Tracker {
  static StreamSubscription<Position>? _sub;
  static Position? _last;
  static final _sent = <String, int>{}; // Konto → letzte Meldung (ms)
  static final _off = <String>{}; // «Konto|Einsatz»: teilt nicht
  static final _noPerm = <String>{}; // «Konto|Einsatz»: fehlende Berechtigung schon gemeldet
  static var _accs = <Account>[];
  static var _inc = <String, int>{};
  static bool _busy = false;
  static Future<void>? _syncing;
  static Timer? _retry;

  /// Teilt die App gerade einen Standort? (Anzeige im Dienst)
  static bool get active => _sub != null && _accs.isNotEmpty;

  /// Nach jeder Abfrage, nach dem Annehmen und beim Wechsel in den Hintergrund: Konten im Einsatz bestimmen, Positionen starten oder
  /// beenden, Herzschlag senden. Gleichzeitige Aufrufe warten auf denselben Lauf (sonst entstünden zwei Abos).
  static Future<void> sync() => _syncing ??= _sync().whenComplete(() => _syncing = null);

  static Future<void> _sync() async {
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
      for (final a in on) {
        final k = '${a.id}|${inc[a.id]}';
        await locResult(a, inc[a.id], err: 'Standort für die App nicht freigegeben');
        if (_noPerm.add(k)) unawaited(appLog(a, 'Standort im Einsatz nicht geteilt: Berechtigung für den Standort fehlt'));
      }
      return;
    }
    _sub ??= Geolocator.getPositionStream(locationSettings: locSettings()).listen((p) {
      _last = p;
      _push(p, _minGapMs);
    }, onError: _onError);
    final p = _last;
    if (p != null) await _push(p, _beatMs);
  }

  /// Fehler der Ortung: festhalten, melden und den Strom neu starten (vorher blieb er still stehen, bis die App neu startete).
  static void _onError(Object e) {
    final msg = e is PermissionDeniedException
        ? 'Berechtigung für den Standort entzogen'
        : e is LocationServiceDisabledException
            ? 'Ortungsdienste ausgeschaltet'
            : 'Ortung fehlgeschlagen: $e';
    debugPrint('Tracker $msg');
    for (final a in List<Account>.from(_accs)) {
      unawaited(locResult(a, _inc[a.id], err: msg));
      unawaited(appLog(a, 'Standort im Einsatz: $msg', warn: false));
    }
    _sub?.cancel();
    _sub = null;
    _retry?.cancel();
    _retry = Timer(const Duration(seconds: _retryS), () => sync().catchError((Object x) => debugPrint('Tracker $x')));
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
        final inc = _inc[a.id], key = '${a.id}|$inc';
        try {
          final r = await a.api.call('incident/location', body: {'lat': p.latitude, 'lon': p.longitude, 'water': 0, 'manual': 0}, timeout: const Duration(seconds: 15));
          if (r['shared'] == false) {
            _off.add(key);
            await _locOff(a, inc, 'Einrückoption ohne Standort');
          } else {
            await locResult(a, inc);
          }
        } on ApiError catch (e) {
          // Nur eine Ablehnung des Servers selbst (JSON mit error: kein laufender Einsatz, nicht aufgeboten, abgemeldet) lässt das Konto ruhen.
          // Antworten ohne JSON (z. B. Captive Portal oder Proxy im Mobilnetz) gelten als vorübergehend, sonst ruhte es nach einer
          // Netzstörung für den ganzen Einsatz (Issue #183).
          if (e.data != null && e.status >= 400 && e.status < 500 && e.status != 408 && e.status != 429) {
            _off.add(key);
            await _locOff(a, inc, e.message);
            if (e.message != 'Kein aktiver Einsatz' && !e.unauth) unawaited(appLog(a, 'Standort teilen beendet: ${e.status} ${e.message}'));
          } else {
            await locResult(a, inc, err: e.message);
          }
        } catch (e) {
          await locResult(a, inc, err: '$e');
        }
      }
      _accs = _accs.where((a) => !_off.contains('${a.id}|${_inc[a.id]}')).toList();
      if (_accs.isEmpty) stop();
    } catch (e) {
      debugPrint('Tracker $e');
    } finally {
      _busy = false;
    }
  }

  static void stop() {
    _retry?.cancel();
    _retry = null;
    _sub?.cancel();
    _sub = null;
    _last = null;
  }
}
