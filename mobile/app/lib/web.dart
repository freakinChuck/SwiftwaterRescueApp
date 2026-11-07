// Web-App eines Kontos in der WebView. Vor dem ersten Skript der Seite setzt die App den Anmeldeschlüssel des Kontos (localStorage sw_token)
// und übernimmt die schon gemeldeten Alarme in sw_acked, damit die Web-App sie nicht noch einmal meldet. Mehrere Konten auf demselben
// Server teilen sich den Speicher der WebView: wechselt das Konto (sw_native_acc), wird der gemerkte Stand (sw_last, sw_boot) gelöscht.
// Fremde Adressen (Navigation, Karten, Telefon, Mail) öffnen sich ausserhalb, der Schlüssel bleibt beim Backend.
// Den Standort liefert die App (geo.dart); quittiert die Web-App einen Alarm (swAck), schliesst die App ihre Meldung dazu.
import 'dart:async';
import 'dart:collection';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:url_launcher/url_launcher.dart';

import 'accounts.dart';
import 'api.dart';
import 'geo.dart';
import 'home.dart';
import 'monitor.dart';
import 'notify.dart';
import 'push.dart';
import 'service.dart';
import 'sys.dart';

class WebPage extends StatefulWidget {
  const WebPage({super.key, required this.account});
  final Account account;

  /// Konto, dessen Web-App gerade offen ist (nicht doppelt öffnen).
  static String? openId;

  @override
  State<WebPage> createState() => _WebState();
}

class _WebState extends State<WebPage> with WidgetsBindingObserver {
  InAppWebViewController? _c;
  GeoBridge? _geo;
  Timer? _hb;
  double _prog = 0;
  bool _closing = false;
  late final Uri _origin = Uri.parse(widget.account.base);
  late final Future<String> _js = _script();

  @override
  void initState() {
    super.initState();
    WebPage.openId = widget.account.id;
    WidgetsBinding.instance.addObserver(this);
    _beat();
    _hb = Timer.periodic(const Duration(seconds: 5), (_) => _beat());
    WidgetsBinding.instance.addPostFrameCallback((_) => _askLocation());
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
    if (go == true) await requestBackgroundLocation();
  }

  /// Herzschlag: solange die Web-App sichtbar ist, meldet sie selbst, die App bleibt für dieses Konto still (monitor.dart).
  void _beat() => setForeground(widget.account.id);

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    if (s == AppLifecycleState.resumed) {
      _geo?.resume();
      _hb?.cancel();
      _beat();
      _hb = Timer.periodic(const Duration(seconds: 5), (_) => _beat());
    } else if (s == AppLifecycleState.paused || s == AppLifecycleState.inactive) {
      _hb?.cancel();
      setForeground(null);
      if (s == AppLifecycleState.paused) _geo?.pause();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _hb?.cancel();
    _geo?.dispose();
    setForeground(null);
    if (WebPage.openId == widget.account.id) WebPage.openId = null;
    super.dispose();
  }

  bool _same(Uri u) => u.scheme == _origin.scheme && u.host == _origin.host && u.port == _origin.port;

  Future<String> _script() async {
    final a = widget.account;
    final tags = (await accState(a.id)).tags;
    final origin = jsonEncode('${_origin.scheme}://${_origin.host}${_origin.hasPort && _origin.port != 80 && _origin.port != 443 ? ':${_origin.port}' : ''}');
    return '''(function(){try{
if(location.origin!==$origin)return;
var L=localStorage,acc=${jsonEncode(a.id)};
if(L.getItem('sw_native_acc')!==acc){['sw_last','sw_boot'].forEach(function(k){L.removeItem(k)});L.setItem('sw_native_acc',acc)}
L.setItem('sw_token',${jsonEncode(a.token)});
var k=[];try{k=JSON.parse(L.getItem('sw_acked')||'[]')}catch(e){}
${jsonEncode(tags)}.forEach(function(t){if(k.indexOf(t)<0)k.push(t)});
L.setItem('sw_acked',JSON.stringify(k.slice(-300)));
}catch(e){}
$geoJs})();''';
  }

  Future<void> _logout({required bool byServer}) async {
    if (_closing) return;
    _closing = true;
    final a = widget.account;
    await pushForget(a);
    if (!byServer) await a.api.logout(); // die Web-App hat die Route logout schon gerufen; doppelt schadet nicht
    await Accounts.update(a.id, (x) => x.loggedOut = true);
    await syncService();
    if (!mounted) return;
    Navigator.pop(context);
    if (byServer) {
      Navigator.push(context, MaterialPageRoute(builder: (_) => LoginPage(relogin: a)));
      snack(context, 'Bei ${a.label} abgemeldet. Bitte neu anmelden.');
    }
  }

  Future<bool> _external(Uri u) async {
    try {
      return await launchUrl(u, mode: LaunchMode.externalApplication);
    } catch (_) {
      return false;
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<String>(
      future: _js,
      builder: (context, snap) {
        if (!snap.hasData) return const Scaffold(body: Center(child: CircularProgressIndicator()));
        return PopScope(
          canPop: false,
          onPopInvokedWithResult: (did, _) async {
            if (did) return;
            if (_c != null && await _c!.canGoBack()) {
              await _c!.goBack();
            } else if (context.mounted) {
              Navigator.pop(context);
            }
          },
          child: Scaffold(
            backgroundColor: const Color(0xFF0B3A5B),
            body: SafeArea(top: Platform.isAndroid, bottom: Platform.isAndroid, child: Stack(children: [
              InAppWebView(
                initialUrlRequest: URLRequest(url: WebUri('${widget.account.base}index.html')),
                initialUserScripts: UnmodifiableListView([UserScript(source: snap.data!, injectionTime: UserScriptInjectionTime.AT_DOCUMENT_START)]),
                initialSettings: InAppWebViewSettings(
                  applicationNameForUserAgent: appUa,
                  javaScriptEnabled: true,
                  domStorageEnabled: true,
                  databaseEnabled: true,
                  geolocationEnabled: true,
                  mediaPlaybackRequiresUserGesture: false,
                  allowsInlineMediaPlayback: true,
                  useShouldOverrideUrlLoading: true,
                  supportMultipleWindows: false,
                  javaScriptCanOpenWindowsAutomatically: true,
                  isInspectable: false,
                  allowsBackForwardNavigationGestures: true,
                ),
                onWebViewCreated: (c) {
                  _c = c;
                  c.addJavaScriptHandler(handlerName: 'swLogout', callback: (_) => _logout(byServer: false));
                  c.addJavaScriptHandler(handlerName: 'swUnauth', callback: (_) => _logout(byServer: true));
                  // Web-App hat einen Alarm, eine Nachricht oder das Einsatzende quittiert: Meldung der App schliessen (stoppt den Dauerton)
                  c.addJavaScriptHandler(
                      handlerName: 'swAck',
                      callback: (a) async {
                        final t = a.isEmpty ? null : a.first;
                        if (t is! String || t.isEmpty) return;
                        await markTag(widget.account.id, t);
                        await cancelNote(widget.account.id, t);
                      });
                  _geo = GeoBridge(c)..attach();
                },
                onProgressChanged: (_, p) => setState(() => _prog = p / 100),
                shouldOverrideUrlLoading: (c, nav) async {
                  final u = nav.request.url;
                  if (u == null) return NavigationActionPolicy.ALLOW;
                  if ((u.scheme == 'http' || u.scheme == 'https') && _same(u)) return NavigationActionPolicy.ALLOW;
                  if (u.scheme == 'about' || u.scheme == 'data' || u.scheme == 'blob') return NavigationActionPolicy.ALLOW;
                  // Navigation (Google Maps, Apple Karten, Waze, geo:), Telefon, Mail, fremde Seiten: ausserhalb öffnen
                  await _external(u);
                  return NavigationActionPolicy.CANCEL;
                },
                onCreateWindow: (c, req) async {
                  final u = req.request.url;
                  if (u != null) await _external(u);
                  return false;
                },
                // nur noch für fremde Seiten ohne Ersatzskript: die App-Berechtigung entscheidet, nichts merken
                onGeolocationPermissionsShowPrompt: (c, origin) async {
                  final ok = await requestLocation() != locNone;
                  return GeolocationPermissionShowPromptResponse(origin: origin, allow: ok, retain: false);
                },
                onPermissionRequest: (c, req) async => PermissionResponse(resources: req.resources, action: PermissionResponseAction.GRANT),
              ),
              if (_prog < 1) Positioned(top: 0, left: 0, right: 0, child: SafeArea(child: LinearProgressIndicator(value: _prog, minHeight: 2))),
            ])),
          ),
        );
      },
    );
  }
}
