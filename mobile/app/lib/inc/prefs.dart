// Seite «Einstellungen» der nativen Einsatz-App (Gegenstück zu prefs/prefsDraw, absMine, sessMine und aiPrefs in web/index.html,
// absRows/absDialog/pwDialog in web/common.js): Konto (Profilbild, Passwort, angemeldete Apps, Abmelden), Alarmierung (Einsatzfähigkeit
// mit Abwesenheiten, Übungsmodus, Standort und Benachrichtigungen über die Systemeinstellungen), Anzeige, AI-Assistent, Rechtliches.
import 'dart:convert';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../sys.dart';
import 'ctx.dart';
import 'store.dart';
import 'util.dart';

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
  return u != null ? 'bis ${_absDT(u)}' : 'bis auf Weiteres';
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
  bool _absErr = false;
  List<J> _sess = [];
  int? _loc; // Stufe der Standortberechtigung
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _loadAbs();
    _loadSess();
    _loadLoc();
  }

  Future<void> _loadAbs() async {
    try {
      final r = jl((await s.api.call('absences'))['data']);
      if (mounted) setState(() => (_abs = r, _absErr = false));
    } catch (_) {
      if (mounted) setState(() => (_abs = [], _absErr = true));
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
    var busy = false;
    await showDialog<void>(
        context: context,
        builder: (d) => StatefulBuilder(
            builder: (d2, ss) => AlertDialog(
                    title: const Text('Passwort ändern'),
                    scrollable: true,
                    content: SizedBox(
                        width: 420,
                        child: Column(mainAxisSize: MainAxisSize.min, children: [
                          TextField(controller: o, obscureText: true, autofillHints: const [AutofillHints.password], decoration: const InputDecoration(labelText: 'Aktuelles Passwort')),
                          TextField(controller: n, obscureText: true, autofillHints: const [AutofillHints.newPassword], decoration: const InputDecoration(labelText: 'Neues Passwort')),
                          TextField(controller: r, obscureText: true, decoration: const InputDecoration(labelText: 'Neues Passwort wiederholen')),
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
                                    await s.api.call('me/password', body: {'old': o.text, 'new': n.text});
                                    if (d.mounted) Navigator.pop(d);
                                    s.toast('Passwort geändert.');
                                  } catch (e) {
                                    ss(() => (busy = false, err = '$e'));
                                  }
                                },
                          child: const Text('Speichern')),
                    ])));
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
                            decoration: const InputDecoration(labelText: 'Art'),
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
                        TextField(controller: note, maxLength: 200, decoration: const InputDecoration(labelText: 'Bemerkung (optional)', hintText: 'z. B. Ferien, Krankheit')),
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
    return Chip2('⏸ ${js(a['kind']) == 'sus' ? 'ausgesetzt' : 'abwesend'} ${_absUntil(a)}${js(a['note']).isNotEmpty ? ' – ${a['note']}' : ''}', color: accOrange);
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
            OutlinedButton(onPressed: _pw, child: const Text('Passwort ändern')),
            OutlinedButton(onPressed: _busy ? null : _logout, child: const Text('Abmelden')),
          ])),
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
      _h5('Übungsmodus'),
      SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: jb(me['exercise_mode']),
          title: const Text('Einsatztypen, die das vorsehen (z. B. Übungen), kritisch alarmieren'),
          onChanged: (v) => _set({'exercise_mode': v ? 1 : 0}, poll: true)),
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
          key: ValueKey('cs$cs'),
          isExpanded: true,
          initialValue: cs == 'none' || coordSys.containsKey(cs) ? cs : '',
          decoration: const InputDecoration(labelText: 'Koordinatensystem'),
          items: [
            DropdownMenuItem(value: '', child: Text('Standard: ${coordSys[cd] ?? coordSys['lv95']}', overflow: TextOverflow.ellipsis)),
            ...coordSys.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value, overflow: TextOverflow.ellipsis))),
            const DropdownMenuItem(value: 'none', child: Text('Keine Koordinaten anzeigen')),
          ],
          onChanged: (v) => _set({'coord_sys': v ?? ''}, poll: true)),
      if (s.feat('nav')) ...[
        const SizedBox(height: 8),
        DropdownButtonFormField<String>(
            key: ValueKey('na$na'),
            isExpanded: true,
            initialValue: navs.contains(na) ? na : '',
            decoration: const InputDecoration(labelText: 'Navigations-App (Knopf 🧭)'),
            items: [
              DropdownMenuItem(value: '', child: Text('Standard: ${navApps[js(me['nav_default'])] ?? navApps['google']}', overflow: TextOverflow.ellipsis)),
              ...navs.map((k) => DropdownMenuItem(value: k, child: Text(navApps[k] ?? k))),
            ],
            onChanged: (v) => _set({'nav_app': v ?? ''})),
      ],
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
                decoration: InputDecoration(labelText: js(f['name'])),
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

  Widget _legal() => MCard(title: 'Rechtliches', buttons: [
        OutlinedButton(onPressed: () => openWeb(context, s, 'legal.html#impressum'), child: const Text('Impressum')),
        OutlinedButton(onPressed: () => openWeb(context, s, 'legal.html#lizenzen'), child: const Text('Lizenzen')),
        OutlinedButton(onPressed: () => openWeb(context, s, 'legal.html#datenschutz'), child: const Text('Datenschutz')),
      ], children: const [
        Mut('Wer die App betreibt, welche Daten sie speichert und welche Lizenzen gelten.'),
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
                  _anzeige(me),
                  ?_ai(me),
                  _legal(),
                ]));
          }));
}
