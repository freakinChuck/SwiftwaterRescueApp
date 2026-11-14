// Nativer Push über Firebase Cloud Messaging (optional). Die Firebase-Angaben kommen beim Bauen über --dart-define (siehe README);
// ohne sie läuft die App ohne Push (Android: Vordergrunddienst, iOS: nur bei offener App).
// Der Server sendet nur, wenn er ein Firebase-Dienstkonto hat (config.php, «fcm»; push/key liefert native=1).
// Android erhält reine Daten-Nachrichten und zeigt sie selbst an (Alarmstufe, Vollbild); iOS zeigt sie über das System (APNs).
import 'dart:convert';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/widgets.dart';

import 'accounts.dart';
import 'api.dart';
import 'monitor.dart';
import 'sounds.dart';

const _apiKey = String.fromEnvironment('FCM_API_KEY');
const _project = String.fromEnvironment('FCM_PROJECT_ID');
const _sender = String.fromEnvironment('FCM_SENDER_ID');
const _appAndroid = String.fromEnvironment('FCM_APP_ID_ANDROID');
const _appIos = String.fromEnvironment('FCM_APP_ID_IOS');

bool get pushBuilt => _apiKey.isNotEmpty && _project.isNotEmpty && _sender.isNotEmpty && (Platform.isIOS ? _appIos : _appAndroid).isNotEmpty;

bool pushReady = false;

/// Letztes Hindernis bei der Anmeldung für Push (für die Anzeige auf der Startseite), null = keines.
String? pushIssue;

Future<bool> _initFirebase() async {
  if (!pushBuilt) return false;
  if (Firebase.apps.isNotEmpty) return true;
  try {
    await Firebase.initializeApp(
        options: FirebaseOptions(apiKey: _apiKey, appId: Platform.isIOS ? _appIos : _appAndroid, messagingSenderId: _sender, projectId: _project, iosBundleId: Platform.isIOS ? 'ch.swiftwaterrescue.app' : null));
    return true;
  } catch (e) {
    debugPrint('Firebase: $e');
    pushIssue = 'Firebase nicht startbar ($e)';
    return false;
  }
}

/// Konto zur Nachricht finden: srv = Host und Pfad des Backends, uid = Empfänger.
Future<Account?> accountFor(Map<String, dynamic> d) async {
  final srv = '${d['srv'] ?? ''}'.toLowerCase(), uid = int.tryParse('${d['uid'] ?? ''}');
  final l = await Accounts.load();
  bool same(Account a) => hostPath(a.base).toLowerCase() == srv || hostPath(a.base).toLowerCase().replaceAll(RegExp(r'/$'), '') == srv.replaceAll(RegExp(r'/$'), '');
  final m = l.where((a) => same(a) && (uid == null || a.uid == uid)).toList();
  if (m.isNotEmpty) return m.first;
  final h = l.where(same).toList();
  return h.isEmpty ? null : h.first;
}

String _lvl(Map<String, dynamic> d) => d['crit'] == '1' ? 'crit' : (d['stay'] == '1' ? 'high' : (d['silent'] == '1' ? 'low' : 'normal'));

Future<void> _show(RemoteMessage m) async {
  final d = m.data;
  final a = await accountFor(d);
  if (a == null || !a.active) return;
  final tag = '${d['tag'] ?? 'sw'}';
  await report(a, tag, '${d['t'] ?? m.notification?.title ?? 'Swiftwater Rescue'}', '${d['b'] ?? m.notification?.body ?? ''}', _lvl(d),
      kind: int.tryParse('${d['kind'] ?? ''}'), ksnd: d['ksnd'] as String?);
}

@pragma('vm:entry-point')
Future<void> pushBackground(RemoteMessage m) async {
  WidgetsFlutterBinding.ensureInitialized();
  if (!await _initFirebase()) return;
  if (Platform.isIOS) return; // iOS zeigt die Meldung selbst (aps.alert)
  await _show(m);
}

/// Beim Start: Firebase einrichten, Nachrichten im Vordergrund selbst anzeigen, Antippen öffnet das Konto (onOpen).
Future<void> initPush(void Function(String accId) onOpen) async {
  if (!await _initFirebase()) return;
  FirebaseMessaging.onBackgroundMessage(pushBackground);
  FirebaseMessaging.onMessage.listen(_show);
  Future<void> open(RemoteMessage m) async {
    final a = await accountFor(m.data);
    if (a != null) {
      await markTag(a.id, '${m.data['tag'] ?? ''}');
      onOpen(a.id);
    }
  }

  FirebaseMessaging.onMessageOpenedApp.listen(open);
  final first = await FirebaseMessaging.instance.getInitialMessage();
  if (first != null) await open(first);
  // ignore: deprecated_member_use
  FirebaseMessaging.instance.onTokenRefresh.listen((_) => syncPush(force: true));
  pushReady = true;
  await syncPush();
}

/// Gerät bei allen aktiven Konten anmelden (und bei inaktiven abmelden). Merkt sich den gemeldeten Stand je Konto.
/// Bei jedem Aufruf fragt die App den Server (push/key mit Token), ob das Gerät dort angemeldet ist, und meldet es sonst neu an:
/// Der Server kann FCM erst später erhalten ('none' gilt nur bis zur nächsten Prüfung) oder das Abo löschen (Sitzung entfernt, Passwort zurückgesetzt, Token abgelehnt).
Future<void> syncPush({bool force = false}) async {
  if (!pushReady) return;
  String? tok;
  try {
    if (Platform.isIOS) {
      final ns = await FirebaseMessaging.instance.requestPermission(alert: true, sound: true, badge: true, criticalAlert: true);
      if (ns.authorizationStatus == AuthorizationStatus.denied) {
        pushIssue = 'Mitteilungen sind in den iOS-Einstellungen für die App ausgeschaltet';
        return;
      }
      if (await FirebaseMessaging.instance.getAPNSToken() == null) {
        pushIssue = 'noch kein Geräte-Token von Apple (APNs) erhalten';
        return; // später erneut
      }
    }
    // ignore: deprecated_member_use
    tok = await FirebaseMessaging.instance.getToken();
  } catch (e) {
    debugPrint('FCM-Token: $e');
    pushIssue = 'kein Firebase-Token ($e)';
  }
  if (tok == null) return;
  pushIssue = null;
  for (final a in await Accounts.load()) {
    final k = 'push_${a.id}', had = await prefs.getString(k);
    final want = a.active && !a.loggedOut;
    try {
      if (want) {
        // Server ohne FCM höchstens alle 6 h erneut fragen (er schreibt dazu jeweils eine Warnung ins Applikationslog)
        final ck = 'pushchk_${a.id}', last = int.tryParse(await prefs.getString(ck) ?? '') ?? 0, now = DateTime.now().millisecondsSinceEpoch;
        if (!force && had == 'none' && now - last < 6 * 3600 * 1000) continue;
        final key = await a.api.call('push/key', body: {'token': tok});
        if (key['native'] != 1) {
          await prefs.setString(k, 'none');
          await prefs.setString(ck, '$now');
          continue;
        }
        // Ältere Server liefern registered nicht: dann wie bisher nur bei neuem Token
        final reg = key['registered'];
        // gewählte Töne je Einsatztyp (Issue #171): iOS spielt über APNs den Ton, den der Server nennt; geänderte Wahl neu melden
        final snd = await sndChoice(a.id), sk = 'pushsnd_${a.id}', sj = jsonEncode(snd);
        if (!force && had == tok && (reg == null || reg == 1) && (await prefs.getString(sk) ?? '{}') == sj) continue;
        await a.api.call('push/native/subscribe', body: {'token': tok, 'platform': Platform.isIOS ? 'ios' : 'android', 'snd': snd});
        await prefs.setString(k, tok);
        await prefs.setString(sk, sj);
      } else if (!want && had != null && had != 'none') {
        await a.api.call('push/native/unsubscribe', body: {'token': had});
        await prefs.remove(k);
      }
    } catch (e) {
      debugPrint('Push ${a.label}: $e');
      pushIssue = 'Anmeldung beim Server fehlgeschlagen ($e)';
    }
  }
}

/// Vor dem Entfernen bzw. Abmelden eines Kontos: Gerät dort abmelden.
Future<void> pushForget(Account a) async {
  final k = 'push_${a.id}', had = await prefs.getString(k);
  if (had != null && had != 'none') {
    try {
      await a.api.call('push/native/unsubscribe', body: {'token': had});
    } catch (_) {}
  }
  await prefs.remove(k);
}

/// Für die Anzeige: Push auf diesem Konto eingerichtet?
Future<String> pushStatus(Account a) async {
  if (!pushBuilt) return 'off';
  final had = await prefs.getString('push_${a.id}');
  return had == null ? 'pending' : (had == 'none' ? 'server' : 'on');
}
