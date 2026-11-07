// Swiftwater Rescue – native App (Issue #108). Hülle um die Web-App mehrerer Backends:
// Kontenliste (home.dart), Anmeldung, Web-App in der WebView (web.dart), Einstellungen; Alarme über monitor.dart, service.dart und push.dart.
import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';

import 'home.dart';
import 'monitor.dart';
import 'notify.dart';
import 'push.dart';
import 'service.dart';
import 'sys.dart';
import 'web.dart';
import 'accounts.dart';

final navKey = GlobalKey<NavigatorState>();

/// Konto in der WebView öffnen (aus der Liste, nach dem Antippen einer Meldung oder einer Push-Nachricht).
Future<void> openAccount(String id) async {
  final l = await Accounts.load();
  final a = l.where((x) => x.id == id).firstOrNull;
  final nav = navKey.currentState;
  if (a == null || nav == null) return;
  if (a.loggedOut) {
    nav.push(MaterialPageRoute(builder: (_) => LoginPage(relogin: a)));
    return;
  }
  // bereits offen: nicht doppelt öffnen
  if (WebPage.openId == id) return;
  nav.popUntil((r) => r.isFirst);
  nav.push(MaterialPageRoute(builder: (_) => WebPage(account: a)));
}

String? _pendingOpen;

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initNotes(onTap: (r) {
    alarmStop(); // Meldung angetippt: Weckton aus
    final p = r.payload;
    if (p != null && p.isNotEmpty) openAccount(p);
  });
  final start = await notes.getNotificationAppLaunchDetails();
  if (start?.didNotificationLaunchApp == true) _pendingOpen = start!.notificationResponse?.payload;
  await configureService();
  runApp(const SwiftwaterApp());
}

class SwiftwaterApp extends StatefulWidget {
  const SwiftwaterApp({super.key});
  @override
  State<SwiftwaterApp> createState() => _AppState();
}

class _AppState extends State<SwiftwaterApp> with WidgetsBindingObserver {
  Timer? _t;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _boot());
  }

  Future<void> _boot() async {
    await requestNotePerms();
    await syncService();
    unawaited(initPush(openAccount));
    final l = await Accounts.load();
    final p = _pendingOpen;
    _pendingOpen = null;
    if (p != null && p.isNotEmpty) {
      await openAccount(p);
    } else if (l.length == 1 && !l.first.loggedOut) {
      await openAccount(l.first.id); // nur ein Konto: gleich die Web-App zeigen
    }
    _iosPoll();
  }

  /// iOS hat keinen Hintergrunddienst: solange die App offen ist, fragt sie selbst ab.
  Future<void> _iosPoll() async {
    _t?.cancel();
    if (!Platform.isIOS) return;
    Future<void> run() async {
      if (_busy) return;
      _busy = true;
      try {
        await pollAll();
      } finally {
        _busy = false;
      }
    }

    _t = Timer.periodic(Duration(seconds: await pollInterval()), (_) => run());
    await run();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    if (s == AppLifecycleState.resumed) {
      refreshChannels();
      _iosPoll();
      syncService();
      syncPush();
    } else if (s == AppLifecycleState.paused) {
      _t?.cancel();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _t?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    const blue = Color(0xFF0B3A5B);
    return MaterialApp(
      navigatorKey: navKey,
      title: 'Swiftwater Rescue',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: blue), useMaterial3: true),
      darkTheme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: blue, brightness: Brightness.dark), useMaterial3: true),
      home: HomePage(onIntervalChanged: _iosPoll),
    );
  }
}
