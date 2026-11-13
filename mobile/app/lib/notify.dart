// Benachrichtigungen des Geräts. Alarmstufen wie in der Web-App (Issue #90): crit Vollbild + Dauerton, high Vollbild + einmaliger Ton,
// normal Hinweis mit Ton, low still, none nichts. Der Schlüssel (tag) ist derselbe wie in der Web-App (i:, m:, e:), je Konto eindeutig,
// so ersetzt eine Push-Nachricht die Meldung aus dem Polling (und umgekehrt), statt doppelt zu erscheinen.
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/widgets.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'sys.dart';

final notes = FlutterLocalNotificationsPlugin();

const chCrit = 'alarm_crit', chCritDnd = 'alarm_crit_dnd', chHigh = 'alarm_high', chNormal = 'info', chLow = 'silent', chService = 'service';
const serviceNoteId = 1;

bool _ready = false;
bool _dnd = false; // Zugriff auf «Nicht stören» beim letzten Anlegen der Kanäle

/// Kennung je Konto und Schlüssel (positiv, 31 Bit), stabil über Isolate und Neustarts.
int noteId(String acc, String tag) {
  var h = 0x811c9dc5;
  for (final c in utf8.encode('$acc|$tag')) {
    h = ((h ^ c) * 0x01000193) & 0x7fffffff;
  }
  return h < 10 ? h + 10 : h;
}

/// force: Kanäle prüfen. Android übernimmt «Nicht stören umgehen» (bypassDnd) nur beim ersten Anlegen eines Kanals und nur mit Zugriff
/// auf «Nicht stören»; ein bestehender Kanal ändert sich nie mehr (auch nicht nach Löschen und Neuanlegen mit derselben ID). Deshalb
/// gibt es dafür den eigenen Kanal chCritDnd, angelegt, sobald der Zugriff besteht.
Future<void> initNotes({void Function(NotificationResponse)? onTap, bool force = false}) async {
  if (_ready && onTap == null && !force) return;
  if (!_ready || onTap != null) {
    // nicht ohne onTap neu initialisieren, sonst ginge das Öffnen beim Antippen verloren
    await notes.initialize(
        settings: const InitializationSettings(
            android: AndroidInitializationSettings('ic_stat_sw'),
            iOS: DarwinInitializationSettings(requestAlertPermission: false, requestBadgePermission: false, requestSoundPermission: false, requestCriticalPermission: false)),
        onDidReceiveNotificationResponse: onTap);
    _ready = true;
  }
  final an = notes.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
  if (an != null) {
    _dnd = await an.hasNotificationPolicyAccess() == true;
    if (_dnd) {
      await an.createNotificationChannel(AndroidNotificationChannel(chCritDnd, 'Alarm (kritisch, auch bei «Nicht stören»)',
          description: 'Alarmierung mit Dauerton, umgeht «Nicht stören»', importance: Importance.max, bypassDnd: true, audioAttributesUsage: AudioAttributesUsage.alarm,
          vibrationPattern: Int64List.fromList([0, 800, 400, 800, 400, 800])));
    }
    // Kanäle: Ton und Wichtigkeit lassen sich nach dem Anlegen nur noch in den Systemeinstellungen ändern
    await an.createNotificationChannel(AndroidNotificationChannel(chCrit, 'Alarm (kritisch)',
        description: 'Alarmierung mit Dauerton, auch bei «Nicht stören», sofern erlaubt', importance: Importance.max, bypassDnd: true, audioAttributesUsage: AudioAttributesUsage.alarm,
        vibrationPattern: Int64List.fromList([0, 800, 400, 800, 400, 800])));
    await an.createNotificationChannel(const AndroidNotificationChannel(chHigh, 'Alarm', description: 'Alarmierung mit einmaligem Ton', importance: Importance.max));
    await an.createNotificationChannel(const AndroidNotificationChannel(chNormal, 'Nachrichten und Hinweise', description: 'Nachrichten im Einsatz, Einsatzende', importance: Importance.high));
    await an.createNotificationChannel(const AndroidNotificationChannel(chLow, 'Stille Hinweise', description: 'Alarmstufe «tief»: ohne Ton', importance: Importance.low, playSound: false, enableVibration: false));
    await an.createNotificationChannel(const AndroidNotificationChannel(chService, 'Bereitschaft', description: 'Dauerhafte Anzeige, solange die App Alarme empfängt', importance: Importance.min, playSound: false, enableVibration: false, showBadge: false));
  }
}

/// Berechtigungen anfragen (Benachrichtigungen, iOS kritische Hinweise; Android Vollbild-Alarm).
Future<bool> requestNotePerms() async {
  if (Platform.isIOS) {
    final i = notes.resolvePlatformSpecificImplementation<IOSFlutterLocalNotificationsPlugin>();
    return await i?.requestPermissions(alert: true, badge: true, sound: true, critical: true) ?? false;
  }
  final an = notes.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
  final ok = await an?.requestNotificationsPermission() ?? true;
  await an?.requestFullScreenIntentPermission();
  return ok;
}

/// Android: Zugriff auf «Nicht stören» (Systemeinstellung), damit der kritische Kanal durchkommt.
Future<void> requestDndAccess() async {
  final an = notes.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
  if (an == null) return;
  if (await an.hasNotificationPolicyAccess() != true) await an.requestNotificationPolicyAccess();
}

/// Kanäle auffrischen (nach dem Erteilen von «Nicht stören», bei jedem Zurückkehren in die App und im Dienst).
Future<void> refreshChannels() => initNotes(force: true);

Future<bool?> hasDndAccess() async => notes.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()?.hasNotificationPolicyAccess();

/// iOS (Issue #184): Eine Push-Nachricht zeigt dort das System (APNs) selbst, eine Meldung der App mit demselben Schlüssel ersetzt sie
/// nicht, sondern steht daneben. Läuft die App im Hintergrund weiter (z. B. beim Teilen des Standorts im Einsatz), meldete sie Alarm,
/// Nachrichten und Einsatzende deshalb doppelt. Ist das Konto für Push angemeldet, meldet im Hintergrund nur noch Push, was der Server
/// auch pusht (i:, m:, e:); andere Meldungen (z. B. «aus dem Einsatz entfernt») und alles im Vordergrund zeigt weiterhin die App.
Future<bool> _pushShows(String acc, String tag) async {
  if (!Platform.isIOS || !RegExp(r'^[ime]:').hasMatch(tag)) return false;
  if (WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed) return false;
  try {
    final t = await SharedPreferencesAsync().getString('push_$acc'); // gesetzt von syncPush (push.dart): Token = angemeldet
    return t != null && t != 'none';
  } catch (_) {
    return false;
  }
}

/// Meldung zeigen. lvl: crit|high|normal|low; payload = Konto-ID (Antippen öffnet das Konto).
Future<void> showNote(String acc, String tag, String title, String body, String lvl, {String? sub}) async {
  await initNotes();
  if (await _pushShows(acc, tag)) return;
  final crit = lvl == 'crit', high = lvl == 'high', low = lvl == 'low' || lvl == 'none';
  final ch = crit ? (_dnd ? chCritDnd : chCrit) : (high ? chHigh : (low ? chLow : chNormal));
  // kritisch: Weckton über den Wecker (sys.dart), klingelt auch lautlos und bei «Nicht stören»; die Meldung selbst wiederholt
  // ihren Ton nur, wenn der Weckton nicht startet
  final ring = crit && Platform.isAndroid && await alarmStart();
  final android = AndroidNotificationDetails(ch, ch,
      importance: low ? Importance.low : Importance.max,
      priority: low ? Priority.low : Priority.max,
      category: crit || high ? AndroidNotificationCategory.alarm : AndroidNotificationCategory.message,
      fullScreenIntent: crit || high,
      audioAttributesUsage: crit ? AudioAttributesUsage.alarm : AudioAttributesUsage.notification,
      additionalFlags: crit && !ring ? Int32List.fromList([4]) : null, // FLAG_INSISTENT: Ton wiederholen, bis man die Meldung antippt
      autoCancel: true,
      ongoing: crit,
      silent: low,
      subText: sub,
      visibility: NotificationVisibility.public,
      styleInformation: BigTextStyleInformation(body),
      tag: tag);
  final ios = DarwinNotificationDetails(
      presentAlert: true,
      presentSound: !low,
      presentBanner: true,
      presentList: true,
      threadIdentifier: tag,
      subtitle: sub,
      interruptionLevel: crit ? InterruptionLevel.critical : (high ? InterruptionLevel.timeSensitive : (low ? InterruptionLevel.passive : InterruptionLevel.active)),
      criticalSoundVolume: crit ? 1.0 : null);
  await notes.show(id: noteId(acc, tag), title: title, body: body, notificationDetails: NotificationDetails(android: android, iOS: ios), payload: acc);
}

Future<void> cancelNote(String acc, String tag) async {
  if (tag.startsWith('i:')) await alarmStop(); // Alarm quittiert: Weckton aus
  await notes.cancel(id: noteId(acc, tag), tag: tag);
}
