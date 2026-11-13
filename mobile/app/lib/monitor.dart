// Überwachung aller aktiven Konten: fragt je Backend incident/state ab (wie das Polling der Web-App) und meldet neue Alarme,
// Nachrichten an mich und das Einsatzende als Benachrichtigung des Geräts. Läuft unter Android im Vordergrunddienst (service.dart),
// unter iOS nur, solange die App offen ist (im Hintergrund übernimmt Push, push.dart).
// Stand je Konto in SharedPreferencesAsync (ohne Zwischenspeicher, damit Dienst und Oberfläche dasselbe sehen).
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'accounts.dart';
import 'api.dart';
import 'notify.dart';
import 'track.dart';

final prefs = SharedPreferencesAsync();

const intervals = [10, 15, 30, 60];
Future<int> pollInterval() async => (await prefs.getInt('interval')) ?? 15;

/// Stand eines Kontos für die Anzeige in der Kontenliste.
class AccState {
  AccState(this.j);
  final Map<String, dynamic> j;
  int? get inc => (j['inc'] as num?)?.toInt();
  int get since => (j['since'] as num?)?.toInt() ?? 0;
  bool get init => j['init'] == true; // schon einmal abgefragt (vorhandene Nachrichten nicht melden)
  bool get joined => j['joined'] == true;
  bool get alarmed => j['alarmed'] == true;
  bool get blocked => j['blocked'] == true;
  String get title => j['title'] as String? ?? '';
  String get kind => j['kind'] as String? ?? '';
  String? get color => j['color'] as String?;
  String? get err => j['err'] as String?;
  int get at => (j['at'] as num?)?.toInt() ?? 0; // letzte erfolgreiche Abfrage (ms)
  List<String> get tags => ((j['tags'] as List?) ?? []).map((e) => '$e').toList(); // gemeldete Schlüssel (quittierte Alarme)
}

Future<AccState> accState(String id) async {
  try {
    final s = await prefs.getString('st_$id');
    if (s != null) return AccState(Map<String, dynamic>.from(jsonDecode(s) as Map));
  } catch (_) {}
  return AccState({});
}

Future<void> _saveState(String id, Map<String, dynamic> j) => prefs.setString('st_$id', jsonEncode(j));
Future<void> clearState(String id) => prefs.remove('st_$id');

/// Schlüssel als gemeldet merken (höchstens 300 je Konto), z. B. auch nach einer Push-Nachricht.
Future<bool> markTag(String id, String tag) async {
  final st = await accState(id);
  final t = st.tags;
  if (t.contains(tag)) return false;
  t.add(tag);
  st.j['tags'] = t.length > 300 ? t.sublist(t.length - 300) : t;
  await _saveState(id, st.j);
  return true;
}

/// Konto, dessen Web-App gerade im Vordergrund offen ist: dort meldet die Web-App selbst, die App bleibt still.
Future<void> setForeground(String? id) async {
  if (id == null) {
    await prefs.remove('fg');
  } else {
    await prefs.setString('fg', '$id|${DateTime.now().millisecondsSinceEpoch}');
  }
}

Future<bool> isForeground(String id) async {
  final s = await prefs.getString('fg');
  if (s == null) return false;
  final p = s.split('|');
  return p.length == 2 && p[0] == id && DateTime.now().millisecondsSinceEpoch - (int.tryParse(p[1]) ?? 0) < 15000;
}

/// Meldung zeigen, ausser schon gemeldet oder die Web-App des Kontos ist offen (dann nur merken). Alarme der Stufen crit und high
/// zeigt die App immer: Nur ihre Meldung umgeht «Nicht stören» und klingelt sicher (sicherer als ein Ton in der Seite).
/// Quittiert man den Alarm in der App (inc/store.dart), schliesst sie die Meldung.
/// kind/ksnd: Einsatztyp und Vorgabe des Tons für kritische Alarme (sounds.dart).
Future<void> report(Account a, String tag, String title, String body, String lvl, {int? kind, String? ksnd}) async {
  if (lvl == 'none') return;
  if (!await markTag(a.id, tag)) return;
  final alarm = tag.startsWith('i:') && (lvl == 'crit' || lvl == 'high');
  if (!alarm && await isForeground(a.id)) return;
  await showNote(a.id, tag, title, body, lvl, sub: a.label, kind: kind, ksnd: ksnd);
}

bool _forMe(Map m, int uid) {
  final r = m['recipients'];
  if (r == null || r == '') return true;
  try {
    final l = (r is String ? jsonDecode(r) : r) as List?;
    return l == null || l.map((e) => (e as num).toInt()).contains(uid);
  } catch (_) {
    return true;
  }
}

String _up(String s) => s.toUpperCase();

/// Ein Konto abfragen und Neues melden. Gibt den neuen Stand zurück.
Future<AccState> pollOne(Account a) async {
  final st = await accState(a.id);
  final j = Map<String, dynamic>.from(st.j);
  try {
    final r = await a.api.call('incident/state', query: {'since': '${st.since}'});
    j.remove('err');
    j['at'] = DateTime.now().millisecondsSinceEpoch;
    final inc = r['incident'] as Map?;
    j['blocked'] = r['blocked'] == 1;
    if (inc != null) {
      final id = (inc['id'] as num).toInt();
      final lvl = (r['level'] as String?) ?? 'crit';
      final me = r['me'] as Map?;
      final joined = me != null && me['left_at'] == null && me['mode'] != 'declined';
      final kind = (inc['kind_name'] as String?) ?? 'Einsatz';
      if (st.inc != id) {
        // neuer Einsatz: alarmieren, sofern aufgeboten (alarm) und noch nicht angenommen; früheren Stand vergessen
        j['alarmed'] = r['alarm'] != false;
        if (r['alarm'] != false && !joined) {
          await report(a, 'i:$id', _up(kind), '${inc['message'] ?? ''}', lvl, kind: (inc['kind_id'] as num?)?.toInt(), ksnd: (inc['kind'] as Map?)?['snd'] as String?);
        }
      }
      // Stufen je Meldungstyp und Einsatztyp (Issue #168); ältere Server ohne Angabe wie bisher
      final dl = lvl == 'none' || lvl == 'low' ? 'low' : 'normal';
      final ml = (r['msg_level'] as String?) ?? dl, el = (r['end_level'] as String?) ?? dl;
      var since = st.since;
      for (final m in (r['messages'] as List? ?? [])) {
        final mid = (m['id'] as num).toInt();
        if (mid > since) since = mid;
        if (st.init && joined && ml != 'none' && (m['from_user'] as num?)?.toInt() != a.uid && _forMe(m as Map, a.uid)) {
          await report(a, 'm:$mid', 'Nachricht von ${m['from_name'] ?? '?'}', '${m['text'] ?? ''}', ml);
        }
      }
      j.addAll({'inc': id, 'since': since, 'joined': joined, 'title': '${inc['message'] ?? ''}', 'kind': kind, 'color': inc['kind_color'], 'level': lvl, 'end_level': el});
    } else {
      if (st.inc != null && (st.alarmed || st.joined)) {
        final k = st.kind.isEmpty ? 'Einsatz' : st.kind;
        final l = (st.j['level'] as String?) ?? 'normal';
        final el = (st.j['end_level'] as String?) ?? (l == 'none' || l == 'low' ? 'low' : 'normal');
        if (el != 'none') await report(a, 'e:${st.inc}', '${_up(k)} BEENDET', '$k beendet.', el);
      }
      for (final k in ['inc', 'joined', 'alarmed', 'title', 'kind', 'color', 'level', 'end_level']) {
        j.remove(k);
      }
    }
    j['init'] = true;
  } on ApiError catch (e) {
    j['err'] = e.message;
    if (e.unauth) {
      await Accounts.update(a.id, (x) => x.loggedOut = true);
      await report(a, 'out:${a.token.hashCode}', 'Abgemeldet', 'Bei ${a.label} bitte neu anmelden, sonst kommen keine Alarme.', 'normal');
    }
  } catch (e) {
    j['err'] = '$e';
  }
  j['tags'] = (await accState(a.id)).tags; // report() hat sie inzwischen ergänzt
  await _saveState(a.id, j);
  return AccState(j);
}

/// Alle aktiven, angemeldeten Konten parallel abfragen. Rückgabe: Anzahl überwachter Konten.
/// track: Standort danach teilen (nicht in der Oberfläche unter Android, dort teilt nur der Vordergrunddienst).
Future<int> pollAll({bool track = true}) async {
  final l = (await Accounts.load()).where((a) => a.active && !a.loggedOut).toList();
  final r = await Future.wait(l.map(pollOne));
  final st = {for (var i = 0; i < l.length; i++) l[i].id: r[i]};
  if (!track) return l.length;
  try {
    await Tracker.sync(); // Standort im Einsatz teilen (track.dart)
  } catch (e) {
    debugPrint('Tracker.sync $e'); // nicht verschlucken (Issue #183): Stand je Konto für die Kontenliste
    for (final a in l.where((a) => (st[a.id]?.joined ?? false))) {
      await locResult(a, st[a.id]?.inc, err: 'Standort teilen fehlgeschlagen: $e');
    }
  }
  return l.length;
}

/// Teilnahme aus der Einsatz-App übernehmen (Android, Issue #179): Der Vordergrunddienst läuft mit Push nur im Einsatz und muss
/// deshalb gleich nach dem Annehmen davon wissen (serviceNeeded), nicht erst nach seiner nächsten Abfrage.
Future<void> setJoined(String id, int? inc, bool joined) async {
  final st = await accState(id);
  if (st.inc == inc && st.joined == joined) return;
  if (inc == null) return; // Einsatzende meldet weiterhin die Überwachung (pollOne)
  final j = Map<String, dynamic>.from(st.j);
  if (st.inc != inc) j['alarmed'] = true; // Einsatz zuerst in der Einsatz-App gesehen: das Ende meldet pollOne trotzdem
  j['inc'] = inc;
  j['joined'] = joined;
  await _saveState(id, j);
}
