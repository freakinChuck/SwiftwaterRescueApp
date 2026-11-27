// Fliessgeschwindigkeit an den Messstationen (Issue #254, Gegenstück zu FLK, fpSvg, flowPredHtml, flowNote, drawFlow, hyFq, hyFpPop
// und rdAlong in web/index.html): Bezugsstation beim PLS mit Kennlinie v̄ = a·Q^m (st.flow_pred), Abfluss stündlich −12 h … +96 h,
// Driftspanne = f_lo … f_hi × Geschwindigkeit an der Oberfläche (v̄ ÷ surf), obere Grenze plus Zusatzmarge der Hauptströmung (mg %), Abschnitte bis Ende Suchgebiet, relevante Abweichung (warn).
// Nur Anzeige: «Übernehmen» setzt die Fliessgeschwindigkeit über incident/flow (mit src fürs Log), sonst ändert die App nichts.
import 'dart:math' as math;

import 'package:flutter/material.dart' hide Text;
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart' hide Path;

import 'ctx.dart';
import 'store.dart';
import 'util.dart';
import '../i18n.dart';

/// Arten der Abschnitte: Bezeichnung, Farbe
const flk = <String, (String, Color)>{
  'stau': ('Stau', Color(0xFF1D7FD1)),
  'schwellen': ('Schwellen', Color(0xFFFF8A3D)),
  'eng': ('Engstelle', Color(0xFFC34FFF)),
  'frei': ('frei', Color(0xFF35C27A)),
};
(String, Color) flkOf(String k) => flk[k] ?? flk['frei']!;

String f1(double v) => v.toStringAsFixed(1);
/// Einheit der Anzeige (IncStore.spdU, Issue #259), gesetzt von den Einstiegspunkten; Diagramm in km/h nur bei «km/h», sonst m/s
String fpU = 'ms';
double get fpK => fpU == 'kmh' ? 3.6 : 1;
String get fpUL => fpU == 'kmh' ? 'km/h' : 'm/s';

/// Spanne in m/s → Text in der Einheit (fmtSpdR in common.js)
String fmtSpdR(double lo, double hi, String u) {
  final a = '${spdR(lo, 1)}–${spdR(hi, 1)} m/s', b = '${spdR(lo * 3.6, 1)}–${spdR(hi * 3.6, 1)} km/h';
  return u == 'kmh' ? b : u == 'both' ? '$a ($b)' : a;
}

String fpR(List<double> d) => fmtSpdR(d[0], d[1], fpU);
String fpKm(num m) => '${(m / 1000).toStringAsFixed(1)} km';
String fpMin(num a, num b) => a == b ? '$a min' : '$a–$b min';
String fpName(J p) => '${js(p['water']).isNotEmpty ? '${js(p['water'])} – ' : ''}${js(p['name'])}';

double _v(J p, double q) => (jd(p['a']) ?? 0) * math.pow(math.max(q, 0), jd(p['m']) ?? 0.5) / (jd(p['surf']) ?? 0.85);

/// Driftspanne [tief, hoch, Mitte] in m/s
List<double> fpDrift(J p, double q, [double? lo, double? hi]) {
  final fl = jd(p['f_lo']) ?? .8, fh = jd(p['f_hi']) ?? 1.0;
  final mg = jd(p['mg']) ?? 0;
  return [fl * _v(p, lo ?? q), fh * _v(p, hi ?? q) * (1 + mg / 100), (fl + fh) / 2 * _v(p, q)];
}

List<List<double?>> _ser(J p) => (p['ser'] is List ? p['ser'] as List : const [])
    .whereType<List>()
    .map((x) => [for (final v in x) jd(v)])
    .where((x) => x.length >= 2 && x[0] != null && x[1] != null)
    .toList();

/// Driftspanne in h Stunden ab dem Stand der Vorhersage (fpD)
List<double>? fpD(J p, int h) {
  final t = (jd(p['at']) ?? 0) + h * 3600;
  List<double?>? b;
  for (final x in _ser(p)) {
    if (x[0]! <= t + 1800) b = x;
  }
  if (b == null) return null;
  final q = b[1]!;
  return fpDrift(p, q, b.length > 2 ? b[2] ?? q : q, b.length > 3 ? b[3] ?? q : q);
}

String fpRel(J p) {
  final km = ((jd(p['dist']) ?? 0) / 1000).toStringAsFixed(1);
  return js(p['rel']) == 'luft' ? '$km km vom PLS (Luftlinie)' : '$km km ${js(p['rel'])} des PLS';
}

/// Diagramm der Driftspanne (fpSvg): gemessen weiss, Vorhersage blau mit Spanne, eingestellte Geschwindigkeit gelb gestrichelt
class FlowChart extends StatelessWidget {
  const FlowChart(this.p, this.set, {super.key, this.light = false});
  final J p;
  final double? set;
  final bool light; // heller Grund (Info-Blatt im hellen Design)
  @override
  Widget build(BuildContext context) {
    final at = jd(p['at']) ?? 0;
    final pts = <(double, List<double>)>[];
    for (final x in _ser(p)) {
      final h = (x[0]! - at) / 3600;
      if (h < -12 || h > 96) continue;
      pts.add((h, fpDrift(p, x[1]!, x.length > 2 ? x[2] : null, x.length > 3 ? x[3] : null).map((v) => v * fpK).toList()));
    }
    if (pts.length < 2) return const SizedBox.shrink();
    return Semantics(
        label: trn(fpU == 'kmh' ? 'Driftspanne in km/h, 12 Stunden zurück bis 4 Tage voraus' : 'Driftspanne in m/s, 12 Stunden zurück bis 4 Tage voraus'),
        child: Container(
            margin: const EdgeInsets.symmetric(vertical: 6),
            decoration: BoxDecoration(color: const Color(0xFF0F2430), borderRadius: BorderRadius.circular(6)),
            child: AspectRatio(aspectRatio: 330 / 150, child: CustomPaint(painter: _FlowPainter(pts, set == null ? null : set! * fpK, [tr('jetzt'), tr('eingestellt')])))));
  }
}

class _FlowPainter extends CustomPainter {
  _FlowPainter(this.pts, this.set, this.lbl);
  final List<(double, List<double>)> pts;
  final double? set;
  final List<String> lbl;

  void _txt(Canvas c, String t, double x, double y, Color col, {String anchor = 'start', double sc = 1}) {
    final tp = TextPainter(text: TextSpan(text: t, style: TextStyle(color: col, fontSize: 10 * sc)), textDirection: TextDirection.ltr)..layout();
    final dx = anchor == 'end' ? x - tp.width : anchor == 'middle' ? x - tp.width / 2 : x;
    tp.paint(c, Offset(dx, y - tp.height * .8));
  }

  @override
  void paint(Canvas c, Size sz) {
    final sc = sz.width / 330;
    c.save();
    c.scale(sc);
    double X(double h) => 66 + h * 8 / 3;
    var lo = pts.map((x) => x.$2[0]).reduce(math.min), hi = pts.map((x) => x.$2[1]).reduce(math.max);
    final s = set;
    if (s != null) {
      lo = math.min(lo, s);
      hi = math.max(hi, s);
    }
    var pad = (hi - lo) * .08;
    if (pad == 0) pad = .1;
    lo -= pad;
    hi += pad;
    double Y(double v) => 128 - (v - lo) / (hi - lo) * 118;
    const g = Color(0xFF8FB0BD);
    final st = [.1, .2, .25, .4, .5, 1.0, 2.0, 5.0].firstWhere((x) => (hi - lo) / x <= 4, orElse: () => 5.0);
    final grid = Paint()..color = const Color(0xFF2C4F60);
    for (var v = (lo / st).ceil() * st; v <= hi; v += st) {
      c.drawLine(Offset(34, Y(v)), Offset(322, Y(v)), grid);
      _txt(c, f1(v), 30, Y(v) + 3, g, anchor: 'end');
    }
    for (final (h, t) in [(-12.0, '−12 h'), (0.0, lbl[0]), (24.0, '+1 T'), (48.0, '+2 T'), (72.0, '+3 T'), (96.0, '+4 T')]) {
      _txt(c, t, X(h), 144, g, anchor: 'middle');
    }
    _dash(c, const Offset(66, 10), const Offset(66, 128), Paint()..color = g, 2, 2);
    final fut = pts.where((x) => x.$1 >= 0).toList(), past = pts.where((x) => x.$1 <= 0).toList();
    if (fut.length > 1) {
      final band = Path()..moveTo(X(fut.first.$1), Y(fut.first.$2[1]));
      for (final x in fut.skip(1)) {
        band.lineTo(X(x.$1), Y(x.$2[1]));
      }
      for (final x in fut.reversed) {
        band.lineTo(X(x.$1), Y(x.$2[0]));
      }
      band.close();
      c.drawPath(band, Paint()..color = const Color(0xFF1D7FD1).withValues(alpha: .22));
    }
    Path line(List<(double, List<double>)> a) {
      final p = Path()..moveTo(X(a.first.$1), Y(a.first.$2[2]));
      for (final x in a.skip(1)) {
        p.lineTo(X(x.$1), Y(x.$2[2]));
      }
      return p;
    }

    final stroke = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;
    if (past.length > 1) c.drawPath(line(past), stroke..color = const Color(0xFFEAF3F6));
    if (fut.length > 1) c.drawPath(line(fut), stroke..color = const Color(0xFF5FB8FF));
    if (s != null) {
      const y = Color(0xFFFFD23F);
      _dash(c, Offset(34, Y(s)), Offset(322, Y(s)), Paint()..color = y, 5, 3);
      _txt(c, '${lbl[1]} ${f1(s)} $fpUL', 320, Y(s) - 3, y, anchor: 'end');
    }
    _txt(c, fpUL, 36, 18, g);
    c.restore();
  }

  void _dash(Canvas c, Offset a, Offset b, Paint p, double on, double off) {
    final d = (b - a).distance, u = (b - a) / d;
    for (var t = 0.0; t < d; t += on + off) {
      c.drawLine(a + u * t, a + u * math.min(t + on, d), p);
    }
  }

  @override
  bool shouldRepaint(_FlowPainter o) => o.set != set || o.pts.length != pts.length || (pts.isNotEmpty && o.pts.last.$2[2] != pts.last.$2[2]);
}

const _yel = Color(0xFFFFD23F);

Widget _box({required Widget child, Color edge = const Color(0xFF5FB8FF), Color bg = const Color(0xFF0C1C25)}) => Container(
    margin: const EdgeInsets.symmetric(vertical: 5),
    padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
    decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(6), border: Border(left: BorderSide(color: edge, width: 4))),
    child: child);

bool _secOpen = true; // Abschnitte aufgeklappt (sw_flsec), für die Laufzeit der App

/// Inhalt der Karte «Fliessgeschwindigkeit» unter dem Eingabefeld (flowPredHtml)
List<Widget> flowPredWidgets(BuildContext c, IncStore s, void Function(double v, String src) take) {
  fpU = s.spdU;
  final p = s.flowPred, i = s.inc;
  if (p == null || i == null) return const [];
  final set = jd(i['flow_ms']), d0 = fpD(p, 0) ?? fpDrift(p, jd(p['q']) ?? 0), nm = fpName(p);
  final w = p['warn'] is Map ? jm(p['warn']) : null;
  const mutC = Color(0xFF8FB0BD);
  Widget tabCell(int h, String l) {
    final d = fpD(p, h);
    if (d == null) return const Expanded(child: SizedBox.shrink());
    final up = set != null && set > 0 && (d[2] - set).abs() >= set * .2;
    return Expanded(
        child: Container(
            margin: const EdgeInsets.symmetric(horizontal: 2),
            padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 2),
            decoration: BoxDecoration(color: Theme.of(c).colorScheme.surfaceContainerHigh, borderRadius: BorderRadius.circular(5)),
            child: Column(children: [
              Text(l, style: TextStyle(fontSize: 11, color: up ? _yel : null), textAlign: TextAlign.center),
              Text(fpR(d), style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: up ? _yel : null)),
            ])));
  }

  final fc = p['fc_at'] != null ? 'BAFU-Vorhersage (Median, Spanne Min.–Max.) · Stand ${fmtDT(jn(p['fc_at']))}' : 'Abfluss bleibt wie jetzt (keine BAFU-Vorhersage)';
  final rs = jl(p['reaches']);
  return [
    if (w != null && set != null)
      _box(
          edge: _yel,
          bg: const Color(0xFF2A2510),
          child: Text.rich(TextSpan(style: const TextStyle(fontSize: 13), children: [
            TextSpan(text: '⚠ In ${w['h']} h deutlich ${jb(w['up']) ? 'schneller' : 'langsamer'}: ', style: const TextStyle(fontWeight: FontWeight.bold)),
            TextSpan(
                text: '${fpR([jd(w['d0']) ?? 0, jd(w['d1']) ?? 0])} statt eingestellt ${s.spd(set)} (Abfluss ${js(p['name'])} '
                    '${(jd(w['q']) ?? 0) > (jd(w['q0']) ?? 0) ? 'steigt' : 'sinkt'}${jb(w['fc']) ? ' gemäss BAFU-Vorhersage' : ''} von ${w['q0']} auf ${w['q']} m³/s).'),
          ]))),
    _box(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Vorschlag jetzt', style: TextStyle(fontSize: 12, color: mutC)),
          Text(fpR(d0), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          Text('Abfluss ${(jd(p['q']) ?? 0).round()} m³/s', style: const TextStyle(fontSize: 12, color: mutC)),
        ])),
        if (jb(s.st?['can_flow']))
          FilledButton(
              style: FilledButton.styleFrom(visualDensity: VisualDensity.compact),
              onPressed: () => take(double.parse(f1(d0[2])), 'Vorschlag $nm'),
              child: Text('Übernehmen ${s.spd(double.parse(f1(d0[2])))}')),
      ]),
      FlowChart(p, set),
      Row(children: [tabCell(3, 'in 3 h'), tabCell(12, 'in 12 h'), tabCell(24, 'in 1 Tag'), tabCell(48, 'in 2 Tagen')]),
      const SizedBox(height: 4),
      Text('$nm (${js(p['station'])}), ${fpRel(p)} · $fc${(jd(p['mg']) ?? 0) > 0 ? ' · obere Grenze mit +${(jd(p['mg'])!).round()} % für die Hauptströmung' : ''}${js(p['src']) == 'strickler' ? ' · ohne Querprofil, Schätzung ±30–50 %' : ''}',
          style: const TextStyle(fontSize: 11, color: mutC)),
    ])),
    if (rs.isNotEmpty)
      _box(
          edge: mutC,
          child: StatefulBuilder(
              builder: (c, set2) => Theme(
                  data: Theme.of(c).copyWith(dividerColor: Colors.transparent),
                  child: ExpansionTile(
                      initiallyExpanded: _secOpen,
                      onExpansionChanged: (o) => _secOpen = o,
                      tilePadding: EdgeInsets.zero,
                      childrenPadding: EdgeInsets.zero,
                      dense: true,
                      title: const Text.rich(TextSpan(children: [
                        TextSpan(text: 'Abschnitte bis Ende Suchgebiet', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                        TextSpan(text: ' (Schätzung)', style: TextStyle(fontSize: 12, color: mutC)),
                      ])),
                      children: rs.map((r) => _reach(c, r, s)).toList())))),
  ];
}

Widget _reach(BuildContext c, J r, IncStore s) {
  final k = flkOf(js(r['kind']));
  return Container(
      padding: const EdgeInsets.symmetric(vertical: 5),
      decoration: BoxDecoration(border: Border(top: BorderSide(color: Theme.of(c).dividerColor))),
      child: Row(children: [
        Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 5, children: [
            Text(js(r['name']), style: const TextStyle(fontSize: 13)),
            Container(
                padding: const EdgeInsets.symmetric(horizontal: 6),
                decoration: BoxDecoration(color: k.$2.withValues(alpha: .25), borderRadius: BorderRadius.circular(9), border: Border.all(color: k.$2)),
                child: Text(k.$1, style: const TextStyle(fontSize: 11))),
          ]),
          Mut('${fpKm(jd(r['len_m']) ?? 0)} · Treibzeit ${fpMin(ji(r['t0']) ?? 0, ji(r['t1']) ?? 0)}${r['va'] != null ? ' · Suchgebiet ${s.spd(r['va'])}' : ''}', size: 12),
        ])),
        Text(fpR([jd(r['v0']) ?? 0, jd(r['v1']) ?? 0]), style: const TextStyle(fontWeight: FontWeight.bold)),
      ]));
}

/// Kartenebene «Fliessgeschwindigkeit je Abschnitt» (drawFlow, Kategorie flow): Abschnitte in der Farbe der Art mit Beschriftung
List<Widget> flowLayers(IncStore s) {
  final p = s.flowPred;
  if (p == null || !s.feat('hydro') || !s.vis('flow')) return const [];
  fpU = s.spdU;
  final ls = <Polyline>[], mk = <Marker>[];
  for (final r in jl(p['reaches'])) {
    final pts = (r['pts'] is List ? r['pts'] as List : const []).whereType<List>().where((x) => x.length >= 2).map((x) => LatLng(jd(x[0])!, jd(x[1])!)).toList();
    if (pts.length < 2) continue;
    final k = flkOf(js(r['kind']));
    ls.add(Polyline(points: pts, color: k.$2.withValues(alpha: .95), strokeWidth: 7));
    mk.add(Marker(
        point: pts[pts.length ~/ 2],
        width: 150,
        height: 22,
        alignment: Alignment.centerRight,
        child: IgnorePointer(
            child: Align(
                alignment: Alignment.centerLeft,
                child: Container(
                    margin: const EdgeInsets.only(left: 8),
                    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(4), boxShadow: const [BoxShadow(color: Colors.black38, blurRadius: 3)]),
                    child: Text('${k.$1} ${fpR([jd(r['v0']) ?? 0, jd(r['v1']) ?? 0])}', style: const TextStyle(fontSize: 11, color: Colors.black, fontWeight: FontWeight.w600)))))));
  }
  if (ls.isEmpty) return const [];
  return [PolylineLayer(polylines: ls), MarkerLayer(markers: mk)];
}

/// Geschwindigkeit an der Station als Zeile (hyFq) für Menükarte und Fenster «Messstationen»
Widget? hyFq(IncStore s, String id) {
  final f = s.hyFlow[id];
  if (f == null) {
    return s.hyFlowOk ? const Mut('kein Querprofil, keine Geschwindigkeit', size: 12) : null;
  }
  fpU = s.spdU;
  final d0 = jd(f['d0']) ?? 0, d1 = jd(f['d1']) ?? 0;
  final h = f['h12'] is List ? (f['h12'] as List).map((x) => jd(x) ?? 0).toList() : null;
  final up = h != null && h.length > 1 && (h[0] + h[1]) / 2 > (d0 + d1) / 2 * 1.1;
  return Padding(
      padding: const EdgeInsets.only(top: 2),
      child: Text.rich(TextSpan(style: const TextStyle(fontSize: 12), children: [
        const TextSpan(text: '🌊 '),
        TextSpan(text: fpR([d0, d1]), style: const TextStyle(fontWeight: FontWeight.bold)),
        if (h != null && h.length > 1) ...[
          const TextSpan(text: ' · in 12 h ', style: TextStyle(color: Color(0xFF8FB0BD))),
          TextSpan(text: '${fpR(h)}${up ? ' ↑' : ''}', style: TextStyle(color: up ? _yel : const Color(0xFF8FB0BD))),
        ],
      ])));
}

/// Zeile im Info-Blatt der Station (hyFq mit pop)
String? hyFqLine(IncStore s, String id) {
  final f = s.hyFlow[id];
  if (f == null) return null;
  final h = f['h12'] is List ? (f['h12'] as List).map((x) => jd(x) ?? 0).toList() : null;
  final src = js(f['src']) == 'prof' ? 'Querprofil${js(f['at']).isNotEmpty ? ' ${js(f['at'])}' : ''}' : js(f['src']) == 'manual' ? 'von Hand' : 'ohne Querprofil';
  fpU = s.spdU;
  return 'Fliessgeschwindigkeit: ${fpR([jd(f['d0']) ?? 0, jd(f['d1']) ?? 0])} · Mittel ${s.spd(f['v'])} ($src)'
      '${h != null && h.length > 1 ? ' · in 12 h ${fpR(h)}' : ''}';
}

/// Zusatz im Info-Blatt der Station (hyFpPop): Diagramm bei der Bezugsstation, «Als Fliessgeschwindigkeit übernehmen»
List<Widget> hyFpExtra(BuildContext c, IncStore s, String id, String name, void Function(double v, String src) take) {
  final p = s.flowPred, f = s.hyFlow[id];
  final ip = p != null && js(p['station']) == id;
  return [
    if (ip) ...[
      FlowChart(p, jd(s.inc?['flow_ms'])),
      Mut('${p['fc_at'] != null ? 'BAFU-Vorhersage ${fmtDT(jn(p['fc_at']))} · ' : ''}Schätzung für treibende Personen', size: 11),
    ],
    if (s.inc != null && jb(s.st?['can_flow']) && f != null)
      Padding(
          padding: const EdgeInsets.only(top: 6),
          child: OutlinedButton(
              onPressed: () {
                Navigator.pop(c);
                take(double.parse((((jd(f['d0']) ?? 0) + (jd(f['d1']) ?? 0)) / 2).toStringAsFixed(1)), 'Messstation $name');
              },
              child: const Text('Als Fliessgeschwindigkeit übernehmen'))),
  ];
}

/// Flussdistanz (rdAlong): Treibzeit nach Abschnitten [Gesamt, Aufteilung je Art]
(String, String)? rdAlong(List<J>? rs, String Function(double s) durS) {
  if (rs == null || rs.isEmpty) return null;
  var t0 = 0, t1 = 0;
  final by = <String, List<num>>{};
  for (final r in rs) {
    final k = flk.containsKey(js(r['kind'])) ? js(r['kind']) : 'frei';
    final b = by.putIfAbsent(k, () => [0, 0, 0]);
    b[0] += jd(r['len_m']) ?? 0;
    b[1] += ji(r['t0']) ?? 0;
    b[2] += ji(r['t1']) ?? 0;
    t0 += ji(r['t0']) ?? 0;
    t1 += ji(r['t1']) ?? 0;
  }
  return (
    t0 == t1 ? durS(t0 * 60.0) : '${durS(t0 * 60.0)} – ${durS(t1 * 60.0)}',
    by.entries.map((e) => '■ ${flk[e.key]!.$1} ${fpKm(e.value[0])}: ${fpMin(e.value[1], e.value[2])}').join(' · '),
  );
}

// ---------- Geschwindigkeit je Abschnitt und Zuschlag fürs Suchgebiet (flow_area, flowAreaHtml/flowAreaBind in index.html) ----------
// Die eingegebene Fliessgeschwindigkeit ist die Grundgeschwindigkeit (gilt ausserhalb der Abschnitte, ohne Abschnitte überall); je Abschnitt
// Grundgeschwindigkeit × Faktor (abschaltbar), von Hand übersteuerbar; «An einem Punkt gemessen» rechnet die Grundgeschwindigkeit zurück.
bool _faOpen = false;

List<J> faReaches(IncStore s) {
  final a = s.st?['flow_area'];
  return a is Map && a['reaches'] is List ? (a['reaches'] as List).whereType<Map>().map((x) => x.cast<String, dynamic>()).toList() : const [];
}

/// Eingabe einer Geschwindigkeit in der Einheit der Person; null = abgebrochen, -1 = leer
Future<double?> _spdDlg(BuildContext c, IncStore s, String title, String text, {double? v, String ok = 'Speichern'}) async {
  final r = await inputDlg(c, title,
      label: '${trn('Fliessgeschwindigkeit')} (${spdIn(s.spdU)})', value: v == null ? '' : spdTo(v, s.spdU), hint: text, type: const TextInputType.numberWithOptions(decimal: true), ok: ok);
  if (r == null) return null;
  if (r.trim().isEmpty) return -1;
  return spdFrom(r, s.spdU);
}

List<Widget> flowAreaWidgets(BuildContext c, IncStore s, MapCtl map) {
  final a0 = s.st?['flow_area'];
  if (a0 is! Map) return const [];
  final a = a0.cast<String, dynamic>(), rs = faReaches(s), pc = jd(a['pct']) ?? 0, pt = a['pt'] is Map ? (a['pt'] as Map).cast<String, dynamic>() : null;
  final auto = jb(a['auto']), area = s.feat('area'), nHand = rs.where((r) => jb(r['o'])).length;
  Future<void> post(J b, [String? ok]) async => s.act('incident/flow/area', b, ok);
  String fac(dynamic f) => (jd(f) ?? 1).toStringAsFixed(2);
  final out = <Widget>[
    const SizedBox(height: 4),
    Mut(
        rs.isNotEmpty
            ? (auto
                ? 'Gilt ausserhalb der Abschnitte; in den Abschnitten mit ihrem Faktor, sofern nicht von Hand gesetzt.'
                : 'Gilt ausserhalb der Abschnitte; in den Abschnitten ohne Faktor, sofern nicht von Hand gesetzt.')
            : 'Es sind keine Abschnitte eingerichtet: die Geschwindigkeit gilt für den ganzen Flusslauf.',
        size: 12),
    if (pt != null)
      Mut(
          '${tr('Gemessen')} ${fmtT(jn(pt['at']))}: ${s.spd(pt['v'])}${js(pt['rname']).isNotEmpty ? ' ${tr('im Abschnitt')} «${js(pt['rname'])}» (${tr('Faktor')} ${fac(pt['fac'])})' : ''}${s.fcs(pt['lat'], pt['lon'])}',
          size: 12),
    Wrap(spacing: 8, runSpacing: 4, children: [
      OutlinedButton(
          onPressed: () async {
            final p = await map.pickPoint('Punkt der Messung antippen');
            if (p == null || !map.ctx.mounted) return;
            final v = await _spdDlg(map.ctx, s, 'Gemessene Fliessgeschwindigkeit',
                rs.isNotEmpty ? 'Daraus wird mit dem Faktor des Abschnitts die Grundgeschwindigkeit berechnet.' : 'Sie gilt für den ganzen Flusslauf.',
                ok: 'Übernehmen');
            if (v == null || v <= 0) return;
            await post({
              'pt': {'lat': p.latitude, 'lon': p.longitude, 'v': v}
            }, 'Grundgeschwindigkeit gesetzt');
          },
          child: const Text('An einem Punkt gemessen …')),
      if (area)
        OutlinedButton(
            onPressed: () async {
              final d = jd(a['pct_def']) ?? 0;
              final r = await inputDlg(c, 'Zuschlag Suchgebiet',
                  label: 'Zuschlag in %',
                  value: jb(a['pct_own']) ? '${pc.round()}' : '',
                  hint: '${tr('Leer = Vorgabe der Verwaltung')} (${d > 0 ? '+' : ''}${d.round()} %)',
                  type: const TextInputType.numberWithOptions(signed: true));
              if (r == null) return;
              final t = r.trim();
              await post({'pct': t.isEmpty ? '' : (double.tryParse(t.replaceAll(',', '.')) ?? 0)}, 'Gespeichert');
            },
            child: Text('${tr('Zuschlag Suchgebiet:')} ${pc != 0 ? '${pc > 0 ? '+' : ''}${pc.round()} %' : tr('keiner')}${jb(a['pct_own']) ? '' : ' (${tr('Vorgabe')})'}')),
    ]),
  ];
  if (rs.isEmpty) return out;
  out.add(ExpansionTile(
      tilePadding: EdgeInsets.zero,
      initiallyExpanded: _faOpen,
      onExpansionChanged: (v) => _faOpen = v,
      title: Text('${tr('Geschwindigkeit je Abschnitt')} (${rs.length}${nHand > 0 ? ', $nHand ${tr('von Hand')}' : ''})',
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
      children: [
        CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            dense: true,
            value: auto,
            title: Text('${tr('Faktoren der Abschnitte anwenden')}${jb(a['auto_own']) ? '' : ' (${tr('Vorgabe')})'}'),
            onChanged: (v) => post({'auto': v == true ? 1 : 0})),
        for (final r in rs)
          Row(children: [
            Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(js(r['name'])),
              Mut(
                  jb(r['o'])
                      ? 'von Hand'
                      : auto
                          ? '${tr('Faktor')} ${fac(r['fac'])}'
                          : '${tr('Grundgeschwindigkeit')} (${tr('Faktor')} ${fac(r['fac'])} ${tr('nicht angewendet')})',
                  size: 12),
            ])),
            Text(r['v'] != null ? s.spd(r['v']) : '–', style: const TextStyle(fontWeight: FontWeight.bold)),
            IconButton(
                tooltip: trn('Geschwindigkeit im Abschnitt setzen'),
                icon: const Icon(Icons.edit, size: 18),
                onPressed: () async {
                  final v = await _spdDlg(c, s, 'Geschwindigkeit im Abschnitt', 'Gilt statt Grundgeschwindigkeit × Faktor. Leer = zurücknehmen.',
                      v: jb(r['o']) ? jd(r['v']) : null);
                  if (v == null) return;
                  await post({
                    'ovr': {'${r['id']}': v < 0 ? null : v}
                  }, 'Gespeichert');
                }),
            if (jb(r['o']))
              IconButton(
                  tooltip: trn('Zurücknehmen'),
                  icon: const Icon(Icons.undo, size: 18),
                  onPressed: () => post({
                        'ovr': {'${r['id']}': null}
                      })),
          ]),
        if (nHand > 0)
          Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                  onPressed: () async {
                    if (!await confirmDlg(c, 'Die Abschnitte gelten danach wieder mit Grundgeschwindigkeit und Faktor.',
                        title: 'Alle von Hand gesetzten Geschwindigkeiten zurücknehmen?', ok: 'Zurücknehmen')) {
                      return;
                    }
                    await post({'ovr_clear': 1}, 'Zurückgenommen');
                  },
                  child: const Text('Alle von Hand gesetzten zurücknehmen'))),
      ]));
  return out;
}
