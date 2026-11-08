// Native Einsatz-App eines Kontos (Gegenstück zu web/index.html, ohne WebView): Karte im Vollbild (flutter_map, drehbar),
// Einsatzkräfte, Externe, Rettlinge, PLS, Suchgebiet, Markierungen und Bereiche, feste Standorte, Nachrichten-Markierungen,
// Helikopter, eigener Standort; Kartenknöpfe rechts unten, Fenster «Ansicht», Seitenfenster und ☰-Menü (menu.dart).
// Alarme crit/high als Vollbild mit Quittieren, Hinweise als Snackbar. Punktwahl und Bereich zeichnen für die Fenster (MapCtl).
import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../accounts.dart';
import '../home.dart' show LoginPage, snack;
import '../monitor.dart';
import '../push.dart';
import '../service.dart';
import '../sys.dart';
import 'ctx.dart';
import 'menu.dart';
import 'msgs.dart';
import 'people.dart';
import 'pois.dart';
import 'prefs.dart';
import 'rd.dart';
import 'res.dart';
import 'store.dart';
import 'util.dart';

const _fitPad = EdgeInsets.fromLTRB(50, 90, 80, 50);
const _homeWaitMs = 20000, _areaWaitMs = 30000;

class IncPage extends StatefulWidget {
  const IncPage({super.key, required this.account});
  final Account account;

  /// Konto, dessen Einsatz-App gerade offen ist (openAccount öffnet nicht doppelt)
  static String? openId;

  /// Konto, dessen Einsatz-App nach dem Antippen einer Meldung das Einsatzgebiet zeigen soll (openAccount mit focus)
  static final focusReq = ValueNotifier<String?>(null);

  @override
  State<IncPage> createState() => _IncPageState();
}

class _IncPageState extends State<IncPage> implements MapCtl {
  late final IncStore s = IncStore(widget.account);
  final mc = MapController();
  final rd = RdState();
  final _sk = GlobalKey<ScaffoldState>();
  StreamSubscription<Toast>? _tSub;
  StreamSubscription<String>? _eSub;
  bool _mapReady = false, _closing = false, _fold = false, _camOpen = false, _noteOpen = false;
  String? _panel;
  int? _follow, _fitted;
  List<String>? _pinfo; // Personeninfo (Issue #105): 'u<user_id>' bzw. 'x<id der Externen>'
  int? _areaFocus; // seit wann das Einsatzgebiet gezeigt werden soll (focusArea)
  double _rot = 0;

  // Punktwahl und Bereich zeichnen
  String? _pickHint;
  Completer<LatLng?>? _pick;
  Completer<List<LatLng>?>? _draw;
  final _drawPts = <LatLng>[];
  Color _drawCol = accOrange;

  // Startansicht ohne Einsatz (homeView)
  bool _homeDone = false, _homeBusy = false;
  int _homeAt = 0;
  bool _autoBusy = false;
  String _autoKey = '';

  // Helikopter
  J? _air;
  bool _airBusy = false;
  int _airLast = 0;

  @override
  void initState() {
    super.initState();
    IncPage.openId = widget.account.id;
    _homeAt = DateTime.now().millisecondsSinceEpoch;
    _tSub = s.toasts.stream.listen((t) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).hideCurrentSnackBar();
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          backgroundColor: t.kind == 'err' ? accRed : (t.kind == 'ok' ? const Color(0xFF1E7A4C) : null),
          content: Text(t.title == null ? t.text : '${t.title}\n${t.text}'),
          duration: Duration(seconds: t.kind == 'err' ? 6 : 4)));
    });
    _eSub = s.events.stream.listen((e) {
      if (e == 'inc') _fitted = null;
    });
    s.addListener(_onStore);
    IncPage.focusReq.addListener(_onFocusReq);
    _onFocusReq();
    s.start();
    WidgetsBinding.instance.addPostFrameCallback((_) => _askLocation());
  }

  @override
  void dispose() {
    if (IncPage.openId == widget.account.id) IncPage.openId = null;
    IncPage.focusReq.removeListener(_onFocusReq);
    s.removeListener(_onStore);
    _tSub?.cancel();
    _eSub?.cancel();
    s.dispose();
    rd.dispose();
    super.dispose();
  }

  /// Standort gleich beim Öffnen erlauben lassen; danach einmalig um «Immer zulassen» bitten (Teilen bei gesperrtem Bildschirm, track.dart).
  Future<void> _askLocation() async {
    if (await requestLocation() != locInUse || !mounted) return;
    if (await prefs.getBool('bgAsked') == true || !mounted) return;
    await prefs.setBool('bgAsked', true);
    if (!mounted) return;
    final go = await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
              title: const Text('Standort «Immer zulassen»?'),
              content: Text('Damit die Einsatzleitung deinen Standort im Einsatz auch bei gesperrtem Bildschirm oder geschlossener App sieht, '
                  'braucht die App den Standort «Immer». Geteilt wird nur, solange du an einem Einsatz teilnimmst und deine Einrückoption den Standort teilt.\n\n'
                  '${Platform.isAndroid ? 'Wähle auf der nächsten Seite «Immer zulassen».' : 'Wähle «Immer erlauben» bzw. in den Einstellungen «Immer».'} Ändern kannst du es jederzeit in den Einstellungen der App.'),
              actions: [
                TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Später')),
                FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Weiter')),
              ],
            ));
    if (go == true) {
      await requestBackgroundLocation();
      await syncService();
    }
  }

  // ---------- Abmelden ----------
  Future<void> _logout({required bool byServer}) async {
    if (_closing) return;
    _closing = true;
    final a = widget.account;
    await pushForget(a);
    if (!byServer) await a.api.logout();
    await Accounts.update(a.id, (x) => x.loggedOut = true);
    await syncService();
    if (!mounted) return;
    final nav = Navigator.of(context);
    nav.popUntil((r) => r.isFirst);
    if (byServer) {
      nav.push(MaterialPageRoute(builder: (_) => LoginPage(relogin: a)));
      snack(nav.context, 'Bei ${a.label} abgemeldet. Bitte neu anmelden.');
    }
  }

  // ---------- Reaktion auf neuen Stand ----------
  void _onStore() {
    if (!mounted) return;
    if (s.unauth) {
      _logout(byServer: true);
      return;
    }
    if (_follow != null) {
      final p = _partOf(_follow!);
      if (p == null) {
        _follow = null;
      } else if (_mapReady) {
        mc.move(llOf(p)!, mc.camera.zoom);
      }
    }
    if (_mapReady) {
      final i = s.inc;
      if (_areaFocus != null) _focusNow();
      if (i != null && _fitted != ji(i['id'])) _startView(i);
      if (i == null) _homeView();
      if (s.autoView) _autoFit();
      _airPoll();
    }
    setState(() {});
  }

  J? _partOf(int uid) =>
      s.participants.where((p) => ji(p['user_id']) == uid && p['lat'] != null && p['left_at'] == null && p['mode'] != 'declined').firstOrNull;

  List<J> get _actParts => s.participants.where((p) => p['lat'] != null && p['left_at'] == null && p['mode'] != 'declined').toList();
  List<J> get _exts => s.externals.where((x) => x['lat'] != null && x['removed'] != 1 && x['removed'] != true).toList();

  // Startansicht beim Öffnen des Einsatzes: alle aktiven Positionen (startView)
  void _startView(J i) {
    if (s.autoView) {
      _fitted = ji(i['id']);
      return;
    }
    final pts = <LatLng>[
      ..._actParts.map((p) => llOf(p)!),
      ..._exts.map((p) => llOf(p)!),
      ...s.rescuees.where((r) => r['lat'] != null && r['rescued_at'] == null).map((r) => llOf(r)!),
      ...s.plsList().where((p) => p.on).map((p) => LatLng(p.lat, p.lon)),
    ];
    if (pts.isEmpty) {
      final p = s.plsList().firstOrNull;
      if (p == null) return;
      pts.add(LatLng(p.lat, p.lon));
    }
    _fitted = ji(i['id']);
    _homeDone = true;
    _fitRaw(pts);
  }

  // Startansicht ohne Einsatz: eigener Standort und eingeblendete feste Standorte (homeView)
  void _homeView() {
    if (_homeDone) return;
    final me = s.selfAt;
    final pl = s.vis('place') ? s.places.where((p) => placeShown(s, p)).expand((p) => placePts(p)).toList() : <LatLng>[];
    final pts = [?me, ...pl];
    if (pts.isEmpty) return;
    _homeBusy = true;
    _fitRaw(pts);
    _homeBusy = false;
    if (me != null || DateTime.now().millisecondsSinceEpoch - _homeAt > _homeWaitMs) _homeDone = true;
  }

  List<LatLng> _overviewPts() => [
        if (s.vis('app')) ..._actParts.map((p) => llOf(p)!),
        if (s.vis('ext')) ..._exts.map((p) => llOf(p)!),
        if (s.vis('res')) ...s.rescuees.where((r) => r['lat'] != null && r['rescued_at'] == null).map((r) => llOf(r)!),
        if (s.vis('pls')) ...s.plsList().where((p) => p.on).map((p) => LatLng(p.lat, p.lon)),
      ];

  void _autoFit() {
    final pts = _overviewPts();
    final k = pts.map((p) => '${p.latitude.toStringAsFixed(5)},${p.longitude.toStringAsFixed(5)}').join(';');
    if (k == _autoKey || pts.isEmpty) return;
    _autoKey = k;
    _autoBusy = true;
    _fitRaw(pts);
    _autoBusy = false;
  }

  void _fitRaw(List<LatLng> pts) {
    if (!_mapReady || pts.isEmpty) return;
    if (pts.length == 1 || pts.every((p) => geoDist(p, pts.first) < 20)) {
      mc.move(pts.first, 15);
    } else {
      mc.fitCamera(CameraFit.coordinates(coordinates: pts, padding: _fitPad, maxZoom: 17));
    }
  }

  void _stopFollow() {
    if (_follow != null || s.autoView) {
      _follow = null;
      if (s.autoView) s.setAuto(false);
      setState(() {});
    }
  }

  // ---------- MapCtl ----------
  @override
  void centerOn(LatLng p, {double? zoom}) {
    _stopFollow();
    mc.move(p, zoom ?? math.max(mc.camera.zoom, 15));
  }

  @override
  void fitPts(List<LatLng> pts) {
    _stopFollow();
    _fitRaw(pts);
  }

  @override
  void follow(int? userId) {
    if (s.autoView) s.setAuto(false);
    setState(() => _follow = userId);
    if (userId != null) {
      final p = _partOf(userId);
      if (p != null) mc.move(llOf(p)!, mc.camera.zoom);
    }
  }

  @override
  int? get followId => _follow;

  @override
  Future<LatLng?> pickPoint(String hint) {
    _pick?.complete(null);
    final c = Completer<LatLng?>();
    _closeOverlays();
    setState(() {
      _pick = c;
      _pickHint = hint;
    });
    return c.future;
  }

  @override
  Future<List<LatLng>?> drawArea(String hint, {Color color = accOrange}) {
    _draw?.complete(null);
    final c = Completer<List<LatLng>?>();
    _closeOverlays();
    setState(() {
      _draw = c;
      _drawPts.clear();
      _drawCol = color;
      _pickHint = hint;
    });
    return c.future;
  }

  void _closeOverlays() {
    if (_sk.currentState?.isDrawerOpen == true) Navigator.of(context).pop();
    _panel = null;
    _camOpen = false;
  }

  /// Seitenfenster zeigen (null = schliessen). Kein Umschalten: nach dem Speichern rufen die Fenster openPanel mit dem
  /// eigenen Fenster auf, das dabei offen bleiben muss (Issue #151: sonst schloss es sich, und der Dialog für den PLS kam nicht).
  @override
  void openPanel(String? p) {
    if (p != null && _sk.currentState?.isDrawerOpen == true) Navigator.of(context).pop();
    setState(() => _panel = p);
  }

  /// Kartenknöpfe: offenes Fenster schliessen, sonst öffnen
  void _togglePanel(String p) => openPanel(_panel == p ? null : p);

  @override
  BuildContext get ctx => context;

  @override
  void closeMenu() {
    if (_sk.currentState?.isDrawerOpen == true) Navigator.of(context).pop();
  }

  /// Eckpunkte des Suchgebiets (key = Teilgebiet), leer ohne berechnetes Gebiet
  List<LatLng> _areaPts([String? key]) {
    final a = s.area;
    final pts = <LatLng>[];
    if (a == null) return pts;
    void add(List<List<LatLng>> polys, List<({LatLng c, double r})> circles) {
      for (final pl in polys) {
        pts.addAll(pl);
      }
      for (final c in circles) {
        final d = c.r / 111320;
        pts.addAll([
          LatLng(c.c.latitude + d, c.c.longitude),
          LatLng(c.c.latitude - d, c.c.longitude),
          LatLng(c.c.latitude, c.c.longitude + d / math.cos(deg2rad(c.c.latitude))),
          LatLng(c.c.latitude, c.c.longitude - d / math.cos(deg2rad(c.c.latitude))),
        ]);
      }
    }

    if (key == null) {
      add(a.polys, a.circles);
    } else {
      final p = a.parts.where((x) => x.key == key).firstOrNull;
      if (p != null) add(p.polys, p.circles);
    }
    return pts;
  }

  @override
  void fitArea([String? key]) {
    final pts = _areaPts(key);
    if (pts.isEmpty) {
      s.toast('Kein Suchgebiet', 'info');
      return;
    }
    fitPts(pts);
    if (MediaQuery.of(context).size.width < 760) setState(() => _panel = null);
  }

  // Einsatzgebiet zeigen (Feedback zu #108, wie focusArea in index.html): nach dem Antippen einer Meldung und nach dem Annehmen.
  // Suchgebiet mit den PLS, ohne Einsatzkräfte; solange das Suchgebiet noch berechnet wird, die PLS. Wartet höchstens _areaWaitMs,
  // ersetzt die Startansicht und endet beim Verschieben der Karte von Hand.
  void _onFocusReq() {
    if (IncPage.focusReq.value != widget.account.id) return;
    IncPage.focusReq.value = null;
    focusArea();
  }

  @override
  void focusArea() {
    _areaFocus = DateTime.now().millisecondsSinceEpoch;
    if (mounted && MediaQuery.of(context).size.width < 760) {
      _sk.currentState?.closeDrawer();
      setState(() => _panel = null);
    }
    _focusNow();
  }

  void _focusNow() {
    final i = s.inc;
    if (_areaFocus == null || !_mapReady || i == null) return;
    if (DateTime.now().millisecondsSinceEpoch - _areaFocus! > _areaWaitMs) {
      _areaFocus = null;
      return;
    }
    final area = s.feat('area') ? _areaPts() : <LatLng>[];
    final pls = s.plsList(), on = pls.where((p) => p.on).toList();
    final pts = [...area, ...(on.isNotEmpty ? on : pls).map((p) => LatLng(p.lat, p.lon))];
    if (pts.isEmpty) return; // noch kein PLS: beim nächsten Polling
    if (area.isNotEmpty || !s.feat('area')) _areaFocus = null;
    _fitted = ji(i['id']);
    _homeDone = true;
    _follow = null;
    if (s.autoView) s.setAuto(false);
    _fitRaw(pts);
  }

  @override
  void openRd() => openPanel('rd');

  @override
  void personInfo(String key) {
    final p = _pinfoItems([key]).firstOrNull;
    if (p == null) return;
    _stopFollow();
    centerOn(llOf(p.$1)!);
    setState(() => _pinfo = [key]);
  }

  // ---------- Helikopter ----------
  bool get _airOn => s.inc != null && jb(s.st?['air']);

  void _airPoll() {
    if (!_airOn || !s.vis('air')) {
      _air = null;
      return;
    }
    final n = DateTime.now().millisecondsSinceEpoch;
    if (_airBusy || n - _airLast < 3500) return;
    _airBusy = true;
    _airLast = n;
    s.api.call('incident/air').then((d) => _air = d).catchError((e) => _air = {'air': [], 'err': '$e'}).whenComplete(() {
      _airBusy = false;
      if (mounted) setState(() {});
    });
  }

  // ---------- Kartentipp ----------
  void _onTap(LatLng p) {
    if (_draw != null) {
      setState(() => _drawPts.add(p));
      return;
    }
    if (_pick != null) {
      final c = _pick!;
      setState(() {
        _pick = null;
        _pickHint = null;
      });
      c.complete(p);
      return;
    }
    if (_camOpen) setState(() => _camOpen = false);
    if (_pinfo != null) setState(() => _pinfo = null); // Antippen der leeren Karte schliesst die Personeninfo
  }

  void _cancelPick() {
    final c = _pick, d = _draw;
    setState(() {
      _pick = null;
      _draw = null;
      _pickHint = null;
    });
    c?.complete(null);
    d?.complete(null);
  }

  void _finishDraw() {
    if (_drawPts.length < 3) {
      s.toast('Mindestens drei Eckpunkte antippen', 'info');
      return;
    }
    final d = _draw!;
    final pts = List<LatLng>.from(_drawPts);
    setState(() {
      _draw = null;
      _pickHint = null;
      _drawPts.clear();
    });
    d.complete(pts);
  }

  bool get _picking => _pick != null || _draw != null;

  // ---------- Info-Blatt (statt Popup) ----------
  Future<void> _sheet(String title, List<String> lines, {double? lat, double? lon, String? navName, List<Widget> Function(BuildContext)? actions}) async {
    if (_picking) return;
    await showModalBottomSheet(
        context: context,
        showDragHandle: true,
        builder: (c) => SafeArea(
            child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text(title, style: Theme.of(c).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 4),
                  ...lines.where((l) => l.isNotEmpty).map((l) => Padding(padding: const EdgeInsets.only(top: 2), child: Text(l))),
                  if (lat != null && lon != null && s.fc(lat, lon).isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Mut(s.fc(lat, lon))),
                  const SizedBox(height: 10),
                  Wrap(spacing: 8, runSpacing: 6, children: [
                    if (lat != null && lon != null && s.feat('nav'))
                      FilledButton(
                          onPressed: () {
                            Navigator.pop(c);
                            navGo(s, lat, lon, navName ?? title);
                          },
                          child: Text('🧭 Mit ${navApps[navApp(s)]} navigieren')),
                    ...?actions?.call(c),
                  ]),
                ]))));
  }

  void _navAsk(double lat, double lon, String name, [List<String> lines = const []]) => _sheet(name, lines, lat: lat, lon: lon);

  // ---------- Personeninfo (Issue #105, Funktion «pinfo», wie pinfo in index.html) ----------
  // Nicht blockierend: Karte als Marker über der Person, fährt bei jeder Aktualisierung mit, Karte und übrige Elemente bleiben bedienbar.
  // Schliesst über ✕, Antippen der leeren Karte und wenn die Person den Einsatz verlässt, ausgeblendet wird oder keinen Standort mehr hat.
  String _pinfoK(J p, bool ext) => ext ? 'x${ji(p['id'])}' : 'u${ji(p['user_id'])}';

  void _pinfoOpen(List<(J, bool)> items) {
    if (_picking) return;
    setState(() => _pinfo = items.map((x) => _pinfoK(x.$1, x.$2)).toList());
  }

  List<(J, bool)> _pinfoItems(List<String> keys) {
    final out = <(J, bool)>[];
    if (!s.feat('pinfo') || s.inc == null) return out;
    for (final k in keys) {
      final ext = k.startsWith('x');
      final p = ext
          ? (s.vis('ext') ? _exts.where((e) => _pinfoK(e, true) == k).firstOrNull : null)
          : (s.vis('app') ? _actParts.where((e) => _pinfoK(e, false) == k).firstOrNull : null);
      if (p != null) out.add((p, ext));
    }
    return out;
  }

  Widget _pinfoOne(J p, bool ext) {
    final r = s.roleOf(p['role_id']), uid = ji(p['user_id']), f = !ext && uid != null && _follow == uid;
    final gs = jl(s.st?['groups']), gids = p['groups'] is List ? (p['groups'] as List).map(ji).toList() : const <int?>[];
    final g = ext ? <J>[] : gids.map((id) => gs.where((x) => ji(x['id']) == id).firstOrNull).whereType<J>().toList(); // alle Gruppen, unabhängig von «Farbe beim Namen»
    final sub = [r != null ? js(r['name']) : 'Ohne Spezialrolle', ext ? 'Externe Einsatzkraft' : js(s.modeOf(js(p['mode']))['name'])].where((x) => x.isNotEmpty).join(' · ');
    final loc = [ext ? 'Fester Standort' : 'Standort ${agoS(s.now, jn(p['loc_at']) ?? s.now)}', s.fc(p['lat'], p['lon'])].where((x) => x.isNotEmpty).join(' · ');
    final nav = navBtn(s, p['lat'], p['lon'], js(p['name']));
    return Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
      Row(children: [
        ext ? _personMk(p, ext: true) : avatar(p, r: 22, border: _roleCol(p)),
        const SizedBox(width: 8),
        Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(js(p['name']), style: const TextStyle(fontWeight: FontWeight.bold)),
          Row(children: [
            if (r != null) Padding(padding: const EdgeInsets.only(right: 4), child: Icon(Icons.circle, size: 10, color: hexColor(r['color']))),
            Expanded(child: Mut(sub)),
          ]),
        ])),
      ]),
      if (g.isNotEmpty)
        Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Wrap(
                spacing: 6,
                runSpacing: 4,
                children: g
                    .map((x) => Container(
                        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                        decoration: BoxDecoration(borderRadius: BorderRadius.circular(999), border: Border.all(color: Colors.white24)),
                        child: Row(mainAxisSize: MainAxisSize.min, children: [
                          Container(width: 10, height: 10, decoration: BoxDecoration(color: hexColor(x['color']), shape: BoxShape.circle, border: Border.all(color: Colors.black38))),
                          const SizedBox(width: 4),
                          Text(js(x['name']), style: const TextStyle(fontSize: 12)),
                        ])))
                    .toList())),
      if (ext && js(p['descr']).isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(js(p['descr']))),
      Padding(padding: const EdgeInsets.only(top: 4), child: Mut(loc)),
      if (!ext || nav != null)
        Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Wrap(spacing: 6, runSpacing: 4, children: [
              if (!ext && uid != null) smallBtn(f ? 'Folgt' : 'Folgen', () => follow(f ? null : uid), primary: f),
              ?nav,
            ])),
    ]);
  }

  Widget? _pinfoLayer() {
    final it = _pinfo == null ? <(J, bool)>[] : _pinfoItems(_pinfo!);
    if (it.isEmpty) {
      _pinfo = null;
      return null;
    }
    final parts = <Widget>[];
    for (final x in it) {
      if (parts.isNotEmpty) parts.add(const Divider(height: 16));
      parts.add(_pinfoOne(x.$1, x.$2));
    }
    return MarkerLayer(rotate: true, markers: [
      Marker(
          point: llOf(it.first.$1)!,
          width: 290,
          height: 380,
          alignment: Alignment.topCenter,
          child: Align(
              alignment: Alignment.bottomCenter,
              child: Padding(
                  padding: const EdgeInsets.only(bottom: 22),
                  child: GestureDetector(
                      onTap: () {}, // Tipps im Fenster gehen nicht an die Karte (würde es schliessen)
                      child: Material(
                          elevation: 6,
                          color: Theme.of(context).colorScheme.surfaceContainerHigh,
                          borderRadius: BorderRadius.circular(10),
                          child: ConstrainedBox(
                              constraints: const BoxConstraints(maxHeight: 340, maxWidth: 280, minWidth: 200),
                              child: Stack(children: [
                                SingleChildScrollView(padding: const EdgeInsets.fromLTRB(10, 8, 34, 10), child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: parts)),
                                Positioned(
                                    top: 0,
                                    right: 0,
                                    child: IconButton(
                                        tooltip: 'Infofenster schliessen',
                                        icon: const Icon(Icons.close, size: 18),
                                        visualDensity: VisualDensity.compact,
                                        onPressed: () => setState(() => _pinfo = null))),
                              ])))))))
    ]);
  }

  // ---------- Ebenen ----------
  Color _roleCol(J p) {
    final r = s.roleOf(p['role_id']);
    if (r != null) return hexColor(r['color']);
    return js(p['color']).isNotEmpty ? hexColor(p['color']) : const Color(0xFF8FB0BD);
  }

  String _ini(J p, {bool ext = false}) {
    if (ext && js(p['abbr']).isNotEmpty) return js(p['abbr']);
    final n = js(p['name']).trim().split(RegExp(r'\s+')).where((x) => x.isNotEmpty).map((x) => x[0]).join();
    return (n.length > 2 ? n.substring(0, 2) : n).toUpperCase();
  }

  Widget _personMk(J p, {bool ext = false}) {
    final col = _roleCol(p), fill = ext && js(p['color']).isNotEmpty ? hexColor(p['color']) : appBlue;
    final ph = js(p['photo']);
    if (!ext && ph.startsWith('data:image/')) return avatar(p, r: 16, border: col);
    final t = _ini(p, ext: ext);
    return Container(
        width: 36,
        height: 36,
        alignment: Alignment.center,
        decoration: BoxDecoration(
            color: fill,
            shape: ext ? BoxShape.rectangle : BoxShape.circle,
            borderRadius: ext ? BorderRadius.circular(6) : null,
            border: Border.all(color: col, width: 3),
            boxShadow: const [BoxShadow(color: Colors.black45, blurRadius: 3)]),
        child: Text(t, style: TextStyle(color: fgOn(fill), fontWeight: FontWeight.bold, fontSize: t.length > 2 ? 10 : 13)));
  }

  String _roleName(J p) => js(s.roleOf(p['role_id'])?['name']);

  Widget _peopleLayer(BuildContext c) {
    final cam = MapCamera.of(c);
    final act = <(J, bool)>[
      if (s.vis('app')) ..._actParts.where((p) => ji(p['user_id']) != s.uid).map((p) => (p, false)),
      if (s.vis('ext')) ..._exts.map((p) => (p, true)),
    ];
    final groups = <(Offset, List<(J, bool)>)>[];
    for (final a in act) {
      final o = cam.latLngToScreenOffset(llOf(a.$1)!);
      final g = groups.where((g) => (g.$1 - o).distance < 40).firstOrNull;
      g != null ? g.$2.add(a) : groups.add((o, [a]));
    }
    return MarkerLayer(
        markers: groups.map((g) {
      final a = g.$2.first.$1, ll = llOf(a)!;
      if (g.$2.length == 1) {
        final ext = g.$2.first.$2;
        return Marker(
            point: ll,
            width: 40,
            height: 40,
            child: GestureDetector(
                onTap: () => s.feat('pinfo')
                    ? _pinfoOpen(g.$2)
                    : _navAsk(ll.latitude, ll.longitude, js(a['name']), [
                        [_roleName(a), if (ext) 'Externe Einsatzkraft' else js(s.modeOf(js(a['mode']))['name'])].where((x) => x.isNotEmpty).join(' · '),
                        if (!ext && a['loc_at'] != null) 'Standort ${agoS(s.now, jn(a['loc_at'])!)}',
                      ]),
                child: Tooltip(message: js(a['name']), child: _personMk(a, ext: ext))));
      }
      final same = g.$2.every((x) => geoDist(llOf(x.$1)!, ll) <= 30);
      final names = g.$2.map((x) => js(x.$1['name'])).join(', ');
      return Marker(
          point: ll,
          width: 40,
          height: 40,
          child: GestureDetector(
              onTap: () => same ? (s.feat('pinfo') ? _pinfoOpen(g.$2) : _navAsk(ll.latitude, ll.longitude, names)) : fitPts(g.$2.map((x) => llOf(x.$1)!).toList()),
              child: Container(
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: appBlue, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 3)),
                  child: Text('${g.$2.length}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)))));
    }).toList());
  }

  Widget _label(String t, {Color? bg}) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
      decoration: BoxDecoration(color: (bg ?? Colors.black).withValues(alpha: .65), borderRadius: BorderRadius.circular(4)),
      child: Text(t, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 11)));

  Marker _dotMk(LatLng p, Widget dot, String? label, VoidCallback onTap, {double w = 120}) => Marker(
      point: p,
      width: w,
      height: 56,
      alignment: Alignment.center,
      child: GestureDetector(
          onTap: onTap,
          behavior: HitTestBehavior.translucent,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const SizedBox(height: 10),
            dot,
            if (label != null && label.isNotEmpty) _label(label),
          ])));

  List<Widget> _layers(BuildContext c) {
    final i = s.inc, out = <Widget>[];
    final cs = s.csys;
    // feste Standorte
    if (s.vis('place')) {
      final pl = s.places.where((p) => placeShown(s, p) && !s.poiHide.contains('f${p['id']}')).toList();
      final lbl = pcLbl(s);
      final areas = pl.where(placeArea).toList(), lines = pl.where(placeLine).toList();
      // feste Linien (Issue #134, z. B. Fahrrinnen): breiter gestrichelter Strich, Marker und Navigation auf halber Länge
      if (lines.isNotEmpty) {
        out.add(PolylineLayer(
            polylines: lines
                .map((p) =>
                    Polyline(points: placePts(p), color: hexColor(p['color']), strokeWidth: 4, pattern: StrokePattern.dashed(segments: const [14, 7])))
                .toList()));
      }
      if (areas.isNotEmpty) {
        out.add(PolygonLayer(
            polygons: areas
                .map((p) => Polygon(
                    points: placePts(p),
                    color: hexColor(p['color']).withValues(alpha: .15),
                    borderColor: hexColor(p['color']),
                    borderStrokeWidth: 2,
                    pattern: StrokePattern.dashed(segments: const [8, 6])))
                .toList()));
      }
      out.add(MarkerLayer(
          markers: pl.map((p) {
        final ll = llOf(p)!, col = hexColor(p['color']), area = placeArea(p), line = placeLine(p);
        return _dotMk(
            ll,
            area || line
                ? Icon(line ? Icons.timeline : Icons.crop_square, size: 18, color: col)
                : Container(
                    width: 28,
                    height: 22,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: col, borderRadius: BorderRadius.circular(5), border: Border.all(color: Colors.white, width: 2)),
                    child: Text(js(p['abbr']).isEmpty ? '⌂' : js(p['abbr']), style: TextStyle(color: fgOn(col), fontSize: 10, fontWeight: FontWeight.bold))),
            lbl ? js(p['name']) : null,
            () => _sheet(js(p['name']), [js(p['description']), 'Fester Standort'], lat: ll.latitude, lon: ll.longitude));
      }).toList()));
    }
    if (i == null) return out;
    // Suchgebiet
    final a = s.area;
    if (a != null && s.vis('area')) {
      if (a.circles.isNotEmpty) {
        out.add(CircleLayer(
            circles: a.circles
                .map((x) => CircleMarker(
                    point: x.c, radius: x.r, useRadiusInMeter: true, color: accRed.withValues(alpha: .12), borderColor: accRed, borderStrokeWidth: 2))
                .toList()));
      }
      if (a.polys.isNotEmpty) {
        out.add(PolygonLayer(
            polygons: a.polys.map((pl) => Polygon(points: pl, color: accRed.withValues(alpha: .28), borderColor: accRed, borderStrokeWidth: 1)).toList()));
      }
      if (a.lines.isNotEmpty) out.add(PolylineLayer(polylines: a.lines.map((l) => Polyline(points: l, color: accRed, strokeWidth: 3)).toList()));
    }
    // Bereiche und Markierungen
    final ps = s.pois.where((p) => p['removed'] != 1 && p['removed'] != true && !s.poiHide.contains('${p['id']}')).toList();
    final ars = ps.where((p) => js(p['kind']) == 'area' && s.vis('ar')).toList(), pts = ps.where((p) => js(p['kind']) != 'area' && s.vis('pt')).toList();
    if (ars.isNotEmpty) {
      out.add(PolygonLayer(
          polygons: ars
              .map((p) => Polygon(points: poiPts(p), color: hexColor(p['color']).withValues(alpha: .25), borderColor: hexColor(p['color']), borderStrokeWidth: 2))
              .toList()));
    }
    out.add(MarkerLayer(markers: [
      ...ars.map((p) {
        final ll = poiPt(p)!;
        return _dotMk(ll, Icon(Icons.pentagon_outlined, size: 18, color: hexColor(p['color'])), js(p['label']).isEmpty ? js(p['name']) : js(p['label']),
            () => _poiSheet(p, ll));
      }),
      ...pts.map((p) {
        final ll = poiPt(p)!, col = hexColor(p['color']);
        return _dotMk(
            ll,
            Container(width: 18, height: 18, decoration: BoxDecoration(color: col, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 3))),
            js(p['label']).isEmpty ? js(p['name']) : js(p['label']),
            () => _poiSheet(p, ll));
      }),
    ]));
    // PLS
    if (s.vis('pls')) {
      out.add(MarkerLayer(
          markers: s
              .plsList()
              .map((p) => Marker(
                  point: LatLng(p.lat, p.lon),
                  width: 26,
                  height: 26,
                  child: GestureDetector(
                      onTap: () => _navAsk(p.lat, p.lon, p.label, ['Beginn der Drift ${fmtDT(p.at ?? jn(i['alarm_at']))}', if (!p.on) 'zählt nicht fürs Suchgebiet']),
                      child: Opacity(
                          opacity: p.on ? 1 : .45,
                          child: Container(
                              decoration: BoxDecoration(
                                  color: accRed, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 3), boxShadow: const [BoxShadow(color: Colors.black54, blurRadius: 3)]),
                              child: const Center(child: Icon(Icons.close, size: 14, color: Colors.white)))))))
              .toList()));
    }
    // Rettlinge und frühere Rettling-Markierungen
    if (s.vis('res') && s.feat('res')) {
      out.add(MarkerLayer(markers: [
        ...s.rescuees.where((r) => r['lat'] != null).map((r) {
          final ok = r['rescued_at'] != null, ll = llOf(r)!;
          return _dotMk(
              ll,
              Container(
                  width: 30,
                  height: 30,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: ok ? okGreen : accOrange, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 3)),
                  child: Text(ok ? '✓' : 'R', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold))),
              js(r['name']),
              () => _sheet(js(r['name']), [js(r['description']), ok ? 'Gerettet ${fmtT(jn(r['rescued_at']))}' : 'Standort bekannt'],
                  lat: ll.latitude, lon: ll.longitude, actions: (c) => [OutlinedButton(onPressed: () {
                        Navigator.pop(c);
                        openPanel('res');
                      }, child: const Text('Rettlinge'))]),
              w: 130);
        }),
        ...jl(s.st?['marks']).where((x) => x['lat'] != null).map((x) {
          final ll = llOf(x)!;
          return _dotMk(
              ll,
              Container(
                  width: 28,
                  height: 28,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: accRed, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 2)),
                  child: const Text('R', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold))),
              null,
              () => _navAsk(ll.latitude, ll.longitude, 'Rettling', ['Rettling – ${jb(x['manual']) ? 'manuell (Einsatzleitung)' : js(x['name'])} · ${fmtT(jn(x['ts']))}']));
        }),
      ]));
    }
    // Nachrichten-Markierungen
    if (s.vis('mm') && s.feat('msg')) {
      out.add(MarkerLayer(
          markers: jl(s.st?['msg_marks'])
              .map((x) => Marker(
                  point: llOf(x)!,
                  width: 30,
                  height: 30,
                  child: GestureDetector(
                      onTap: () => _sheet(js(x['from_name']), ['Nachricht · ${fmtT(jn(x['created']))}', js(x['text'])],
                          lat: jd(x['lat']),
                          lon: jd(x['lon']),
                          navName: 'Markierung von ${js(x['from_name'])}',
                          actions: (c) => [
                                if (ji(x['from_user']) == s.uid || s.canManage)
                                  OutlinedButton(
                                      onPressed: () async {
                                        Navigator.pop(c);
                                        if (await confirmDlg(context, 'Kartenmarkierung dieser Nachricht entfernen? Die Nachricht selbst bleibt erhalten.')) {
                                          await s.act('incident/message/unmark', {'id': x['id']});
                                        }
                                      },
                                      child: const Text('Markierung entfernen'))
                              ]),
                      child: Container(
                          alignment: Alignment.center,
                          decoration: BoxDecoration(color: const Color(0xFF1D7FD1), borderRadius: BorderRadius.circular(8), border: Border.all(color: Colors.white, width: 2)),
                          child: const Text('✉', style: TextStyle(color: Colors.white))))))
              .toList()));
    }
    // Einsatzkräfte und Externe (gruppiert)
    out.add(Builder(builder: _peopleLayer));
    // Personeninfo (Issue #105) über der Person
    final pi = _pinfoLayer();
    if (pi != null) out.add(pi);
    // Helikopter
    if (_airOn && s.vis('air') && _air != null) {
      final now = jn(_air!['now']) ?? s.now;
      out.add(MarkerLayer(
          markers: jl(_air!['air']).where((x) => x['lat'] != null).map((x) {
        final resc = js(x['rescue']).isNotEmpty, heli = resc || x['cat'] == 'A7';
        final col = resc ? const Color(0xFFE30613) : (heli ? const Color(0xFFFFD23F) : const Color(0xFF7FC8FF));
        final name = resc ? '${js(x['rescue'])}${js(x['cs']).isNotEmpty ? ' ${js(x['cs'])}' : ''}' : (js(x['cs']).isNotEmpty ? js(x['cs']) : (js(x['reg']).isNotEmpty ? js(x['reg']) : js(x['hex']).toUpperCase()));
        final alt = jb(x['gnd']) ? 'am Boden' : (x['alt'] == null ? 'Höhe ?' : '${((jd(x['alt']) ?? 0) * .3048).round()} m');
        final old = now - (jn(x['at']) ?? now) > 20;
        return Marker(
            point: llOf(x)!,
            width: 120,
            height: 50,
            child: GestureDetector(
                onTap: () => _sheet(name, [
                      resc ? 'Rettungshelikopter' : (heli ? 'Helikopter' : 'Luftfahrzeug'),
                      [js(x['type']), js(x['reg'])].where((e) => e.isNotEmpty).join(' · '),
                      'Höhe $alt${x['gs'] != null ? ' · ${((jd(x['gs']) ?? 0) * 1.852).round()} km/h' : ''}',
                      if (x['at'] != null) 'Stand ${fmtT(jn(x['at']))}',
                    ], lat: jd(x['lat']), lon: jd(x['lon'])),
                child: Opacity(
                    opacity: old ? .5 : 1,
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      Transform.rotate(
                          angle: deg2rad((jd(x['trk']) ?? 0)),
                          child: Icon(heli ? Icons.navigation : Icons.flight, color: col, size: 26, shadows: const [Shadow(color: Colors.black, blurRadius: 3)])),
                      _label('$name · $alt'),
                    ]))));
      }).toList()));
    }
    // Flussdistanz
    if (_panel == 'rd' || rd.a != null) out.addAll(rdLayers(rd));
    // Bereich zeichnen
    if (_draw != null && _drawPts.isNotEmpty) {
      out.add(PolylineLayer(polylines: [Polyline(points: [..._drawPts, if (_drawPts.length > 2) _drawPts.first], color: _drawCol, strokeWidth: 3)]));
      out.add(CircleLayer(circles: _drawPts.map((p) => CircleMarker(point: p, radius: 6, color: _drawCol, borderColor: Colors.white, borderStrokeWidth: 2)).toList()));
    }
    // eigener Standort (blauer Punkt)
    final me = s.selfAt;
    if (me != null) {
      final acc = s.manualPos == null ? s.devPos?.accuracy : null;
      out.add(CircleLayer(circles: [
        if (acc != null && acc > 5) CircleMarker(point: me, radius: acc, useRadiusInMeter: true, color: const Color(0x332196F3), borderColor: const Color(0x662196F3), borderStrokeWidth: 1),
        CircleMarker(point: me, radius: 8, color: const Color(0xFF2196F3), borderColor: Colors.white, borderStrokeWidth: 3),
      ]));
    }
    if (cs.isEmpty) return out;
    return out;
  }

  void _poiSheet(J p, LatLng ll) {
    final area = js(p['kind']) == 'area';
    _sheet(js(p['label']).isEmpty ? js(p['name']) : js(p['label']), [area ? 'Bereich' : 'Markierung', js(p['by_name']).isNotEmpty ? 'von ${js(p['by_name'])}' : ''],
        lat: ll.latitude,
        lon: ll.longitude,
        actions: (c) => [
              if (s.canManage)
                OutlinedButton(
                    onPressed: () async {
                      Navigator.pop(c);
                      if (await confirmDlg(context, '${area ? 'Bereich' : 'Markierung'} entfernen?', danger: true, ok: 'Entfernen')) {
                        await s.act('incident/poi/remove', {'id': p['id']});
                      }
                    },
                    child: const Text('Entfernen')),
            ]);
  }

  // ---------- Fenster «Ansicht» ----------
  void _viewBox() {
    showModalBottomSheet(
        context: context,
        showDragHandle: true,
        isScrollControlled: true,
        builder: (c) => ListenableBuilder(
            listenable: s,
            builder: (c, _) {
              final cats = s.inc != null ? s.viewCats() : (s.places.isNotEmpty ? s.viewCats().where((v) => v.$1 == 'place').toList() : <(String, String, String)>[]);
              return SafeArea(
                  child: ConstrainedBox(
                      constraints: BoxConstraints(maxHeight: MediaQuery.of(c).size.height * .75),
                      child: ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), children: [
                        Text('Grundkarte', style: Theme.of(c).textTheme.titleSmall),
                        RadioGroup<String>(
                            groupValue: s.base,
                            onChanged: (v) => v == null ? null : s.setBase(v),
                            child: Column(
                                children: mapProviders.entries.map((e) => RadioListTile<String>(dense: true, value: e.key, title: Text(e.value.$1))).toList())),
                        if (cats.isNotEmpty) ...[
                          const Divider(),
                          Text('Auf der Karte', style: Theme.of(c).textTheme.titleSmall),
                          for (final v in cats) ...[
                            CheckboxListTile(dense: true, value: s.vis(v.$1), onChanged: (x) => s.setVis([v.$1], x == true), title: Text(v.$2)),
                            if (v.$1 == 'place') ..._viewPlaceCats(),
                          ],
                          if (cats.length > 1)
                            Wrap(spacing: 8, children: [
                              OutlinedButton(onPressed: () => s.setVis(cats.map((v) => v.$1), true), child: const Text('Alle einblenden')),
                              OutlinedButton(onPressed: () => s.setVis(cats.map((v) => v.$1), false), child: const Text('Alle ausblenden')),
                            ]),
                        ],
                        if (_airOn) Padding(padding: const EdgeInsets.only(top: 8), child: Mut(_airInfo(), size: 12)),
                      ])));
            }));
  }

  /// unter «Feste Standorte»: Kategorien (nur wenn ein Standort eine hat) und «Namen auf der Karte» (viewPlaceCats, Issue #151)
  List<Widget> _viewPlaceCats() {
    final cs = placeCatsUsed(s);
    if (cs.isEmpty) return [];
    Widget sub(Widget w) => Padding(padding: const EdgeInsets.only(left: 24), child: w);
    return [
      if (cs.any((c) => c.id != 0))
        for (final c in cs)
          sub(CheckboxListTile(
              dense: true,
              value: pcOn(s, c.id),
              onChanged: (x) {
                setPlaceCat(s, [c.id], x == true);
                if (x == true && !s.vis('place')) s.setVis(['place'], true);
              },
              title: Text('${c.name} (${c.n})'))),
      sub(CheckboxListTile(dense: true, value: pcLbl(s), onChanged: (x) => setPlaceCat(s, ['lbl'], x == true), title: const Text('Namen auf der Karte'))),
      if (pcOwn(s)) sub(Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: () => setPlaceCat(s, null, true), child: const Text('Standard der Verwaltung')))),
    ];
  }

  String _airInfo() {
    if (!s.vis('air')) return 'Helikopter ausgeblendet.';
    final d = _air;
    if (d == null) return 'Luftlage wird geladen …';
    if (d['err'] != null) return '⚠ ${d['err']}';
    if (d['info'] != null) return js(d['info']);
    final n = jl(d['air']).length;
    return 'Helikopter: ${n > 0 ? '$n im Umkreis' : 'keine im Umkreis'} von ${js(d['radius_km'])} km um Einsatz und Suchgebiet'
        '${d['src'] is Map ? ' · Quelle ${js(jm(d['src'])['name'])}' : ''}${d['at'] != null ? ' · Stand ${fmtT(jn(d['at']))}' : ''}. Tief fliegende Helikopter können fehlen.';
  }

  // ---------- Kartenknöpfe ----------
  Widget _fab(String tip, Widget icon, VoidCallback onTap, {int? count, Color? countCol, bool active = false}) => Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Stack(clipBehavior: Clip.none, children: [
        FloatingActionButton.small(
            heroTag: tip,
            tooltip: tip,
            backgroundColor: active ? const Color(0xFF1D7FD1) : appBlue,
            foregroundColor: Colors.white,
            onPressed: onTap,
            child: icon),
        if (count != null && count > 0)
          Positioned(
              right: -4,
              top: -4,
              child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                  decoration: BoxDecoration(color: countCol ?? accRed, borderRadius: BorderRadius.circular(10)),
                  child: Text('$count', style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)))),
      ]));

  int get _pplCount => s.participants.where((p) => p['left_at'] == null && p['mode'] != 'declined' && jb(s.modeOf(js(p['mode']))['counts'])).length;
  int get _resCount => s.rescuees.where((r) => r['rescued_at'] == null && r['removed'] != 1 && r['removed'] != true).length;

  Widget _buttons() {
    final i = s.inc;
    final btns = <Widget>[
      if (i != null) _fab('Kamerafokus', const Icon(Icons.center_focus_strong), () => setState(() => _camOpen = !_camOpen), active: s.autoView),
      if (i != null) _fab('Einsatzkräfte', const Icon(Icons.groups), () => _togglePanel('people'), count: _pplCount, countCol: const Color(0xFF546E7A), active: _panel == 'people'),
      if (i != null && s.feat('res')) _fab('Rettlinge', const Text('R', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)), () => _togglePanel('res'), count: _resCount, active: _panel == 'res'),
      if (i != null && s.feat('msg')) _fab('Nachrichten', const Icon(Icons.chat), () => _togglePanel('msgs'), count: s.unread, active: _panel == 'msgs'),
      if ((i != null && s.feat('poi')) || s.places.isNotEmpty)
        _fab(i == null ? 'Feste Standorte' : 'Markierungen', const Icon(Icons.place), () => _togglePanel('pois'), active: _panel == 'pois'),
      if (i != null && s.feat('rd')) _fab('Flussdistanz', const Icon(Icons.straighten), () => _togglePanel('rd'), active: _panel == 'rd'),
    ];
    final foldN = _fold ? _resCount + s.unread : 0;
    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.end, children: [
      if (_rot.abs() > .5)
        _fab('Nach Norden ausrichten', Transform.rotate(angle: deg2rad(_rot), child: const Icon(Icons.navigation, color: accRed)), () {
          mc.rotate(0);
          setState(() => _rot = 0);
        }),
      if (btns.length > 1) _fab(_fold ? 'Kartenknöpfe einblenden' : 'Kartenknöpfe ausblenden', Text(_fold ? '«' : '»', style: const TextStyle(fontSize: 18)), () => setState(() {
            _fold = !_fold;
            _camOpen = false;
          }), count: foldN),
      if (!_fold || btns.length <= 1) ...btns,
    ]);
  }

  Widget _camMenu() {
    final a = s.area, parts = a?.parts.where((p) => p.polys.isNotEmpty || p.circles.isNotEmpty).toList() ?? [];
    Widget it(String t, VoidCallback f, {bool on = false}) => ListTile(
        dense: true,
        title: Text(t),
        trailing: on ? const Icon(Icons.check, size: 18) : null,
        onTap: () {
          setState(() => _camOpen = false);
          f();
        });
    return Material(
        elevation: 6,
        borderRadius: BorderRadius.circular(10),
        child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 260),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              if (s.feat('area') && a != null && !a.empty) it('Suchgebiet', () => fitArea()),
              if (parts.length > 1) ...parts.map((p) => it('Suchgebiet «${p.label}»', () => fitArea(p.key))),
              it('Einsatzkräfte', () {
                final pts = [..._actParts.map((p) => llOf(p)!), ..._exts.map((p) => llOf(p)!)];
                if (pts.isEmpty) {
                  s.toast('Keine Einsatzkräfte mit Standort', 'info');
                } else {
                  fitPts(pts);
                }
              }),
              it('Auto-Übersicht', () {
                final on = !s.autoView;
                _follow = null;
                s.setAuto(on);
                _autoKey = '';
                if (on) _autoFit();
              }, on: s.autoView),
            ])));
  }

  Widget _topBar() {
    final i = s.inc, k = i != null ? s.kindOf(i) : null;
    final t = s.offline
        ? 'Offline · zuletzt verbunden ${s.offAt != null ? fmtHM(s.offAt! / 1000) : '?'}'
        : (i != null ? '${k!.name}: ${js(i['message']).isEmpty ? 'läuft' : js(i['message'])}' : widget.account.label);
    return SafeArea(
        child: Padding(
            padding: const EdgeInsets.all(8),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              FloatingActionButton.small(heroTag: 'menu', tooltip: 'Menü', backgroundColor: appBlue, foregroundColor: Colors.white, onPressed: () => _sk.currentState?.openDrawer(), child: const Icon(Icons.menu)),
              const SizedBox(width: 8),
              Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                GestureDetector(
                    onTap: () => _sk.currentState?.openDrawer(),
                    child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        decoration: BoxDecoration(
                            color: s.offline ? const Color(0xFF8A6D00) : (k != null ? hexColor(k.color) : appBlue).withValues(alpha: .92), borderRadius: BorderRadius.circular(16)),
                        child: Text(t, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)))),
                ?_joinBan(),
                if (_follow != null || s.autoView)
                  Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: InputChip(
                          label: Text(_follow != null ? 'Folgen: ${js(s.participants.where((p) => ji(p['user_id']) == _follow).firstOrNull?['name'])}' : 'Auto-Übersicht'),
                          onDeleted: _stopFollow)),
              ])),
              const SizedBox(width: 8),
              FloatingActionButton.small(heroTag: 'view', tooltip: 'Ansicht', backgroundColor: appBlue, foregroundColor: Colors.white, onPressed: _viewBox, child: const Icon(Icons.layers)),
            ])));
  }

  /// Hinweis, solange ein Einsatz läuft, den man sehen darf, aber nicht angenommen hat (Issue #155, joinBan in index.html;
  /// nicht für die Übungsleitung, sie wird nicht alarmiert). Antippen öffnet das Menü mit der Auswahl der Einrückoption.
  Widget? _joinBan() {
    final st = s.st, m = s.myPart;
    if (s.inc == null || st == null || jb(st['blocked']) || s.exLead || s.joined || !s.modes.any((x) => jb(x['active']))) return null;
    final t = m != null && m['left_at'] != null ? 'Du nimmst nicht mehr an diesem Einsatz teil.' : 'Einsatz noch nicht angenommen.';
    return Padding(
        padding: const EdgeInsets.only(top: 6),
        child: Material(
            color: const Color(0xFFFFD23F),
            elevation: 3,
            borderRadius: BorderRadius.circular(10),
            child: InkWell(
                borderRadius: BorderRadius.circular(10),
                onTap: () {
                  if (_panel != null) openPanel(null);
                  _sk.currentState?.openDrawer();
                },
                child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    child: Row(children: [
                      const Icon(Icons.warning_amber_rounded, color: Color(0xFF231400), size: 20),
                      const SizedBox(width: 8),
                      Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(t, style: const TextStyle(color: Color(0xFF231400), fontWeight: FontWeight.w600)),
                        const Text('Antippen, um die Einrückoption zu wählen.', style: TextStyle(color: Color(0xFF231400), fontSize: 12)),
                      ])),
                    ])))));
  }

  Widget? _note() {
    if (_pickHint != null) {
      return Material(
          color: const Color(0xFF1D7FD1),
          borderRadius: BorderRadius.circular(10),
          child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 6, 6, 6),
              child: Row(children: [
                Expanded(child: Text(_draw != null ? '$_pickHint (${_drawPts.length} Eckpunkte)' : _pickHint!, style: const TextStyle(color: Colors.white))),
                if (_draw != null) ...[
                  IconButton(tooltip: 'Rückgängig', color: Colors.white, onPressed: _drawPts.isEmpty ? null : () => setState(() => _drawPts.removeLast()), icon: const Icon(Icons.undo)),
                  IconButton(tooltip: 'Fertig', color: Colors.white, onPressed: _finishDraw, icon: const Icon(Icons.check)),
                ],
                IconButton(tooltip: 'Abbrechen', color: Colors.white, onPressed: _cancelPick, icon: const Icon(Icons.close)),
              ])));
    }
    final a = s.area;
    if (s.inc == null || a == null || a.info.isEmpty || !s.vis('area')) return null;
    final more = (a.short ?? '').isNotEmpty;
    return GestureDetector(
        onTap: more ? () => setState(() => _noteOpen = !_noteOpen) : null,
        child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(color: Colors.black.withValues(alpha: .7), borderRadius: BorderRadius.circular(10)),
            child: Text(
                !more ? a.info.join(' ') : (_noteOpen ? '${a.info.join('\n')}\n▴ weniger' : '${a.short} ▸ Details'),
                style: const TextStyle(color: Colors.white, fontSize: 12))));
  }

  Widget? _panelWidget() => switch (_panel) {
        'people' => PeoplePanel(s: s, map: this),
        'msgs' => MsgsPanel(s: s, map: this),
        'res' => ResPanel(s: s, map: this),
        'pois' => PoisPanel(s: s, map: this),
        'rd' => RdPanel(s: s, map: this, rd: rd),
        _ => null,
      };

  Widget _alarm(AlarmItem a) => Positioned.fill(
      child: Material(
          color: a.lvl == 'crit' ? const Color(0xF0B71C1C) : const Color(0xF0E65100),
          child: SafeArea(
              child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    const Icon(Icons.notifications_active, size: 72, color: Colors.white),
                    const SizedBox(height: 16),
                    Text(a.title, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.bold)),
                    const SizedBox(height: 12),
                    Text(a.body, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 20)),
                    const SizedBox(height: 8),
                    Text(widget.account.label, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white70)),
                    const SizedBox(height: 32),
                    FilledButton(
                        style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: Colors.black, padding: const EdgeInsets.symmetric(vertical: 18)),
                        onPressed: () async {
                          await s.ackAlarm();
                          if (s.alarm == null && a.key.startsWith('i:')) _sk.currentState?.openDrawer();
                        },
                        child: const Text('Quittieren', style: TextStyle(fontSize: 20))),
                  ])))));

  void _openPrefs() {
    closeMenu();
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => PrefsPage(s: s, onLogout: () => _logout(byServer: false))));
  }

  @override
  Widget build(BuildContext context) {
    if (s.mustChange) return _MustChange(s: s, onLogout: () => _logout(byServer: false));
    final w = MediaQuery.of(context).size.width, narrow = w < 760;
    final pw = _panelWidget(), note = _note();
    final prov = mapProviders[s.base]!;
    return PopScope(
        canPop: _panel == null && !_picking,
        onPopInvokedWithResult: (did, _) {
          if (did) return;
          if (_picking) {
            _cancelPick();
          } else {
            setState(() => _panel = null);
          }
        },
        child: Scaffold(
            key: _sk,
            drawer: Drawer(
                width: math.min(w * .92, 440),
                child: !s.ready
                    ? const Center(child: CircularProgressIndicator())
                    : IncMenu(s: s, map: this, openPrefs: _openPrefs, plsPick: (c, {res}) => plsPick(c, s, this, res: res))),
            body: Stack(children: [
              FlutterMap(
                  mapController: mc,
                  options: MapOptions(
                      initialCenter: const LatLng(46.8, 8.2),
                      initialZoom: 8,
                      maxZoom: 19,
                      interactionOptions: const InteractionOptions(flags: InteractiveFlag.all),
                      onTap: (_, p) => _onTap(p),
                      onMapReady: () {
                        _mapReady = true;
                        _onStore();
                      },
                      onMapEvent: (e) {
                        if (e is MapEventRotate || e is MapEventRotateEnd) setState(() => _rot = mc.camera.rotation);
                        final hand = e.source == MapEventSource.onDrag || e.source == MapEventSource.onMultiFinger || e.source == MapEventSource.doubleTap || e.source == MapEventSource.doubleTapHold || e.source == MapEventSource.scrollWheel;
                        if (hand && e is MapEventMoveStart) {
                          if (!_homeBusy) _homeDone = true;
                          _areaFocus = null;
                          if (!_autoBusy) _stopFollow();
                        }
                      }),
                  children: [
                    TileLayer(urlTemplate: prov.$2, userAgentPackageName: 'ch.swiftwaterrescue.app', maxNativeZoom: prov.$4, maxZoom: 19),
                    ..._layers(context),
                    RichAttributionWidget(attributions: [TextSourceAttribution(prov.$3), if (_airOn && _air?['src'] is Map) TextSourceAttribution(js(jm(_air!['src'])['name']))]),
                  ]),
              _topBar(),
              if (!s.ready && s.fatal == null) const Center(child: CircularProgressIndicator()),
              if (s.fatal != null && s.st == null)
                Center(
                    child: Card(
                        child: Padding(
                            padding: const EdgeInsets.all(16),
                            child: Column(mainAxisSize: MainAxisSize.min, children: [
                              Text('Verbindung zu ${widget.account.label} fehlgeschlagen'),
                              Mut(s.fatal!),
                              const SizedBox(height: 8),
                              FilledButton(onPressed: s.boot, child: const Text('Erneut versuchen')),
                            ])))),
              if (!(narrow && pw != null))
                Positioned(
                    right: (pw != null && !narrow) ? 412 : 10,
                    bottom: 24,
                    child: SafeArea(
                        child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                      if (_camOpen) Padding(padding: const EdgeInsets.only(right: 8), child: _camMenu()),
                      _buttons(),
                    ]))),
              if (note != null)
                Positioned(
                    left: 10,
                    right: (pw != null && !narrow) ? 412 : 70,
                    bottom: 24,
                    child: SafeArea(child: Align(alignment: Alignment.bottomLeft, child: note))),
              if (pw != null)
                Positioned(
                    right: 0,
                    top: 0,
                    bottom: 0,
                    width: narrow ? w : 400,
                    child: Material(elevation: 8, child: SafeArea(left: false, child: pw))),
              if (s.alarm != null) _alarm(s.alarm!),
            ])));
  }
}

/// Erst ein eigenes Passwort setzen (must_change, serverseitig erzwungen)
class _MustChange extends StatefulWidget {
  const _MustChange({required this.s, required this.onLogout});
  final IncStore s;
  final Future<void> Function() onLogout;
  @override
  State<_MustChange> createState() => _MustChangeState();
}

class _MustChangeState extends State<_MustChange> {
  final _old = TextEditingController(), _n1 = TextEditingController(), _n2 = TextEditingController();
  bool _busy = false;

  Future<void> _save() async {
    if (_n1.text.isEmpty || _n1.text != _n2.text) {
      widget.s.toast('Die neuen Passwörter stimmen nicht überein', 'err');
      return;
    }
    setState(() => _busy = true);
    try {
      await widget.s.api.call('me/password', body: {'old': _old.text, 'new': _n1.text});
      widget.s.mustChange = false;
      widget.s.toast('Passwort geändert');
      await widget.s.boot();
    } catch (e) {
      widget.s.fail(e);
    }
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
      appBar: AppBar(title: const Text('Eigenes Passwort setzen')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        const Text('Bitte setze zuerst ein eigenes Passwort.'),
        TextField(controller: _old, obscureText: true, decoration: const InputDecoration(labelText: 'Aktuelles Passwort')),
        TextField(controller: _n1, obscureText: true, decoration: const InputDecoration(labelText: 'Neues Passwort')),
        TextField(controller: _n2, obscureText: true, decoration: const InputDecoration(labelText: 'Neues Passwort wiederholen')),
        const SizedBox(height: 16),
        FilledButton(onPressed: _busy ? null : _save, child: const Text('Speichern')),
        TextButton(onPressed: widget.onLogout, child: const Text('Abmelden')),
      ]));
}
