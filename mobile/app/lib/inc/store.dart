// Zustand der nativen Einsatz-App eines Kontos (Gegenstück zum Polling in web/index.html): me, Rollen, Einrückoptionen,
// incident/state alle 4 s (Nachrichten inkrementell über since), Alarme und Hinweise mit den Schlüsseln der Web-App (i:, m:, k:, e:),
// Suchgebiet (area.dart), Standort teilen mit Erkennung «auf dem Gewässer» und «Suchgebiet erreicht» (incident/event).
// Solange die Seite sichtbar ist, meldet ein Herzschlag (setForeground) das Konto als offen: Überwachung (monitor.dart) und
// Standortdienst (track.dart) bleiben dann für dieses Konto still, die Seite teilt selbst. Pausiert die App, übernehmen sie wieder.
// Parallele Einsätze (Issue #167, wie incWatch/setSel in index.html): Api.inc (X-Inc) wählt den angezeigten Einsatz (incsel_<Konto>),
// incident/state mit oth=1 liefert incidents (alle sichtbaren laufenden), others (Lage der übrigen) und my_inc (eigene Teilnahme).
// Man nimmt höchstens an einem teil; der Standort geht immer an den eigenen (der Server wählt ihn bei incident/location selbst).
import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/widgets.dart';
import 'package:flutter/services.dart';
import 'package:flutter_map/flutter_map.dart' show LatLngBounds;
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../accounts.dart';
import '../api.dart';
import '../beacon.dart';
import '../demo.dart';
import '../monitor.dart';
import '../notify.dart';
import '../service.dart';
import '../sys.dart';
import '../track.dart';
import 'area.dart';
import 'util.dart';
import 'voice.dart';

/// Kategorien auf der Karte (VIEW in common.js): Schlüssel, Bezeichnung, Funktion
const viewCatsAll = [
  ('app', 'Einsatzkräfte mit App', ''),
  ('ext', 'Externe Einsatzkräfte', 'ext'),
  ('res', 'Rettlinge', 'res'),
  ('pls', 'PLS', ''),
  ('place', 'Feste Standorte', 'place'),
  ('area', 'Suchgebiet', 'area'),
  ('pt', 'Markierungen', 'poi'),
  ('ar', 'Bereiche', 'poi'),
  ('ln', 'Linien', 'poi'),
  ('mm', 'Nachrichten-Markierungen', 'msg'),
  ('air', 'Helikopter', ''),
  ('veh', 'Fahrzeuge', 'veh'),
  ('hydro', 'Messstationen', 'hydro'),
  ('geo', 'Geodaten (OpenStreetMap)', 'geo'), // Issue #182, zuerst ausgeblendet
];

/// Grundkarte (Issue #106, MAP_PROVIDERS/mapsSet in common.js): Liste vom Server (maps, map_def in me und incident/state), eingestellt in der Verwaltung,
/// global und je Einsatztyp, je mit Abdeckung (bounds). Die eigene Vektorkarte (vec, PMTiles) gibt es nativ noch nicht.
class MapProv {
  MapProv(this.k, this.label, this.url, this.attr, this.nat, {this.sub = const [], this.bounds});
  final String k, label, url, attr;
  final int nat;
  final List<String> sub;
  final LatLngBounds? bounds;

  /// Quellenangabe als Text (der Server liefert HTML für Leaflet)
  String get attrText => attr.replaceAll(RegExp(r'<[^>]*>'), '').replaceAll('&copy;', '©').replaceAll('&amp;', '&');

  static MapProv? of(J j) {
    if (j['vec'] != null && j['vec'] != 0) return null;
    final b = j['bounds'] is List && (j['bounds'] as List).length == 2 ? (j['bounds'] as List).map((p) => p is List && p.length == 2 ? LatLng(jd(p[0]) ?? 0, jd(p[1]) ?? 0) : null).toList() : null;
    final url = js(j['url']), k = js(j['k']);
    if (k.isEmpty || url.isEmpty) return null;
    return MapProv(k, js(j['label']), url, js(j['attr']), ji(j['nat']) ?? 19,
        sub: js(j['sub']).split('').where((x) => x.isNotEmpty).toList(), bounds: b != null && b[0] != null && b[1] != null ? LatLngBounds(b[0]!, b[1]!) : null);
  }
}

/// Rückfall, bis die Liste vom Server da ist (älterer Server)
final mapFallback = [
  MapProv('swisstopo_sat', 'Luftbild (swisstopo)', 'https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.swissimage/default/current/3857/{z}/{x}/{y}.jpeg', '© swisstopo', 18),
  MapProv('swisstopo', 'Landeskarte (swisstopo)', 'https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.pixelkarte-farbe/default/current/3857/{z}/{x}/{y}.jpeg', '© swisstopo', 18),
  MapProv('osm', 'OpenStreetMap', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', '© OpenStreetMap-Mitwirkende', 19),
];
const defaultBase = 'swisstopo_sat';

class AlarmItem {
  AlarmItem(this.title, this.body, this.key, this.lvl, [this.kind, this.ksnd]);
  final String title, body, key, lvl;
  final int? kind; // Einsatztyp und Vorgabe des Tons (kritisch, Issue #171)
  final String? ksnd;
}

class Toast {
  Toast(this.text, this.kind, [this.title]);
  final String text, kind; // ok | info | err
  final String? title;
}

class IncStore extends ChangeNotifier with WidgetsBindingObserver {
  IncStore(this.acc);
  final Account acc;
  late final Api api = acc.api;

  /// Demo-Modus (Issue #74): kein Server, kein Teilen im Hintergrund, kein Dienst, keine Überwachung, nichts gespeichert
  late final bool demo = isDemoAcc(acc);

  J? me;
  List<J> roles = [], modes = [];
  J? st; // letzter Stand von incident/state
  final msgs = <J>[];
  int _lastMsg = 0;
  int? _lastIncId;
  bool _msgInit = false;
  bool ready = false, offline = false, closed = false;
  String? fatal; // Start fehlgeschlagen
  int? offAt; // Zeitpunkt der letzten Verbindung (ms)
  bool unauth = false; // Server hat den Schlüssel abgelehnt
  bool mustChange = false;

  // Suchgebiet
  final nets = <String, NetSlot>{};
  int? _netsInc;
  SearchArea? area;
  final _areaLogged = <int>{};

  // eigener Standort
  Position? devPos;
  LatLng? manualPos; // manuell gesetzt (Test), hat Vorrang
  String locStatus = '', locErr = '';
  StreamSubscription<Position>? _posSub;
  int _lastSent = 0;
  LatLng? _lastPos;
  Timer? _hb, _poll, _fg;
  bool _visible = true, _polling = false;

  // Alarme und Hinweise
  final alarmQ = <AlarmItem>[];
  AlarmItem? alarm;
  final toasts = StreamController<Toast>.broadcast();
  final _acked = <String>{};

  // Ansicht (pro Gerät, wie localStorage der Web-App)
  SharedPreferences? _sp;
  Set<String> viewHide = {};
  String base = defaultBase;
  List<MapProv> maps = mapFallback;
  String mapDef = defaultBase;
  String _mapsKey = '';
  int? _baseInc;
  bool autoView = false;
  Set<String> poiHide = {};
  int? _poiInc;
  int msgSeen = 0;

  /// Ereignisse an die Karte (z. B. Einsatz neu: Startansicht)
  final events = StreamController<String>.broadcast();

  /// Funk & Konferenz (Issue #113): Durchsagen, Mithören, Live-Konferenz (voice.dart)
  late final VoiceStore vx = VoiceStore(this);

  // ---------- Parallele Einsätze (Issue #167) ----------
  /// Wunsch von aussen (Antippen einer Alarmmeldung, openAccount): (Konto, Einsatz) anzeigen
  static final selReq = ValueNotifier<(String, int)?>(null);
  int _selGen = 0; // Wahl geändert: laufende Abfrage verwerfen
  bool _again = false;
  List<J>? _incPrev; // laufende Einsätze der letzten Abfrage (Ende der übrigen melden)
  Set<int> othHide = {}; // ausgeblendete übrige Einsätze (oth_hide_<Konto>, wie sw_othhide)

  /// alle laufenden Einsätze, die man sehen darf (leer bei älteren Servern und im Demo)
  List<J> get incidents => jl(st?['incidents']);

  /// Lage der übrigen laufenden Einsätze für die Karte (nur im Einsatz)
  List<J> get others => inc == null ? <J>[] : jl(st?['others']);

  /// Eintrag eines laufenden Einsatzes aus incidents
  J? incOf(int? id) => incidents.where((x) => ji(x['id']) == id).firstOrNull;

  /// Server mit parallelen Einsätzen? (liefert my_inc, auch als null)
  bool get _par => st != null && st!.containsKey('my_inc');

  /// eigene Teilnahme an einem laufenden Einsatz (id, mode, message, kind_name); ältere Server und Demo: nach me
  J? get myInc {
    if (_par) return st!['my_inc'] is Map ? jm(st!['my_inc']) : null;
    return joined ? {'id': inc!['id'], 'mode': myPart!['mode'], 'message': inc!['message'], 'kind_name': kindOf(inc!).name} : null;
  }

  int? get myIncId => ji(myInc?['id']);

  /// angezeigter Einsatz ist nicht der eigene (man nimmt an einem anderen teil)
  bool get otherShown => inc != null && myIncId != null && myIncId != ji(inc!['id']);

  /// angezeigten Einsatz wählen (setSel); 0 = der Server wählt
  Future<void> setSel(int id) async {
    if (api.inc == id && ji(inc?['id']) == id) return;
    api.inc = id;
    _sp?.setInt('incsel_${acc.id}', id);
    _selGen++;
    if (_polling) {
      _again = true;
      return;
    }
    await poll();
  }

  void _onSelReq() {
    final r = selReq.value;
    if (r == null || r.$1 != acc.id) return;
    selReq.value = null;
    unawaited(setSel(r.$2));
  }

  void setOthHide(int id, bool hide) {
    hide ? othHide.add(id) : othHide.remove(id);
    _sp?.setStringList('oth_hide_${acc.id}', othHide.map((e) => '$e').toList());
    notifyListeners();
  }

  /// Alarme und Ende der übrigen Einsätze melden (der angezeigte wie bisher); true = neu alarmiert ohne eigene Teilnahme, Anzeige gewechselt
  bool _incWatch(J nx) {
    if (nx['incidents'] is! List) return false;
    final il = jl(nx['incidents']), cur = nx['incident'] is Map ? (ji(jm(nx['incident'])['id']) ?? 0) : 0;
    var sw = false;
    if (cur != 0 && api.inc != cur) {
      api.inc = cur; // gewählter beendet bzw. erste Abfrage: der Server hat gewählt
      _sp?.setInt('incsel_${acc.id}', cur);
    }
    final nme = nx['me'] is Map ? jm(nx['me']) : null, inNow = nme != null && nme['left_at'] == null && nme['mode'] != 'declined';
    for (final x in il) {
      final id = ji(x['id']) ?? 0, k = '${x['kind_name'] ?? 'Einsatz'}';
      if (id == cur || !jb(x['alarm']) || jb(x['joined']) || _acked.contains('i:$id')) continue;
      final lv = js(x['level']).isEmpty ? 'crit' : js(x['level']);
      if (lv == 'none') continue;
      final kd = jl(nx['kinds']).where((y) => ji(y['id']) == ji(x['kind_id'])).firstOrNull;
      notify(k.toUpperCase(), js(x['message']), 'i:$id', lv, kind: ji(x['kind_id']), ksnd: kd?['snd'] as String?);
      if (!sw && nx['my_inc'] == null && !inNow) {
        sw = true;
        api.inc = id;
        _sp?.setInt('incsel_${acc.id}', id);
      }
    }
    final p = _incPrev, oid = ji(inc?['id']);
    if (p != null) {
      for (final x in p) {
        final id = ji(x['id']);
        if (il.any((y) => ji(y['id']) == id) || id == cur || (cur == 0 && id == oid)) continue;
        final el = js(x['end_level']).isEmpty ? 'normal' : js(x['end_level']), k = '${x['kind_name'] ?? 'Einsatz'}';
        if (jb(x['alarm']) || jb(x['mine'])) notify('${k.toUpperCase()} BEENDET', '$k beendet.', 'e:$id', el);
      }
    }
    _incPrev = il;
    return sw;
  }

  // ---------- Hilfen ----------
  int get uid => ji(me?['id']) ?? acc.uid;
  J? get inc => st?['incident'] is Map ? jm(st!['incident']) : null;
  /// Kategorie des laufenden Einsatzes (Feedback zu #164): 0 Primäreinsatz, 1 Sekundäreinsatz, 2 Freies Ereignis; Einrückoptionen und Spezialrollen gelten je für eine Kategorie
  int get incCat => inc?['kind'] is Map ? (ji(jm(inc!['kind'])['sec']) ?? 0) : 0;
  List<J> get iModes => modes.where((x) => (ji(x['sec']) ?? 0) == incCat).toList();
  List<J> get iRoles => roles.where((r) => (ji(r['sec']) ?? 0) == incCat).toList();
  J? get myPart => st?['me'] is Map ? jm(st!['me']) : null;
  num get now => jn(st?['now']) ?? DateTime.now().millisecondsSinceEpoch / 1000;
  bool get joined {
    final m = myPart;
    return inc != null && m != null && m['left_at'] == null && m['mode'] != 'declined';
  }

  bool get canManage => jb(st?['can_manage']);
  bool get exLead => jb(st?['ex_lead']);
  List<J> get participants => jl(st?['participants']);
  List<J> get externals => feat('ext') ? jl(st?['externals']) : [];
  List<J> get rescuees => jl(st?['rescuees']);
  List<J> get pois => jl(st?['pois']);
  List<J> get places => feat('place') ? jl(st?['places']) : [];
  List<J> get placeCats => jl(st?['place_cats']);

  J modeOf(String? k) => modes.firstWhere((x) => x['code'] == k, orElse: () => {'code': k, 'name': k ?? '', 'share_location': 1, 'active': 0, 'lead_rights': 0, 'counts': 1});
  bool shares(String? k) => jb(modeOf(k)['share_location']);
  bool leadMode(String? k) => jb(modeOf(k)['lead_rights']);
  J? roleOf(dynamic id) => id == null ? null : roles.where((r) => '${r['id']}' == '$id').firstOrNull;
  bool can(String p) => (me?['perms'] is List) && (me!['perms'] as List).contains(p);
  bool get isAdmin => jb(me?['is_admin']);

  String get csys => js(me?['coord_sys']).isNotEmpty ? js(me!['coord_sys']) : (js(me?['coord_default']).isNotEmpty ? js(me!['coord_default']) : 'lv95');
  String fc(dynamic lat, dynamic lon) => fmtC(jd(lat), jd(lon), csys);
  String fcs(dynamic lat, dynamic lon, [String pre = ' · ', String post = '']) {
    final t = fc(lat, lon);
    return t.isEmpty ? '' : '$pre$t$post';
  }

  /// Funktion an? Im Einsatz gemäss features, ohne Einsatz an, wenn für einen der Einsatztypen an (feat)
  bool feat(String k) {
    final i = inc, f = st?['features'];
    if (i != null && f is Map) return f[k] != 0;
    final m = (i == null && f is Map) ? f : me?['features'];
    if (m is! Map || m.isEmpty) return true;
    return m.values.any((x) => x is! Map || x[k] != 0);
  }

  List<(String, String, String)> viewCats() =>
      viewCatsAll.where((v) => v.$1 == 'air' ? jb(st?['air']) : v.$1 == 'geo' ? feat('geo') && geoKinds.isNotEmpty : v.$1 == 'veh' ? (st?['veh'] is Map) : (v.$3.isEmpty || feat(v.$3))).toList(); // Fahrzeuge nur, wenn der Server sie liefert (Issue #177)

  /// Objektarten der Geodaten-Ebene, die die Verwaltung anbietet (Issue #182)
  List<J> get geoKinds => jl(st?['geo_kinds']).where((k) => jb(k['live'])).toList();

  /// ausgeblendete Objektarten der Geodaten-Ebene, pro Gerät (geo_off)
  Set<String> geoOff = {};
  void setGeoOff(String k, bool off) {
    off ? geoOff.add(k) : geoOff.remove(k);
    _sp?.setStringList('geo_off', geoOff.toList());
    notifyListeners();
  }
  bool vis(String k) => !viewHide.contains(k);

  /// Art des Einsatzes (kindOf)
  ({String name, String color}) kindOf(J x) => js(x['kind_name']).isNotEmpty
      ? (name: js(x['kind_name']), color: js(x['kind_color']))
      : (jb(x['is_exercise']) ? (name: 'Übung', color: '#1d7fd1') : (name: 'Einsatz', color: '#ff4d2e'));

  // ---------- Start ----------
  Future<void> start() async {
    WidgetsBinding.instance.addObserver(this);
    _sp = await SharedPreferences.getInstance();
    _loadView();
    api.inc = demo ? 0 : (_sp!.getInt('incsel_${acc.id}') ?? 0);
    othHide = (_sp!.getStringList('oth_hide_${acc.id}') ?? []).map(int.tryParse).whereType<int>().toSet();
    selReq.addListener(_onSelReq);
    _onSelReq();
    try {
      final a = await accState(acc.id);
      _acked.addAll((_sp!.getStringList('ack_${acc.id}') ?? []));
      _acked.addAll(a.tags.where((t) => !t.startsWith('i:'))); // Nachrichten und Einsatzende, die schon gemeldet wurden, nicht nochmals
    } catch (_) {}
    _beat();
    _fg = Timer.periodic(const Duration(seconds: 5), (_) => _beat());
    await boot();
  }

  Future<void> boot() async {
    fatal = null;
    try {
      me = jm((await api.call('me'))['me']);
      unawaited(Beacons.putMe(acc.id, me)); // Fahrzeuge (Issue #177)
      mustChange = jb(me!['must_change']);
      if (mustChange) {
        ready = true;
        notifyListeners();
        return;
      }
      roles = jl((await api.call('roles'))['data']);
      modes = jl((await api.call('modes'))['data']);
      _sp?.setString('boot_${acc.id}', jsonEncode({'me': me, 'roles': roles, 'modes': modes}));
      offline = false;
    } on ApiError catch (e) {
      if (e.unauth) {
        unauth = true;
        notifyListeners();
        return;
      }
      // offline: gemerkte Anmeldung und letzten Stand zeigen (bootOffline)
      final b = _sp?.getString('boot_${acc.id}');
      if (!e.offline || b == null) {
        fatal = e.message;
        notifyListeners();
        _poll?.cancel();
        _poll = Timer(const Duration(seconds: 5), boot);
        return;
      }
      final j = jm(jsonDecode(b));
      me = jm(j['me']);
      roles = jl(j['roles']);
      modes = jl(j['modes']);
      final l = _sp?.getString('last_${acc.id}');
      if (l != null) {
        st = jm(jsonDecode(l));
        msgs.addAll(jl(st!['messages']));
        _lastMsg = msgs.fold(0, (a, m) => (ji(m['id']) ?? 0) > a ? ji(m['id'])! : a);
        _lastIncId = ji(inc?['id']);
        _msgInit = true;
      }
      offline = true;
      offAt ??= _sp?.getInt('lastAt_${acc.id}') ?? DateTime.now().millisecondsSinceEpoch;
    }
    _mapsSet(me);
    _baseSync();
    ready = true;
    notifyListeners();
    await poll();
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(seconds: 4), (_) => poll());
  }

  /// Nach geänderten persönlichen Einstellungen
  Future<void> reloadMe() async {
    try {
      me = jm((await api.call('me'))['me']);
      unawaited(Beacons.putMe(acc.id, me)); // Fahrzeuge (Issue #177)
      notifyListeners();
    } catch (_) {}
  }

  void _loadView() {
    final p = _sp!;
    viewHide = (p.getStringList('v_hide') ?? []).toSet();
    // Geodaten-Ebene (Issue #182) auf jedem Gerät zuerst ausgeblendet (VIEW_OFF)
    if (!(p.getBool('v_def_geo') ?? false)) {
      viewHide.add('geo');
      p.setStringList('v_hide', viewHide.toList());
      p.setBool('v_def_geo', true);
    }
    geoOff = (p.getStringList('geo_off') ?? []).toSet();
    final b = p.getString('v_base');
    base = _hasMap(b) ? b! : defaultBase;
    autoView = p.getBool('v_auto') ?? false;
  }

  void setVis(Iterable<String> keys, bool on) {
    for (final k in keys) {
      on ? viewHide.remove(k) : viewHide.add(k);
    }
    _sp?.setStringList('v_hide', viewHide.toList());
    notifyListeners();
  }

  /// Im Einsatz gilt die Wahl bis zum Einsatzende (`v_base_<Konto>_<Einsatz>`), ohne Einsatz `v_base`
  void setBase(String k) {
    if (!_hasMap(k)) return;
    base = k;
    _sp?.setString(_baseInc != null && _baseInc != 0 ? 'v_base_${acc.id}_$_baseInc' : 'v_base', k);
    notifyListeners();
  }

  bool _hasMap(String? k) => k != null && maps.any((m) => m.k == k);
  MapProv get prov => maps.firstWhere((m) => m.k == base, orElse: () => maps.first);

  /// Liste vom Server übernehmen; true = geändert
  bool _mapsSet(J? src) {
    if (src == null || src['maps'] is! List) return false;
    final key = jsonEncode([src['maps'], src['map_def']]);
    if (key == _mapsKey) return false;
    final l = jl(src['maps']).map(MapProv.of).whereType<MapProv>().toList();
    if (l.isEmpty) return false;
    _mapsKey = key;
    maps = l;
    mapDef = _hasMap(js(src['map_def'])) ? js(src['map_def']) : l.first.k;
    return true;
  }

  /// Wie baseSync in index.html: beim ersten Mal in einem Einsatz die eigene Wahl für diesen Einsatz, sonst die Standardkarte des Einsatztyps;
  /// ohne Einsatz v_base. Nicht mehr erlaubte Karten fallen auf den Standard zurück.
  void _baseSync() {
    final id = ji(inc?['id']) ?? 0, ch = _mapsSet(st);
    if (!ch && id == _baseInc && _hasMap(base)) return;
    if (!(id == _baseInc && _hasMap(base))) {
      final sv = _sp?.getString(id != 0 ? 'v_base_${acc.id}_$id' : 'v_base');
      base = _hasMap(sv) ? sv! : mapDef; // im Einsatz ohne eigene Wahl: Standard des Einsatztyps
    }
    if (st != null && _baseInc != id) {
      for (final k in _sp?.getKeys().where((k) => k.startsWith('v_base_${acc.id}_') && k != 'v_base_${acc.id}_$id' && !incidents.any((x) => k == 'v_base_${acc.id}_${x['id']}')).toList() ?? <String>[]) {
        _sp?.remove(k); // Wahl beendeter Einsätze verwerfen
      }
    }
    _baseInc = id;
  }

  void setAuto(bool on) {
    autoView = on;
    _sp?.setBool('v_auto', on);
    notifyListeners();
  }

  /// ausgeblendete Markierungen, Bereiche und feste Standorte je Einsatz (`sw_poihide_<id>`, ohne Einsatz 0)
  void _poiHideLoad() {
    final id = ji(inc?['id']) ?? 0;
    if (_poiInc == id) return;
    _poiInc = id;
    poiHide = (_sp?.getStringList('poihide_${acc.id}_$id') ?? []).toSet();
    msgSeen = _sp?.getInt('msgseen_${acc.id}_$id') ?? 0;
  }

  void setPoiHidden(Iterable<String> keys, bool hide) {
    _poiHideLoad();
    for (final k in keys) {
      hide ? poiHide.add(k) : poiHide.remove(k);
    }
    _sp?.setStringList('poihide_${acc.id}_${_poiInc ?? 0}', poiHide.toList());
    notifyListeners();
  }

  void setMsgSeen(int id) {
    if (id <= msgSeen) return;
    msgSeen = id;
    _sp?.setInt('msgseen_${acc.id}_${_poiInc ?? 0}', id);
  }

  // ---------- Pegel & Abfluss (Issue #125, nativ seit Issue #165) ----------
  // Messstationen des BAFU: die des Einsatzes (st.hydro, zu Beginn und am Ende erfasst) und die persönlichen (me.hydro, im Profil gewählt).
  // Aktuelle Werte über hydro (Server mit Cache), höchstens alle 5 Minuten und sofort, wenn sich die Stationen ändern (hyPoll).
  Map<String, J> hyData = {};
  String? hyErr;
  List<J>? hyAll; // alle Stationen (Suche im Profil), einmal geladen
  int _hyAt = 0;
  String _hyKey = '';
  bool _hyBusy = false;

  List<String> get hyMine => me?['hydro'] is List ? (me!['hydro'] as List).map((e) => '$e').toList() : <String>[];
  List<J> get hyInc => inc != null ? jl(st?['hydro']) : <J>[];
  List<String> get hyIds => {...hyInc.map((r) => js(r['station'])), ...hyMine}.where((x) => x.isNotEmpty).toList();
  J? hyStation(String id) => hyData[id] ?? hyInc.where((r) => js(r['station']) == id).firstOrNull ?? hyAll?.where((x) => js(x['id']) == id).firstOrNull;

  /// Kartenknopf «Messstationen» (hyBtn): im Einsatz bei eingeschalteter Funktion, ohne Einsatz, sobald man eigene Stationen hat
  bool get hyBtn => feat('hydro') && (inc != null || hyMine.isNotEmpty);

  Future<void> hyPoll({bool force = false}) async {
    if (!feat('hydro')) return;
    final ids = hyIds, k = ids.join(',');
    if (ids.isEmpty) {
      if (_hyKey.isNotEmpty) {
        _hyKey = '';
        hyData = {};
        notifyListeners();
      }
      return;
    }
    if (_hyBusy || (!force && k == _hyKey && DateTime.now().millisecondsSinceEpoch - _hyAt < 300000)) return;
    _hyBusy = true;
    try {
      final d = await api.call('hydro', body: {'ids': ids});
      hyData = {for (final x in jl(d['stations'])) js(x['id']): x};
      hyErr = d['err'] == null ? null : js(d['err']);
    } on ApiError catch (e) {
      hyErr = e.offline ? 'Keine Verbindung zum Server' : e.message;
    } catch (e) {
      hyErr = '$e';
    } finally {
      _hyBusy = false;
      _hyKey = k;
      _hyAt = DateTime.now().millisecondsSinceEpoch;
      if (!closed) notifyListeners();
    }
  }

  /// alle Stationen für die Suche im Profil
  Future<List<J>> hyLoadAll() async => hyAll ??= jl((await api.call('hydro', body: {}))['stations']);

  /// persönliche Messstationen speichern (me/settings mit hydro, höchstens 20)
  Future<void> hySave(List<String> l) async {
    final old = me?['hydro'];
    me?['hydro'] = l;
    notifyListeners();
    try {
      await api.call('me/settings', body: {'hydro': l});
      await hyPoll(force: true);
    } catch (e) {
      me?['hydro'] = old;
      fail(e);
      notifyListeners();
    }
  }

  int get unread => msgs.where((m) => (ji(m['id']) ?? 0) > msgSeen && ji(m['from_user']) != uid).length;

  // ---------- Polling ----------
  Future<void> poll() async {
    if (_polling || closed || mustChange) return;
    _polling = true;
    final g = _selGen;
    try {
      var nx = await api.call('incident/state', query: {'since': '$_lastMsg', ...vx.query(), 'oth': '1'});
      if (g != _selGen) {
        _again = true; // Wahl inzwischen geändert: diese Antwort gilt nicht mehr
        return;
      }
      // neu alarmiert zu einem anderen Einsatz ohne eigene Teilnahme: diesen anzeigen
      if (_incWatch(nx)) nx = await api.call('incident/state', query: {'since': '0', ...vx.query(), 'oth': '1'});
      _askBt(nx);
      final i = nx['incident'] is Map ? jm(nx['incident']) : null;
      if (i != null) {
        final id = ji(i['id'])!, lv = js(nx['level']).isEmpty ? 'crit' : js(nx['level']);
        // Stufe für Nachrichten je Einsatztyp (Issue #168), ältere Server ohne msg_level wie bisher
        final cr = js(nx['msg_level']).isNotEmpty ? js(nx['msg_level']) : (lv == 'none' ? 'low' : lv), kr = cr == 'none' ? 'low' : cr;
        if (_lastIncId != id) {
          _lastIncId = id;
          _lastMsg = 0;
          _msgInit = false;
          msgs.clear();
          if (nx['alarm'] != false && !(nx['me'] is Map && jm(nx['me'])['left_at'] == null && jm(nx['me'])['mode'] != 'declined')) {
            notify(kindOf(i).name.toUpperCase(), js(i['message']), 'i:$id', lv, kind: ji(i['kind_id']), ksnd: i['kind'] is Map ? jm(i['kind'])['snd'] as String? : null);
          }
          events.add('inc');
        }
        final nm = nx['me'] is Map ? jm(nx['me']) : null;
        final inNow = nm != null && nm['left_at'] == null && nm['mode'] != 'declined';
        for (final m in jl(nx['messages'])) {
          final mid = ji(m['id']) ?? 0;
          if (mid > _lastMsg) _lastMsg = mid;
          if (msgs.any((x) => x['id'] == m['id'])) continue;
          msgs.add(m);
          if (_msgInit && cr != 'none' && ji(m['from_user']) != uid && inNow && msgForMe(m)) notify('Nachricht von ${js(m['from_name'])}', js(m['text']), 'm:$mid', cr);
        }
        _msgInit = true;
        final was = ji(inc?['id']) == id ? myPart : null; // nur derselbe Einsatz (parallele Einsätze: Anzeige gewechselt)
        if (was != null && was['left_at'] == null && was['mode'] != 'declined' && nm != null && nm['left_at'] != null && !selfLeft) {
          notify('AUS EINSATZ ENTFERNT', 'Die Einsatzleitung hat dich aus dem Einsatz entfernt.', 'k:$id:${nm['left_at']}', kr);
        }
        st = nx;
        unawaited(vx.onState(nx));
        final mine = myIncId; // Issue #167: Teilnahme an irgendeinem laufenden Einsatz
        if (_joinedSeen != mine && mine != null) _trackNow(); // angenommen: Teilen im Hintergrund sofort vorbereiten, nicht erst beim nächsten Polling
        _joinedSeen = mine;
      } else {
        final o = inc;
        if (o != null) {
          final k = kindOf(o).name, lv = js(st?['level']).isEmpty ? 'crit' : js(st!['level']);
          final el = js(st?['end_level']).isNotEmpty ? js(st!['end_level']) : (lv == 'none' ? 'low' : lv); // Issue #168
          unawaited(cancelIncNotes(acc.id, (o['id'] as num?)?.toInt())); // Issue #174, nur dieser Einsatz (#167): Meldungen des Einsatzes zurücknehmen
          if (el != 'none' && (st?['alarm'] != false || st?['me'] != null)) notify('${k.toUpperCase()} BEENDET', '$k beendet.', 'e:${o['id']}', el);
        }
        st = nx;
        unawaited(vx.onState(nx));
        _joinedSeen = null;
        _lastIncId = null;
        msgs.clear();
        _lastMsg = 0;
      }
      selfLeft = false;
      _baseSync();
      hyPoll();
      _poiHideLoad();
      _calcArea();
      _syncPos();
      _checkArea();
      _saveLast();
      if (offline) {
        offline = false;
        offAt = null;
      }
    } on ApiError catch (e) {
      if (e.unauth) {
        unauth = true;
        _poll?.cancel();
      } else if (e.offline) {
        offline = true;
        offAt ??= _sp?.getInt('lastAt_${acc.id}') ?? DateTime.now().millisecondsSinceEpoch;
      }
    } catch (e) {
      debugPrint('Poll $e');
    } finally {
      _polling = false;
      if (_again && !closed) {
        _again = false;
        scheduleMicrotask(poll); // Wahl während der Abfrage geändert (setSel)
      }
    }
    if (!closed) notifyListeners();
  }

  bool selfLeft = false; // selbst ausgetreten: keine Meldung «entfernt»
  int? _joinedSeen; // Einsatz, an dem man zuletzt teilnahm

  /// Stand des Kontos für die Überwachung sofort nachführen und das Teilen im Hintergrund starten (Issue #183). Vorher geschah das erst
  /// beim nächsten Polling der App (Standard 15 s); unter iOS ist dieses im Hintergrund gestoppt, wer annahm und gleich sperrte, teilte nie.
  /// Unter Android teilt der Vordergrunddienst in seinem eigenen Isolate, er erhält nur den Auftrag, sofort abzufragen.
  /// Android (Issue #179): Mit Push läuft der Dienst nur im Einsatz (serviceNeeded); damit er gleich startet, zuerst die Teilnahme
  /// im Stand der Überwachung festhalten (setJoined).
  void _trackNow() {
    if (demo) return;
    if (Platform.isAndroid) {
      unawaited(setJoined(acc.id, myIncId, true).then((_) => syncService()).catchError((Object e) => debugPrint('Dienst $e')));
      return;
    }
    unawaited(pollOne(acc).then((_) => Tracker.sync()).catchError((Object e) => debugPrint('Tracker $e')));
  }

  void _saveLast() {
    if (st == null || demo) return;
    try {
      _sp?.setString('last_${acc.id}', jsonEncode({...st!, 'messages': msgs}));
      _sp?.setInt('lastAt_${acc.id}', DateTime.now().millisecondsSinceEpoch);
    } catch (_) {}
  }

  List<int> msgIds(J m) {
    final r = m['recipients'];
    if (r == null || r == '') return [];
    try {
      final l = r is String ? jsonDecode(r) : r;
      return l is List ? l.map((e) => (e as num).toInt()).toList() : [];
    } catch (_) {
      return [];
    }
  }

  bool msgForMe(J m) {
    final r = m['recipients'];
    if (r == null || r == '') return true;
    return msgIds(m).isEmpty || msgIds(m).contains(uid);
  }

  // ---------- Alarme und Hinweise ----------
  /// Alarmstufe (Issue #90): crit Vollbild mit Dauerton, high Vollbild mit einmaligem Ton, normal Hinweis mit Ton, low still, none nichts
  void notify(String t, String b, String key, String lvl, {int? kind, String? ksnd}) {
    if (lvl == 'none') return;
    if (_acked.contains(key)) return;
    if (lvl == 'crit' || lvl == 'high') {
      if (alarm?.key == key || alarmQ.any((q) => q.key == key)) return;
      alarmQ.add(AlarmItem(t, b, key, lvl, kind, ksnd));
      _showAlarm();
      return;
    }
    _ack(key);
    toasts.add(Toast(b, 'info', t));
    if (lvl != 'low') {
      SystemSound.play(SystemSoundType.alert);
      HapticFeedback.mediumImpact();
    }
    if (!_visible) showNote(acc.id, key, demo ? 'DEMO – $t' : t, b, lvl, sub: acc.label);
  }

  void _showAlarm() {
    if (alarm != null || alarmQ.isEmpty) return;
    alarm = alarmQ.removeAt(0);
    final a = alarm!;
    // Meldung des Geräts mit Weckton (kritisch) bzw. Alarmton; dieselbe Kennung wie die Überwachung, also nie doppelt
    markTag(acc.id, a.key);
    showNote(acc.id, a.key, demo ? 'DEMO – ${a.title}' : a.title, a.body, a.lvl, sub: acc.label, kind: a.kind, ksnd: a.ksnd);
    if (a.lvl == 'crit' && Platform.isIOS) HapticFeedback.heavyImpact();
    notifyListeners();
  }

  /// Alarm quittiert: Weckton aus, Meldung schliessen, nächsten zeigen
  Future<void> ackAlarm() async {
    final a = alarm;
    if (a == null) return;
    _ack(a.key);
    alarm = null;
    await cancelNote(acc.id, a.key);
    await alarmStop();
    _showAlarm();
    notifyListeners();
  }

  void _ack(String k) {
    _acked.add(k);
    markTag(acc.id, k);
    final l = _acked.toList();
    _sp?.setStringList('ack_${acc.id}', l.length > 300 ? l.sublist(l.length - 300) : l);
  }

  /// Fahrzeuge (Issue #177): im Einsatz mit Fahrzeugen einmal um Bluetooth bitten (Android 12+: «Geräte in der Nähe»), damit die App die Beacons sieht
  static bool _btAsked = false;
  void _askBt(J nx) {
    final v = nx['veh'];
    if (_btAsked || nx['incident'] is! Map || v is! Map || jl(v['list']).isEmpty) return;
    final m = nx['me'];
    if (m is! Map || m['left_at'] != null || m['mode'] == 'declined') return;
    _btAsked = true;
    requestBluetooth();
  }

  void toast(String text, [String kind = 'ok']) => toasts.add(Toast(text, kind));
  void fail(Object e) => toasts.add(Toast(e is ApiError ? e.message : '$e', 'err'));

  /// Aktion ausführen: Fehler als Hinweis, danach neu laden. Rückgabe: Antwort oder null bei Fehler.
  Future<J?> act(String route, [J? body, String? ok]) async {
    try {
      final r = await api.call(route, body: body ?? {});
      if (ok != null) toast(ok);
      await poll();
      return r;
    } catch (e) {
      fail(e);
      await poll();
      return null;
    }
  }

  // ---------- Suchgebiet ----------
  /// Alle PLS (plsList): der des Einsatzes und die eigenen der Rettlinge; on = zählt fürs Suchgebiet
  List<({double lat, double lon, num? at, String label, bool on, J? res})> plsList() {
    final i = inc;
    if (i == null) return [];
    final src = jl(st?['sources']), l = <({double lat, double lon, num? at, String label, bool on, J? res})>[];
    if (jd(i['lat']) != null) l.add((lat: jd(i['lat'])!, lon: jd(i['lon'])!, at: jn(i['pls_at']), label: 'PLS des Einsatzes', on: src.any((s) => !jb(s['own'])), res: null));
    for (final r in rescuees.where((r) => jd(r['pls_lat']) != null)) {
      l.add((lat: jd(r['pls_lat'])!, lon: jd(r['pls_lon'])!, at: jn(r['pls_at']), label: 'PLS «${js(r['name'])}»', on: resSt(r) == 'miss', res: r));
    }
    return l;
  }

  String resSt(J r) => r['rescued_at'] != null ? 'ok' : (r['lat'] != null ? 'loc' : 'miss');

  void _calcArea() {
    final i = inc;
    if (i == null) {
      area = null;
      return;
    }
    final id = ji(i['id']);
    if (_netsInc != id) {
      nets.clear();
      _netsInc = id;
    }
    if (!feat('area')) {
      area = null;
      return;
    }
    final posts = <({LatLng p, String name})>[];
    for (final p in [...participants.where((p) => p['lat'] != null && p['left_at'] == null), ...externals.where((x) => x['lat'] != null)]) {
      if (jb(roleOf(p['role_id'])?['is_post'])) posts.add((p: llOf(p)!, name: js(p['name'])));
    }
    area = searchArea(jl(st?['sources']), nets, jd(i['flow_ms']), now, posts, (s, r) => overpassNet(api, {'around': [r, s.latitude, s.longitude]}), () {
      _calcArea();
      notifyListeners();
    }, rescuees, jl(st?['stops'])); // Flusssperren (Issue #182)
  }

  void _checkArea() {
    final i = inc, a = area, m = myPart;
    if (i == null || a == null || a.polys.isEmpty || !joined || m == null) return;
    final id = ji(i['id'])!;
    if (_areaLogged.contains(id)) return;
    final pos = _lastPos ?? llOf(m);
    if (pos == null) return;
    if (a.polys.any((pl) => inPoly(pos, pl))) {
      _areaLogged.add(id);
      api.call('incident/event', body: {'kind': 'area'}).catchError((_) {
        _areaLogged.remove(id);
        return <String, dynamic>{};
      });
    }
  }

  // ---------- eigener Standort ----------
  /// Im Einsatz mit Einrückoption ohne Standortfreigabe: kein blauer Punkt, keine Abfrage (selfHidden)
  bool get selfHidden {
    final m = myInc; // Issue #167: nach der eigenen Teilnahme, auch wenn ein anderer Einsatz angezeigt wird
    return m != null && !shares(js(m['mode']));
  }

  /// teilt den Standort: nach der eigenen Teilnahme, auch wenn ein anderer Einsatz angezeigt wird (Issue #167)
  bool get sharing => myInc != null && shares(js(myInc!['mode']));

  LatLng? get selfPartPos {
    if (inc == null) return null;
    final m = participants.where((p) => ji(p['user_id']) == uid && p['lat'] != null && p['left_at'] == null && p['mode'] != 'declined').firstOrNull;
    return m == null ? null : llOf(m);
  }

  /// eigener Standort für Karte und Anzeige (selfAt)
  LatLng? get selfAt => selfHidden ? null : (manualPos ?? (devPos != null ? LatLng(devPos!.latitude, devPos!.longitude) : selfPartPos));

  Future<void> _syncPos() async {
    if (!_visible || selfHidden) {
      _stopPos();
      return;
    }
    if (_posSub != null) return;
    final lvl = await requestLocation();
    if (lvl == locNone) {
      locErr = 'nicht freigegeben (Standort in den Einstellungen der App erlauben)';
      return;
    }
    locErr = '';
    if (_posSub != null || !_visible) return;
    _posSub = Geolocator.getPositionStream(
            // dieselben Einstellungen wie track.dart: unter iOS gibt es nur einen Strom, und wer ihn zuerst öffnet, bestimmt sie (Issue #183)
            locationSettings: locSettings(android: const Duration(seconds: 3)))
        .listen((p) {
      devPos = p;
      locErr = '';
      if (manualPos == null) {
        final q = LatLng(p.latitude, p.longitude);
        if (DateTime.now().millisecondsSinceEpoch - _lastSent > 3000) {
          _sendPos(q);
        } else {
          _lastPos = q;
        }
      }
      notifyListeners();
    }, onError: (e) {
      locErr = 'nicht verfügbar ($e)';
      notifyListeners();
    });
    _hb ??= Timer.periodic(const Duration(seconds: 5), (_) {
      final p = manualPos ?? _lastPos;
      if (p != null && DateTime.now().millisecondsSinceEpoch - _lastSent > 10000) _sendPos(p);
    });
  }

  void _stopPos() {
    _posSub?.cancel();
    _posSub = null;
    _hb?.cancel();
    _hb = null;
    if (selfHidden) devPos = null;
  }

  Future<void> _sendPos(LatLng p) async {
    _lastPos = p;
    if (!sharing) return;
    _lastSent = DateTime.now().millisecondsSinceEpoch;
    try {
      final bs = manualPos == null ? await Beacons.forReport(acc) : null; // Fahrzeuge (Issue #177)
      await api.call('incident/location', body: {
        'lat': p.latitude,
        'lon': p.longitude,
        'beacons': ?bs,
        if (manualPos == null && devPos != null) 'acc': devPos!.accuracy,
        // Gewässer nur, wenn der angezeigte Einsatz der eigene ist (sonst ist das Gewässernetz eines anderen geladen, Issue #167)
        'water': !otherShown && waterDist(p, nets.values.map((n) => n.net).whereType<RiverNet>()) < waterM ? 1 : 0,
        'manual': manualPos != null ? 1 : 0
      });
      locStatus = 'Standort wird geteilt (${fmtT(DateTime.now().millisecondsSinceEpoch / 1000)})';
      if (!demo) unawaited(locResult(acc, myIncId));
    } catch (e) {
      locStatus = 'Standort senden fehlgeschlagen: $e';
      if (!demo) unawaited(locResult(acc, myIncId, err: e is ApiError ? e.message : '$e'));
    }
    _checkArea();
  }

  /// Standort manuell setzen (Test), null = wieder Gerätestandort
  void setManual(LatLng? p) {
    manualPos = p;
    if (p != null) _sendPos(p);
    notifyListeners();
  }

  // ---------- Lebenszyklus ----------
  void _beat() {
    if (_visible && !closed && !demo) setForeground(acc.id);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _visible = true;
      _beat();
      poll();
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      // Hintergrund: Überwachung und Standortdienst übernehmen (monitor.dart, track.dart)
      _visible = false;
      if (!demo) setForeground(null);
      if (Platform.isIOS && !demo) {
        // Erst das Teilen im Hintergrund anhängen, dann den eigenen Strom beenden: So läuft die Ortung ohne Unterbruch weiter
        // (unter iOS darf sie mit «Beim Verwenden» nur im Vordergrund starten).
        Tracker.sync().catchError((Object e) => debugPrint('Tracker $e')).whenComplete(() {
          if (!_visible) _stopPos();
        });
      } else {
        _stopPos();
      }
    }
  }

  bool get visible => _visible;

  @override
  void dispose() {
    closed = true;
    selReq.removeListener(_onSelReq);
    WidgetsBinding.instance.removeObserver(this);
    _poll?.cancel();
    _fg?.cancel();
    _stopPos();
    if (!demo) setForeground(null);
    vx.dispose();
    toasts.close();
    events.close();
    super.dispose();
  }
}
