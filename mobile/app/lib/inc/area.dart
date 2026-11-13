// Suchgebiet entlang des Flusslaufs (Portierung von searchArea, riverNet, riverFollow, corridor, riverArea und overpassNet aus web/common.js).
// Das Gewässernetz kommt über die Route overpass (Cache des Servers); erreicht der Server Overpass nicht (502 mit query), fragt das Gerät
// die Server selbst in derselben Reihenfolge, lädt das Ergebnis über overpass/put hoch und meldet die Versuche über overpass/stat.
import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;

import 'package:http/http.dart' as http;
import 'package:latlong2/latlong.dart';

import '../api.dart';
import 'util.dart';

const bankM = 30.0; // Ufergebiet je Seite
const snapM = 300.0; // maximale Distanz PLS zum Gewässer
const waterM = 80.0; // so nahe an der Gewässerachse gilt als «auf dem Gewässer»

int netRadius(double d) => ((math.min(math.max(5000, d * 1.3), 30000)) / 1000).ceil() * 1000;

class Way {
  Way(this.id, this.nodes, this.geometry, this.name);
  final int id;
  final List<int> nodes;
  final List<LatLng> geometry;
  final String name;
}

class WayRef {
  WayRef(this.w, this.k);
  final Way w;
  final int k;
}

class RiverNet {
  RiverNet(this.ways, this.byNode);
  final List<Way> ways;
  final Map<int, List<WayRef>> byNode;

  factory RiverNet.parse(J j) {
    final els = j['elements'];
    if (els is! List) throw Exception('ungültige Antwort');
    final ways = <Way>[];
    for (final e in els.whereType<Map>()) {
      final g = e['geometry'], n = e['nodes'];
      if (g is! List || n is! List) continue;
      final geo = g.whereType<Map>().map((p) => LatLng(jd(p['lat']) ?? 0, jd(p['lon']) ?? 0)).toList();
      final nodes = n.map((x) => ji(x) ?? 0).toList();
      if (geo.length != nodes.length || geo.isEmpty) continue;
      ways.add(Way(ji(e['id']) ?? 0, nodes, geo, js(jm(e['tags'])['name'])));
    }
    final by = <int, List<WayRef>>{};
    for (final w in ways) {
      for (var k = 0; k < w.nodes.length; k++) {
        (by[w.nodes[k]] ??= []).add(WayRef(w, k));
      }
    }
    return RiverNet(ways, by);
  }
}

/// Eintrag je PLS: geladenes Netz, laufende Abfrage oder Fehlschlag
class NetSlot {
  NetSlot({this.r = 0, this.net, this.loading = false, this.fail = false, this.err = '', this.retryAt = 0});
  int r;
  RiverNet? net;
  bool loading, fail;
  String err;
  int retryAt;
}

class _Ctx {
  _Ctx(this.posts, this.segs);
  final List<({LatLng p, String cut})> posts; // cut = Text, wo das Gebiet endet («am Posten …», «an «Wehr» (Flusssperrung)»)
  final List<_Seg> segs; // Flusssperren als Linien bzw. Ränder von Bereichen (Issue #182)
  final done = <String, double>{};
  String? cut;
}

class _Seg {
  _Seg(this.a, this.b, this.cut);
  final LatLng a, b;
  final String cut;
}

/// Flusssperren (Issue #182, stops aus incident/state: name, why, lat, lon, pts, line): Punkte wie Posten, Linien und Ränder von Bereichen als Strecken (stopCtx in common.js)
({List<({LatLng p, String cut})> posts, List<_Seg> segs}) _stopCtx(List<J> stops) {
  final posts = <({LatLng p, String cut})>[], segs = <_Seg>[];
  for (final s in stops) {
    final nm = s['name'] is String ? s['name'] as String : '', why = s['why'] is String ? s['why'] as String : '';
    final cut = nm.isNotEmpty ? 'an «$nm»${why.isNotEmpty ? ' ($why)' : ''}' : 'an einer Flusssperre';
    final pts = s['pts'] is List ? (s['pts'] as List).whereType<List>().map((q) => LatLng(jd(q[0])!, jd(q[1])!)).toList() : <LatLng>[];
    if (pts.length < 2) {
      final la = jd(s['lat']), lo = jd(s['lon']);
      if (la != null && lo != null) posts.add((p: LatLng(la, lo), cut: cut));
      continue;
    }
    if (s['line'] != 1 && s['line'] != true) pts.add(pts.first);
    for (var i = 1; i < pts.length; i++) {
      segs.add(_Seg(pts[i - 1], pts[i], cut));
    }
  }
  return (posts: posts, segs: segs);
}

/// erste Strecke, die a → b schneidet, bzw. erster Posten (< 120 m) zwischen a und b: (t = Anteil auf a → b, cut)
({double t, String cut})? _hit(_Ctx ctx, LatLng a, LatLng b) {
  ({double t, String cut})? best;
  final dx = b.longitude - a.longitude, dy = b.latitude - a.latitude;
  for (final q in ctx.segs) {
    final ex = q.b.longitude - q.a.longitude, ey = q.b.latitude - q.a.latitude, den = dx * ey - dy * ex;
    if (den == 0) continue;
    final t = ((q.a.longitude - a.longitude) * ey - (q.a.latitude - a.latitude) * ex) / den, u = ((q.a.longitude - a.longitude) * dy - (q.a.latitude - a.latitude) * dx) / den;
    if (t > 1e-9 && t <= 1 && u >= 0 && u <= 1 && (best == null || t < best.t)) best = (t: t, cut: q.cut);
  }
  const ky = 111320.0;
  for (final q in ctx.posts) {
    final kx = ky * math.cos(deg2rad(q.p.latitude)), ax = (a.longitude - q.p.longitude) * kx, ay = (a.latitude - q.p.latitude) * ky, mx = dx * kx, my = dy * ky, l = mx * mx + my * my;
    if (l == 0) continue;
    final t = -(ax * mx + ay * my) / l;
    if (t <= 0 || t > 1) continue;
    final ox = ax + t * mx, oy = ay + t * my;
    if (math.sqrt(ox * ox + oy * oy) < 120 && (best == null || t < best.t)) best = (t: t, cut: q.cut);
  }
  return best;
}

List<List<LatLng>>? _follow(RiverNet net, Way w, int k, double rem, Set<int> seen, _Ctx ctx) {
  final key = '${w.id}:$k';
  if ((ctx.done[key] ?? -1) >= rem) return null;
  ctx.done[key] = rem;
  final pts = [w.geometry[k]], out = <List<LatLng>>[], last = w.geometry.length - 1;
  seen = {...seen, w.id};
  bool post(LatLng p) {
    for (final q in ctx.posts) {
      if (geoDist(p, q.p) < 120) {
        ctx.cut = q.cut;
        return true;
      }
    }
    return false;
  }

  List<List<LatLng>> sub(WayRef e, double r) {
    final s = _follow(net, e.w, e.k, r, seen, ctx);
    return s == null ? [] : s.map((b) => [...pts, ...b.skip(1)]).toList();
  }

  if (post(pts[0])) return [pts];
  for (var j = k; j < last; j++) {
    for (final e in net.byNode[w.nodes[j]] ?? <WayRef>[]) {
      if (e.k == 0 && e.w != w && !seen.contains(e.w.id)) out.addAll(sub(e, rem));
    }
    final a = w.geometry[j], b = w.geometry[j + 1], d = geoDist(a, b), x = _hit(ctx, a, b);
    if (x != null && d * x.t < rem) {
      pts.add(LatLng(a.latitude + (b.latitude - a.latitude) * x.t, a.longitude + (b.longitude - a.longitude) * x.t));
      ctx.cut = x.cut;
      return [pts, ...out];
    }
    if (d >= rem) {
      final f = d == 0 ? 0.0 : rem / d;
      pts.add(LatLng(a.latitude + (b.latitude - a.latitude) * f, a.longitude + (b.longitude - a.longitude) * f));
      return [pts, ...out];
    }
    pts.add(b);
    rem -= d;
    if (post(b)) return [pts, ...out];
  }
  final nx = (net.byNode[w.nodes[last]] ?? <WayRef>[]).where((e) => !seen.contains(e.w.id) && e.k < e.w.geometry.length - 1).expand((e) => sub(e, rem)).toList();
  return nx.isNotEmpty ? [...nx, ...out] : [pts, ...out];
}

const _r = 6378137.0;
math.Point<double> _proj(LatLng p) => math.Point(_r * deg2rad(p.longitude), _r * math.log(math.tan(math.pi / 4 + deg2rad(p.latitude) / 2)));
LatLng _unproj(double x, double y) => LatLng((2 * math.atan(math.exp(y / _r)) - math.pi / 2) * 180 / math.pi, x / _r * 180 / math.pi);

/// Band der Breite w (Meter) je Seite um eine Linie
List<LatLng> corridor(List<LatLng> pts, double w) {
  final k = w / math.cos(deg2rad(pts[0].latitude)), p = pts.map(_proj).toList(), a = <LatLng>[], b = <LatLng>[];
  for (var j = 0; j < p.length; j++) {
    final q = p[math.max(0, j - 1)], r = p[math.min(p.length - 1, j + 1)];
    var dx = r.x - q.x, dy = r.y - q.y;
    final n = math.sqrt(dx * dx + dy * dy);
    dx /= n == 0 ? 1 : n;
    dy /= n == 0 ? 1 : n;
    a.add(_unproj(p[j].x - dy * k, p[j].y + dx * k));
    b.add(_unproj(p[j].x + dy * k, p[j].y - dx * k));
  }
  return [...a, ...b.reversed];
}

class RiverArea {
  final lines = <List<LatLng>>[];
  final polys = <List<LatLng>>[];
  String? cutBy;
  double maxLen = 0;
}

RiverArea? riverArea(RiverNet net, LatLng i, double d, List<({LatLng p, String name})> posts, [List<J> stops = const []]) {
  WayRef? best;
  var bd = 1e12;
  for (final w in net.ways) {
    for (var k = 0; k < w.geometry.length; k++) {
      final x = geoDist(w.geometry[k], i);
      if (x < bd) {
        bd = x;
        best = WayRef(w, k);
      }
    }
  }
  if (best == null || bd > snapM) return null;
  final sc = _stopCtx(stops), r = RiverArea(), ctx = _Ctx([...posts.map((q) => (p: q.p, cut: 'am Posten ${q.name}')), ...sc.posts], sc.segs);
  for (final pts in _follow(net, best.w, best.k, d, {}, ctx) ?? <List<LatLng>>[]) {
    if (pts.length < 2) continue;
    var len = 0.0;
    for (var j = 1; j < pts.length; j++) {
      len += geoDist(pts[j - 1], pts[j]);
    }
    r.maxLen = math.max(r.maxLen, len);
    r.lines.add(pts);
    r.polys.add(corridor(pts, bankM));
  }
  r.cutBy = ctx.cut;
  return r;
}

String srcKey(double lat, double lon) => '${lat.toStringAsFixed(6)},${lon.toStringAsFixed(6)}';

/// Schlüssel eines Teilgebiets: PLS mit Zeitpunkt (partKey)
String partKey(double lat, double lon, num at) => '${lat.toStringAsFixed(6)},${lon.toStringAsFixed(6)}@$at';

class AreaPart {
  AreaPart(this.key, this.names, this.at);
  final String key;
  final List<String> names;
  final LatLng at;
  final polys = <List<LatLng>>[];
  final circles = <({LatLng c, double r})>[];
  String get label => names.isEmpty ? 'PLS des Einsatzes' : names.join(', ');
}

class SearchArea {
  final polys = <List<LatLng>>[];
  final lines = <List<LatLng>>[];
  final circles = <({LatLng c, double r})>[];
  final info = <String>[];
  final parts = <AreaPart>[];
  String? short;
  bool get empty => polys.isEmpty && circles.isEmpty;

  bool contains(LatLng p) => circles.any((c) => geoDist(p, c.c) <= c.r) || polys.any((pl) => inPoly(p, pl));
}

/// srcs = sources des Servers ([{lat,lon,at,own,names}]), nets je PLS, get lädt das Netz, redraw nach dem Laden; rs = Rettlinge
SearchArea searchArea(List<J> srcs, Map<String, NetSlot> nets, double? flow, num now, List<({LatLng p, String name})> posts,
    Future<J> Function(LatLng s, int r) get, void Function() redraw, List<J> rs, [List<J> stops = const []]) {
  final r = SearchArea();
  if (srcs.isEmpty) {
    final miss = rs.where((x) => x['rescued_at'] == null && x['lat'] == null).toList();
    r.info.add(rs.isEmpty
        ? 'Suchgebiet: Kein PLS gesetzt.'
        : miss.isNotEmpty
            ? 'Suchgebiet: Kein PLS für ${miss.map((x) => '«${x['name']}»').join(', ')}. PLS des Einsatzes oder der Rettlinge setzen.'
            : 'Suchgebiet: Alle Rettlinge gefunden.');
    return r;
  }
  if (flow == null || flow == 0) {
    r.info.add('Suchgebiet: Fliessgeschwindigkeit fehlt.');
    return r;
  }
  for (final s in srcs) {
    final lat = jd(s['lat'])!, lon = jd(s['lon'])!, at = jn(s['at']) ?? now, d = flow * (now - at), k = srcKey(lat, lon);
    final names = (s['names'] is List ? (s['names'] as List).map((e) => '$e').toList() : <String>[]);
    final who = names.isNotEmpty ? ' «${names.join('», «')}»' : '';
    final lbl = srcs.length > 1 || who.isNotEmpty ? 'Suchgebiet ab PLS$who (${fmtDT(at)}): ' : 'Suchgebiet: ';
    if (d <= 0) {
      r.info.add('${lbl}Der Zeitpunkt des PLS liegt in der Zukunft.');
      continue;
    }
    final c = LatLng(lat, lon), p = AreaPart(partKey(lat, lon, at), names, c);
    r.parts.add(p);
    var n = nets[k];
    final now0 = DateTime.now().millisecondsSinceEpoch;
    if (n == null || (!n.loading && ((!n.fail && n.r < d * 1.1 && n.r < 30000) || (n.fail && now0 > n.retryAt)))) {
      final rr = netRadius(d), slot = NetSlot(r: n?.r ?? 0, net: n?.net, loading: true);
      nets[k] = slot;
      get(c, rr).then((j) => NetSlot(r: rr, net: RiverNet.parse(j))).catchError((e) => NetSlot(r: rr, fail: true, err: '$e', retryAt: DateTime.now().millisecondsSinceEpoch + 20000)).then((v) {
        if (nets[k] == slot) {
          nets[k] = v;
          redraw();
        }
      });
      n = slot;
    }
    void circle(String why) {
      final x = (c: c, r: d);
      r.circles.add(x);
      p.circles.add(x);
      r.info.add('$lbl$why Darstellung als Kreis, Radius ${d.round()} m.');
    }

    if (n.fail) {
      circle('Gewässerdaten nicht verfügbar (${n.err}), neuer Versuch folgt.');
      continue;
    }
    if (n.net == null) {
      circle('Gewässerdaten werden geladen.');
      continue;
    }
    final a = riverArea(n.net!, c, d, posts, stops);
    if (a == null) {
      circle('Kein Gewässer nahe der PLS.');
      continue;
    }
    r.polys.addAll(a.polys);
    r.lines.addAll(a.lines);
    p.polys.addAll(a.polys);
    r.info.add('$lbl${(a.maxLen / 1000).toStringAsFixed(1)} km Flusslauf ab PLS (Driftdistanz ${d.round()} m), Ufergebiet ${bankM.round()} m je Seite${a.cutBy != null ? ', endet ${a.cutBy}.' : '.'}');
  }
  if (srcs.length > 1) {
    final riv = r.parts.where((p) => p.polys.isNotEmpty).length, circ = r.parts.length - riv, none = srcs.length - r.parts.length;
    r.short = 'Suchgebiet aus ${srcs.length} PLS: $riv entlang Gewässer${circ > 0 ? ', $circ als Kreis' : ''}${none > 0 ? ', $none ohne Gebiet' : ''}';
  }
  return r;
}

/// Abstand zur nächsten Gewässerachse (Meter), unendlich ohne Daten (waterDist)
double waterDist(LatLng p, Iterable<RiverNet> nets) {
  final ky = 111320.0, kx = ky * math.cos(deg2rad(p.latitude));
  var best = double.infinity;
  for (final n in nets) {
    for (final w in n.ways) {
      final g = w.geometry;
      for (var j = 1; j < g.length; j++) {
        final ax = (g[j - 1].longitude - p.longitude) * kx, ay = (g[j - 1].latitude - p.latitude) * ky;
        final dx = (g[j].longitude - g[j - 1].longitude) * kx, dy = (g[j].latitude - g[j - 1].latitude) * ky, l = dx * dx + dy * dy;
        final t = l == 0 ? 0.0 : math.max(0.0, math.min(1.0, -(ax * dx + ay * dy) / l));
        best = math.min(best, math.sqrt(math.pow(ax + t * dx, 2) + math.pow(ay + t * dy, 2)));
      }
    }
  }
  return best;
}

/// Gewässernetz über den Server, Rückfall im Gerät (overpassNet). put: Route zum Hochladen (overpass/put), null = nicht hochladen.
Future<J> overpassNet(Api api, J body, {String route = 'overpass', String? put = 'overpass/put'}) async {
  try {
    return await api.call(route, body: body, timeout: const Duration(seconds: 70));
  } on ApiError catch (e) {
    final q = e.data?['query'];
    if (q is! String) rethrow;
    final tries = <J>[];
    void stat() {
      if (put != null && tries.isNotEmpty) api.call('overpass/stat', body: {'results': tries}).catchError((_) => <String, dynamic>{});
    }

    for (final url in (e.data?['servers'] is List ? e.data!['servers'] as List : const [])) {
      final t = DateTime.now().millisecondsSinceEpoch;
      void bad(String err) => tries.add({'url': '$url', 'ok': 0, 'ms': DateTime.now().millisecondsSinceEpoch - t, 'err': err});
      try {
        final res = await http.post(Uri.parse('$url'), body: {'data': q}, headers: {'User-Agent': appUa}).timeout(const Duration(seconds: 30));
        if (res.statusCode != 200) {
          bad('HTTP ${res.statusCode}');
          continue;
        }
        final j = jsonDecode(utf8.decode(res.bodyBytes));
        if (j is! Map || j['elements'] is! List) {
          bad('Ungültige Antwort');
          continue;
        }
        tries.add({'url': '$url', 'ok': 1, 'ms': DateTime.now().millisecondsSinceEpoch - t});
        stat();
        if (put != null) {
          final els = (j['elements'] as List).whereType<Map>().map((x) {
            final tg = jm(x['tags']);
            return {'type': x['type'], 'id': x['id'], 'nodes': x['nodes'], 'geometry': x['geometry'], 'tags': {'waterway': tg['waterway'], 'name': tg['name']}};
          }).toList();
          api.call(put, body: {...body, 'elements': els}, timeout: const Duration(seconds: 60)).catchError((_) => <String, dynamic>{});
        }
        return j.cast<String, dynamic>();
      } on TimeoutException {
        bad('Zeitüberschreitung');
      } on FormatException {
        bad('Ungültige Antwort');
      } catch (_) {
        bad('Keine Antwort');
      }
    }
    stat();
    rethrow;
  }
}
