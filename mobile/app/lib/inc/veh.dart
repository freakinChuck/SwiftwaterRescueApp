// Fahrzeuge mit BLE-Beacons (Issue #177) in der nativen Einsatz-App, Gegenstück zu vehDraw/vehPop/vehFold/vehLoadDlg in web/index.html und common.js.
// st.veh vom Server ({kinds, list, hints, stale_min, can_link}, null = für diese Person nicht sichtbar). Ketten (Verbund: Anhänger am Zugfahrzeug,
// Boot auf dem Anhänger) als ein Marker beim obersten Fahrzeug. Verladen/abladen dürfen alle Teilnehmenden und die Einsatzleitung, ohne Einsatz die Verwaltung.
import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';

import 'ctx.dart';
import 'store.dart';
import 'util.dart';

J? vehOf(IncStore s) => s.st?['veh'] is Map ? jm(s.st!['veh']) : null;
List<J> vehList(IncStore s) => jl(vehOf(s)?['list']);
J? vehById(IncStore s, dynamic id) => id == null ? null : vehList(s).where((v) => ji(v['id']) == ji(id)).firstOrNull;
J vehKind(IncStore s, J v) =>
    jl(vehOf(s)?['kinds']).where((k) => ji(k['id']) == ji(v['kind_id'])).firstOrNull ?? <String, dynamic>{'name': 'Fahrzeug', 'sym': '🚐', 'persons': 1};
bool _hang(IncStore s, J c) => jb(vehKind(s, c)['persons']); // an einem Fahrzeug mit Personen «angehängt», sonst «verladen»

/// oberste Fahrzeuge (nicht aufgenommen) und was sie tragen
List<J> vehRoots(IncStore s) {
  final l = vehList(s);
  return l.where((v) => v['carrier_id'] == null || !l.any((x) => ji(x['id']) == ji(v['carrier_id']))).toList();
}

List<J> vehKids(IncStore s, int? id) {
  final l = vehList(s), o = <J>[];
  void add(int? i) {
    for (final x in l.where((x) => ji(x['carrier_id']) == i && i != null)) {
      if (!o.contains(x)) {
        o.add(x);
        add(ji(x['id']));
      }
    }
  }

  add(id);
  return o;
}

bool vehStale(IncStore s, J v) => v['seen_at'] == null || s.now - (jn(v['seen_at']) ?? 0) > (ji(vehOf(s)?['stale_min']) ?? 30) * 60;

String vehAgo(num? ts, num now) {
  if (ts == null) return 'noch nie gesehen';
  final d = (now - ts).round().clamp(0, 1 << 31);
  if (d < 60) return 'gerade eben';
  if (d < 3600) return 'vor ${(d / 60).round()} min';
  if (d < 86400) return 'vor ${d ~/ 3600} h ${((d % 3600) / 60).round()} min';
  return 'am ${fmtDT(ts)}';
}

String vehInfo(IncStore s, J v) {
  final c = vehById(s, v['carrier_id']);
  return [
    js(vehKind(s, v)['name']),
    if (c != null) '${_hang(s, c) ? 'an' : 'auf'} «${js(c['name'])}»',
    '${jb(v['inc']) ? 'gesehen' : 'zuletzt gesehen'} ${vehAgo(jn(v['seen_at']), s.now)}',
  ].join(' · ');
}

List<String> vehCrew(IncStore s, J v) =>
    (v['crew'] is List ? v['crew'] as List : []).map((u) => s.participants.where((p) => ji(p['user_id']) == ji(u)).firstOrNull).whereType<J>().map((p) => js(p['name'])).toList();

/// «im Fahrzeug» einer Person (Funktion vehp)
String? vehOfPerson(IncStore s, J p) {
  final v = vehById(s, p['veh_id']);
  return v == null ? null : '${js(vehKind(s, v)['sym'])} im Fahrzeug «${js(v['name'])}»';
}

/// mögliche Träger für v (Art passt, nicht v selbst oder etwas, das v trägt) mit Belegung
List<({J c, int n, int? max, bool full})> vehCarriers(IncStore s, J v) {
  bool inV(J c) {
    J? r = c;
    for (var i = 0; i < 20 && r != null && r['carrier_id'] != null; i++) {
      if (ji(r['carrier_id']) == ji(v['id'])) return true;
      r = vehById(s, r['carrier_id']);
    }
    return false;
  }

  final o = <({J c, int n, int? max, bool full})>[];
  for (final c in vehList(s)) {
    final room = jm(c['room'])['${ji(v['kind_id'])}'];
    if (ji(c['id']) == ji(v['id']) || inV(c) || room is! Map) continue;
    final n = ji(room['n']) ?? 0, max = ji(room['max']);
    o.add((c: c, n: n, max: max, full: max != null && n >= max && ji(v['carrier_id']) != ji(c['id'])));
  }
  return o;
}

Future<void> vehLink(IncStore s, int id, int? carrier) => s.act(s.inc != null ? 'incident/vehicle/link' : 'vehicle/link', {'id': id, 'carrier': carrier});

/// Verladen bzw. anhängen: Auswahl der Träger, nächste zuerst, volle gesperrt
Future<void> vehLoadDlg(BuildContext context, IncStore s, J v) async {
  final ll = llOf(v);
  double? dist(J c) {
    final q = llOf(c);
    return ll == null || q == null ? null : const Distance().as(LengthUnit.Meter, ll, q);
  }

  final cs = vehCarriers(s, v)..sort((a, b) => (dist(a.c) ?? 1e9).compareTo(dist(b.c) ?? 1e9));
  final r = await showDialog<int>(
      context: context,
      builder: (d) => SimpleDialog(title: Text('«${js(v['name'])}» verladen bzw. anhängen'), children: [
            for (final x in cs)
              SimpleDialogOption(
                  onPressed: x.full ? null : () => Navigator.pop(d, ji(x.c['id'])),
                  child: Opacity(
                      opacity: x.full ? .45 : 1,
                      child: Text('${js(vehKind(s, x.c)['sym'])} ${js(x.c['name'])}'
                          '${x.max != null ? ' (${x.n} von ${x.max} belegt)' : ''}'
                          '${dist(x.c) != null ? ' · ${dist(x.c)! < 1000 ? '${dist(x.c)!.round()} m' : '${(dist(x.c)! / 1000).toStringAsFixed(1)} km'}' : ''}'
                          '${x.full ? ' – voll' : ''}'))),
          ]));
  if (r != null) await vehLink(s, ji(v['id'])!, r);
}

/// Knöpfe verladen/abladen für ein Fahrzeug (nur mit can_link)
List<Widget> vehBtns(BuildContext context, IncStore s, J v, {VoidCallback? before}) {
  if (!jb(vehOf(s)?['can_link'])) return [];
  final c = vehById(s, v['carrier_id']);
  return [
    if (c != null)
      smallBtn(_hang(s, c) ? 'Abhängen' : 'Abladen', () {
        before?.call();
        vehLink(s, ji(v['id'])!, null);
      }),
    if (vehCarriers(s, v).isNotEmpty)
      smallBtn(c != null ? 'Umladen …' : 'Verladen …', () {
        before?.call();
        vehLoadDlg(context, s, v);
      }),
  ];
}

/// Marker einer Kette: Farbe des obersten Fahrzeugs, aufgenommene als Zeichen daran, blass ohne neuere Sichtung
Widget vehChip(IncStore s, J root, List<J> kids) {
  final col = js(root['color']).isEmpty ? const Color(0xFF335C67) : hexColor(root['color']);
  final fg = fgOn(col);
  return Opacity(
      opacity: vehStale(s, root) ? .5 : 1,
      child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
          decoration: BoxDecoration(color: col, borderRadius: BorderRadius.circular(8), border: Border.all(color: Colors.white, width: 2), boxShadow: const [BoxShadow(color: Colors.black54, blurRadius: 3)]),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Text(js(vehKind(s, root)['sym']), style: const TextStyle(fontSize: 15)),
            if (js(root['abbr']).isNotEmpty) Text(' ${js(root['abbr'])}', style: TextStyle(color: fg, fontSize: 11, fontWeight: FontWeight.bold)),
            for (final k in kids)
              Container(
                  margin: const EdgeInsets.only(left: 2),
                  padding: const EdgeInsets.symmetric(horizontal: 2),
                  decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(4)),
                  child: Text(js(vehKind(s, k)['sym']), style: const TextStyle(fontSize: 12))),
          ])));
}

/// Zeilen fürs Info-Blatt einer Kette (Fahrzeug, Art, Verbund, gesehen, an Bord)
List<String> vehSheetLines(IncStore s, J root, List<J> kids) => [
      for (final v in [root, ...kids]) ...[
        '${js(vehKind(s, v)['sym'])} ${js(v['name'])}: ${vehInfo(s, v)}',
        if (js(v['descr']).isNotEmpty) js(v['descr']),
        if (vehCrew(s, v).isNotEmpty) 'An Bord: ${vehCrew(s, v).join(', ')}',
      ],
      if (jd(root['acc']) != null && jd(root['acc'])! > 50) 'Genauigkeit ca. ${jd(root['acc'])!.round()} m',
    ];

/// Gruppe «Fahrzeuge» im Fenster «Einsatzkräfte»: Hinweise «Träger voll», Ketten mit eingerückten aufgenommenen Fahrzeugen
List<Widget> vehRows(BuildContext context, IncStore s, MapCtl map) {
  final out = <Widget>[];
  for (final h in jl(vehOf(s)?['hints'])) {
    final a = vehById(s, h['id']), b = vehById(s, h['carrier']);
    if (a == null || b == null) continue;
    out.add(Container(
        margin: const EdgeInsets.symmetric(vertical: 4),
        padding: const EdgeInsets.all(8),
        decoration: BoxDecoration(color: const Color(0xFF5A4300), borderRadius: BorderRadius.circular(6)),
        child: Text('«${js(a['name'])}» fährt mit «${js(b['name'])}», dort ist aber kein Platz mehr. Bitte von Hand verladen.', style: const TextStyle(fontSize: 13))));
  }
  Widget row(J v, bool kid) {
    final ll = llOf(v), crew = vehCrew(s, v);
    return Padding(
        padding: EdgeInsets.only(left: kid ? 22 : 0, top: 5, bottom: 5),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 26, child: Text(js(vehKind(s, v)['sym']), style: const TextStyle(fontSize: 18))),
          const SizedBox(width: 6),
          Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(js(v['name']), style: const TextStyle(fontWeight: FontWeight.w600)),
            Mut(vehInfo(s, v), size: 12),
            if (crew.isNotEmpty) Mut('An Bord: ${crew.join(', ')}', size: 12),
            if (!kid && ll != null && s.fc(v['lat'], v['lon']).isNotEmpty) Mut(s.fc(v['lat'], v['lon']), size: 12),
            Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Wrap(spacing: 6, runSpacing: 4, children: [
                  if (!kid && ll != null)
                    smallBtn('Zentrieren', () {
                      if (!s.vis('veh')) s.setVis(['veh'], true);
                      if (map.followId != null) map.follow(null);
                      map.centerOn(ll);
                      if (MediaQuery.sizeOf(context).width < 760) map.openPanel(null);
                    }),
                  if (!kid && ll != null) ?navBtn(s, v['lat'], v['lon'], js(v['name'])),
                  ...vehBtns(context, s, v),
                ])),
          ])),
        ]));
  }

  for (final r in vehRoots(s)) {
    out.add(row(r, false));
    out.addAll(vehKids(s, ji(r['id'])).map((k) => row(k, true)));
  }
  return out;
}
