// Fenster «Markierungen» der nativen Einsatz-App (Gegenstück zu renderPois, newPoi, startDraw, showPlace in web/index.html):
// PLS zuoberst (immer sichtbar), feste Standorte nach Kategorie, Markierungen und Bereiche der Einsatzleitung,
// je Eintrag und Gruppe ein-/ausblendbar (pro Gerät und Einsatz, Schlüssel wie sw_poihide_<id>: Markierung = ID, fester Standort = f<ID>).
// Ohne Einsatz nur die festen Standorte.
import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:latlong2/latlong.dart';

import 'ctx.dart';
import 'store.dart';
import 'util.dart';

const _placeCol = '#1d7fd1'; // PLACE_COL

List<LatLng> _ptsOf(dynamic v) => v is List
    ? v.whereType<List>().where((q) => q.length >= 2 && jd(q[0]) != null && jd(q[1]) != null).map((q) => LatLng(jd(q[0])!, jd(q[1])!)).toList()
    : <LatLng>[];

/// Einsatzsymbol (Issue #146): Bild `img/sym/<Schlüssel>.svg` vom Server des Kontos (wie symImg in common.js)
Widget symPic(IncStore s, String k, {double size = 32}) => SvgPicture.network('${s.acc.base}img/sym/${Uri.encodeComponent(k)}.svg',
    width: size, height: size, placeholderBuilder: (_) => SizedBox(width: size, height: size, child: const Icon(Icons.place, size: 18)));

/// Eckpunkte eines Bereichs bzw. [Punkt] einer Markierung (pois.pts = [[lat, lon], …])
List<LatLng> poiPts(J p) => _ptsOf(p['pts']);

/// Mittelpunkt einer Markierung bzw. eines Bereichs (poiPt: Bereich = Mitte des umschliessenden Rechtecks)
LatLng? poiPt(J p) {
  final l = poiPts(p);
  if (l.isEmpty) return null;
  if (p['kind'] != 'area') return l.first;
  final la = l.map((x) => x.latitude), lo = l.map((x) => x.longitude);
  return LatLng((la.reduce(math.min) + la.reduce(math.max)) / 2, (lo.reduce(math.min) + lo.reduce(math.max)) / 2);
}

/// feste Standorte: Eckpunkte eines Bereichs bzw. Punkte einer Linie (places.pts, NULL = Punkt) bzw. [Punkt]
List<LatLng> placePts(J p) {
  final l = _ptsOf(p['pts']);
  if (l.length >= (placeLine(p) ? 2 : 3)) return l;
  final c = llOf(p);
  return c == null ? [] : [c];
}

/// feste Linie (Issue #134, z. B. Fahrrinne): places.line = 1, pts mit mindestens 2 Punkten
bool placeLine(J f) => jb(f['line']) && _ptsOf(f['pts']).length >= 2;

/// fester Bereich (Fläche, mindestens 3 Eckpunkte)
bool placeArea(J f) => !placeLine(f) && _ptsOf(f['pts']).length >= 3;

/// Länge einer Linie in km (lineKm)
double _lineKm(List<LatLng> pts) {
  var m = 0.0;
  for (var j = 1; j < pts.length; j++) {
    m += geoDist(pts[j - 1], pts[j]);
  }
  return m / 1000;
}

/// Art eines festen Standorts mit pts (placeShape): «Linie, ca. X km» bzw. «Bereich»
String _placeShape(J f) {
  if (!placeLine(f)) return 'Bereich';
  final km = _lineKm(placePts(f));
  return 'Linie, ca. ${km.toStringAsFixed(km < 10 ? 2 : 1)} km';
}

/// Fläche in Hektaren (haOf)
double _ha(List<LatLng> pts) {
  if (pts.length < 3) return 0;
  final k = 111320 * math.cos(deg2rad(pts[0].latitude));
  var a = 0.0;
  for (var j = 0; j < pts.length; j++) {
    final p = pts[j], q = pts[(j + 1) % pts.length];
    a += p.longitude * k * q.latitude * 111320 - q.longitude * k * p.latitude * 111320;
  }
  return a.abs() / 2 / 1e4;
}

String _poiInfo(IncStore s, J p) {
  final c = poiPt(p);
  final pos = c == null ? '' : (p['kind'] == 'area' ? s.fcs(c.latitude, c.longitude, ' · Mitte ') : s.fcs(c.latitude, c.longitude));
  return '${p['kind'] == 'area' ? 'Bereich, ca. ${_ha(poiPts(p)).toStringAsFixed(1)} ha' : p['sym'] != null && js(p['sym_name']) != js(p['name']) ? js(p['sym_name']) : 'Markierung'}$pos · ${js(p['by_name'])} · ${fmtT(jn(p['created']))}';
}

/// Angaben zu einem festen Standort (placeInfo)
String placeInfo(IncStore s, J f) =>
    [js(f['descr']), placeArea(f) || placeLine(f) ? '${_placeShape(f)}${s.fcs(f['lat'], f['lon'], ', Mitte ', '')}' : s.fc(f['lat'], f['lon'])].where((x) => x.isNotEmpty).join(' · ');

/// Kategorie fester Standorte eingeblendet? Pro Person (me.place_view), sonst Standard der Verwaltung (def_on), ohne Kategorie an (pcOn)
bool pcOn(IncStore s, dynamic cid) {
  final k = '${ji(cid) ?? 0}', v = s.me?['place_view'];
  if (v is Map && v.containsKey(k)) return jb(v[k]);
  final c = s.placeCats.where((x) => ji(x['id']) == ji(cid)).firstOrNull;
  return c == null ? true : jb(c['def_on']);
}

/// Namen fester Standorte auf der Karte, pro Person (place_view.lbl, Standard an) (pcLbl)
bool pcLbl(IncStore s) {
  final v = s.me?['place_view'];
  return v is! Map || v['lbl'] != 0;
}

/// eigene Wahl der Kategorien gespeichert (Knopf «Standard der Verwaltung»)
bool pcOwn(IncStore s) {
  final v = s.me?['place_view'];
  return v is Map && v.keys.any((k) => k != 'lbl');
}

/// Kategorien fester Standorte sichtbar? Kategorie «place», Kategorie des Standorts und der Standort selbst (placeShown)
bool placeShown(IncStore s, J place) => s.vis('place') && pcOn(s, place['cat_id']) && !s.poiHide.contains('f${place['id']}');

/// vorkommende Kategorien (id, name, n), «Ohne Kategorie» (id 0) zuletzt (placeCatsUsed)
List<({int id, String name, int n})> placeCatsUsed(IncStore s) {
  final fs = s.places;
  final cs = s.placeCats
      .map((c) => (id: ji(c['id']) ?? 0, name: js(c['name']), n: fs.where((f) => ji(f['cat_id']) == ji(c['id'])).length))
      .where((c) => c.n > 0)
      .toList();
  final o = fs.where((f) => !cs.any((c) => c.id == ji(f['cat_id']))).length;
  return o > 0 ? [...cs, (id: 0, name: 'Ohne Kategorie', n: o)] : cs;
}

Timer? _pcSave;

/// Kategorien fester Standorte ein-/ausblenden, pro Person gespeichert (me/settings place_view, verzögert);
/// ids null = «Standard der Verwaltung» (behält die Wahl lbl der Namen); 'lbl' = Namen auf der Karte (setPlaceCat)
void setPlaceCat(IncStore s, List<Object>? ids, bool on) {
  final me = s.me;
  if (me == null) return;
  final old = me['place_view'];
  final om = old is Map ? old.cast<String, dynamic>() : <String, dynamic>{};
  final v = ids == null ? <String, dynamic>{if (om.containsKey('lbl')) 'lbl': om['lbl']} : {...om};
  for (final id in ids ?? const <int>[]) {
    v['$id'] = on ? 1 : 0;
  }
  me['place_view'] = v;
  s.setPoiHidden(const [], false); // nur neu zeichnen
  _pcSave?.cancel();
  _pcSave = Timer(const Duration(milliseconds: 400), () async {
    try {
      await s.api.call('me/settings', body: {'place_view': v});
    } catch (e) {
      me['place_view'] = old;
      s.fail(e);
      s.setPoiHidden(const [], false);
    }
  });
}

class PoisPanel extends StatefulWidget {
  const PoisPanel({super.key, required this.s, required this.map});
  final IncStore s;
  final MapCtl map;
  @override
  State<PoisPanel> createState() => _PoisPanelState();
}

class _PoisPanelState extends State<PoisPanel> {
  IncStore get s => widget.s;
  MapCtl get map => widget.map;

  void _afterShow() {
    if (MediaQuery.sizeOf(context).width < 760) map.openPanel(null);
  }

  void _showPoi(J p) {
    map.follow(null);
    final id = '${p['id']}', vk = p['kind'] == 'area' ? 'ar' : 'pt';
    if (s.poiHide.contains(id)) s.setPoiHidden([id], false);
    if (!s.vis(vk)) s.setVis([vk], true);
    final pts = poiPts(p);
    if (pts.isEmpty) return;
    p['kind'] == 'area' ? map.fitPts(pts) : map.centerOn(pts.first);
    _afterShow();
  }

  void _showPlace(J f) {
    map.follow(null);
    final k = 'f${f['id']}';
    if (s.poiHide.contains(k)) s.setPoiHidden([k], false);
    if (!pcOn(s, f['cat_id'])) setPlaceCat(s, [ji(f['cat_id']) ?? 0], true);
    if (!s.vis('place')) s.setVis(['place'], true);
    final pts = placePts(f);
    if (pts.isEmpty) return;
    pts.length > 1 ? map.fitPts(pts) : map.centerOn(pts.first);
    _afterShow();
  }

  Future<void> _remove(J p) async {
    final a = p['kind'] == 'area';
    if (!await confirmDlg(context, '${a ? 'Bereich' : 'Markierung'} «${js(p['name'])}» für alle entfernen?', ok: 'Entfernen', danger: true)) return;
    await s.act('incident/poi/remove', {'id': p['id']});
  }

  /// Neu (nur Einsatzleitung): Bezeichnung und Farbe, danach Punkt antippen bzw. Eckpunkte zeichnen (newPoi, startDraw)
  /// Markierungen mit Einsatzsymbol (Issue #146, wie newPoi in index.html): Auswahl aus me.symbols, «Ohne Symbol» nur mit me.sym_free;
  /// ohne eigene Bezeichnung gilt der Name des Symbols.
  Future<void> _newPoi(String kind) async {
    final area = kind == 'area';
    final n = TextEditingController(), q = TextEditingController();
    var color = poiColors.first.$1;
    final me = s.me ?? <String, dynamic>{}, syms = area ? <J>[] : ((me['symbols'] as List?) ?? []).map((x) => jm(x)).toList();
    final free = me['sym_free'] != 0, sets = jm(me['sym_sets']);
    String? sym;
    String symName(String? k) => js((syms.firstWhere((x) => x['k'] == k, orElse: () => {}))['name']);
    Widget grid(StateSetter ss) {
      final qq = q.text.trim().toLowerCase(), hit = syms.where((x) => qq.isEmpty || '${x['name']} ${x['cat']}'.toLowerCase().contains(qq)).toList();
      final multi = syms.map((x) => x['set']).toSet().length > 1, out = <Widget>[];
      Widget btn(String? k, Widget pic, String label) => SizedBox(
          width: 92,
          child: OutlinedButton(
              style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
                  side: BorderSide(color: sym == k ? Colors.white : Colors.white24, width: sym == k ? 2 : 1),
                  backgroundColor: sym == k ? const Color(0x331D7FD1) : null),
              onPressed: () => ss(() => sym = k),
              child: Column(mainAxisSize: MainAxisSize.min, children: [pic, const SizedBox(height: 3), Text(label, textAlign: TextAlign.center, style: const TextStyle(fontSize: 11), maxLines: 3)])));
      if (free && qq.isEmpty) out.add(btn(null, const SizedBox(height: 32, child: Center(child: Text('⚑', style: TextStyle(fontSize: 22)))), 'Ohne Symbol'));
      String? last;
      for (final x in hit) {
        final h = '${multi ? '${js(sets[x['set']])} · ' : ''}${js(x['cat'])}';
        if (h != last) out.add(SizedBox(width: double.infinity, child: Padding(padding: const EdgeInsets.only(top: 6), child: Mut(h))));
        last = h;
        out.add(btn(js(x['k']), symPic(s, js(x['k'])), js(x['name'])));
      }
      if (out.isEmpty) out.add(const Mut('Kein Symbol gefunden'));
      return SizedBox(height: 260, child: SingleChildScrollView(child: Wrap(spacing: 6, runSpacing: 6, children: out)));
    }

    final ok = await formDlg(
        context,
        area ? 'Bereich markieren' : 'Markierung setzen',
        (d, ss) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
              if (syms.isNotEmpty) ...[
                TextField(controller: q, onChanged: (_) => ss(() {}), decoration: const InputDecoration(labelText: 'Symbol suchen', isDense: true)),
                const SizedBox(height: 6),
                grid(ss),
              ],
              TextField(
                  controller: n,
                  autofocus: syms.isEmpty,
                  maxLength: 80,
                  decoration: InputDecoration(labelText: 'Bezeichnung', hintText: sym != null ? symName(sym) : area ? 'z. B. Suchsektor Nord' : 'z. B. Treffpunkt Boote')),
              if (sym == null) ...[
                const Padding(padding: EdgeInsets.only(top: 6, bottom: 6), child: Mut('Farbe')),
                ColorPick(value: color, onChanged: (c) => ss(() => color = c)),
              ],
              Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Mut(area
                      ? 'Danach die Eckpunkte des Bereichs auf der Karte antippen.'
                      : '${syms.isNotEmpty ? 'Ohne eigene Bezeichnung gilt der Name des Symbols. ' : ''}Danach den Punkt auf der Karte antippen.')),
            ]),
        ok: 'Weiter', valid: () {
      if (syms.isNotEmpty && sym == null && !free) {
        s.toast('Bitte ein Symbol wählen.', 'err');
        return false;
      }
      if (n.text.trim().isNotEmpty || sym != null) return true;
      s.toast('Bitte eine Bezeichnung eingeben.', 'err');
      return false;
    });
    final name = n.text.trim().isNotEmpty ? n.text.trim() : symName(sym);
    n.dispose();
    q.dispose();
    if (!ok || name.isEmpty) return;
    List<List<double>>? pts;
    if (area) {
      final l = await map.drawArea('Bereich «$name»: Eckpunkte antippen', color: hexColor(color));
      if (l == null || l.length < 3) return;
      pts = l.map((q) => [q.latitude, q.longitude]).toList();
    } else {
      final q = await map.pickPoint('Tippe auf die Karte, um «$name» zu setzen.');
      if (q == null) return;
      pts = [
        [q.latitude, q.longitude]
      ];
    }
    if (await s.act('incident/poi', {'kind': kind, 'name': name, 'color': color, 'sym': sym, 'pts': pts}) != null) map.openPanel('pois');
  }

  Widget _swatch(String c, {bool off = false, bool pls = false}) => Container(
      width: 14,
      height: 14,
      margin: const EdgeInsets.symmetric(horizontal: 6),
      decoration: BoxDecoration(
          color: pls ? accRed.withValues(alpha: off ? .35 : 1) : hexColor(c),
          shape: pls ? BoxShape.circle : BoxShape.rectangle,
          borderRadius: pls ? null : BorderRadius.circular(3),
          border: pls ? Border.all(color: Colors.white, width: 2) : null));

  Widget _item({Widget? check, required Widget sw, required String name, required String info, required List<Widget> acts, bool faded = false}) => Opacity(
      opacity: faded ? .6 : 1,
      child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 2),
          child: Row(children: [
            SizedBox(width: 40, child: check),
            sw,
            Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(name, style: const TextStyle(fontWeight: FontWeight.w600)),
              if (info.isNotEmpty) Mut(info, size: 12),
            ])),
            ...acts,
          ])));

  Widget _groupHead(String t, bool? v, ValueChanged<bool>? on) => Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Row(children: [
        SizedBox(width: 40, child: v == null ? null : Checkbox(value: v, onChanged: on == null ? null : (x) => on(x == true))),
        Expanded(child: Text(t, style: const TextStyle(fontWeight: FontWeight.bold))),
      ]));

  List<Widget> _pls() {
    final pl = s.plsList();
    return [
      _groupHead('Point last seen (PLS) · immer sichtbar', null, null),
      if (pl.isEmpty) const Padding(padding: EdgeInsets.only(left: 40), child: Mut('Kein PLS gesetzt')),
      ...pl.map((p) => _item(
          sw: _swatch('', pls: true, off: !p.on),
          name: p.label,
          info: '${s.fcs(p.lat, p.lon, '', ' · ')}Drift ab ${fmtDT(p.at ?? jn(s.inc?['pls_at']))}${p.on ? '' : ' · zählt nicht fürs Suchgebiet'}',
          faded: !p.on,
          acts: [
            smallBtn('Zeigen', () {
              map.follow(null);
              map.centerOn(LatLng(p.lat, p.lon));
              _afterShow();
            }),
            ?navBtn(s, p.lat, p.lon, p.label),
          ])),
    ];
  }

  Widget _placeRow(J f) => _item(
      check: Checkbox(value: !s.poiHide.contains('f${f['id']}'), onChanged: (v) => s.setPoiHidden(['f${f['id']}'], v != true)),
      sw: _swatch(js(f['color']).isEmpty ? _placeCol : js(f['color'])),
      name: js(f['name']),
      info: placeInfo(s, f),
      acts: [smallBtn('Zeigen', () => _showPlace(f)), ?navBtn(s, f['lat'], f['lon'], js(f['name']))]);

  List<Widget> _places() {
    final fs = s.places;
    if (fs.isEmpty) return [];
    final cs = placeCatsUsed(s), withCat = cs.any((c) => c.id != 0);
    return [
      _groupHead('Feste Standorte (${fs.length})', fs.every((f) => !s.poiHide.contains('f${f['id']}')),
          (v) => s.setPoiHidden(fs.map((f) => 'f${f['id']}'), !v)),
      if (withCat)
        for (final c in cs) ...[
          Padding(
              padding: const EdgeInsets.only(left: 20, top: 4),
              child: Row(children: [
                Checkbox(
                    value: pcOn(s, c.id),
                    onChanged: (v) {
                      setPlaceCat(s, [c.id], v == true);
                      if (v == true && !s.vis('place')) s.setVis(['place'], true);
                    }),
                Expanded(child: Text('${c.name} (${c.n})', style: const TextStyle(fontWeight: FontWeight.w600))),
              ])),
          ...fs.where((f) => (ji(f['cat_id']) ?? 0) == c.id || (c.id == 0 && !cs.any((x) => x.id != 0 && x.id == ji(f['cat_id'])))).map(_placeRow),
        ]
      else
        ...fs.map(_placeRow),
    ];
  }

  Widget _poiRow(J p) {
    final id = '${p['id']}', c = poiPt(p);
    return _item(
        check: Checkbox(value: !s.poiHide.contains(id), onChanged: (v) => s.setPoiHidden([id], v != true)),
        sw: p['sym'] != null ? Padding(padding: const EdgeInsets.symmetric(horizontal: 2), child: symPic(s, js(p['sym']), size: 24)) : _swatch(js(p['color'])),
        name: js(p['name']),
        info: _poiInfo(s, p),
        acts: [
          smallBtn('Zeigen', () => _showPoi(p)),
          if (c != null) ?navBtn(s, c.latitude, c.longitude, js(p['name'])),
          if (s.canManage)
            IconButton(
                tooltip: 'Für alle entfernen', visualDensity: VisualDensity.compact, icon: const Icon(Icons.close, size: 20), onPressed: () => _remove(p)),
        ]);
  }

  List<Widget> _poiGroups() {
    final ps = s.pois, out = <Widget>[];
    for (final (k, t) in [('point', 'Markierungen'), ('area', 'Bereiche')]) {
      final items = ps.where((p) => (p['kind'] == 'area' ? 'area' : 'point') == k).toList();
      out.add(_groupHead('$t (${items.length})', items.isNotEmpty && items.every((p) => !s.poiHide.contains('${p['id']}')),
          items.isEmpty ? null : (v) => s.setPoiHidden(items.map((p) => '${p['id']}'), !v)));
      if (items.isEmpty) out.add(const Padding(padding: EdgeInsets.only(left: 40), child: Mut('Keine')));
      out.addAll(items.map(_poiRow));
    }
    return out;
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
      listenable: s,
      builder: (context, _) {
        final inc = s.inc != null, form = inc && s.canManage && s.feat('poi');
        final body = [
          if (inc) ..._pls(),
          ..._places(),
          if (inc && s.feat('poi')) ..._poiGroups(),
        ];
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          PanelHead(inc ? 'Markierungen' : 'Feste Standorte', onClose: () => map.openPanel(null)),
          Expanded(
              child: ListView(
                  padding: const EdgeInsets.fromLTRB(4, 0, 8, 12),
                  children: body.isEmpty ? [const Padding(padding: EdgeInsets.all(12), child: Mut('Keine Einträge.'))] : body)),
          if (form)
            Container(
                decoration: BoxDecoration(border: Border(top: BorderSide(color: Theme.of(context).dividerColor))),
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
                child: Wrap(spacing: 8, children: [
                  FilledButton(onPressed: () => _newPoi('point'), child: const Text('+ Markierung')),
                  FilledButton(onPressed: () => _newPoi('area'), child: const Text('+ Bereich')),
                ])),
        ]);
      });
}
