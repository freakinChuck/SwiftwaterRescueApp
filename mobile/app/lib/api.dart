// Zugriff auf die JSON-API eines Backends (web/api/index.php, Routen über ?r=<route>, Token im Header X-Token).
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

/// Kennung der App im User-Agent (auch in der WebView): Die Web-App erkennt daran die App-Hülle (NATIVE in common.js).
const appUa = 'SwiftwaterRescueApp/1.0';

class ApiError implements Exception {
  ApiError(this.message, [this.status = 0, this.data]);
  final String message;
  final int status;

  /// ganze Antwort des Servers (z. B. query und servers bei 502 der Route overpass)
  final Map<String, dynamic>? data;
  bool get unauth => status == 401;
  bool get offline => status == 0;
  @override
  String toString() => message;
}

/// Adresse des Web-Ordners vereinheitlichen: https ergänzen, index.html/admin.html/api/… entfernen, mit «/» am Ende.
String normBase(String raw) {
  var s = raw.trim();
  if (s.isEmpty) return s;
  if (!RegExp(r'^https?://', caseSensitive: false).hasMatch(s)) s = 'https://$s';
  final u = Uri.parse(s);
  var p = u.path;
  p = p.replaceFirst(RegExp(r'(api/(index\.php)?|[^/]*\.html?)$'), '');
  if (!p.endsWith('/')) p = '$p/';
  return u.replace(path: p, query: '', fragment: '').toString().replaceAll(RegExp(r'[?#]+$'), '');
}

/// Kurzform für die Anzeige und für den Abgleich mit dem Feld srv der Push-Nachrichten: Host plus Pfad, ohne Schema.
String hostPath(String base) {
  final u = Uri.parse(base);
  return '${u.host}${u.hasPort && u.port != 80 && u.port != 443 ? ':${u.port}' : ''}${u.path}';
}

class Api {
  Api(this.base, [this.token]);
  final String base;
  String? token;
  static final _client = http.Client();

  Uri uri(String route, [Map<String, String>? q]) => Uri.parse('${base}api/index.php').replace(queryParameters: {'r': route, ...?q});

  Future<Map<String, dynamic>> call(String route, {Map<String, dynamic>? body, Map<String, String>? query, Duration timeout = const Duration(seconds: 20)}) async {
    final h = {'X-Token': token ?? '', 'User-Agent': appUa, if (body != null) 'Content-Type': 'application/json'};
    http.Response r;
    try {
      r = body != null
          ? await _client.post(uri(route, query), headers: h, body: jsonEncode(body)).timeout(timeout)
          : await _client.get(uri(route, query), headers: h).timeout(timeout);
    } on SocketException {
      throw ApiError('Keine Verbindung zum Server');
    } on HandshakeException {
      throw ApiError('Sichere Verbindung zum Server fehlgeschlagen');
    } on http.ClientException {
      throw ApiError('Keine Verbindung zum Server');
    } catch (e) {
      throw ApiError('Keine Verbindung zum Server (${e.runtimeType})');
    }
    dynamic j;
    try {
      j = jsonDecode(utf8.decode(r.bodyBytes));
    } catch (_) {
      throw ApiError(r.statusCode == 404 ? 'Unter dieser Adresse läuft kein Swiftwater-Rescue-Server' : 'Ungültige Antwort vom Server (${r.statusCode})', r.statusCode);
    }
    if (j is Map && j['error'] != null) throw ApiError('${j['error']}', r.statusCode, j is Map<String, dynamic> ? j : null);
    if (r.statusCode >= 400) throw ApiError('Fehler ${r.statusCode}', r.statusCode);
    return j is Map<String, dynamic> ? j : {'data': j};
  }

  /// Anmeldung mit eigener Gerätesitzung (device): meldet weder den Browser noch andere Geräte ab.
  Future<String> login(String user, String pass, String device) async {
    final j = await call('login', body: {'username': user, 'password': pass, 'device': device, 'platform': Platform.isIOS ? 'ios' : 'android'});
    final t = j['token'];
    if (t is! String || t.isEmpty) throw ApiError('Anmeldung fehlgeschlagen');
    return token = t;
  }

  Future<void> logout() async {
    try {
      await call('logout', body: {}, timeout: const Duration(seconds: 8));
    } catch (_) {}
  }
}
