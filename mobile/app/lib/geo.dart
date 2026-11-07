// Standort für die Web-App in der WebView. Die WebView fragt den Standort sonst selbst ab: das scheiterte (eigene Berechtigungsabfrage
// parallel zur App, verweigert gemerkt, http-Adressen ohne Standort). Stattdessen ersetzt ein Skript vor dem ersten Skript der Seite
// navigator.geolocation (geoJs) und holt die Positionen über den Handler swGeo von der App (geolocator).
// Pausiert die App (Bildschirm gesperrt), ruhen die Abfragen der Web-App; das Teilen im Einsatz übernimmt dann track.dart.
import 'dart:async';
import 'dart:convert';

import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:geolocator/geolocator.dart';

import 'sys.dart';

/// Ersatz für navigator.geolocation (W3C-Schnittstelle: getCurrentPosition, watchPosition, clearWatch, Fehlercodes 1–3).
const geoJs = '''(function(){var w=window,P={},n=0;
function call(a){var f=w.flutter_inappwebview;if(f&&f.callHandler)return f.callHandler('swGeo',a);
return new Promise(function(r,j){w.addEventListener('flutterInAppWebViewPlatformReady',function(){w.flutter_inappwebview.callHandler('swGeo',a).then(r,j)},{once:true})})}
function pos(d){return{coords:{latitude:d.lat,longitude:d.lon,accuracy:d.acc,altitude:d.alt,altitudeAccuracy:null,heading:d.hdg,speed:d.spd},timestamp:d.ts}}
function err(c,m){return{code:c,message:m||'',PERMISSION_DENIED:1,POSITION_UNAVAILABLE:2,TIMEOUT:3}}
function o(x){x=x||{};return{hi:!!x.enableHighAccuracy,age:x.maximumAge||0,to:x.timeout>0&&x.timeout<1e9?x.timeout:0}}
w.__swGeo=function(id,d){var p=P[id];if(!p)return;if(d.ok)p.s(pos(d));else if(p.e)p.e(err(d.code,d.msg))};
var g={getCurrentPosition:function(s,e,x){var a=o(x);a.op='once';call(a).then(function(d){if(d&&d.ok)s(pos(d));else if(e)e(err(d?d.code:2,d&&d.msg))},function(){if(e)e(err(2,'Standort nicht verfügbar'))})},
watchPosition:function(s,e,x){var id=++n,a=o(x);P[id]={s:s,e:e};a.op='watch';a.id=id;call(a).catch(function(){if(e)e(err(2,'Standort nicht verfügbar'))});return id},
clearWatch:function(id){if(P[id]){delete P[id];call({op:'clear',id:id}).catch(function(){})}}};
try{Object.defineProperty(navigator,'geolocation',{value:g,configurable:true})}catch(e){}})();''';

Map<String, dynamic> posJs(Position p) => {
      'ok': 1,
      'lat': p.latitude,
      'lon': p.longitude,
      'acc': p.accuracy,
      'alt': p.altitude,
      'hdg': p.heading.isNaN || p.heading < 0 ? null : p.heading,
      'spd': p.speed.isNaN || p.speed < 0 ? null : p.speed,
      'ts': p.timestamp.millisecondsSinceEpoch,
    };

LocationSettings geoSettings({bool high = true, Duration? limit}) =>
    LocationSettings(accuracy: high ? LocationAccuracy.best : LocationAccuracy.medium, distanceFilter: 0, timeLimit: limit);

class GeoBridge {
  GeoBridge(this._c);
  final InAppWebViewController _c;
  final _subs = <int, StreamSubscription<Position>>{};
  final _opts = <int, bool>{}; // Wachen mit hoher Genauigkeit (für resume)
  bool _paused = false;

  void attach() => _c.addJavaScriptHandler(handlerName: 'swGeo', callback: (a) => _handle(a.isEmpty ? null : a.first));

  /// Fehler im Format der Web-App: 1 = verweigert, 2 = nicht verfügbar, 3 = Zeitüberschreitung.
  Map<String, dynamic> _err(int code, String msg) => {'ok': 0, 'code': code, 'msg': msg};

  Future<Map<String, dynamic>?> _ready() async {
    if (await requestLocation() == locNone) return _err(1, 'Standortzugriff für die App nicht erlaubt (Einstellungen der App)');
    if (!await Geolocator.isLocationServiceEnabled()) return _err(2, 'Standort am Gerät ausgeschaltet');
    return null;
  }

  Future<dynamic> _handle(dynamic a) async {
    if (a is! Map) return null;
    final op = a['op'], id = (a['id'] as num?)?.toInt() ?? 0, high = a['hi'] == true;
    if (op == 'clear') {
      await _subs.remove(id)?.cancel();
      _opts.remove(id);
      return null;
    }
    final e = await _ready();
    if (op == 'once') {
      if (e != null) return e;
      final age = (a['age'] as num?)?.toInt() ?? 0, to = (a['to'] as num?)?.toInt() ?? 0;
      try {
        if (age > 0) {
          final l = await Geolocator.getLastKnownPosition();
          if (l != null && DateTime.now().difference(l.timestamp).inMilliseconds <= age) return posJs(l);
        }
        return posJs(await Geolocator.getCurrentPosition(locationSettings: geoSettings(high: high, limit: to > 0 ? Duration(milliseconds: to) : null)));
      } on TimeoutException {
        return _err(3, 'Zeitüberschreitung');
      } catch (x) {
        return _err(2, '$x');
      }
    }
    if (op == 'watch') {
      if (e != null) {
        _send(id, e);
        return null;
      }
      _opts[id] = high;
      if (!_paused) _start(id);
    }
    return null;
  }

  void _start(int id) {
    _subs[id]?.cancel();
    _subs[id] = Geolocator.getPositionStream(locationSettings: geoSettings(high: _opts[id] ?? true))
        .listen((p) => _send(id, posJs(p)), onError: (x) => _send(id, _err(2, '$x')));
  }

  void _send(int id, Map<String, dynamic> d) {
    _c.evaluateJavascript(source: 'window.__swGeo&&window.__swGeo($id,${jsonEncode(d)})').catchError((_) => null);
  }

  /// App im Hintergrund: Abfragen anhalten (spart Akku; das Teilen übernimmt track.dart), beim Zurückkehren fortsetzen.
  void pause() {
    _paused = true;
    for (final s in _subs.values) {
      s.cancel();
    }
    _subs.clear();
  }

  void resume() {
    if (!_paused) return;
    _paused = false;
    for (final id in _opts.keys) {
      _start(id);
    }
  }

  void dispose() {
    pause();
    _opts.clear();
  }
}
