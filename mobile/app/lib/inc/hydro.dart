// Pegel & Abfluss in der nativen Einsatz-App (Issue #165, Gegenstück zu hyList, hydroCard, renderHyd, hyPop, drawHydro und hyPrefs in
// web/index.html): Liste der Messstationen (im Einsatz «Beim PLS» mit Beginn/Ende/aktuell, dann «Meine Messstationen»), gemeinsam für
// die Karte im Menü und das Seitenfenster «Messstationen» (Kartenknopf, Issue #147), Marker auf der Karte (Kategorie hydro) und die
// Auswahl der persönlichen Messstationen im Profil. Daten und Abfrage in store.dart (hyPoll).
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

import 'ctx.dart';
import 'store.dart';
import 'util.dart';

String hyName(J s) => '${js(s['water']).isNotEmpty ? '${js(s['water'])}, ' : ''}${js(s['name'])}';

String _n(double x, int d) => x.toStringAsFixed(d);

/// Messwerte als Text (hyVals)
String hyVals(J v) {
  final f = jd(v['flow']), h = jd(v['height']), l = jd(v['level']), t = jd(v['temp']), g = ji(v['danger']);
  final p = [
    if (f != null) '${_n(f, f < 1 ? 3 : (f < 10 ? 2 : 1))} m³/s',
    if (h != null) '${_n(h, 2)} m ü. M.',
    if (l != null) '${_n(l, 2)} m',
    if (t != null) '${_n(t, 1)} °C',
    if (g != null) 'Gefahrenstufe $g',
    if (js(v['cls']).isNotEmpty) js(v['cls']),
  ];
  return p.isEmpty ? 'keine Messwerte' : p.join(' · ');
}

/// aktueller Wert (hyNow)
String hyNow(IncStore s, String id) {
  final c = s.hyData[id];
  if (c != null) return '${hyVals(c)}${c['at'] != null ? ' · Messung ${fmtT(jn(c['at']))}' : ''}';
  return s.hyErr ?? 'Messwerte werden geladen …';
}

/// Distanz zum PLS bei der Erfassung (hyDist)
String hyDist(IncStore s, String id) {
  final r = s.hyInc.where((x) => js(x['station']) == id && x['dist'] != null).firstOrNull;
  return r == null ? '' : '${_n((jd(r['dist']) ?? 0) / 1000, 1)} km vom PLS';
}

/// Werte zu Beginn und am Ende des Einsatzes (hyPh)
List<String> hyPh(IncStore s, String id) => s.hyInc
    .where((r) => js(r['station']) == id)
    .map((r) => '${js(r['phase']) == 'start' ? 'Beginn' : 'Ende'} (${fmtT(jn(r['ts']))}): ${hyVals(r['v'] is Map ? jm(r['v']) : <String, dynamic>{})}')
    .toList();

/// Farbe nach Gefahrenstufe (.hyk.g2 … g5)
Color hyColor(int? g) => switch (g) {
      2 => const Color(0xFFC9A800),
      3 => const Color(0xFFE07B00),
      4 => const Color(0xFFD0021B),
      final x? when x >= 5 => const Color(0xFF7A0010),
      _ => const Color(0xFF0B4F8A),
    };

/// Station zeigen (hyShow): Kategorie einblenden, Karte auf die Station; am Handy schliessen Menü und Fenster
void hyShow(BuildContext c, IncStore s, MapCtl map, String id) {
  final st = s.hyStation(id);
  final la = jd(st?['lat']), lo = jd(st?['lon']);
  if (la == null || lo == null) return;
  if (!s.vis('hydro')) s.setVis(['hydro'], true);
  if (MediaQuery.sizeOf(c).width < 760) {
    map.closeMenu();
    map.openPanel(null);
  }
  map.centerOn(LatLng(la, lo), zoom: 13);
}

Future<void> hyOpenBafu(String id) async {
  try {
    await launchUrl(Uri.parse('https://www.hydrodaten.admin.ch/de/seen-und-fluesse/stationen-und-daten/${Uri.encodeComponent(id)}'), mode: LaunchMode.externalApplication);
  } catch (_) {}
}

/// Liste der Stationen (hyList), gemeinsam für die Karte im Menü und das Seitenfenster
List<Widget> hyList(BuildContext c, IncStore s, MapCtl map) {
  final i = s.inc, ir = s.hyInc;
  final is_ = <String>{...ir.map((r) => js(r['station']))}.toList();
  final mine = s.hyMine.where((x) => !is_.contains(x)).toList();
  Widget row(String id) {
    final st = s.hyStation(id);
    final d = hyDist(s, id);
    return Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text.rich(TextSpan(children: [
              TextSpan(text: st == null ? 'Station $id' : hyName(st), style: const TextStyle(fontWeight: FontWeight.bold)),
              if (d.isNotEmpty) TextSpan(text: '  $d', style: TextStyle(fontSize: 12, color: Theme.of(c).colorScheme.onSurfaceVariant)),
            ])),
            Mut(hyNow(s, id), size: 12),
            ...hyPh(s, id).map((t) => Mut(t, size: 12)),
          ])),
          if (st != null) ...[
            smallBtn('Zeigen', () => hyShow(c, s, map, id)),
            ?navBtn(s, st['lat'], st['lon'], hyName(st)),
          ],
        ]));
  }

  final hasPls = i != null && (i['lat'] != null || s.rescuees.any((r) => r['pls_lat'] != null));
  Widget h5(String t) => Padding(padding: const EdgeInsets.only(top: 8, bottom: 2), child: Text(t, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)));
  return [
    if (i != null) ...[
      h5('Beim PLS'),
      if (is_.isNotEmpty)
        ...is_.map(row)
      else
        Mut(hasPls
            ? 'Noch keine Werte erfasst (keine Messstation im Umkreis oder BAFU nicht erreichbar, siehe Log).'
            : 'Werden erfasst, sobald ein PLS bekannt ist. Am Einsatzende folgen die Werte derselben Stationen.'),
      h5('Meine Messstationen'),
    ],
    if (mine.isNotEmpty)
      ...mine.map(row)
    else
      Mut(s.hyMine.isNotEmpty ? 'Stehen schon oben.' : 'Keine gewählt. Im Profil kannst du Messstationen wählen, die du hier und auf der Karte siehst.'),
  ];
}

const _src = 'Quelle: BAFU (über api.existenz.ch bzw. hydrodaten.admin.ch). Werte ungeprüft. Gefahrenstufen 1–5 gemäss BAFU.';

/// Karte «Pegel & Abfluss» im Menü (hydroCard)
Widget? hydroCard(BuildContext c, IncStore s, MapCtl map, VoidCallback openPrefs) {
  if (!s.feat('hydro')) return null;
  return MCard(title: 'Pegel & Abfluss', buttons: [
    OutlinedButton(onPressed: openPrefs, child: const Text('Messstationen wählen')),
  ], children: [
    ...hyList(c, s, map),
    const Padding(padding: EdgeInsets.only(top: 6), child: Mut(_src, size: 11)),
  ]);
}

/// Seitenfenster «Messstationen» (Kartenknopf, Issue #147)
class HydPanel extends StatelessWidget {
  const HydPanel({super.key, required this.s, required this.map, required this.openPrefs});
  final IncStore s;
  final MapCtl map;
  final VoidCallback openPrefs;
  @override
  Widget build(BuildContext context) => ListenableBuilder(
      listenable: s,
      builder: (c, _) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            PanelHead('Messstationen', onClose: () => map.openPanel(null)),
            Expanded(child: ListView(padding: const EdgeInsets.fromLTRB(12, 0, 12, 12), children: hyList(c, s, map))),
            Container(
                decoration: BoxDecoration(border: Border(top: BorderSide(color: Theme.of(c).dividerColor))),
                padding: const EdgeInsets.fromLTRB(4, 0, 12, 8),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  CheckboxListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      controlAffinity: ListTileControlAffinity.leading,
                      value: s.vis('hydro'),
                      title: const Text('Messstationen auf der Karte anzeigen'),
                      onChanged: (v) => s.setVis(['hydro'], v == true)),
                  Padding(
                      padding: const EdgeInsets.only(left: 8),
                      child: OutlinedButton(
                          onPressed: () {
                            map.openPanel(null);
                            openPrefs();
                          },
                          child: const Text('Messstationen wählen'))),
                  const Padding(padding: EdgeInsets.only(left: 8, top: 4), child: Mut('Quelle: BAFU. Werte ungeprüft.', size: 11)),
                ])),
          ]));
}

/// Marker der Messstationen (drawHydro): 💧 auf Blau bzw. in der Farbe der Gefahrenstufe, gelber Rand = Station des Einsatzes
MarkerLayer? hydroLayer(IncStore s, void Function(String id) onTap) {
  if (!s.feat('hydro') || !s.vis('hydro')) return null;
  final mine = s.hyMine.toSet(), mk = <Marker>[];
  for (final id in s.hyIds) {
    final st = s.hyStation(id);
    final la = jd(st?['lat']), lo = jd(st?['lon']);
    if (la == null || lo == null) continue;
    final g = ji(s.hyData[id]?['danger']);
    mk.add(Marker(
        point: LatLng(la, lo),
        width: 28,
        height: 28,
        child: GestureDetector(
            onTap: () => onTap(id),
            child: Container(
                alignment: Alignment.center,
                decoration: BoxDecoration(
                    color: hyColor(g != null && g > 1 ? g : null),
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: mine.contains(id) ? Colors.white : const Color(0xFFFFD166), width: 2),
                    boxShadow: const [BoxShadow(color: Colors.black54, blurRadius: 4)]),
                child: const Text('💧', style: TextStyle(fontSize: 13))))));
  }
  return mk.isEmpty ? null : MarkerLayer(markers: mk);
}

/// Zeilen des Info-Blatts zu einer Station (hyPop)
List<String> hyPopLines(IncStore s, String id) {
  final d = hyDist(s, id);
  return ['Messstation BAFU $id${d.isNotEmpty ? ' · $d' : ''}', 'Aktuell: ${hyNow(s, id)}', ...hyPh(s, id)];
}

/// Profil: persönliche Messstationen (hyPrefs, höchstens 20), Suche in allen Stationen des BAFU
class HydroPrefs extends StatefulWidget {
  const HydroPrefs({super.key, required this.s});
  final IncStore s;
  @override
  State<HydroPrefs> createState() => _HydroPrefsState();
}

class _HydroPrefsState extends State<HydroPrefs> {
  IncStore get s => widget.s;
  final _q = TextEditingController();
  String? _err;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    s.hyPoll();
  }

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    if (s.hyAll != null || _loading) return;
    setState(() => _loading = true);
    try {
      await s.hyLoadAll();
      _err = null;
    } catch (e) {
      _err = '$e';
    }
    if (mounted) setState(() => _loading = false);
  }

  @override
  Widget build(BuildContext context) {
    final m = s.hyMine, t = _q.text.trim().toLowerCase();
    final hits = t.isEmpty || s.hyAll == null
        ? <J>[]
        : s.hyAll!.where((x) => !m.contains(js(x['id'])) && '${js(x['name'])} ${js(x['water'])} ${js(x['id'])}'.toLowerCase().contains(t)).take(8).toList();
    return MCard(title: 'Pegel & Abfluss', children: [
      const Mut('Messstationen des BAFU, die du im Menü und auf der Karte siehst (Kategorie «Messstationen» im Fenster «Ansicht»).'),
      const SizedBox(height: 4),
      if (m.isEmpty) const Mut('Noch keine gewählt.'),
      for (var k = 0; k < m.length; k++)
        Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(children: [
              Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(s.hyStation(m[k]) != null ? hyName(s.hyStation(m[k])!) : 'Station ${m[k]}', style: const TextStyle(fontWeight: FontWeight.bold)),
                Mut(hyNow(s, m[k]), size: 12),
              ])),
              IconButton(
                  tooltip: 'Nach oben',
                  visualDensity: VisualDensity.compact,
                  onPressed: k == 0
                      ? null
                      : () {
                          final l = [...m];
                          final x = l[k - 1];
                          l[k - 1] = l[k];
                          l[k] = x;
                          s.hySave(l);
                        },
                  icon: const Icon(Icons.arrow_upward)),
              IconButton(tooltip: 'Entfernen', visualDensity: VisualDensity.compact, onPressed: () => s.hySave(m.where((x) => x != m[k]).toList()), icon: const Icon(Icons.close)),
            ])),
      if (m.length < 20) ...[
        const SizedBox(height: 6),
        TextField(
            controller: _q,
            decoration: const InputDecoration(labelText: 'Messstation hinzufügen', hintText: 'Ort, Gewässer oder Nummer', isDense: true),
            onTap: _load,
            onChanged: (_) {
              _load();
              setState(() {});
            }),
        if (t.isNotEmpty && _loading) const Padding(padding: EdgeInsets.only(top: 6), child: Mut('Stationen werden geladen …')),
        if (t.isNotEmpty && _err != null) Padding(padding: const EdgeInsets.only(top: 6), child: Mut(_err!)),
        if (t.isNotEmpty && s.hyAll != null && hits.isEmpty) const Padding(padding: EdgeInsets.only(top: 6), child: Mut('Keine passende Station.')),
        ...hits.map((x) {
          final f = jd(x['flow']);
          return Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                  onPressed: () {
                    _q.clear();
                    FocusScope.of(context).unfocus();
                    s.hySave([...m, js(x['id'])]);
                  },
                  child: Text('+ ${hyName(x)} (${js(x['type']) == 'lake' ? 'See' : 'Fluss'}${f != null ? ', ${_n(f, f < 10 ? 2 : 1)} m³/s' : ''})')));
        }),
      ],
    ]);
  }
}
