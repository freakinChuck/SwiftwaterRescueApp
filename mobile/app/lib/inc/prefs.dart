// Seite «Einstellungen» der nativen Einsatz-App (Gegenstück zu prefs/prefsDraw, absMine, sessMine und aiPrefs in web/index.html,
// absRows/absDialog/pwDialog in web/common.js): Konto (Profilbild, Passwort, angemeldete Apps, Abmelden), Alarmierung (Einsatzfähigkeit
// mit Abwesenheiten, Übungsmodus, Ton bei kritischem Alarm je Einsatztyp (Issue #171, nur nativ), Standort und Benachrichtigungen über die
// Systemeinstellungen), Anzeige, AI-Assistent, Rechtliches.
import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart' hide Text;
import 'package:flutter/material.dart' as m show Text;
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../beacon.dart';
import '../notify.dart';
import '../push.dart';
import '../sounds.dart';
import '../sys.dart';
import '../org.dart';
import '../version.dart';
import 'ctx.dart';
import 'docs.dart';
import 'hydro.dart';
import 'skills.dart';
import 'store.dart';
import 'util.dart';
import 'veh.dart';
import 'voice.dart';
import '../i18n.dart';

/// Arten der Abwesenheit (ABS_KIND)
const _absKind = {'abs': 'Abwesend', 'sus': 'Einsatzfähigkeit ausgesetzt'};

/// Modi des AI-Assistenten (AI_MN)
const _aiMn = {'off': 'Aus', 'cmd': 'Auf Abruf (Knopf ✨)', 'comp': 'Begleitend (Vorschlag schon beim Öffnen vorbereitet)'};
const _aiOrder = ['off', 'cmd', 'comp'];

String _p2(int n) => n.toString().padLeft(2, '0');

/// Datum mit Wochentag (absDT), z. B. «Sa., 07.11.26, 14:30»
String _absDT(num ts) {
  final d = tsDate(ts);
  const wd = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
  return '${wd[d.weekday - 1]}., ${_p2(d.day)}.${_p2(d.month)}.${_p2(d.year % 100)}, ${_p2(d.hour)}:${_p2(d.minute)}';
}

/// «bis …» bzw. «bis auf Weiteres» (absUntil)
String _absUntil(J a) {
  final u = jn(a['until']) ?? jn(a['until_at']);
  return u != null ? 'bis ${_absDT(u)}' : (js(a['kind']) == 'rule' ? '' : 'bis auf Weiteres');
}


// ---------- Regelmässige Einsatzzeiten (Issue #180, wie ruleTxt in common.js) ----------
const _ruleMode = {'on': 'Einsatzzeit', 'off': 'Regelmässig ausgesetzt'};
const _wd = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
String _hm(int m) => '${_p2(m ~/ 60)}:${_p2(m % 60)}';
String _ruleDays(int d) => d == 127
    ? 'täglich'
    : d == 31
        ? 'Mo–Fr'
        : d == 96
            ? 'Sa, So'
            : [for (var i = 0; i < 7; i++) if (d >> i & 1 == 1) _wd[i]].join(', ');
String _ruleTxt(J r) {
  final f = ji(r['t_from']) ?? 0, t = ji(r['t_to']) ?? 0;
  return '${_ruleDays(ji(r['days']) ?? 0)}, ${f == t ? 'ganzer Tag' : '${_hm(f)}–${_hm(t)}${t < f ? ' (über Mitternacht)' : ''}'}';
}

class PrefsPage extends StatefulWidget {
  const PrefsPage({super.key, required this.s, required this.onLogout});
  final IncStore s;
  final Future<void> Function() onLogout;
  @override
  State<PrefsPage> createState() => _PrefsPageState();
}

class _PrefsPageState extends State<PrefsPage> {
  IncStore get s => widget.s;
  List<J>? _abs; // null = wird geladen
  List<J>? _rules; // regelmässige Einsatzzeiten (Issue #180), null = nicht verfügbar
  bool _absErr = false;
  List<J> _sess = [];
  int? _loc; // Stufe der Standortberechtigung
  bool _busy = false;
  Map<String, String> _snd = {}; // eigene Töne je Einsatztyp (sounds.dart)
  String? _probe; // Typ-ID, deren Probe gerade läuft (Android)
  Timer? _probeT;
  String _taLvl = 'crit'; // persönlicher Testalarm (Issue #218): gewählte Stufe (pro Gerät), laufender Test mit Zeitpunkt
  J? _ta;
  Timer? _taT;

  @override
  void initState() {
    super.initState();
    _loadAbs();
    _loadSess();
    _loadLoc();
    _loadSnd();
    s.addListener(_onAlarm);
    SharedPreferences.getInstance().then((p) {
      final v = p.getString('talvl');
      if (mounted && v != null && _taTxt.containsKey(v)) setState(() => _taLvl = v);
    });
  }

  @override
  void dispose() {
    s.removeListener(_onAlarm);
    if (_probe != null) alarmStop();
    _probeT?.cancel();
    _taT?.cancel();
    super.dispose();
  }

  /// Ein Alarm (auch der Testalarm) steht auf der Karte: Profil schliessen, damit er sichtbar ist
  void _onAlarm() {
    if (s.alarm == null || !mounted) return;
    if (_ta != null && s.alarm!.key == 't:${_ta!['id']}') _ta = null;
    if (ModalRoute.of(context)?.isCurrent == true) Navigator.of(context).maybePop();
  }

  static const _taTxt = {'crit': 'Kritisch', 'high': 'Hoch', 'normal': 'Normal', 'low': 'Leise'};

  Future<void> _testAlarm() async {
    try {
      if (_ta != null) {
        await s.api.call('me/testalarm', body: {'lvl': ''});
        _ta = null;
        s.toast('Testalarm abgebrochen', 'info');
      } else {
        final r = await s.api.call('me/testalarm', body: {'lvl': _taLvl});
        _ta = {'id': r['id'], 'at': r['at'], 'lvl': _taLvl};
        s.toast(s.demo ? 'Testalarm in 30 s (im Demo nur bei offener App).' : 'Testalarm in 30 s. Jetzt die App schliessen oder den Bildschirm sperren.', 'ok');
        // die Überwachung bzw. die offene App fragt ab; damit er pünktlich kommt, kurz nach dem Zeitpunkt selbst abfragen
        Timer(const Duration(seconds: 31), () => s.poll());
      }
    } catch (e) {
      s.fail(e);
    }
    _taTick();
  }

  void _taTick() {
    _taT?.cancel();
    if (_ta != null && (jn(_ta!['at']) ?? 0) * 1000 < DateTime.now().millisecondsSinceEpoch - 5000) _ta = null;
    if (mounted) setState(() {});
    if (_ta != null) _taT = Timer(const Duration(seconds: 1), _taTick);
  }

  List<Widget> _testAlarmUi() {
    final left = _ta == null ? 0 : ((jn(_ta!['at']) ?? 0) - DateTime.now().millisecondsSinceEpoch / 1000).ceil().clamp(0, 60);
    return [
      _h5('Testalarm'),
      const Mut('Löst nach 30 Sekunden einen Alarm der gewählten Stufe nur für dich aus, ohne Einsatz. Danach die App schliessen oder den Bildschirm sperren, um Ton und Benachrichtigung zu prüfen.', size: 12),
      Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            DropdownButton<String>(
                value: _taLvl,
                items: [for (final e in _taTxt.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
                onChanged: _ta != null
                    ? null
                    : (v) {
                        if (v == null) return;
                        setState(() => _taLvl = v);
                        SharedPreferences.getInstance().then((p) => p.setString('talvl', v));
                      }),
            OutlinedButton(onPressed: _testAlarm, child: Text(_ta == null ? 'Testalarm in 30 s' : 'Abbrechen')),
            if (_ta != null) Mut(left > 0 ? 'Testalarm «${_taTxt[_ta!['lvl']]}» in $left s' : 'Testalarm ausgelöst'),
          ])),
    ];
  }

  Future<void> _loadSnd() async {
    final m = await sndChoice(s.acc.id);
    if (mounted) setState(() => _snd = m);
  }

  Future<void> _setSnd(int kind, String? k) async {
    await setSndChoice(s.acc.id, kind, k);
    await _loadSnd();
    unawaited(syncPush()); // iOS: der Server nennt APNs den Ton
  }

  /// Probe: Android als Wecker wie beim echten Alarm (8 s, erneutes Antippen stoppt), iOS als kritische Meldung (einmal).
  Future<void> _probeSnd(String id, String k) async {
    final was = _probe;
    _probeT?.cancel();
    if (was != null) {
      await alarmStop();
      setState(() => _probe = null);
      if (was == id) return;
    }
    if (Platform.isAndroid) {
      await alarmStart(path: await sndPath(k));
      setState(() => _probe = id);
      _probeT = Timer(const Duration(seconds: 8), () {
        alarmStop();
        if (mounted) setState(() => _probe = null);
      });
    } else {
      await showNote(s.acc.id, 'snd:probe', 'Probe: ${sndName(k)}', 'So klingt ein kritischer Alarm dieses Typs.', 'crit', sub: s.acc.label, snd: k);
    }
  }

  /// Ton bei kritischem Alarm je Einsatztyp, der kritisch alarmieren kann (Alarmstufe kritisch oder mit Übungsmodus)
  List<Widget> _sounds(J me) {
    final ks = jl(me['kinds']).map(jm).where((k) => js(k['alarm']) == 'crit' || jb(k['mode_up'])).toList();
    if (ks.isEmpty) return [const Mut('Kein Einsatztyp alarmiert kritisch.')];
    final items = <DropdownMenuItem<String>>[];
    for (final g in sndGroups.entries) {
      items.add(DropdownMenuItem(enabled: false, value: '#${g.key}', child: Text(g.value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13))));
      items.addAll(alarmSounds.where((x) => x.group == g.key).map((x) => DropdownMenuItem(value: x.key, child: Padding(padding: const EdgeInsets.only(left: 10), child: Text(x.name)))));
    }
    return [
      const Mut('Gilt für dieses Backend auf diesem Gerät. Android spielt ihn als Wecker in Schleife, iOS einmal (höchstens 30 s).'),
      ...ks.map((k) {
        final id = '${ji(k['id'])}', def = sndDef(k['snd'] as String?), own = _snd[id], eff = sndKnown(own) || own == sndSystem ? own! : def;
        return Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(children: [
              Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(js(k['name']), style: const TextStyle(fontWeight: FontWeight.bold)),
                DropdownButton<String>(
                    isExpanded: true,
                    value: own == null ? '' : (own == sndSystem || sndKnown(own) ? own : ''),
                    items: [
                      DropdownMenuItem(value: '', child: Text('Vorgabe: ${sndName(def)}')),
                      const DropdownMenuItem(value: sndSystem, child: Text('Ton des Systems')),
                      ...items,
                    ],
                    onChanged: (v) {
                      if (v == null || v.startsWith('#')) return;
                      _setSnd(ji(k['id'])!, v.isEmpty ? null : v);
                    }),
              ])),
              const SizedBox(width: 8),
              smallBtn(_probe == id ? '■ Stopp' : '▶ Probe', () => _probeSnd(id, eff)),
            ]));
      }),
    ];
  }

  Future<void> _loadAbs() async {
    try {
      final r = jl((await s.api.call('absences'))['data']);
      if (mounted) setState(() => (_abs = r, _absErr = false));
    } catch (_) {
      if (mounted) setState(() => (_abs = [], _absErr = true));
    }
    try {
      final r = jl((await s.api.call('avail/rules'))['rules']);
      if (mounted) setState(() => _rules = r);
    } catch (_) {
      if (mounted) setState(() => _rules = null); // älterer Server
    }
  }

  Future<void> _loadSess() async {
    try {
      final r = jl((await s.api.call('me/sessions'))['data']);
      if (mounted) setState(() => _sess = r);
    } catch (_) {}
  }

  Future<void> _loadLoc() async {
    final l = await locLevel();
    if (mounted) setState(() => _loc = l);
  }

  /// eigene Einstellung speichern (me/settings ändert nur übergebene Werte), danach me neu laden
  Future<void> _set(J body, {bool poll = false}) async {
    try {
      await s.api.call('me/settings', body: body);
      await s.reloadMe();
      if (poll) await s.poll();
    } catch (e) {
      s.fail(e);
      await s.reloadMe();
    }
  }

  // ---------- Konto ----------
  Future<void> _photo() async {
    final src = await showModalBottomSheet<ImageSource>(
        context: context,
        builder: (c) => SafeArea(
                child: Column(mainAxisSize: MainAxisSize.min, children: [
              ListTile(leading: const Icon(Icons.photo_library), title: const Text('Bild auswählen'), onTap: () => Navigator.pop(c, ImageSource.gallery)),
              ListTile(leading: const Icon(Icons.photo_camera), title: const Text('Foto aufnehmen'), onTap: () => Navigator.pop(c, ImageSource.camera)),
            ])));
    if (src == null) return;
    try {
      final f = await ImagePicker().pickImage(source: src, maxWidth: 800, maxHeight: 800, imageQuality: 90);
      if (f == null) return;
      final d = await _square96(await f.readAsBytes());
      await s.api.call('me/photo', body: {'photo': d});
      await s.reloadMe();
      s.toast('Profilbild gespeichert');
    } catch (e) {
      s.fail(e);
    }
  }

  /// wie die Web-App: Mitte quadratisch ausschneiden, auf 96 × 96 px verkleinern (dart:ui kann nur PNG schreiben)
  Future<String> _square96(Uint8List bytes) async {
    final codec = await ui.instantiateImageCodec(bytes);
    final img = (await codec.getNextFrame()).image;
    final w = img.width.toDouble(), h = img.height.toDouble(), m = w < h ? w : h;
    final rec = ui.PictureRecorder(), c = Canvas(rec);
    c.drawImageRect(img, Rect.fromLTWH((w - m) / 2, (h - m) / 2, m, m), const Rect.fromLTWH(0, 0, 96, 96), Paint()..filterQuality = FilterQuality.high);
    final out = await rec.endRecording().toImage(96, 96);
    final png = await out.toByteData(format: ui.ImageByteFormat.png);
    if (png == null) throw Exception('Bild konnte nicht verarbeitet werden');
    return 'data:image/png;base64,${base64Encode(png.buffer.asUint8List())}';
  }

  Future<void> _pw() async {
    final o = TextEditingController(), n = TextEditingController(), r = TextEditingController();
    String? err;
    var busy = false, others = true; // Issue #270: andere Geräte und Apps abmelden (Standard)
    await showDialog<void>(
        context: context,
        builder: (d) => StatefulBuilder(
            builder: (d2, ss) => AlertDialog(
                    title: const Text('Passwort ändern'),
                    scrollable: true,
                    content: SizedBox(
                        width: 420,
                        child: Column(mainAxisSize: MainAxisSize.min, children: [
                          TextField(controller: o, obscureText: true, autofillHints: const [AutofillHints.password], decoration: InputDecoration(labelText: trn('Aktuelles Passwort'))),
                          TextField(controller: n, obscureText: true, autofillHints: const [AutofillHints.newPassword], decoration: InputDecoration(labelText: trn('Neues Passwort'))),
                          TextField(controller: r, obscureText: true, decoration: InputDecoration(labelText: trn('Neues Passwort wiederholen'))),
                          CheckboxListTile(value: others, contentPadding: EdgeInsets.zero, controlAffinity: ListTileControlAffinity.leading, title: const Text('Andere Geräte und Apps abmelden'), onChanged: (v) => ss(() => others = v ?? true)),
                          if (err != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(err!, style: const TextStyle(color: accRed))),
                        ])),
                    actions: [
                      TextButton(onPressed: () => Navigator.pop(d), child: const Text('Abbrechen')),
                      FilledButton(
                          onPressed: busy
                              ? null
                              : () async {
                                  if (n.text != r.text) return ss(() => err = 'Die neuen Passwörter stimmen nicht überein.');
                                  ss(() => (busy = true, err = null));
                                  try {
                                    await s.api.call('me/password', body: {'old': o.text, 'new': n.text, 'keep': others ? 0 : 1});
                                    if (d.mounted) Navigator.pop(d);
                                    s.toast('Passwort geändert.');
                                  } catch (e) {
                                    ss(() => (busy = false, err = '$e'));
                                  }
                                },
                          child: const Text('Speichern')),
                    ])));
  }

  // Direktanruf (Issue #44): eigene Telefonnummer, nur wenn die Verwaltung ihn eingeschaltet hat (me.phone_cfg); self 0 = nur anzeigen
  bool _telOn(J me) {
    final c = me['phone_cfg'];
    return !s.demo && c is Map && (ji(c['show']) == 1 || ji(c['call']) == 1) && s.feat('call');
  }

  List<Widget> _tel(J me) {
    final c = me['phone_cfg'] as Map, self = ji(c['self']) == 1, n = js(me['phone']);
    final w = js(c['who']) == 'lead' ? 'die Einsatzleitung' : 'alle, die den Einsatz angenommen haben,';
    return [
      _h5('Telefonnummer'),
      Row(children: [
        Expanded(child: n.isEmpty ? const Mut('keine hinterlegt') : Text(n)),
        if (self) smallBtn(n.isEmpty ? 'Erfassen' : 'Ändern', _telEdit),
      ]),
      Mut('Im laufenden Einsatz sehen $w sie bei den Einsatzkräften${ji(c['call']) == 1 ? ' und können dich direkt anrufen' : ''}.${self ? '' : ' Ändern über die Verwaltung.'}', size: 12),
    ];
  }

  Future<void> _telEdit() async {
    final t = TextEditingController(text: js(s.me?['phone']));
    final v = await showDialog<String>(
        context: context,
        builder: (d) => AlertDialog(
                title: const Text('Telefonnummer'),
                content: SizedBox(
                    width: 420,
                    child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                      TextField(
                          controller: t,
                          keyboardType: TextInputType.phone,
                          autofillHints: const [AutofillHints.telephoneNumber],
                          maxLength: 30,
                          decoration: InputDecoration(labelText: trn('Für den Direktanruf im Einsatz'), hintText: trn('+41 79 123 45 67'))),
                      const Mut('Leer lassen, wenn du nicht angerufen werden willst.', size: 12),
                    ])),
                actions: [
                  TextButton(onPressed: () => Navigator.pop(d), child: const Text('Abbrechen')),
                  FilledButton(onPressed: () => Navigator.pop(d, t.text.trim()), child: const Text('Speichern')),
                ]));
    if (v == null) return;
    await _set({'phone': v});
  }

  Future<void> _sessRemove(J r) async {
    if (!await confirmDlg(context, 'App auf diesem Gerät abmelden? Sie erhält danach keine Alarme mehr.', ok: 'Abmelden', danger: true)) return;
    try {
      await s.api.call('me/session/remove', body: {'id': r['id']});
      s.toast('App abgemeldet');
      _loadSess();
    } catch (e) {
      s.fail(e);
    }
  }

  Future<void> _logout() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await widget.onLogout();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ---------- Abwesenheiten ----------
  Future<void> _absDone() async {
    await s.reloadMe();
    await _loadAbs();
    await s.poll();
  }

  Future<void> _absRemove(J a) async {
    final now = jb(a['now']);
    if (!await confirmDlg(context, now ? 'Eintrag jetzt beenden?' : 'Eintrag entfernen?', ok: now ? 'Beenden' : 'Entfernen', danger: true)) return;
    try {
      await s.api.call('absence/remove', body: {'id': a['id']});
      s.toast(now ? 'Beendet.' : 'Entfernt.');
      await _absDone();
    } catch (e) {
      s.fail(e);
    }
  }

  /// Erfassen bzw. ändern (absDialog): a = bestehender Eintrag oder null
  Future<void> _absDialog(J? a) async {
    final nowTs = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    var kind = js(a?['kind']).isEmpty ? 'abs' : js(a!['kind']);
    var from = tsDate(jn(a?['from_at']) ?? nowTs);
    DateTime? until = a?['until_at'] != null ? tsDate(jn(a!['until_at'])!) : null;
    final note = TextEditingController(text: js(a?['note']));
    String? err;
    var busy = false;
    String fmt(DateTime d) => _absDT(d.millisecondsSinceEpoch / 1000);
    Future<DateTime?> pick(BuildContext c, DateTime init) async {
      final dt = await showDatePicker(context: c, initialDate: init, firstDate: DateTime.now().subtract(const Duration(days: 366)), lastDate: DateTime.now().add(const Duration(days: 2 * 366)));
      if (dt == null || !c.mounted) return null;
      final t = await showTimePicker(context: c, initialTime: TimeOfDay.fromDateTime(init));
      if (t == null) return null;
      return DateTime(dt.year, dt.month, dt.day, t.hour, t.minute);
    }

    await showDialog<void>(
        context: context,
        builder: (d) => StatefulBuilder(builder: (d2, ss) {
              void quick(String q) {
                final fr = from;
                if (q == 'x') {
                  until = null;
                } else if (q == 'm') {
                  final n = DateTime.now(), mo = DateTime(n.year, n.month, n.day + 1, 6);
                  final min = fr.add(const Duration(hours: 1));
                  until = mo.isAfter(min) ? mo : min;
                } else {
                  until = fr.add(const Duration(days: 7));
                }
                ss(() {});
              }

              return AlertDialog(
                  title: Text(a != null ? 'Eintrag ändern' : 'Abwesenheit erfassen'),
                  scrollable: true,
                  content: SizedBox(
                      width: 420,
                      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
                        DropdownButtonFormField<String>(
                            initialValue: kind,
                            decoration: InputDecoration(labelText: trn('Art')),
                            items: _absKind.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value))).toList(),
                            onChanged: (v) => kind = v ?? 'abs'),
                        const SizedBox(height: 6),
                        const Mut('Beides wirkt gleich: nicht aufgeboten und kein Alarm. Laufende Einsätze bleiben sichtbar, freiwillig beitreten ist möglich.'),
                        const SizedBox(height: 6),
                        ListTile(
                            contentPadding: EdgeInsets.zero,
                            title: const Text('Von'),
                            subtitle: Text(fmt(from)),
                            trailing: const Icon(Icons.edit_calendar),
                            onTap: () async {
                              final v = await pick(d2, from);
                              if (v != null) ss(() => from = v);
                            }),
                        ListTile(
                            contentPadding: EdgeInsets.zero,
                            title: const Text('Bis (leer = bis auf Weiteres)'),
                            subtitle: Text(until == null ? 'bis auf Weiteres' : fmt(until!)),
                            trailing: const Icon(Icons.edit_calendar),
                            onTap: () async {
                              final v = await pick(d2, until ?? from.add(const Duration(days: 1)));
                              if (v != null) ss(() => until = v);
                            }),
                        Wrap(spacing: 6, runSpacing: 4, children: [
                          smallBtn('Bis morgen 06:00', () => quick('m')),
                          smallBtn('1 Woche', () => quick('w')),
                          smallBtn('Bis auf Weiteres', () => quick('x')),
                        ]),
                        TextField(controller: note, maxLength: 200, decoration: InputDecoration(labelText: trn('Bemerkung (optional)'), hintText: trn('z. B. Ferien, Krankheit'))),
                        if (err != null) Text(err!, style: const TextStyle(color: accRed)),
                      ])),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(d), child: const Text('Abbrechen')),
                    FilledButton(
                        onPressed: busy
                            ? null
                            : () async {
                                ss(() => (busy = true, err = null));
                                try {
                                  await s.api.call('absence/save', body: {
                                    if (a != null) 'id': a['id'],
                                    'from': from.millisecondsSinceEpoch ~/ 1000,
                                    'until': until == null ? null : until!.millisecondsSinceEpoch ~/ 1000,
                                    'kind': kind,
                                    'note': note.text,
                                  });
                                  if (d.mounted) Navigator.pop(d);
                                  s.toast('Gespeichert.');
                                  await _absDone();
                                } catch (e) {
                                  ss(() => (busy = false, err = '$e'));
                                }
                              },
                        child: const Text('Speichern')),
                  ]);
            }));
  }

  // ---------- Regelmässige Einsatzzeiten (Issue #180, ruleDialog) ----------
  Future<void> _ruleRemove(J r) async {
    if (!await confirmDlg(context, 'Regel entfernen?', ok: 'Entfernen', danger: true)) return;
    try {
      await s.api.call('avail/rule/remove', body: {'id': r['id']});
      s.toast('Entfernt.');
      await _absDone();
    } catch (e) {
      s.fail(e);
    }
  }

  Future<void> _ruleDialog(J? r) async {
    var mode = js(r?['mode']).isEmpty ? 'off' : js(r!['mode']);
    var days = ji(r?['days']) ?? 31;
    var from = ji(r?['t_from']) ?? 7 * 60, to = ji(r?['t_to']) ?? 17 * 60;
    final note = TextEditingController(text: js(r?['note']));
    String? err;
    var busy = false;
    await showDialog<void>(
        context: context,
        builder: (d) => StatefulBuilder(builder: (d2, ss) {
              Future<void> pick(bool isFrom) async {
                final m = isFrom ? from : to;
                final t = await showTimePicker(context: d2, initialTime: TimeOfDay(hour: m ~/ 60, minute: m % 60));
                if (t == null) return;
                ss(() {
                  if (isFrom) {
                    from = t.hour * 60 + t.minute;
                  } else {
                    to = t.hour * 60 + t.minute;
                  }
                });
              }

              return AlertDialog(
                  title: Text(r != null ? 'Regel ändern' : 'Regelmässige Einsatzzeit erfassen'),
                  scrollable: true,
                  content: SizedBox(
                      width: 420,
                      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
                        DropdownButtonFormField<String>(
                            initialValue: mode,
                            decoration: InputDecoration(labelText: trn('Art')),
                            items: _ruleMode.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value))).toList(),
                            onChanged: (v) => mode = v ?? 'off'),
                        const SizedBox(height: 6),
                        const Mut(
                            'Einsatzzeit: Hast du solche Regeln, wirst du nur in diesen Zeiten alarmiert. Regelmässig ausgesetzt: in diesen Zeiten kein Alarm (z. B. Arbeit). Wirkt wie eine Abwesenheit; laufende Einsätze bleiben sichtbar.'),
                        const SizedBox(height: 8),
                        const Mut('Wochentage'),
                        Wrap(spacing: 4, runSpacing: 4, children: [
                          for (var i = 0; i < 7; i++)
                            FilterChip(label: Text(_wd[i]), selected: days >> i & 1 == 1, showCheckmark: false, onSelected: (_) => ss(() => days ^= 1 << i)),
                        ]),
                        const SizedBox(height: 4),
                        Wrap(spacing: 6, runSpacing: 4, children: [
                          smallBtn('Mo–Fr', () => ss(() => days = 31)),
                          smallBtn('Sa, So', () => ss(() => days = 96)),
                          smallBtn('Täglich', () => ss(() => days = 127)),
                        ]),
                        Row(children: [
                          Expanded(
                              child: ListTile(
                                  contentPadding: EdgeInsets.zero, title: const Text('Von'), subtitle: Text(_hm(from)), trailing: const Icon(Icons.schedule), onTap: () => pick(true))),
                          const SizedBox(width: 8),
                          Expanded(
                              child: ListTile(
                                  contentPadding: EdgeInsets.zero, title: const Text('Bis'), subtitle: Text(_hm(to)), trailing: const Icon(Icons.schedule), onTap: () => pick(false))),
                        ]),
                        const Mut('«Bis» vor «Von» = über Mitternacht (zählt zum gewählten Tag), gleiche Zeit = ganzer Tag.', size: 12),
                        TextField(controller: note, maxLength: 200, decoration: InputDecoration(labelText: trn('Bemerkung (optional)'), hintText: trn('z. B. Arbeit, Pikett'))),
                        if (err != null) Text(err!, style: const TextStyle(color: accRed)),
                      ])),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(d), child: const Text('Abbrechen')),
                    FilledButton(
                        onPressed: busy
                            ? null
                            : () async {
                                ss(() => (busy = true, err = null));
                                try {
                                  await s.api.call('avail/rule/save', body: {
                                    if (r != null) 'id': r['id'],
                                    'mode': mode,
                                    'days': days,
                                    'from': from,
                                    'to': to,
                                    'note': note.text,
                                  });
                                  if (d.mounted) Navigator.pop(d);
                                  s.toast('Gespeichert.');
                                  await _absDone();
                                } catch (e) {
                                  ss(() => (busy = false, err = '$e'));
                                }
                              },
                        child: const Text('Speichern')),
                  ]);
            }));
  }

  // ---------- Standort ----------
  String get _locText => switch (_loc) {
        null => 'Wird geprüft …',
        locAlways => '✓ Immer zugelassen: Der Standort wird im Einsatz auch bei gesperrtem Bildschirm geteilt.',
        locInUse => 'Nur während der Nutzung zugelassen: Bei gesperrtem Bildschirm oder geschlossener App wird der Standort nicht geteilt.',
        _ => 'Nicht zugelassen: Die App kann den Standort weder anzeigen noch teilen.',
      };

  Future<void> _locAlways() async {
    await requestBackgroundLocation();
    await _loadLoc();
  }

  // ---------- Aufbau ----------
  Widget _h5(String t) => Padding(padding: const EdgeInsets.only(top: 12, bottom: 4), child: Text(t, style: const TextStyle(fontWeight: FontWeight.bold)));

  Widget _absChip(J? a) {
    if (a == null) return const Chip2('✓ einsatzfähig', color: okGreen);
    return Chip2('⏸ ${absWord(a)} ${_absUntil(a)}${js(a['note']).isNotEmpty ? ' – ${a['note']}' : ''}'.trim(), color: accOrange);
  }

  Widget _konto(J me) {
    final un = jl(me['uroles']).map((r) => js(r['name'])).where((x) => x.isNotEmpty).join(', ');
    final groups = jl(me['groups']);
    return MCard(title: 'Konto', children: [
      Row(children: [
        avatar(me, r: 26, border: Colors.transparent),
        const SizedBox(width: 12),
        Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(js(me['name']), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          Mut('${js(me['username'])} · ${un.isEmpty ? 'keine Benutzerrolle' : un}'),
        ])),
      ]),
      if (groups.isNotEmpty) ...[
        _h5('Meine Einsatzgruppen'),
        Wrap(spacing: 6, runSpacing: 4, children: groups.map((g) => Chip2(js(g['name']), color: hexColor(g['color']))).toList()),
      ],
      Padding(
          padding: const EdgeInsets.only(top: 10),
          child: Wrap(spacing: 8, runSpacing: 6, children: [
            OutlinedButton(onPressed: _photo, child: const Text('Profilbild ändern')),
            if (!s.demo) OutlinedButton(onPressed: _pw, child: const Text('Passwort ändern')),
            OutlinedButton(onPressed: _busy ? null : _logout, child: Text(s.demo ? 'Demo beenden' : 'Abmelden')), // Demo-Modus (Issue #74)
            OutlinedButton(
                onPressed: () => Navigator.of(context, rootNavigator: true).popUntil((r) => r.isFirst), child: const Text('Konten / Server wechseln')),
          ])),
      if (_telOn(me)) ..._tel(me),
      if (_sess.isNotEmpty) ...[
        _h5('Angemeldete Apps'),
        ..._sess.map((r) => Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(children: [
              Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text.rich(TextSpan(children: [
                  TextSpan(text: js(r['device']), style: const TextStyle(fontWeight: FontWeight.bold)),
                  if (js(r['platform']).isNotEmpty) TextSpan(text: ' · ${const {'android': 'Android', 'ios': 'iOS'}[r['platform']] ?? r['platform']}'),
                  if (jb(r['cur'])) const TextSpan(text: ' · dieses Gerät', style: TextStyle(fontStyle: FontStyle.italic)),
                ])),
                Mut('angemeldet ${fmtDT(jn(r['created']))} · zuletzt ${fmtDT(jn(r['last_seen']))}', size: 12),
              ])),
              if (!jb(r['cur'])) smallBtn('Entfernen', () => _sessRemove(r)),
            ]))),
      ],
    ]);
  }

  Widget _alarm(J me) {
    final abs = _abs;
    return MCard(title: 'Alarmierung', children: [
      Row(children: [const Expanded(child: Text('Einsatzfähigkeit', style: TextStyle(fontWeight: FontWeight.bold))), Flexible(child: _absChip(me['absent'] is Map ? jm(me['absent']) : null))]),
      const SizedBox(height: 4),
      const Mut('Abwesend oder Einsatzfähigkeit ausgesetzt: kein Aufgebot und kein Alarm. Laufende Einsätze bleiben sichtbar.'),
      const SizedBox(height: 6),
      if (abs == null)
        const Mut('Wird geladen …')
      else if (_absErr)
        const Mut('Abwesenheiten nicht verfügbar (keine Verbindung).')
      else ...[
        if (abs.isEmpty) const Mut('Keine Einträge.'),
        ...abs.map((a) => Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(children: [
              Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(_absKind[a['kind']] ?? _absKind['abs']!, style: const TextStyle(fontWeight: FontWeight.bold)),
                Mut('${jb(a['now']) ? 'seit' : 'ab'} ${_absDT(jn(a['from_at']) ?? 0)}, ${_absUntil(a)}${js(a['note']).isNotEmpty ? ' · ${a['note']}' : ''}', size: 12),
              ])),
              smallBtn('Ändern', () => _absDialog(a)),
              const SizedBox(width: 4),
              smallBtn(jb(a['now']) ? 'Beenden' : 'Entfernen', () => _absRemove(a)),
            ]))),
        Padding(padding: const EdgeInsets.only(top: 6), child: Align(alignment: Alignment.centerLeft, child: OutlinedButton(onPressed: () => _absDialog(null), child: const Text('+ Abwesenheit erfassen')))),
      ],
      if (_rules != null && !s.demo) ...[
        _h5('Regelmässige Einsatzzeiten'),
        const Mut('Je Wochentag und Uhrzeit: nur in diesen Zeiten alarmiert werden (Einsatzzeit) oder regelmässig ausgesetzt.', size: 12),
        if (_rules!.isEmpty) const Mut('Keine regelmässigen Einsatzzeiten.'),
        ..._rules!.map((r) => Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(children: [
              Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(_ruleMode[r['mode']] ?? '', style: const TextStyle(fontWeight: FontWeight.bold)),
                Mut('${_ruleTxt(r)}${js(r['note']).isNotEmpty ? ' · ${r['note']}' : ''}', size: 12),
              ])),
              smallBtn('Ändern', () => _ruleDialog(r)),
              const SizedBox(width: 4),
              smallBtn('Entfernen', () => _ruleRemove(r)),
            ]))),
        Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Wrap(spacing: 8, runSpacing: 6, children: [
              OutlinedButton(onPressed: () => _ruleDialog(null), child: const Text('+ Regel erfassen')),
              OutlinedButton(onPressed: () => openWeb(context, s, 'admin.html#kalender'), child: const Text('📅 Kalender')), // Issue #181
            ])),
      ],
      _h5('Übungsmodus'),
      SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: jb(me['exercise_mode']),
          title: const Text('Einsatztypen, die das vorsehen (z. B. Übungen), kritisch alarmieren'),
          onChanged: (v) => _set({'exercise_mode': v ? 1 : 0}, poll: true)),
      _h5('Ton bei kritischem Alarm'),
      ..._sounds(me),
      ..._testAlarmUi(),
      _h5('Standort'),
      Mut(_locText),
      Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Wrap(spacing: 8, runSpacing: 6, children: [
            if (_loc != locAlways) OutlinedButton(onPressed: _locAlways, child: const Text('Standort immer zulassen')),
            OutlinedButton(
                onPressed: () async {
                  await openAppSettings();
                  _loadLoc();
                },
                child: const Text('App-Einstellungen')),
          ])),
      _h5('Benachrichtigungen'),
      const Mut('Benachrichtigungen kommen über die App. Ein- und ausschalten in den Einstellungen der App bzw. des Geräts.'),
    ]);
  }

  Widget _anzeige(J me) {
    final cd = js(me['coord_default']).isEmpty ? 'lv95' : js(me['coord_default']);
    final cs = js(me['coord_sys']);
    final navs = (me['nav_apps'] is List ? (me['nav_apps'] as List).map((e) => '$e') : const <String>[]).where((k) => navOk(s, k)).toList();
    final na = js(me['nav_app']);
    return MCard(title: 'Anzeige', children: [
      DropdownButtonFormField<String>(
            key: ValueKey('lg${I18n.chosen}'),
            isExpanded: true,
            initialValue: langs.containsKey(I18n.chosen) ? I18n.chosen : '',
            decoration: InputDecoration(labelText: trn('Sprache')),
            items: [
              DropdownMenuItem(value: '', child: Text('🌐 ${tr('Automatisch')} (${langs[I18n.device()]})', overflow: TextOverflow.ellipsis)),
              ...langs.entries.map((e) => DropdownMenuItem(value: e.key, child: m.Text(e.value))),
            ],
            onChanged: (v) => langSet(v ?? '')),
      const SizedBox(height: 8),
      DropdownButtonFormField<String>(
          key: ValueKey('cs$cs'),
          isExpanded: true,
          initialValue: cs == 'none' || coordSys.containsKey(cs) ? cs : '',
          decoration: InputDecoration(labelText: trn('Koordinatensystem')),
          items: [
            DropdownMenuItem(value: '', child: Text('Standard: ${coordSys[cd] ?? coordSys['lv95']}', overflow: TextOverflow.ellipsis)),
            ...coordSys.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value, overflow: TextOverflow.ellipsis))),
            const DropdownMenuItem(value: 'none', child: Text('Keine Koordinaten anzeigen')),
          ],
          onChanged: (v) => _set({'coord_sys': v ?? ''}, poll: true)),
      const SizedBox(height: 8),
      DropdownButtonFormField<String>( // Issue #259: Einheit der Geschwindigkeiten
          key: ValueKey('sp${js(me['speed_unit'])}'),
          isExpanded: true,
          initialValue: speedUnits.containsKey(js(me['speed_unit'])) ? js(me['speed_unit']) : '',
          decoration: InputDecoration(labelText: trn('Geschwindigkeiten')),
          items: [
            DropdownMenuItem(value: '', child: Text('Standard: ${speedUnits[js(me['speed_default'])] ?? speedUnits['ms']}', overflow: TextOverflow.ellipsis)),
            ...speedUnits.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value, overflow: TextOverflow.ellipsis))),
          ],
          onChanged: (v) => _set({'speed_unit': v ?? ''}, poll: true)),
      if (s.feat('nav')) ...[
        const SizedBox(height: 8),
        DropdownButtonFormField<String>(
            key: ValueKey('na$na'),
            isExpanded: true,
            initialValue: navs.contains(na) ? na : '',
            decoration: InputDecoration(labelText: trn('Navigations-App (Knopf 🧭)')),
            items: [
              DropdownMenuItem(value: '', child: Text('Standard: ${navApps[js(me['nav_default'])] ?? navApps['google']}', overflow: TextOverflow.ellipsis)),
              ...navs.map((k) => DropdownMenuItem(value: k, child: Text(navApps[k] ?? k))),
            ],
            onChanged: (v) => _set({'nav_app': v ?? ''})),
      ],
    ]);
  }

  /// Wasserzeit (Feedback zu #241): persönliche Vorgabe des Kälteschutzes, gilt in jedem Einsatz, bis man ihn dort ändert
  Widget? _wt(J me) {
    final w = jm(me['wt']), suits = jl(w['suits']);
    if (s.demo || suits.isEmpty || !s.feat('wtime')) return null;
    final cur = js(w['suit']), d = js(suits.where((x) => x['k'] == w['def']).firstOrNull?['name']);
    return MCard(title: 'Wasserzeit', children: [
      DropdownButtonFormField<String>(
          key: ValueKey('wt$cur'),
          isExpanded: true,
          initialValue: suits.any((x) => x['k'] == cur) ? cur : '',
          decoration: InputDecoration(labelText: trn('Mein Kälteschutz')),
          items: [
            DropdownMenuItem(value: '', child: Text('Vorgabe der Organisation (${tr(d)})', overflow: TextOverflow.ellipsis)),
            ...suits.map((x) => DropdownMenuItem(value: js(x['k']), child: Text(js(x['name']), overflow: TextOverflow.ellipsis))),
          ],
          onChanged: (v) => _set({'wt_suit': v ?? ''})),
      const Padding(
          padding: EdgeInsets.only(top: 6),
          child: Mut('Wird beim Annehmen eines Einsatzes vorgewählt. Im Einsatz kannst du ihn jederzeit ändern, die Einsatzleitung ebenso.', size: 12)),
    ]);
  }

  Widget? _ai(J me) {
    final ai = me['ai'];
    if (ai is! Map || ai.isEmpty) return null;
    final fns = jm(ai);
    final sel = <String, String>{for (final e in fns.entries) if (js(jm(e.value)['choice']).isNotEmpty) e.key: js(jm(e.value)['choice'])};
    return MCard(title: 'AI-Assistent', children: [
      const Mut('Texte, die der AI-Assistent auf Wunsch erstellt. «Begleitend» bereitet sie schon beim Öffnen vor.'),
      ...fns.entries.map((e) {
        final f = jm(e.value), mx = js(f['max']), opts = _aiOrder.sublist(0, _aiOrder.indexOf(mx) + 1);
        final ch = js(f['choice']);
        return Padding(
            padding: const EdgeInsets.only(top: 8),
            child: DropdownButtonFormField<String>(
                key: ValueKey('ai${e.key}$ch'),
                isExpanded: true,
                initialValue: opts.contains(ch) ? ch : '',
                decoration: InputDecoration(labelText: trn(js(f['name']))),
                items: [
                  DropdownMenuItem(value: '', child: Text('Standard: ${_aiMn[mx] ?? mx}', overflow: TextOverflow.ellipsis)),
                  ...opts.map((m) => DropdownMenuItem(value: m, child: Text(_aiMn[m]!, overflow: TextOverflow.ellipsis))),
                ],
                onChanged: (v) {
                  final n = {...sel};
                  (v == null || v.isEmpty) ? n.remove(e.key) : n[e.key] = v;
                  _set({'ai_modes': n});
                }));
      }),
    ]);
  }

  // ---------- Fahrzeuge (Issue #177) ----------
  int? _bt;
  Future<void> _loadBt() async {
    final b = await btState();
    if (mounted) setState(() => _bt = b);
  }

  /// Karte «Fahrzeuge»: Zustimmung für ausserhalb von Einsätzen (nur wenn die Verwaltung es erlaubt), Bluetooth, «Fahrzeuge jetzt scannen»,
  /// «Beacon einlernen» (Recht cfg_map)
  Widget? _veh(J me) {
    final c = me['beacon_cfg'];
    if (c is! Map || s.demo) return null;
    final home = jb(c['home']), admin = s.can('cfg_map');
    if (_bt == null) _loadBt();
    return MCard(title: 'Fahrzeuge', children: [
      if (home)
        SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: jb(me['beacon']),
            title: const Text('Fahrzeuge auch ausserhalb von Einsätzen melden'),
            subtitle: Text('Die App erkennt die Beacons in den Fahrzeugen und meldet deren Standort ohne deinen Namen, höchstens alle ${ji(c['home_min']) ?? 15} min. '
                'Das Betriebssystem sucht die Beacons selbst, das braucht wenig Akku. Im Einsatz meldet die App die Fahrzeuge immer, solange du den Standort teilst.'),
            onChanged: (v) async {
              if (v) await requestBluetooth();
              await _set({'beacon': v ? 1 : 0});
              _loadBt();
            }),
      Mut(switch (_bt) {
        0 => 'Bluetooth ist aus oder fehlt: Die App sieht keine Fahrzeuge.',
        1 => Platform.isIOS ? 'Die App darf den Standort noch nicht nutzen: Ohne ihn sieht iOS keine Beacons.' : 'Die App darf Bluetooth noch nicht nutzen.',
        2 => Platform.isIOS ? 'Bereit, sofern Bluetooth eingeschaltet ist.' : 'Bluetooth bereit.',
        3 => 'Nur ungefährer Standort: iOS findet so keine Beacons. Einstellungen → Swiftwater Rescue → Standort → «Genauer Standort» einschalten.',
        _ => '',
      }),
      if (_bt == 1 || _bt == 3)
        Padding(
            padding: const EdgeInsets.only(top: 6),
            child: OutlinedButton(onPressed: () => requestBluetooth().then((_) => _loadBt()), child: Text(_bt == 3 ? 'Genauen Standort erlauben' : 'Bluetooth erlauben'))),
      Padding(padding: const EdgeInsets.only(top: 6), child: Wrap(spacing: 8, runSpacing: 6, children: [?vehScanBtn(context, s), if (admin) OutlinedButton(onPressed: _learn, child: const Text('Beacon einlernen …'))])),
    ]);
  }

  /// Beacons in der Nähe zeigen (Signalstärke), ein Tipp ordnet eines einem Fahrzeug zu (vehicle/save). iOS sieht nur eingetragene UUIDs.
  Future<void> _learn() async {
    J d;
    try {
      d = await s.api.call('vehicles');
    } catch (e) {
      s.fail(e);
      return;
    }
    if (!mounted) return;
    final bt = await requestBluetooth();
    if (bt != 2) {
      s.toast(bt == 3 ? 'iOS findet Beacons nur mit dem genauen Standort' : 'Bluetooth ist aus oder nicht erlaubt', 'err');
      return;
    }
    final cfg = jm(d['cfg']), vs = jl(d['vehicles']).where((v) => jb(v['active'])).toList();
    // iOS sieht nur Beacons mit bekannter UUID: Standard, weitere UUIDs der Einstellung und die eigenen UUIDs der Fahrzeuge
    final known = {
      js(cfg['uuid']),
      ...(cfg['uuids'] is List ? cfg['uuids'] as List : const []).map((u) => '$u'),
      ...vs.map((v) => js(v['uuid'])),
    }.where((u) => u.isNotEmpty).map((u) => u.toUpperCase()).toList();
    if (!mounted) return;
    await showDialog<void>(
        context: context,
        builder: (dc) {
          List<J>? found;
          var busy = false;
          return StatefulBuilder(builder: (dc, set) {
            Future<void> scan() async {
              set(() => busy = true);
              final r = (await beaconScan(Platform.isIOS ? known : [], ms: 6000)).where((b) => js(b['uuid']).isNotEmpty).toList(); // ohne Einträge nur mit Batteriestand
              r.sort((a, b) => (ji(b['rssi']) ?? -127).compareTo(ji(a['rssi']) ?? -127));
              set(() {
                found = r;
                busy = false;
              });
            }

            if (found == null && !busy) scan();
            String owner(J b) {
              final u = js(b['uuid']).toUpperCase();
              final v = vs.where((v) => (js(v['uuid']).isEmpty ? js(cfg['uuid']) : js(v['uuid'])).toUpperCase() == u && ji(v['major']) == ji(b['major']) && ji(v['minor']) == ji(b['minor'])).firstOrNull;
              final p = ji(b['batt']) ?? Beacons.battPct(ji(b['mv']));
              return (v == null ? ' · noch keinem Fahrzeug zugeordnet' : ' · gehört zu «${js(v['name'])}»') + (p == null ? '' : ' · Batterie $p %');
            }

            return AlertDialog(
                title: const Text('Beacon einlernen'),
                content: SizedBox(
                    width: 420,
                    child: busy
                        ? const Padding(padding: EdgeInsets.all(20), child: Center(child: CircularProgressIndicator()))
                        : (found ?? []).isEmpty
                            ? Mut(Platform.isIOS ? 'Kein Beacon gefunden. iOS sieht nur Beacons mit einer eingetragenen UUID (Verwaltung → Fahrzeuge).' : 'Kein Beacon gefunden. Halte das Handy nahe an das Beacon.')
                            : ListView(shrinkWrap: true, children: [
                                const Mut('Das stärkste Signal steht zuoberst. Antippen und das Fahrzeug wählen.'),
                                for (final b in found!)
                                  ListTile(
                                      dense: true,
                                      title: Text('${ji(b['major'])} / ${ji(b['minor'])} · ${ji(b['rssi'])} dBm'),
                                      subtitle: Text('${js(b['uuid'])}${known.map((k) => k.toUpperCase()).contains(js(b['uuid']).toUpperCase()) ? '' : ' (UUID nicht eingetragen)'}${owner(b)}'),
                                      onTap: () async {
                                        final v = await showDialog<J>(
                                            context: dc,
                                            builder: (x) => SimpleDialog(title: const Text('Welches Fahrzeug?'), children: [
                                                  for (final v in vs) SimpleDialogOption(onPressed: () => Navigator.pop(x, v), child: Text(js(v['name']))),
                                                ]));
                                        if (v == null) return;
                                        final u = js(b['uuid']).toUpperCase() == js(cfg['uuid']).toUpperCase() ? '' : js(b['uuid']);
                                        try {
                                          await s.api.call('vehicle/save', body: {...v, 'uuid': u, 'major': ji(b['major']), 'minor': ji(b['minor']), if (js(b['mac']).isNotEmpty) 'mac': js(b['mac'])});
                                          s.toast('«${js(v['name'])}» hat jetzt dieses Beacon');
                                          if (dc.mounted) Navigator.pop(dc);
                                        } catch (e) {
                                          s.fail(e);
                                        }
                                      }),
                              ])),
                actions: [
                  TextButton(onPressed: busy ? null : scan, child: const Text('Neu suchen')),
                  TextButton(onPressed: () => Navigator.pop(dc), child: const Text('Schliessen')),
                ]);
          });
        });
  }

  // Version (Issue #175): App und Server, Hinweis bei veralteter App, «Was ist neu» vom Server des Kontos
  Widget _version(J me) {
    final v = me['version'] is Map ? jm(me['version']) : null, un = updateNote(v, js(me['app_min']));
    return MCard(title: 'Version', buttons: [
      OutlinedButton(onPressed: () => showChanges(context, s.api), child: const Text('Was ist neu')),
      if (un != null) FilledButton(onPressed: openStore, child: const Text('Aktualisieren')),
    ], children: [
      Mkv([('App', appVerLabel), ('Server', verTxt(v))]),
      if (un != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(un, style: TextStyle(color: Theme.of(context).colorScheme.error))),
    ]);
  }

  Widget _legal() => MCard(title: 'Rechtliches', buttons: [
        OutlinedButton(onPressed: () => openWeb(context, s, 'hilfe.html'), child: const Text('Bedienungsanleitung')), // Issue #37
        OutlinedButton(onPressed: () => openWeb(context, s, 'legal.html#impressum'), child: const Text('Impressum')),
        OutlinedButton(onPressed: () => openWeb(context, s, 'legal.html#lizenzen'), child: const Text('Lizenzen')),
        OutlinedButton(onPressed: () => openWeb(context, s, 'legal.html#datenschutz'), child: const Text('Datenschutz')),
      ], children: [
        if (!s.demo && orgLogoUrl(s.acc.base, s.me?['org_info']) != null)
          Padding(padding: const EdgeInsets.only(bottom: 8), child: OrgLogo(s.acc.base, s.me?['org_info'], h: 72, maxW: 220)), // Issue #123
        if (!s.demo && js(jm(s.me?['org_info'])['name']).isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 4), child: Text(js(jm(s.me?['org_info'])['name']), style: const TextStyle(fontWeight: FontWeight.bold))),
        const Mut('Wer die App betreibt, welche Daten sie speichert und welche Lizenzen gelten.'),
      ]);

  @override
  Widget build(BuildContext context) => Scaffold(
      appBar: AppBar(title: const Text('Einstellungen')),
      body: ListenableBuilder(
          listenable: s,
          builder: (c, _) {
            final me = s.me;
            if (me == null) return const Center(child: CircularProgressIndicator());
            return RefreshIndicator(
                onRefresh: () async {
                  await Future.wait([s.reloadMe(), _loadAbs(), _loadSess(), _loadLoc()]);
                },
                child: ListView(padding: const EdgeInsets.fromLTRB(12, 8, 12, 24), children: [
                  _konto(me),
                  _alarm(me),
                  if (!s.demo && (ji(jm(me['skills'])['n']) ?? 0) > 0) SkillPrefs(s: s), // Ausbildung (Issue #117)
                  _anzeige(me),
                  ?_wt(me),
                  ?_veh(me),
                  if (s.feat('hydro')) HydroPrefs(s: s), // persönliche Messstationen (Issue #165)
                  if (s.feat('voice')) VoicePrefs(s: s), // Funk (Issue #113)
                  if (docBtn(s)) DocPrefs(s: s, openPanel: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => Scaffold(body: SafeArea(child: DocsPanel(s: s)))))), // Dokumente (Issue #229)
                  ?_ai(me),
                  _version(me),
                  _legal(),
                ]));
          }));
}
