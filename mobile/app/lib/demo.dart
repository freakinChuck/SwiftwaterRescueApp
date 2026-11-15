// Demo-Modus der nativen App (Issue #74, Gegenstück zu web/demo.js): die Einsatz-App ohne Server und ohne Konto ausprobieren.
// - Ein eigenes Konto mit der Adresse demoBase, das nie gespeichert wird (Kontenliste, Überwachung, Push, Standortdienst und
//   Vordergrunddienst sehen es nicht). Api.call beantwortet seine Aufrufe hier statt über das Netz (Demo.i.call).
// - Zustand nur im Speicher: Er bleibt, solange die App läuft, und beginnt nach einem Neustart der App von vorn.
// - Szenario, Routen und Texte wie web/demo.js (Reuss in Luzern, simulierte Einsatzkräfte 101–106, Rega-Helikopter);
//   **Änderungen an einer der beiden Simulationen auch in der anderen nachführen.**
// - Externe Dienste wie in der echten App direkt vom Gerät: Kartenkacheln und das Gewässernetz (overpass: 502 mit query/servers,
//   area.dart fragt dann selbst, Ergebnis über overpass/put nur im Speicher). Mit einem angemeldeten Konto holt das Demo das Gewässernetz
//   über die Route overpass dieses Servers (_netAcc, nur lesend, nichts wird hochgeladen).
import 'dart:math' as math;

import 'accounts.dart';
import 'api.dart';
import 'monitor.dart' show prefs;

typedef _J = Map<String, dynamic>;
typedef _P = List<double>; // [lat, lon]

/// Adresse des Demo-Kontos: kein Server, Api.call fragt Demo.i
const demoBase = 'demo://swiftwater/';
const demoId = 'demo';
bool isDemoBase(String base) => base.startsWith('demo:');
bool isDemoAcc(Account a) => a.id == demoId || isDemoBase(a.base);

/// Konto für den Demo-Modus (nicht in Accounts gespeichert)
Account demoAccount() => Account(id: demoId, base: demoBase, username: 'demo', token: 'demo', name: 'Alex Demo', org: 'Demo-Modus', uid: 1);

int _now() => DateTime.now().millisecondsSinceEpoch ~/ 1000;
String _f5(num x) => x.toStringAsFixed(5);
String _hm(int t) {
  final d = DateTime.fromMillisecondsSinceEpoch(t * 1000);
  return '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
}

double _hav(double a, double b, double c, double d) {
  const r = 6371000.0;
  double rad(double x) => x * math.pi / 180;
  final x = rad(c - a), y = rad(d - b), h = math.pow(math.sin(x / 2), 2) + math.cos(rad(a)) * math.cos(rad(c)) * math.pow(math.sin(y / 2), 2);
  return 2 * r * math.asin(math.sqrt(h));
}

/// Versatz in Metern Ost/Nord
_P _off(_P p, num e, num n) => [p[0] + n / 111320, p[1] + e / (111320 * math.cos(p[0] * math.pi / 180))];

Never _fail(String m, [int st = 400]) => throw ApiError(m, st, {'error': m});

// ---------- feste Daten (wie web/demo.js) ----------
const _feat = {
  'area': 1,
  'res': 1,
  'msg': 1,
  'poi': 1,
  'ext': 1,
  'rd': 1,
  'pub': 0,
  'rec': 1,
  'nav': 1,
  'place': 1,
  'hydro': 1,
  'pinfo': 1,
  'geo': 0,
  'veh': 1,
  'vehp': 1,
  'call': 0,
}; // öffentliche Ansicht und Geodaten-Ebene (#182) brauchen den Server, Direktanruf (#44) keine echten Nummern
_J _kind(int id, String name, String color, String alarm, String an, int modeUp, int rec) => {
  'id': id,
  'name': name,
  'color': color,
  'alarm': alarm,
  'alarm_name': an,
  'mode_up': modeUp,
  'msg_lvl': 'normal',
  'end_lvl': 'normal',
  'rec': rec,
  'air': 1,
  'grp_off': <int>[],
  'grp_def': <int>[],
  'persons': 0,
  'open_all': 0,
  'plans': 0,
  'snd': null,
  'can': 1,
  'no_grp': 0,
  'features': _feat,
};
final _kinds = [_kind(1, 'Einsatz', '#ff4d2e', 'high', 'Hoch', 0, 0), _kind(2, 'Übung', '#1d7fd1', 'normal', 'Normal', 1, 1)];
const _roles = [
  {'id': 1, 'name': 'Einsatzleitung', 'color': '#d62828', 'is_lead': 1, 'is_post': 0, 'can_message': 1},
  {'id': 2, 'name': 'Rettungsboot', 'color': '#1d7fd1', 'is_lead': 0, 'is_post': 0, 'can_message': 1},
  {'id': 3, 'name': 'Rettungsschlitten', 'color': '#e6a100', 'is_lead': 0, 'is_post': 0, 'can_message': 1},
  {'id': 4, 'name': 'Sicherungs- und Beobachtungsposten', 'color': '#2a9d5c', 'is_lead': 0, 'is_post': 1, 'can_message': 1},
];
const _modes = [
  {'id': 1, 'code': 'base', 'name': 'Anfahrt via Stützpunkt', 'share_location': 1, 'active': 1, 'lead_rights': 0, 'counts': 1},
  {'id': 2, 'code': 'direct', 'name': 'Anfahrt direkt', 'share_location': 1, 'active': 1, 'lead_rights': 0, 'counts': 1},
  {'id': 3, 'code': 'logistics', 'name': 'Logistik', 'share_location': 1, 'active': 1, 'lead_rights': 0, 'counts': 1},
  {'id': 4, 'code': 'coordinator', 'name': 'Koordinator KP Rück', 'share_location': 0, 'active': 1, 'lead_rights': 1, 'counts': 0},
];
const _extT = [
  {'id': 1, 'name': 'Seepolizei Luzern', 'descr': 'Polizeiboot', 'role_id': null, 'abbr': 'POL', 'color': '#3949ab', 'lat': null, 'lon': null},
  {'id': 2, 'name': 'Feuerwehr – Beobachtungsposten', 'descr': '', 'role_id': 4, 'abbr': 'FW', 'color': '#e53935', 'lat': null, 'lon': null},
];
const _groups = [
  {'id': 1, 'name': 'Wasserrettung Stadt', 'color': '#1d7fd1'},
  {'id': 2, 'name': 'Wasserrettung Land', 'color': '#2a9d5c'},
];
const _symRaw = [
  ['sz-el', 'sz', 'Führung', 'Einsatzleitung (Ei Ltg)', 1],
  ['sz-warterm', 'sz', 'Führung', 'Warteraum Rettungsmittel (Warterm)', 0],
  ['sz-helipl', 'sz', 'Führung', 'Helikopterlandeplatz (Helipl)', 0],
  ['sz-patsst', 'sz', 'Sanität', 'Patientensammelstelle (Pat Sst)', 0],
  ['sz-sanhist', 'sz', 'Sanität', 'Sanitätshilfsstelle (San Hist)', 0],
  ['sz-tsst', 'sz', 'Sanität', 'Totensammelstelle (TSst)', 0],
  ['sz-sstunv', 'sz', 'Betreuung', 'Sammelstelle Unverletzte (Sst Unv)', 0],
  ['sz-betreu', 'sz', 'Betreuung', 'Betreuungsstelle (Betreu Stel)', 0],
  ['sz-medien', 'sz', 'Betreuung', 'Medientreffpunkt (Medien Tpt)', 0],
  ['sz-streugut', 'sz', 'Betreuung', 'Streugut-Sammelstelle (Streugut Sst)', 0],
  ['sw-ew', 'sw', 'Wasserrettung', 'Bootseinwasserung', 0],
  ['sw-bo', 'sw', 'Wasserrettung', 'Boot im Einsatz', 0],
  ['sw-sp', 'sw', 'Wasserrettung', 'Sicherungsposten', 0],
  ['sw-bp', 'sw', 'Wasserrettung', 'Beobachtungsposten', 0],
  ['sw-tr', 'sw', 'Wasserrettung', 'Seilsicherung / Traverse', 0],
  ['sw-st', 'sw', 'Wasserrettung', 'Rettungsstelle Ufer', 0],
  ['sw-kp', 'sw', 'Führung', 'Kommandoposten', 0],
  ['sw-tp', 'sw', 'Führung', 'Treffpunkt / Sammelplatz', 0],
  ['sw-br', 'sw', 'Führung', 'Bereitstellungsraum', 0],
  ['sw-sh', 'sw', 'Sanität', 'Sanitätshilfsstelle', 0],
  ['sw-ps', 'sw', 'Sanität', 'Patientensammelstelle', 0],
  ['sw-hl', 'sw', 'Sanität', 'Helikopter-Landeplatz', 0],
  ['sw-am', 'sw', 'Sanität', 'Ambulanz', 0],
  ['sw-fw', 'sw', 'Partner', 'Feuerwehr', 0],
  ['sw-po', 'sw', 'Partner', 'Polizei', 0],
  ['sw-zs', 'sw', 'Partner', 'Zivilschutz', 0],
  ['sw-we', 'sw', 'Gefahren', 'Gefahrenstelle (Wehr, Schwelle)', 0],
  ['sw-wa', 'sw', 'Gefahren', 'Walze / Rücklauf', 0],
  ['sw-ab', 'sw', 'Allgemein', 'Absperrung', 0],
  ['sw-md', 'sw', 'Allgemein', 'Materialdepot', 0],
  ['sw-pk', 'sw', 'Allgemein', 'Parkplatz Fahrzeuge', 0],
  ['sw-fo', 'sw', 'Allgemein', 'Fundort Gegenstand', 0],
];
final _syms = _symRaw.map((s) => <String, dynamic>{'k': s[0], 'set': s[1], 'cat': s[2], 'name': s[3], 'foot': s[4]}).toList();
_J? _sym(String? k) => k == null ? null : _syms.where((s) => s['k'] == k).firstOrNull;
const _meId = 1;
const _simNames = {101: 'Anna Muster', 102: 'Beat Beispiel', 103: 'Carla Probst', 104: 'David Fischer', 105: 'Eva Keller', 106: 'Fabian Roth'};
const _simGroups = {
  101: [1],
  102: [1],
  103: [1],
  104: [2],
  105: [2],
  106: [2],
};
const _overpass = ['https://overpass-api.de/api/interpreter', 'https://maps.mail.ru/osm/tools/overpass/api/interpreter', 'https://overpass.kumi.systems/api/interpreter'];
// Beispielort: Reuss in Luzern (Koordinaten ungefähr, nur für die Simulation)
const _pls = [47.0534, 8.3033], _kp = [47.05295, 8.30415], _sp = [47.0487, 8.3097], _ew = [47.0612, 8.2862], _fw = [47.0645, 8.2820], _post = [47.0688, 8.2760];
const _find = [47.0595, 8.2902], _jacke = [47.0580, 8.2927], _r3 = [47.0570, 8.2940], _r4 = [47.0588, 8.2915], _r5 = [47.0603, 8.2890], _r6 = [47.0615, 8.2865];
const _home = {
  101: [47.0440, 8.3220],
  102: [47.0700, 8.3150],
  103: [47.0390, 8.2850],
  104: [47.0800, 8.2900],
  105: [47.0300, 8.3000],
  106: [47.0500, 8.3300],
};
final _places = [
  {
    'id': 1,
    'plan_id': null,
    'cat_id': 1,
    'name': 'Stützpunkt Wasserrettung (Demo)',
    'descr': 'Boote, Schlitten und Material. Fiktiver Standort für den Demo-Modus.',
    'abbr': 'SP',
    'color': '#1d7fd1',
    'lat': _sp[0],
    'lon': _sp[1],
    'pts': null,
    'line': 0,
    'active': 1,
    'sort': null,
  },
  {
    'id': 2,
    'plan_id': null,
    'cat_id': 1,
    'name': 'Bootseinwasserung Reusszopf (Demo)',
    'descr': 'Einwasserung für Boote, Zufahrt über die Uferstrasse. Fiktiv.',
    'abbr': 'EW',
    'color': '#2a9d5c',
    'lat': _ew[0],
    'lon': _ew[1],
    'pts': null,
    'line': 0,
    'active': 1,
    'sort': null,
  },
];
const _placeCats = [
  {'id': 1, 'name': 'Wasserrettung', 'def_on': 1},
];
const _hydro = [
  {
    'id': 'D2152',
    'name': 'Luzern, Geissmattbrücke (Demo)',
    'water': 'Reuss',
    'type': 'river',
    'lat': 47.0556,
    'lon': 8.2968,
    'flow': 118.4,
    'height': 432.71,
    'level': null,
    'temp': 9.8,
    'danger': 2,
    'cls': 'hoch',
  },
  {
    'id': 'D2025',
    'name': 'Emmenbrücke (Demo)',
    'water': 'Kleine Emme',
    'type': 'river',
    'lat': 47.0726,
    'lon': 8.2738,
    'flow': 34.2,
    'height': 427.35,
    'level': null,
    'temp': 8.9,
    'danger': 1,
    'cls': 'mittel',
  },
];
const _sector = [
  [47.0559, 8.2961],
  [47.0575, 8.2946],
  [47.0618, 8.2873],
  [47.0612, 8.2856],
  [47.0567, 8.2932],
  [47.0551, 8.2962],
];

/// Wegpunkte je Person: [Sekunden nach Alarm, lat, lon]
List<double> _w(int t, List<double> p) => [t.toDouble(), p[0], p[1]];
final _bspRoute = <int, List<List<double>>>{
  101: [_w(20, _home[101]!), _w(240, _kp)],
  102: [_w(40, _home[102]!), _w(200, _sp), _w(230, _sp), _w(330, _ew), _w(360, _r6), _w(430, _r5), _w(500, _off(_find, -15, -12)), _w(560, _off(_find, -15, -12)), _w(640, _ew)],
  103: [_w(170, _home[103]!), _w(320, _ew), _w(360, _off(_r6, 6, 4)), _w(430, _off(_r5, 6, 4)), _w(500, _off(_find, -10, -8)), _w(560, _off(_find, -10, -8)), _w(640, _off(_ew, 5, 5))],
  104: [_w(200, _home[104]!), _w(380, _r3), _w(470, _r4), _w(495, _off(_find, 8, 10))],
  105: [_w(260, _home[105]!), _w(460, _post)],
  106: [_w(330, _home[106]!), _w(520, _off(_kp, 25, -15))],
};

/// eigener Einsatz: [Annahme s, Start Ost/Nord m, Ziel Ost/Nord m, Ankunft s] relativ zum Bezugspunkt
const _genRoute = {
  101: [
    15,
    [-2600, 1800],
    [-60, 40],
    220,
  ],
  102: [
    25,
    [3100, 2400],
    [120, -90],
    280,
  ],
  103: [
    45,
    [-1800, -2600],
    [130, -80],
    300,
  ],
  104: [
    60,
    [2200, -3000],
    [260, 180],
    330,
  ],
  105: [
    100,
    [-3500, -900],
    [-700, -500],
    330,
  ],
  106: [
    130,
    [1500, 3300],
    [-40, 70],
    360,
  ],
};

class _Inc {
  _Inc(this.id, this.message, this.lat, this.lon, this.alarmAt, this.createdBy, this.kindId, this.record, this.air, this.script) : t0 = alarmAt;
  final int id;
  final String message;
  double? lat, lon;
  int? plsAt;
  final int alarmAt;
  int? endedAt;
  double? flow;
  final int createdBy, kindId;
  int record, air, openJoin = 0, stopOn = 1;
  final stopOff = <int>[];
  late int recorded = record;
  final String script; // bsp | gen
  int t0;
  final done = <int>{};
  final parts = <_J>[], log = <_J>[], msgs = <_J>[], pois = <_J>[], res = <_J>[], ext = <_J>[];
  final homes = <int, List<_P>>{};
}

class _Step {
  const _Step(this.at, this.uid, this.fn);
  final int at, uid;
  final void Function(Demo d, int t) fn;
}

/// Simuliertes Backend (Routen wie web/api/index.php, soweit die Einsatz-App sie nutzt)
class Demo {
  Demo._(this.mode) {
    if (mode == 'bsp') _bspStart(_now() - 150);
  }
  static Demo? _i;
  static Demo get i => _i ??= Demo._('bsp');

  /// neu beginnen: 'bsp' = Beispieldaten, 'leer' = ohne Einsatz; src: angemeldetes Konto, dessen Vorgaben «ohne Daten» übernimmt (_loadOrg)
  static Demo restart(String mode, {Account? src}) {
    final d = _i = Demo._(mode);
    if (mode == 'leer' && src != null) d._ready = d._loadOrg(src);
    return d;
  }

  static bool get started => _i != null;

  final String mode;
  bool sim = true; // andere Einsatzkräfte simulieren
  bool lead = true; // Recht inc_lead: nach dem Annehmen alle Rechte der Einsatzleitung
  bool welcomed = false; // Erklärung beim ersten Öffnen gezeigt
  int _seqInc = 900000, _seqLog = 1, _seqMsg = 1, _seqPoi = 1, _seqRes = 1, _seqExt = 1, _seqAbs = 1;
  _Inc? _inc;
  _J? _last;
  final abs = <_J>[];

  // ---------- Vorgaben der eigenen Organisation (nur «ohne Daten», wie loadOrg in web/demo.js) ----------
  // Einmal beim Start liest die App mit dem gewählten Konto (nur lesende Routen) feste Standorte, Einsatztypen, Spezialrollen,
  // Einrückoptionen, Messstationen, Symbole, Grundkarten und die eigenen Einstellungen. Geschrieben wird nie etwas; ohne Antwort die Demo-Daten.
  Future<void> _ready = Future.value();
  Map<String, dynamic>? org; // geladene Vorgaben (name = Organisation), null = Demo-Daten
  Account? _src;
  // Kopien mit Typ Map<String, dynamic> (die Konstanten sind Map<String, Object>, firstWhere mit orElse scheitert sonst zur Laufzeit)
  List<_J> _ks = [..._kinds], _ps = [..._places], _hy = [..._hydro], _sy = [..._syms], _ext = [..._extT];
  List<Map<String, dynamic>> _rs = [..._roles], _ms = [..._modes], _pc = [..._placeCats];

  Future<void> _loadOrg(Account a) async {
    final api = a.api;
    Future<dynamic> get(String r) async {
      try {
        final o = await api.call(r, timeout: const Duration(seconds: 8));
        return o.containsKey('data') && o.length == 1 ? o['data'] : o;
      } catch (_) {
        return null;
      }
    }

    final m = await get('me');
    final me = m is Map ? m['me'] : null;
    if (me is! Map || me['must_change'] == 1) return;
    final pm = me['perms'] is List ? me['perms'] as List : const [];
    final res = await Future.wait([get('roles'), get('modes'), get('demo/places'), get('hydro'), if (pm.contains('cfg_inc') || pm.contains('ex_plan')) get('ext_templates')]);
    List<_J>? l(dynamic x) => x is List ? x.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList() : null;
    // alle festen Standorte (demo/places); ältere Server ohne die Route: wie bisher aus incident/state
    final dp = res[2] is Map && (res[2] as Map)['places'] is List ? res[2] : await get('incident/state');
    final st = dp is Map ? dp : const {}, hy = res[3] is Map ? res[3] as Map : const {};
    final ks = (l(me['kinds']) ?? [])
        .map(
          (k) => {
            ...k,
            if (k['alarm'] == 'crit') 'alarm': 'high', // kein Dauerton im Demo
            if (k['alarm'] == 'crit') 'alarm_name': 'Hoch',
            'plans': 0,
            'persons': 0,
            'open_all': 0,
            'grp_off': <int>[],
            'grp_def': <int>[],
            'can': 1,
            'no_grp': 0,
            'features': {..._feat, if (k['features'] is Map) ...Map<String, dynamic>.from(k['features'] as Map), 'pub': 0, 'geo': 0},
          },
        )
        .toList();
    final ms = l(res[1]) ?? [];
    if (_inc != null) return;
    _src = a;
    // Funktion «Feste Standorte» im Demo an, sobald die Organisation Standorte hat
    if (st['places'] is List && (st['places'] as List).isNotEmpty) {
      for (final k in ks) {
        k['features'] = {...(k['features'] as Map), 'place': 1};
      }
    }
    if (ks.isNotEmpty) _ks = ks;
    _rs = l(res[0]) ?? _rs;
    if (ms.any((x) => x['active'] == 1)) _ms = ms;
    _ps = l(st['places']) ?? _ps;
    _pc = l(st['place_cats']) ?? _pc;
    _props = l(st['place_props']) ?? _props;
    if (st['place_near_km'] is num) _nearKm = (st['place_near_km'] as num).toDouble();
    _hy = l(hy['stations'])?.where((h) => h['lat'] is num && h['lon'] is num).toList() ?? _hy;
    if (l(me['symbols']) case final sy?) _sy = sy;
    if (res.length > 4 && l(res[4]) != null) _ext = l(res[4])!.where((x) => x['active'] == 1).map((x) => x..remove('active')).toList();
    for (final k in ['name', 'photo', 'coord_sys', 'nav_app', 'place_view', 'hydro', 'exercise_mode']) {
      if (me.containsKey(k)) _me[k] = me[k];
    }
    _veh = null;
    org = {
      'name': '${me['org'] ?? ''}',
      'cfg': {
        for (final k in ['coord_default', 'rd_speeds', 'nav_apps', 'nav_default', 'symbols', 'sym_free', 'sym_sets', 'maps', 'map_def', 'version'])
          if (me[k] != null) k: me[k],
      },
      'maps': {
        for (final k in ['maps', 'map_def'])
          if (me[k] != null) k: me[k],
      },
    };
  }

  _J _featOf(_J? k) => {..._feat, if (k?['features'] is Map) ...Map<String, dynamic>.from(k!['features'] as Map), 'pub': 0, 'geo': 0, 'call': 0};
  _J get _featAll => {for (final k in _ks) '${k['id']}': _featOf(k)};
  // Flusssperren (Issue #182, wie incStops): feste Standorte mit einer Eigenschaft der Wirkung stop, solange berücksichtigt und nicht ausgenommen
  List<_J> _props = [];
  double _nearKm = 10;
  _J get _placeOut => {'place_props': _props, 'place_near_km': _nearKm, 'geo_kinds': []};
  List<_J> _stops(_Inc i) {
    final f = _featOf(_kindOf(i)), eff = _props.where((p) => p['effect'] == 'stop').toList();
    if (f['area'] != 1 || f['place'] != 1 || i.stopOn != 1 || eff.isEmpty) return [];
    final o = <_J>[];
    for (final pl in _ps) {
      if (pl['active'] == 0 || i.stopOff.contains(pl['id'])) continue;
      final pr = pl['props'] is List ? pl['props'] as List : const [];
      final p = eff.where((e) => pr.contains(e['id'])).firstOrNull;
      if (p != null) o.add({'id': pl['id'], 'name': pl['name'], 'why': p['name'], 'lat': pl['lat'], 'lon': pl['lon'], 'pts': pl['pts'], 'line': pl['line']});
    }
    return o;
  }

  _J get _mapsOut => org == null ? {} : Map<String, dynamic>.from(org!['maps'] as Map);

  /// Mit Vorgaben die Spezialrollen und Einrückoptionen der Organisation: 1 = Einsatzleitung, 4 = Posten, 2/3 = übrige
  int? _rl(int n) {
    if (org == null) return n;
    final l = _rs.where((r) => r['is_lead'] == 1).firstOrNull, q = _rs.where((r) => r['is_post'] == 1).firstOrNull;
    final o = _rs.where((r) => r != l && r != q).toList();
    final r = n == 1 ? l : (n == 4 ? q : (n == 2 ? o.firstOrNull : (o.length > 1 ? o[1] : o.firstOrNull)));
    return r?['id'] as int?;
  }

  String _mc(String c) {
    if (org == null) return c;
    final a = _ms.where((m) => m['active'] == 1).toList();
    final m =
        a.where((m) => m['code'] == c && m['lead_rights'] != 1 && m['share_location'] == 1).firstOrNull ?? a.where((m) => m['share_location'] == 1 && m['lead_rights'] != 1).firstOrNull ?? a.first;
    return '${m['code']}';
  }

  final _reply = <_J>[];
  final _opc = <String, _J>{};
  final _me = <String, dynamic>{
    'id': _meId,
    'username': 'demo',
    'name': 'Alex Demo',
    'photo': null,
    'exercise_mode': 0,
    'coord_sys': null,
    'nav_app': null,
    'place_view': <String, dynamic>{},
    'hydro': ['D2152'],
    'ai_modes': <String, dynamic>{},
  };

  // ---------- Hilfen ----------
  String _uname(int? id) => id == _meId ? '${_me['name']}' : (_simNames[id] ?? '?');
  Map<String, dynamic> _role(int id) => _rs.firstWhere((r) => r['id'] == id, orElse: () => {'id': id, 'name': 'Rolle $id', 'is_lead': 0, 'is_post': 0});
  Map<String, dynamic>? _mode(String? c) => _ms.where((m) => m['code'] == c).firstOrNull;
  void _logev(int? uid, String kind, String text, [int? ts]) => _inc?.log.add({'id': _seqLog++, 'ts': ts ?? _now(), 'kind': kind, 'text': text, 'uid': uid});
  _J? _part([int id = _meId]) => _inc?.parts.where((p) => p['user_id'] == id).firstOrNull;
  bool _active([int id = _meId]) {
    final p = _part(id);
    return p != null && p['left_at'] == null && p['mode'] != 'declined';
  }

  bool _isLead() {
    final i = _inc, p = _part();
    if (i == null || p == null) return false;
    return i.createdBy == _meId || (p['role_id'] != null && _role(p['role_id'] as int)['is_lead'] == 1) || _mode(p['mode'] as String?)?['lead_rights'] == 1;
  }

  bool _canManage() => _active() && (_isLead() || lead);
  _Inc _needInc() => _inc ?? _fail('Kein aktiver Einsatz');
  _Inc _needLead([String m = 'Nur die Einsatzleitung kann das']) {
    final i = _needInc();
    if (!_canManage()) _fail(m, 403);
    return i;
  }

  _Inc _needPart([String m = 'Nur Personen, die den Einsatz angenommen haben, können das']) {
    final i = _needInc();
    if (!_active() && !_canManage()) _fail(m, 403);
    return i;
  }

  _J _kindOf(_Inc i) => _ks.firstWhere((k) => k['id'] == i.kindId, orElse: () => _ks.first);
  double _d(dynamic v) => v is num ? v.toDouble() : (double.tryParse('$v') ?? double.nan);
  bool _fin(dynamic v) => v != null && _d(v).isFinite;
  List<_P> _ptsIn(dynamic v) => (v is List ? v : const []).map<_P>((q) {
    if (q is! List || q.length != 2 || !_fin(q[0]) || !_fin(q[1])) _fail('Ungültige Koordinaten');
    return [double.parse(_d(q[0]).toStringAsFixed(7)), double.parse(_d(q[1]).toStringAsFixed(7))];
  }).toList();
  String _unknownName() {
    final n = _inc!.res.where((r) => '${r['name']}'.startsWith('Unbekannter Rettling')).length;
    return n > 0 ? 'Unbekannter Rettling ${n + 1}' : 'Unbekannter Rettling';
  }

  _Inc _newInc(String message, double? lat, double? lon, int at, int by, int kindId, int record, int air, String script) => _Inc(++_seqInc, message, lat, lon, at, by, kindId, record, air, script);

  _J _addPart(int uid, String m, [int? ts]) {
    final i = _inc!;
    var p = _part(uid);
    final prev = p == null ? null : Map<String, dynamic>.from(p);
    if (p == null) {
      p = {'id': i.parts.length + 1, 'incident_id': i.id, 'user_id': uid, 'mode': m, 'role_id': null, 'lat': null, 'lon': null, 'loc_at': null, 'left_at': null, 'label': null};
      i.parts.add(p);
    }
    p['mode'] = m;
    p['left_at'] = null;
    final md = _mode(m)!;
    if (md['share_location'] == 0) p['lat'] = p['lon'] = p['loc_at'] = null;
    if (md['lead_rights'] == 1) p['role_id'] = null;
    if (prev == null || prev['mode'] != m || prev['left_at'] != null) {
      _logev(uid, 'accept', '${prev != null && prev['left_at'] != null ? 'ist wieder eingestiegen' : 'hat den Einsatz angenommen'} (${md['name']})', ts);
    }
    return p;
  }

  void _setRole(int uid, int? rid, [int? ts]) {
    final p = _part(uid);
    if (p == null || p['role_id'] == rid) return;
    p['role_id'] = rid;
    _logev(uid, 'role', rid != null ? 'übernimmt die Rolle ${_role(rid)['name']}' : 'legt die Spezialrolle ab', ts);
  }

  _J _addMsg(int uid, String text, {int? ts, List<int>? to, _P? mark}) {
    final p = _part(uid);
    final m = <String, dynamic>{
      'id': _seqMsg++,
      'from_user': uid,
      'text': text,
      'created': ts ?? _now(),
      'recipients': to,
      'lat': p?['lat'],
      'lon': p?['lon'],
      'mark': mark != null ? 1 : 0,
      'mark_lat': mark?[0],
      'mark_lon': mark?[1],
    };
    _inc!.msgs.add(m);
    return m;
  }

  _J _addPoi(int uid, String kind, String name, String color, List<_P> pts, String? sym, [int? ts]) {
    final p = <String, dynamic>{'id': _seqPoi++, 'kind': kind, 'name': name, 'color': color, 'pts': pts, 'sym': sym, 'created_by': uid, 'created': ts ?? _now(), 'removed': null};
    _inc!.pois.add(p);
    String txt;
    if (kind == 'point') {
      final s = _sym(sym);
      txt = 'setzt die Markierung «$name»${s != null && name != s['name'] ? ' (Symbol ${s['name']})' : ''} (${_f5(pts[0][0])}, ${_f5(pts[0][1])})';
    } else if (kind == 'line') {
      var m = 0.0;
      for (var j = 1; j < pts.length; j++) {
        m += _hav(pts[j - 1][0], pts[j - 1][1], pts[j][0], pts[j][1]);
      }
      txt = 'zeichnet die Linie «$name» (${pts.length} Punkte, ca. ${(m / 1000).toStringAsFixed(m < 1e4 ? 2 : 1)} km)';
    } else {
      final k = 111320 * math.cos(pts[0][0] * math.pi / 180);
      var a = 0.0;
      for (var j = 0; j < pts.length; j++) {
        final u = pts[j], v = pts[(j + 1) % pts.length];
        a += u[1] * k * v[0] * 111320 - v[1] * k * u[0] * 111320;
      }
      txt = 'markiert den Bereich «$name» (${pts.length} Punkte, ca. ${(a.abs() / 2 / 1e4).toStringAsFixed(1)} ha)';
    }
    _logev(uid, 'poi', txt, ts);
    return p;
  }

  _J _addRes(int uid, String name, String descr, [int? ts]) {
    final r = <String, dynamic>{
      'id': _seqRes++,
      'name': name,
      'descr': descr,
      'lat': null,
      'lon': null,
      'loc_at': null,
      'loc_by': null,
      'rescued_at': null,
      'rescued_by': null,
      'created_by': uid,
      'created': ts ?? _now(),
      'pls_lat': null,
      'pls_lon': null,
      'pls_at': null,
      'removed': null,
    };
    _inc!.res.add(r);
    _logev(uid, 'rescuee', 'erfasst den Rettling «$name»${descr.isNotEmpty ? ': $descr' : ''}', ts);
    return r;
  }

  void _locRes(int uid, _J r, _P ll, bool own, [int? ts]) {
    r['lat'] = ll[0];
    r['lon'] = ll[1];
    r['loc_at'] = ts ?? _now();
    r['loc_by'] = uid;
    _logev(uid, 'resmark', 'markiert den Standort von «${r['name']}» ${own ? 'am eigenen Standort' : 'auf der Karte'} (${_f5(ll[0])}, ${_f5(ll[1])})', ts);
  }

  void _rescue(int uid, _J r, bool on, [int? ts]) {
    if (on == (r['rescued_at'] != null)) return;
    r['rescued_at'] = on ? ts ?? _now() : null;
    r['rescued_by'] = on ? uid : null;
    _logev(uid, 'rescued', on ? 'meldet «${r['name']}» als gerettet' : 'nimmt «gerettet» bei «${r['name']}» zurück', ts);
  }

  _J _addExt(int uid, _J o, [int? ts]) {
    final e = <String, dynamic>{
      'id': _seqExt++,
      'name': o['name'],
      'descr': o['descr'] ?? '',
      'role_id': o['role_id'],
      'abbr': o['abbr'],
      'color': o['color'],
      'lat': o['lat'],
      'lon': o['lon'],
      'created_by': uid,
      'created': ts ?? _now(),
      'moved': null,
      'removed': null,
      'manual': o['manual'] == true || o['manual'] == 1 ? 1 : 0,
    };
    _inc!.ext.add(e);
    _logev(
      uid,
      'ext',
      'erfasst die externe Einsatzkraft «${e['name']}» (${e['role_id'] != null ? _role(e['role_id'] as int)['name'] : 'ohne Spezialrolle'}, ${_f5(e['lat'] as num)}, ${_f5(e['lon'] as num)})',
      ts,
    );
    return e;
  }

  void _endInc(int uid, [int? t]) {
    final i = _inc;
    if (i == null) return;
    for (final v in _vs) {
      v['inc'] = 0;
    }
    i.record = 0;
    i.endedAt = t ?? _now();
    _logev(uid, 'end', 'hat den Einsatz beendet', t);
    _last = {'id': i.id, 'ended_at': i.endedAt};
    _inc = null;
  }

  // ---------- Szenarien ----------
  void _bspStart(int t0) {
    _inc = _newInc('Person beim Nadelwehr in die Reuss gestürzt, treibt flussabwärts Richtung Reusszopf. Einsatz Wasserrettung.', _pls[0], _pls[1], t0, 101, 1, 0, 1, 'bsp');
    _logev(101, 'start', 'hat den Einsatz gestartet (Einsatz, mit Helikoptern): ${_inc!.message}', t0);
    _simRun(t0 + 150, init: true);
  }

  static final _bsp = <_Step>[
    _Step(20, 101, (d, t) => d._addPart(101, 'direct', t)),
    _Step(25, 101, (d, t) => d._setRole(101, 1, t)),
    _Step(40, 102, (d, t) => d._addPart(102, 'base', t)),
    _Step(45, 102, (d, t) => d._setRole(102, 2, t)),
    _Step(60, 101, (d, t) => d._addRes(101, 'Unbekannter Rettling', 'Mann, ca. 40 Jahre, dunkle Jacke, Jeans. Von Passanten beim Nadelwehr gesehen.', t)),
    _Step(75, 101, (d, t) {
      d._inc!.flow = 1.8;
      d._logev(101, 'flow', 'setzt die Fliessgeschwindigkeit auf 1.8 m/s', t);
    }),
    _Step(90, 101, (d, t) => d._addPoi(101, 'point', 'Kommandoposten', '#d62828', [_kp], 'sw-kp', t)),
    _Step(
      110,
      101,
      (d, t) => d._addExt(101, {'name': 'Feuerwehr Luzern', 'descr': 'Beobachtung von der Brücke Reussbühl', 'role_id': 4, 'abbr': 'FW', 'color': '#e53935', 'lat': _fw[0], 'lon': _fw[1]}, t),
    ),
    _Step(130, 101, (d, t) => d._addMsg(101, 'KP bei der Spreuerbrücke. Boote via Stützpunkt, Einwassern beim Reusszopf. Schlitten sucht das linke Ufer ab Geissmattbrücke ab.', ts: t)),
    _Step(170, 103, (d, t) => d._addPart(103, 'direct', t)),
    _Step(175, 103, (d, t) => d._setRole(103, 2, t)),
    _Step(200, 104, (d, t) => d._addPart(104, 'base', t)),
    _Step(210, 104, (d, t) => d._setRole(104, 3, t)),
    _Step(230, 102, (d, t) => d._addMsg(102, 'Boot 1 ab Stützpunkt unterwegs zur Einwasserung Reusszopf, ETA 3 min.', ts: t)),
    _Step(260, 105, (d, t) => d._addPart(105, 'direct', t)),
    _Step(265, 105, (d, t) => d._setRole(105, 4, t)),
    _Step(300, 101, (d, t) => d._addPoi(101, 'area', 'Suchsektor Boot 1', '#1d7fd1', _sector.map((p) => [p[0], p[1]]).toList(), null, t)),
    _Step(330, 106, (d, t) => d._addPart(106, 'logistics', t)),
    _Step(365, 102, (d, t) => d._addMsg(102, 'Boot 1 im Wasser, suchen flussaufwärts Richtung Geissmattbrücke.', ts: t)),
    _Step(420, 103, (d, t) => d._addMsg(103, 'Dunkle Jacke im Ufergebüsch rechts gesichtet, Markierung gesetzt.', ts: t, mark: _jacke)),
    _Step(470, 105, (d, t) => d._addMsg(105, 'Sicherungsposten Emmenmündung bezogen, Wurfsack bereit.', ts: t)),
    _Step(490, 104, (d, t) {
      final r = d._inc!.res.firstOrNull;
      if (r != null && r['lat'] == null && r['removed'] == null) {
        d._locRes(104, r, _find, true, t);
        d._addMsg(104, 'Rettling am linken Ufer gesichtet, hält sich an einem Ast fest! Boot 1 bitte kommen.', ts: t);
      }
    }),
    _Step(540, 102, (d, t) {
      final r = d._inc!.res.firstOrNull;
      if (r != null && r['removed'] == null) {
        d._rescue(102, r, true, t);
        d._addMsg(102, 'Person geborgen, ansprechbar, stark unterkühlt. Übergabe an den Rettungsdienst bei der Einwasserung Reusszopf.', ts: t);
      }
    }),
    _Step(560, 106, (d, t) => d._addMsg(106, 'Decken und Wärmepacks beim KP bereit.', ts: t)),
    _Step(620, 101, (d, t) => d._addMsg(101, 'Danke allen. Rettling dem Rettungsdienst übergeben. Rückzug zum Stützpunkt, Material kontrollieren.', ts: t)),
    _Step(1200, 101, (d, t) {
      if (!d.lead && d._inc != null) d._endInc(101, t);
    }),
    // Fahrzeuge (Issue #177): Einsatzleitung im Kommandofahrzeug, Boot 1 mit Bus und Anhänger zur Einwasserung, dort abgeladen, nach der Rettung wieder verladen
    _Step(20, 101, (d, t) => d._vehCrew(101, 4)),
    _Step(40, 102, (d, t) => d._vehCrew(102, 1)),
    _Step(345, 102, (d, t) {
      d._vehAt(102, t);
      d._vehSet(d._vById(3), null, null, true, t);
      d._vehCrew(102, 3);
      d._vehCrew(103, 3);
    }),
    _Step(650, 102, (d, t) {
      d._vehAt(102, t);
      d._vehCrew(103, null);
      d._vehCrew(102, 1);
      d._vehSet(d._vById(3), d._vById(2), null, true, t);
    }),
  ];
  static final _gen = <_Step>[
    _Step(15, 101, (d, t) => d._addPart(101, d._mc('direct'), t)),
    _Step(18, 101, (d, t) => d._setRole(101, d._rl(1), t)),
    _Step(25, 102, (d, t) => d._addPart(102, d._mc('base'), t)),
    _Step(28, 102, (d, t) => d._setRole(102, d._rl(2), t)),
    _Step(40, 101, (d, t) => d._addMsg(101, 'Bin unterwegs zum PLS und übernehme die Einsatzleitung vor Ort.', ts: t)),
    _Step(45, 103, (d, t) => d._addPart(103, d._mc('direct'), t)),
    _Step(48, 103, (d, t) => d._setRole(103, d._rl(2), t)),
    _Step(60, 104, (d, t) => d._addPart(104, d._mc('base'), t)),
    _Step(63, 104, (d, t) => d._setRole(104, d._rl(3), t)),
    _Step(90, 102, (d, t) => d._addMsg(102, 'Boot wird am Stützpunkt vorbereitet, ETA ca. 8 min.', ts: t)),
    _Step(100, 105, (d, t) => d._addPart(105, d._mc('direct'), t)),
    _Step(103, 105, (d, t) => d._setRole(105, d._rl(4), t)),
    _Step(130, 106, (d, t) => d._addPart(106, d._mc('logistics'), t)),
    _Step(220, 101, (d, t) => d._addMsg(101, 'Vor Ort beim PLS. Bitte Standort teilen und Suchgebiet gemäss Karte absuchen.', ts: t)),
    _Step(320, 105, (d, t) => d._addMsg(105, 'Posten flussabwärts bezogen.', ts: t)),
    // Fahrzeuge (Issue #177): Boot beim Ziel abgeladen
    _Step(15, 101, (d, t) => d._vehCrew(101, 4)),
    _Step(25, 102, (d, t) => d._vehCrew(102, 1)),
    _Step(300, 102, (d, t) {
      d._vehAt(102, t);
      d._vehSet(d._vById(3), null, null, true, t);
      d._vehCrew(102, 3);
    }),
  ];

  /// Bezugspunkt eines eigenen Einsatzes: PLS, sonst PLS eines Rettlings, sonst eigener Standort
  _P? _anchor(_Inc i) {
    if (i.lat != null) return [i.lat!, i.lon!];
    final r = i.res.where((r) => r['pls_lat'] != null).firstOrNull;
    if (r != null) return [_d(r['pls_lat']), _d(r['pls_lon'])];
    final p = _part();
    return p != null && p['lat'] != null ? [_d(p['lat']), _d(p['lon'])] : null;
  }

  List<List<double>>? _route(_Inc i, int uid) {
    if (i.script == 'bsp') return _bspRoute[uid];
    final g = _genRoute[uid];
    if (g == null) return null;
    if (!i.homes.containsKey(uid)) {
      final a = _anchor(i);
      if (a == null) return null;
      final f = g[1] as List, to = g[2] as List;
      i.homes[uid] = [_off(a, f[0] as num, f[1] as num), _off(a, to[0] as num, to[1] as num)];
    }
    final h = i.homes[uid]!;
    return [
      [(g[0] as int).toDouble(), h[0][0], h[0][1]],
      [(g[3] as int).toDouble(), h[1][0], h[1][1]],
    ];
  }

  _P? _posAt(List<List<double>>? r, num t) {
    if (r == null || r.isEmpty) return null;
    if (t <= r[0][0]) return [r[0][1], r[0][2]];
    for (var j = 1; j < r.length; j++) {
      if (t <= r[j][0]) {
        final a = r[j - 1], b = r[j], f = (t - a[0]) / math.max(1, b[0] - a[0]);
        return [a[1] + (b[1] - a[1]) * f, a[2] + (b[2] - a[2]) * f];
      }
    }
    return [r.last[1], r.last[2]];
  }

  /// Simulation bis t: fällige Schritte einmal ausführen (auch rückwirkend), Standorte nachführen, Antworten senden
  void _simRun(int t, {bool init = false}) {
    final i = _inc;
    if (i == null || i.endedAt != null) return;
    if (!sim && !init) return;
    final steps = i.script == 'bsp' ? _bsp : _gen, rel = t - i.t0;
    for (var k = 0; k < steps.length; k++) {
      final s = steps[k];
      if (i.done.contains(k) || rel < s.at) continue;
      i.done.add(k);
      if (_part(s.uid)?['kicked'] == 1) continue;
      s.fn(this, i.t0 + s.at);
      if (_inc == null) return;
    }
    for (final p in i.parts) {
      final u = p['user_id'] as int;
      if (u == _meId || p['left_at'] != null || p['mode'] == 'declined' || _mode(p['mode'] as String?)?['share_location'] == 0) continue;
      final ll = _posAt(_route(i, u), rel);
      if (ll == null) continue;
      final q = _off(ll, math.sin(t / 23 + u) * 4, math.cos(t / 31 + u) * 4);
      p['lat'] = double.parse(q[0].toStringAsFixed(7));
      p['lon'] = double.parse(q[1].toStringAsFixed(7));
      p['loc_at'] = t;
    }
    _vehSim(i, t);
    _reply.removeWhere((r) {
      if ((r['at'] as int) > t) return false;
      if (_inc != null && _active(r['uid'] as int)) _addMsg(r['uid'] as int, '${r['text']}', ts: r['at'] as int, to: [_meId]);
      return true;
    });
  }

  // ---------- Fahrzeuge (Issue #177, wie vehState/vehLinkSet/vehRoom im Server und vehOut in web/demo.js) ----------
  // Fiktive Fahrzeuge mit Beacons beim Stützpunkt bzw. beim ersten festen Standort der Organisation. Die Simulation setzt Einsatzkräfte in Fahrzeuge
  // (veh_id; echt erkennt das die App über die Beacons); ein Fahrzeug mit Besatzung fährt mit ihr, verladene Fahrzeuge mit dem Träger.
  static const _vKinds = <_J>[
    {
      'id': 1,
      'name': 'Zug-/Personenfahrzeug',
      'sym': '🚐',
      'persons': 1,
      'water': 0,
      'carries': {'2': 1},
      'carry_max': null,
    },
    {
      'id': 2,
      'name': 'Anhänger',
      'sym': '⛓',
      'persons': 0,
      'water': 0,
      'carries': {'3': 1},
      'carry_max': null,
    },
    {'id': 3, 'name': 'Boot', 'sym': '⛵', 'persons': 1, 'water': 1, 'carries': <String, dynamic>{}, 'carry_max': null},
  ];
  List<_J>? _veh;
  List<_J> get _vs => _veh ??= _vehFresh();
  List<_J> _vehFresh() {
    final f = _ps.where((p) => p['active'] != 0 && p['plan_id'] == null && _fin(p['lat']) && _fin(p['lon'])).firstOrNull;
    final _P h = f != null ? [_d(f['lat']), _d(f['lon'])] : [_sp[0], _sp[1]];
    final t = _now() - 3600;
    _J v(int id, int k, String name, String abbr, String color, String descr, int? c, num e, num n) {
      final q = _off(h, e, n);
      return {
        'id': id, 'kind_id': k, 'name': name, 'descr': descr, 'abbr': abbr, 'color': color, //
        'lat': double.parse(q[0].toStringAsFixed(7)), 'lon': double.parse(q[1].toStringAsFixed(7)), 'acc': 15.0, 'seen_at': t, 'inc': 0, 'carrier_id': c, 'carrier_manual': 0,
      };
    }

    return [
      v(1, 1, 'Bus 1', 'B1', '#1d7fd1', 'Mannschaftsbus mit Anhängerkupplung (Demo)', null, 10, 8),
      v(2, 2, 'Anhänger 1', 'A1', '#1d7fd1', 'Bootsanhänger (Demo)', 1, 10, 8),
      v(3, 3, 'Boot 1', 'RB1', '#1d7fd1', 'Rettungsboot (Demo)', 2, 10, 8),
      v(4, 1, 'Kommandofahrzeug', 'KDO', '#d62828', 'Fahrzeug der Einsatzleitung (Demo)', null, -12, -6),
    ];
  }

  _J? _vById(int? id) => id == null ? null : _vs.where((v) => v['id'] == id).firstOrNull;
  _J _vKind(_J v) => _vKinds.firstWhere((k) => k['id'] == v['kind_id'], orElse: () => _vKinds.first);
  Map<String, dynamic> _vCap(_J v) => Map<String, dynamic>.from(_vKind(v)['carries'] as Map);
  _J _vRoot(_J v) {
    var r = v;
    for (var n = 0; n < 20 && r['carrier_id'] != null && _vById(r['carrier_id'] as int) != null; n++) {
      r = _vById(r['carrier_id'] as int)!;
    }
    return r;
  }

  List<_J> _vTree(_J v) {
    final o = [v];
    for (var k = 0; k < o.length && k < 50; k++) {
      for (final x in _vs) {
        if (x['carrier_id'] == o[k]['id'] && !o.contains(x)) o.add(x);
      }
    }
    return o;
  }

  /// freie Plätze für die Art kid auf c: -1 = nimmt sie nicht auf; die Gesamtzahl (carry_max) begrenzt über alle Arten
  int _vRoom(_J c, int kid, int ex) {
    final cap = _vCap(c);
    if (!cap.containsKey('$kid')) return -1;
    final on = _vs.where((x) => x['carrier_id'] == c['id'] && x['id'] != ex).toList(), m = cap['$kid'], t = _vKind(c)['carry_max'];
    final r = m == null ? 1 << 30 : (m as int) - on.where((x) => x['kind_id'] == kid).length;
    return t == null ? r : math.min(r, (t as int) - on.length);
  }

  void _vMove(_J v, _P ll, int t, _Inc? i) {
    for (final x in _vTree(_vRoot(v))) {
      if (i != null && x['inc'] != 1) {
        x['inc'] = 1;
        _logev(null, 'veh', '«${x['name']}» gesehen (${_f5(ll[0])}, ${_f5(ll[1])})', t);
      }
      x['lat'] = double.parse(ll[0].toStringAsFixed(7));
      x['lon'] = double.parse(ll[1].toStringAsFixed(7));
      x['acc'] = 10.0;
      x['seen_at'] = t;
    }
  }

  /// Verbund setzen bzw. lösen (c null), Texte wie vehLinkText im Server
  void _vehSet(_J? v, _J? c, int? uid, bool auto, int t) {
    if (v == null || v['carrier_id'] == c?['id']) return;
    final old = _vById(v['carrier_id'] as int?), a = '«${v['name']}»';
    bool hang(_J x) => _vKind(x)['persons'] == 1;
    v['carrier_id'] = c?['id'];
    v['carrier_manual'] = c != null && !auto ? 1 : 0;
    if (c != null) {
      for (final x in _vTree(v)) {
        x['lat'] = c['lat'];
        x['lon'] = c['lon'];
        x['seen_at'] = c['seen_at'];
      }
      for (final p in _inc?.parts ?? const <_J>[]) {
        if (p['veh_id'] == v['id']) p['veh_id'] = null;
      }
    }
    if (_inc == null) return;
    final by = auto ? null : uid;
    if (old != null) {
      _logev(
        by,
        'veh',
        auto ? '$a von «${old['name']}» ${hang(old) ? 'abgehängt' : 'abgeladen'} (automatisch)' : (hang(old) ? 'hängt $a von «${old['name']}» ab' : 'lädt $a von «${old['name']}» ab'),
        t,
      );
    }
    if (c != null) {
      _logev(
        by,
        'veh',
        auto ? '$a ${hang(c) ? 'an' : 'auf'} «${c['name']}» ${hang(c) ? 'angehängt' : 'verladen'} (automatisch)' : (hang(c) ? 'hängt $a an «${c['name']}» an' : 'verlädt $a auf «${c['name']}»'),
        t,
      );
    }
  }

  void _vehCrew(int uid, int? vid) => _part(uid)?['veh_id'] = vid;

  /// Fahrzeug der Person an den Ort der Person zum Zeitpunkt t (Schritte laufen auch rückwirkend, z. B. nach einer Pause)
  void _vehAt(int uid, int t) {
    final i = _inc, p = _part(uid), v = _vById(p?['veh_id'] as int?);
    if (i == null || v == null || v['carrier_id'] != null) return;
    final ll = _posAt(_route(i, uid), t - i.t0);
    if (ll != null) _vMove(v, ll, t, i);
  }

  /// Fahrzeuge mit Besatzung fahren mit ihr (Kette beim obersten Fahrzeug)
  void _vehSim(_Inc i, int t) {
    if (_featOf(_kindOf(i))['veh'] != 1) return;
    for (final p in i.parts) {
      if (p['veh_id'] == null || p['left_at'] != null || p['mode'] == 'declined' || p['lat'] == null) continue;
      final v = _vById(p['veh_id'] as int);
      if (v != null && v['carrier_id'] == null) _vMove(v, [_d(p['lat']), _d(p['lon'])], t, i);
    }
  }

  _J _vehOut(_Inc? i) {
    final l = _vs;
    return {
      'kinds': _vKinds,
      'list': l.map((v) {
        final room = {
          for (final e in _vCap(v).entries) e.key: {'n': l.where((x) => x['carrier_id'] == v['id'] && '${x['kind_id']}' == e.key).length, 'max': e.value},
        };
        return {
          ...v,
          'inc': i != null && v['inc'] == 1 ? 1 : 0,
          'room': room,
          'room_all': {'n': l.where((x) => x['carrier_id'] == v['id']).length, 'max': _vKind(v)['carry_max']},
          'crew': i == null ? <int>[] : i.parts.where((p) => p['veh_id'] == v['id'] && p['left_at'] == null && p['mode'] != 'declined').map((p) => p['user_id'] as int).toList(),
        };
      }).toList(),
      'hints': [],
      'stale_min': 30,
      'can_link': i != null && (_active() || _canManage()) ? 1 : 0,
    };
  }

  /// Rega-Helikopter über dem Suchgebiet (Beispieldaten) bzw. über dem Bezugspunkt (eigener Einsatz)
  List<_J> _heli(int t) {
    final i = _inc;
    if (i == null || i.air == 0) return [];
    final bsp = i.script == 'bsp', c = bsp ? _find : _anchor(i), from = bsp ? 380 : 150, to = bsp ? 660 : 480, rel = t - i.t0;
    if (c == null || rel < from - 60 || rel > to + 80) return [];
    (List<double>, double, int) at(int x) {
      final r = x - i.t0;
      List<double> ll;
      double trk;
      double alt;
      if (r < from) {
        final f = (r - from + 60) / 60;
        ll = _off(c, 6000 * (1 - f) + 600, 9000 * (1 - f));
        trk = 214;
        alt = 2600 - 1000 * f;
      } else if (r <= to) {
        final w = (r - from) / 90 * 2 * math.pi;
        ll = _off(c, 600 * math.cos(w), 600 * math.sin(w));
        trk = (360 - w * 180 / math.pi) % 360;
        alt = 1600;
      } else {
        final f = (r - to) / 80;
        ll = _off(c, 600 + 9000 * f, 6000 * f);
        trk = 56;
        alt = 1600 + 1500 * f;
      }
      return (ll, (trk + 360) % 360, alt.round());
    }

    final (ll, trk, alt) = at(t);
    final pl = i.lat != null ? _hav(i.lat!, i.lon!, ll[0], ll[1]) : null;
    return [
      {
        'hex': '4b0d3e',
        'cs': 'RGA3',
        'reg': 'HB-ZRD',
        'type': 'EC45',
        'desc': 'Airbus H145 (Demo)',
        'cat': 'A7',
        'lat': ll[0],
        'lon': ll[1],
        'alt': alt,
        'gnd': 0,
        'gs': rel >= from && rel <= to ? 60 : 110,
        'trk': trk,
        'vr': 0,
        'at': t,
        'rescue': 'Rega',
        'pls_m': pl?.round(),
        'near': null,
        'trail': [60, 45, 30, 15].map((d) => t - d).where((x) => x - i.t0 >= from - 60).map((x) {
          final (q, _, h) = at(x);
          return [x, double.parse(q[0].toStringAsFixed(6)), double.parse(q[1].toStringAsFixed(6)), h];
        }).toList(),
      },
    ];
  }

  // ---------- Antworten ----------
  _J? _absNow() {
    final t = _now();
    return abs.where((a) => a['removed'] == 0 && (a['from_at'] as int) <= t && (a['until_at'] == null || (a['until_at'] as int) > t)).firstOrNull;
  }

  _J _meOut() => {
    ..._me,
    'must_change': 0,
    'uroles': [
      {
        'id': 3,
        'name': 'Einsatzkraft (Demo)',
        'sys': null,
        'perms': ['inc_start', 'ex_start'],
      },
    ],
    'is_admin': 0,
    'perms': ['inc_start', 'ex_start', if (lead) 'inc_lead'],
    'coord_default': 'lv95',
    'rd_speeds': [10, 20, 30],
    'kinds': _ks,
    'nav_apps': ['google', 'apple', 'waze', 'geo'],
    'nav_default': 'google',
    'symbols': _sy,
    'sym_free': 1,
    'sym_sets': {'sz': 'Taktische Symbole', 'sw': 'Swiftwater'},
    if (org != null) ...Map<String, dynamic>.from(org!['cfg'] as Map),
    'features': _featAll,
    'my_runs': 'off',
    'groups': [_groups.first],
    'absent': _absNow(),
    'ai': null,
    'org': '${org?['name'] ?? ''}'.isNotEmpty ? 'Demo-Modus · ${org!['name']}' : 'Demo-Modus',
    'session': 0,
    'demo': mode,
  };

  List<_J> _sources(_Inc i, List<_J> res) {
    final ip = i.lat != null ? <String, dynamic>{'lat': i.lat, 'lon': i.lon, 'at': i.plsAt ?? i.alarmAt, 'own': 0} : null;
    if (res.isEmpty) {
      return ip != null
          ? [
              {...ip, 'names': <String>[]},
            ]
          : [];
    }
    final o = <String, _J>{};
    for (final r in res) {
      if (r['rescued_at'] != null || r['lat'] != null) continue;
      final p = r['pls_lat'] != null ? <String, dynamic>{'lat': r['pls_lat'], 'lon': r['pls_lon'], 'at': r['pls_at'], 'own': 1} : ip;
      if (p == null) continue;
      (o['${p['lat']},${p['lon']},${p['at']}'] ??= {...p, 'names': <String>[]})['names'].add(r['name']);
    }
    return o.values.toList();
  }

  List<_J> _logRows(_Inc i) {
    final rows = i.log
        .where((l) => l['kind'] != 'message')
        .map((l) => <String, dynamic>{'id': l['id'], 'ts': l['ts'], 'kind': l['kind'], 'text': l['text'], 'name': l['uid'] == null ? null : _uname(l['uid'] as int)})
        .toList();
    for (final m in i.msgs) {
      final rc = m['recipients'] as List<int>?, to = rc != null ? rc.map(_uname).join(', ') : 'alle';
      rows.add({
        'id': 1000000000 + (m['id'] as int),
        'ts': m['created'],
        'kind': 'message',
        'name': _uname(m['from_user'] as int),
        'text':
            'Nachricht an $to: ${m['text']}${m['lat'] != null ? ' · Standort ${_f5(m['lat'] as num)}, ${_f5(m['lon'] as num)}' : ''}'
            '${m['mark_lat'] != null ? ' · Kartenmarkierung ${_f5(m['mark_lat'] as num)}, ${_f5(m['mark_lon'] as num)}${m['mark'] == 1 ? '' : ' (entfernt)'}' : ''}',
      });
    }
    rows.sort((a, b) => (b['ts'] as int) != (a['ts'] as int) ? (b['ts'] as int) - (a['ts'] as int) : (b['id'] as int) - (a['id'] as int));
    return rows.take(1000).toList();
  }

  List<_J> _hydroInc(_Inc i) {
    if (i.lat == null && !i.res.any((r) => r['pls_lat'] != null)) return [];
    final a = _anchor(i) ?? _pls, t = i.alarmAt + 30, bsp = i.script == 'bsp';
    // Vorgaben der Organisation: wie der Server die nächsten Stationen mit Messwerten im Umkreis (15 km, höchstens 3, ohne Seen)
    final hs = org != null
        ? (_hy
                  .where((h) => h['type'] != 'lake' && (h['flow'] != null || h['height'] != null || h['level'] != null))
                  .map((h) => (_hav(a[0], a[1], _d(h['lat']), _d(h['lon'])), h))
                  .where((x) => x.$1 <= 15000)
                  .toList()
                ..sort((x, y) => x.$1.compareTo(y.$1)))
              .take(3)
              .map((x) => x.$2)
        : _hy.take(bsp ? 2 : 1);
    return hs.map((h) {
      final d = _hav(a[0], a[1], _d(h['lat']), _d(h['lon'])).round();
      return <String, dynamic>{
        'phase': 'start',
        'ts': t,
        'station': h['id'],
        'name': h['name'],
        'water': h['water'],
        'lat': h['lat'],
        'lon': h['lon'],
        'dist': bsp || org != null ? d : null,
        'at': t - 300,
        'danger': h['danger'],
        'v': {'flow': h['flow'], 'height': h['height'], 'level': h['level'], 'temp': h['temp'], 'danger': h['danger']},
      };
    }).toList();
  }

  _J _state(Map<String, String> q) {
    final t = _now();
    _simRun(t);
    final i = _inc;
    if (i == null) {
      return {
        'incident': null,
        'fb_open': 0,
        'air_home': 0,
        'my_abs': _absNow(),
        'blocked': 0,
        'last_ended': _last,
        'places': _ps,
        'place_cats': _pc,
        'kinds': _ks,
        'features': _featAll,
        'ex_plans': [],
        'ex_sched': [], // Übungsplan (Issue #169): im Demo keine vorbereiteten Übungen
        'veh': _ks.any((k) => _featOf(k)['veh'] == 1) ? _vehOut(null) : null,
        ..._mapsOut,
        ..._placeOut,
        'now': t,
      };
    }
    final k = _kindOf(i), since = int.tryParse(q['since'] ?? '') ?? 0, mgr = _canManage(), act = _active();
    final res = i.res.where((r) => r['removed'] == null).map((r) {
      final o = Map<String, dynamic>.from(r)..remove('removed');
      o['loc_name'] = r['loc_by'] != null ? _uname(r['loc_by'] as int) : null;
      o['rescued_name'] = r['rescued_by'] != null ? _uname(r['rescued_by'] as int) : null;
      return o;
    }).toList();
    final lvl = k['mode_up'] == 1 && _me['exercise_mode'] == 1 ? 'crit' : k['alarm'], canRead = act || mgr;
    _J msgOut(_J m) => {
      'id': m['id'],
      'from_user': m['from_user'],
      'text': m['text'],
      'created': m['created'],
      'recipients': m['recipients'],
      'lat': m['lat'],
      'lon': m['lon'],
      'mark_lat': m['mark_lat'],
      'mark_lon': m['mark_lon'],
      'from_name': _uname(m['from_user'] as int),
    };
    final itpl = <String, _J>{};
    for (final e in i.ext.reversed.where((e) => e['manual'] == 1)) {
      itpl.putIfAbsent('${e['name']}'.toLowerCase(), () => {'name': e['name'], 'descr': e['descr'], 'role_id': e['role_id'], 'abbr': e['abbr'], 'color': e['color']});
    }
    return {
      'incident': {
        'id': i.id,
        'message': i.message,
        'lat': i.lat,
        'lon': i.lon,
        'alarm_at': i.alarmAt,
        'ended_at': null,
        'flow_ms': i.flow,
        'created_by': i.createdBy,
        'is_exercise': 0,
        'record': i.record,
        'recorded': i.recorded,
        'public_anon': 1,
        'pls_at': i.plsAt ?? i.alarmAt,
        'plan_id': null,
        'public_log': 1,
        'rp_anon': null,
        'log_anon': 0,
        'air': i.air,
        'view_anon': 0,
        'txt_anon': 0,
        'part_anon': 0,
        'kind_id': k['id'],
        'open_join': i.openJoin,
        'stop_on': i.stopOn,
        'stop_off': i.stopOff,
        'kind_name': k['name'],
        'kind_color': k['color'],
        'kind': k,
        'public': 0,
        'aufgebot': null,
      },
      'pois': i.pois.where((p) => p['removed'] == null).map((p) {
        final s = _sym(p['sym'] as String?);
        return <String, dynamic>{
          'id': p['id'],
          'kind': p['kind'],
          'name': p['name'],
          'color': p['color'],
          'pts': p['pts'],
          'sym': p['sym'],
          'created_by': p['created_by'],
          'created': p['created'],
          'by_name': _uname(p['created_by'] as int),
          if (s != null) 'sym_name': s['name'],
          if (s != null) 'sym_foot': s['foot'],
        };
      }).toList(),
      'rescuees': res,
      'externals': i.ext
          .where((e) => e['removed'] == null)
          .map(
            (e) => Map<String, dynamic>.from(e)
              ..remove('removed')
              ..remove('manual')
              ..['by_name'] = _uname(e['created_by'] as int),
          )
          .toList(),
      'places': _ps,
      'place_cats': _pc,
      ..._placeOut,
      'stops': _stops(i),
      'ext_templates': mgr ? _ext : [],
      'ext_inc_templates': mgr ? itpl.values.toList() : [],
      'sources': _sources(i, res),
      'participants': i.parts.map((p) {
        final u = p['user_id'] as int;
        return {
          'user_id': u,
          'mode': p['mode'],
          'role_id': p['role_id'],
          'lat': p['lat'],
          'lon': p['lon'],
          'loc_at': p['loc_at'],
          'left_at': p['left_at'],
          'name': _uname(u),
          'photo': u == _meId ? _me['photo'] : null,
          'groups': u == _meId ? [1] : (_simGroups[u] ?? const []),
          if (_featOf(_kindOf(i))['veh'] == 1 && _featOf(_kindOf(i))['vehp'] == 1) 'veh_id': p['veh_id'],
        };
      }).toList(),
      'groups': _groups,
      'marks': [],
      'messages': canRead ? i.msgs.where((m) => (m['id'] as int) > since).map(msgOut).toList() : [],
      'msg_marks': canRead
          ? i.msgs
                .where((m) => m['mark'] == 1)
                .map(
                  (m) => {
                    'id': m['id'],
                    'from_user': m['from_user'],
                    'text': m['text'],
                    'created': m['created'],
                    'lat': m['mark_lat'],
                    'lon': m['mark_lon'],
                    'from_name': _uname(m['from_user'] as int),
                  },
                )
                .toList()
          : [],
      'me': _part() != null ? (Map<String, dynamic>.from(_part()!)..remove('kicked')) : null,
      'is_lead': _isLead(),
      'can_manage': mgr,
      'ai': null,
      'can_flow': act || mgr,
      'can_message': act || mgr,
      'can_read_messages': canRead,
      'critical': lvl == 'crit',
      'level': lvl,
      'msg_level': k['msg_lvl'],
      'end_level': k['end_lvl'],
      'alarm': i.createdBy != _meId,
      'absent': 0,
      'my_abs': _absNow(),
      'features': _featOf(k),
      ..._mapsOut,
      'air': i.air,
      'air_inc': i.air,
      'ex_lead': false,
      'ex_title': null,
      'ex_injects': [],
      'log': act || mgr ? _logRows(i) : null,
      'hydro': _hydroInc(i),
      'veh': _featOf(_kindOf(i))['veh'] == 1 ? _vehOut(i) : null,
      'now': t,
    };
  }

  String _opKey(_J b) => '${b['around'] ?? b['bbox']}';
  String _overpassQ(_J b) {
    String f(dynamic x) => _d(x).toStringAsFixed(5);
    String sel;
    if (b['around'] is List) {
      final a = (b['around'] as List).map(_d).toList();
      if (!(a[0] >= 100 && a[0] <= 30000)) _fail('Ungültiger Umkreis');
      sel = 'around:${a[0].round()},${f(a[1])},${f(a[2])}';
    } else if (b['bbox'] is List) {
      sel = (b['bbox'] as List).map(f).join(',');
    } else {
      _fail('Umkreis oder Ausschnitt fehlt');
    }
    return '[out:json][timeout:25];way($sel)["waterway"~"^(river|stream|canal)\$"];out geom;';
  }

  /// Konto für das Gewässernetz: das der Vorgaben, sonst das erste angemeldete (zuletzt verwendetes zuerst); ohne Konto null
  Account? _net;
  bool _netDone = false;
  Future<Account?> _netAcc() async {
    if (_src != null) return _src;
    if (_netDone) return _net;
    _netDone = true;
    try {
      final l = (await Accounts.load()).where((a) => !a.loggedOut && a.token.isNotEmpty).toList();
      final last = await prefs.getString('last_acc');
      _net = l.where((a) => a.id == last).firstOrNull ?? l.firstOrNull;
    } catch (_) {}
    return _net;
  }

  /// Aufruf wie Api.call: Antwort als Map (Listen als {'data': …}), Fehler als ApiError mit Status und Antwort
  Future<Map<String, dynamic>> call(String route, {Map<String, dynamic>? body, Map<String, String>? query}) async {
    await _ready;
    // öffentlich und nur lesend (Issue #175): mit Vorgaben vom Server des Kontos
    if ((route == 'version' || route == 'changes') && _src != null) return _src!.api.call(route, body: body, query: query);
    // Gewässernetz: angemeldet über den Server der Organisation (Cache, PostGIS, Overpass in deren Reihenfolge), nur lesend; overpass/put bleibt im Demo.
    // 502 (Gerät soll selbst fragen, mit den Servern der Organisation) und 503 (Overpass dort aus) gelten wie in der echten App; sonst wie bisher vom Gerät (realOverpass in web/demo.js)
    if (route == 'overpass' && !_opc.containsKey(_opKey(body ?? const {}))) {
      final a = await _netAcc();
      if (a != null) {
        try {
          return await a.api.call('overpass', body: {'around': body?['around'], 'bbox': body?['bbox']});
        } on ApiError catch (e) {
          if (e.status == 502 || e.status == 503) rethrow;
        } catch (_) {}
      }
    }
    await Future<void>.delayed(Duration(milliseconds: 40 + math.Random().nextInt(80))); // etwas Verzögerung wie übers Netz
    final o = _handle(route, body ?? const {}, query ?? const {});
    return o is Map<String, dynamic> ? o : {'data': o};
  }

  dynamic _handle(String r, _J b, Map<String, String> q) {
    final t = _now();
    switch (r) {
      case 'login':
        return {'token': 'demo'};
      case 'logout':
      case 'me/session/remove':
      case 'overpass/stat':
      case 'push/subscribe':
      case 'push/unsubscribe':
      case 'push/native/subscribe':
      case 'push/native/unsubscribe':
      case 'applog/client':
      case 'incident/mark/remove':
        return {'ok': true};
      case 'me':
        return {'me': _meOut()};
      case 'me/settings':
        for (final k in ['exercise_mode', 'coord_sys', 'nav_app', 'place_view', 'hydro', 'ai_modes']) {
          if (b.containsKey(k)) _me[k] = (k == 'coord_sys' || k == 'nav_app') && '${b[k] ?? ''}'.isEmpty ? null : b[k];
        }
        return {'ok': true};
      case 'me/password':
        _fail('Im Demo-Modus gibt es kein Passwort.');
      case 'me/photo':
        _me['photo'] = b['photo'];
        return {'ok': true};
      case 'me/sessions':
        return [];
      case 'roles':
        return _rs;
      case 'modes':
        return _ms;
      case 'incident/state':
        return _state(q);
      case 'start/options':
        return {
          'groups': [],
          'kinds': _ks,
          'start': {'1': null, '2': null},
          'add': {'1': null, '2': null},
          'people': [],
          'kind_people': {'1': null, '2': null},
          'kind_absent': {'1': 0, '2': 0},
        };
      case 'incident/start':
        if (_inc != null) _fail('Es läuft bereits ein Einsatz');
        final msg = '${b['message'] ?? ''}'.trim();
        if (msg.isEmpty) _fail('Die Meldung ist Pflicht');
        final k = _ks.firstWhere((x) => '${x['id']}' == '${b['kind_id']}', orElse: () => _ks.first);
        final at = _fin(b['alarm_at']) ? math.min(_d(b['alarm_at']).toInt(), t) : t, pls = _fin(b['lat']) && _fin(b['lon']);
        final rec = (b['record'] == true || b['record'] == 1) ? 1 : 0, air = b['air'] == null ? ((k['air'] as num?)?.toInt() ?? 0) : (b['air'] == true || b['air'] == 1 ? 1 : 0);
        _inc = _newInc(msg, pls ? _d(b['lat']) : null, pls ? _d(b['lon']) : null, at, _meId, k['id'] as int, rec, air, 'gen')..t0 = t;
        _logev(_meId, 'start', 'hat den Einsatz gestartet (${k['name']}${rec == 1 ? ', mit Aufzeichnung' : ''}${air == 1 ? ', mit Helikoptern' : ''}${pls ? '' : ', ohne PLS'}): $msg');
        return {'id': _inc!.id};
      case 'incident/join':
        final i = _needInc();
        if (b['mode'] == 'declined') {
          if (_part() == null) {
            i.parts.add({'id': i.parts.length + 1, 'incident_id': i.id, 'user_id': _meId, 'mode': 'declined', 'role_id': null, 'lat': null, 'lon': null, 'loc_at': null, 'left_at': null});
            _logev(_meId, 'decline', 'hat den Einsatz abgelehnt');
          }
          return {'ok': true};
        }
        final m = _mode('${b['mode'] ?? ''}');
        if (m == null || m['active'] != 1) _fail('Ungültige Auswahl');
        _addPart(_meId, m['code'] as String);
        return {'ok': true};
      case 'incident/leave':
        _needInc();
        final p = _part();
        if (p != null && p['left_at'] == null && p['mode'] != 'declined') {
          p['left_at'] = t;
          p['role_id'] = null;
          _logev(_meId, 'leave', 'hat den Einsatz verlassen');
        }
        return {'ok': true};
      case 'incident/role':
        _needInc();
        final p = _part() ?? _fail('Nicht im Einsatz');
        if (_mode(p['mode'] as String?)?['lead_rights'] == 1) _fail('Mit dieser Einrückoption gibt es keine Spezialrolle');
        _setRole(_meId, _fin(b['role_id']) && _d(b['role_id']) > 0 ? _d(b['role_id']).toInt() : null);
        return {'ok': true};
      case 'incident/role/set':
        _needLead('Nur die Einsatzleitung kann Spezialrollen anderer Personen ändern');
        final u = _d(b['user_id']).toInt(), p = _part(u);
        if (p == null || p['left_at'] != null) _fail('Die Person ist nicht im Einsatz');
        if (_mode(p['mode'] as String?)?['lead_rights'] == 1) _fail('Mit dieser Einrückoption gibt es keine Spezialrolle');
        final rid = _fin(b['role_id']) && _d(b['role_id']) > 0 ? _d(b['role_id']).toInt() : null;
        if (rid != p['role_id']) {
          p['role_id'] = rid;
          _logev(_meId, 'role', rid != null ? 'teilt ${_uname(u)} die Rolle ${_role(rid)['name']} zu' : 'entfernt die Spezialrolle von ${_uname(u)}');
        }
        return {'ok': true};
      case 'incident/location':
        _needInc();
        final p = _part();
        if (p == null || p['left_at'] != null || p['mode'] == 'declined' || _mode(p['mode'] as String?)?['share_location'] == 0) return {'ok': true};
        p['lat'] = _d(b['lat']);
        p['lon'] = _d(b['lon']);
        p['loc_at'] = t;
        return {'ok': true};
      case 'incident/event':
        final i = _needInc();
        if (b['kind'] == 'area' && !i.log.any((l) => l['uid'] == _meId && l['kind'] == 'area')) _logev(_meId, 'area', 'hat das Suchgebiet erreicht');
        return {'ok': true};
      case 'incident/flow':
        final i = _needPart('Nur Personen, die am Einsatz teilnehmen, können die Fliessgeschwindigkeit setzen');
        final v = _d(b['flow_ms']);
        if (!(v > 0 && v <= 15)) _fail('Fliessgeschwindigkeit muss zwischen 0 und 15 m/s liegen');
        i.flow = v;
        _logev(_meId, 'flow', 'setzt die Fliessgeschwindigkeit auf $v m/s');
        return {'ok': true};
      case 'incident/poi':
        _needLead('Nur die Einsatzleitung kann Markierungen und Bereiche setzen');
        final k = b['kind'] == 'area' || b['kind'] == 'line' ? b['kind'] as String : 'point', pts = _ptsIn(b['pts']);
        String? s;
        if (k == 'point') {
          s = '${b['sym'] ?? ''}'.trim();
          if (s.isEmpty) s = null;
          if (s != null && _sym(s) == null) _fail('Dieses Symbol steht nicht zur Wahl');
        }
        var n = '${b['name'] ?? ''}'.trim();
        if (n.isEmpty && s != null) n = '${_sym(s)!['name']}';
        if (n.isEmpty) _fail('Bezeichnung fehlt');
        if (k == 'point' ? pts.length != 1 : pts.length < (k == 'line' ? 2 : 3) || pts.length > 200) {
          _fail({'point': 'Für eine Markierung ist genau ein Punkt nötig', 'line': 'Eine Linie braucht 2 bis 200 Punkte', 'area': 'Ein Bereich braucht 3 bis 200 Punkte'}[k]!);
        }
        final col = RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch('${b['color'] ?? ''}') ? '${b['color']}' : '#ff9f1c';
        return {'ok': true, 'id': _addPoi(_meId, k, n.length > 80 ? n.substring(0, 80) : n, col, pts, s)['id']};
      case 'incident/poi/remove':
        final i = _needLead('Nur die Einsatzleitung kann Markierungen und Bereiche entfernen');
        final p = i.pois.where((x) => '${x['id']}' == '${b['id']}' && x['removed'] == null).firstOrNull ?? _fail('Markierung nicht gefunden', 404);
        p['removed'] = t;
        _logev(_meId, 'poidel', '${{'area': 'entfernt den Bereich «', 'line': 'entfernt die Linie «'}[p['kind']] ?? 'entfernt die Markierung «'}${p['name']}»');
        return {'ok': true};
      case 'incident/rescuee/save':
        final i = _needPart('Nur Personen, die den Einsatz angenommen haben, können Rettlinge erfassen');
        final r = b['id'] != null ? (i.res.where((x) => '${x['id']}' == '${b['id']}' && x['removed'] == null).firstOrNull ?? _fail('Rettling nicht gefunden', 404)) : null;
        var n = '${b['name'] ?? ''}'.trim();
        final d = '${b['descr'] ?? ''}'.trim();
        if (n.isEmpty) n = r != null && '${r['name']}'.startsWith('Unbekannter Rettling') ? '${r['name']}' : _unknownName();
        final has = b.containsKey('pls'), pj = has && b['pls'] is Map ? b['pls'] as Map : null;
        final pl = pj != null ? [_d(pj['lat']), _d(pj['lon']), _fin(pj['at']) ? _d(pj['at']).toInt() : (i.plsAt ?? i.alarmAt)] : [null, null, null];
        String plsTxt(String x) => pl[0] == null ? 'übernimmt für «$x» den PLS des Einsatzes' : 'setzt den PLS von «$x» (${_f5(pl[0]!)}, ${_f5(pl[1]!)}, ${_hm(pl[2] as int)})';
        if (r != null) {
          if (r['name'] != n || r['descr'] != d) {
            _logev(_meId, 'rescuee', '${r['name'] != n ? 'ändert den Rettling «${r['name']}» zu «$n»' : 'ändert die Beschreibung von «$n»'}${d.isNotEmpty ? ': $d' : ''}');
          }
          r['name'] = n;
          r['descr'] = d;
          if (has && (r['pls_lat'] != pl[0] || r['pls_lon'] != pl[1] || r['pls_at'] != pl[2])) {
            r['pls_lat'] = pl[0];
            r['pls_lon'] = pl[1];
            r['pls_at'] = pl[2];
            _logev(_meId, 'rescuee', plsTxt(n));
          }
          return {'ok': true, 'id': r['id']};
        }
        final x = _addRes(_meId, n, d);
        x['pls_lat'] = pl[0];
        x['pls_lon'] = pl[1];
        x['pls_at'] = pl[2];
        if (pl[0] != null) _logev(_meId, 'rescuee', plsTxt(n));
        return {'ok': true, 'id': x['id']};
      case 'incident/rescuee/locate':
        final i = _needPart('Nur Personen, die den Einsatz angenommen haben, können den Standort markieren');
        final r = i.res.where((x) => '${x['id']}' == '${b['id']}' && x['removed'] == null).firstOrNull ?? _fail('Rettling nicht gefunden', 404);
        if (b['clear'] == true || b['clear'] == 1) {
          if (r['lat'] != null) {
            r['lat'] = r['lon'] = r['loc_at'] = r['loc_by'] = null;
            _logev(_meId, 'resmark', 'setzt den Fundort von «${r['name']}» zurück');
          }
          return {'ok': true};
        }
        final own = b['own'] == true || b['own'] == 1;
        _P ll;
        if (own) {
          final p = _part();
          if (p == null || p['lat'] == null) _fail('Dein Standort ist nicht bekannt: Standortfreigabe erlauben oder Position auf der Karte wählen');
          ll = [_d(p['lat']), _d(p['lon'])];
        } else {
          if (!_fin(b['lat']) || !_fin(b['lon'])) _fail('Ungültige Koordinaten');
          ll = [_d(b['lat']), _d(b['lon'])];
        }
        _locRes(_meId, r, ll, own);
        return {'ok': true};
      case 'incident/rescuee/rescued':
        final i = _needPart('Nur Personen, die den Einsatz angenommen haben, können Rettlinge als gerettet markieren');
        final r = i.res.where((x) => '${x['id']}' == '${b['id']}' && x['removed'] == null).firstOrNull ?? _fail('Rettling nicht gefunden', 404);
        _rescue(_meId, r, b['rescued'] == true || b['rescued'] == 1);
        return {'ok': true};
      case 'incident/rescuee/remove':
        final i = _needLead('Nur die Einsatzleitung kann Rettlinge entfernen');
        final r = i.res.where((x) => '${x['id']}' == '${b['id']}' && x['removed'] == null).firstOrNull ?? _fail('Rettling nicht gefunden', 404);
        r['removed'] = t;
        _logev(_meId, 'rescuee', 'entfernt den Rettling «${r['name']}»');
        return {'ok': true};
      case 'incident/external/save':
        final i = _needLead('Nur die Einsatzleitung kann externe Einsatzkräfte erfassen');
        final n = '${b['name'] ?? ''}'.trim();
        if (n.isEmpty) _fail('Name fehlt');
        final ab = '${b['abbr'] ?? ''}'.trim(), col = RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch('${b['color'] ?? ''}') ? '${b['color']}' : null;
        final rid = _fin(b['role_id']) && _d(b['role_id']) > 0 ? _d(b['role_id']).toInt() : null, descr = '${b['descr'] ?? ''}';
        final ll = _fin(b['lat']) && _fin(b['lon']) ? [_d(b['lat']), _d(b['lon'])] : null;
        final st = {'abbr': ab.isEmpty ? null : (ab.length > 4 ? ab.substring(0, 4) : ab), 'color': col};
        if (b['id'] != null) {
          final e = i.ext.where((x) => '${x['id']}' == '${b['id']}' && x['removed'] == null).firstOrNull ?? _fail('Externe Einsatzkraft nicht gefunden', 404);
          if (e['name'] != n || e['descr'] != descr || e['role_id'] != rid) _logev(_meId, 'ext', 'ändert die externe Einsatzkraft «${e['name']}»${e['name'] != n ? ' zu «$n»' : ''}');
          e.addAll({'name': n, 'descr': descr, 'role_id': rid, ...st});
          if (ll != null && (ll[0] != e['lat'] || ll[1] != e['lon'])) {
            e['lat'] = ll[0];
            e['lon'] = ll[1];
            e['moved'] = t;
            _logev(_meId, 'ext', 'verschiebt die externe Einsatzkraft «$n» (${_f5(ll[0])}, ${_f5(ll[1])})');
          }
          return {'ok': true, 'id': e['id']};
        }
        if (ll == null) _fail('Standort fehlt');
        return {
          'ok': true,
          'id': _addExt(_meId, {'name': n, 'descr': descr, 'role_id': rid, ...st, 'lat': ll[0], 'lon': ll[1], 'manual': b['manual']})['id'],
        };
      case 'incident/external/remove':
        final i = _needLead('Nur die Einsatzleitung kann externe Einsatzkräfte entfernen');
        final e = i.ext.where((x) => '${x['id']}' == '${b['id']}' && x['removed'] == null).firstOrNull ?? _fail('Externe Einsatzkraft nicht gefunden', 404);
        e['removed'] = t;
        _logev(_meId, 'ext', 'entfernt die externe Einsatzkraft «${e['name']}»');
        return {'ok': true};
      case 'incident/kick':
        _needLead('Nur die Einsatzleitung kann Einsatzkräfte entfernen');
        final u = _d(b['user_id']).toInt();
        if (u == _meId) _fail('Zum Verlassen den Knopf «Einsatz verlassen» verwenden');
        final p = _part(u);
        if (p == null || p['left_at'] != null) _fail('Die Person ist nicht im Einsatz');
        p['left_at'] = t;
        p['role_id'] = null;
        p['kicked'] = 1;
        _logev(_meId, 'kick', 'hat ${_uname(u)} aus dem Einsatz entfernt');
        return {'ok': true};
      case 'incident/message':
        final i = _needInc();
        if (!_active()) _fail('Nachrichten können nur Personen senden, die den Einsatz angenommen haben');
        final tx = '${b['text'] ?? ''}'.trim();
        if (tx.isEmpty) _fail('Text fehlt');
        final act = i.parts.where((p) => p['left_at'] == null && p['mode'] != 'declined').map((p) => p['user_id'] as int).toList();
        List<int>? to;
        if (b['to'] is List && (b['to'] as List).isNotEmpty) {
          to = (b['to'] as List).map((x) => _d(x).toInt()).where(act.contains).toList();
          if (to.isEmpty) _fail('Die gewählten Personen haben den Einsatz nicht angenommen');
        }
        _P? mk;
        if (b['mark'] == true || b['mark'] == 1) {
          final p = _part()!;
          mk = _fin(b['mark_lat']) && _fin(b['mark_lon']) ? [_d(b['mark_lat']), _d(b['mark_lon'])] : (p['lat'] != null ? [_d(p['lat']), _d(p['lon'])] : null);
          if (mk == null) _fail('Für die Markierung ist ein Standort nötig: Position auf der Karte wählen');
        }
        _addMsg(_meId, tx, to: to, mark: mk);
        if (sim) {
          final r = (to ?? act).where((u) => u != _meId && _simNames.containsKey(u) && _active(u)).firstOrNull;
          if (r != null) _reply.add({'uid': r, 'at': t + 6, 'text': RegExp(r'\?\s*$').hasMatch(tx) ? 'Kläre ich ab und melde mich.' : 'Verstanden.'});
        }
        return {'ok': true};
      case 'incident/message/unmark':
        final i = _needInc();
        final m = i.msgs.where((x) => '${x['id']}' == '${b['id']}').firstOrNull ?? _fail('Nachricht nicht gefunden', 404);
        if (m['from_user'] != _meId && !_canManage()) _fail('Nur die sendende Person oder die Einsatzleitung kann die Markierung entfernen', 403);
        if (m['mark'] == 1) {
          m['mark'] = 0;
          _logev(_meId, 'markdel', 'entfernt die Kartenmarkierung einer Nachricht');
        }
        return {'ok': true};
      case 'incident/pls':
        final i = _needLead('Nur die Einsatzleitung kann den PLS des Einsatzes ändern');
        if (b['clear'] == true || b['clear'] == 1) {
          i.lat = i.lon = null;
          i.plsAt = null;
          _logev(_meId, 'pls', 'entfernt den PLS des Einsatzes');
          return {'ok': true};
        }
        if (!_fin(b['lat']) || !_fin(b['lon'])) _fail('Ungültige Koordinaten');
        final had = i.lat != null, at = _fin(b['at']) ? _d(b['at']).toInt() : i.alarmAt;
        i.lat = _d(b['lat']);
        i.lon = _d(b['lon']);
        i.plsAt = at == i.alarmAt ? null : at;
        _logev(_meId, 'pls', '${had ? 'ändert' : 'setzt'} den PLS des Einsatzes (${_f5(i.lat!)}, ${_f5(i.lon!)}, ${_hm(at)})');
        return {'ok': true};
      case 'incident/record':
        final i = _needLead('Nur die Einsatzleitung kann die Aufzeichnung steuern');
        final tr = {
          'start': ([0], 1, i.recorded == 1 ? 'nimmt die Aufzeichnung wieder auf' : 'startet die Aufzeichnung'),
          'pause': ([1], 2, 'pausiert die Aufzeichnung'),
          'resume': ([2], 1, 'setzt die Aufzeichnung fort'),
          'stop': ([1, 2], 0, 'beendet die Aufzeichnung'),
        }[b['action']];
        if (tr == null) _fail('Unbekannte Aktion');
        if (!tr.$1.contains(i.record)) return {'ok': true, 'record': i.record};
        i.record = tr.$2;
        if (tr.$2 > 0) i.recorded = 1;
        _logev(_meId, 'rec', tr.$3);
        return {'ok': true, 'record': i.record};
      case 'incident/public':
        _fail('Die öffentliche Ansicht gibt es im Demo-Modus nicht.');
      case 'incident/stops':
        final i = _needLead('Nur die Einsatzleitung kann Flusssperren ein- und ausschalten');
        if (b.containsKey('on')) {
          final on = b['on'] == true || b['on'] == 1 ? 1 : 0;
          if (on != i.stopOn) {
            i.stopOn = on;
            _logev(_meId, 'stop', on == 1 ? 'berücksichtigt die Flusssperren fester Standorte wieder im Suchgebiet' : 'berücksichtigt die Flusssperren fester Standorte im Suchgebiet nicht mehr');
          }
        }
        if (b['id'] != null) {
          final f = _ps.where((x) => '${x['id']}' == '${b['id']}').firstOrNull ?? _fail('Standort nicht gefunden', 404);
          final id = f['id'] as int, x = b['off'] == true || b['off'] == 1;
          if (x != i.stopOff.contains(id)) {
            x ? i.stopOff.add(id) : i.stopOff.remove(id);
            _logev(_meId, 'stop', 'berücksichtigt die Flusssperre «${f['name']}»${x ? ' im Suchgebiet nicht mehr' : ' wieder im Suchgebiet'}');
          }
        }
        return {'ok': true};
      case 'incident/open':
        _needLead().openJoin = b['on'] == true || b['on'] == 1 ? 1 : 0;
        return {'ok': true};
      case 'incident/invite/add':
        _fail('Im Demo-Modus sind bereits alle Personen aufgeboten.');
      case 'incident/air/set':
        final i = _needLead(), on = b['on'] == true || b['on'] == 1 ? 1 : 0;
        if (on != i.air) {
          i.air = on;
          _logev(_meId, 'air', on == 1 ? 'schaltet die Helikopter ein' : 'schaltet die Helikopter aus');
        }
        return {'ok': true};
      case 'incident/vehicle/link':
        if (_featOf(_kindOf(_needInc()))['veh'] != 1) _fail('Die Funktion «Fahrzeuge» ist ausgeschaltet', 403);
        _needPart('Nur Personen, die am Einsatz teilnehmen');
        final v = _vById(_fin(b['id']) ? _d(b['id']).toInt() : null) ?? _fail('Fahrzeug nicht gefunden', 404);
        final c = b['carrier'] == null ? null : (_vById(_fin(b['carrier']) ? _d(b['carrier']).toInt() : null) ?? _fail('Träger nicht gefunden', 404));
        if (c != null) {
          if (_vTree(v).contains(c)) _fail('«${c['name']}» wird selbst von «${v['name']}» getragen');
          if (v['carrier_id'] != c['id']) {
            final r = _vRoom(c, v['kind_id'] as int, v['id'] as int);
            if (r < 0) _fail('«${c['name']}» kann «${_vKind(v)['name']}» nicht aufnehmen');
            if (r < 1) _fail('«${c['name']}» ist voll');
          }
        }
        _vehSet(v, c, _meId, false, t);
        return {'ok': true};
      case 'incident/air':
        _needInc();
        return {
          'air': _heli(t),
          'at': t,
          'src': {'name': 'Demo (simuliert)', 'site': ''},
          'err': null,
          'radius_km': 15,
          'now': t,
        };
      case 'air/home':
        _fail('Helikopter ohne Einsatz gibt es im Demo-Modus nicht.', 403);
      case 'incident/end':
        _needLead('Nur die Einsatzleitung kann den Einsatz beenden');
        _endInc(_meId);
        return {'ok': true};
      case 'ex/start':
      case 'ex/release':
        _fail('Vorbereitete Übungen gibt es im Demo-Modus nicht.');
      case 'me/skills': // Ausbildung (Issue #117): im Demo keine
        return {'items': [], 'exp': {}};
      case 'absences':
        return abs
            .where((a) => a['removed'] == 0 && (a['until_at'] == null || (a['until_at'] as int) > t))
            .map((a) => {...a, 'name': _me['name'], 'by_name': _me['name'], 'now': (a['from_at'] as int) <= t ? 1 : 0})
            .toList();
      case 'absence/save':
        final from = _fin(b['from']) ? math.max(_d(b['from']).toInt(), t - 60) : t, until = _fin(b['until']) ? _d(b['until']).toInt() : null;
        if (until != null && until <= math.max(from, t)) _fail('«Bis» muss nach «Von» und in der Zukunft liegen');
        final k = b['kind'] == 'sus' ? 'sus' : 'abs', nt = '${b['note'] ?? ''}'.trim();
        if (b['id'] != null) {
          final a = abs.where((x) => '${x['id']}' == '${b['id']}' && x['removed'] == 0).firstOrNull ?? _fail('Eintrag nicht gefunden', 404);
          a.addAll({'from_at': from, 'until_at': until, 'kind': k, 'note': nt.isEmpty ? null : nt});
          return {'ok': true, 'id': a['id']};
        }
        final a = <String, dynamic>{
          'id': _seqAbs++,
          'user_id': _meId,
          'from_at': from,
          'until_at': until,
          'kind': k,
          'note': nt.isEmpty ? null : nt,
          'created_by': _meId,
          'created_at': t,
          'removed': 0,
        };
        abs.add(a);
        return {'ok': true, 'id': a['id']};
      case 'absence/remove':
        final a = abs.where((x) => '${x['id']}' == '${b['id']}' && x['removed'] == 0).firstOrNull ?? _fail('Eintrag nicht gefunden', 404);
        a['removed'] = 1;
        return {'ok': true};
      case 'hydro':
        final ids = b['ids'] is List ? (b['ids'] as List).map((x) => '$x').toList() : _hy.map((h) => '${h['id']}').toList();
        return {
          'stations': _hy.where((h) => ids.contains(h['id'])).map((h) => {...h, 'at': h['at'] ?? t - 240}).toList(),
          'at': t - 240,
          'err': null,
        };
      case 'overpass':
        final c = _opc[_opKey(b)];
        if (c != null) return c;
        const m = 'Gewässernetz vom Gerät laden';
        throw ApiError(m, 502, {'error': m, 'query': _overpassQ(b), 'servers': _overpass});
      case 'overpass/put':
        if (b['elements'] is List) _opc[_opKey(b)] = {'elements': b['elements']};
        return {'ok': true};
      case 'push/key':
        _fail('Im Demo-Modus gibt es keine Push-Benachrichtigungen.');
      case 'ai/ask':
        _fail('Den AI-Assistenten gibt es im Demo-Modus nicht.');
    }
    _fail('Im Demo-Modus nicht verfügbar', 404);
  }
}
