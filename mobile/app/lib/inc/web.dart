// Seiten der Web-App in der App (Verwaltung, «Meine Einsätze», Rechtliches) in einer WebView statt im Browser, ohne eigene Anmeldung:
// Vor dem ersten Skript der Seite setzt die App den Anmeldeschlüssel des Kontos (localStorage sw_token). Fremde Adressen (Navigation,
// Telefon, Mail, fremde Seiten) öffnen sich ausserhalb, der Schlüssel bleibt beim Backend. «Zur Einsatz-App» führt zurück zur Karte.
// Dateien, die die Web-App im Browser erzeugt (AAR Word/PDF, CSV), gibt sie über swSave an die App, die sie zum Teilen bzw. Speichern anbietet.
// Abmelden in der Web-App meldet das Konto in der App ab (swLogout). Ein neuer Alarm schliesst die Seite, damit er auf der Karte erscheint.
import 'dart:collection';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../accounts.dart';
import '../api.dart';
import '../push.dart';
import '../service.dart';
import 'store.dart';

class WebPage extends StatefulWidget {
  const WebPage({super.key, required this.s, required this.page});
  final IncStore s;
  final String page; // relativ zum Web-Ordner, z. B. admin.html#meine
  @override
  State<WebPage> createState() => _WebState();
}

class _WebState extends State<WebPage> {
  InAppWebViewController? _c;
  double _prog = 0;
  bool _gone = false;
  late final Uri _origin = Uri.parse(widget.s.acc.base);
  late final Object? _alarm0 = widget.s.alarm;

  @override
  void initState() {
    super.initState();
    widget.s.addListener(_onStore);
  }

  @override
  void dispose() {
    widget.s.removeListener(_onStore);
    super.dispose();
  }

  /// neuer Alarm oder Anmeldung ungültig: zurück zur Karte (die Einsatz-App zeigt den Alarm bzw. meldet ab)
  void _onStore() {
    final s = widget.s;
    if ((s.alarm != null && s.alarm != _alarm0) || s.unauth) _close();
  }

  void _close() {
    if (_gone || !mounted) return;
    _gone = true;
    Navigator.of(context).pop();
  }

  bool _same(Uri u) => u.scheme == _origin.scheme && u.host == _origin.host && u.port == _origin.port;

  /// «Zur Einsatz-App»: Web-Ordner selbst oder index.html
  bool _isApp(Uri u) => _same(u) && (u.path == _origin.path || u.path == '${_origin.path}index.html');

  String get _js {
    final o = _origin;
    final origin = jsonEncode('${o.scheme}://${o.host}${o.hasPort && o.port != 80 && o.port != 443 ? ':${o.port}' : ''}');
    return '(function(){try{if(location.origin!==$origin)return;localStorage.setItem("sw_token",${jsonEncode(widget.s.acc.token)})}catch(e){}})();';
  }

  Future<bool> _external(Uri u) async {
    try {
      return await launchUrl(u, mode: LaunchMode.externalApplication);
    } catch (_) {
      return false;
    }
  }

  /// Abmelden in der Web-App: Die Route logout hat die Sitzung der App schon beendet, das Konto gilt als abgemeldet.
  Future<void> _logout() async {
    if (_gone) return;
    _gone = true;
    final a = widget.s.acc;
    await pushForget(a);
    await Accounts.update(a.id, (x) => x.loggedOut = true);
    await syncService();
    if (mounted) Navigator.of(context).popUntil((r) => r.isFirst);
  }

  /// Datei aus der Web-App (Name, Typ, Base64) zum Teilen bzw. Speichern anbieten
  Future<void> _save(List<dynamic> a) async {
    if (a.length < 3 || a[0] is! String || a[2] is! String) return;
    final name = (a[0] as String).replaceAll(RegExp(r'[\\/:*?"<>|]'), '_');
    try {
      final dir = await getTemporaryDirectory();
      final f = File('${dir.path}/$name');
      await f.writeAsBytes(base64Decode(a[2] as String));
      await SharePlus.instance.share(ShareParams(files: [XFile(f.path, mimeType: a[1] is String ? a[1] as String : null)], title: name));
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$name konnte nicht gespeichert werden.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (did, _) async {
        if (did) return;
        if (_c != null && await _c!.canGoBack()) {
          await _c!.goBack();
        } else {
          _close();
        }
      },
      child: Scaffold(
        backgroundColor: const Color(0xFF0B3A5B),
        body: SafeArea(
            child: Stack(children: [
          InAppWebView(
            initialUrlRequest: URLRequest(url: WebUri('${widget.s.acc.base}${widget.page}')),
            initialUserScripts: UnmodifiableListView([UserScript(source: _js, injectionTime: UserScriptInjectionTime.AT_DOCUMENT_START)]),
            initialSettings: InAppWebViewSettings(
              applicationNameForUserAgent: appUa,
              javaScriptEnabled: true,
              domStorageEnabled: true,
              databaseEnabled: true,
              useShouldOverrideUrlLoading: true,
              supportMultipleWindows: false,
              javaScriptCanOpenWindowsAutomatically: true,
              isInspectable: false,
              allowsBackForwardNavigationGestures: true,
            ),
            onWebViewCreated: (c) {
              _c = c;
              c.addJavaScriptHandler(handlerName: 'swLogout', callback: (_) => _logout());
              c.addJavaScriptHandler(handlerName: 'swUnauth', callback: (_) => _close());
              c.addJavaScriptHandler(handlerName: 'swSave', callback: _save);
            },
            onProgressChanged: (_, p) => setState(() => _prog = p / 100),
            shouldOverrideUrlLoading: (c, nav) async {
              final u = nav.request.url;
              if (u == null) return NavigationActionPolicy.ALLOW;
              if (_isApp(u)) {
                _close();
                return NavigationActionPolicy.CANCEL;
              }
              if ((u.scheme == 'http' || u.scheme == 'https') && _same(u)) return NavigationActionPolicy.ALLOW;
              if (u.scheme == 'about' || u.scheme == 'data' || u.scheme == 'blob') return NavigationActionPolicy.ALLOW;
              await _external(u);
              return NavigationActionPolicy.CANCEL;
            },
            // target="_blank": eigene Seiten (Rechtliches, öffentliche Ansicht) in derselben WebView, fremde ausserhalb
            onCreateWindow: (c, req) async {
              final u = req.request.url;
              if (u == null) return false;
              if (_isApp(u)) {
                _close();
              } else if (_same(u)) {
                await c.loadUrl(urlRequest: URLRequest(url: u));
              } else {
                await _external(u);
              }
              return false;
            },
          ),
          if (_prog < 1) Positioned(top: 0, left: 0, right: 0, child: LinearProgressIndicator(value: _prog, minHeight: 2)),
        ])),
      ),
    );
  }
}
