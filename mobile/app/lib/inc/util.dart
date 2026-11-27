// Gemeinsame Helfer der nativen Einsatz-App (Gegenstück zu web/common.js): JSON-Zugriff, Zeit, Koordinaten (LV95/LV03/WGS84), Distanzen, Farben.
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';

typedef J = Map<String, dynamic>;

/// Zahl aus JSON (der Server liefert Zahlen als Zahlen, ältere Stände teils als Text).
num? jn(dynamic v) => v is num ? v : (v is String ? num.tryParse(v) : null);
double? jd(dynamic v) => jn(v)?.toDouble();
int? ji(dynamic v) => jn(v)?.toInt();
bool jb(dynamic v) => v == true || (v is num && v != 0) || v == '1';
String js(dynamic v) => v == null ? '' : '$v';
List<J> jl(dynamic v) => v is List ? v.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList() : <J>[];
J jm(dynamic v) => v is Map ? v.cast<String, dynamic>() : <String, dynamic>{};

/// Position eines Eintrags (lat/lon), null ohne Standort.
LatLng? llOf(J? x, [String la = 'lat', String lo = 'lon']) {
  if (x == null) return null;
  final a = jd(x[la]), b = jd(x[lo]);
  return a == null || b == null ? null : LatLng(a, b);
}

final _dist = const Distance();
double geoDist(LatLng a, LatLng b) => _dist.as(LengthUnit.Meter, a, b);

String _p2(int n) => n.toString().padLeft(2, '0');
DateTime tsDate(num ts) => DateTime.fromMillisecondsSinceEpoch((ts * 1000).round());

/// Uhrzeit hh:mm:ss (fmtT)
String fmtT(num? ts) {
  if (ts == null) return '';
  final d = tsDate(ts);
  return '${_p2(d.hour)}:${_p2(d.minute)}:${_p2(d.second)}';
}

/// Tag, Monat und Uhrzeit (fmtDT)
String fmtDT(num? ts) {
  if (ts == null) return '';
  final d = tsDate(ts);
  return '${_p2(d.day)}.${_p2(d.month)}., ${_p2(d.hour)}:${_p2(d.minute)}';
}

String fmtHM(num? ts) {
  if (ts == null) return '';
  final d = tsDate(ts);
  return '${_p2(d.hour)}:${_p2(d.minute)}';
}

/// «gerade eben», «vor N min», «vor N h» relativ zu now (Sekunden)
String agoS(num now, num ts) {
  final s = now - ts;
  return s < 90 ? 'gerade eben' : s < 5400 ? 'vor ${(s / 60).round()} min' : 'vor ${(s / 3600).round()} h';
}

/* ---------- Geschwindigkeiten (Issue #259, wie SPEED_UNITS/fmtSpd in common.js) ---------- */
/// Einheit der Anzeige: eigene Wahl (users.speed_unit), sonst Standard der Organisation (me.speed_default); gespeichert wird in m/s
const speedUnits = {'ms': 'm/s', 'kmh': 'km/h', 'both': 'm/s und km/h'};

String spdR(double v, int d) {
  final f = math.pow(10, d), r = (v * f).round() / f;
  return r == r.roundToDouble() ? r.round().toString() : r.toString();
}

/// Geschwindigkeit in m/s → Text in der Einheit (beides: «1.8 m/s (6.5 km/h)»)
String fmtSpd(double? ms, String u) {
  if (ms == null) return '';
  final a = '${spdR(ms, 2)} m/s', b = '${spdR(ms * 3.6, 1)} km/h';
  return u == 'kmh' ? b : u == 'both' ? '$a ($b)' : a;
}

/// Geschwindigkeit in km/h (Vergleichsgeschwindigkeiten, Helikopter) → Text in der Einheit
String fmtKmh(double? k, String u) {
  if (k == null) return '';
  final a = '${spdR(k / 3.6, 1)} m/s', b = '${spdR(k, 1)} km/h';
  return u == 'kmh' ? b : u == 'both' ? '$a ($b)' : a;
}

/// Eingabe der Fliessgeschwindigkeit: in km/h nur bei der Einheit km/h, sonst in m/s
String spdIn(String u) => u == 'kmh' ? 'km/h' : 'm/s';
String spdTo(double? ms, String u) => ms == null ? '' : u == 'kmh' ? spdR(ms * 3.6, 1) : spdR(ms, 2);
double? spdFrom(String t, String u) {
  final v = double.tryParse(t.trim().replaceAll(',', '.'));
  if (v == null) return null;
  return u == 'kmh' ? (v / 3.6 * 100).round() / 100 : v;
}

/* ---------- Koordinaten ---------- */
const coordSys = {
  'lv95': 'LV95 (CH1903+, E / N)',
  'lv03': 'LV03 (CH1903, Y / X)',
  'wgs84': 'WGS84 Dezimalgrad',
  'wgs84dms': 'WGS84 Grad, Minuten, Sekunden',
};

/// WGS84 → LV95 mit den Näherungsformeln von swisstopo (ca. 1 m)
List<double> toLV95(double lat, double lon) {
  final p = (lat * 3600 - 169028.66) / 1e4, l = (lon * 3600 - 26782.5) / 1e4;
  return [
    2600072.37 + 211455.93 * l - 10938.51 * l * p - .36 * l * p * p - 44.54 * l * l * l,
    1200147.07 + 308807.95 * p + 3745.25 * l * l + 76.63 * p * p - 194.56 * l * l * p + 119.79 * p * p * p,
  ];
}

String _thou(double n) => n.round().toString().replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => "'");

String _dms(double v, String pos, String neg) {
  final a = v.abs(), d = a.floor(), m = ((a - d) * 60).floor(), s = ((a - d) * 60 - m) * 60;
  return "$d°${_p2(m)}'${s.toStringAsFixed(1).padLeft(4, '0')}\"${v < 0 ? neg : pos}";
}

String fmtC(double? lat, double? lon, String sys) {
  if (lat == null || lon == null || sys == 'none') return '';
  if (sys == 'wgs84') return '${lat.toStringAsFixed(5)}, ${lon.toStringAsFixed(5)}';
  if (sys == 'wgs84dms') return '${_dms(lat, 'N', 'S')} ${_dms(lon, 'E', 'W')}';
  final en = toLV95(lat, lon);
  return sys == 'lv03' ? '${_thou(en[0] - 2e6)} / ${_thou(en[1] - 1e6)}' : '${_thou(en[0])} / ${_thou(en[1])}';
}

/// Eingabe WGS84 dezimal, LV95 oder LV03 → WGS84, null wenn ungültig (parseC)
LatLng? parseC(String s) {
  final n = s.replaceAll(RegExp(r"['’]"), '').trim().split(RegExp(r'\s*[,;/]\s*|\s+')).where((x) => x.isNotEmpty).map(double.tryParse).toList();
  if (n.length != 2 || n.any((x) => x == null || !x.isFinite)) return null;
  var a = n[0]!, b = n[1]!;
  if (a.abs() <= 90 && b.abs() <= 180) return LatLng(a, b);
  if (a < b) {
    final t = a;
    a = b;
    b = t;
  }
  if (a < 1e6) {
    a += 2e6;
    b += 1e6;
  }
  if (a < 2.4e6 || a > 2.9e6 || b < 1.05e6 || b > 1.35e6) return null;
  final y = (a - 2.6e6) / 1e6, x = (b - 1.2e6) / 1e6;
  return LatLng((16.9023892 + 3.238272 * x - .270978 * y * y - .002528 * x * x - .0447 * y * y * x - .014 * x * x * x) * 100 / 36,
      (2.6779094 + 4.728982 * y + .791484 * y * x + .1306 * y * x * x - .0436 * y * y * y) * 100 / 36);
}

/* ---------- Farben ---------- */
Color hexColor(dynamic c, [Color def = const Color(0xFF8FB0BD)]) {
  final m = RegExp(r'^#?([0-9a-fA-F]{6})$').firstMatch(js(c));
  return m == null ? def : Color(0xFF000000 | int.parse(m.group(1)!, radix: 16));
}

/// Schriftfarbe auf einer Hintergrundfarbe (kindFg)
Color fgOn(Color c) => (c.r * 255 * .299 + c.g * 255 * .587 + c.b * 255 * .114) > 160 ? const Color(0xFF08161E) : Colors.white;

/// Punkt im Polygon (Strahlverfahren)
bool inPoly(LatLng p, List<LatLng> poly) {
  var c = false;
  for (var i = 0, j = poly.length - 1; i < poly.length; j = i++) {
    final a = poly[i], b = poly[j];
    if ((a.latitude > p.latitude) != (b.latitude > p.latitude) &&
        p.longitude < (b.longitude - a.longitude) * (p.latitude - a.latitude) / (b.latitude - a.latitude) + a.longitude) {
      c = !c;
    }
  }
  return c;
}

/// Mittelpunkt mehrerer Punkte (Bereiche)
LatLng centerOf(List<LatLng> pts) {
  var a = 0.0, b = 0.0;
  for (final p in pts) {
    a += p.latitude;
    b += p.longitude;
  }
  return LatLng(a / pts.length, b / pts.length);
}

double deg2rad(double d) => d * math.pi / 180;

/// Wort zum Stand (absWord): Abwesenheit bzw. regelmässige Einsatzzeiten (Issue #180)
String absWord(J a) => js(a['kind']) == 'rule'
    ? (js(a['why']) == 'on' ? 'ausserhalb Einsatzzeit' : 'regelmässig ausgesetzt')
    : js(a['kind']) == 'sus'
        ? 'ausgesetzt'
        : 'abwesend';
