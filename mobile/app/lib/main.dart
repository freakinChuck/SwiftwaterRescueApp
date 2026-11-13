// Swiftwater Rescue – native App (Issue #108) für mehrere Backends:
// Kontenliste (home.dart), Anmeldung, native Einsatz-App (inc/map_page.dart, ohne WebView), Einstellungen; Alarme über monitor.dart, service.dart und push.dart.
import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';

import 'home.dart';
import 'monitor.dart';
import 'notify.dart';
import 'push.dart';
import 'service.dart';
import 'sys.dart';
import 'inc/map_page.dart';
import 'accounts.dart';

final navKey = GlobalKey<NavigatorState>();

/// Einsatz-App eines Kontos öffnen (aus der Liste, nach dem Antippen einer Meldung oder einer Push-Nachricht).
/// focus: aus einer Meldung geöffnet, die Einsatz-App zeigt das Einsatzgebiet (IncPage.focusReq).
Future<void> openAccount(String id, {bool focus = false}) async {
  final l = await Accounts.load();
  final a = l.where((x) => x.id == id).firstOrNull;
  final nav = navKey.currentState;
  if (a == null || nav == null) return;
  if (a.loggedOut) {
    nav.push(MaterialPageRoute(builder: (_) => LoginPage(relogin: a)));
    return;
  }
  if (focus) IncPage.focusReq.value = id;
  await prefs.setString('last_acc', id); // zuletzt verwendetes Backend, beim nächsten Start wieder geöffnet (Issue #166)
  // bereits offen: nicht doppelt öffnen
  if (IncPage.openId == id) return;
  nav.popUntil((r) => r.isFirst);
  nav.push(MaterialPageRoute(builder: (_) => IncPage(account: a)));
}

String? _pendingOpen;

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initNotes(onTap: (r) {
    alarmStop(); // Meldung angetippt: Weckton aus
    final p = r.payload;
    if (p != null && p.isNotEmpty) openAccount(p, focus: true);
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
    // nach der Anmeldung für Push erneut prüfen, ob der Vordergrunddienst noch nötig ist (Issue #179)
    unawaited(initPush((id) => openAccount(id, focus: true)).then((_) => syncService()));
    final l = await Accounts.load();
    final p = _pendingOpen;
    _pendingOpen = null;
    if (p != null && p.isNotEmpty) {
      await openAccount(p, focus: true);
    } else {
      // zuletzt verwendetes Backend (Issue #166), sonst bei nur einem Konto dieses; abgemeldete führen zur Kontenliste
      final last = await prefs.getString('last_acc');
      final a = l.where((x) => x.id == last).firstOrNull ?? (l.length == 1 ? l.first : null);
      if (a != null && !a.loggedOut) await openAccount(a.id);
    }
    _iosPoll();
  }

  /// iOS hat keinen Hintergrunddienst, unter Android läuft er nur, wenn er nötig ist (Issue #179): solange die App offen ist, fragt sie
  /// dann selbst ab (Kontenliste, Teilen des Standorts).
  Future<void> _iosPoll() async {
    _t?.cancel();
    if (Platform.isAndroid && await serviceNeeded()) return;
    Future<void> run() async {
      if (_busy) return;
      _busy = true;
      try {
        await pollAll(track: Platform.isIOS);
        if (Platform.isAndroid && await serviceNeeded()) {
          _t?.cancel(); // z. B. Einsatz angenommen: ab jetzt fragt der Vordergrunddienst ab
          await syncService();
        }
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
      syncPush().then((_) => syncService());
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
