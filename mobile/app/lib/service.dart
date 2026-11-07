// Android: Vordergrunddienst, der alle aktiven Konten im eingestellten Abstand abfragt (monitor.dart), auch bei geschlossener App
// und nach einem Neustart. Er zeigt dauerhaft «Bereit für Alarme». iOS erlaubt keinen solchen Dienst: dort fragt die App nur, solange
// sie offen ist, und im Hintergrund kommt der Alarm über Push (push.dart).
import 'dart:async';
import 'dart:io';
import 'dart:ui';

import 'package:flutter/widgets.dart';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import 'accounts.dart';
import 'monitor.dart';
import 'notify.dart';
import 'track.dart';

final service = FlutterBackgroundService();

Future<void> configureService() async {
  if (!Platform.isAndroid) return;
  await initNotes();
  await service.configure(
      androidConfiguration: AndroidConfiguration(
          onStart: serviceMain,
          autoStart: false,
          autoStartOnBoot: true,
          isForegroundMode: true,
          notificationChannelId: chService,
          initialNotificationTitle: 'Swiftwater Rescue',
          initialNotificationContent: 'Bereit für Alarme',
          foregroundServiceNotificationId: serviceNoteId,
          foregroundServiceTypes: [AndroidForegroundType.specialUse]),
      iosConfiguration: IosConfiguration(autoStart: false));
}

/// Dienst starten, wenn mindestens ein Konto aktiv ist, sonst beenden.
Future<void> syncService() async {
  if (!Platform.isAndroid) return;
  final on = (await Accounts.load()).any((a) => a.active && !a.loggedOut);
  final run = await service.isRunning();
  if (on && !run) {
    await service.startService();
  } else if (!on && run) {
    service.invoke('stop');
  } else if (on) {
    service.invoke('poll'); // sofort neu abfragen (z. B. nach dem Anmelden)
  }
}

@pragma('vm:entry-point')
Future<void> serviceMain(ServiceInstance s) async {
  DartPluginRegistrant.ensureInitialized();
  WidgetsFlutterBinding.ensureInitialized();
  await initNotes();
  var busy = false, stop = false;
  Timer? t;
  Future<void> run() async {
    if (busy || stop) return;
    busy = true;
    try {
      await refreshChannels(); // «Nicht stören» umgehen, sobald der Zugriff erteilt ist
      final n = await pollAll();
      if (n == 0) {
        stop = true;
        await s.stopSelf();
        return;
      }
      final hm = DateTime.now();
      final txt = '$n ${n == 1 ? 'Konto' : 'Konten'} · zuletzt ${hm.hour.toString().padLeft(2, '0')}:${hm.minute.toString().padLeft(2, '0')}${Tracker.active ? ' · Standort wird geteilt' : ''}';
      if (s is AndroidServiceInstance) {
        await notes.show(
            id: serviceNoteId,
            title: 'Bereit für Alarme',
            body: txt,
            notificationDetails: const NotificationDetails(
                android: AndroidNotificationDetails(chService, 'Bereitschaft', icon: 'ic_stat_sw', ongoing: true, importance: Importance.min, priority: Priority.min, showWhen: false, silent: true)));
      }
      s.invoke('update', {'at': DateTime.now().millisecondsSinceEpoch});
    } finally {
      busy = false;
    }
  }

  Future<void> schedule() async {
    t?.cancel();
    if (stop) return;
    t = Timer.periodic(Duration(seconds: await pollInterval()), (_) => run());
  }

  s.on('stop').listen((_) async {
    stop = true;
    Tracker.stop();
    t?.cancel();
    await s.stopSelf();
  });
  s.on('poll').listen((_) => run());
  s.on('interval').listen((_) => schedule());
  await schedule();
  await run();
}
