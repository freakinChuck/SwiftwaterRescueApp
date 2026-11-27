// Notruf (SOS, Issue #240) in der nativen Einsatz-App, Gegenstück zu web/sos.js.
// Knopf «SOS» auf der Karte (nur mit Teilnahme, Funktion sos), Auslösen durch langes Drücken (sos_cfg.hold s), danach Abbruch-Countdown
// (sos_cfg.countdown s). st.sos = laufende Notrufe des Einsatzes ({id, user_id, name, at, lat, lon, ack_name, mine, to_me}).
// Die Alarmierung (Vollbild x:<id>, kritisch) macht IncStore._sosPoll, quittiert wird über ackAlarm (incident/sos/ack).
import 'dart:async';
import 'package:flutter/material.dart' hide Text;
import 'package:flutter/services.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import 'ctx.dart';
import 'store.dart';
import 'util.dart';
import '../i18n.dart';

const sosRed = Color(0xFFE5383B);

List<J> sosList(IncStore s) => s.feat('sos') ? jl(s.st?['sos']) : [];
J? sosCfg(IncStore s) => s.st?['sos_cfg'] is Map ? jm(s.st!['sos_cfg']) : null;
J? sosMine(IncStore s) => sosList(s).where((x) => jb(x['mine'])).firstOrNull;
bool sosMay(IncStore s) => s.inc != null && s.joined && sosCfg(s) != null && s.feat('sos');
J? sosById(IncStore s, int? id) => sosList(s).where((x) => ji(x['id']) == id).firstOrNull;
LatLng? sosPos(J x) => jd(x['lat']) == null ? null : LatLng(jd(x['lat'])!, jd(x['lon'])!);

String sosAgo(IncStore s, J x) {
  final m = ((s.now - (jn(x['at']) ?? s.now)) / 60).floor();
  return m < 1 ? 'gerade eben' : 'vor $m min';
}

/// Distanz vom eigenen Standort zur auslösenden Person, z. B. «350 m» bzw. «1.2 km»
String sosDist(IncStore s, J x) {
  final a = s.selfAt, b = sosPos(x);
  if (a == null || b == null) return '';
  final d = geoDist(a, b);
  return d < 1000 ? '${(d / 10).round() * 10} m' : '${(d / 1000).toStringAsFixed(1)} km';
}

/// Knopf «SOS»: langes Drücken (Fortschrittsring), danach Countdown mit Abbruch; läuft der eigene Notruf, beendet ein Tippen ihn (mit Rückfrage)
class SosButton extends StatefulWidget {
  const SosButton({super.key, required this.s});
  final IncStore s;
  @override
  State<SosButton> createState() => _SosButtonState();
}

class _SosButtonState extends State<SosButton> with SingleTickerProviderStateMixin {
  late final AnimationController _ac = AnimationController(vsync: this);
  bool _busy = false;

  IncStore get s => widget.s;

  @override
  void initState() {
    super.initState();
    _ac.addStatusListener((st) {
      if (st == AnimationStatus.completed) {
        _ac.reset();
        HapticFeedback.heavyImpact();
        _countdown();
      }
    });
  }

  @override
  void dispose() {
    _ac.dispose();
    super.dispose();
  }

  void _down() {
    if (sosMine(s) != null || _busy) return;
    final h = ji(sosCfg(s)?['hold']) ?? 2;
    _ac.duration = Duration(milliseconds: h * 1000);
    HapticFeedback.selectionClick();
    _ac.forward(from: 0);
  }

  void _up() {
    if (_ac.isAnimating) {
      _ac.reset();
      s.toast('Zum Auslösen gedrückt halten', 'info');
    }
  }

  Future<void> _countdown() async {
    final n = ji(sosCfg(s)?['countdown']) ?? 5;
    final go = n <= 0 ? true : await showDialog<bool>(context: context, barrierDismissible: false, builder: (_) => _SosCountdown(n: n));
    if (go != true || !mounted) return;
    setState(() => _busy = true);
    await s.sosSend();
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _end(J x) async {
    if (!await confirmDlg(context, 'Eigenen Notruf beenden?\n\nAlle Alarmierten erhalten eine Entwarnung.', ok: 'Beenden')) return;
    await s.sosEnd(ji(x['id']));
  }

  @override
  Widget build(BuildContext context) {
    final mine = sosMine(s);
    return Tooltip(
        message: trn(mine != null ? 'Eigenen Notruf beenden' : 'Notruf (SOS): gedrückt halten'),
        child: GestureDetector(
            onTapDown: (_) => _down(),
            onTapUp: (_) => _up(),
            onTapCancel: _up,
            onTap: mine != null ? () => _end(mine) : null,
            child: SizedBox(
                width: 52,
                height: 52,
                child: Stack(alignment: Alignment.center, children: [
                  Material(
                      shape: const CircleBorder(),
                      elevation: 4,
                      color: mine != null ? Colors.white : sosRed,
                      child: SizedBox(
                          width: 48,
                          height: 48,
                          child: Center(
                              child: Text(mine != null ? 'ENDE' : 'SOS',
                                  style: TextStyle(color: mine != null ? sosRed : Colors.white, fontWeight: FontWeight.w900, fontSize: mine != null ? 12 : 15))))),
                  AnimatedBuilder(
                      animation: _ac,
                      builder: (_, _) => _ac.value == 0
                          ? const SizedBox.shrink()
                          : SizedBox(width: 52, height: 52, child: CircularProgressIndicator(value: _ac.value, strokeWidth: 4, color: Colors.white, backgroundColor: Colors.black26))),
                ]))));
  }
}

class _SosCountdown extends StatefulWidget {
  const _SosCountdown({required this.n});
  final int n;
  @override
  State<_SosCountdown> createState() => _SosCountdownState();
}

class _SosCountdownState extends State<_SosCountdown> {
  late int _n = widget.n;
  Timer? _t;

  @override
  void initState() {
    super.initState();
    _t = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      HapticFeedback.mediumImpact();
      if (--_n <= 0) {
        _t?.cancel();
        Navigator.pop(context, true);
      } else {
        setState(() {});
      }
    });
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
          backgroundColor: sosRed,
          title: const Text('Notruf wird gesendet', style: TextStyle(color: Colors.white)),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            Text('$_n', style: const TextStyle(color: Colors.white, fontSize: 64, fontWeight: FontWeight.w900)),
            const Text('Einsatzleitung und Einsatzkräfte in der Nähe werden alarmiert.', textAlign: TextAlign.center, style: TextStyle(color: Colors.white)),
          ]),
          actions: [
            TextButton(style: TextButton.styleFrom(foregroundColor: Colors.white), onPressed: () => Navigator.pop(context, true), child: const Text('Sofort senden')),
            FilledButton(style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: sosRed), onPressed: () => Navigator.pop(context, false), child: const Text('Abbrechen')),
          ]);
}

/// rote Leiste oben: je laufendem Notruf «SOS – Name – vor 1 min» mit Zeigen, 🧭, Quittieren, Beenden
Widget? sosBar(BuildContext c, IncStore s, MapCtl map) {
  final l = sosList(s);
  if (l.isEmpty) return null;
  return Column(mainAxisSize: MainAxisSize.min, children: [
    for (final x in l)
      Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Material(
              color: sosRed,
              elevation: 4,
              borderRadius: BorderRadius.circular(10),
              child: Padding(
                  padding: const EdgeInsets.fromLTRB(10, 4, 4, 4),
                  child: Row(children: [
                    Expanded(
                        child: Text(
                            jb(x['mine'])
                                ? 'SOS – dein Notruf läuft${x['ack_name'] != null ? ' · gesehen von ${js(x['ack_name'])}' : ''}'
                                : 'SOS – ${js(x['name'])} – ${sosAgo(s, x)}${sosDist(s, x).isNotEmpty ? ' · ${sosDist(s, x)}' : ''}${x['ack_name'] != null ? ' · quittiert' : ''}',
                            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700))),
                    if (sosPos(x) != null && !jb(x['mine'])) ...[
                      IconButton(tooltip: trn('Zeigen'), color: Colors.white, visualDensity: VisualDensity.compact, onPressed: () => map.centerOn(sosPos(x)!, zoom: 16), icon: const Icon(Icons.my_location)),
                      if (s.feat('nav'))
                        IconButton(
                            tooltip: trn('Navigieren'),
                            visualDensity: VisualDensity.compact,
                            onPressed: () => navGo(s, jd(x['lat'])!, jd(x['lon'])!, 'SOS ${js(x['name'])}'),
                            icon: const Text('🧭', style: TextStyle(fontSize: 18))),
                    ],
                    if (!jb(x['mine']) && x['ack_by'] == null)
                      TextButton(style: TextButton.styleFrom(foregroundColor: Colors.white), onPressed: () => s.sosAck(ji(x['id'])), child: const Text('Quittieren')),
                    if (jb(x['mine']) || s.canManage)
                      TextButton(
                          style: TextButton.styleFrom(foregroundColor: Colors.white),
                          onPressed: () async {
                            if (await confirmDlg(c, jb(x['mine']) ? 'Eigenen Notruf beenden?' : 'Notruf von ${js(x['name'])} beenden?', ok: 'Beenden')) await s.sosEnd(ji(x['id']));
                          },
                          child: const Text('Beenden')),
                  ])))),
  ]);
}

/// blinkender roter Marker an der Position der auslösenden Person
Widget? sosLayer(IncStore s) {
  final l = sosList(s).where((x) => sosPos(x) != null).toList();
  if (l.isEmpty) return null;
  return MarkerLayer(markers: [
    for (final x in l)
      Marker(
          point: sosPos(x)!,
          width: 120,
          height: 76,
          child: IgnorePointer(
              child: Column(children: [
            const _SosPulse(),
            Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(color: sosRed, borderRadius: BorderRadius.circular(6)),
                child: Text('SOS ${js(x['name'])}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700))),
          ]))),
  ]);
}

class _SosPulse extends StatefulWidget {
  const _SosPulse();
  @override
  State<_SosPulse> createState() => _SosPulseState();
}

class _SosPulseState extends State<_SosPulse> with SingleTickerProviderStateMixin {
  late final AnimationController _ac = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..repeat(reverse: true);
  @override
  void dispose() {
    _ac.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
      animation: _ac,
      builder: (_, _) => Container(
          width: 52,
          height: 52,
          decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: sosRed.withValues(alpha: .25 + .35 * _ac.value),
              border: Border.all(color: sosRed, width: 3)),
          child: const Center(child: Text('SOS', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 13)))));
}
