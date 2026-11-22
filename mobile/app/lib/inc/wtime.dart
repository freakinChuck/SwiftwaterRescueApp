// Wasserzeit in der nativen Einsatz-App (Issue #241, Gegenstück zu wtCard, wtLine, wtBtn, wtMk und wtSelfSync in index.html).
// st.wtime (Server wtState): run = laufende Wasserzeiten aller Personen, sum = beendete je Person, Temperatur des Einsatzes, Richtwerte lim je Kälteschutz.
// Warnungen kommen als alerts aus incident/state (IncStore.poll, Überwachung in monitor.dart), den Abstand zur Gewässerachse (wd) meldet _sendPos.
import 'dart:async';
import 'dart:math' as math;
import 'package:flutter/material.dart' hide Text;
import 'ctx.dart';
import 'store.dart';
import 'util.dart';
import '../i18n.dart';

J? wtState(IncStore s) => s.inc != null && s.feat('wtime') && s.st?['wtime'] is Map ? jm(s.st!['wtime']) : null;
List<J> wtRuns(IncStore s) => jl(wtState(s)?['run']);
J? wtOf(IncStore s, dynamic uid) => uid == null ? null : wtRuns(s).where((r) => ji(r['user_id']) == ji(uid)).firstOrNull;
double wtNow(IncStore s) => DateTime.now().millisecondsSinceEpoch / 1000 + s.wtSkew;
double wtEl(IncStore s, J r) => math.max(0, wtNow(s) - (jn(r['start_at']) ?? 0));

/// 0 = läuft, 1 = Vorwarnung (warn %), 2 = Richtwert erreicht
int wtStage(IncStore s, J r) {
  final p = wtEl(s, r) / math.max(1, ji(r['lim_s']) ?? 1) * 100;
  return p >= 100 ? 2 : (p >= (ji(wtState(s)?['warn']) ?? 75) ? 1 : 0);
}

String wtClock(num sec) {
  final t = sec.floor(), h = t ~/ 3600, m = t % 3600 ~/ 60, x = (t % 60).toString().padLeft(2, '0');
  return h > 0 ? '$h:${m.toString().padLeft(2, '0')}:$x' : '$m:$x';
}

String wtTxt(IncStore s, J r) => '${wtClock(wtEl(s, r))} / ${((ji(r['lim_s']) ?? 0) / 60).round()} min';
const _bg = [Color(0xFF1D6FA5), Color(0xFFFFD23F), Color(0xFFE0242B)], _fg = [Colors.white, Color(0xFF231400), Colors.white];

/// Zeit im Wasser, läuft sekundengenau; short = nur Minuten (unter dem Marker)
class WtChip extends StatefulWidget {
  const WtChip(this.s, this.r, {super.key, this.short = false, this.extra = 0});
  final IncStore s;
  final J r;
  final bool short;
  final int extra; // weitere Personen im Wasser (Gruppe auf der Karte)
  @override
  State<WtChip> createState() => _WtChipState();
}

class _WtChipState extends State<WtChip> {
  Timer? _t;
  @override
  void initState() {
    super.initState();
    _t = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.s, r = widget.r, g = wtStage(s, r);
    final t = widget.short ? '🌊${(wtEl(s, r) / 60).floor()}′${widget.extra > 0 ? ' (${widget.extra + 1})' : ''}' : '🌊 ${wtTxt(s, r)}';
    return Container(
        padding: EdgeInsets.symmetric(horizontal: widget.short ? 4 : 6, vertical: 1),
        decoration: BoxDecoration(color: _bg[g], borderRadius: BorderRadius.circular(4), boxShadow: widget.short ? const [BoxShadow(color: Colors.black54, blurRadius: 3)] : null),
        child: Text(t,
            style: TextStyle(color: _fg[g], fontWeight: FontWeight.bold, fontSize: widget.short ? 11 : 13, fontFeatures: const [FontFeature.tabularFigures()])));
  }
}

/// Zeile bei der Person (Fenster «Einsatzkräfte», Personeninfo): laufende Zeit mit Kälteschutz, sonst die bisherige Summe
Widget? wtLine(IncStore s, J p) {
  final w = wtState(s);
  if (w == null || p['ext'] != null || p['jk'] != null) return null;
  final r = wtOf(s, p['user_id']), sum = ji(jm(w['sum'])['${p['user_id']}']);
  if (r != null) {
    return Padding(
        padding: const EdgeInsets.only(top: 2),
        child: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [WtChip(s, r), Mut('${js(r['suit_name'])}${jb(r['auto']) ? ' · automatisch' : ''}', size: 12)]));
  }
  return sum != null && sum > 0 ? Mut('🌊 Wasserzeit bisher ${(sum / 60).round()} min', size: 12) : null;
}

/// Knopf der Einsatzleitung: für andere starten bzw. beenden
Widget? wtBtn(BuildContext c, IncStore s, J p) {
  if (wtState(s) == null || p['ext'] != null || p['jk'] != null || !s.canManage || ji(p['user_id']) == s.uid || p['left_at'] != null || p['mode'] == 'declined') return null;
  return wtOf(s, p['user_id']) != null ? smallBtn('An Land', () => wtStopFor(c, s, p)) : smallBtn('🌊 Ins Wasser', () => wtStartFor(c, s, p));
}

Future<void> wtStopFor(BuildContext c, IncStore s, J p) async {
  if (await confirmDlg(c, 'Wasserzeit von ${js(p['name'])} beenden?', ok: 'Beenden')) await s.act('incident/wtime/stop', {'user_id': p['user_id']});
}

Future<void> wtStartFor(BuildContext c, IncStore s, J p) async {
  final k = await _suitDlg(c, s, 'Wasserzeit für ${js(p['name'])}', null, ok: 'Starten');
  if (k != null) await s.act('incident/wtime/start', {'user_id': p['user_id'], 'suit': k});
}

Future<String?> _suitDlg(BuildContext c, IncStore s, String title, String? cur, {String ok = 'OK'}) {
  final w = wtState(s) ?? {}, lim = jm(w['lim']);
  var v = cur ?? js(w['suit']);
  return showDialog<String>(
      context: c,
      builder: (d) => StatefulBuilder(
          builder: (d, set) => AlertDialog(
                  title: Text(title),
                  content: RadioGroup<String>(
                      groupValue: v,
                      onChanged: (n) => set(() => v = n ?? v),
                      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                        for (final x in jl(w['suits']))
                          RadioListTile<String>(
                              dense: true,
                              contentPadding: EdgeInsets.zero,
                              value: js(x['k']),
                              title: Text('${js(x['name'])} (${((ji(lim[x['k']]) ?? 0) / 60).round()} min)'),
                              subtitle: Text(js(x['hint']), style: const TextStyle(fontSize: 12))),
                      ])),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(d), child: const Text('Abbrechen')),
                    FilledButton(onPressed: () => Navigator.pop(d, v), child: Text(ok)),
                  ])));
}

/// Chip unter dem Marker einer Person bzw. Gruppe (die am längsten im Wasser)
Widget? wtMk(IncStore s, List<J> items) {
  final rs = items.where((p) => p['ext'] == null && p['jk'] == null).map((p) => wtOf(s, p['user_id'])).whereType<J>().toList();
  if (rs.isEmpty) return null;
  rs.sort((a, b) => wtStage(s, b).compareTo(wtStage(s, a)) != 0 ? wtStage(s, b).compareTo(wtStage(s, a)) : (jn(a['start_at']) ?? 0).compareTo(jn(b['start_at']) ?? 0));
  return IgnorePointer(child: WtChip(s, rs.first, short: true, extra: rs.length - 1));
}

/// Marker mit Chip darunter (Stack ohne Zuschneiden, der Marker bleibt 40 × 40)
Widget wtWrap(Widget mk, Widget? chip) => chip == null
    ? mk
    : Stack(clipBehavior: Clip.none, alignment: Alignment.center, children: [mk, Positioned(top: 38, left: -20, right: -20, child: Center(child: chip))]);

/// eigene laufende Wasserzeit oben auf der Karte (wtSelfSync), mit «An Land»
Widget? wtSelfChip(IncStore s) {
  final r = s.joined ? wtOf(s, s.uid) : null;
  if (r == null) return null;
  return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Material(
          color: const Color(0xFF0D2A3A),
          elevation: 3,
          borderRadius: BorderRadius.circular(8),
          child: Padding(
              padding: const EdgeInsets.fromLTRB(6, 4, 4, 4),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                WtChip(s, r),
                const SizedBox(width: 6),
                smallBtn('An Land', () => s.act('incident/wtime/stop', {})),
              ]))));
}

/// Karte «Wasserzeit» im Register «Einsatz» (wtCard): Temperatur, Richtwerte, eigener Start/Stopp mit Kälteschutz, wer im Wasser ist;
/// die Einsatzleitung setzt die Wassertemperatur von Hand
Widget? wtCard(BuildContext c, IncStore s) {
  final w = wtState(s);
  if (w == null || (!s.joined && !s.canManage)) return null;
  final mine = s.joined ? wtOf(s, s.uid) : null, run = wtRuns(s), lim = jm(w['lim']), src = js(w['temp_src']), t = jn(w['temp']);
  final tt = t != null ? '${t.toString().replaceAll(RegExp(r'\.0$'), '')} °C · ${src == 'manual' ? tr('von Hand gesetzt') : 'BAFU ${js(w['temp_txt'])}'}' : tr('unbekannt, es gilt der Richtwert der kältesten Stufe');
  final suits = jl(w['suits']), my = mine != null ? js(mine['suit']) : js(w['suit']);
  String suitName(String k) => js(suits.where((x) => x['k'] == k).firstOrNull?['name']);
  return MCard(title: 'Wasserzeit', children: [
    Mkv([
      (tr('Wassertemperatur'), tt),
      (tr('Richtwerte'), suits.map((x) => '${tr(js(x['name']))} ${((ji(lim[x['k']]) ?? 0) / 60).round()} min').join(' · ')),
      if (s.joined) (tr('Mein Kälteschutz'), tr(suitName(my))),
    ]),
    if (mine != null)
      Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            WtChip(s, mine),
            Mut('seit ${fmtT(jn(mine['start_at']))}${jb(mine['auto']) ? ' (automatisch erkannt)' : ''}'),
          ])),
    if (run.isNotEmpty) ...[
      Padding(padding: const EdgeInsets.only(top: 8, bottom: 2), child: Text('Im Wasser (${run.length})', style: const TextStyle(fontWeight: FontWeight.bold))),
      for (final r in run)
        Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Row(children: [
              Expanded(
                  child: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                Text(js(r['name']), style: const TextStyle(fontWeight: FontWeight.w600)),
                WtChip(s, r),
                Mut(js(r['suit_name']), size: 12),
              ])),
              if (s.canManage && ji(r['user_id']) != s.uid) smallBtn('An Land', () => wtStopFor(c, s, {'user_id': r['user_id'], 'name': r['name']})),
            ])),
    ],
    Padding(
        padding: const EdgeInsets.only(top: 6),
        child: Mut(
            '${jb(w['auto']) ? 'Die App erkennt den Aufenthalt im Wasser auch selbst (mit geladenem Gewässernetz, nicht in einem Fahrzeug). ' : ''}Warnung bei ${ji(w['warn']) ?? 75} % und beim Erreichen des Richtwerts an dich und die Einsatzleitung.',
            size: 12)),
  ], buttons: [
    if (s.joined && mine == null)
      FilledButton(
          onPressed: () async {
            final k = await _suitDlg(c, s, 'Ich gehe ins Wasser', my, ok: 'Starten');
            if (k != null) await s.act('incident/wtime/start', {'suit': k});
          },
          child: const Text('🌊 Ich gehe ins Wasser')),
    if (mine != null) FilledButton(onPressed: () => s.act('incident/wtime/stop', {}), child: const Text('Zurück an Land')),
    if (mine != null)
      OutlinedButton(
          onPressed: () async {
            final k = await _suitDlg(c, s, 'Kälteschutz ändern', my);
            if (k != null && k != my) await s.act('incident/wtime/start', {'suit': k});
          },
          child: const Text('Kälteschutz ändern')),
    if (jb(w['can_temp']))
      OutlinedButton(
          onPressed: () async {
            final v = await inputDlg(c, 'Wassertemperatur von Hand (°C)',
                value: src == 'manual' && t != null ? '$t' : '', type: const TextInputType.numberWithOptions(decimal: true, signed: true), ok: 'Setzen', hint: src == 'bafu' && t != null ? '$t' : null);
            if (v == null) return;
            final n = double.tryParse(v.trim().replaceAll(',', '.'));
            if (v.trim().isNotEmpty && n == null) {
              s.toast('Temperatur eingeben (−2 bis 35 °C).', 'err');
              return;
            }
            await s.act('incident/wtime/temp', {'temp': n});
          },
          child: const Text('Wassertemperatur setzen')),
  ]);
}
