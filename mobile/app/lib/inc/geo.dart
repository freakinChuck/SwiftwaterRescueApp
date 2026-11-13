// Geodaten-Ebene (Issue #182, Funktion «geo», wie geoSync/geoDraw in index.html): Objekte am Gewässer aus OpenStreetMap, zuschaltbar.
// Geladen ab Zoomstufe geoZ in Kacheln von geoT Grad (Route geo/tile, gleicher Zwischenspeicher wie Verwaltung und Web-App), höchstens 9 im Bild,
// je 30 min im Gerät behalten. Wirkt auf nichts, nur Orientierung.

import 'package:flutter_map/flutter_map.dart';

import 'store.dart';
import 'util.dart';

const geoZ = 13.0, geoT = 0.1;

class GeoLayer {
  GeoLayer(this.s);
  final IncStore s;
  final _tiles = <String, ({List<J> items, int t, bool err})>{};
  bool busy = false;
  String err = '';

  bool get on => s.feat('geo') && s.geoKinds.isNotEmpty && s.vis('geo');

  List<(int, int)> tileList(LatLngBounds b) {
    final t = <(int, int)>[];
    for (var x = (b.west / geoT).floor(); x <= (b.east / geoT).floor(); x++) {
      for (var y = (b.south / geoT).floor(); y <= (b.north / geoT).floor(); y++) {
        t.add((x, y));
      }
    }
    return t;
  }

  /// sichtbare Objekte (angebotene, nicht ausgeblendete Arten), je ref einmal
  List<J> items(MapCamera cam) {
    if (!on || cam.zoom < geoZ) return [];
    final tl = tileList(cam.visibleBounds);
    if (tl.length > 9) return [];
    final ok = s.geoKinds.map((k) => js(k['k'])).toSet(), m = <String, J>{};
    for (final (x, y) in tl) {
      for (final it in _tiles['$x,$y']?.items ?? const <J>[]) {
        final k = js(it['kind']);
        if (ok.contains(k) && !s.geoOff.contains(k)) m[js(it['ref'])] = it;
      }
    }
    return m.values.toList();
  }

  /// fehlende Kacheln nacheinander laden, danach redraw
  Future<void> sync(MapCamera cam, void Function() redraw) async {
    if (!on || cam.zoom < geoZ || busy) return;
    final tl = tileList(cam.visibleBounds);
    if (tl.length > 9) return;
    final now = DateTime.now().millisecondsSinceEpoch;
    final miss = tl.where((t) {
      final c = _tiles['${t.$1},${t.$2}'];
      return c == null || now - c.t > (c.err ? 60000 : 1800000);
    }).toList();
    if (miss.isEmpty) return;
    busy = true;
    redraw();
    for (final (x, y) in miss) {
      try {
        final r = await s.api.call('geo/tile', body: {'x': x, 'y': y});
        _tiles['$x,$y'] = (items: jl(r['items']), t: DateTime.now().millisecondsSinceEpoch, err: false);
        err = '';
      } catch (e) {
        _tiles['$x,$y'] = (items: <J>[], t: DateTime.now().millisecondsSinceEpoch, err: true);
        err = '$e';
      }
    }
    busy = false;
    redraw();
  }

  /// Stand für das Fenster «Ansicht»
  String info(MapCamera cam) => !s.vis('geo')
      ? 'Objekte am Gewässer aus OpenStreetMap (Wehre, Brücken, Einwasserungen …), nur zur Orientierung.'
      : cam.zoom < geoZ || tileList(cam.visibleBounds).length > 9
          ? 'Sichtbar ab näherem Zoom (Zoomstufe ${geoZ.round()}).'
          : busy
              ? 'Wird geladen …'
              : err.isNotEmpty
                  ? '⚠ $err'
                  : '${items(cam).length} Objekte im Bild · © OpenStreetMap-Mitwirkende';

  J? kindOf(String k) => jl(s.st?['geo_kinds']).where((x) => js(x['k']) == k).firstOrNull;

  /// Name wie geoNm: OSM-Name, sonst Art und Gewässer
  String name(J it) {
    final n = js(it['name']);
    if (n.isNotEmpty) return n;
    final w = js(it['water']);
    return '${js(kindOf(js(it['kind']))?['name'])}${w.isNotEmpty ? ' $w' : ''}'.trim();
  }
}
