// Ausbildungskontrolle in der nativen Einsatz-App (Issue #117, Gegenstück zu skNote, skMine, skRows und skEntryDlg in web/common.js
// bzw. web/index.html): Karte «Ausbildung» im Profil mit dem Stand je Fähigkeit (Route me/skills), Erfassen einer Ausbildung bzw.
// Auffrischung (skill/entry/save) und Entfernen eigener Einträge (skill/entry/remove); Hinweis im Menü über skSumText aus me.skills.
import 'package:flutter/material.dart' hide Text;

import 'ctx.dart';
import 'store.dart';
import 'util.dart';
import '../i18n.dart';

const _st = {
  'ok': ('gültig', okGreen),
  'warn': ('läuft bald ab', accOrange),
  'exp': ('abgelaufen', accRed),
  'miss': ('fehlt (Pflicht)', accOrange),
  'none': ('nicht erfasst', Colors.grey),
};

String _d(num? ts) {
  if (ts == null) return '';
  final d = tsDate(ts);
  return '${d.day.toString().padLeft(2, '0')}.${d.month.toString().padLeft(2, '0')}.${d.year}';
}

/// Gültigkeit in Worten (skValid)
String skValid(J k) {
  final n = ji(k['n']) ?? 0; // Kalenderjahre: 0 = laufendes, 1 = Folgejahr …
  switch (js(k['exp'])) {
    case 'months':
      return n % 12 != 0 ? '$n Monate' : (n == 12 ? '1 Jahr' : '${n ~/ 12} Jahre');
    case 'year':
      return n == 0 ? 'bis Ende des Kalenderjahres' : (n == 1 ? 'bis Ende des Folgejahres' : 'bis Ende des $n. Folgejahres');
  }
  return 'unbegrenzt';
}

/// Zusammenfassung aus me.skills {exp, warn, miss}; leer = alles in Ordnung (skSum)
String skSumText(dynamic s) {
  if (s is! Map) return '';
  final e = ji(s['exp']) ?? 0, w = ji(s['warn']) ?? 0, m = ji(s['miss']) ?? 0;
  return [
    if (e > 0) '$e abgelaufen',
    if (w > 0) '$w läuft bald ab',
    if (m > 0) m > 1 ? '$m Pflicht-Ausbildungen fehlen' : '1 Pflicht-Ausbildung fehlt',
  ].join(', ');
}

/// Karte «Ausbildung» im Profil (nur wenn für die Person Fähigkeiten gelten, me.skills.n > 0)
class SkillPrefs extends StatefulWidget {
  const SkillPrefs({super.key, required this.s});
  final IncStore s;
  @override
  State<SkillPrefs> createState() => _SkillPrefsState();
}

class _SkillPrefsState extends State<SkillPrefs> {
  IncStore get s => widget.s;
  List<J>? _items; // null = wird geladen
  bool _err = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = jm((await s.api.call('me/skills'))['data']);
      if (mounted) setState(() => (_items = jl(r['items']), _err = false));
    } catch (_) {
      if (mounted) setState(() => (_items = [], _err = true));
    }
  }

  Future<void> _done() async {
    await _load();
    await s.reloadMe(); // Hinweis im Menü
  }

  Future<void> _add(J k) async {
    final now = DateTime.now();
    final day = await showDatePicker(
        context: context, helpText: 'Datum der Ausbildung', initialDate: now, firstDate: now.subtract(const Duration(days: 60 * 366)), lastDate: now);
    if (day == null || !mounted) return;
    final note = await inputDlg(context, js(k['name']), label: 'Bemerkung (optional)', hint: 'z. B. Kurs, Ort, Ausbildner', ok: 'Speichern');
    if (note == null) return;
    try {
      final t = DateTime(day.year, day.month, day.day, 12).millisecondsSinceEpoch ~/ 1000; // Mittag Ortszeit wie im Web
      await s.api.call('skill/entry/save', body: {'skill_id': k['id'], 'done': t, 'note': note});
      s.toast('Ausbildung erfasst.');
      await _done();
    } catch (e) {
      s.fail(e);
    }
  }

  Future<void> _remove(J e) async {
    if (!await confirmDlg(context, 'Eintrag vom ${_d(jn(e['done_at']))} entfernen?', ok: 'Entfernen', danger: true)) return;
    try {
      await s.api.call('skill/entry/remove', body: {'id': e['id']});
      s.toast('Eintrag entfernt.');
      await _done();
    } catch (x) {
      s.fail(x);
    }
  }

  Widget _row(J x) {
    final k = jm(x['skill']), st = _st[js(x['state'])] ?? _st['none']!, adm = s.can('skills'), my = ji(s.me?['id']);
    final ed = adm || jb(k['self']), exp = jn(x['exp']), hist = jl(x['hist']);
    return Container(
      margin: const EdgeInsets.symmetric(vertical: 4),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(border: Border.all(color: Colors.white24), borderRadius: BorderRadius.circular(8)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Wrap(spacing: 6, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
          Text(js(k['name']), style: const TextStyle(fontWeight: FontWeight.bold)),
          if (js(k['cat']).isNotEmpty) Mut(js(k['cat'])),
          if (jb(x['req'] ?? k['req'])) const Mut('★'), // req je Person (Pflicht je Einsatzgruppe)
          Chip2('${st.$1}${exp != null ? (x['state'] == 'exp' ? ' seit ' : ' bis ') + _d(exp) : ''}', color: st.$2),
        ]),
        const SizedBox(height: 2),
        Mut(
            '${x['done'] != null ? 'Zuletzt ${_d(jn(x['done']))}${js(x['note']).isNotEmpty ? ' – ${js(x['note'])}' : ''}' : 'Noch keine Ausbildung erfasst'} · Gültigkeit ${skValid(k)}${js(k['descr']).isNotEmpty ? '\n${js(k['descr'])}' : ''}',
            size: 12),
        const SizedBox(height: 6),
        if (ed)
          smallBtn(x['done'] != null ? '+ Auffrischung erfassen' : '+ Ausbildung erfassen', () => _add(k))
        else if (x['state'] != 'ok')
          const Mut('Erfasst die Verwaltung.', size: 12),
        if (hist.isNotEmpty)
          ExpansionTile(
              tilePadding: EdgeInsets.zero,
              title: Text('Verlauf (${hist.length})', style: const TextStyle(fontSize: 14)),
              children: hist
                  .map((e) => Row(children: [
                        Expanded(
                            child: Text(
                                '${_d(jn(e['done_at']))}${js(e['note']).isNotEmpty ? ' – ${js(e['note'])}' : ''}${ji(e['created_by']) != my && js(e['by_name']).isNotEmpty ? ' (erfasst von ${js(e['by_name'])})' : ''}',
                                style: const TextStyle(fontSize: 13))),
                        if (adm || (ji(e['created_by']) == my && jb(k['self']))) smallBtn('✕', () => _remove(e)),
                      ]))
                  .toList()),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final it = _items;
    return MCard(title: 'Ausbildung', children: [
      const Mut('Deine Ausbildungen mit Gültigkeit. Läuft eine ab oder fehlt eine Pflicht-Ausbildung, steht ein Hinweis im Menü.'),
      const SizedBox(height: 4),
      if (it == null)
        const Mut('Wird geladen …')
      else if (_err)
        const Mut('Ausbildung nicht verfügbar (keine Verbindung).')
      else if (it.isEmpty)
        const Mut('Für dich ist keine Ausbildung hinterlegt.')
      else
        ...it.map(_row),
    ]);
  }
}
