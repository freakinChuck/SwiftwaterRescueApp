// Fenster «Rettlinge» der nativen Einsatz-App (Gegenstück zu renderRes, editRes, resFound, plsPick in web/index.html):
// Gruppen Vermisst / Standort bekannt / Gerettet (einklappbar, pro Gerät gemerkt), Fundort, «gerettet», Suchgebiet je Rettling,
// PLS des Einsatzes bzw. eines Rettlings (Ort auf der Karte, danach Zeitpunkt), frühere Rettling-Markierungen.
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'area.dart';
import 'ctx.dart';
import 'store.dart';
import 'util.dart';

const _resSt = {'miss': 'Vermisst', 'loc': 'Standort bekannt', 'ok': 'Gerettet'};

/// Rettlinge bearbeiten dürfen alle, die den Einsatz angenommen haben, und die Einsatzleitung (canRes)
bool _canRes(IncStore s) => s.inc != null && (s.canManage || s.joined);

/// am Handy decken die Seitenfenster die Karte ab: nach «Zeigen» schliessen (narrow)
bool _narrow(BuildContext c) => MediaQuery.sizeOf(c).width < 760;

/// Teilgebiet des Suchgebiets eines Rettlings (resPart)
String? _resPart(IncStore s, J r) {
  final i = s.inc;
  if (i == null) return null;
  if (jd(r['pls_lat']) != null) return partKey(jd(r['pls_lat'])!, jd(r['pls_lon'])!, jn(r['pls_at']) ?? 0);
  return jd(i['lat']) != null ? partKey(jd(i['lat'])!, jd(i['lon'])!, jn(i['pls_at']) ?? jn(i['alarm_at']) ?? 0) : null;
}

String _resPls(IncStore s, J r) => jd(r['pls_lat']) != null
    ? 'Eigener PLS${s.fcs(r['pls_lat'], r['pls_lon'])} · Drift ab ${fmtDT(jn(r['pls_at']))}'
    : (jd(s.inc?['lat']) != null ? 'PLS des Einsatzes' : 'Kein PLS');

String _resInfo(IncStore s, J r) =>
    (r['rescued_at'] != null
        ? 'Gerettet ${fmtT(jn(r['rescued_at']))} · ${js(r['rescued_name'])}'
        : r['lat'] != null
            ? 'Standort ${agoS(s.now, jn(r['loc_at']) ?? s.now)} · ${js(r['loc_name'])}'
            : 'Noch kein Standort') +
    s.fcs(r['lat'], r['lon']);

J? _resOf(IncStore s, int id) => s.rescuees.where((x) => ji(x['id']) == id).firstOrNull;

String _p2(int n) => n.toString().padLeft(2, '0');
String _dtTxt(DateTime d) => '${_p2(d.day)}.${_p2(d.month)}.${d.year}, ${_p2(d.hour)}:${_p2(d.minute)}';
int _ts(DateTime d) => d.millisecondsSinceEpoch ~/ 1000;

/// Datum und Uhrzeit wählen (Ersatz für datetime-local)
Future<DateTime?> _pickDT(BuildContext c, DateTime init) async {
  final d = await showDatePicker(context: c, initialDate: init, firstDate: DateTime(2020), lastDate: DateTime.now().add(const Duration(days: 2)));
  if (d == null || !c.mounted) return null;
  final t = await showTimePicker(context: c, initialTime: TimeOfDay.fromDateTime(init));
  if (t == null) return null;
  return DateTime(d.year, d.month, d.day, t.hour, t.minute);
}

/// Feld mit Zeitpunkt in einem Formular (Antippen = ändern)
Widget _dtField(BuildContext c, String label, DateTime v, ValueChanged<DateTime> set) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 6),
    child: InputDecorator(
        decoration: InputDecoration(labelText: label, border: const OutlineInputBorder(), isDense: true),
        child: InkWell(
            onTap: () async {
              final d = await _pickDT(c, v);
              if (d != null) set(d);
            },
            child: Row(children: [Expanded(child: Text(_dtTxt(v))), const Icon(Icons.edit_calendar, size: 18)]))));

/// PLS setzen/verschieben: res == null = PLS des Einsatzes (incident/pls; clear: entfernen nach Rückfrage),
/// sonst des Rettlings (incident/rescuee/save mit pls). Punkt auf der Karte, danach Zeitpunkt (Beginn der Drift) wie die Web-App.
Future<void> plsPick(BuildContext c, IncStore s, MapCtl map, {J? res, bool clear = false}) async {
  final i = s.inc;
  if (i == null) return;
  if (res == null && clear) {
    if (await confirmDlg(c, 'PLS des Einsatzes entfernen? Rettlinge ohne eigenen PLS haben dann kein Suchgebiet mehr.', ok: 'Entfernen', danger: true)) {
      await s.act('incident/pls', {'clear': 1});
    }
    return;
  }
  final name = js(res?['name']);
  final what = res == null ? 'PLS des Einsatzes' : 'PLS von «$name»';
  final at = res == null
      ? (jd(i['lat']) != null ? jn(i['pls_at']) : jn(i['alarm_at']))
      : (jd(res['pls_lat']) != null ? jn(res['pls_at']) : jn(i['pls_at']));
  map.closeMenu();
  final q = await map.pickPoint('Tippe auf die Karte, um den $what zu setzen.');
  // Menü bzw. Fenster «Rettlinge» sind für die Punktwahl geschlossen, ihr Kontext besteht nicht mehr (Issue #151)
  c = map.ctx;
  if (q == null || !c.mounted) return;
  var t = tsDate(at ?? s.now);
  final ok = await formDlg(
      c,
      what,
      (d, ss) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            if (s.fc(q.latitude, q.longitude).isNotEmpty) Mut(s.fc(q.latitude, q.longitude)),
            _dtField(d, 'Zeitpunkt (Beginn der Drift)', t, (v) => ss(() => t = v)),
            const Mut('Ab diesem Zeitpunkt wird die Driftdistanz mit der Fliessgeschwindigkeit berechnet.'),
          ]),
      ok: 'OK');
  if (!ok) return;
  final lat = double.parse(q.latitude.toStringAsFixed(7)), lon = double.parse(q.longitude.toStringAsFixed(7));
  if (res == null) {
    await s.act('incident/pls', {'lat': lat, 'lon': lon, 'at': _ts(t)});
  } else {
    final r = await s.act('incident/rescuee/save', {
      'id': res['id'],
      'name': name,
      'descr': js(res['descr']),
      'pls': {'lat': lat, 'lon': lon, 'at': _ts(t)}
    });
    if (r != null) map.openPanel('res');
  }
}

/// Erfassen (id 0) bzw. Bearbeiten: Name, Beschreibung, PLS des Einsatzes übernehmen oder eigener PLS (editRes)
Future<void> _editRes(BuildContext c, IncStore s, MapCtl map, int id) async {
  final i = s.inc;
  if (i == null) return;
  final r = (id != 0 ? _resOf(s, id) : null) ?? <String, dynamic>{'name': '', 'descr': ''};
  final own = jd(r['pls_lat']) != null, incPls = jd(i['lat']) != null;
  final n = TextEditingController(text: js(r['name'])), ds = TextEditingController(text: js(r['descr']));
  var t = tsDate(jn(r['pls_at']) ?? s.now);
  var takeInc = false, pick = !incPls;
  final ok = await formDlg(
      c,
      id != 0 ? 'Rettling bearbeiten' : 'Rettling erfassen',
      (d, ss) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: n, maxLength: 80, decoration: const InputDecoration(labelText: 'Name', hintText: 'leer = Unbekannter Rettling')),
            TextField(
                controller: ds,
                maxLength: 1000,
                minLines: 2,
                maxLines: 5,
                keyboardType: TextInputType.multiline,
                decoration: const InputDecoration(labelText: 'Beschreibung (Merkmale, Kleidung, Alter …)')),
            if (own) ...[
              Mut('Eigener PLS${s.fcs(r['pls_lat'], r['pls_lon'])}'),
              if (!takeInc) _dtField(d, 'Drift ab', t, (v) => ss(() => t = v)),
              CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: takeInc,
                  onChanged: (v) => ss(() => takeInc = v == true),
                  title: const Text('Stattdessen den PLS des Einsatzes übernehmen')),
            ] else ...[
              Mut('PLS: ${incPls ? 'der des Einsatzes${s.fcs(i['lat'], i['lon'], ' (', ')')}, Drift ab ${fmtDT(jn(i['pls_at']))}' : 'noch keiner (der Einsatz hat keinen PLS)'}'),
              CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: pick,
                  onChanged: (v) => ss(() => pick = v == true),
                  title: Text(id != 0 ? 'Eigenen PLS auf der Karte setzen' : 'Danach eigenen PLS auf der Karte setzen')),
            ],
          ]));
  final name = n.text.trim(), descr = ds.text;
  n.dispose();
  ds.dispose();
  if (!ok) return;
  // ohne Namen vergibt der Server «Unbekannter Rettling» (Issue #86)
  final body = <String, dynamic>{if (id != 0) 'id': id, 'name': name, 'descr': descr};
  if (own) body['pls'] = takeInc ? null : {'lat': r['pls_lat'], 'lon': r['pls_lon'], 'at': _ts(t)};
  final res = await s.act('incident/rescuee/save', body);
  if (res == null) return;
  map.openPanel('res');
  c = map.ctx;
  if (!own && pick && c.mounted) {
    final nid = ji(res['id']) ?? id;
    final x = _resOf(s, nid) ?? {'id': nid, 'name': name, 'descr': descr};
    await plsPick(c, s, map, res: x);
  }
}

/// Fundort melden: mit geteiltem Standort Auswahl «Mein Standort» oder Karte, sonst direkt auf der Karte (resFound)
Future<void> _resFound(BuildContext c, IncStore s, MapCtl map, J r) async {
  final name = js(r['name']);
  Future<void> pick() async {
    final q = await map.pickPoint('Tippe auf die Karte, um den Fundort von «$name» zu setzen.');
    if (q == null) return;
    if (await s.act('incident/rescuee/locate', {'id': r['id'], 'lat': q.latitude, 'lon': q.longitude}) != null) map.openPanel('res');
  }

  if (!s.sharing) return pick();
  final v = await showDialog<String>(
      context: c,
      builder: (d) => AlertDialog(title: Text('Fundort von «$name»'), content: const Text('Wo wurde der Rettling gefunden?'), actions: [
            TextButton(onPressed: () => Navigator.pop(d), child: const Text('Abbrechen')),
            OutlinedButton(onPressed: () => Navigator.pop(d, 'map'), child: const Text('Auf der Karte wählen')),
            FilledButton(onPressed: () => Navigator.pop(d, 'own'), child: const Text('📍 Mein Standort')),
          ]));
  if (v == 'map') return pick();
  if (v == 'own') await s.act('incident/rescuee/locate', {'id': r['id'], 'own': 1});
}

class ResPanel extends StatefulWidget {
  const ResPanel({super.key, required this.s, required this.map});
  final IncStore s;
  final MapCtl map;
  @override
  State<ResPanel> createState() => _ResPanelState();
}

class _ResPanelState extends State<ResPanel> {
  IncStore get s => widget.s;
  MapCtl get map => widget.map;
  final _more = <int>{}; // aufgeklappte «⋯» je Rettling (resMore)
  Set<String> _fold = {}; // zugeklappte Gruppen (sw_resfold)
  SharedPreferences? _sp;
  String get _fk => 'resfold_${s.acc.id}';

  @override
  void initState() {
    super.initState();
    SharedPreferences.getInstance().then((p) {
      if (!mounted) return;
      setState(() {
        _sp = p;
        _fold = (p.getStringList(_fk) ?? []).toSet();
      });
    });
  }

  void _toggleFold(String k) {
    setState(() => _fold.contains(k) ? _fold.remove(k) : _fold.add(k));
    _sp?.setStringList(_fk, _fold.toList());
  }

  Future<void> _rescued(J r, bool v) async {
    final n = js(r['name']);
    if (!await confirmDlg(context, v ? '«$n» als gerettet melden?' : '«Gerettet» bei «$n» zurücknehmen?')) return;
    await s.act('incident/rescuee/rescued', {'id': r['id'], 'rescued': v ? 1 : 0});
  }

  Future<void> _clearLoc(J r) async {
    if (!await confirmDlg(context, 'Fundort von «${js(r['name'])}» zurücksetzen? Der Rettling gilt dann wieder als vermisst.')) return;
    await s.act('incident/rescuee/locate', {'id': r['id'], 'clear': 1});
  }

  Future<void> _remove(J r) async {
    if (!await confirmDlg(context, 'Rettling «${js(r['name'])}» für alle entfernen?', ok: 'Entfernen', danger: true)) return;
    await s.act('incident/rescuee/remove', {'id': r['id']});
  }

  void _afterShow() {
    if (_narrow(context)) map.openPanel(null);
  }

  Widget _row(J r, bool can) {
    final t = Theme.of(context), st = s.resSt(r), id = ji(r['id']) ?? 0, more = can && _more.contains(id), name = js(r['name']);
    final i = s.inc!;
    final stCol = switch (st) { 'ok' => okGreen, 'loc' => accOrange, _ => accRed };
    final part = _resPart(s, r);
    final btns = <Widget>[
      if (can && st != 'ok') ...[
        smallBtn('📍 Fundort', () => _resFound(context, s, map, r)),
        smallBtn('Gerettet', () => _rescued(r, true), primary: true),
      ],
      if (can && st == 'ok') smallBtn('Zurücknehmen', () => _rescued(r, false)),
      if (st == 'miss' && s.feat('area') && part != null)
        smallBtn('Suchgebiet', () {
          map.fitArea(part);
          _afterShow();
        }),
      if (r['lat'] != null)
        smallBtn('Zeigen', () {
          map.follow(null);
          map.centerOn(llOf(r)!);
          _afterShow();
        }),
      if (st != 'ok')
        ?(r['lat'] != null
            ? navBtn(s, r['lat'], r['lon'], name, label: 'Navigieren')
            : navBtn(s, r['pls_lat'] ?? i['lat'], r['pls_lon'] ?? i['lon'], 'PLS «$name»', label: 'Zum PLS')),
      if (can)
        Tooltip(
            message: 'Weitere Aktionen',
            child: smallBtn('⋯', () => setState(() => more ? _more.remove(id) : _more.add(id)))),
    ];
    return Opacity(
        opacity: st == 'ok' ? .75 : 1,
        child: Card(
            margin: const EdgeInsets.symmetric(vertical: 4),
            child: Padding(
                padding: const EdgeInsets.all(10),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Row(children: [
                    Expanded(child: Text(name, style: const TextStyle(fontWeight: FontWeight.bold))),
                    Chip2(_resSt[st]!, color: stCol),
                  ]),
                  if (js(r['descr']).isNotEmpty) Padding(padding: const EdgeInsets.only(top: 3), child: Text(js(r['descr']), style: t.textTheme.bodyMedium)),
                  Padding(padding: const EdgeInsets.only(top: 3), child: Mut(_resInfo(s, r), size: 12)),
                  Mut(_resPls(s, r), size: 12),
                  Padding(padding: const EdgeInsets.only(top: 6), child: Wrap(spacing: 6, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: btns)),
                  if (more)
                    Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: Wrap(spacing: 6, runSpacing: 4, children: [
                          if (r['lat'] != null && st != 'ok') smallBtn('Fundort zurücksetzen', () => _clearLoc(r)),
                          smallBtn('PLS setzen', () => plsPick(context, s, map, res: r)),
                          smallBtn('✎ Bearbeiten', () => _editRes(context, s, map, id)),
                          if (s.canManage) smallBtn('✕ Entfernen', () => _remove(r), color: accRed),
                        ])),
                ]))));
  }

  /// frühere Einzelmarkierungen (rescuee_marks): nur Anzeige und Entfernen
  List<Widget> _marks() {
    final ms = jl(s.st?['marks']);
    if (!s.joined || ms.isEmpty) return [];
    return [
      const Padding(padding: EdgeInsets.only(top: 12, bottom: 4), child: Text('Frühere Rettling-Markierungen', style: TextStyle(fontWeight: FontWeight.bold))),
      ...ms.map((x) => Row(children: [
            Expanded(child: Text('${jb(x['manual']) ? 'Einsatzleitung (manuell)' : js(x['name'])} · ${fmtT(jn(x['ts']))}${s.fcs(x['lat'], x['lon'])}')),
            ?navBtn(s, x['lat'], x['lon'], 'Rettling'),
            if (s.canManage || ji(x['user_id']) == s.uid) smallBtn('Entfernen', () => s.act('incident/mark/remove', {'id': x['id']})),
          ])),
      const Mut('Neue Markierungen über «📍 Fundort» beim Rettling.', size: 12),
    ];
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
      listenable: s,
      builder: (context, _) {
        final head = PanelHead('Rettlinge', onClose: () => map.openPanel(null));
        if (s.inc == null) return Column(children: [head, const Padding(padding: EdgeInsets.all(16), child: Mut('Kein laufender Einsatz.'))]);
        final rs = s.rescuees, can = _canRes(s);
        final groups = <Widget>[];
        for (final k in ['miss', 'loc', 'ok']) {
          final l = rs.where((r) => s.resSt(r) == k).toList();
          if (l.isEmpty) continue;
          groups.add(FoldGroup(title: _resSt[k]!, count: l.length, open: !_fold.contains(k), onToggle: () => _toggleFold(k), children: l.map((r) => _row(r, can)).toList()));
        }
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          head,
          Expanded(
              child: ListView(padding: const EdgeInsets.fromLTRB(12, 0, 12, 12), children: [
            if (groups.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 10), child: Mut('Noch keine Rettlinge erfasst.')) else ...groups,
            ..._marks(),
          ])),
          if (can)
            Container(
                decoration: BoxDecoration(border: Border(top: BorderSide(color: Theme.of(context).dividerColor))),
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
                child: Align(alignment: Alignment.centerLeft, child: FilledButton(onPressed: () => _editRes(context, s, map, 0), child: const Text('+ Rettling erfassen')))),
        ]);
      });
}

