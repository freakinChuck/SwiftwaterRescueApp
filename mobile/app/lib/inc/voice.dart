// Funk & Konferenz (Issue #113) der nativen Einsatz-App, Gegenstück zu voice.js und dem Funk-Block in web/index.html:
// Durchsagen aufnehmen (record, AAC in m4a) und abspielen (just_audio), Mithören (neue Durchsagen sofort abspielen, solange die
// Einsatz-App offen ist), Nachsenden aus einem Funkloch (vxq_<Konto>), Live-Konferenz über LiveKit (livekit_client).
// Daten aus incident/state (voice: ch, items mit vs inkrementell, conf, conf_list …), Anwesenheit über vc beim Abruf.
// Unter Android läuft die Konferenz bei gesperrtem Bildschirm weiter, weil der Vordergrunddienst dann den Typ microphone trägt
// (vx_conf, service.dart); unter iOS über den Hintergrundmodus audio.
import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:just_audio/just_audio.dart';
import 'package:livekit_client/livekit_client.dart' as lk;
import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';
import 'package:share_plus/share_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api.dart';
import '../monitor.dart' show prefs;
import '../service.dart';
import 'ctx.dart';
import 'store.dart';
import 'util.dart';

String vxDur(num? ms) {
  final s = ((ms ?? 0) / 1000).round();
  return '${s ~/ 60}:${(s % 60).toString().padLeft(2, '0')}';
}

const _keepTxt = {'both': 'Aufnahme und Transkript', 'tr': 'nur Transkript', 'audio': 'nur Aufnahme', 'none': 'nichts nach dem Einsatzende'};

class VoiceStore extends ChangeNotifier {
  VoiceStore(this.s);
  final IncStore s;
  SharedPreferences? _sp;
  String _key = '';
  final items = <int, J>{};
  Set<int> heard = {};
  bool _first = true;
  int vs = 0;
  bool listen = true;
  final queue = <int>[];
  int? playing;
  AudioPlayer? _pl;
  AudioPlayer? _tonePl; // Töne beim Beitreten und Verlassen der Konferenz, getrennt von den Durchsagen
  final _files = <int, String>{};
  // Aufnahme
  AudioRecorder? _rec;
  int? recCh;
  DateTime? recT0;
  int recAt = 0;
  bool recDrop = false, recBusy = false;
  Timer? _recTm;
  int pend = 0;
  bool _flushing = false;
  // Konferenz
  lk.Room? room;
  lk.EventsListener<lk.RoomEvent>? _ls;
  int? confCh;
  int confSince = 0;
  String confState = ''; // connecting | on | reconnecting
  bool confSpeak = false, confOpen = false, confMic = false, _wasConf = false;
  bool confMay = false, confMuted = false; // Sprechrecht im Kanal; von der Einsatzleitung stumm geschaltet (incident/voice/mute)
  Set<String> speakers = {}, mics = {}; // wer spricht (ActiveSpeakers), wer das Mikrofon offen hat
  String confMe = ''; // eigene Kennung im Raum (u<User-ID>)
  String? recBtn; // welche Sprechtaste gedrückt ist (nur sie leuchtet)
  // Durchsagen und Konferenz getrennt: in der Konferenz «A» sein und Durchsagen in «B» senden
  int? spk; // Kanal der Sprechtaste auf der Karte (Durchsage)
  int? ch; // Kanal der Durchsagen im Fenster
  int? cc; // Kanal der Konferenz im Fenster

  J? get v => s.st?['voice'] is Map ? jm(s.st!['voice']) : null;
  bool get on => s.inc != null && v != null && s.feat('voice') && chs.isNotEmpty;
  List<J> get chs => jl(v?['ch']);
  List<J> get speakChs => chs.where((c) => jb(c['speak'])).toList();
  int get maxS => ji(v?['max_s']) ?? 60;
  bool get confAvail => jb(v?['conf']);
  bool get recording => recCh != null;
  int get recS => recT0 == null ? 0 : DateTime.now().difference(recT0!).inSeconds;
  bool get tap => _sp?.getBool('vx_tap') ?? false;
  bool get pttMap => _sp?.getBool('vx_ptt') ?? true;
  String chName(int? id) => js((chs.where((c) => ji(c['id']) == id).firstOrNull ?? jl(v?['all_ch']).where((c) => ji(c['id']) == id).firstOrNull ?? {'name': 'Kanal'})['name']);
  List<J> list(int? c) => (items.values.where((x) => c == null || ji(x['ch']) == c).toList()..sort((a, b) => (ji(a['id']) ?? 0).compareTo(ji(b['id']) ?? 0)));
  int unheard(int? c) => list(c).where((x) => !heard.contains(ji(x['id']))).length;

  /// Abfrage: vs (Stand), vc (Anwesenheit in der Konferenz: Kanal bzw. 0 = verlassen)
  Map<String, String> query() => {'vs': '$vs', if (confCh != null && confState != 'connecting') 'vc': '$confCh' else if (_wasConf) 'vc': '0'};

  Future<void> onState(J nx) async {
    _sp ??= await SharedPreferences.getInstance();
    final i = nx['incident'] is Map ? jm(nx['incident']) : null, vo = nx['voice'] is Map ? jm(nx['voice']) : null;
    if (confCh == null) _wasConf = false;
    if (i == null || vo == null) {
      if (room != null) await confLeave();
      if (recording) await recStop(drop: true);
      notifyListeners();
      return;
    }
    final k = '${s.acc.id}_${i['id']}';
    if (k != _key) {
      _key = k;
      items.clear();
      queue.clear();
      _first = true;
      vs = 0;
      heard = (_sp!.getStringList('vxheard_$k') ?? []).map(int.parse).toSet();
      final l = _sp!.getBool('vx_listen');
      listen = l ?? jb(vo['listen']);
    }
    final fresh = <J>[];
    for (final it in jl(vo['items'])) {
      final id = ji(it['id'])!, had = items.containsKey(id);
      items[id] = it;
      if (_first || ji(it['uid']) == s.uid) {
        heard.add(id);
      } else if (confCh == ji(it['ch']) && (ji(it['rec_at']) ?? 0) >= confSince - 2) {
        heard.add(id); // live in der Konferenz gehört
      } else if (!had && !heard.contains(id)) {
        fresh.add(it);
      }
    }
    if (jn(nx['now']) != null) vs = ji(nx['now'])!;
    _first = false;
    _saveHeard();
    final lv = js(nx['msg_level']).isEmpty ? 'normal' : js(nx['msg_level']);
    if (listen && s.visible) {
      for (final it in fresh.where((x) => jb(x['audio']))) {
        final id = ji(it['id'])!;
        if (!queue.contains(id) && playing != id) queue.add(id);
      }
      _next();
    } else {
      for (final it in fresh.reversed.take(3)) {
        s.notify('Durchsage «${chName(ji(it['ch']))}»', '${js(it['name'])} (${vxDur(jn(it['dur']))})', 'v:${it['id']}', lv == 'none' ? 'low' : lv);
      }
    }
    if (room != null && (!confAvail || !chs.any((c) => ji(c['id']) == confCh))) await confLeave();
    if (room != null && confMe.isNotEmpty) {
      final x = jl(vo['conf_list']).where((y) => js(y['ident']) == confMe && ji(y['ch']) == confCh).firstOrNull;
      if (x != null) _setMuted(jb(x['muted']));
    }
    if (!chs.any((c) => ji(c['id']) == cc)) cc = confCh ?? ji((chs.where((c) => ji(c['id']) == _sp!.getInt('vx_cc')).firstOrNull ?? chs.firstOrNull)?['id']);
    if (!speakChs.any((c) => ji(c['id']) == spk)) spk = ji((speakChs.where((c) => ji(c['id']) == _sp!.getInt('vx_spk')).firstOrNull ?? speakChs.firstOrNull)?['id']);
    if (!chs.any((c) => ji(c['id']) == ch)) ch = ji((chs.where((c) => ji(c['id']) == spk).firstOrNull ?? chs.firstOrNull)?['id']);
    unawaited(_flush());
    notifyListeners();
  }

  void _saveHeard() {
    final l = heard.toList()..sort();
    _sp?.setStringList('vxheard_$_key', (l.length > 500 ? l.sublist(l.length - 500) : l).map((e) => '$e').toList());
  }

  void markHeard(int? c) {
    for (final x in list(c)) {
      heard.add(ji(x['id'])!);
    }
    _saveHeard();
    notifyListeners();
  }

  void setListen(bool on) {
    listen = on;
    _sp?.setBool('vx_listen', on);
    if (!on) queue.clear();
    notifyListeners();
  }

  void setPref(String k, bool on) {
    _sp?.setBool(k, on);
    notifyListeners();
  }

  void setSpk(int id) {
    spk = id;
    ch = id;
    _sp?.setInt('vx_spk', id);
    notifyListeners();
  }

  /// Kanal der Durchsagen im Fenster; mit Sprechrecht auch der Kanal der Sprechtaste auf der Karte
  void setCh(int id) {
    ch = id;
    if (speakChs.any((c) => ji(c['id']) == id)) {
      spk = id;
      _sp?.setInt('vx_spk', id);
    }
    markHeard(id);
  }

  void setCc(int id) {
    cc = id;
    _sp?.setInt('vx_cc', id);
    notifyListeners();
  }

  // ---------- Wiedergabe ----------
  Future<String> _file(int id) async {
    final f = _files[id];
    if (f != null && File(f).existsSync()) return f;
    final a = s.api, r = await http.get(a.uri('voice/get', {'id': '$id'}), headers: {'X-Token': a.token ?? '', 'User-Agent': appUa}).timeout(const Duration(seconds: 30));
    if (r.statusCode != 200) {
      String m = 'Fehler ${r.statusCode}';
      try {
        m = '${jsonDecode(utf8.decode(r.bodyBytes))['error']}';
      } catch (_) {}
      throw ApiError(m, r.statusCode);
    }
    final ct = r.headers['content-type'] ?? '', ext = ct.contains('webm') ? 'webm' : (ct.contains('ogg') ? 'ogg' : (ct.contains('mpeg') ? 'mp3' : (ct.contains('wav') ? 'wav' : 'm4a')));
    final p = '${(await getTemporaryDirectory()).path}/vx_${s.acc.id}_$id.$ext';
    await File(p).writeAsBytes(r.bodyBytes);
    _files[id] = p;
    return p;
  }

  Future<void> play(int id) async {
    try {
      playing = id;
      notifyListeners();
      final p = await _file(id);
      final pl = _pl ??= AudioPlayer();
      await pl.stop();
      await pl.setFilePath(p);
      unawaited(pl.playerStateStream.firstWhere((x) => x.processingState == ProcessingState.completed).then((_) => _ended(id)).catchError((_) {}));
      unawaited(pl.play());
    } catch (e) {
      s.toast('Durchsage konnte nicht abgespielt werden: ${e is ApiError ? e.message : e}', 'err');
      _ended(id);
    }
  }

  void _ended(int id) {
    if (playing != id) return;
    playing = null;
    heard.add(id);
    _saveHeard();
    notifyListeners();
    _next();
  }

  void _next() {
    if (playing != null || queue.isEmpty || recording) return;
    play(queue.removeAt(0));
  }

  Future<void> stop() async {
    queue.clear();
    final p = playing;
    playing = null;
    if (p != null) heard.add(p);
    _saveHeard();
    await _pl?.stop();
    notifyListeners();
  }

  // ---------- Aufnahme ----------
  Future<void> recStart(int c, {String? btn}) async {
    if (recording || recBusy) return;
    recBusy = true;
    recBtn = btn;
    try {
      await _pl?.pause();
      playing = null;
      final r = _rec ??= AudioRecorder();
      if (!await r.hasPermission()) {
        s.toast('Kein Zugriff aufs Mikrofon: bitte in den Einstellungen des Telefons erlauben.', 'err');
        return;
      }
      final p = '${(await getTemporaryDirectory()).path}/vx_rec_${DateTime.now().millisecondsSinceEpoch}.m4a';
      if (room != null && confCh == c && confSpeak) unawaited(confMicSet(true));
      await r.start(const RecordConfig(encoder: AudioEncoder.aacLc, bitRate: 24000, sampleRate: 22050, numChannels: 1, autoGain: true, echoCancel: true, noiseSuppress: true), path: p);
      recCh = c;
      recDrop = false;
      recT0 = DateTime.now();
      recAt = DateTime.now().millisecondsSinceEpoch ~/ 1000;
      HapticFeedback.mediumImpact();
      _recTm = Timer.periodic(const Duration(milliseconds: 250), (_) {
        if (recS >= maxS) {
          recStop();
        } else {
          notifyListeners();
        }
      });
    } catch (e) {
      s.toast('Aufnahme nicht möglich: $e', 'err');
    } finally {
      recBusy = false;
      notifyListeners();
    }
  }

  void setDrop(bool d) {
    if (recDrop == d) return;
    recDrop = d;
    notifyListeners();
  }

  Future<void> recStop({bool? drop}) async {
    final c = recCh, t0 = recT0;
    if (c == null || t0 == null) return;
    _recTm?.cancel();
    recCh = null;
    recT0 = null;
    final d = drop ?? recDrop;
    if (room != null && confSpeak && !confOpen) unawaited(confMicSet(false));
    String? path;
    try {
      path = await _rec?.stop();
    } catch (_) {}
    notifyListeners();
    final ms = DateTime.now().difference(t0).inMilliseconds;
    if (path == null) return;
    final f = File(path);
    if (d) {
      s.toast('Aufnahme verworfen.', 'info');
    } else if (ms < 600) {
      s.toast(tap ? 'Zu kurz.' : 'Zu kurz: die Sprechtaste gedrückt halten, während du sprichst.', 'info');
    } else {
      final body = {'ch': c, 'data': base64Encode(await f.readAsBytes()), 'mime': 'audio/mp4', 'dur': ms > maxS * 1000 ? maxS * 1000 : ms, 'rec_at': recAt, 'inc': ji(s.inc?['id'])};
      unawaited(_send(body));
    }
    try {
      await f.delete();
    } catch (_) {}
    _next();
  }

  /// senden; ohne Verbindung merken und nachsenden (`vxq_<Konto>`)
  Future<void> _send(J body) async {
    pend++;
    notifyListeners();
    try {
      await s.api.call('incident/voice', body: body, timeout: const Duration(seconds: 60));
      unawaited(s.poll());
    } on ApiError catch (e) {
      if (e.offline) {
        final q = _sp?.getStringList('vxq_${s.acc.id}') ?? [];
        q.add(jsonEncode(body));
        await _sp?.setStringList('vxq_${s.acc.id}', q.length > 20 ? q.sublist(q.length - 20) : q);
        s.toast('Keine Verbindung: die Durchsage wird gesendet, sobald die Verbindung zurück ist.', 'info');
      } else {
        s.toast(e.message, 'err');
      }
    } finally {
      pend--;
      notifyListeners();
    }
  }

  int get queued => _sp?.getStringList('vxq_${s.acc.id}')?.length ?? 0;

  Future<void> _flush() async {
    final q = _sp?.getStringList('vxq_${s.acc.id}') ?? [];
    if (q.isEmpty || _flushing) return;
    _flushing = true;
    final rest = <String>[];
    var n = 0;
    for (final b in q) {
      try {
        await s.api.call('incident/voice', body: jm(jsonDecode(b)), timeout: const Duration(seconds: 60));
        n++;
      } on ApiError catch (e) {
        if (e.offline) {
          rest.add(b);
        } else {
          s.toast('Durchsage nicht nachgesendet: ${e.message}', 'err');
        }
      } catch (_) {}
    }
    await _sp?.setStringList('vxq_${s.acc.id}', rest);
    _flushing = false;
    if (n > 0) s.toast(n == 1 ? 'Eine Durchsage nachgesendet.' : '$n Durchsagen nachgesendet.');
  }

  // ---------- Live-Konferenz ----------
  /// leiser Ton beim Beitreten (steigend) bzw. Verlassen (fallend), eigenes und das der anderen (scripts/sounds/conf.py)
  Future<void> _tone(bool up) async {
    try {
      final pl = _tonePl ??= AudioPlayer();
      await pl.setVolume(0.7);
      await pl.setAsset('assets/sounds/conf_${up ? 'join' : 'leave'}.wav');
      unawaited(pl.play());
    } catch (_) {}
  }

  Future<void> confJoin(int c) async {
    if (confCh == c && room != null) return;
    if (room != null) await confLeave(sw: true);
    confCh = c;
    confState = 'connecting';
    confSince = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    _wasConf = true;
    notifyListeners();
    // Android: Vordergrunddienst mit Typ microphone, damit die Konferenz bei gesperrtem Bildschirm weiterläuft (service.dart)
    await prefs.setBool('vx_conf', true);
    unawaited(syncService());
    try {
      final j = await s.api.call('incident/voice/join', body: {'ch': c});
      if (confCh != c) return;
      confMay = jb(j['speak']);
      confMuted = jb(j['muted']);
      confSpeak = confMay && !confMuted;
      final r = room = lk.Room(roomOptions: const lk.RoomOptions(adaptiveStream: false, dynacast: false, defaultAudioCaptureOptions: lk.AudioCaptureOptions(echoCancellation: true, noiseSuppression: true, autoGainControl: true)));
      final ls = _ls = r.createListener();
      ls
        ..on<lk.ActiveSpeakersChangedEvent>((e) {
          speakers = e.speakers.map((p) => p.identity).toSet();
          notifyListeners();
        })
        ..on<lk.ParticipantConnectedEvent>((_) {
          if (confState == 'on') unawaited(_tone(true));
          _peers();
        })
        ..on<lk.ParticipantDisconnectedEvent>((_) {
          if (confState == 'on') unawaited(_tone(false));
          _peers();
        })
        ..on<lk.TrackPublishedEvent>((_) => _peers())
        ..on<lk.TrackUnpublishedEvent>((_) => _peers())
        ..on<lk.TrackMutedEvent>((_) => _peers())
        ..on<lk.TrackUnmutedEvent>((_) => _peers())
        ..on<lk.LocalTrackPublishedEvent>((_) => _peers())
        ..on<lk.LocalTrackUnpublishedEvent>((_) => _peers())
        // Einsatzleitung schaltet stumm bzw. hebt auf: Senderecht im Raum
        ..on<lk.ParticipantPermissionsUpdatedEvent>((e) {
          if (e.participant != r.localParticipant) return;
          final can = e.permissions.canPublish;
          confMuted = confMay && !can;
          confSpeak = can;
          if (!can) {
            confOpen = false;
            confMic = false;
          }
          _peers();
        })
        ..on<lk.RoomReconnectingEvent>((_) {
          confState = 'reconnecting';
          notifyListeners();
        })
        ..on<lk.RoomReconnectedEvent>((_) {
          confState = 'on';
          notifyListeners();
        })
        ..on<lk.RoomDisconnectedEvent>((_) {
          if (room == r) unawaited(confLeave());
        });
      await r.connect(js(j['url']), js(j['token']));
      if (room != r) {
        await r.disconnect();
        return;
      }
      try {
        await lk.AudioManager.instance.setSpeakerOutputPreferred(true);
      } catch (_) {}
      confState = 'on';
      unawaited(_tone(true));
      confMe = r.localParticipant?.identity ?? '';
      cc = c;
      _sp?.setInt('vx_cc', c);
      _peers();
      unawaited(s.api.call('incident/voice/conf', body: {'ch': c}).catchError((_) => <String, dynamic>{}));
    } catch (e) {
      s.toast('Konferenz: ${e is ApiError ? e.message : e}', 'err');
      await confLeave();
    }
    notifyListeners();
  }

  /// sw: Wechsel in eine andere Konferenz (nur der Ton des Beitritts)
  Future<void> confLeave({bool sw = false}) async {
    final r = room;
    room = null;
    final had = confCh != null;
    if (!sw && confState == 'on') unawaited(_tone(false));
    confCh = null;
    confState = '';
    confMic = false;
    confOpen = false;
    confMuted = false;
    confMe = '';
    speakers = {};
    mics = {};
    await _ls?.dispose();
    _ls = null;
    try {
      await r?.disconnect();
      await r?.dispose();
    } catch (_) {}
    if (had) unawaited(s.api.call('incident/voice/conf', body: {'ch': 0}).catchError((_) => <String, dynamic>{}));
    await prefs.setBool('vx_conf', false);
    unawaited(syncService());
    notifyListeners();
  }

  Future<void> confMicSet(bool on) async {
    final r = room;
    if (r == null || (on && !confSpeak)) return;
    try {
      await r.localParticipant?.setMicrophoneEnabled(on);
      confMic = on;
    } catch (e) {
      s.toast('Mikrofon: $e', 'err');
    }
    _peers();
  }

  /// wer das Mikrofon offen hat (eigenes nur, solange es offen ist)
  void _peers() {
    final r = room;
    if (r == null) return;
    mics = {
      ...r.remoteParticipants.values.where((p) => p.isMicrophoneEnabled()).map((p) => p.identity),
      if (confMic && r.localParticipant != null) r.localParticipant!.identity,
    };
    notifyListeners();
  }

  /// Stand vom Server (conf_list): stumm geschaltet; das Senderecht selbst ändert der Medienserver
  void _setMuted(bool m) {
    if (confMuted == m) return;
    confMuted = m;
    if (m) {
      confSpeak = false;
      confOpen = false;
      if (confMic) unawaited(confMicSet(false));
    } else if (confMay) {
      confSpeak = room?.localParticipant?.permissions.canPublish ?? true;
    }
    notifyListeners();
  }

  /// Einsatzleitung: Person in der Konferenz stumm schalten bzw. aufheben
  Future<void> mute(int c, String ident, bool on) async {
    try {
      await s.api.call('incident/voice/mute', body: {'ch': c, 'ident': ident, 'on': on ? 1 : 0});
      unawaited(s.poll());
    } on ApiError catch (e) {
      s.toast(e.message, 'err');
    }
  }

  bool micOpen(String ident) => mics.contains(ident);
  List<String> get talkers => speakers.where((i) => i != confMe).toList();
  String confName(String ident) => js((jl(v?['conf_list']).where((x) => js(x['ident']) == ident).firstOrNull ?? {'name': 'Jemand'})['name']);
  String confStateOf(J x) => speaking(js(x['ident']))
      ? 'spricht'
      : jb(x['muted'])
          ? 'stumm geschaltet'
          : micOpen(js(x['ident']))
              ? 'Mikrofon offen'
              : 'hört zu';

  void setOpen(bool on) {
    confOpen = on;
    confMicSet(on);
  }

  bool speaking(String ident) => speakers.contains(ident);

  @override
  void dispose() {
    _recTm?.cancel();
    unawaited(confLeave());
    _pl?.dispose();
    _tonePl?.dispose();
    _rec?.dispose();
    super.dispose();
  }
}

/// Sprechtaste: gedrückt halten (zur Seite wischen = verwerfen) oder, mit «Antippen» im Profil, antippen zum Starten und Stoppen.
/// id: welche Taste (nur die gedrückte leuchtet); live: Taste der Konferenz (grüner Rand)
class PttButton extends StatelessWidget {
  const PttButton({super.key, required this.vx, required this.ch, required this.id, this.size = 64, this.live = false});
  final VoiceStore vx;
  final int? Function() ch;
  final String id;
  final double size;
  final bool live;
  @override
  Widget build(BuildContext context) {
    final rec = vx.recording && vx.recBtn == id, drop = rec && vx.recDrop, open = live && vx.confMic && !rec;
    final b = Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: drop ? const Color(0xFF5C6B73) : (rec ? accRed : (open ? const Color(0xFF1F6B45) : appBlue)),
            border: Border.all(color: live ? okGreen : Colors.white, width: 2.5),
            boxShadow: [BoxShadow(color: rec && !drop ? accRed.withValues(alpha: .35) : Colors.black54, blurRadius: rec ? 18 : 8, spreadRadius: rec ? 6 : 0)]),
        child: Icon(Icons.mic, color: Colors.white, size: size * .45));
    void start() {
      final c = ch();
      if (c == null) return vx.s.toast('Kein Kanal zum Sprechen gewählt.', 'info');
      vx.recStart(c, btn: id);
    }

    final lbl = live ? 'Live in der Konferenz sprechen' : 'Sprechtaste Durchsage';
    if (vx.tap) {
      return Semantics(button: true, label: lbl, child: GestureDetector(onTap: () => vx.recording ? vx.recStop() : start(), child: b));
    }
    double x0 = 0;
    return Semantics(
        button: true,
        label: lbl,
        child: Listener(
            onPointerDown: (e) {
              x0 = e.position.dx;
              start();
            },
            onPointerMove: (e) => vx.setDrop((e.position.dx - x0).abs() > 80),
            onPointerUp: (_) => vx.recStop(),
            onPointerCancel: (_) => vx.recStop(drop: true),
            child: b));
  }
}

String _recTxt(VoiceStore vx) => vx.recording && vx.recBtn != 'conf' && vx.recBtn != 'mapc'
    ? (vx.recDrop ? 'Loslassen = verwerfen' : 'Aufnahme ${vx.recS} s / ${vx.maxS} s')
    : (vx.tap ? 'Antippen zum Sprechen' : 'Gedrückt halten zum Sprechen');
String _liveTxt(VoiceStore vx) => vx.recording && vx.recBtn == 'conf'
    ? (vx.recDrop ? 'Loslassen = verwerfen' : 'Du sprichst live · ${vx.recS} s')
    : (vx.tap ? 'Antippen = live sprechen' : 'Gedrückt halten = live sprechen');

Widget _pill(String t, {Color? bg, Color fg = Colors.white}) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
    decoration: BoxDecoration(color: bg ?? Colors.black.withValues(alpha: .7), borderRadius: BorderRadius.circular(10)),
    child: Text(t, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: fg, fontSize: 11, fontWeight: bg != null ? FontWeight.bold : FontWeight.normal)));

/// Knopf auf der Karte: der Konferenz beitreten (Kanal `cc`) bzw. sie verlassen
Widget _confBtn(VoiceStore vx) {
  final inConf = vx.room != null, cid = inConf ? vx.confCh : vx.cc, busy = vx.confState == 'connecting';
  final lbl = inConf ? 'Konferenz «${vx.chName(cid)}» verlassen' : 'Der Konferenz «${vx.chName(cid)}» beitreten';
  return Semantics(
      button: true,
      label: lbl,
      child: Material(
          color: const Color(0xE60F2430),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: inConf ? accRed : okGreen)),
          elevation: 4,
          child: InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: busy || cid == null ? null : () => inConf ? vx.confLeave() : vx.confJoin(cid),
              child: SizedBox(
                  width: double.infinity,
                  child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 3),
                      child: Column(mainAxisSize: MainAxisSize.min, children: [
                        Text(busy ? '⏳ Verbinde …' : (inConf ? '✕ Verlassen' : '🎧 Beitreten'), style: const TextStyle(color: Colors.white, fontSize: 12)),
                        if (!inConf || !vx.confSpeak)
                          Text(vx.chName(cid), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white70, fontSize: 10)),
                      ]))))));
}

/// Sprechtasten links unten über dem Hinweis zum Suchgebiet (vxFab): Durchsage mit Kanal darunter; in der Konferenz darüber die Live-Taste,
/// ist die Konferenz eingerichtet, zuoberst der Knopf zum Beitreten bzw. Verlassen
Widget? voiceFab(BuildContext c, VoiceStore vx) {
  final live = vx.room != null && vx.confState == 'on' && vx.confSpeak;
  final conf = vx.room != null || (vx.confAvail && vx.cc != null);
  if (!vx.on || (vx.speakChs.isEmpty && !live && !conf) || !vx.pttMap) return null;
  return SizedBox(
      width: 92,
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        if (conf) ...[_confBtn(vx), const SizedBox(height: 6)],
        if (live) ...[
          _pill('LIVE', bg: okGreen, fg: const Color(0xFF06210F)),
          const SizedBox(height: 3),
          PttButton(vx: vx, ch: () => vx.confSpeak ? vx.confCh : null, id: 'mapc', size: 50, live: true),
          const SizedBox(height: 3),
          _pill(vx.chName(vx.confCh)),
          const SizedBox(height: 10),
        ],
        if (vx.speakChs.isNotEmpty) ...[
          PttButton(vx: vx, ch: () => vx.spk, id: 'map', size: 64),
          const SizedBox(height: 4),
          if (vx.speakChs.length > 1)
            Container(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                decoration: BoxDecoration(color: Colors.black.withValues(alpha: .7), borderRadius: BorderRadius.circular(14)),
                child: DropdownButton<int>(
                    value: vx.spk,
                    isDense: true,
                    isExpanded: true,
                    underline: const SizedBox(),
                    dropdownColor: appBlue,
                    style: const TextStyle(color: Colors.white, fontSize: 12),
                    items: vx.speakChs.map((c) => DropdownMenuItem(value: ji(c['id']), child: Text(js(c['name']), overflow: TextOverflow.ellipsis))).toList(),
                    onChanged: vx.recording ? null : (v) => v == null ? null : vx.setSpk(v)))
          else
            _pill(vx.chName(vx.spk)),
        ],
      ]));
}

/// Leiste oben: laufende Wiedergabe, Aufnahme bzw. wer gerade live in der Konferenz spricht
Widget? voiceBar(BuildContext c, VoiceStore vx) {
  final it = vx.playing == null ? null : vx.items[vx.playing];
  final rec = vx.recording, tk = vx.room != null && !rec && it == null ? vx.talkers : <String>[];
  if (!vx.on || (it == null && !rec && tk.isEmpty)) return null;
  final live = rec && vx.room != null && vx.confCh == vx.recCh;
  return Material(
      color: const Color(0xF216323F),
      borderRadius: BorderRadius.circular(8),
      elevation: 6,
      child: Container(
          decoration: BoxDecoration(border: Border(left: BorderSide(color: rec ? accRed : (tk.isNotEmpty ? okGreen : const Color(0xFF1D7FD1)), width: 4))),
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          child: Row(children: [
            if (tk.isNotEmpty) _pill('LIVE', bg: okGreen, fg: const Color(0xFF06210F)) else Text(rec ? '●' : '🔊', style: TextStyle(fontSize: 20, color: rec ? accRed : Colors.white)),
            const SizedBox(width: 8),
            Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              Text(
                  rec
                      ? '${vx.recDrop ? 'Aufnahme wird verworfen' : 'Du sprichst'} · ${live ? 'live in der Konferenz' : 'Durchsage'} «${vx.chName(vx.recCh)}»'
                      : tk.isNotEmpty
                          ? '🔊 ${tk.map(vx.confName).join(', ')}'
                          : js(it!['name']),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
              Text(
                  rec
                      ? '${vx.recS} s · Loslassen = senden, zur Seite wischen = verwerfen'
                      : tk.isNotEmpty
                          ? '${tk.length > 1 ? 'sprechen' : 'spricht'} · Konferenz «${vx.chName(vx.confCh)}»'
                          : 'Durchsage «${vx.chName(ji(it!['ch']))}» · ${vxDur(jn(it['dur']))}${vx.queue.isNotEmpty ? ' · noch ${vx.queue.length}' : ''}',
                  style: const TextStyle(color: Colors.white70, fontSize: 12)),
            ])),
            if (it != null && !rec) IconButton(onPressed: vx.stop, icon: const Icon(Icons.stop, color: Colors.white), tooltip: 'Wiedergabe beenden'),
          ])));
}

/// Seitenfenster «Funk»: Register Live-Konferenz (Standard, sofern eingerichtet) und Durchsagen, je mit eigener Kanalwahl
class VoicePanel extends StatefulWidget {
  const VoicePanel({super.key, required this.s, required this.map});
  final IncStore s;
  final MapCtl map;
  @override
  State<VoicePanel> createState() => _VoicePanelState();
}

class _VoicePanelState extends State<VoicePanel> {
  String? _tab; // null = Konferenz, sofern eingerichtet
  final _sc = ScrollController();
  int _last = -1;

  VoiceStore get vx => widget.s.vx;

  @override
  void dispose() {
    _sc.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(listenable: Listenable.merge([widget.s, vx]), builder: (c, _) => _build(c));

  Widget _item(J it) {
    final id = ji(it['id'])!, mine = ji(it['uid']) == widget.s.uid, nw = !vx.heard.contains(id) && !mine, late = (ji(it['rec_at']) ?? 0) < (ji(it['at']) ?? 0) - 30;
    final col = hexColor(it['color'], jb(it['guest']) ? const Color(0xFF9B8CFF) : const Color(0xFF4B6F80));
    return Container(
        decoration: BoxDecoration(border: Border(bottom: BorderSide(color: Theme.of(context).dividerColor), left: nw ? const BorderSide(color: accRed, width: 3) : BorderSide.none)),
        padding: EdgeInsets.fromLTRB(nw ? 8 : 0, 8, 0, 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Container(width: 10, height: 10, decoration: BoxDecoration(color: col, shape: BoxShape.circle)),
            const SizedBox(width: 6),
            Expanded(child: Text(mine ? 'Du' : js(it['name']), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.bold))),
            Mut(fmtT(jn(it['at'])), size: 12),
          ]),
          Row(children: [
            IconButton.filledTonal(
                onPressed: jb(it['audio']) ? () => vx.playing == id ? vx.stop() : vx.play(id) : null,
                icon: Icon(vx.playing == id ? Icons.pause : Icons.play_arrow),
                tooltip: jb(it['audio']) ? 'Abspielen' : 'Aufnahme gelöscht'),
            const SizedBox(width: 6),
            Mut(vxDur(jn(it['dur']))),
          ]),
          if (js(it['tr']).isNotEmpty) Text('«${js(it['tr'])}»  ✨', style: const TextStyle(fontSize: 13)) else if (ji(it['trs']) == 1) const Mut('Transkript folgt …', size: 12),
          if (late) Mut('Aufgenommen ${fmtT(jn(it['rec_at']))} ohne Verbindung, gesendet ${fmtT(jn(it['at']))}', size: 11),
        ]));
  }

  /// Kanalwahl als Auswahlliste (statt einer langen Zeile mit Knöpfen)
  Widget _chSel(String label, int? val, List<DropdownMenuItem<int>> items, ValueChanged<int> on, {Widget? trailing}) => Padding(
      padding: const EdgeInsets.fromLTRB(12, 6, 12, 6),
      child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Expanded(
            child: InputDecorator(
                decoration: InputDecoration(labelText: label, isDense: true, border: const OutlineInputBorder(), contentPadding: const EdgeInsets.fromLTRB(10, 6, 6, 6)),
                child: DropdownButtonHideUnderline(child: DropdownButton<int>(value: val, isDense: true, isExpanded: true, items: items, onChanged: (v) => v == null ? null : on(v))))),
        if (trailing != null) ...[const SizedBox(width: 8), trailing],
      ]));

  Widget _conf(BuildContext c) {
    final s = widget.s, cid = vx.cc, all = jl(vx.v?['conf_list']), cl = all.where((x) => ji(x['ch']) == cid).toList();
    final inC = vx.confCh == cid && (vx.room != null || vx.confState == 'connecting'), lead = vx.v?['all_ch'] != null, tk = cl.where((x) => vx.speaking(js(x['ident']))).toList();
    final ids = cl.map((x) => js(x['ident'])).toSet();
    Widget who(J x) {
      final p = s.participants.where((q) => ji(q['user_id']) == ji(x['user_id'])).firstOrNull, r = s.roleOf(p?['role_id']), me0 = ji(x['user_id']) == s.uid, ident = js(x['ident']);
      final sk = vx.speaking(ident), mu = jb(x['muted']), st = inC ? vx.confStateOf(x) : (mu ? 'stumm geschaltet' : 'verbunden');
      return Container(
          decoration: BoxDecoration(color: sk ? okGreen.withValues(alpha: .12) : null, borderRadius: BorderRadius.circular(6)),
          child: ListTile(
              dense: true,
              contentPadding: const EdgeInsets.symmetric(horizontal: 4),
              leading: avatar({'name': js(x['name'])}, r: 16, border: sk ? okGreen : hexColor(r?['color'])),
              title: Text('${js(x['name'])}${me0 ? ' (ich)' : ''}'),
              subtitle: Text.rich(TextSpan(children: [
                TextSpan(text: '${r != null ? js(r['name']) : (x['guest_id'] != null ? 'Extern' : 'Ohne Spezialrolle')} · '),
                TextSpan(text: st, style: TextStyle(color: sk ? okGreen : (mu ? const Color(0xFFFFB4A6) : null))),
              ])),
              trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                if (sk) const Icon(Icons.volume_up, color: okGreen),
                if (lead && !me0)
                  TextButton(
                      onPressed: cid == null ? null : () => vx.mute(cid, ident, !mu),
                      style: mu ? TextButton.styleFrom(foregroundColor: const Color(0xFFFFB4A6)) : null,
                      child: Text(mu ? 'Aufheben' : 'Stumm')),
              ])));
    }

    final stt = inC
        ? (vx.confState == 'connecting'
            ? 'Verbinde …'
            : vx.confState == 'reconnecting'
                ? 'Verbindung unterbrochen, verbinde neu …'
                : tk.isNotEmpty
                    ? '🔊 ${tk.map((x) => js(x['ident']) == vx.confMe ? 'Du' : js(x['name'])).join(', ')} ${tk.length > 1 ? 'sprechen' : 'spricht'}'
                    : vx.confMuted
                        ? '🔇 Von der Einsatzleitung stumm geschaltet'
                        : vx.confSpeak
                            ? (vx.confMic ? 'Dein Mikrofon ist offen' : 'Du hörst zu · zum Sprechen die Live-Taste halten')
                            : 'Verbunden · in diesem Kanal nur zuhören')
        : '${cl.length} verbunden${vx.confCh != null ? ' · du bist in «${vx.chName(vx.confCh)}»' : ''}';
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      _chSel(
          'Kanal der Konferenz',
          cid,
          vx.chs.map((x) {
            final id = ji(x['id'])!, k = all.where((y) => ji(y['ch']) == id).length;
            return DropdownMenuItem(value: id, child: Text('${js(x['name'])}${vx.confCh == id ? ' ● verbunden' : k > 0 ? ' ($k)' : ''}', overflow: TextOverflow.ellipsis));
          }).toList(),
          vx.setCc),
      Padding(
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 6),
          child: Row(children: [
            if (inC && vx.confState == 'on') ...[const Chip2('LIVE', color: okGreen), const SizedBox(width: 6)],
            Expanded(child: Text(stt, style: TextStyle(fontWeight: tk.isNotEmpty || vx.confMuted ? FontWeight.bold : null, color: tk.isNotEmpty ? okGreen : (inC && vx.confMuted ? const Color(0xFFFFB4A6) : null)))),
          ])),
      const Divider(height: 1),
      Expanded(
          child: ListView(padding: const EdgeInsets.symmetric(horizontal: 8), children: [
        ...cl.map(who),
        if (cl.isEmpty) const Padding(padding: EdgeInsets.all(8), child: Mut('Noch niemand verbunden.')),
        ...s.participants.where((p) => p['left_at'] == null && p['mode'] != 'declined' && !ids.contains('u${p['user_id']}')).map((p) => Opacity(
            opacity: .55,
            child: ListTile(dense: true, contentPadding: const EdgeInsets.symmetric(horizontal: 4), leading: avatar(p, r: 16), title: Text(js(p['name'])), subtitle: const Text('nicht in der Konferenz · erhält Durchsagen')))),
      ])),
      Container(
          decoration: BoxDecoration(border: Border(top: BorderSide(color: Theme.of(c).dividerColor))),
          padding: const EdgeInsets.all(10),
          child: inC
              ? Row(children: [
                  if (vx.confSpeak) ...[
                    PttButton(vx: vx, ch: () => vx.confSpeak ? vx.confCh : null, id: 'conf', size: 56, live: true),
                    const SizedBox(width: 8),
                    Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                      Text(_liveTxt(vx), style: const TextStyle(fontWeight: FontWeight.bold)),
                      Row(children: [SizedBox(height: 32, child: Switch(value: vx.confOpen, onChanged: vx.setOpen)), const Text('Mikrofon offen')]),
                    ])),
                  ] else
                    const Spacer(),
                  OutlinedButton(onPressed: vx.confLeave, child: const Text('Verlassen')),
                ])
              : FilledButton(
                  onPressed: cid == null || vx.confState == 'connecting' ? null : () => vx.confJoin(cid),
                  child: Text(vx.confState == 'connecting' ? 'Verbinde …' : (vx.confCh != null ? 'Zu «${vx.chName(cid)}» wechseln' : '«${vx.chName(cid)}» beitreten')))),
    ]);
  }

  Widget _build(BuildContext context) {
    if (!vx.on) return Column(children: [PanelHead('Funk', onClose: () => widget.map.openPanel(null)), const Padding(padding: EdgeInsets.all(12), child: Mut('Kein Funk in diesem Einsatz.'))]);
    final tab = vx.confAvail ? (_tab ?? 'conf') : 'ann', its = vx.list(vx.ch), chs = vx.chs, cur = chs.where((c) => ji(c['id']) == vx.ch).firstOrNull, n = vx.unheard(null);
    final hi = its.isEmpty ? 0 : ji(its.last['id'])!;
    if (tab == 'ann' && hi != _last) {
      _last = hi;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_sc.hasClients) _sc.jumpTo(_sc.position.maxScrollExtent);
      });
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      PanelHead('🎙 Funk', onClose: () => widget.map.openPanel(null)),
      if (vx.confAvail)
        Padding(
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 2),
            child: SegmentedButton<String>(
                showSelectedIcon: false,
                segments: [
                  ButtonSegment(value: 'conf', label: Text(vx.room != null ? '● Live-Konferenz' : 'Live-Konferenz')),
                  ButtonSegment(value: 'ann', label: Text(n > 0 ? 'Durchsagen ($n)' : 'Durchsagen')),
                ],
                selected: {tab},
                onSelectionChanged: (x) => setState(() => _tab = x.first))),
      if (tab == 'conf')
        Expanded(child: _conf(context))
      else ...[
        _chSel(
            'Kanal der Durchsagen',
            vx.ch,
            chs.map((c) {
              final id = ji(c['id'])!, u = vx.unheard(id);
              return DropdownMenuItem(value: id, child: Text('${js(c['name'])}${u > 0 ? ' ($u neu)' : ''}${jb(c['speak']) ? '' : ' – nur hören'}', overflow: TextOverflow.ellipsis));
            }).toList(),
            vx.setCh,
            trailing: Column(mainAxisSize: MainAxisSize.min, children: [SizedBox(height: 30, child: Switch(value: vx.listen, onChanged: vx.setListen)), const Mut('Mithören', size: 11)])),
        const Divider(height: 1),
        Expanded(
            child: its.isEmpty
                ? const Padding(padding: EdgeInsets.all(12), child: Mut('Noch keine Durchsagen in diesem Kanal.'))
                : ListView(controller: _sc, padding: const EdgeInsets.symmetric(horizontal: 12), children: [
                    ...its.map(_item),
                    if (vx.queued > 0) Padding(padding: const EdgeInsets.all(8), child: Mut('⏳ ${vx.queued == 1 ? 'Eine Durchsage wartet' : '${vx.queued} Durchsagen warten'} auf die Verbindung.')),
                  ])),
        Container(
            decoration: BoxDecoration(border: Border(top: BorderSide(color: Theme.of(context).dividerColor))),
            padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
            child: cur != null && jb(cur['speak'])
                ? Row(children: [
                    PttButton(vx: vx, ch: () => vx.ch, id: 'ann', size: 60),
                    const SizedBox(width: 12),
                    Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(_recTxt(vx), style: const TextStyle(fontWeight: FontWeight.bold)),
                      Mut('Durchsage «${js(cur['name'])}» · höchstens ${vx.maxS} s${vx.pend > 0 ? ' · wird gesendet …' : ''}${vx.confCh == vx.ch ? ' · geht auch live in die Konferenz' : ''}', size: 12),
                    ])),
                  ])
                : Mut(cur == null ? 'Kein Kanal.' : 'In diesem Kanal darfst du nur zuhören.')),
      ],
      CheckboxListTile(
          dense: true,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text('Sprechtaste auf der Karte'),
          value: vx.pttMap,
          onChanged: (v) => vx.setPref('vx_ptt', v == true)),
    ]);
  }
}

/// Register «Leitung»: Kanäle des Einsatzes und Links für Externe (funk.html?k=)
List<Widget> voiceLeadCards(BuildContext c, IncStore s) {
  final v = s.vx.v;
  if (v == null || v['all_ch'] is! List) return [];
  final all = jl(v['all_ch']), on = all.where((x) => jb(x['on'])).toList(), links = jl(v['guest_links']);
  String url(String t) => '${s.api.base}funk.html?k=$t';
  Future<void> share(String t, String label) async {
    try {
      await SharePlus.instance.share(ShareParams(text: 'Funk «$label»: ${url(t)}', subject: 'Funk für Externe'));
    } catch (_) {
      await Clipboard.setData(ClipboardData(text: url(t)));
      s.toast('Link kopiert.');
    }
  }

  return [
    MCard(title: '🎙 Funk & Konferenz', buttons: [
      smallBtn('Kanäle anpassen', () async {
        final sel = on.map((x) => ji(x['id'])!).toSet();
        final ok = await formDlg(
            c,
            'Funkkanäle dieses Einsatzes',
            (d, ss) => Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  const Mut('Welche Kanäle es in diesem Einsatz gibt. Wer sie hört und darin spricht, legt die Verwaltung fest.'),
                  ...all.map((x) => CheckboxListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      controlAffinity: ListTileControlAffinity.leading,
                      title: Text(js(x['name'])),
                      value: sel.contains(ji(x['id'])),
                      onChanged: (b) => ss(() => b == true ? sel.add(ji(x['id'])!) : sel.remove(ji(x['id']))))),
                ]));
        if (ok) await s.act('incident/voice/channels', {'ids': sel.toList()});
      }),
      if (jb(v['inc_ch'])) smallBtn('Vorgabe des Einsatztyps', () => s.act('incident/voice/channels', {'ids': null})),
    ], children: [
      Mkv([
        ('Kanäle', '${on.map((x) => js(x['name'])).join(', ')}${jb(v['inc_ch']) ? ' (angepasst)' : ''}'),
        ('Konferenz', jb(v['conf']) ? '${jl(v['conf_list']).length} verbunden' : 'nicht eingerichtet'),
        ('Aufbewahrung', _keepTxt[js(v['keep'])] ?? js(v['keep'])),
      ]),
    ]),
    if (jb(v['guests']))
      MCard(title: 'Funk für Externe (Link)', buttons: [
        smallBtn('+ Link für Externe', () async {
          final lb = TextEditingController();
          int? chSel = ji(on.firstOrNull?['id']);
          bool speak = false;
          final ok = await formDlg(
              c,
              'Link für Externe',
              (d, ss) => Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    TextField(controller: lb, maxLength: 80, decoration: const InputDecoration(labelText: 'Bezeichnung (Organisation)', hintText: 'z. B. Kapo Bern – Einsatzzentrale')),
                    DropdownButtonFormField<int>(
                        initialValue: chSel,
                        decoration: const InputDecoration(labelText: 'Kanal'),
                        items: on.map((x) => DropdownMenuItem(value: ji(x['id']), child: Text(js(x['name'])))).toList(),
                        onChanged: (x) => ss(() => chSel = x)),
                    if (jb(v['guest_speak']))
                      CheckboxListTile(contentPadding: EdgeInsets.zero, controlAffinity: ListTileControlAffinity.leading, title: const Text('darf auch sprechen'), value: speak, onChanged: (b) => ss(() => speak = b == true))
                    else
                      const Mut('Externe hören nur zu (Einstellung des Einsatztyps).'),
                  ]),
              ok: 'Erstellen',
              valid: () => lb.text.trim().isNotEmpty && chSel != null);
          if (!ok) return;
          final r = await s.act('incident/voice/guest/save', {'label': lb.text.trim(), 'ch': chSel, 'speak': speak ? 1 : 0});
          if (r != null) await share(js(r['token']), lb.text.trim());
        }, primary: true),
      ], children: [
        Mut('Externe ohne App (z. B. Polizei, Feuerwehr) hören über einen Link im Browser mit, ohne Anmeldung${jb(v['guest_speak']) ? ' (wahlweise auch sprechen)' : ''}. Ein Link gilt für einen Kanal, bis zum Einsatzende oder bis du ihn entfernst. Keine Karte, keine Standorte.'),
        ...links.map((g) => ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            title: Text(js(g['label']), style: const TextStyle(fontWeight: FontWeight.bold)),
            subtitle: Text('Kanal «${s.vx.chName(ji(g['ch']))}» · ${jb(g['speak']) ? 'hören und sprechen' : 'nur hören'} · ${g['opened_at'] != null ? 'geöffnet ${fmtT(jn(g['opened_at']))}' : 'noch nicht geöffnet'}'),
            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
              IconButton(onPressed: () => share(js(g['token']), js(g['label'])), icon: const Icon(Icons.share), tooltip: 'Link teilen'),
              IconButton(
                  onPressed: () async {
                    if (await confirmDlg(c, 'Link für «${js(g['label'])}» entfernen? Er funktioniert danach nicht mehr.', ok: 'Entfernen', danger: true)) {
                      await s.act('incident/voice/guest/remove', {'id': ji(g['id'])});
                    }
                  },
                  icon: const Icon(Icons.close),
                  tooltip: 'Link entfernen'),
            ]))),
      ]),
  ];
}

/// Profil, Karte «Funk»: Antippen statt Halten, Mithören, Sprechtaste auf der Karte (pro Gerät)
class VoicePrefs extends StatelessWidget {
  const VoicePrefs({super.key, required this.s});
  final IncStore s;
  @override
  Widget build(BuildContext context) => ListenableBuilder(
      listenable: s.vx,
      builder: (c, _) => MCard(title: 'Funk', children: [
            SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Sprechtaste antippen statt gedrückt halten'),
                subtitle: const Text('Z. B. mit Handschuhen: einmal tippen = Aufnahme starten, nochmals tippen = senden'),
                value: s.vx.tap,
                onChanged: (v) => s.vx.setPref('vx_tap', v)),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('Mithören'), subtitle: const Text('Neue Durchsagen sofort abspielen, solange die Einsatz-App offen ist'), value: s.vx.listen, onChanged: s.vx.setListen),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('Sprechtaste auf der Karte'), value: s.vx.pttMap, onChanged: (v) => s.vx.setPref('vx_ptt', v)),
            const Mut('Gilt für dieses Gerät. Für Aufnahmen und die Konferenz braucht die App den Zugriff aufs Mikrofon.', size: 12),
          ]));
}

/// ▶ bei Durchsagen im Einsatzlog (Menü)
Widget? voiceLogBtn(IncStore s, J l) {
  if (js(l['kind']) != 'voice' || !jb(l['audio']) || ji(l['voice']) == null) return null;
  final id = ji(l['voice'])!;
  return IconButton(
      visualDensity: VisualDensity.compact,
      onPressed: () => s.vx.playing == id ? s.vx.stop() : s.vx.play(id),
      icon: Icon(s.vx.playing == id ? Icons.pause : Icons.play_arrow, size: 20),
      tooltip: 'Durchsage abspielen');
}
