// Seitenfenster «Einsatzkräfte» der nativen Einsatz-App (Gegenstück zu renderPeople, addExt, editExt in web/index.html):
// Gruppen «Einsatzkräfte mit App» und «Externe Einsatzkräfte» (zugeklappte gemerkt pro Gerät und Konto), je nach Spezialrolle,
// Zentrieren, Folgen, Navigation; für die Einsatzleitung Spezialrolle ändern, aus dem Einsatz entfernen und Externe erfassen,
// bearbeiten, verschieben und entfernen.
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'ctx.dart';
import 'store.dart';
import 'util.dart';

class PeoplePanel extends StatefulWidget {
  const PeoplePanel({super.key, required this.s, required this.map});
  final IncStore s;
  final MapCtl map;
  @override
  State<PeoplePanel> createState() => _PeoplePanelState();
}

/// Farben für Externe (extStyleFields: Standard #e53935, dazu die Farben der Markierungen)
const _extColors = [('#e53935', 'Rot'), ('#ff9f1c', 'Orange'), ('#ffd23f', 'Gelb'), ('#35c27a', 'Grün'), ('#1d7fd1', 'Blau'), ('#c78cff', 'Violett'), ('#000000', 'Schwarz'), ('#ffffff', 'Weiss')];

class _PeoplePanelState extends State<PeoplePanel> {
  IncStore get s => widget.s;
  MapCtl get map => widget.map;
  Set<String> _fold = {};
  SharedPreferences? _sp;

  String get _foldKey => 'pplfold_${s.acc.id}';

  @override
  void initState() {
    super.initState();
    SharedPreferences.getInstance().then((p) {
      if (!mounted) return;
      setState(() {
        _sp = p;
        _fold = (p.getStringList(_foldKey) ?? []).toSet();
      });
    });
  }

  void _toggleFold(String k) {
    setState(() => _fold.contains(k) ? _fold.remove(k) : _fold.add(k));
    _sp?.setStringList(_foldKey, _fold.toList());
  }

  bool _narrow() => mounted && MediaQuery.sizeOf(context).width < 760;

  /// aktive Einsatzkräfte (ohne Abgelehnte und Ausgetretene, activePeople)
  List<J> get _active => s.inc == null ? [] : s.participants.where((p) => p['left_at'] == null && p['mode'] != 'declined').toList();
  J? _ext(int id) => s.externals.where((x) => ji(x['id']) == id).firstOrNull;

  /// nach Spezialrolle in der Reihenfolge der Verwaltung, ohne Rolle zuletzt; innerhalb alphabetisch (byRole)
  List<(J?, List<J>)> _byRole(List<J> l) {
    int cmp(J a, J b) => js(a['name']).toLowerCase().compareTo(js(b['name']).toLowerCase());
    final g = <(J?, List<J>)>[
      for (final r in s.roles) (r, l.where((p) => '${p['role_id']}' == '${r['id']}').toList()..sort(cmp)),
      (null, l.where((p) => !s.roles.any((r) => '${r['id']}' == '${p['role_id']}')).toList()..sort(cmp)),
    ];
    return g.where((x) => x.$2.isNotEmpty).toList();
  }

  // ---------- Zentrieren, Folgen ----------
  void _center(J p) {
    final ll = llOf(p);
    if (ll == null) return;
    if (map.followId != null) map.follow(null);
    map.centerOn(ll);
    if (_narrow()) map.openPanel(null);
  }

  void _follow(int id) {
    if (map.followId == id) {
      map.follow(null);
      setState(() {});
      return;
    }
    map.follow(id);
    if (_narrow()) {
      map.openPanel(null);
    } else {
      setState(() {});
    }
  }

  // ---------- Einsatzleitung: Spezialrolle, entfernen ----------
  Future<void> _setRole(J p) async {
    final uid = ji(p['user_id']);
    if (uid == null) return;
    final cur = ji(p['role_id']);
    final r = await showDialog<(int?,)>(
        context: context,
        builder: (d) => SimpleDialog(title: Text('Spezialrolle von ${js(p['name'])}'), children: [
              _roleOpt(d, null, 'Keine Spezialrolle', null, cur == null),
              for (final r in s.roles) _roleOpt(d, ji(r['id']), js(r['name']), hexColor(r['color']), cur == ji(r['id'])),
            ]));
    if (r == null || r.$1 == cur) return;
    await s.act('incident/role/set', {'user_id': uid, 'role_id': r.$1});
  }

  Widget _roleOpt(BuildContext d, int? id, String name, Color? c, bool sel) => SimpleDialogOption(
      onPressed: () => Navigator.pop(d, (id,)),
      child: Row(children: [
        Icon(sel ? Icons.radio_button_checked : Icons.radio_button_unchecked, size: 20),
        const SizedBox(width: 10),
        if (c != null) ...[Icon(Icons.circle, size: 12, color: c), const SizedBox(width: 6)],
        Expanded(child: Text(name)),
      ]));

  Future<void> _kick(J p) async {
    final n = js(p['name']);
    if (!await confirmDlg(context, '$n aus dem Einsatz entfernen?', ok: 'Entfernen', danger: true)) return;
    await s.act('incident/kick', {'user_id': ji(p['user_id'])});
  }

  // ---------- Externe Einsatzkräfte ----------
  /// nach dem Speichern das Fenster wieder zeigen (extGo)
  void _extGo() => map.openPanel('people');

  /// Standort auf der Karte antippen und speichern (extPlace)
  Future<void> _extPlace(J body) async {
    if (_narrow()) map.openPanel(null);
    final q = await map.pickPoint('Tippe auf die Karte, um «${js(body['name'])}» zu setzen.');
    if (q == null) {
      _extGo();
      return;
    }
    final r = await s.act('incident/external/save', {...body, 'lat': _r7(q.latitude), 'lon': _r7(q.longitude)});
    if (r != null) _extGo();
  }

  double _r7(double v) => double.parse(v.toStringAsFixed(7));

  Future<void> _addExt() async {
    final a = jl(s.st?['ext_templates']), b = jl(s.st?['ext_inc_templates']);
    if (a.isEmpty && b.isEmpty) return _editExt(null);
    String rn(J t) => js(s.roleOf(t['role_id'])?['name']).isNotEmpty ? js(s.roleOf(t['role_id'])!['name']) : 'Ohne Spezialrolle';
    Widget it(BuildContext d, J t) {
      final has = jd(t['lat']) != null;
      return ListTile(
          contentPadding: EdgeInsets.zero,
          leading: _mk({...t, 'ext': 1}),
          title: Text(js(t['name'])),
          subtitle: Text('${rn(t)}${has ? ' · mit Standort${s.fcs(t['lat'], t['lon'], ' ')}' : ''}'),
          onTap: () => Navigator.pop(d, (t, false)),
          trailing: has ? TextButton(onPressed: () => Navigator.pop(d, (t, true)), child: const Text('Karte')) : null);
    }

    Widget hd(String t) => Padding(padding: const EdgeInsets.only(top: 10, bottom: 2), child: Text(t, style: const TextStyle(fontWeight: FontWeight.bold)));
    final r = await showDialog<(J?, bool)>(
        context: context,
        builder: (d) => AlertDialog(
                title: const Text('Externe Einsatzkraft erfassen'),
                scrollable: true,
                content: SizedBox(
                    width: 420,
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      const Mut('Vorlage antippen, danach den Standort auf der Karte wählen. Vorlagen mit Standort werden direkt dort gesetzt.'),
                      if (a.isNotEmpty) ...[hd('Vorlagen'), ...a.map((t) => it(d, t))],
                      if (b.isNotEmpty) ...[hd('In diesem Einsatz erfasst'), ...b.map((t) => it(d, t))],
                    ])),
                actions: [
                  TextButton(onPressed: () => Navigator.pop(d), child: const Text('Abbrechen')),
                  FilledButton(onPressed: () => Navigator.pop(d, (null, false)), child: const Text('Neu ohne Vorlage')),
                ]));
    if (r == null) return;
    final t = r.$1;
    if (t == null) return _editExt(null);
    final body = <String, dynamic>{'name': js(t['name']), 'descr': js(t['descr']), 'role_id': ji(t['role_id']), 'abbr': js(t['abbr']), 'color': js(t['color'])};
    if (jd(t['lat']) != null && !r.$2) {
      final x = await s.act('incident/external/save', {...body, 'lat': jd(t['lat']), 'lon': jd(t['lon'])});
      if (x != null) _extGo();
      return;
    }
    await _extPlace(body);
  }

  Future<void> _editExt(J? e) async {
    final id = ji(e?['id']);
    final n = TextEditingController(text: js(e?['name'])), de = TextEditingController(text: js(e?['descr'])), ab = TextEditingController(text: js(e?['abbr']));
    int? role = ji(e?['role_id']);
    if (role != null && s.roleOf(role) == null) role = null;
    var color = js(e?['color']).toLowerCase(), colOn = color.isNotEmpty, pick = id == null, err = '';
    if (color.isEmpty) color = '#e53935';
    final cols = [..._extColors, if (!_extColors.any((c) => c.$1 == color)) (color, 'Aktuelle Farbe')];
    StateSetter? dss;
    final ok = await formDlg(context, id != null ? 'Externe Einsatzkraft bearbeiten' : 'Externe Einsatzkraft erfassen', (c, ss) {
      dss = ss;
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: n, maxLength: 80, autofocus: id == null, decoration: InputDecoration(labelText: 'Name', hintText: 'z. B. Feuerwehr, Posten Brücke', errorText: err.isEmpty ? null : err)),
        TextField(controller: de, maxLength: 500, minLines: 2, maxLines: 4, decoration: const InputDecoration(labelText: 'Beschreibung (Organisation, Funkkanal, Telefon …)')),
        DropdownButtonFormField<int?>(
            initialValue: role,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Spezialrolle'),
            items: [
              const DropdownMenuItem<int?>(value: null, child: Text('Keine Spezialrolle')),
              for (final r in s.roles)
                DropdownMenuItem<int?>(
                    value: ji(r['id']),
                    child: Row(children: [Icon(Icons.circle, size: 12, color: hexColor(r['color'])), const SizedBox(width: 6), Flexible(child: Text(js(r['name']), overflow: TextOverflow.ellipsis))])),
            ],
            onChanged: (v) => ss(() => role = v)),
        const Padding(padding: EdgeInsets.only(top: 4), child: Mut('Mit einer Posten-Rolle endet das Suchgebiet an diesem Standort.', size: 12)),
        const SizedBox(height: 8),
        TextField(controller: ab, maxLength: 4, decoration: const InputDecoration(labelText: 'Kürzel (max. 4 Zeichen, auf dem Marker)', hintText: 'z. B. FW')),
        CheckboxListTile(contentPadding: EdgeInsets.zero, dense: true, value: colOn, onChanged: (v) => ss(() => colOn = v == true), title: const Text('Farbe')),
        Opacity(
            opacity: colOn ? 1 : .4,
            child: ColorPick(
                value: color,
                colors: cols,
                onChanged: (v) => ss(() {
                      color = v;
                      colOn = true;
                    }))),
        const Padding(padding: EdgeInsets.only(top: 4), child: Mut('Die Farbe füllt den Marker, der Rand zeigt die Spezialrolle.', size: 12)),
        CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            dense: true,
            value: pick,
            onChanged: id == null ? null : (v) => ss(() => pick = v == true),
            title: Text(id != null ? 'Standort neu auf der Karte setzen' : 'Standort auf der Karte setzen')),
        Mut(id != null ? s.fcs(e?['lat'], e?['lon'], 'Standort ') : 'Danach den Standort auf der Karte antippen.', size: 12),
      ]);
    }, ok: id != null ? 'Speichern' : 'Weiter', valid: () {
      if (n.text.trim().isNotEmpty) return true;
      dss?.call(() => err = 'Bitte einen Namen eingeben.');
      return false;
    });
    final body = <String, dynamic>{
      'id': ?id,
      'name': n.text.trim(),
      'descr': de.text.trim(),
      'role_id': role,
      'abbr': ab.text.trim(),
      'color': colOn ? color : '',
    };
    if (!ok) return;
    if (id != null) {
      final r = await s.act('incident/external/save', body);
      if (r != null && pick) await _extPlace(body);
      return;
    }
    await _extPlace({...body, 'manual': 1});
  }

  Future<void> _removeExt(J x) async {
    if (!await confirmDlg(context, 'Externe Einsatzkraft «${js(x['name'])}» für alle entfernen?', ok: 'Entfernen', danger: true)) return;
    await s.act('incident/external/remove', {'id': ji(x['id'])});
  }

  // ---------- Darstellung ----------
  /// Marker wie mk(): Rand = Spezialrolle, bei Externen Füllung = Farbe und Text = Kürzel
  Widget _mk(J p) {
    final r = s.roleOf(p['role_id']);
    final ext = jb(p['ext']);
    final fc = ext && js(p['color']).isNotEmpty ? hexColor(p['color']) : null;
    final col = r != null ? hexColor(r['color']) : (fc ?? const Color(0xFF8FB0BD));
    if (!ext) return avatar(p, border: col);
    final ini = js(p['abbr']).isNotEmpty
        ? js(p['abbr'])
        : js(p['name']).trim().split(RegExp(r'\s+')).where((x) => x.isNotEmpty).map((x) => x[0]).take(2).join().toUpperCase();
    final bg = fc ?? appBlue;
    return Container(
        width: 37,
        height: 37,
        alignment: Alignment.center,
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(6), border: Border.all(color: col, width: 2.5)),
        child: Text(ini.isEmpty ? '?' : ini, style: TextStyle(color: fgOn(bg), fontSize: ini.length > 2 ? 10 : 12, fontWeight: FontWeight.bold)));
  }

  Widget _row(J p) {
    final has = llOf(p) != null, uid = ji(p['user_id']), me = uid == s.uid, f = uid != null && map.followId == uid;
    final cs = has ? s.fc(p['lat'], p['lon']) : '';
    final lead = s.leadMode(js(p['mode']));
    final nav = has ? navBtn(s, p['lat'], p['lon'], js(p['name'])) : null;
    final mgr = s.canManage
        ? PopupMenuButton<String>(
            tooltip: 'Einsatzleitung',
            icon: const Icon(Icons.more_vert, size: 20),
            onSelected: (v) => v == 'role' ? _setRole(p) : _kick(p),
            itemBuilder: (_) => [
                  PopupMenuItem(value: 'role', enabled: !lead, child: Text(lead ? 'Rechte der Einsatzleitung (keine Spezialrolle)' : 'Spezialrolle ändern …')),
                  if (!me) const PopupMenuItem(value: 'kick', child: Text('Aus dem Einsatz entfernen')),
                ])
        : null;
    return _entry(
      lead: _mk(p),
      title: '${js(p['name'])}${me ? ' (du)' : ''}',
      lines: ['${js(s.modeOf(js(p['mode']))['name'])} · ${has ? 'Standort ${agoS(s.now, jn(p['loc_at']) ?? s.now)}' : 'kein Standort'}', if (cs.isNotEmpty) cs],
      buttons: [
        if (has) smallBtn('Zentrieren', () => _center(p)),
        if (has && uid != null) smallBtn(f ? 'Folgt' : 'Folgen', () => _follow(uid), primary: f),
        ?nav,
      ],
      trailing: mgr,
    );
  }

  Widget _xrow(J e) {
    final cs = s.fc(e['lat'], e['lon']), id = ji(e['id']) ?? 0;
    final nav = navBtn(s, e['lat'], e['lon'], js(e['name']));
    return _entry(
      lead: _mk(e),
      title: js(e['name']),
      lines: ['Extern${js(e['descr']).isNotEmpty ? ' · ${js(e['descr'])}' : ''}', if (cs.isNotEmpty) cs],
      buttons: [
        if (llOf(e) != null) smallBtn('Zentrieren', () => _center(e)),
        ?nav,
        if (s.canManage) ...[
          IconButton(
              tooltip: 'Name, Beschreibung, Spezialrolle, Kürzel, Farbe und Standort ändern',
              icon: const Icon(Icons.edit, size: 18),
              visualDensity: VisualDensity.compact,
              onPressed: () {
                final x = _ext(id);
                if (x != null) _editExt(x);
              }),
          IconButton(
              tooltip: 'Für alle entfernen',
              icon: const Icon(Icons.close, size: 18),
              visualDensity: VisualDensity.compact,
              onPressed: () {
                final x = _ext(id);
                if (x != null) _removeExt(x);
              }),
        ],
      ],
    );
  }

  Widget _entry({required Widget lead, required String title, required List<String> lines, required List<Widget> buttons, Widget? trailing}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(padding: const EdgeInsets.only(top: 2), child: lead),
        const SizedBox(width: 10),
        Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
          for (final l in lines) Mut(l, size: 12),
          if (buttons.isNotEmpty)
            Padding(padding: const EdgeInsets.only(top: 4), child: Wrap(spacing: 6, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: buttons)),
        ])),
        ?trailing,
      ]));

  List<Widget> _roleGroups(List<J> l, Widget Function(J) row) => [
        for (final g in _byRole(l)) ...[
          Padding(
              padding: const EdgeInsets.only(top: 6, bottom: 2),
              child: Row(children: [
                if (g.$1 != null) ...[Icon(Icons.circle, size: 11, color: hexColor(g.$1!['color'])), const SizedBox(width: 6)],
                Expanded(child: Text('${g.$1 != null ? js(g.$1!['name']) : 'Ohne Spezialrolle'} (${g.$2.length})', style: Theme.of(context).textTheme.labelLarge)),
              ])),
          ...g.$2.map(row),
        ],
      ];

  Widget _group(String k, String title, List<J> l, String empty, Widget Function(J) row) {
    final open = !_fold.contains(k);
    return FoldGroup(
        title: title,
        count: l.length,
        open: open,
        onToggle: () => _toggleFold(k),
        children: l.isEmpty ? [Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: Mut(empty))] : _roleGroups(l, row));
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
      listenable: s,
      builder: (context, _) {
        final ap = _active, xl = s.externals.map((e) => <String, dynamic>{...e, 'ext': 1}).toList();
        final showExt = s.feat('ext') && (xl.isNotEmpty || s.canManage);
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          PanelHead('Einsatzkräfte', onClose: () => map.openPanel(null)),
          Expanded(
              child: s.inc == null
                  ? const Padding(padding: EdgeInsets.all(12), child: Mut('Kein laufender Einsatz.'))
                  : ListView(padding: const EdgeInsets.fromLTRB(12, 0, 12, 12), children: [
                      _group('int', 'Einsatzkräfte mit App', ap, 'Noch niemand im Einsatz.', _row),
                      if (showExt) _group('ext', 'Externe Einsatzkräfte', xl, 'Keine erfasst.', _xrow),
                      if (s.canManage && s.feat('ext'))
                        Padding(padding: const EdgeInsets.only(top: 10), child: FilledButton(onPressed: _addExt, child: const Text('+ Externe Einsatzkraft'))),
                    ])),
        ]);
      });
}

