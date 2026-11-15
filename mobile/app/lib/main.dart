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
import 'sounds.dart';
import 'sys.dart';
import 'inc/map_page.dart';
import 'inc/store.dart' show IncStore;
import 'accounts.dart';
import 'beacon.dart';
import 'demo.dart';
import 'demo_ui.dart' show demoSource;

final navKey = GlobalKey<NavigatorState>();

/// Einsatz-App eines Kontos öffnen (aus der Liste, nach dem Antippen einer Meldung oder einer Push-Nachricht).
/// focus: aus einer Meldung geöffnet, die Einsatz-App zeigt das Einsatzgebiet (IncPage.focusReq).
/// tag: Schlüssel der Meldung; bei einem Alarm (`i:<Einsatz>`) zeigt die Einsatz-App diesen Einsatz (parallele Einsätze, Issue #167).
Future<void> openAccount(String id, {bool focus = false, String? tag}) async {
  if (id == demoId) return openDemo(); // Meldung aus dem Demo-Modus angetippt (Issue #74)
  final l = await Accounts.load();
  final a = l.where((x) => x.id == id).firstOrNull;
  final nav = navKey.currentState;
  if (a == null || nav == null) return;
  if (a.loggedOut) {
    nav.push(MaterialPageRoute(builder: (_) => LoginPage(relogin: a)));
    return;
  }
  final ii = int.tryParse(RegExp(r'^i:(\d+)$').firstMatch(tag ?? '')?.group(1) ?? '');
  if (ii != null) IncStore.selReq.value = (id, ii);
  if (focus) IncPage.focusReq.value = id;
  await prefs.setString('last_acc', id); // zuletzt verwendetes Backend, beim nächsten Start wieder geöffnet (Issue #166)
  // bereits offen: nicht doppelt öffnen
  if (IncPage.openId == id) return;
  nav.popUntil((r) => r.isFirst);
  nav.push(MaterialPageRoute(builder: (_) => IncPage(account: a)));
}

/// Demo-Modus (Issue #74): Einsatz-App mit dem simulierten Backend (demo.dart) öffnen; mode null = bisherigen Stand weiterführen
Future<void> openDemo([String? mode]) async {
  if (navKey.currentState == null) return;
  if (mode != null || !Demo.started) {
    // «Ohne Daten» mit einem angemeldeten Konto: dessen Vorgaben übernehmen (bei mehreren Konten Auswahl, Abbrechen = nicht starten)
    final (ok, src) = mode == 'leer' ? await demoSource() : (true, null);
    if (!ok) return;
    Demo.restart(mode ?? 'bsp', src: src);
  }
  final nav = navKey.currentState;
  if (nav == null) return;
  if (mode == null && IncPage.openId == demoId) return;
  nav.popUntil((r) => r.isFirst);
  nav.push(MaterialPageRoute(builder: (_) => IncPage(account: demoAccount())));
}

String? _pendingOpen;

/// Meldung angetippt: payload «Konto-ID|Schlüssel» (ältere Meldungen nur die Konto-ID)
Future<void> _openNote(String? p) async {
  if (p == null || p.isEmpty) return;
  final i = p.indexOf('|');
  await openAccount(i < 0 ? p : p.substring(0, i), focus: true, tag: i < 0 ? null : p.substring(i + 1));
}

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initNotes(onTap: (r) {
    alarmStop(); // Meldung angetippt: Weckton aus
    _openNote(r.payload);
  });
  final start = await notes.getNotificationAppLaunchDetails();
  if (start?.didNotificationLaunchApp == true) _pendingOpen = start!.notificationResponse?.payload;
  await configureService();
  Beacons.listen(); // iOS: Sichtungen der Fahrzeuge melden, wenn das System die App weckt (Issue #177)
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
    unawaited(ensureSounds()); // Töne für kritische Alarme ablegen (Issue #171)
    await requestNotePerms();
    await syncService();
    // nach der Anmeldung für Push erneut prüfen, ob der Vordergrunddienst noch nötig ist (Issue #179)
    unawaited(initPush((id, tag) => openAccount(id, focus: true, tag: tag)).then((_) => syncService()));
    final l = await Accounts.load();
    final p = _pendingOpen;
    _pendingOpen = null;
    if (p != null && p.isNotEmpty) {
      await _openNote(p);
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
