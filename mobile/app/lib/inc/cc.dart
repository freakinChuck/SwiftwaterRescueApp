// Einsatzkonzepte (Issue #260), native Einsatz-App: wie web/cc.js.
// - Hinweis auf der Karte (ccBanner, nur Einsatzleitung): vorgeschlagene Konzepte (PLS im Geltungsbereich) und Anschlusskonzepte (das Suchgebiet erreicht
//   den Geltungsbereich eines weiteren Konzepts), «Vorschau …», «Laden», «Später» (pro Gerät und Einsatz, nur im Speicher).
// - Seitenfenster «Einsatzkonzept laden» (CcPanel, panel 'cc'): Liste aller Konzepte des Einsatztyps bzw. Elemente eines Konzepts mit Häkchen, «N von M Elementen laden».
// - Vorschau gestrichelt auf der Karte (ccPreviewLayers), Karte «Einsatzkonzepte» im Register «Leitung» (ccLeadCard),
//   Posten im Fenster «Markierungen» mit «noch nicht besetzt» bzw. «besetzt durch …» (ccPostTxt).
import 'dart:math' as math;

import 'package:flutter/material.dart' hide Text;
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import 'ctx.dart';
import 'pois.dart';
import 'store.dart';
import 'util.dart';
import '../i18n.dart';

class CcState {
  List<J>? list;
  String? key;
  int? prev; // Konzept in der Vorschau, null = Liste
  Set<int>? sel;
  double postM = 150;
  final later = <String>{};
  int? inc;
}

final _cc = Expando<CcState>();
CcState ccOf(IncStore s) => _cc[s] ??= CcState();

const ccKindNames = {
  'post': 'Posten', 'point': 'Markierung', 'area': 'Bereich', 'line': 'Linie', 'ext': 'Externe Einsatzkraft', 'msg': 'Nachricht an alle', 'doc': 'Dokumente',
  'stop': 'Flusssperre', 'voice': 'Funkkanäle', 'invite': 'Nachbieten', 'opt': 'Einstellung des Einsatzes'
};
const _ccFeat = {'post': 'poi', 'point': 'poi', 'area': 'poi', 'line': 'poi', 'ext': 'ext', 'msg': 'msg', 'doc': 'docs', 'stop': 'area', 'voice': 'voice'};
const _ccGrp = [
  ('Posten', ['post', 'ext']),
  ('Markierungen', ['point']),
  ('Bereiche und Linien', ['area', 'line']),
  ('Weiteres', ['msg', 'doc', 'stop', 'voice', 'invite', 'opt'])
];
const _ccViolet = Color(0xFF7B4BD6);

J ccS(IncStore s) => jm(s.st?['cc']);
bool ccOn(IncStore s) => s.inc != null && s.st?['cc'] is Map && s.feat('cc');
bool ccLead(IncStore s) => ccOn(s) && s.canManage;
String? ccName(IncStore s, int? id) => id == null ? null : jl(ccS(s)['loaded']).where((x) => ji(x['id']) == id).map((x) => js(x['name'])).firstOrNull;
bool _ok(IncStore s, J it) => _ccFeat[it['kind']] == null || s.feat(_ccFeat[it['kind']]!);
List<int> _done(J c) => (c['done'] is List ? c['done'] as List : const []).map((x) => ji(x) ?? 0).toList();
List<LatLng> _pts(dynamic v) => v is List ? v.whereType<List>().where((q) => q.length >= 2).map((q) => LatLng(jd(q[0]) ?? 0, jd(q[1]) ?? 0)).toList() : <LatLng>[];

/// Liste der Konzepte (incident/concepts), neu bei geändertem Stand; danach neu zeichnen
Future<void> ccFetch(IncStore s, {bool force = false}) async {
  if (!ccLead(s)) return;
  final x = ccOf(s), c = ccS(s), inc = ji(s.inc?['id']);
  if (x.inc != inc) {
    x.later.clear();
    x.inc = inc;
  }
  final k = '$inc:${c['ver']}:${jl(c['loaded']).map((l) => '${l['id']}/${l['n']}').join(',')}:${jl(c['sug']).length}';
  if (!force && x.key == k) return;
  x.key = k;
  try {
    final r = await s.api.call('incident/concepts');
    x.list = jl(r['list']);
    x.postM = jd(r['post_m']) ?? 150;
    s.ping();
  } catch (_) {
    x.key = null;
  }
}

// Geometrie wie concepts.php
bool _pip(LatLng p, List<LatLng> poly) => poly.length >= 3 && inPoly(p, poly);
double _seg(LatLng p, LatLng a, LatLng b) {
  final k = 111320 * math.cos(p.latitude * math.pi / 180), px = (p.longitude - a.longitude) * k, py = (p.latitude - a.latitude) * 111320, bx = (b.longitude - a.longitude) * k, by = (b.latitude - a.latitude) * 111320;
  final l = bx * bx + by * by, t = l > 0 ? ((px * bx + py * by) / l).clamp(0.0, 1.0) : 0.0;
  final dx = px - t * bx, dy = py - t * by;
  return math.sqrt(dx * dx + dy * dy);
}

List<List<LatLng>> ccGeo(J c) => c['scope'] == 'river' ? (jm(c['river'])['lines'] is List ? (jm(c['river'])['lines'] as List).map(_pts).toList() : <List<LatLng>>[]) : [_pts(c['area'])];
bool ccHit(J c, LatLng p) {
  if (c['scope'] == 'river') {
    final buf = jd(jm(c['river'])['buf']) ?? 150;
    return ccGeo(c).any((l) => [for (var i = 1; i < l.length; i++) i].any((i) => _seg(p, l[i - 1], l[i]) <= buf));
  }
  return _pip(p, _pts(c['area']));
}

/// Anschlusskonzepte: nicht geladen, nicht abgelehnt, nicht passend zum PLS, und das Suchgebiet berührt den Geltungsbereich
List<J> ccNext(IncStore s) {
  final c = ccS(s), l = ccOf(s).list, a = s.area;
  if (!ccLead(s) || !jb(c['next']) || l == null || a == null) return const [];
  final pts = <LatLng>[for (final x in a.lines) ...x, for (final ci in a.circles) ...[ci.c, for (var k = 0; k < 16; k++) LatLng(ci.c.latitude + ci.r / 111320 * math.cos(k * math.pi / 8), ci.c.longitude + ci.r / 111320 * math.sin(k * math.pi / 8) / math.cos(ci.c.latitude * math.pi / 180))]];
  return l.where((x) => x['state'] == null && !jb(x['fit']) && x['mode'] != 'off' && pts.any((p) => ccHit(x, p))).toList();
}

/// Posten: besetzt, wenn eine aktive Einsatzkraft mit der Spezialrolle höchstens post_m entfernt ist
String ccPostTxt(IncStore s, J p) {
  final rid = ji(p['cc_role']), pt = poiPt(p);
  if (rid == null || pt == null) return '';
  final rn = js(s.roles.where((r) => ji(r['id']) == rid).map((r) => r['name']).firstOrNull ?? 'Spezialrolle');
  final near = s.participants
      .where((x) => x['left_at'] == null && x['mode'] != 'declined' && ji(x['role_id']) == rid && x['lat'] != null && geoDist(LatLng(jd(x['lat'])!, jd(x['lon'])!), pt) <= ccOf(s).postM)
      .map((x) => js(x['name']))
      .toList();
  return '${tr('Auftrag')} $rn · ${near.isEmpty ? tr('noch nicht besetzt') : '${tr('besetzt durch')} ${near.join(', ')}'}';
}

String _itemTxt(IncStore s, J it) {
  final r = jm(it['refs']);
  switch (it['kind']) {
    case 'post':
      final rn = s.roles.where((x) => ji(x['id']) == ji(it['role_id'])).map((x) => js(x['name'])).firstOrNull;
      final n = jl0(r['users']) + jl0(r['groups']);
      return ['Auftrag', ?rn, if (n > 0) 'Zuteilung an $n'].join(' · ');
    case 'msg':
      return '«${js(it['text'])}»';
    case 'doc':
      return 'Im Fenster «Dokumente»';
    case 'invite':
      return 'Mit Alarm';
    case 'stop':
      return jb(r['off']) ? 'Im Suchgebiet nicht berücksichtigen' : 'Im Suchgebiet berücksichtigen';
    default:
      return js(it['descr']).isNotEmpty ? js(it['descr']) : (ccKindNames[it['kind']] ?? '');
  }
}

int jl0(dynamic v) => v is List ? v.length : 0;

Widget _itemIc(IncStore s, J it) {
  if (js(it['sym']).isNotEmpty) return symPic(s, js(it['sym']), size: 24);
  if (it['kind'] == 'ext') {
    return Container(
        width: 22,
        height: 22,
        alignment: Alignment.center,
        decoration: BoxDecoration(color: hexColor(it['color'], const Color(0xFF5A6B74)), border: Border.all(color: Colors.white, width: 1.5)),
        child: Text(js(it['abbr']).isEmpty ? 'EX' : js(it['abbr']), style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold)));
  }
  if (it['kind'] == 'area' || it['kind'] == 'line') {
    return Container(width: 16, height: it['kind'] == 'line' ? 4 : 16, color: hexColor(it['color'], const Color(0xFFFF9F1C)));
  }
  const ic = {'msg': '✉', 'doc': '📄', 'stop': '⛔', 'voice': '🎙', 'invite': '📣', 'opt': '⚙', 'post': '⚑', 'point': '⚑'};
  return SizedBox(width: 24, child: Text(ic[it['kind']] ?? '•', textAlign: TextAlign.center, style: const TextStyle(fontSize: 18)));
}

/// Vorschau öffnen (cid = Konzept, null = Liste)
Future<void> ccOpen(IncStore s, MapCtl map, int? cid) async {
  final x = ccOf(s);
  if (x.list == null) await ccFetch(s, force: true);
  x.prev = cid;
  x.sel = null;
  final c = x.list?.where((y) => ji(y['id']) == cid).firstOrNull;
  if (c != null) {
    x.sel = jl(c['items']).where((it) => jb(it['def_on']) && _ok(s, it) && !_done(c).contains(ji(it['id']))).map((it) => ji(it['id'])!).toSet();
    final pts = <LatLng>[for (final l in ccGeo(c)) ...l, for (final it in jl(c['items'])) ...[if (it['lat'] != null) LatLng(jd(it['lat'])!, jd(it['lon'])!), ..._pts(it['pts'])]];
    if (pts.isNotEmpty) map.fitPts(pts);
  }
  map.openPanel('cc');
  s.ping();
}

Future<void> ccLoad(BuildContext ctx, IncStore s, MapCtl map, int cid, [List<int>? items]) async {
  final r = await s.act('incident/concept/load', {'id': cid, 'items': ?items}, 'Einsatzkonzept geladen.');
  if (r != null) {
    ccOf(s).prev = null;
    map.openPanel(null);
    ccFetch(s, force: true);
  }
}

/// Gestrichelte Vorschau des gewählten Konzepts (nur bei offenem Fenster 'cc')
List<Widget> ccPreviewLayers(IncStore s, String? panel) {
  final x = ccOf(s);
  if (panel != 'cc' || x.prev == null || !ccLead(s)) return const [];
  final c = x.list?.where((y) => ji(y['id']) == x.prev).firstOrNull;
  if (c == null) return const [];
  const v = Color(0xFFB18CFF);
  final its = jl(c['items']).where((it) => x.sel?.contains(ji(it['id'])) == true).toList();
  final buf = jd(jm(c['river'])['buf']) ?? 150;
  return [
    if (c['scope'] == 'river')
      PolylineLayer(polylines: [for (final l in ccGeo(c)) Polyline(points: l, color: v.withValues(alpha: .4), strokeWidth: (buf / 8).clamp(6, 30).toDouble())])
    else
      PolygonLayer(polygons: [
        Polygon(points: _pts(c['area']), color: _ccViolet.withValues(alpha: .08), borderColor: v, borderStrokeWidth: 3, pattern: StrokePattern.dashed(segments: const [8, 6]))
      ]),
    PolygonLayer(polygons: [
      for (final it in its.where((it) => it['kind'] == 'area'))
        Polygon(points: _pts(it['pts']), color: hexColor(it['color']).withValues(alpha: .15), borderColor: hexColor(it['color']), borderStrokeWidth: 2, pattern: StrokePattern.dashed(segments: const [6, 5]))
    ]),
    PolylineLayer(polylines: [
      for (final it in its.where((it) => it['kind'] == 'line')) Polyline(points: _pts(it['pts']), color: hexColor(it['color']), strokeWidth: 4, pattern: StrokePattern.dashed(segments: const [6, 5]))
    ]),
    MarkerLayer(markers: [
      for (final it in its.where((it) => it['lat'] != null && ['post', 'point', 'ext'].contains(it['kind'])))
        Marker(
            point: LatLng(jd(it['lat'])!, jd(it['lon'])!),
            width: 190,
            height: 30,
            alignment: const Alignment(.84, 0),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Opacity(opacity: .8, child: _itemIc(s, it)),
              const SizedBox(width: 4),
              Flexible(
                  child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
                      decoration: BoxDecoration(color: const Color(0xCC3B1F6E), borderRadius: BorderRadius.circular(4), border: Border.all(color: v)),
                      child: Text(js(it['name']).split(' – ').first, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 11)))),
            ]))
    ]),
  ];
}

/// Hinweis auf der Karte (unter dem Einsatz-Chip): erster Vorschlag bzw. erstes Anschlusskonzept
Widget? ccBanner(BuildContext ctx, IncStore s, MapCtl map, String? panel) {
  if (!ccLead(s) || panel == 'cc') return null;
  ccFetch(s);
  final x = ccOf(s), c = ccS(s);
  final l = [
    for (final y in jl(c['sug'])) (k: 's:${y['id']}', cid: ji(y['concept_id'])!, name: js(y['name']), n: ji(y['n']) ?? 0, why: 'Der PLS liegt im Geltungsbereich', next: false),
    for (final y in ccNext(s)) (k: 'n:${y['id']}', cid: ji(y['id'])!, name: js(y['name']), n: jl(y['items']).length, why: 'Das Suchgebiet erreicht den Geltungsbereich (Anschluss)', next: true),
  ].where((y) => !x.later.contains(y.k)).toList();
  if (l.isEmpty) return null;
  final y = l.first;
  return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Material(
          color: const Color(0xFF3B1F6E),
          elevation: 3,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10), side: const BorderSide(color: Color(0xFF9B7BE6))),
          child: Padding(
              padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                Text(y.next ? '📋 Anschlusskonzept verfügbar' : '📋 Einsatzkonzept verfügbar', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                Text('«${y.name}»', style: const TextStyle(color: Colors.white)),
                Text('${tr(y.why)} · ${y.n} ${tr(y.n == 1 ? 'Element' : 'Elemente')}${l.length > 1 ? ' · ${l.length - 1} ${tr('weitere')}' : ''}',
                    style: const TextStyle(color: Color(0xFFD6C8F5), fontSize: 12)),
                const SizedBox(height: 6),
                Row(children: [
                  Expanded(child: FilledButton(style: FilledButton.styleFrom(backgroundColor: _ccViolet), onPressed: () => ccOpen(s, map, y.cid), child: const Text('Vorschau …'))),
                  const SizedBox(width: 6),
                  Expanded(
                      child: OutlinedButton(
                          style: OutlinedButton.styleFrom(foregroundColor: Colors.white),
                          onPressed: () async {
                            if (await confirmDlg(ctx, '${tr('Einsatzkonzept laden?')}\n\n${tr('Die vorausgewählten Elemente erscheinen für alle auf der Karte.')}', ok: 'Laden') &&
                                ctx.mounted) {
                              ccLoad(ctx, s, map, y.cid);
                            }
                          },
                          child: const Text('Laden'))),
                  const SizedBox(width: 6),
                  Expanded(
                      child: OutlinedButton(
                          style: OutlinedButton.styleFrom(foregroundColor: Colors.white),
                          onPressed: () {
                            x.later.add(y.k);
                            s.ping();
                          },
                          child: const Text('Später'))),
                ]),
              ]))));
}

/// Seitenfenster «Einsatzkonzept laden»
class CcPanel extends StatelessWidget {
  const CcPanel({super.key, required this.s, required this.map});
  final IncStore s;
  final MapCtl map;
  @override
  Widget build(BuildContext context) => ListenableBuilder(
      listenable: s,
      builder: (c, _) {
        final x = ccOf(s), l = x.list, cc = l?.where((y) => ji(y['id']) == x.prev).firstOrNull;
        void close() {
          x.prev = null;
          map.openPanel(null);
        }
        if (l == null) {
          return Column(children: [PanelHead('Einsatzkonzepte', onClose: close), const Padding(padding: EdgeInsets.all(16), child: Mut('Wird geladen …'))]);
        }
        if (cc == null) {
          final ls = [...l]..sort((a, b) => (jb(b['fit']) ? 1 : 0) - (jb(a['fit']) ? 1 : 0));
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            PanelHead('Einsatzkonzept laden', onClose: close),
            Expanded(
                child: ListView(padding: const EdgeInsets.fromLTRB(12, 0, 12, 12), children: [
              if (ls.isEmpty) const Mut('Für diesen Einsatztyp gibt es keine Einsatzkonzepte. Die Verwaltung legt sie unter Einsatz → «Einsatzkonzepte» an.'),
              for (final y in ls)
                ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                      Text(js(y['name'])),
                      if (jb(y['fit'])) const Chip2('passt zum PLS', color: _ccViolet),
                      if (y['state'] == 'loaded') const Chip2('geladen', color: Color(0xFF5A6B74)),
                    ]),
                    subtitle: Text('${jl(y['items']).length} ${tr(jl(y['items']).length == 1 ? 'Element' : 'Elemente')}${js(y['descr']).isNotEmpty ? ' · ${y['descr']}' : ''}'),
                    trailing: OutlinedButton(onPressed: () => ccOpen(s, map, ji(y['id'])), child: const Text('Vorschau …'))),
            ])),
          ]);
        }
        final its = jl(cc['items']), done = _done(cc), sel = x.sel ??= its.where((it) => jb(it['def_on']) && _ok(s, it) && !done.contains(ji(it['id']))).map((it) => ji(it['id'])!).toSet();
        final m = its.where((it) => _ok(s, it) && !done.contains(ji(it['id']))).length;
        Widget row(J it) {
          final id = ji(it['id'])!, dn = done.contains(id), ok = _ok(s, it);
          return Opacity(
              opacity: dn || !ok ? .5 : 1,
              child: CheckboxListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: sel.contains(id),
                  onChanged: dn || !ok
                      ? null
                      : (v) {
                          v == true ? sel.add(id) : sel.remove(id);
                          s.ping();
                        },
                  secondary: _itemIc(s, it),
                  title: Text(js(it['name'])),
                  subtitle: Text([
                    _itemTxt(s, it),
                    if (dn) tr('bereits geladen'),
                    if (!ok) tr('Funktion für diesen Einsatztyp ausgeschaltet'),
                    if (['msg', 'invite'].contains(it['kind'])) tr('löst Meldungen aus'),
                  ].join(' · '))));
        }

        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          PanelHead('Einsatzkonzept laden', onClose: close),
          Expanded(
              child: ListView(padding: const EdgeInsets.fromLTRB(12, 0, 12, 12), children: [
            Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
              Text(js(cc['name']), style: const TextStyle(fontWeight: FontWeight.bold)),
              if (jb(cc['fit'])) const Chip2('passt zum PLS', color: _ccViolet),
              if (cc['state'] == 'loaded') const Chip2('geladen', color: Color(0xFF5A6B74)),
            ]),
            if (js(cc['descr']).isNotEmpty) Mut(js(cc['descr'])),
            Mut(cc['scope'] == 'river' ? 'Geltungsbereich: entlang Gewässer' : 'Geltungsbereich: Bereich auf der Karte'),
            for (final (t, ks) in _ccGrp)
              if (its.any((it) => ks.contains(it['kind']))) ...[
                Padding(padding: const EdgeInsets.only(top: 8), child: Text('${tr(t)} (${its.where((it) => ks.contains(it['kind'])).length})', style: const TextStyle(fontWeight: FontWeight.w600))),
                for (final it in its.where((it) => ks.contains(it['kind']))) row(it),
              ],
          ])),
          Container(
              decoration: BoxDecoration(border: Border(top: BorderSide(color: Theme.of(c).dividerColor))),
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                FilledButton(
                    style: FilledButton.styleFrom(backgroundColor: _ccViolet),
                    onPressed: sel.isEmpty
                        ? null
                        : () async {
                            final mm = its.where((it) =>
                                sel.contains(ji(it['id'])) &&
                                (['msg', 'invite'].contains(it['kind']) || it['kind'] == 'post' && (jl0(jm(it['refs'])['users']) + jl0(jm(it['refs'])['groups'])) > 0));
                            if (mm.isNotEmpty &&
                                !await confirmDlg(c, '${tr('Einsatzkonzept laden?')}\n\n${tr('Dabei gehen Meldungen hinaus:')} ${mm.map((it) => js(it['name'])).join(', ')}.', ok: 'Laden')) {
                              return;
                            }
                            if (c.mounted) ccLoad(c, s, map, ji(cc['id'])!, sel.toList());
                          },
                    child: Text('${sel.length} ${tr('von')} $m ${tr(m == 1 ? 'Element' : 'Elementen')} ${tr('laden')}')),
                TextButton(
                    onPressed: () {
                      x.prev = null;
                      s.ping();
                    },
                    child: const Text('Andere Konzepte')),
              ])),
        ]);
      });
}

/// Karte «Einsatzkonzepte» im Register «Leitung»
Widget? ccLeadCard(BuildContext c, IncStore s, MapCtl map) {
  if (!ccLead(s)) return null;
  ccFetch(s);
  final cs = ccS(s), l = ccOf(s).list ?? const <J>[];
  J? cn(int? id) => l.where((y) => ji(y['id']) == id).firstOrNull;
  Widget row(String name, String chip, String info, List<Widget> btns, {bool grey = false, Widget? extra}) => Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
          Text(name, style: const TextStyle(fontWeight: FontWeight.bold)),
          Chip2(chip, color: grey ? const Color(0xFF5A6B74) : _ccViolet)
        ]),
        if (info.isNotEmpty) Mut(info),
        ?extra,
        Wrap(spacing: 8, runSpacing: 4, children: btns),
      ]));
  final loaded = jl(cs['loaded']), sug = jl(cs['sug']), fit = (cs['fit'] is List ? cs['fit'] as List : const []).map((x) => ji(x)).toList();
  final children = <Widget>[
    for (final x in loaded)
      row(
          js(x['name']),
          jb(x['auto']) ? 'automatisch geladen' : 'geladen',
          '${fmtHM(ji(x['at']))}${x['by_name'] != null ? ' ${tr('von')} ${x['by_name']}' : ''} · ${x['n']} ${tr(ji(x['n']) == 1 ? 'Element' : 'Elemente')}',
          [
            OutlinedButton(onPressed: () => ccShow(s, map, ji(x['id'])), child: const Text('Zeigen')),
            OutlinedButton(onPressed: () => ccOpen(s, map, ji(x['concept_id'])), child: const Text('Elemente …')),
            OutlinedButton(
                onPressed: () async {
                  if (await confirmDlg(
                      c,
                      '${tr('Einsatzkonzept entfernen?')}\n\n${tr('Die Markierungen, Bereiche, Linien und Externen des Konzepts verschwinden für alle. Nachrichten, Funkkanäle, Flusssperren und Nachbieten bleiben.')}',
                      ok: 'Entfernen',
                      danger: true)) {
                    await s.act('incident/concept/remove', {'id': ji(x['id'])}, 'Einsatzkonzept entfernt.');
                    ccFetch(s, force: true);
                  }
                },
                child: const Text('Entfernen')),
          ],
          extra: jl(x['pend']).isEmpty
              ? null
              : Container(
                  margin: const EdgeInsets.symmetric(vertical: 4),
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(border: Border.all(color: const Color(0xFF9B7BE6)), borderRadius: BorderRadius.circular(6)),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${tr('Offen (beim automatischen Laden nicht ausgelöst):')} ${jl(x['pend']).map((p) => js(p['name'])).join(', ')}'),
                    Wrap(spacing: 8, children: [
                      OutlinedButton(onPressed: () => s.act('incident/concept/run', {'id': ji(x['id'])}, 'Ausgelöst.'), child: const Text('Jetzt auslösen')),
                      OutlinedButton(
                          onPressed: () async {
                            if (await confirmDlg(c, '${tr('Offene Aktionen verwerfen?')}\n\n${tr('Nachrichten, Zuteilungen und Nachbieten des Konzepts werden dann nicht mehr ausgelöst.')}', ok: 'Verwerfen')) {
                              s.act('incident/concept/drop', {'id': ji(x['id'])});
                            }
                          },
                          child: const Text('Verwerfen')),
                    ]),
                  ]))),
    for (final x in sug)
      row(js(x['name']), 'vorgeschlagen', '${tr('Der PLS liegt im Geltungsbereich')} · ${x['n']} ${tr(ji(x['n']) == 1 ? 'Element' : 'Elemente')}', [
        FilledButton(style: FilledButton.styleFrom(backgroundColor: _ccViolet), onPressed: () => ccOpen(s, map, ji(x['concept_id'])), child: const Text('Vorschau …')),
        OutlinedButton(onPressed: () => ccLoad(c, s, map, ji(x['concept_id'])!), child: const Text('Laden')),
        OutlinedButton(onPressed: () => s.act('incident/concept/dismiss', {'id': ji(x['id'])}), child: const Text('Nicht laden')),
      ]),
    for (final y in ccNext(s))
      row(js(y['name']), 'Anschluss', tr('Das Suchgebiet erreicht den Geltungsbereich.'), [OutlinedButton(onPressed: () => ccOpen(s, map, ji(y['id'])), child: const Text('Vorschau …'))], grey: true),
    for (final id in fit.where((id) => !loaded.any((x) => ji(x['concept_id']) == id) && !sug.any((x) => ji(x['concept_id']) == id)))
      if (cn(id) != null) row(js(cn(id)!['name']), 'passt zum PLS', '', [OutlinedButton(onPressed: () => ccOpen(s, map, id), child: const Text('Vorschau …'))]),
  ];
  return MCard(title: 'Einsatzkonzepte', buttons: [
    OutlinedButton(onPressed: () => ccOpen(s, map, null), child: Text(loaded.isEmpty ? 'Einsatzkonzept laden …' : 'Anderes Konzept laden …'))
  ], children: [
    if (children.isEmpty) Mut(ccOf(s).list != null && l.isEmpty ? 'Für diesen Einsatztyp gibt es keine Einsatzkonzepte.' : 'Kein Einsatzkonzept geladen.'),
    ...children,
  ]);
}

/// Ausschnitt mit allen Markierungen und Externen eines geladenen Konzepts
void ccShow(IncStore s, MapCtl map, int? id) {
  final pts = <LatLng>[
    for (final p in s.pois.where((p) => ji(p['cc_id']) == id)) ...poiPts(p),
    for (final e in s.externals.where((e) => ji(e['cc_id']) == id && e['lat'] != null)) LatLng(jd(e['lat'])!, jd(e['lon'])!)
  ];
  if (pts.isEmpty) {
    s.toast(tr('Keine Elemente auf der Karte.'), 'info');
    return;
  }
  map.closeMenu();
  map.fitPts(pts);
}
