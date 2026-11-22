// Fahrzeuge mit BLE-Beacons (Issue #177) in der nativen Einsatz-App, Gegenstück zu vehDraw/vehPop/vehFold/vehLoadDlg in web/index.html und common.js.
// st.veh vom Server ({kinds, list, hints, stale_min, can_link}, null = für diese Person nicht sichtbar). Ketten (Verbund: Anhänger am Zugfahrzeug,
// Boot auf dem Anhänger) als ein Marker beim obersten Fahrzeug. Verladen/abladen dürfen alle Teilnehmenden und die Einsatzleitung, ohne Einsatz die Verwaltung.
import 'dart:ui' as ui;
import 'package:flutter/material.dart' hide Text;
import 'package:latlong2/latlong.dart';

import 'ctx.dart';
import 'store.dart';
import 'util.dart';
import '../i18n.dart';

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

/// Batteriestand des Beacons (Issue #224): «Batterie 80 %», schwach ab vehBattLow, Stand älter als 1 Tag mit Zeitpunkt; null = unbekannt
const vehBattLow = 20;
String? vehBatt(IncStore s, J v) {
  final p = ji(v['batt']);
  if (p == null) return null;
  final at = jn(v['batt_at']);
  final mv = ji(v['batt_mv']);
  return '${p <= vehBattLow ? '⚠ Batterie schwach' : 'Batterie'} $p %${mv != null ? ' (${(mv / 1000).toStringAsFixed(2)} V)' : ''}${at != null && s.now - at > 86400 ? ' (${vehAgo(at, s.now)})' : ''}';
}

String vehInfo(IncStore s, J v) {
  final c = vehById(s, v['carrier_id']);
  return [
    js(vehKind(s, v)['name']),
    if (c != null) '${_hang(s, c) ? 'an' : 'auf'} «${js(c['name'])}»',
    '${jb(v['inc']) ? 'gesehen' : 'zuletzt gesehen'} ${vehAgo(jn(v['seen_at']), s.now)}',
    ?vehBatt(s, v),
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
    // Gesamtzahl über alle Arten (room_all), z. B. eine Anhängerkupplung für zwei Anhängerarten
    final all = jm(c['room_all']), tn = ji(all['n']) ?? 0, tmax = ji(all['max']);
    final full = (max != null && n >= max) || (tmax != null && tn >= tmax);
    o.add((c: c, n: n, max: max, full: full && ji(v['carrier_id']) != ji(c['id'])));
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

/// Marker einer Kette: je Fahrzeug ein eigenes Feld (Farbe, Symbol, Kürzel), mit Spitze unten über dem Standort
/// (wie vehIconHtml im Web), damit Personen und Standorte am selben Ort ihn nicht verdecken; blass ohne neuere Sichtung
Widget vehChip(IncStore s, J root, List<J> kids) {
  Widget seg(J v) {
    final col = js(v['color']).isEmpty ? const Color(0xFF335C67) : hexColor(v['color']);
    return Container(
        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
        decoration: BoxDecoration(color: col, borderRadius: BorderRadius.circular(6)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Text(js(vehKind(s, v)['sym']), style: const TextStyle(fontSize: 15)),
          if (js(v['abbr']).isNotEmpty) Text(' ${js(v['abbr'])}', style: TextStyle(color: fgOn(col), fontSize: 11, fontWeight: FontWeight.bold)),
        ]));
  }

  final all = [root, ...kids];
  return Opacity(
      opacity: vehStale(s, root) ? .5 : 1,
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        for (var i = 0; i < all.length; i++) ...[if (i > 0) const SizedBox(width: 2), seg(all[i])],
      ]));
}

/// Ketten, die getrennt am selben Ort stehen (höchstens [vehNearM]), teilen sich einen Marker (wie vehGroups im Web)
const vehNearM = 25.0;
List<List<J>> vehGroups(IncStore s) {
  const d = Distance();
  final gs = <List<J>>[];
  for (final r in vehRoots(s).where((r) => llOf(r) != null)) {
    final g = gs.where((g) => d(llOf(g.first)!, llOf(r)!) <= vehNearM).firstOrNull;
    g != null ? g.add(r) : gs.add([r]);
  }
  return gs;
}

/// Marker einer Gruppe: je Kette eine Zeile untereinander (mehrere gestrichelt umrandet), mit Spitze unten am Standort
Widget vehGroupChip(IncStore s, List<J> g) {
  final multi = g.length > 1;
  return Column(mainAxisSize: MainAxisSize.min, children: [
    Container(
        padding: EdgeInsets.all(multi ? 2 : 0),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8), border: Border.all(color: Colors.white, width: 2), boxShadow: const [BoxShadow(color: Colors.black54, blurRadius: 3)]),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          for (var i = 0; i < g.length; i++) ...[
            if (i > 0) const SizedBox(height: 4),
            multi
                ? Container(
                    padding: const EdgeInsets.all(1),
                    decoration: BoxDecoration(border: Border.all(color: const Color(0xFF99AAAA)), borderRadius: BorderRadius.circular(7)),
                    child: vehChip(s, g[i], vehKids(s, ji(g[i]['id']))))
                : vehChip(s, g[i], vehKids(s, ji(g[i]['id']))),
          ],
        ])),
    CustomPaint(size: const Size(12, 7), painter: _VehTip()),
  ]);
}

class _VehTip extends CustomPainter {
  @override
  void paint(Canvas c, Size z) => c.drawPath(ui.Path()..moveTo(0, 0)..lineTo(z.width, 0)..lineTo(z.width / 2, z.height)..close(), Paint()..color = Colors.white);
  @override
  bool shouldRepaint(covariant CustomPainter o) => false;
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
  final scan = vehScanBtn(context, s);
  if (scan != null) out.add(Padding(padding: const EdgeInsets.only(top: 4, bottom: 4), child: Align(alignment: Alignment.centerLeft, child: scan)));
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
                    IconButton(
                        tooltip: trn('Auf der Karte zentrieren'),
                        icon: const Icon(Icons.my_location, size: 20),
                        visualDensity: VisualDensity.compact,
                        onPressed: () {
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

/// Knopf «Fahrzeuge jetzt scannen» (Fenster «Einsatzkräfte» und Profil); nicht im Demo und ohne Fahrzeuge mit Beacon
Widget? vehScanBtn(BuildContext context, IncStore s) {
  if (s.demo || s.me?['beacon_cfg'] is! Map) return null;
  return OutlinedButton.icon(onPressed: () => vehScanDlg(context, s), icon: const Icon(Icons.bluetooth_searching, size: 18), label: const Text('Fahrzeuge jetzt scannen'));
}

String _sig(int r) => '${r >= -60 ? 'stark' : r >= -75 ? 'mittel' : 'schwach'} ($r dBm)';
String _bt(int? p) => p == null ? '' : ' · ${p <= vehBattLow ? '⚠ Batterie schwach' : 'Batterie'} $p %';

/// Ergänzt fehlende Namen (ältere Server ohne name in beacon_cfg): aus der Lage, mit cfg_map aus der Verwaltung, sonst «Fahrzeug N»
Future<void> _scanNames(IncStore s, List<J> found) async {
  final miss = found.where((b) => b['id'] != null && js(b['name']).isEmpty).toList();
  if (miss.isEmpty) return;
  final nm = <int, String>{for (final v in vehList(s)) if (ji(v['id']) != null && js(v['name']).isNotEmpty) ji(v['id'])!: js(v['name'])};
  if (miss.any((b) => !nm.containsKey(ji(b['id']))) && s.can('cfg_map')) {
    try {
      for (final v in jl((await s.api.call('vehicles'))['vehicles'])) {
        if (ji(v['id']) != null && js(v['name']).isNotEmpty) nm[ji(v['id'])!] = js(v['name']);
      }
    } catch (_) {}
  }
  for (final b in miss) {
    b['name'] = nm[ji(b['id'])] ?? 'Fahrzeug ${b['id']}';
  }
}

/// Sucht etwa 6 s nach den Beacons, meldet sie (IncStore.scanVehicles) und zeigt, was gefunden und gemeldet wurde
Future<void> vehScanDlg(BuildContext context, IncStore s) async {
  ({List<J> found, String msg, bool ok})? r;
  var started = false;
  await showDialog<void>(
      context: context,
      builder: (dc) => StatefulBuilder(builder: (dc, set) {
            if (!started) {
              started = true;
              s.scanVehicles().then((x) async {
                await _scanNames(s, x.found);
                if (dc.mounted) set(() => r = x);
              });
            }
            final x = r;
            return AlertDialog(
                title: const Text('Fahrzeuge scannen'),
                content: SizedBox(
                    width: 420,
                    child: x == null
                        ? const Column(mainAxisSize: MainAxisSize.min, children: [
                            Padding(padding: EdgeInsets.all(16), child: CircularProgressIndicator()),
                            Mut('Die App sucht einige Sekunden nach den Beacons der Fahrzeuge.'),
                          ])
                        : ListView(shrinkWrap: true, children: [
                            for (final b in x.found.where((b) => b['id'] != null))
                              ListTile(
                                  dense: true,
                                  contentPadding: EdgeInsets.zero,
                                  leading: Icon(Icons.directions_car, color: (ji(b['batt']) ?? 100) <= vehBattLow ? const Color(0xFFE57373) : null),
                                  title: Text(js(b['name'])),
                                  subtitle: Text('Signal ${_sig(ji(b['rssi']) ?? -127)}${_bt(ji(b['batt']))}')),
                            Padding(padding: const EdgeInsets.only(top: 8), child: Text(x.msg, style: TextStyle(color: x.ok ? null : const Color(0xFFE57373)))),
                            if (x.found.any((b) => b['id'] == null)) ...[
                              const Padding(padding: EdgeInsets.only(top: 12), child: Text('Weitere Beacons in der Nähe', style: TextStyle(fontWeight: FontWeight.w600))),
                              const Mut('Senden den Batteriestand, gehören aber zu keinem Fahrzeug. Die MAC-Adresse lässt sich beim Fahrzeug in der Verwaltung eintragen.', size: 12),
                              for (final b in x.found.where((b) => b['id'] == null))
                                ListTile(
                                    dense: true,
                                    contentPadding: EdgeInsets.zero,
                                    leading: const Icon(Icons.bluetooth),
                                    title: Text(js(b['mac'])),
                                    subtitle: Text('Signal ${_sig(ji(b['rssi']) ?? -127)}${_bt(ji(b['batt']))}')),
                            ],
                          ])),
                actions: [
                  if (x != null)
                    TextButton(
                        onPressed: () => set(() {
                              r = null;
                              started = false;
                            }),
                        child: const Text('Erneut scannen')),
                  TextButton(onPressed: () => Navigator.pop(dc), child: const Text('Schliessen')),
                ]);
          }));
}
