// Version der App (Issue #175): gemeinsame Version mit Web und API (Datei VERSION im Hauptordner), beim Bauen gesetzt über
// --dart-define=APP_VERSION/APP_BUILD (.github/workflows/mobile.yml, ios.yml). Ohne Angaben (lokaler Build) 0.0.0: dann kein Hinweis und kein «Was ist neu».
// Der Server erhält die Version bei der Anmeldung (app_ver) und im Header X-App-Version (api.dart), die Verwaltung zeigt sie unter System → «Versionen».
// Hinweis «Bitte App aktualisieren» (updateNote) bei Mindestversion app_min des Servers oder höherer API-Stufe; gesperrt wird nie.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'api.dart';

const appVersion = String.fromEnvironment('APP_VERSION', defaultValue: '0.0.0');
const appBuild = String.fromEnvironment('APP_BUILD', defaultValue: '0');

/// Kompatibilitätsstufe der API, für die diese App gebaut ist (API_LEVEL in web/api/index.php)
const apiLevel = 1;

bool get appVerKnown => appVersion != '0.0.0';
String get appVerLabel => appVerKnown ? '$appVersion (Build $appBuild)' : 'Entwicklungsstand ohne Version';

/// Für den Server (appVer in index.php): Version plus Build, z. B. 0.4.0+2611131200
String get appVerHeader => '$appVersion+${RegExp(r'^\d{1,12}$').hasMatch(appBuild) ? appBuild : '0'}';

List<int> _vp(String s) {
  final m = RegExp(r'^(\d+)\.(\d+)\.(\d+)').firstMatch(s.trim());
  return m == null ? [0, 0, 0] : [int.parse(m.group(1)!), int.parse(m.group(2)!), int.parse(m.group(3)!)];
}

/// Versionen vergleichen (nur MAJOR.MINOR.PATCH): <0, 0, >0
int verCmp(String a, String b) {
  final x = _vp(a), y = _vp(b);
  for (var i = 0; i < 3; i++) {
    if (x[i] != y[i]) return x[i].compareTo(y[i]);
  }
  return 0;
}

/// Anzeige der Server-Version (verTxt in common.js): Produktiv nur die Nummer, sonst mit Abstand, Commit und System
String verTxt(Map? v) {
  if (v == null || v['version'] == null) return '';
  final env = '${v['env'] ?? ''}';
  return '${v['full'] ?? v['version']}${env.isNotEmpty && env != 'prod' ? ' · ${v['env_name'] ?? env}${v['pr'] != null ? ' PR ${v['pr']}' : ''}' : ''}';
}

/// Hinweis für ältere Apps: Server verlangt eine höhere Mindestversion (app_min) oder hat eine höhere API-Stufe; null = aktuell
String? updateNote(Map? ver, String? appMin) {
  if (!appVerKnown) return null;
  if (appMin != null && appMin.isNotEmpty && verCmp(appVersion, appMin) < 0) {
    return 'Bitte App aktualisieren: Dieser Server verlangt mindestens Version $appMin, installiert ist $appVersion. Alarme und Einsätze funktionieren weiter.';
  }
  final a = ver?['api'];
  if (a is num && a > apiLevel) return 'Bitte App aktualisieren: Der Server ist neuer als diese App, einzelne Funktionen können fehlen.';
  return null;
}

/// Store öffnen: Android Google Play, iOS TestFlight (Verteilung vor dem Produktivstart)
Future<void> openStore() async {
  final u = Uri.parse(Platform.isAndroid ? 'https://play.google.com/store/apps/details?id=ch.swiftwaterrescue.app' : 'itms-beta://');
  try {
    await launchUrl(u, mode: LaunchMode.externalApplication);
  } catch (_) {}
}

/// «Später» beim Hinweis: 7 Tage ausblenden (wie sw_pushlater in der Web-App)
Future<bool> updateLater() async {
  final sp = await SharedPreferences.getInstance();
  return DateTime.now().millisecondsSinceEpoch - (sp.getInt('updlater') ?? 0) < 7 * 86400000;
}

Future<void> setUpdateLater() async => (await SharedPreferences.getInstance()).setInt('updlater', DateTime.now().millisecondsSinceEpoch);

/// «Was ist neu» (Route changes des Servers): since = nur Versionen danach; quiet = ohne Fehlermeldung und ohne Dialog, wenn nichts Neues
Future<void> showChanges(BuildContext context, Api api, {String? since, bool quiet = false}) async {
  List vs;
  try {
    vs = ((await api.call('changes'))['versions'] as List?) ?? [];
  } catch (e) {
    if (quiet || !context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
    return;
  }
  if (since != null) vs = vs.where((v) => v is Map && verCmp('${v['version']}', since) > 0).toList();
  if (quiet && vs.isEmpty) return;
  if (!context.mounted) return;
  final th = Theme.of(context);
  await showDialog<void>(
      context: context,
      builder: (c) => AlertDialog(
            title: const Text('Was ist neu'),
            content: SizedBox(
                width: double.maxFinite,
                child: vs.isEmpty
                    ? const Text('Keine Angaben.')
                    : ListView(shrinkWrap: true, children: [
                        for (final v in vs.whereType<Map>()) ...[
                          Padding(
                              padding: const EdgeInsets.only(top: 10, bottom: 4),
                              child: Text('Version ${v['version']}${'${v['date'] ?? ''}'.isNotEmpty ? '  ·  ${'${v['date']}'.split('-').reversed.join('.')}' : ''}',
                                  style: th.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold))),
                          for (final t in (v['items'] as List? ?? [])) Padding(padding: const EdgeInsets.only(bottom: 4), child: Text('•  $t')),
                        ],
                      ])),
            actions: [FilledButton(onPressed: () => Navigator.pop(c), child: const Text('OK'))],
          ));
}

/// Nach einem App-Update einmal «Was ist neu» mit den Versionen seit der zuletzt gesehenen (vom Server des Kontos); beim ersten Start nur merken
Future<void> whatsNew(BuildContext context, Api api) async {
  if (!appVerKnown) return;
  final sp = await SharedPreferences.getInstance();
  final seen = sp.getString('seenver');
  if (seen == appVersion) return;
  await sp.setString('seenver', appVersion);
  if (seen == null || verCmp(appVersion, seen) <= 0) return;
  if (!context.mounted) return;
  await showChanges(context, api, since: seen, quiet: true);
}
