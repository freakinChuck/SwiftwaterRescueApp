// Werkzeug «Flussdistanz» der nativen Einsatz-App (Gegenstück zu rdOpen, rdGraph, rdSnap, rdDijkstra, rdPath und rdRender in web/index.html):
// A und B auf der Karte antippen, eigenes Gewässernetz über die Route overpass (bbox, Rechteck um beide Punkte), Punkte aufs nächste Gewässer,
// kürzester Weg zuerst in Fliessrichtung (OSM-Wege zeigen flussabwärts), sonst umgekehrt, sonst ohne Richtung. Der Server speichert nichts.
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'area.dart';
import 'ctx.dart';
import 'store.dart';
import 'util.dart';

/// maximale Luftlinie zwischen den Punkten (Grösse der Overpass-Abfrage)
const rdMaxM = 50000.0;

/// Distanz als «850 m», «2.35 km», «12.4 km» (kmT)
String kmT(double m) => m < 1000 ? '${m.round()} m' : '${(m / 1000).toStringAsFixed(m < 10000 ? 2 : 1)} km';

/// Dauer als «< 1 min», «25 min», «1 h 05 min» (durS)
String durS(double s) {
  if (!s.isFinite) return '–';
  final m = (s / 60).round();
  return m < 1 ? '< 1 min' : m < 60 ? '$m min' : '${m ~/ 60} h ${(m % 60).toString().padLeft(2, '0')} min';
}

class _Node {
  _Node(this.lat, this.lon);
  final double lat, lon;
  final adj = <(int, double, bool)>[]; // Nachbar, Meter, in Fliessrichtung
}

/// Lotpunkt auf einem Abschnitt u → v: du/dv = Meter bis zu den Enden, e = Abstand zum gewählten Punkt
class _Snap {
  _Snap(this.e, this.u, this.v, this.du, this.dv, this.ll);
  final double e, du, dv;
  final int u, v;
  final LatLng ll;
}

/// Gewässernetz als Graph, mit dem abgedeckten Rechteck [s, w, n, e]
class _Graph {
  _Graph(this.bb, this.g);
  final List<double> bb;
  final Map<int, _Node> g;
}

/// Zustand einer Messung; step: a | b | load | done (idle = noch nicht gestartet)
class RdState extends ChangeNotifier {
  LatLng? a, b, snapA, snapB;
  List<LatLng> path = [];
  bool busy = false;
  String? err;
  String step = 'idle';
  double air = 0, dist = 0;
  String dir = '';
  bool down = false;
  int _run = 0;
  _Graph? _net; // zuletzt geladenes Netz, wiederverwendet, solange es beide Punkte abdeckt

  bool get done => step == 'done' && err == null && path.isNotEmpty;

  void reset() {
    _run++;
    a = b = snapA = snapB = null;
    path = [];
    busy = false;
    err = null;
    step = 'idle';
    air = dist = 0;
    dir = '';
    down = false;
    notifyListeners();
  }

  void _set(VoidCallback f) {
    f();
    notifyListeners();
  }

  /// A und B wählen lassen und messen
  Future<void> measure(IncStore s, MapCtl map) async {
    reset();
    final run = _run;
    _set(() => step = 'a');
    final pa = await map.pickPoint('Flussdistanz: Startpunkt A auf der Karte antippen');
    if (run != _run) return;
    if (pa == null) return reset();
    _set(() => (a = pa, step = 'b'));
    final pb = await map.pickPoint('Flussdistanz: Zielpunkt B auf der Karte antippen');
    if (run != _run) return;
    if (pb == null) return reset();
    _set(() => b = pb);
    await _calc(s, map, run);
  }

  Future<void> _calc(IncStore s, MapCtl map, int run) async {
    final pa = a!, pb = b!;
    air = geoDist(pa, pb);
    if (air > rdMaxM) {
      return _set(() => (err = 'Die Punkte liegen ${kmT(air)} auseinander (Luftlinie), möglich sind höchstens ${(rdMaxM / 1000).round()} km.', step = 'done'));
    }
    _set(() => (step = 'load', busy = true));
    try {
      final g = await _graph(s, pa, pb);
      if (run != _run) return; // inzwischen geschlossen oder neu gestartet
      final sa = _snap(g, pa), sb = _snap(g, pb);
      if (sa == null || sa.e > snapM) throw _RdErr('Kein Gewässer im Umkreis von ${snapM.round()} m um Punkt A.');
      if (sb == null || sb.e > snapM) throw _RdErr('Kein Gewässer im Umkreis von ${snapM.round()} m um Punkt B.');
      // zuerst in Fliessrichtung (A → B oder B → A), sonst ohne Richtung (z. B. über einen Zusammenfluss)
      var r = _path(g, sa, sb, true), d = 'B liegt flussabwärts von A';
      if (r == null) {
        r = _path(g, sb, sa, true);
        if (r != null) {
          r = (d: r.d, pts: r.pts.reversed.toList());
          d = 'B liegt flussaufwärts von A';
        }
      }
      if (r == null) {
        r = _path(g, sa, sb, false);
        d = 'Verbindung nicht in einer Fliessrichtung (z. B. über einen Zusammenfluss)';
      }
      if (r == null) throw _RdErr('Zwischen A und B gibt es keine Verbindung über das Gewässernetz.');
      snapA = sa.ll;
      snapB = sb.ll;
      path = r.pts;
      dist = r.d;
      dir = d;
      down = d.contains('abwärts');
      map.fitPts([...path, pa, pb]);
    } on _RdErr catch (e) {
      if (run != _run) return;
      err = e.m;
    } catch (e) {
      if (run != _run) return;
      err = 'Gewässerdaten nicht verfügbar: $e';
    }
    _set(() => (step = 'done', busy = false));
  }

  /// Netz laden (Ausschnitt auf 0.01° nach aussen gerundet, damit ähnliche Messungen dieselbe Abfrage stellen) oder wiederverwenden
  Future<Map<int, _Node>> _graph(IncStore s, LatLng a, LatLng b) async {
    final m = math.max(1500.0, geoDist(a, b) * .3), la = m / 111320, lo = m / (111320 * math.cos(deg2rad(a.latitude)));
    double r2(double x) => (x * 100).round() / 100;
    final bb = [
      (math.min(a.latitude, b.latitude) - la) * 100,
      (math.min(a.longitude, b.longitude) - lo) * 100,
      (math.max(a.latitude, b.latitude) + la) * 100,
      (math.max(a.longitude, b.longitude) + lo) * 100,
    ];
    final q = [r2(bb[0].floorToDouble() / 100), r2(bb[1].floorToDouble() / 100), r2(bb[2].ceilToDouble() / 100), r2(bb[3].ceilToDouble() / 100)];
    final n = _net;
    if (n != null && n.bb[0] <= q[0] && n.bb[1] <= q[1] && n.bb[2] >= q[2] && n.bb[3] >= q[3]) return n.g;
    final net = RiverNet.parse(await overpassNet(s.api, {'bbox': q}));
    final g = <int, _Node>{};
    for (final w in net.ways) {
      for (var k = 1; k < w.nodes.length; k++) {
        final u = g.putIfAbsent(w.nodes[k - 1], () => _Node(w.geometry[k - 1].latitude, w.geometry[k - 1].longitude));
        final v = g.putIfAbsent(w.nodes[k], () => _Node(w.geometry[k].latitude, w.geometry[k].longitude));
        final d = geoDist(LatLng(u.lat, u.lon), LatLng(v.lat, v.lon));
        u.adj.add((w.nodes[k], d, true));
        v.adj.add((w.nodes[k - 1], d, false));
      }
    }
    _net = _Graph(q, g);
    return g;
  }
}

class _RdErr implements Exception {
  _RdErr(this.m);
  final String m;
}

/// nächster Punkt auf einem Gewässerabschnitt (rdSnap)
_Snap? _snap(Map<int, _Node> g, LatLng p) {
  _Snap? best;
  const ky = 111320.0;
  final kx = ky * math.cos(deg2rad(p.latitude));
  g.forEach((uid, u) {
    for (final (vid, d, f) in u.adj) {
      if (!f) continue;
      final v = g[vid]!;
      final ax = (u.lon - p.longitude) * kx, ay = (u.lat - p.latitude) * ky, dx = (v.lon - u.lon) * kx, dy = (v.lat - u.lat) * ky, l2 = dx * dx + dy * dy;
      final t = l2 > 0 ? math.max(0.0, math.min(1.0, -(ax * dx + ay * dy) / l2)) : 0.0;
      final e = math.sqrt(math.pow(ax + t * dx, 2) + math.pow(ay + t * dy, 2));
      if (best == null || e < best!.e) best = _Snap(e, uid, vid, d * t, d * (1 - t), LatLng(u.lat + (v.lat - u.lat) * t, u.lon + (v.lon - u.lon) * t));
    }
  });
  return best;
}

/// kürzeste Wege (Dijkstra mit Binärheap); dir = nur in Fliessrichtung
({Map<int, double> d, Map<int, int?> p}) _dijkstra(Map<int, _Node> g, List<(int, double)> src, bool dir) {
  final dd = <int, double>{}, pp = <int, int?>{}, h = <(double, int)>[];
  void push(double c, int id) {
    h.add((c, id));
    var i = h.length - 1;
    while (i > 0) {
      final q = (i - 1) >> 1;
      if (h[q].$1 <= h[i].$1) break;
      final t = h[q];
      h[q] = h[i];
      h[i] = t;
      i = q;
    }
  }

  (double, int) pop() {
    final t = h.first, x = h.removeLast();
    if (h.isNotEmpty) {
      h[0] = x;
      var i = 0;
      for (;;) {
        final l = 2 * i + 1, r = l + 1;
        var m = i;
        if (l < h.length && h[l].$1 < h[m].$1) m = l;
        if (r < h.length && h[r].$1 < h[m].$1) m = r;
        if (m == i) break;
        final y = h[m];
        h[m] = h[i];
        h[i] = y;
        i = m;
      }
    }
    return t;
  }

  for (final (id, c) in src) {
    if (!dd.containsKey(id) || c < dd[id]!) {
      dd[id] = c;
      pp[id] = null;
      push(c, id);
    }
  }
  while (h.isNotEmpty) {
    final (c, id) = pop();
    if (c > dd[id]!) continue;
    for (final (n, d, f) in g[id]!.adj) {
      if (dir && !f) continue;
      final nc = c + d;
      if (!dd.containsKey(n) || nc < dd[n]!) {
        dd[n] = nc;
        pp[n] = id;
        push(nc, n);
      }
    }
  }
  return (d: dd, p: pp);
}

/// Weg vom Lotpunkt A zum Lotpunkt B (rdPath): Meter und Punkte, null ohne Verbindung
({double d, List<LatLng> pts})? _path(Map<int, _Node> g, _Snap a, _Snap b, bool dir) {
  ({double d, List<int> nodes})? best;
  if (a.u == b.u && a.v == b.v && (!dir || b.du >= a.du)) best = (d: (b.du - a.du).abs(), nodes: <int>[]); // gleicher Abschnitt
  final r = _dijkstra(g, dir ? [(a.v, a.dv)] : [(a.u, a.du), (a.v, a.dv)], dir);
  for (final (end, add) in dir ? [(b.u, b.du)] : [(b.u, b.du), (b.v, b.dv)]) {
    final de = r.d[end];
    if (de == null) continue;
    final d = de + add;
    if (best == null || d < best.d) {
      final nodes = <int>[];
      for (int? n = end; n != null; n = r.p[n]) {
        nodes.insert(0, n);
      }
      best = (d: d, nodes: nodes);
    }
  }
  if (best == null) return null;
  return (d: best.d, pts: [a.ll, ...best.nodes.map((n) => LatLng(g[n]!.lat, g[n]!.lon)), b.ll]);
}

/// Ebenen für flutter_map 8: Weg entlang des Gewässers, Verbindung zu den Lotpunkten und Luftlinie gestrichelt, Marker A/B
List<Widget> rdLayers(RdState rd) {
  final a = rd.a, b = rd.b;
  if (a == null) return const [];
  Marker pin(LatLng p, String t) => Marker(
      point: p,
      width: 26,
      height: 26,
      child: IgnorePointer(
          child: Container(
              alignment: Alignment.center,
              decoration: BoxDecoration(color: const Color(0xFF2EE6D6), shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 2)),
              child: Text(t, style: const TextStyle(color: Color(0xFF08161E), fontWeight: FontWeight.bold, fontSize: 13)))));
  final dash = StrokePattern.dashed(segments: const [4, 4]);
  return [
    PolylineLayer(polylines: [
      if (b != null) Polyline(points: [a, b], color: Colors.white.withValues(alpha: .55), strokeWidth: 1.5, pattern: StrokePattern.dashed(segments: const [8, 6])),
      if (rd.snapA != null) Polyline(points: [a, rd.snapA!], color: Colors.white, strokeWidth: 1, pattern: dash),
      if (b != null && rd.snapB != null) Polyline(points: [b, rd.snapB!], color: Colors.white, strokeWidth: 1, pattern: dash),
      if (rd.path.length > 1) Polyline(points: rd.path, color: const Color(0xFF2EE6D6).withValues(alpha: .9), strokeWidth: 5),
    ]),
    MarkerLayer(markers: [pin(a, 'A'), if (b != null) pin(b, 'B')]),
  ];
}

/// Seitenfenster bzw. Leiste des Werkzeugs: Schritte, Ergebnis mit Treibzeit und Vergleichsgeschwindigkeiten
class RdPanel extends StatefulWidget {
  const RdPanel({super.key, required this.s, required this.map, required this.rd});
  final IncStore s;
  final MapCtl map;
  final RdState rd;
  @override
  State<RdPanel> createState() => _RdPanelState();
}

class _RdPanelState extends State<RdPanel> {
  final _flow = TextEditingController();
  SharedPreferences? _sp;

  @override
  void initState() {
    super.initState();
    SharedPreferences.getInstance().then((p) {
      _sp = p;
      if (_flow.text.isEmpty && mounted) setState(() => _flow.text = p.getString('rdflow') ?? '');
    });
    _flow.text = _incFlow ?? '';
    // wie rdOpen: beim Öffnen gleich mit Punkt A beginnen
    if (widget.rd.step == 'idle') WidgetsBinding.instance.addPostFrameCallback((_) => _start());
  }

  @override
  void dispose() {
    _flow.dispose();
    super.dispose();
  }

  /// Fliessgeschwindigkeit des Einsatzes (rdFlow), sonst null
  String? get _incFlow {
    final f = jd(widget.s.inc?['flow_ms']);
    return f != null && f > 0 ? '$f' : null;
  }

  List<double> get _speeds {
    final l = widget.s.me?['rd_speeds'];
    final v = l is List ? l.map(jd).whereType<double>().toList() : <double>[];
    return v.isNotEmpty ? v : const [10, 20, 30];
  }

  void _start() {
    if (!mounted) return;
    final f = _incFlow;
    if (f != null) _flow.text = f;
    widget.rd.measure(widget.s, widget.map);
  }

  void _close() {
    widget.rd.reset();
    widget.map.openPanel(null);
  }

  String _sp2(double v) => v == v.roundToDouble() ? '${v.round()}' : '$v';

  @override
  Widget build(BuildContext context) => ListenableBuilder(
      listenable: Listenable.merge([widget.rd, widget.s]),
      builder: (c, _) {
        final rd = widget.rd, s = widget.s, t = Theme.of(c);
        final close = OutlinedButton(onPressed: _close, child: const Text('Schliessen'));
        final again = FilledButton(onPressed: _start, child: const Text('Neu messen'));
        Widget body;
        if (!s.feat('rd')) {
          body = const Mut('Das Werkzeug «Flussdistanz» ist ausgeschaltet.');
        } else if (rd.step == 'a' || rd.step == 'idle') {
          body = const Text('Startpunkt A auf der Karte antippen');
        } else if (rd.step == 'b') {
          body = const Text('Zielpunkt B auf der Karte antippen');
        } else if (rd.step == 'load') {
          body = const Row(children: [SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)), SizedBox(width: 10), Text('Gewässerdaten werden geladen …')]);
        } else if (rd.err != null) {
          body = Text(rd.err!);
        } else {
          final f = double.tryParse(_flow.text.replaceAll(',', '.')) ?? 0;
          body = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text.rich(TextSpan(children: [
              TextSpan(text: 'Flussdistanz A → B: ${kmT(rd.dist)}', style: const TextStyle(fontWeight: FontWeight.bold)),
              TextSpan(text: '  (Luftlinie ${kmT(rd.air)})', style: TextStyle(color: t.colorScheme.onSurfaceVariant)),
            ])),
            const SizedBox(height: 4),
            Mut('${rd.dir}${s.fcs(rd.a?.latitude, rd.a?.longitude, ' · A ')}${s.fcs(rd.b?.latitude, rd.b?.longitude, ' · B ')}'),
            const SizedBox(height: 8),
            Row(children: [
              SizedBox(
                  width: 150,
                  child: TextField(
                      controller: _flow,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      decoration: const InputDecoration(labelText: 'Fliessgeschwindigkeit', suffixText: 'm/s', isDense: true),
                      onChanged: (v) {
                        _sp?.setString('rdflow', v);
                        setState(() {});
                      })),
              const SizedBox(width: 12),
              Expanded(child: Text(f > 0 ? '${durS(rd.dist / f)} (${(f * 3.6).toStringAsFixed(1)} km/h)' : 'Fliessgeschwindigkeit eingeben')),
            ]),
            const SizedBox(height: 6),
            ..._speeds.map((v) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(children: [SizedBox(width: 150, child: Text('Mit ${_sp2(v)} km/h')), const SizedBox(width: 12), Text(durS(rd.dist / (v / 3.6)))]))),
            if (!rd.down)
              Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Mut(rd.dir.contains('aufwärts')
                      ? 'Treibzeit mit der Fliessgeschwindigkeit: von B nach A (flussabwärts).'
                      : 'Keine durchgehende Fliessrichtung: Treibzeit nur als Richtwert.')),
          ]);
        }
        return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          PanelHead('Flussdistanz', onClose: _close),
          Padding(
              padding: const EdgeInsets.fromLTRB(12, 4, 12, 12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
                body,
                if (rd.step == 'done')
                  Padding(padding: const EdgeInsets.only(top: 10), child: Wrap(spacing: 8, children: [again, close])),
              ])),
        ]);
      });
}
