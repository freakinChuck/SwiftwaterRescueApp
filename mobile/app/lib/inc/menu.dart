// Seitenmenü (☰) der nativen Einsatz-App (Gegenstück zu draw() in web/index.html, Issue #129): Kopfzeile mit Profil, Meine Einsätze und
// Verwaltung; im Einsatz Kopf mit den wichtigsten Angaben und Register Einsatz/Leitung/Übung/Log/Info; ohne Einsatz «… starten» je
// Einsatztyp (Startformular mit Aufgebot), vorbereitete Übungen und Werkzeuge. Unten «Mein Standort» in LV95 und WGS84 Grad/Min/Sek.
// Parallele Einsätze (Issue #167): Karte «Laufende Einsätze» über den Registern (incSwitch), «Weiteren Einsatz starten» im Register «Einsatz» (startMore).
// MCard (ctx.dart) zeigt «buttons» unter «children»; die Reihenfolge der Argumente folgt der Anzeige.
// ignore_for_file: sort_child_properties_last
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../org.dart';
import '../version.dart';
import 'ctx.dart';
import 'hydro.dart';
import 'pois.dart';
import 'skills.dart';
import 'store.dart';
import 'util.dart';
import 'voice.dart';

/// Rechte mit einem Bereich in der Verwaltung (ADMIN_PERMS in common.js)
const _adminPerms = ['users', 'uroles', 'groups', 'avail', 'cfg_inc', 'ex_plan', 'cfg_func', 'cfg_ai', 'cfg_map', 'stats', 'cfg_my', 'aar', 'aar_rel', 'aar_cfg', 'aar_arch', 'legal', 'anon', 'cfg_conn', 'applog', 'versions'];

/// Arten der Übungselemente (EXK)
const _exk = {'pls': 'PLS des Einsatzes', 'res': 'Neuer Rettling', 'seen': 'Rettling gesichtet', 'ext': 'Neue externe Einsatzkraft', 'extdel': 'Externe Einsatzkraft entfernen'};

List<int> _ints(dynamic v) => v is List ? v.map(ji).whereType<int>().toList() : <int>[];

class IncMenu extends StatefulWidget {
  const IncMenu({super.key, required this.s, required this.map, required this.openPrefs, required this.plsPick});
  final IncStore s;
  final MapCtl map;
  final VoidCallback openPrefs; // Profil öffnen
  final Future<void> Function(BuildContext c, {J? res}) plsPick; // PLS des Einsatzes setzen (aus res.dart, wird von der Karte übergeben)
  @override
  State<IncMenu> createState() => _IncMenuState();
}

class _IncMenuState extends State<IncMenu> {
  IncStore get s => widget.s;
  MapCtl get map => widget.map;
  String _tab = 'main';
  int? _tabInc; // Einsatz, für den das Register gewählt wurde (beim Öffnen bzw. neuem Einsatz: secReset)
  final _fl = TextEditingController();
  final _flFocus = FocusNode();
  String? _flSrv;
  final _busy = <String>{};
  bool _updLater = true; // Hinweis «Bitte App aktualisieren» bis zur Prüfung von «Später» ausgeblendet (Issue #175)

  @override
  void initState() {
    super.initState();
    updateLater().then((v) {
      if (mounted) setState(() => _updLater = v);
    });
  }

  @override
  void dispose() {
    _fl.dispose();
    _flFocus.dispose();
    super.dispose();
  }

  /// Aktion mit gesperrtem Knopf (b.disabled während der Anfrage)
  Future<J?> _run(String key, String route, [J? body, String? ok]) async {
    if (_busy.contains(key)) return null;
    setState(() => _busy.add(key));
    final r = await s.act(route, body, ok);
    if (mounted) setState(() => _busy.remove(key));
    return r;
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(listenable: s, builder: (c, _) => _build(c));

  Widget _build(BuildContext c) {
    final st = s.st, i = s.inc;
    final List<Widget> top = [];
    if (s.offline) top.add(_offline(c));
    final abs = st?['my_abs'] is Map ? jm(st!['my_abs']) : null;
    if (abs != null) top.add(_absNote(c, abs));
    final sk = s.demo || i != null ? '' : skSumText(s.me?['skills']); // Ausbildung (Issue #117), nur ohne Einsatz
    if (sk.isNotEmpty) {
      top.add(MCard(children: [
        Row(children: [
          Expanded(child: Text('Ausbildung: $sk', style: const TextStyle(fontWeight: FontWeight.w600))),
          smallBtn('Ansehen', () {
            map.closeMenu();
            widget.openPrefs();
          }),
        ]),
      ]));
    }
    // offene Feedback-Anfragen zum After Action Review (Issue #103), nur ohne Einsatz; Antwort in «Meine Einsätze» (WebView)
    final fbo = i == null && !s.demo ? (ji(st?['fb_open']) ?? 0) : 0;
    if (fbo > 0) top.add(_fbNote(c, fbo));
    final un = _updLater ? null : updateNote(s.me?['version'] is Map ? jm(s.me!['version']) : null, js(s.me?['app_min']));
    if (un != null) top.add(_updNote(un));
    Widget body;
    if (st != null && jb(st['blocked'])) {
      body = ListView(padding: const EdgeInsets.fromLTRB(12, 0, 12, 12), children: [
        ...top,
        const MCard(title: 'Einsatz läuft', children: [
          Mut('Es läuft ein Einsatz, für den du nicht aufgeboten bist. Die Einsatzleitung kann allen den Beitritt erlauben oder dich nachbieten, wenn du einer dafür zugelassenen Einsatzgruppe angehörst.')
        ]),
        ?_tools(),
        _foot(c),
      ]);
    } else if (i == null) {
      body = ListView(padding: const EdgeInsets.fromLTRB(12, 0, 12, 12), children: [...top, ..._noInc(c), _foot(c)]);
    } else {
      body = _incView(c, i, top);
    }
    return Material(
      child: SafeArea(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [_titleBar(c), Expanded(child: body)]),
      ),
    );
  }

  // ---------- Kopfzeile ----------
  Widget _titleBar(BuildContext c) {
    final me = s.me;
    final adm = me != null && _adminPerms.any(s.can);
    final my = me != null && js(me['my_runs']).isNotEmpty && me['my_runs'] != 'off';
    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 6, 4, 4),
      child: Row(children: [
        // Servername antippen = zur Kontenliste (Auswahl der Backends); am iPhone gibt es keine Zurück-Taste
        Expanded(
            child: Tooltip(
                message: 'Konten / Server wechseln',
                child: InkWell(
                    borderRadius: BorderRadius.circular(8),
                    onTap: () => Navigator.of(c, rootNavigator: true).popUntil((r) => r.isFirst),
                    child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 8),
                        child: Row(children: [
                          if (!s.demo) Padding(padding: const EdgeInsets.only(right: 8), child: OrgLogo(s.acc.base, me?['org_info'])), // Issue #123
                          Flexible(
                              child: Text(s.acc.label.isEmpty ? 'Swiftwater Rescue' : s.acc.label,
                                  maxLines: 1, overflow: TextOverflow.ellipsis, style: Theme.of(c).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold))),
                          const SizedBox(width: 4),
                          const Icon(Icons.swap_horiz, size: 18),
                        ]))))),
        IconButton(
            tooltip: 'Profil${me != null ? ' (${js(me['name'])})' : ''}',
            icon: const Text('👤', style: TextStyle(fontSize: 20)),
            onPressed: () {
              map.closeMenu();
              widget.openPrefs();
            }),
        if (my) IconButton(tooltip: 'Meine Einsätze', icon: const Text('📋', style: TextStyle(fontSize: 20)), onPressed: () => openWeb(context, s, 'admin.html#meine')),
        if (adm) IconButton(tooltip: 'Verwaltung', icon: const Text('⚙', style: TextStyle(fontSize: 20)), onPressed: () => openWeb(context, s, 'admin.html')),
        IconButton(tooltip: 'Menü schliessen', icon: const Icon(Icons.close), onPressed: map.closeMenu),
      ]),
    );
  }

  Widget _offline(BuildContext c) {
    final at = s.offAt;
    return MCard(color: accOrange.withValues(alpha: .25), children: [
      Text('Keine Verbindung zum Server${at != null ? ' · letzte Verbindung ${fmtT(at / 1000)}' : ''}', style: const TextStyle(fontWeight: FontWeight.bold)),
      const Mut('Angezeigt wird der zuletzt geladene Stand. Aktionen schlagen fehl, bis die Verbindung zurück ist.'),
    ]);
  }

  String _absTxt(J a) {
    final u = jn(a['until_at']) ?? jn(a['until']);
    return '${absWord(a)}${u == null ? (a['kind'] == 'rule' ? '' : ' bis auf Weiteres') : ' bis ${fmtDT(u)}'}';
  }

  // Hinweis für ältere Apps (Issue #175): Mindestversion des Servers bzw. höhere API-Stufe; «Später» blendet ihn 7 Tage aus
  Widget _updNote(String t) => MCard(color: accOrange.withValues(alpha: .25), children: [
        Text(t, style: const TextStyle(fontWeight: FontWeight.w600)),
      ], buttons: [
        smallBtn('Aktualisieren', openStore, primary: true),
        smallBtn('Später', () async {
          await setUpdateLater();
          if (mounted) setState(() => _updLater = true);
        }),
      ]);

  Widget _absNote(BuildContext c, J a) => MCard(children: [
        Row(children: [
          Expanded(child: Text('⏸ ${_absTxt(a)}${js(a['note']).isNotEmpty ? ' – ${js(a['note'])}' : ''}', style: const TextStyle(fontWeight: FontWeight.w600))),
          smallBtn('Ändern', () {
            map.closeMenu();
            widget.openPrefs();
          }),
        ]),
        const Mut('Du erhältst keine Aufgebote und keine Alarme.'),
      ]);

  Widget _fbNote(BuildContext c, int n) => MCard(children: [
        Row(children: [
          Expanded(child: Text('📝 Feedback erbeten zu ${n == 1 ? 'einem beendeten Einsatz' : '$n beendeten Einsätzen'}', style: const TextStyle(fontWeight: FontWeight.w600))),
          smallBtn('Antworten', () => openWeb(context, s, 'admin.html#meine')),
        ]),
      ]);

  // ---------- Werkzeuge und Fusszeile ----------
  Widget? _tools() {
    if (!s.feat('rd')) return null;
    final sp = s.me?['rd_speeds'] is List ? (s.me!['rd_speeds'] as List).join(', ') : '10, 20, 30';
    return MCard(title: 'Werkzeuge', children: [
      Mut('Distanz zwischen zwei Punkten entlang des Gewässers mit Treibzeit (Fliessgeschwindigkeit) und Fahrzeiten bei $sp km/h.')
    ], buttons: [
      OutlinedButton(
          onPressed: () {
            map.closeMenu();
            map.openRd();
          },
          child: const Text('Flussdistanz messen')),
    ]);
  }

  String _roleNames() {
    final l = jl(s.me?['uroles']).map((r) => js(r['name'])).where((n) => n.isNotEmpty).join(', ');
    return l.isEmpty ? 'keine Benutzerrolle' : l;
  }

  List<(String, String)> _infoRows() {
    final me = s.me ?? {};
    final org = s.demo ? '' : js(jm(me['org_info'])['name']);
    return [
      if (org.isNotEmpty) ('Organisation', org), // Issue #123
      ('Angemeldet', '${js(me['name'])} (${js(me['username'])}) · ${_roleNames()}${jb(me['exercise_mode']) ? ' · Übungsmodus an' : ''}'),
      ('Server', s.acc.base.replaceFirst(RegExp(r'^https?://'), '')),
    ];
  }

  Widget _foot(BuildContext c) {
    final rows = <(String, String)>[];
    if (s.csys != 'none') {
      final p = s.selfAt;
      rows.add((
        'Mein Standort',
        p != null
            ? 'LV95 ${fmtC(p.latitude, p.longitude, 'lv95')} · WGS84 ${fmtC(p.latitude, p.longitude, 'wgs84dms')}${s.manualPos != null ? ' (manuell gesetzt)' : ''}'
            : (s.selfHidden ? 'wird bei dieser Einrückoption nicht ermittelt' : (s.locErr.isNotEmpty ? s.locErr : 'wird ermittelt …'))
      ));
    }
    if (s.inc == null) rows.addAll(_infoRows());
    final t = Theme.of(c);
    // Impressum, Lizenzen, Datenschutz wie in der Fusszeile der Verwaltung (Issue #123)
    Widget lk(String h, String l) => InkWell(onTap: () => openWeb(c, s, 'legal.html#$h'), child: Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Text(l, style: t.textTheme.bodySmall?.copyWith(color: t.colorScheme.primary))));
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: rows
              .map<Widget>((r) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 1.5),
                  child: Text.rich(TextSpan(children: [TextSpan(text: '${r.$1}: ', style: const TextStyle(fontWeight: FontWeight.bold)), TextSpan(text: r.$2)]),
                      style: t.textTheme.bodySmall?.copyWith(color: t.colorScheme.onSurfaceVariant))))
              .followedBy([
            Wrap(spacing: 14, children: [lk('impressum', 'Impressum'), lk('lizenzen', 'Lizenzen'), lk('datenschutz', 'Datenschutz')]),
          ]).toList()),
    );
  }

  // ---------- ohne Einsatz ----------
  List<J> _kindsNow() {
    final st = s.st;
    if (st != null && st['kinds'] is List) return jl(st['kinds']); // seit Issue #167 auch im Einsatz (weiteren starten)
    return jl(s.me?['kinds']);
  }

  List<Widget> _noInc(BuildContext c) {
    final ks = _kindsNow(), ka = ks.where((k) => jb(k['can'])).toList(), ng = ks.where((k) => jb(k['no_grp'])).toList();
    // Sekundäreinsätze (Issue #164): eigene Karte, starten ohne Alarm
    // Kategorie: 0 Primäreinsatz, 1 Sekundäreinsatz (ohne Alarm), 2 Freies Ereignis (Alarmstufe je Typ)
    int cat(J k) => ji(k['sec']) ?? 0;
    final nk = ka.where((k) => cat(k) == 0).toList(), sk = ka.where((k) => cat(k) == 1).toList(), fk = ka.where((k) => cat(k) == 2).toList();
    final xps = jl(s.st?['ex_plans']);
    const xpk = ['Übung', 'Sekundäreinsatz, ohne Alarm', 'Freies Ereignis'];
    return [
      MCard(title: 'Kein aktiver Einsatz', children: [
        if (nk.isEmpty)
          Mut('Du darfst ${sk.isNotEmpty || fk.isNotEmpty ? 'keine Primäreinsätze' : 'keine Einsätze'} starten${ng.isNotEmpty || sk.isNotEmpty || fk.isNotEmpty ? '' : ' (Benutzerrolle)'}. Bei einem Alarm erscheint er hier.'),
        if (ng.isNotEmpty)
          Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Mut('${ng.map((k) => '«${js(k['name'])}»').join(', ')}: Du bist in keiner Einsatzgruppe, die dafür aufbietbar ist.')),
      ], buttons: [
        for (var n = 0; n < nk.length; n++)
          n == 0
              ? FilledButton(onPressed: () => _startKind(c, nk[n]), child: Text('${js(nk[n]['name'])} starten'))
              : OutlinedButton(onPressed: () => _startKind(c, nk[n]), child: Text('${js(nk[n]['name'])} starten')),
      ]),
      if (sk.isNotEmpty)
        MCard(title: 'Sekundäreinsätze', children: [
          const Mut('Starten ohne Alarm: Die Aufgebotenen sehen den Einsatz in der App und treten selbst bei. Alle Funktionen stehen zur Verfügung.'),
        ], buttons: [
          for (final k in sk) OutlinedButton(onPressed: () => _startKind(c, k), child: Text('${js(k['name'])} starten')),
        ]),
      if (fk.isNotEmpty)
        MCard(title: 'Freie Ereignisse', children: const [], buttons: [
          for (final k in fk) OutlinedButton(onPressed: () => _startKind(c, k), child: Text('${js(k['name'])} starten')),
        ]),
      // Planungen (Issue #29, #164): vorbereitete Übungen, geplante Sekundäreinsätze und freie Ereignisse
      if (xps.isNotEmpty)
        MCard(title: 'Planungen (${xps.length})', children: [
          for (final p in xps)
            Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(children: [
                  Expanded(child: Text('${js(p['title'])} · ${xpk[(ji(p['sec']) ?? 0).clamp(0, 2)]}${p['at'] != null ? ' · ${fmtDT(jn(p['at']))}' : ''}')),
                  smallBtn('Starten', _busy.contains('xs${p['id']}') ? null : () => _exStart(c, p), primary: true),
                ])),
          const Padding(
              padding: EdgeInsets.only(top: 4),
              child: Mut('Startet mit der vorbereiteten Meldung (Sekundäreinsätze ohne Alarm). Du leitest: du wirst nicht alarmiert und gibst die vorbereiteten Elemente frei. Teilnehmen kannst du zusätzlich.')),
        ]),
      ?_sched(c),
      ?hydroCard(c, s, map, widget.openPrefs),
      ?_tools(),
    ];
  }

  /// Einsatzplan eines geplanten Sekundäreinsatzes (Feedback zu #164), für alle Teilnehmenden
  Widget _planCard(J p) {
    final d = p['doc'] is Map ? jm(p['doc']) : <String, dynamic>{}, ts = jl(d['tasks']);
    Widget h(String t) => Padding(padding: const EdgeInsets.only(top: 8, bottom: 2), child: Text(t, style: const TextStyle(fontWeight: FontWeight.bold)));
    final kv = <(String, String)>[if (d['at'] != null) ('Geplanter Beginn', fmtDT(jn(d['at']))), if (js(d['meet']).isNotEmpty) ('Treffpunkt', js(d['meet']))];
    final empty = kv.isEmpty && js(d['goal']).isEmpty && ts.isEmpty && js(d['info']).isEmpty;
    return MCard(title: 'Einsatzplan${js(p['title']).isNotEmpty ? ' «${js(p['title'])}»' : ''}', children: [
      if (kv.isNotEmpty) Mkv(kv),
      if (js(d['goal']).isNotEmpty) ...[h('Auftrag und Ziel'), SelectableText(js(d['goal']))],
      if (ts.isNotEmpty) ...[
        h('Aufträge'),
        for (final t in ts)
          Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Text.rich(TextSpan(children: [TextSpan(text: '${js(t['who'])}: ', style: const TextStyle(fontWeight: FontWeight.bold)), TextSpan(text: js(t['task']))]))),
      ],
      if (js(d['info']).isNotEmpty) ...[h('Weitere Angaben'), SelectableText(js(d['info']))],
      if (empty) const Mut('Kein Einsatzplan erfasst.'),
    ]);
  }

  // Übungsplan (Issue #169): wie schedCard in index.html, Daten aus ex_sched (ohne Einsatz)
  final Set<int> _schedOpen = {};
  String _schedWhen(J x) {
    if (x['at'] == null) return 'Datum noch offen';
    final a = tsDate(x['at'] as num), wd = const ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][a.weekday - 1];
    String p2(int v) => v.toString().padLeft(2, '0');
    var r = '$wd., ${p2(a.day)}.${p2(a.month)}.${a.year}, ${p2(a.hour)}:${p2(a.minute)}';
    if (x['end'] != null) {
      final e = tsDate(x['end'] as num);
      r += ' bis ${e.year == a.year && e.month == a.month && e.day == a.day ? fmtHM(x['end'] as num) : fmtDT(x['end'] as num)}';
    }
    return r;
  }

  Widget? _sched(BuildContext c) {
    final xs = jl(s.st?['ex_sched']);
    if (xs.isEmpty) return null;
    final ws = <Widget>[];
    for (final x in xs) {
      final id = x['id'] as int, u = x['su'] is Map ? jm(x['su']) : null, m = u?['mine'] is Map ? jm(u!['mine']) : null, k = x['kind'] is Map ? jm(x['kind']) : null;
      final list = u?['list'] is List ? jl(u!['list']) : null;
      if (ws.isNotEmpty) ws.add(const Divider(height: 16));
      ws.add(Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
        if (k != null) Chip2(js(k['name']), color: hexColor(k['color'])),
        Text(js(x['title']), style: const TextStyle(fontWeight: FontWeight.w600)),
        if (jb(x['running'])) const Chip2('läuft', color: accRed),
      ]));
      ws.add(Mkv([
        ('Wann', _schedWhen(x)),
        ('Ort', js(x['loc'])),
        ('Leitung', (x['leads'] is List ? x['leads'] as List : const []).join(', ')),
        if (u != null) ('Anmeldungen', '${u['yes']} nehmen teil${(u['no'] as int? ?? 0) > 0 ? ', ${u['no']} nicht' : ''}'),
      ]));
      if (js(x['info']).isNotEmpty) ws.add(Mut(js(x['info'])));
      if (list != null) {
        ws.add(InkWell(
            onTap: () => setState(() => _schedOpen.contains(id) ? _schedOpen.remove(id) : _schedOpen.add(id)),
            child: Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Mut('${_schedOpen.contains(id) ? '▾' : '▸'} Wer hat geantwortet?'))));
        if (_schedOpen.contains(id)) {
          ws.add(list.isEmpty
              ? const Mut('Noch niemand')
              : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  for (final r in list) Mut('${r['status'] == 'yes' ? '✓' : '✗'} ${js(r['name'])}${js(r['note']).isNotEmpty ? ' – ${js(r['note'])}' : ''}'),
                ]));
        }
      }
      if (u == null) continue;
      if (!jb(u['may'])) {
        final gs = (u['groups'] is List ? u['groups'] as List : const []).join(', ');
        ws.add(Mut('Anmelden können nur Mitglieder von ${gs.isEmpty ? 'bestimmten Einsatzgruppen' : gs}.'));
        continue;
      }
      if (m != null) ws.add(Mut('Deine Antwort: ${m['status'] == 'yes' ? 'Ich nehme teil' : 'Ich nehme nicht teil'}${js(m['note']).isNotEmpty ? ' – ${js(m['note'])}' : ''}'));
      if (!jb(u['open'])) {
        ws.add(const Mut('Die Anmeldung ist geschlossen.'));
        continue;
      }
      final busy = _busy.contains('su$id');
      ws.add(Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Wrap(spacing: 8, runSpacing: 6, children: [
            smallBtn('Ich nehme teil', busy ? null : () => _signup(c, id, 'yes'), primary: m?['status'] != 'yes'),
            smallBtn('Ich nehme nicht teil', busy ? null : () => _signup(c, id, 'no')),
            if (m != null) smallBtn('Zurückziehen', busy ? null : () => _signup(c, id, '')),
          ])));
      if (u['until'] != null) ws.add(Mut('Anmeldeschluss ${fmtDT(u['until'] as num)}'));
    }
    return MCard(title: 'Übungsplan (${xs.length})', children: ws);
  }

  Future<void> _signup(BuildContext c, int id, String v) async {
    var note = '';
    if (v.isNotEmpty) {
      final n = await inputDlg(c, v == 'yes' ? 'Ich nehme teil' : 'Ich nehme nicht teil', label: 'Bemerkung (optional)', hint: 'z. B. komme etwas später', ok: 'Senden');
      if (n == null) return;
      note = n.trim();
    }
    await _run('su$id', 'ex/signup', {'id': id, 'status': v, 'note': note}, v == 'yes' ? 'Angemeldet' : v == 'no' ? 'Abgemeldet' : 'Antwort zurückgezogen');
  }

  Future<void> _exStart(BuildContext c, J p) async {
    final sec = ji(p['sec']) == 1;
    if (!await confirmDlg(c, sec ? 'Sekundäreinsatz «${js(p['title'])}» jetzt starten? Niemand wird alarmiert.' : '«${js(p['title'])}» jetzt starten? ${jb(p['invited']) ? 'Die aufgebotenen Personen werden' : 'Alle werden'} gemäss Alarmstufe alarmiert.',
        ok: 'Starten')) {
      return;
    }
    final r = await _run('xs${p['id']}', 'ex/start', {'id': p['id']});
    if (r != null && mounted) setState(() => _tabInc = null); // Register neu wählen (Übungsleitung: «Übung»)
    if (r != null && ji(r['id']) != null) await s.setSel(ji(r['id'])!); // gestartete Planung anzeigen (Issue #167)
  }

  /// Startformular als eigene Seite; für den PLS auf der Karte schliesst sie sich kurz und öffnet sich danach wieder
  Future<void> _startKind(BuildContext c, J k) async {
    final nav = Navigator.of(c, rootNavigator: true);
    final d = _StartDraft(k);
    map.closeMenu();
    while (true) {
      final r = await nav.push<String>(MaterialPageRoute(builder: (_) => _StartPage(s: s, d: d)));
      if (r != 'pick') break;
      final p = await map.pickPoint('Tippe auf die Karte, um den PLS zu setzen.');
      if (p != null) d.pls.text = '${p.latitude.toStringAsFixed(6)}, ${p.longitude.toStringAsFixed(6)}';
    }
    d.dispose();
  }

  // ---------- im Einsatz ----------
  Widget _incView(BuildContext c, J i, List<Widget> top) {
    final st = s.st!;
    final inj = jl(st['ex_injects']), xOpen = inj.where((j) => j['released'] == null || j['released'] == 0).length;
    final tabs = <(String, String, int)>[
      ('main', 'Einsatz', 0),
      if (s.canManage) ('lead', 'Leitung', 0),
      if (s.exLead || inj.isNotEmpty) ('ex', 'Übung', xOpen),
      if (st['log'] is List) ('log', 'Log', 0),
      ('info', 'Info', 0),
    ];
    final id = ji(i['id']);
    if (_tabInc != id) {
      _tabInc = id;
      _tab = s.exLead ? 'ex' : 'main';
    }
    if (!tabs.any((t) => t.$1 == _tab)) _tab = 'main';
    final content = switch (_tab) {
      'lead' => _lead(c, i),
      'ex' => _ex(c, i, inj),
      'log' => _log(c),
      'info' => _info(c, i),
      _ => _main(c, i),
    };
    return CustomScrollView(slivers: [
      SliverPadding(padding: const EdgeInsets.symmetric(horizontal: 12), sliver: SliverList.list(children: [...top, ?_incSwitch(c), _head(c, i)])),
      SliverPersistentHeader(pinned: true, delegate: _TabsDelegate(_tabBar(c, tabs))),
      SliverPadding(padding: const EdgeInsets.fromLTRB(12, 4, 12, 12), sliver: SliverList.list(children: [...content, _foot(c)])),
    ]);
  }

  Widget _tabBar(BuildContext c, List<(String, String, int)> tabs) {
    final t = Theme.of(c);
    return Container(
      color: t.colorScheme.surface,
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        child: Row(
            children: tabs
                .map((x) => Padding(
                    padding: const EdgeInsets.only(right: 6),
                    child: ChoiceChip(
                      label: Row(mainAxisSize: MainAxisSize.min, children: [
                        Text(x.$2),
                        if (x.$3 > 0) ...[const SizedBox(width: 4), Chip2('${x.$3}', color: accRed)],
                      ]),
                      selected: _tab == x.$1,
                      showCheckmark: false,
                      onSelected: (_) => setState(() => _tab = x.$1),
                    )))
                .toList()),
      ),
    );
  }

  Widget _head(BuildContext c, J i) {
    final st = s.st!, k = s.kindOf(i), kind = jm(i['kind']);
    final secs = s.now - (jn(i['alarm_at']) ?? s.now), rec = ji(i['record']) ?? 0;
    final lvl = st['level'] == 'crit' && kind['alarm'] != 'crit' ? 'Kritisch (Übungsmodus)' : js(kind['alarm_name']);
    final sec = ji(kind['sec']) == 1, free = ji(kind['sec']) == 2, par = i['parent'] is Map ? jm(i['parent']) : null; // Sekundäreinsatz, freies Ereignis, Folgeeinsatz (Issue #164)
    return MCard(children: [
      Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 6, runSpacing: 4, children: [
        Chip2(k.name, color: hexColor(k.color)),
        Text(js(i['message']), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
      ]),
      const SizedBox(height: 6),
      Mkv([
        (sec ? 'Beginn' : 'Alarm', '${fmtDT(jn(i['alarm_at']))} · vor ${(secs / 60).round()} min'),
        ('Art', free ? 'Freies Ereignis' : ''),
        sec ? ('Art', 'Sekundäreinsatz (ohne Alarm)') : ('Alarmstufe', lvl),
        ('Folgeeinsatz zu', par == null ? '' : '${js(par['kind_name'])} vom ${fmtDT(jn(par['alarm_at']))}${js(par['message']).isNotEmpty ? ': ${js(par['message'])}' : ''}'),
        ('PLS', i['lat'] != null ? s.fc(i['lat'], i['lon']) : ''),
        ('Fliessgeschw.', i['flow_ms'] != null ? '${i['flow_ms']} m/s' : ''),
      ]),
      if (rec != 0 || jb(i['public']))
        Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Wrap(spacing: 6, runSpacing: 4, children: [
              if (rec == 1) const Chip2('● wird aufgezeichnet', color: accRed),
              if (rec == 2) const Chip2('❚❚ Aufzeichnung pausiert'),
              if (jb(i['public'])) Chip2('🌐 öffentlich sichtbar (${jb(i['public_anon']) ? 'ohne Namen' : 'mit Namen'})', color: const Color(0xFF1D7FD1)),
            ])),
    ]);
  }

  // ---------- Register «Einsatz» ----------
  List<Widget> _main(BuildContext c, J i) {
    final st = s.st!, m = s.myPart, joined = s.joined;
    final out = <Widget>[];
    final ai = jm(st['ai']), aiSit = js(ai['sit']).isNotEmpty && ai['sit'] != 'off' && (joined || s.canManage || s.exLead);
    if (aiSit) {
      out.add(Padding(
          padding: const EdgeInsets.only(bottom: 4),
          child: Wrap(children: [
            OutlinedButton(
                style: OutlinedButton.styleFrom(side: const BorderSide(color: Color(0xFFA06CF0), width: 1.5)),
                onPressed: () => _aiSit(c, i),
                child: const Text('✨ Lagezusammenfassung')),
          ])));
    }
    final act = s.iModes.where((x) => jb(x['active'])).toList();
    String mLabel(J x) => '${js(x['name'])}${jb(x['share_location']) ? '' : ' (ohne Standort)'}';
    if (s.exLead) {
      final ks = (ji(jm(i['kind'])['sec']) ?? 0).clamp(0, 2);
      out.add(MCard(title: ks > 0 ? 'Leitung' : 'Übungsleitung', children: [
        Text.rich(TextSpan(children: [
          TextSpan(text: 'Du leitest ${const ['diese Übung', 'diesen geplanten Sekundäreinsatz', 'dieses geplante freie Ereignis'][ks]}${js(st['ex_title']).isNotEmpty ? ' «${js(st['ex_title'])}»' : ''}. ', style: const TextStyle(fontWeight: FontWeight.bold)),
          TextSpan(
              text:
                  'Rechte der Einsatzleitung, du wirst nicht alarmiert. Die Elemente gibst du im Register «Übung» frei.${joined ? ' Du nimmst zusätzlich als Einsatzkraft teil.' : ' Du kannst zusätzlich als Einsatzkraft teilnehmen:'}'),
        ]), style: TextStyle(fontSize: 13, color: Theme.of(c).colorScheme.onSurfaceVariant)),
      ], buttons: [
        if (!joined) for (final x in act) OutlinedButton(onPressed: _busy.contains('join') ? null : () => _join(x), child: Text('Zusätzlich teilnehmen\n${mLabel(x)}', textAlign: TextAlign.center)),
      ]));
    } else if (!joined) {
      final abs = st['my_abs'] is Map ? jm(st['my_abs']) : null;
      out.add(MCard(title: 'Teilnahme', children: [
        if (jb(st['absent']))
          Mut('${abs != null ? '⏸ ${_absTxt(abs)}. ' : ''}Du bist abwesend gemeldet und wurdest nicht alarmiert, kannst aber trotzdem teilnehmen.')
        else if (st['alarm'] == false)
          Mut('Du bist für diesen Einsatz nicht aufgeboten, ${jb(jm(i['kind'])['open_all']) ? 'kannst aber trotzdem teilnehmen.' : 'die Einsatzleitung hat aber allen den Beitritt erlaubt.'}'),
      ], buttons: [
        for (final x in act) FilledButton(onPressed: _busy.contains('join') ? null : () => _join(x), child: Text('Einsatz annehmen\n${mLabel(x)}', textAlign: TextAlign.center)),
      ]));
    }
    if (st['plan'] is Map) out.add(_planCard(jm(st['plan'])));
    if (joined && m != null) {
      final sh = s.shares(js(m['mode'])), lm = s.leadMode(js(m['mode']));
      out.add(MCard(title: 'Mein Einsatz', children: [
        Mkv([
          ('Einrückoption', '${js(s.modeOf(js(m['mode']))['name'])}${lm ? ' (Rechte der Einsatzleitung, keine Spezialrolle)' : ''}'),
          ('Standort', sh ? (s.manualPos != null ? 'manuell gesetzt (Test)' : (s.locStatus.isNotEmpty ? s.locStatus : (s.locErr.isNotEmpty ? 'Standort ${s.locErr}' : 'Standort wird ermittelt …'))) : 'wird bei dieser Einrückoption nicht geteilt'),
        ]),
        if (!lm)
          Padding(
              padding: const EdgeInsets.only(top: 6),
              child: _roleSel(ji(m['role_id']), (v) => s.act('incident/role', {'role_id': v}), label: 'Meine Spezialrolle')),
      ], buttons: [
        if (sh)
          OutlinedButton(
              onPressed: () async {
                if (s.manualPos != null) {
                  s.setManual(null);
                  return;
                }
                map.closeMenu();
                final p = await map.pickPoint('Tippe auf die Karte, um deinen Standort zu setzen.');
                if (p != null) s.setManual(p);
              },
              child: Text(s.manualPos != null ? 'GPS-Standort verwenden' : 'Standort manuell setzen (Test)')),
        OutlinedButton(
            onPressed: _busy.contains('leave')
                ? null
                : () async {
                    s.selfLeft = true;
                    final r = await _run('leave', 'incident/leave');
                    if (r == null) s.selfLeft = false;
                    if (s.manualPos != null) s.setManual(null);
                  },
            child: const Text('Einsatz verlassen')),
      ]));
    }
    if (jb(st['can_flow'])) {
      final srv = i['flow_ms'] == null ? '' : '${i['flow_ms']}';
      if (_flSrv != srv && !_flFocus.hasFocus) {
        _flSrv = srv;
        _fl.text = srv;
      }
      final area = s.feat('area'), secs = s.now - (jn(i['alarm_at']) ?? s.now);
      out.add(MCard(title: area ? 'Fliessgeschwindigkeit & Suchgebiet' : 'Fliessgeschwindigkeit', children: [
        Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
          Expanded(
              child: TextField(
                  controller: _fl,
                  focusNode: _flFocus,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(labelText: 'Fliessgeschwindigkeit (m/s)', isDense: true),
                  onSubmitted: (_) => _setFlow())),
          const SizedBox(width: 8),
          FilledButton(onPressed: _busy.contains('flow') ? null : _setFlow, child: Text(area ? 'Setzen & berechnen' : 'Setzen')),
        ]),
        const SizedBox(height: 6),
        Mut('${(s.area?.info ?? const <String>[]).join(' ')} Zeit seit Alarm: ${(secs / 60).round()} min.'.trim()),
      ]));
    }
    final marks = jl(st['marks']);
    if (joined && marks.isNotEmpty) {
      out.add(MCard(title: 'Frühere Rettling-Markierungen', children: [
        for (final x in marks)
          Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(children: [
                Expanded(child: Text('${jb(x['manual']) ? 'Einsatzleitung (manuell)' : js(x['name'])} · ${fmtT(jn(x['ts']))}${s.fcs(x['lat'], x['lon'])}', style: const TextStyle(fontSize: 13))),
                if (s.canManage || ji(x['user_id']) == s.uid) smallBtn('Entfernen', () => s.act('incident/mark/remove', {'id': x['id']})),
              ])),
        const Mut('Neue Markierungen über den Kartenknopf «Rettlinge».'),
      ]));
    }
    final hy = hydroCard(c, s, map, widget.openPrefs); // Pegel & Abfluss (Issue #165)
    if (hy != null) out.add(hy);
    final sm = _startMore(c);
    if (sm != null) out.add(sm);
    final t = _tools();
    if (t != null) out.add(t);
    return out;
  }

  Future<void> _join(J x) async {
    // Parallele Einsätze (Issue #167): man nimmt höchstens an einem teil, der Server verlässt den anderen
    final o = s.myInc, i = s.inc;
    if (o != null && i != null && ji(o['id']) != ji(i['id']) &&
        !await confirmDlg(context, 'Du nimmst an «${js(o['kind_name'])}: ${js(o['message'])}» teil. Diesen Einsatz annehmen und den anderen verlassen?', ok: 'Annehmen')) {
      return;
    }
    final r = await _run('join', 'incident/join', {'mode': x['code']});
    if (r != null && mounted) {
      setState(() => _tab = 'main');
      map.focusArea(); // zurück aufs Einsatzgebiet
    }
  }

  // ---------- Parallele Einsätze (Issue #167) ----------
  /// Auswahl, sobald mehr als ein Einsatz läuft (incSwitch)
  Widget? _incSwitch(BuildContext c) {
    final il = s.incidents;
    if (il.length < 2) return null;
    final cur = ji(s.inc?['id']);
    return MCard(title: 'Laufende Einsätze (${il.length})', children: [
      for (final x in il)
        Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(children: [
              Expanded(
                  child: Text.rich(TextSpan(children: [
                WidgetSpan(alignment: PlaceholderAlignment.middle, child: Chip2(js(x['kind_name']), color: hexColor(x['kind_color']))),
                TextSpan(text: ' ${js(x['message']).isEmpty ? '–' : js(x['message'])}', style: TextStyle(fontWeight: ji(x['id']) == cur ? FontWeight.w600 : null)),
                TextSpan(
                    text:
                        ' · ${fmtDT(jn(x['alarm_at']))}${jb(x['mine']) ? ' · du nimmst teil' : jb(x['ex_lead']) ? ' · Übungsleitung' : jb(x['alarm']) && !jb(x['joined']) ? ' · alarmiert' : ''}',
                    style: TextStyle(fontSize: 12, color: Theme.of(c).colorScheme.onSurfaceVariant, fontWeight: jb(x['alarm']) && !jb(x['joined']) && !jb(x['mine']) ? FontWeight.bold : null)),
              ]))),
              const SizedBox(width: 6),
              ji(x['id']) == cur ? const Mut('angezeigt') : smallBtn('Anzeigen', () => s.setSel(ji(x['id'])!)),
            ])),
    ]);
  }

  /// im Einsatz: weiteren Einsatz bzw. eine Planung starten, läuft parallel (startMore)
  Widget? _startMore(BuildContext c) {
    if (s.demo || s.st?['start_plans'] == null) return null; // Demo und ältere Server: nur ein Einsatz
    final ka = _kindsNow().where((k) => jb(k['can'])).toList(), xp = jl(s.st?['start_plans']);
    if (ka.isEmpty && xp.isEmpty) return null;
    const xpk = ['Übung', 'Sekundäreinsatz, ohne Alarm', 'Freies Ereignis'];
    return MCard(title: 'Weiteren Einsatz starten', children: [
      const Mut('Läuft parallel zu den übrigen Einsätzen. Teilnehmen kann man nur an einem Einsatz gleichzeitig.'),
      for (final p in xp)
        Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(children: [
              Expanded(child: Text('${js(p['title'])} · ${xpk[(ji(p['sec']) ?? 0).clamp(0, 2)]}${p['at'] != null ? ' · ${fmtDT(jn(p['at']))}' : ''}')),
              smallBtn('Starten', _busy.contains('xs${p['id']}') ? null : () => _exStart(c, p)),
            ])),
    ], buttons: [
      for (final k in ka) OutlinedButton(onPressed: () => _startKind(c, k), child: Text('${js(k['name'])} starten')),
    ]);
  }

  void _setFlow() {
    final v = double.tryParse(_fl.text.trim().replaceAll(',', '.'));
    if (v == null || v < 0) {
      s.toast('Bitte eine Fliessgeschwindigkeit in m/s eingeben', 'err');
      return;
    }
    _flFocus.unfocus();
    _flSrv = null;
    _run('flow', 'incident/flow', {'flow_ms': v});
  }

  /// Auswahl der Spezialrolle (roleSel), null = keine
  Widget _roleSel(int? cur, void Function(int?) onChanged, {String? label}) {
    final ids = s.iRoles.map((r) => ji(r['id'])).toSet();
    return DropdownButtonFormField<int?>(
      key: ValueKey('rs$cur'),
      initialValue: ids.contains(cur) ? cur : null,
      isExpanded: true,
      decoration: InputDecoration(labelText: label, isDense: true),
      items: [
        const DropdownMenuItem<int?>(value: null, child: Text('Keine Spezialrolle')),
        ...s.iRoles.map((r) => DropdownMenuItem<int?>(value: ji(r['id']), child: Text(js(r['name']), overflow: TextOverflow.ellipsis))),
      ],
      onChanged: (v) {
        if (v != cur) onChanged(v);
      },
    );
  }

  // ---------- Register «Leitung» ----------
  String _invTxt(dynamic a0) {
    if (a0 is! Map || jb(a0['all'])) return 'Alle Personen${a0 is Map && (ji(a0['absent']) ?? 0) > 0 ? ', davon ${a0['absent']} abwesend (nicht alarmiert)' : ''}';
    final a = jm(a0), n = ji(a['n']) ?? 0, p = ji(a['persons']) ?? 0, ab = ji(a['absent']) ?? 0;
    final parts = [...(a['groups'] is List ? (a['groups'] as List).map((e) => '$e') : const <String>[]), if (p > 0) '$p ${p == 1 ? 'Einzelperson' : 'Einzelpersonen'}'];
    return '${parts.join(', ')} ($n ${n == 1 ? 'Person' : 'Personen'})${ab > 0 ? ', davon $ab abwesend (nicht alarmiert)' : ''}';
  }

  List<Widget> _lead(BuildContext c, J i) {
    if (!s.canManage) return const [];
    final st = s.st!, rec = ji(i['record']) ?? 0, recorded = jb(i['recorded']);
    final recS = switch (rec) {
      0 => (recorded ? 'Beendet. Bisherige Aufzeichnung bleibt für das Replay erhalten.' : 'Aus', [('start', recorded ? 'Wieder aufnehmen' : 'Aufzeichnung starten')]),
      1 => ('● Läuft', [('pause', 'Pausieren'), ('stop', 'Beenden')]),
      2 => ('❚❚ Pausiert', [('resume', 'Fortsetzen'), ('stop', 'Beenden')]),
      _ => ('?', <(String, String)>[]),
    };
    final act = s.participants.where((p) => p['mode'] != 'declined').toList();
    final hasPls = i['lat'] != null;
    return [
      MCard(title: 'Aufgebot', children: [
        Mut(_invTxt(i['aufgebot'])),
        if (i['aufgebot'] != null)
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: jb(i['open_join']),
            title: const Text('Beitritt für alle erlauben (auch nicht Aufgebotene sehen den Einsatz und können ihn annehmen, ohne Alarm)', style: TextStyle(fontSize: 14)),
            onChanged: (v) async {
              if (v == true && !await confirmDlg(c, 'Allen den Beitritt erlauben? Alle Personen sehen den Einsatz dann und können ihn annehmen (ohne Alarm).')) return;
              s.act('incident/open', {'on': v == true ? 1 : 0});
            },
          ),
      ], buttons: [
        if (i['aufgebot'] != null) OutlinedButton(onPressed: () => _invAdd(c, i), child: const Text('Nachbieten')),
      ]),
      MCard(title: 'PLS des Einsatzes', children: [
        Mut('${hasPls ? '${s.fc(i['lat'], i['lon']).isEmpty ? 'gesetzt' : s.fc(i['lat'], i['lon'])} · Drift ab ${fmtDT(jn(i['pls_at']))}' : 'Nicht gesetzt. Ohne PLS des Einsatzes braucht jeder Rettling einen eigenen PLS.'} Gilt für alle Rettlinge ohne eigenen PLS.'),
      ], buttons: [
        OutlinedButton(onPressed: () => widget.plsPick(c), child: Text(hasPls ? 'PLS verschieben' : 'PLS setzen')),
        if (hasPls)
          OutlinedButton(
              onPressed: () async {
                if (await confirmDlg(c, 'PLS des Einsatzes entfernen? Rettlinge ohne eigenen PLS haben dann kein Suchgebiet mehr.', ok: 'Entfernen', danger: true)) {
                  s.act('incident/pls', {'clear': 1});
                }
              },
              child: const Text('PLS entfernen')),
      ]),
      // Flusssperren (Issue #182): feste Standorte mit Wirkung «begrenzt Suchgebiet»; einzelne nimmt man im Popup oder im Fenster «Markierungen» aus
      if (s.feat('area') && s.places.any((f) => placeEff(s, f, 'stop')))
        MCard(title: 'Flusssperren', children: [
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: jb(i['stop_on']),
            title: const Text('Flusssperren fester Standorte im Suchgebiet berücksichtigen', style: TextStyle(fontSize: 14)),
            onChanged: (v) => s.act('incident/stops', {'on': v == true ? 1 : 0}),
          ),
          Mut(s.places.where((f) => placeEff(s, f, 'stop')).map((f) => '${js(f['name'])}${(i['stop_off'] is List && (i['stop_off'] as List).contains(f['id'])) ? ' (ausgenommen)' : ''}').join(' · ')),
          const Mut('Das Suchgebiet endet dort wie bei einem Sicherungsposten. Einzelne Standorte nimmt man im Popup oder im Fenster «Markierungen» aus, z. B. wenn die Person ein Wehr passiert haben könnte.'),
        ]),
      if (s.feat('rec'))
        MCard(title: 'Aufzeichnung', children: [
          Mkv([('Stand', recS.$1)]),
          const Mut('Gespeichert werden die Standortverläufe aller Teilnehmenden, solange die Aufzeichnung läuft (Replay in der Verwaltung).'),
        ], buttons: [
          for (final a in recS.$2) OutlinedButton(onPressed: _busy.contains('rec') ? null : () => _run('rec', 'incident/record', {'action': a.$1}), child: Text(a.$2)),
        ]),
      if (st['air_inc'] != null)
        MCard(title: 'Helikopter', children: [
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: jb(st['air_inc']),
            title: const Text('Helikopter auf der Karte (Luftlage aus ADS-B-Daten)', style: TextStyle(fontSize: 14)),
            onChanged: (v) => s.act('incident/air/set', {'on': v == true ? 1 : 0}),
          ),
          const Mut('Gilt für alle in diesem Einsatz. Wer sie sieht, legt die Verwaltung fest.'),
        ]),
      if (s.feat('voice')) ...voiceLeadCards(c, s), // Funk (Issue #113)
      if (s.feat('pub')) _pubSec(c, i),
      MCard(title: 'Einsatzkräfte (${act.where((p) => p['left_at'] == null).length})', children: [
        if (act.isEmpty) const Mut('Noch niemand hat den Einsatz angenommen.'),
        for (final p in act) _crewRow(c, p),
      ], buttons: [
        OutlinedButton(
            onPressed: () {
              map.closeMenu();
              map.openPanel('people');
            },
            child: const Text('Einsatzkräfte auf der Karte')),
      ]),
      MCard(title: 'Einsatz beenden', color: accRed.withValues(alpha: .12), children: [
        const Mut('Beendet den Einsatz für alle. Log und Replay bleiben in der Verwaltung.'),
      ], buttons: [
        FilledButton(
            style: FilledButton.styleFrom(backgroundColor: accRed),
            onPressed: _busy.contains('end')
                ? null
                : () async {
                    if (!await confirmDlg(c, 'Einsatz für alle beenden?', ok: 'Einsatz beenden', danger: true)) return;
                    final r = await _run('end', 'incident/end');
                    if (r != null) map.closeMenu();
                  },
            child: const Text('Einsatz beenden')),
      ]),
    ];
  }

  Widget _crewRow(BuildContext c, J p) {
    final left = p['left_at'] != null, lm = s.leadMode(js(p['mode'])), uid = ji(p['user_id']);
    final roleName = js(s.roleOf(p['role_id'])?['name']);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
        Expanded(
            flex: 5,
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(js(p['name']), style: const TextStyle(fontWeight: FontWeight.w600)),
              Mut(left ? 'verlassen' : js(s.modeOf(js(p['mode']))['name']), size: 12),
            ])),
        const SizedBox(width: 6),
        Expanded(
            flex: 6,
            child: left
                ? Mut(roleName)
                : lm
                    ? const Mut('Rechte der Einsatzleitung', size: 12)
                    : _roleSel(ji(p['role_id']), (v) => s.act('incident/role/set', {'user_id': uid, 'role_id': v}))),
        SizedBox(
            width: 40,
            child: !left && uid != s.uid
                ? IconButton(
                    tooltip: 'Aus dem Einsatz entfernen',
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: () async {
                      if (await confirmDlg(c, '${js(p['name'])} aus dem Einsatz entfernen?', ok: 'Entfernen', danger: true)) s.act('incident/kick', {'user_id': uid});
                    })
                : null),
      ]),
    );
  }

  Widget _pubSec(BuildContext c, J i) {
    final tok = js(i['public_token']), u = jb(i['public']) && tok.isNotEmpty ? '${s.acc.base}public.html?k=$tok' : '';
    final log = jb(i['public_log']);
    Widget cb(String t, bool v, void Function(bool) f) => CheckboxListTile(
        contentPadding: EdgeInsets.zero,
        dense: true,
        controlAffinity: ListTileControlAffinity.leading,
        value: v,
        title: Text(t, style: const TextStyle(fontSize: 14)),
        onChanged: (x) => f(x == true));
    return MCard(title: 'Öffentliche Ansicht (ohne Login)', children: [
      cb('Zuschauen ohne Anmeldung erlauben', jb(i['public']), (v) async {
        if (v &&
            !await confirmDlg(
                c,
                'Öffentliche Ansicht einschalten?\n\nWer den Link hat, sieht ohne Anmeldung die Karte mit den Standorten der Einsatzkräfte, Markierungen, Bereichen und Rettlingen'
                '${log ? ', dazu das Einsatzprotokoll mit den Nachrichten' : ' (keine Nachrichten, kein Einsatzlog)'}.',
                ok: 'Einschalten')) {
          return;
        }
        s.act('incident/public', {'on': v});
      }),
      cb('Anonym: Namen ausblenden (nur Spezialrolle bzw. Einrückoption)', jb(i['public_anon']), (v) => s.act('incident/public', {'anon': v})),
      cb('Einsatzprotokoll zeigen (live, mit Nachrichten; anonym wie die Ansicht)', log, (v) => s.act('incident/public', {'log': v})),
      if (u.isNotEmpty) ...[
        const SizedBox(height: 4),
        SelectableText(u, style: const TextStyle(fontSize: 12)),
        const SizedBox(height: 4),
        Wrap(spacing: 8, children: [
          smallBtn('Link kopieren', () async {
            await Clipboard.setData(ClipboardData(text: u));
            s.toast('Link kopiert.');
          }),
          smallBtn('Öffnen', () async {
            try {
              await launchUrl(Uri.parse(u), mode: LaunchMode.externalApplication);
            } catch (_) {
              s.toast('Browser nicht verfügbar', 'err');
            }
          }),
        ]),
      ],
      const SizedBox(height: 4),
      Mut('Sichtbar sind Karte, Standorte, Markierungen, Bereiche und Rettlinge${log ? ', dazu das Einsatzprotokoll mit den Nachrichten' : ', keine Nachrichten und kein Einsatzlog'}. Ausschalten macht den Link ungültig.'),
    ]);
  }

  /// Nachbieten (invAdd): weitere Gruppen, bei Typen mit Einzelpersonen auch diese, oder alle; alarmiert nur die neu Aufgebotenen
  Future<void> _invAdd(BuildContext c, J i) async {
    J o;
    try {
      o = await s.api.call('start/options');
    } catch (e) {
      s.fail(e);
      return;
    }
    if (jl(o['groups']).isEmpty) {
      s.toast('Es gibt keine Einsatzgruppen.', 'info');
      return;
    }
    final k = jm(i['kind']), kid = '${ji(i['kind_id']) ?? ji(k['id'])}';
    final add = jm(o['add']), allowed = add[kid] == null ? null : _ints(add[kid]);
    final v = _Inv();
    if (!c.mounted) return;
    final ok = await formDlg(
        c,
        'Nachbieten',
        (d, ss) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
              const Mut('Alarmiert nur die neu aufgebotenen Personen. Bereits Aufgebotene erhalten keinen weiteren Alarm.'),
              const SizedBox(height: 6),
              _InvPick(o: o, k: k, allowed: allowed, v: v, ss: ss),
            ]),
        ok: 'Nachbieten', valid: () {
      final g = v.get();
      if (g['all'] == null && v.groups.isEmpty && v.persons.isEmpty) {
        s.toast('Bitte mindestens eine Einsatzgruppe wählen', 'err');
        return false;
      }
      return true;
    });
    final body = v.get();
    v.dispose();
    if (ok) await s.act('incident/invite/add', body, 'Nachgeboten.');
  }

  // ---------- Register «Übung» ----------
  bool _rel(J j) => j['released'] != null && j['released'] != 0;
  String _rn(J x) => js(x['name']).isEmpty ? 'Unbekannter Rettling' : js(x['name']);

  String _exWhat(List<J> js0, J j) {
    final kind = js(j['kind']), ref = (kind == 'seen' || kind == 'extdel') ? js0.where((x) => x['id'] == j['ref_id']).firstOrNull : null;
    final ago = ji(j['ago']);
    final t = j['ago'] == null ? ', Zeitpunkt gibst du bei der Freigabe an' : ((ago ?? 0) > 0 ? ', Drift ab $ago min vor der Freigabe' : '');
    if (kind == 'ext') return 'Erfasst die externe Einsatzkraft «${js(j['name'])}» an diesem Standort.';
    if (kind == 'extdel') return 'Entfernt die externe Einsatzkraft «${ref != null ? js(ref['name']) : '?'}».';
    if (kind == 'pls') return 'Setzt den PLS des Einsatzes$t.';
    if (kind == 'res') return 'Erfasst den Rettling «${js(j['name'])}» mit eigenem PLS$t.';
    return j['ref_id'] == null
        ? 'Meldet einen gesichteten Rettling und erfasst ihn als ${js(j['name']).isNotEmpty ? '«${js(j['name'])}»' : '«Unbekannter Rettling»'} mit PLS hier$t.'
        : 'Meldet «${ref != null ? _rn(ref) : '?'}» gesichtet und setzt den PLS neu$t.';
  }

  List<Widget> _ex(BuildContext c, J i, List<J> inj) {
    final open = inj.where((j) => !_rel(j)).length;
    return [
      Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Text.rich(TextSpan(children: [
            const TextSpan(text: 'Elemente der Übung: ', style: TextStyle(fontWeight: FontWeight.bold)),
            TextSpan(text: '$open offen, ${inj.length - open} freigegeben.'),
          ]))),
      if (inj.isEmpty) const Mut('Keine vorbereiteten Elemente.'),
      for (final j in inj) _exCard(c, i, inj, j),
      const Padding(
          padding: EdgeInsets.only(top: 8),
          child: Mut(
              'Nicht freigegebene Elemente sieht nur die Übungsleitung. Die Meldung geht bei der Freigabe als Nachricht an alle. Elemente mit ⏱ gibt der Server zur angegebenen Zeit selbst frei, du kannst sie auch früher freigeben.')),
    ];
  }

  Widget _exCard(BuildContext c, J i, List<J> inj, J j) {
    final kind = js(j['kind']), refd = kind == 'seen' || kind == 'extdel';
    final ref = refd ? inj.where((x) => x['id'] == j['ref_id']).firstOrNull : null;
    final blocked = refd && j['ref_id'] != null && !(ref != null && _rel(ref));
    final what = kind == 'res' || kind == 'ext'
        ? ': ${js(j['name'])}'
        : kind == 'extdel'
            ? ': ${ref != null ? js(ref['name']) : '?'}'
            : ref != null
                ? ': ${_rn(ref)}'
                : kind == 'seen'
                    ? (js(j['name']).isNotEmpty ? ': neu, ${js(j['name'])}' : ': unbekannt')
                    : '';
    final rel = _rel(j), key = 'xr${j['id']}';
    final auto = jn(j['auto']), at = jn(i['alarm_at']);
    return MCard(children: [
      Text.rich(TextSpan(children: [
        TextSpan(text: js(j['label']), style: const TextStyle(fontWeight: FontWeight.bold)),
        TextSpan(text: '  ${_exk[kind] ?? kind}$what${s.fcs(j['lat'], j['lon'])}', style: TextStyle(fontSize: 13, color: Theme.of(c).colorScheme.onSurfaceVariant)),
      ])),
      if (js(j['msg']).isNotEmpty) Padding(padding: const EdgeInsets.only(top: 3), child: Text('Meldung: ${js(j['msg'])}')),
      if (rel) Padding(padding: const EdgeInsets.only(top: 4), child: Mut('✓ freigegeben ${fmtT(jn(j['released']))}${js(j['released_name']).isNotEmpty ? ' von ${js(j['released_name'])}' : ''}')),
      if (!rel && auto != null && at != null)
        Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Mut(
                '⏱ automatisch um ${fmtHM(at + 60 * auto)}${blocked ? (kind == 'extdel' ? ', sobald die externe Einsatzkraft freigegeben ist' : ', sobald der Rettling freigegeben ist') : ''}')),
      if (!rel && blocked) Padding(padding: const EdgeInsets.only(top: 4), child: Mut(kind == 'extdel' ? 'Zuerst die externe Einsatzkraft freigeben' : 'Zuerst den Rettling freigeben')),
    ], buttons: [
      if (!rel) FilledButton(onPressed: blocked || _busy.contains(key) ? null : () => _exRelease(c, inj, j), child: const Text('Freigeben')),
      if (llOf(j) != null)
        OutlinedButton(
            onPressed: () {
              map.follow(null);
              map.closeMenu();
              map.centerOn(llOf(j)!);
            },
            child: const Text('Zeigen')),
    ]);
  }

  Future<void> _exRelease(BuildContext c, List<J> inj, J j) async {
    final key = 'xr${j['id']}', what = _exWhat(inj, j), msg = js(j['msg']);
    if (j['ago'] != null) {
      if (await confirmDlg(c, '$what${msg.isNotEmpty ? '\nMeldung an alle: $msg' : ''}', title: '«${js(j['label'])}» freigeben?', ok: 'Freigeben')) {
        await _run(key, 'ex/release', {'id': j['id']});
      }
      return;
    }
    // kein vorbereiteter Zeitpunkt: die Übungsleitung gibt ihn jetzt an (exRelAt)
    final t = TextEditingController();
    int? ago;
    final ok = await formDlg(
        c,
        '«${js(j['label'])}» freigeben',
        (d, ss) {
          final a = _exAgo(t.text);
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Mut('${what.replaceAll(', Zeitpunkt gibst du bei der Freigabe an', '')}${msg.isNotEmpty ? '\nMeldung an alle: $msg' : ''}'),
            const SizedBox(height: 8),
            TextField(
                controller: t,
                autofocus: true,
                keyboardType: TextInputType.datetime,
                decoration: const InputDecoration(
                    labelText: 'Zeitpunkt des PLS (Beginn der Drift)', hintText: 'jetzt', helperText: 'leer = jetzt, Minuten vorher (z. B. 15) oder Uhrzeit (z. B. 14:20)', helperMaxLines: 2),
                onChanged: (_) => ss(() {})),
            const SizedBox(height: 6),
            Mut(a == null || a > 1440
                ? 'Nicht erkannt'
                : 'PLS um ${fmtHM(DateTime.now().millisecondsSinceEpoch / 1000 - a * 60)}${a > 0 ? ' (vor $a min)' : ' (jetzt)'}'),
          ]);
        },
        ok: 'Freigeben',
        valid: () {
          final a = _exAgo(t.text);
          if (a == null || a > 1440) {
            s.toast('Zeitpunkt nicht erkannt: leer, Minuten (0 bis 1440) oder Uhrzeit wie 14:20', 'err');
            return false;
          }
          ago = a;
          return true;
        });
    t.dispose();
    if (ok && ago != null) await _run(key, 'ex/release', {'id': j['id'], 'ago': ago});
  }

  /// leer = jetzt, Zahl = Minuten vorher, HH:MM = Uhrzeit (liegt sie in der Zukunft, gilt der Vortag)
  int? _exAgo(String v0) {
    final v = v0.trim();
    if (v.isEmpty) return 0;
    if (RegExp(r'^\d+$').hasMatch(v)) return int.parse(v);
    final m = RegExp(r'^(\d{1,2})[:.](\d{2})$').firstMatch(v);
    if (m == null) return null;
    final h = int.parse(m.group(1)!), mi = int.parse(m.group(2)!);
    if (h > 23 || mi > 59) return null;
    final n = DateTime.now();
    var t = DateTime(n.year, n.month, n.day, h, mi);
    if (t.difference(n).inSeconds > 60) t = t.subtract(const Duration(days: 1));
    final d = (n.difference(t).inSeconds / 60).round();
    return d < 0 ? 0 : d;
  }

  // ---------- Register «Log» ----------
  List<Widget> _log(BuildContext c) {
    final l = jl(s.st?['log']).toList()..sort((a, b) => ((jn(b['ts']) ?? 0).compareTo(jn(a['ts']) ?? 0)));
    if (l.isEmpty) return const [Padding(padding: EdgeInsets.only(top: 8), child: Mut('Noch keine Einträge.'))];
    final t = Theme.of(c);
    return [
      for (final x in l.take(300))
        Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(
                  child: Text.rich(
                      TextSpan(children: [
                        TextSpan(text: '${fmtT(jn(x['ts']))}  ', style: TextStyle(color: t.colorScheme.onSurfaceVariant, fontFeatures: const [FontFeature.tabularFigures()])),
                        TextSpan(text: js(x['name']).isEmpty ? 'System' : js(x['name']), style: const TextStyle(fontWeight: FontWeight.bold)),
                        TextSpan(text: ' ${js(x['text'])}'),
                      ]),
                      style: const TextStyle(fontSize: 13))),
              ?voiceLogBtn(s, x), // Durchsage abspielen (Funk, Issue #113)
            ])),
      if (l.length > 300) const Mut('Ältere Einträge im Log der Verwaltung.'),
    ];
  }

  // ---------- Register «Info» ----------
  String _leadWhy(J i, J? m, bool joined) {
    final why = <String>[], r = joined && m != null ? s.roleOf(m['role_id']) : null;
    if (joined && m != null && s.leadMode(js(m['mode']))) why.add('Einrückoption ${js(s.modeOf(js(m['mode']))['name'])}');
    if (r != null && jb(r['is_lead'])) why.add('Spezialrolle ${js(r['name'])}');
    if (s.can('inc_lead')) why.add('Benutzerrolle mit «Jeden Einsatz leiten»');
    if (ji(i['created_by']) == s.uid) why.add('hat den Einsatz gestartet');
    if (s.exLead) why.add('Übungsleitung');
    return why.isEmpty ? 'laut Server' : why.join(', ');
  }

  List<Widget> _info(BuildContext c, J i) {
    final st = s.st!, m = s.myPart, joined = s.joined, r = joined && m != null ? s.roleOf(m['role_id']) : null, sh = s.sharing;
    final yes = <String>[], no = <String>[];
    void add(bool ok, String t) => (ok ? yes : no).add(t);
    final cm = s.canManage;
    for (final t in ['Einsatz verwalten und beenden', 'Spezialrollen setzen', 'Einsatzkräfte entfernen', 'Rettlinge entfernen', 'Markierungen und Bereiche setzen', 'Externe Einsatzkräfte erfassen', 'Öffentliche Ansicht steuern']) {
      add(cm, t);
    }
    add(jb(st['can_flow']), 'Fliessgeschwindigkeit setzen');
    add(jb(st['can_read_messages']), 'Nachrichten lesen');
    add(joined && jb(st['can_message']), 'Nachrichten senden');
    add(sh, 'Standort teilen');
    add(cm || joined, 'Rettlinge erfassen, Standort und «gerettet» markieren');
    if (r != null && jb(r['is_post']) && s.feat('area')) yes.add('Posten: Suchgebiet endet am eigenen Standort');
    Widget li(String t, bool ok) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(ok ? '✓ ' : '✕ ', style: TextStyle(color: ok ? okGreen : Theme.of(c).colorScheme.onSurfaceVariant, fontWeight: FontWeight.bold)),
          Expanded(child: Text(t, style: TextStyle(color: ok ? null : Theme.of(c).colorScheme.onSurfaceVariant))),
        ]));
    final im = joined && m != null
        ? '${js(s.modeOf(js(m['mode']))['name'])}${s.leadMode(js(m['mode'])) ? ' (Rechte der Einsatzleitung)' : ''}${r != null ? ' · Spezialrolle ${js(r['name'])}' : ''}'
        : 'nicht angenommen';
    return [
      MCard(title: 'Meine Rechte im Einsatz', children: [...yes.map((t) => li(t, true)), ...no.map((t) => li(t, false))]),
      MCard(title: 'Angaben', children: [
        Mkv([('Im Einsatz', im), if (cm) ('Einsatzleitung', _leadWhy(i, m, joined)), ..._infoRows()])
      ]),
    ];
  }

  // ---------- AI-Assistent: Lagezusammenfassung ----------
  Future<void> _aiSit(BuildContext c, J i) async {
    await showDialog<void>(context: c, builder: (_) => _AiSitDlg(s: s, id: ji(i['id'])!));
  }
}

/// Register-Leiste, bleibt beim Scrollen oben (.mtabs)
class _TabsDelegate extends SliverPersistentHeaderDelegate {
  _TabsDelegate(this.child);
  final Widget child;
  @override
  double get minExtent => 48;
  @override
  double get maxExtent => 48;
  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) => SizedBox.expand(child: child);
  @override
  bool shouldRebuild(_TabsDelegate old) => true;
}

/// Auswahl des Aufgebots (invPick): Gruppen, Einzelpersonen, alle
class _Inv {
  final groups = <int>{};
  final persons = <int>[];
  bool all = false;
  final q = TextEditingController();

  /// Werte für incident/start bzw. incident/invite/add
  J get() => all ? {'all': 1} : {'groups': groups.toList(), 'persons': persons};
  void dispose() => q.dispose();
}

class _InvPick extends StatelessWidget {
  const _InvPick({required this.o, required this.k, required this.allowed, required this.v, required this.ss});
  final J o, k;
  final List<int>? allowed; // null = alle Gruppen, dann auch «Alle Personen»
  final _Inv v;
  final StateSetter ss;

  @override
  Widget build(BuildContext context) {
    final off = _ints(k['grp_off']);
    final gs = jl(o['groups']).where((g) => !off.contains(ji(g['id'])) && (allowed == null || allowed!.contains(ji(g['id'])))).toList();
    if (jl(o['groups']).isEmpty) return const SizedBox.shrink();
    final kid = '${ji(k['id'])}';
    final kpRaw = jm(o['kind_people'])[kid], kp = kpRaw == null ? null : _ints(kpRaw), na = ji(jm(o['kind_absent'])[kid]) ?? 0;
    final pp = jb(k['persons']) ? jl(o['people']).where((u) => kp == null || kp.contains(ji(u['id']))).toList() : <J>[];
    String pn(int id) => js(pp.where((u) => ji(u['id']) == id).firstOrNull?['name']).isEmpty ? '?' : js(pp.where((u) => ji(u['id']) == id).first['name']);
    final q = v.q.text.trim().toLowerCase();
    final match = pp.where((u) => !v.persons.contains(ji(u['id'])) && (q.isEmpty || js(u['name']).toLowerCase().contains(q))).toList();
    Widget row(Widget ctl, Widget t) => Row(children: [ctl, Expanded(child: t)]);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
      const Padding(padding: EdgeInsets.only(top: 4, bottom: 2), child: Text('Aufgebot', style: TextStyle(fontWeight: FontWeight.bold))),
      if (allowed == null) ...[
        row(Checkbox(value: v.all, onChanged: (x) => ss(() => v.all = x == true)), const Text('Alle aufbietbaren Personen alarmieren')),
        if (na > 0) Mut('$na ${na == 1 ? 'Person ist' : 'Personen sind'} abwesend und ${na == 1 ? 'wird' : 'werden'} nicht alarmiert.'),
      ],
      Opacity(
        opacity: v.all ? .4 : 1,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          if (gs.isEmpty) Mut('Du darfst bei «${js(k['name'])}» keine Einsatzgruppe aufbieten.'),
          for (final g in gs)
            row(
                Checkbox(
                    value: v.groups.contains(ji(g['id'])),
                    onChanged: v.all ? null : (x) => ss(() => x == true ? v.groups.add(ji(g['id'])!) : v.groups.remove(ji(g['id'])))),
                Text.rich(TextSpan(children: [
                  WidgetSpan(
                      alignment: PlaceholderAlignment.middle,
                      child: Container(width: 10, height: 10, margin: const EdgeInsets.only(right: 6), decoration: BoxDecoration(color: hexColor(g['color']), shape: BoxShape.circle))),
                  TextSpan(text: js(g['name'])),
                  TextSpan(
                      text: '  ${g['n']} ${ji(g['n']) == 1 ? 'Person' : 'Personen'}${(ji(g['na']) ?? 0) > 0 ? ', davon ${g['na']} abwesend' : ''}',
                      style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant)),
                ]))),
          if (pp.isNotEmpty) ...[
            const SizedBox(height: 6),
            TextField(
                controller: v.q,
                enabled: !v.all,
                decoration: const InputDecoration(labelText: 'Einzelpersonen', hintText: 'Liste filtern: Name', isDense: true, prefixIcon: Icon(Icons.search, size: 18)),
                onChanged: (_) => ss(() {}),
                onSubmitted: (_) {
                  if (match.isNotEmpty) ss(() => v.persons.add(ji(match.first['id'])!));
                }),
            if (v.persons.isNotEmpty)
              Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Wrap(spacing: 6, runSpacing: 4, children: [
                    for (final id in v.persons) InputChip(label: Text(pn(id)), onDeleted: v.all ? null : () => ss(() => v.persons.remove(id)), visualDensity: VisualDensity.compact),
                  ])),
            ConstrainedBox(
              constraints: const BoxConstraints(maxHeight: 180),
              child: match.isEmpty
                  ? Padding(padding: const EdgeInsets.only(top: 6), child: Mut(q.isNotEmpty ? 'Keine passende Person' : 'Alle ausgewählt'))
                  : ListView(shrinkWrap: true, children: [
                      for (final u in match)
                        ListTile(
                            dense: true,
                            visualDensity: VisualDensity.compact,
                            contentPadding: EdgeInsets.zero,
                            title: Text('+ ${js(u['name'])}${jb(u['absent']) ? ' (abwesend)' : ''}'),
                            enabled: !v.all,
                            onTap: () => ss(() => v.persons.add(ji(u['id'])!))),
                    ]),
            ),
          ],
        ]),
      ),
    ]);
  }
}

/// Eingaben des Startformulars, bleiben erhalten, während der PLS auf der Karte gewählt wird
class _StartDraft {
  _StartDraft(this.k) : rec = jb(k['rec']), air = jb(k['air']);
  final J k;
  final msg = TextEditingController(), pls = TextEditingController();
  bool rec, air;
  DateTime? at; // Alarmeingang, null = jetzt
  int? parent; // Sekundäreinsatz (Issue #164): Folgeeinsatz zu, null = kein Bezug
  final take = <String>{'pls', 'flow', 'res'};
  J? opts; // start/options
  bool optsLoaded = false;
  final inv = _Inv();
  void dispose() {
    msg.dispose();
    pls.dispose();
    inv.dispose();
  }
}

class _StartPage extends StatefulWidget {
  const _StartPage({required this.s, required this.d});
  final IncStore s;
  final _StartDraft d;
  @override
  State<_StartPage> createState() => _StartPageState();
}

class _StartPageState extends State<_StartPage> {
  _StartDraft get d => widget.d;
  IncStore get s => widget.s;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (!d.optsLoaded) _load();
  }

  Future<void> _load() async {
    try {
      final o = await s.api.call('start/options');
      d.optsLoaded = true;
      d.opts = o;
      final kid = ji(d.k['id']);
      final f = jl(o['kinds']).where((x) => ji(x['id']) == kid).firstOrNull ?? d.k;
      d.inv.groups.addAll(_ints(f['grp_def']));
      if (mounted) setState(() {});
    } catch (_) {}
  }

  J get _kf {
    final kid = ji(d.k['id']);
    return jl(d.opts?['kinds']).where((x) => ji(x['id']) == kid).firstOrNull ?? d.k;
  }

  Future<void> _pickAt() async {
    final n = DateTime.now(), cur = d.at ?? n;
    final day = await showDatePicker(context: context, initialDate: cur, firstDate: n.subtract(const Duration(days: 7)), lastDate: n);
    if (day == null || !mounted) return;
    final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(cur));
    if (t == null || !mounted) return;
    setState(() => d.at = DateTime(day.year, day.month, day.day, t.hour, t.minute));
  }

  Future<void> _go() async {
    final k = d.k, kid = ji(k['id']);
    if (d.msg.text.trim().isEmpty) {
      s.toast('Die Meldung ist Pflicht', 'err');
      return;
    }
    final pt = d.pls.text.trim(), p = pt.isEmpty ? null : parseC(pt);
    if (pt.isNotEmpty && p == null) {
      s.toast('PLS nicht erkannt: LV95, LV03 oder WGS84 dezimal', 'err');
      return;
    }
    final body = <String, dynamic>{
      'kind_id': kid,
      'record': d.rec && _recOn ? 1 : 0,
      if (k['air'] != null) 'air': d.air ? 1 : 0,
      'message': d.msg.text,
      if (p != null) ...{'lat': p.latitude, 'lon': p.longitude},
      if (d.at != null) 'alarm_at': d.at!.millisecondsSinceEpoch ~/ 1000,
      if (d.parent != null) ...{'parent_id': d.parent, for (final t in _takes) 'take_$t': 1},
    };
    if (d.opts != null && jl(d.opts!['groups']).isNotEmpty) {
      final v = d.inv.get();
      if (v['all'] != null) {
        body['invite_all'] = 1;
      } else {
        body.addAll(v);
      }
    }
    setState(() => _busy = true);
    try {
      final r = await s.api.call('incident/start', body: body);
      // neuen Einsatz anzeigen (parallele Einsätze, Issue #167)
      if (ji(r['id']) != null && !s.demo) {
        await s.setSel(ji(r['id'])!);
      } else {
        await s.poll();
      }
      if (mounted) Navigator.pop(context, 'ok');
    } catch (e) {
      s.fail(e);
      if (mounted) setState(() => _busy = false);
    }
  }

  bool get _recOn => jm(d.k['features'])['rec'] != 0;

  J? get _par => d.parent == null ? null : jl(d.opts?['parents']).where((p) => ji(p['id']) == d.parent).firstOrNull;
  List<(String, String)> get _takeOpts {
    final p = _par;
    if (p == null) return [];
    final n = ji(p['missing']) ?? 0;
    return [
      if (jb(p['pls'])) ('pls', 'PLS übernehmen (mit dem ursprünglichen Zeitpunkt, falls unten keiner eingegeben ist)'),
      if (jb(p['flow'])) ('flow', 'Fliessgeschwindigkeit übernehmen'),
      if (n > 0) ('res', n == 1 ? 'Den vermissten Rettling übernehmen' : 'Die $n vermissten Rettlinge übernehmen'),
    ];
  }

  Iterable<String> get _takes => _takeOpts.map((t) => t.$1).where(d.take.contains);

  /// Folgeeinsatz zu einem beendeten Einsatz der letzten 30 Tage (start/options liefert parents)
  List<Widget> _parPick() {
    final ps = jl(d.opts?['parents']), ref = ji(d.k['ref']) ?? 1; // 0 kein Bezug, 1 optional, 2 Pflicht
    if (!jb(d.k['sec']) || ref == 0) return [];
    if (ps.isEmpty) return ref == 2 ? [const SizedBox(height: 10), const Mut('Dieser Einsatztyp verlangt einen Bezug, es gibt aber keinen früheren Einsatz der letzten 30 Tage.')] : [];
    if (ref == 2 && d.parent == null) d.parent = ji(ps.first['id']);
    return [
      const SizedBox(height: 10),
      DropdownButtonFormField<int?>(
          isExpanded: true,
          initialValue: d.parent,
          decoration: InputDecoration(labelText: ref == 2 ? 'Folgeeinsatz zu' : 'Folgeeinsatz zu (optional)', isDense: true),
          items: [
            if (ref != 2) const DropdownMenuItem<int?>(value: null, child: Text('– kein Bezug –')),
            for (final p in ps)
              DropdownMenuItem<int?>(
                  value: ji(p['id']),
                  child: Text('${js(p['kind_name'])} vom ${fmtDT(jn(p['alarm_at']))}: ${js(p['message'])}', overflow: TextOverflow.ellipsis)),
          ],
          onChanged: (v) => setState(() => d.parent = v)),
      if (_par != null && _takeOpts.isEmpty) const Padding(padding: EdgeInsets.only(top: 4), child: Mut('Nichts zu übernehmen (kein PLS, keine vermissten Rettlinge).')),
      for (final t in _takeOpts)
        CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: d.take.contains(t.$1),
            onChanged: (v) => setState(() => v == true ? d.take.add(t.$1) : d.take.remove(t.$1)),
            title: Text(t.$2)),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final k = d.k, sec = ji(k['sec']) == 1, alarm = sec ? 'none' : js(k['alarm']);
    final o = d.opts, kid = '${ji(k['id'])}';
    final st = jm(o?['start']), allowed = st[kid] == null ? null : _ints(st[kid]);
    return Scaffold(
      appBar: AppBar(title: Text('${js(k['name'])} starten')),
      body: StatefulBuilder(
        builder: (c, ss) => ListView(padding: const EdgeInsets.all(16), children: [
          Mut(sec
              ? 'Sekundäreinsatz ohne Alarm: Die Aufgebotenen werden nicht alarmiert, sehen den Einsatz aber in der App und treten selbst bei.'
              : 'Alarmstufe ${js(k['alarm_name'])}${jb(k['mode_up']) && alarm != 'crit' ? ' (kritisch bei Personen mit Übungsmodus)' : ''}.'),
          ..._parPick(),
          const SizedBox(height: 10),
          TextField(controller: d.msg, minLines: 2, maxLines: 5, autofocus: true, decoration: const InputDecoration(labelText: 'Meldung', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          if (_recOn) ...[
            CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: d.rec,
                onChanged: (v) => setState(() => d.rec = v == true),
                title: const Text('Aufzeichnen (Replay in der Verwaltung)'),
                subtitle: const Text('Speichert die Standortverläufe aller Teilnehmenden für die spätere Auswertung.')),
          ],
          if (k['air'] != null)
            CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: d.air,
                onChanged: (v) => setState(() => d.air = v == true),
                title: const Text('Helikopter auf der Karte (Luftlage aus ADS-B-Daten)')),
          if (o != null) _InvPick(o: o, k: _kf, allowed: allowed, v: d.inv, ss: (f) => setState(f)),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(
                child: TextField(
                    controller: d.pls,
                    decoration: const InputDecoration(labelText: 'PLS (optional)', hintText: 'LV95, LV03 oder WGS84', isDense: true))),
            const SizedBox(width: 8),
            OutlinedButton(onPressed: () => Navigator.pop(context, 'pick'), child: const Text('Karte')),
          ]),
          const Padding(
              padding: EdgeInsets.only(top: 4),
              child: Mut('PLS (optional): auf der Karte wählen oder Koordinaten eingeben. Ohne PLS entsteht das Suchgebiet erst mit dem PLS eines Rettlings.')),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(
                child: Text.rich(TextSpan(children: [
              TextSpan(text: sec ? 'Beginn: ' : 'Alarmeingang: ', style: const TextStyle(fontWeight: FontWeight.w600)),
              TextSpan(text: d.at == null ? 'jetzt (Standard)' : fmtDT(d.at!.millisecondsSinceEpoch / 1000)),
            ]))),
            TextButton(onPressed: _pickAt, child: const Text('Ändern')),
            if (d.at != null) TextButton(onPressed: () => setState(() => d.at = null), child: const Text('Jetzt')),
          ]),
          const SizedBox(height: 16),
          Row(children: [
            Expanded(
                child: FilledButton(
                    style: FilledButton.styleFrom(backgroundColor: alarm == 'none' ? null : accRed, padding: const EdgeInsets.symmetric(vertical: 14)),
                    onPressed: _busy ? null : _go,
                    child: Text(alarm == 'none' ? 'Starten' : 'Alarm auslösen'))),
            const SizedBox(width: 8),
            OutlinedButton(onPressed: () => Navigator.pop(context), child: const Text('Abbrechen')),
          ]),
        ]),
      ),
    );
  }
}

/// Lagezusammenfassung des AI-Assistenten (ai/ask mit fn sit), mit zusätzlichem Wunsch, Kopieren und «↻ Neu»
class _AiSitDlg extends StatefulWidget {
  const _AiSitDlg({required this.s, required this.id});
  final IncStore s;
  final int id;
  @override
  State<_AiSitDlg> createState() => _AiSitDlgState();
}

class _AiSitDlgState extends State<_AiSitDlg> {
  final _q = TextEditingController();
  J? _res;
  String? _err;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _ask(false);
  }

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  Future<void> _ask(bool fresh) async {
    setState(() {
      _busy = true;
      _err = null;
    });
    try {
      final r = await widget.s.api.call('ai/ask',
          body: {'fn': 'sit', 'id': widget.id, if (fresh) 'fresh': 1, if (_q.text.trim().isNotEmpty) 'q': _q.text.trim()}, timeout: const Duration(seconds: 120));
      if (mounted) setState(() => _res = r);
    } catch (e) {
      if (mounted) setState(() => _err = '$e');
    }
    if (mounted) setState(() => _busy = false);
  }

  String _meta(J r) {
    final tk = ji(r['tokens']);
    return '${jb(r['cached']) ? 'Gespeicherter Vorschlag vom ' : 'Erstellt '}${fmtDT(jn(r['ts']))}${tk != null && tk > 0 ? ' · $tk Tokens' : ''}${jb(r['anon']) ? ' · Namen ersetzt durch Bezeichnungen' : ''}';
  }

  @override
  Widget build(BuildContext context) {
    final r = _res;
    return AlertDialog(
      title: const Text('✨ Lagezusammenfassung'),
      scrollable: true,
      content: SizedBox(
        width: 520,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          TextField(
              controller: _q,
              minLines: 1,
              maxLines: 3,
              maxLength: 2000,
              decoration: const InputDecoration(labelText: 'Zusätzlicher Wunsch (optional)', hintText: 'z. B. kürzer, mehr zur Alarmierung', isDense: true)),
          const Mut('Vorschlag des AI-Assistenten: bitte prüfen, bevor du ihn übernimmst. Es gehen nur die Daten dieser Ansicht an den Anbieter.', size: 12),
          const SizedBox(height: 8),
          if (_busy)
            const Mut('✨ Der AI-Assistent schreibt …')
          else if (_err != null)
            Text(_err!, style: const TextStyle(color: accRed))
          else if (r != null) ...[
            SelectableText(js(r['text']).replaceAll(RegExp(r'\*\*([^*\n]+)\*\*'), r'$1').replaceAllMapped(RegExp(r'^#{1,6}\s*', multiLine: true), (_) => '')),
            const SizedBox(height: 6),
            Mut(_meta(r), size: 12),
          ],
        ]),
      ),
      actions: [
        TextButton(onPressed: _busy ? null : () => _ask(true), child: const Text('↻ Neu')),
        TextButton(
            onPressed: r == null || _busy
                ? null
                : () async {
                    await Clipboard.setData(ClipboardData(text: js(r['text'])));
                    widget.s.toast('In die Zwischenablage kopiert.');
                  },
            child: const Text('Kopieren')),
        FilledButton(onPressed: () => Navigator.pop(context), child: const Text('Schliessen')),
      ],
    );
  }
}
