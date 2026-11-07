// Seitenfenster «Nachrichten» der nativen Einsatz-App (Gegenstück zu renderMsgs in web/index.html): Verlauf (älteste oben),
// an mich gerichtete markiert, Standort und Kartenmarkierung je Nachricht (Zeigen, 🧭, entfernen), Häkchen für die
// Kategorie «Nachrichten-Markierungen» und Senden mit Empfängerauswahl nach Spezialrolle (keine Auswahl = alle).
import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';

import 'ctx.dart';
import 'store.dart';
import 'util.dart';

class MsgsPanel extends StatefulWidget {
  const MsgsPanel({super.key, required this.s, required this.map});
  final IncStore s;
  final MapCtl map;
  @override
  State<MsgsPanel> createState() => _MsgsPanelState();
}

class _MsgsPanelState extends State<MsgsPanel> {
  final _txt = TextEditingController();
  final _sc = ScrollController();
  final _to = <int>{}; // gewählte Empfänger (keine = alle)
  bool _mark = false, _rcpOpen = false, _busy = false;
  int _lastId = -1;

  IncStore get s => widget.s;

  @override
  void dispose() {
    _txt.dispose();
    _sc.dispose();
    super.dispose();
  }

  List<J> get _msgs {
    final l = [...s.msgs];
    l.sort((a, b) {
      final c = (jn(a['created']) ?? 0).compareTo(jn(b['created']) ?? 0);
      return c != 0 ? c : (ji(a['id']) ?? 0).compareTo(ji(b['id']) ?? 0);
    });
    return l;
  }

  /// aktive Einsatzkräfte (activePeople) ohne mich
  List<J> get _others => s.participants.where((p) => p['left_at'] == null && p['mode'] != 'declined' && ji(p['user_id']) != s.uid).toList();

  /// nach Spezialrolle gruppiert (byRole), Reihenfolge wie in der Verwaltung, ohne Rolle zuletzt
  List<(String, List<J>)> _byRole(List<J> l) {
    final g = <(String, List<J>)>[];
    for (final r in s.roles) {
      final it = l.where((p) => '${p['role_id']}' == '${r['id']}').toList();
      if (it.isNotEmpty) g.add((js(r['name']), it));
    }
    final rest = l.where((p) => !s.roles.any((r) => '${r['id']}' == '${p['role_id']}')).toList();
    if (rest.isNotEmpty) g.add(('Ohne Spezialrolle', rest));
    return g;
  }

  String _msgTo(J m) {
    final ids = s.msgIds(m);
    if (m['recipients'] == null || m['recipients'] == '' || ids.isEmpty) return 'alle';
    return ids.map((id) => id == s.uid ? 'dich' : js((s.participants.where((p) => ji(p['user_id']) == id).firstOrNull ?? {'name': '?'})['name'])).join(', ');
  }

  bool _canUnmark(J m) => ji(m['from_user']) == s.uid || s.canManage;

  Future<void> _unmark(J m) async {
    if (!await confirmDlg(context, 'Kartenmarkierung dieser Nachricht entfernen? Die Nachricht selbst bleibt erhalten.', ok: 'Entfernen', danger: true)) return;
    await s.act('incident/message/unmark', {'id': ji(m['id'])});
  }

  Future<void> _send() async {
    final t = _txt.text.trim();
    if (t.isEmpty || _busy) return;
    final valid = _others.map((p) => ji(p['user_id'])).toSet();
    final body = <String, dynamic>{'text': t, 'to': _to.where(valid.contains).toList()};
    if (_mark) {
      // Markierung am eigenen Standort (falls geteilt und bekannt), sonst Punkt auf der Karte antippen
      LatLng? q = s.sharing ? s.selfAt : null;
      q ??= await widget.map.pickPoint('Tippe auf die Karte, um die Markierung zur Nachricht zu setzen.');
      if (q == null) return;
      body.addAll({'mark': 1, 'mark_lat': q.latitude, 'mark_lon': q.longitude});
      widget.map.openPanel('msgs');
    }
    setState(() => _busy = true);
    final r = await s.act('incident/message', body);
    if (!mounted) return;
    setState(() {
      _busy = false;
      if (r != null) {
        _txt.clear();
        _mark = false;
        _to.clear();
        _rcpOpen = false;
      }
    });
  }

  void _toEnd() => WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_sc.hasClients) _sc.jumpTo(_sc.position.maxScrollExtent);
      });

  @override
  Widget build(BuildContext context) => ListenableBuilder(listenable: s, builder: (c, _) => _build(c));

  Widget _build(BuildContext context) {
    final t = Theme.of(context);
    final ms = _msgs;
    final st = s.st ?? const <String, dynamic>{};
    // gelesen: höchste ID merken; der Zähler am Kartenknopf wird über eine Benachrichtigung des Stores neu gezeichnet
    final hi = ms.fold<int>(0, (a, m) => (ji(m['id']) ?? 0) > a ? ji(m['id'])! : a);
    if (hi > s.msgSeen) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted || hi <= s.msgSeen) return;
        s.setMsgSeen(hi);
        s.setVis(const [], true);
      });
    }
    // beim Öffnen und bei neuen Nachrichten ans Ende (bei neuen nur, wenn man unten war oder selbst gesendet hat)
    if (hi != _lastId) {
      final atEnd = !_sc.hasClients || _sc.position.maxScrollExtent - _sc.position.pixels < 40;
      final own = ms.isNotEmpty && ji(ms.last['from_user']) == s.uid;
      if (_lastId < 0 || atEnd || own) _toEnd();
      _lastId = hi;
    }
    final marked = jl(st['msg_marks']).map((x) => ji(x['id'])).toSet();
    final joined = s.joined || s.exLead; // Übungsleitung sendet ohne Teilnahme
    return Material(
      color: t.colorScheme.surface,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        PanelHead('Nachrichten', onClose: () => widget.map.openPanel(null)),
        Expanded(
            child: ms.isEmpty
                ? Padding(
                    padding: const EdgeInsets.all(12),
                    child: Mut(jb(st['can_read_messages']) ? 'Noch keine Nachrichten.' : 'Nachrichten sehen nur Personen, die den Einsatz angenommen haben.'))
                : ListView.builder(
                    controller: _sc,
                    padding: const EdgeInsets.fromLTRB(10, 4, 10, 8),
                    itemCount: ms.length,
                    itemBuilder: (c, i) => _msgTile(c, ms[i], marked.contains(ji(ms[i]['id']))))),
        if (s.feat('msg'))
          Container(
              decoration: BoxDecoration(border: Border(top: BorderSide(color: t.dividerColor))),
              child: CheckboxListTile(
                  dense: true,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: s.vis('mm'),
                  onChanged: (v) => s.setVis(['mm'], v == true),
                  title: const Text('Nachrichten-Markierungen auf der Karte anzeigen'))),
        Container(
            decoration: BoxDecoration(border: Border(top: BorderSide(color: t.dividerColor))),
            padding: const EdgeInsets.fromLTRB(10, 6, 10, 10),
            child: !joined || !jb(st['can_message'])
                ? Mut(joined ? 'Deine Rolle darf keine Nachrichten senden.' : 'Zum Senden zuerst den Einsatz annehmen.')
                : _form(context)),
      ]),
    );
  }

  Widget _msgTile(BuildContext context, J m, bool marked) {
    final t = Theme.of(context);
    final me = ji(m['from_user']) == s.uid, toMe = m['recipients'] != null && m['recipients'] != '' && s.msgForMe(m);
    final from = js(m['from_name']);
    final lk = TextButton.styleFrom(visualDensity: VisualDensity.compact, padding: const EdgeInsets.symmetric(horizontal: 6), minimumSize: const Size(0, 32));
    final nb = marked ? navBtn(s, m['mark_lat'], m['mark_lon'], 'Markierung von $from') : null;
    return Container(
      margin: const EdgeInsets.symmetric(vertical: 4),
      padding: const EdgeInsets.fromLTRB(10, 8, 10, 6),
      decoration: BoxDecoration(
          color: toMe ? accOrange.withValues(alpha: .14) : t.colorScheme.surfaceContainerHighest.withValues(alpha: .5),
          borderRadius: BorderRadius.circular(10),
          border: toMe ? const Border(left: BorderSide(color: accOrange, width: 4)) : null),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text.rich(TextSpan(children: [
          TextSpan(text: me ? 'Du' : from, style: const TextStyle(fontWeight: FontWeight.bold)),
          TextSpan(text: ' ${js(m['text'])}'),
        ])),
        Padding(padding: const EdgeInsets.only(top: 2), child: Mut('${fmtT(jn(m['created']))} · an ${_msgTo(m)}', size: 12)),
        if (m['lat'] != null || marked)
          Wrap(crossAxisAlignment: WrapCrossAlignment.center, children: [
            if (llOf(m) != null)
              TextButton(
                  style: lk,
                  onPressed: () => widget.map.centerOn(llOf(m)!),
                  child: Text('📍 Standort${s.fcs(m['lat'], m['lon'], ' ')}', style: const TextStyle(fontSize: 13))),
            if (marked && llOf(m, 'mark_lat', 'mark_lon') != null)
              TextButton(
                  style: lk,
                  onPressed: () {
                    if (!s.vis('mm')) s.setVis(['mm'], true);
                    widget.map.centerOn(llOf(m, 'mark_lat', 'mark_lon')!);
                  },
                  child: Text('✉ Markierung${s.fcs(m['mark_lat'], m['mark_lon'], ' ')}', style: const TextStyle(fontSize: 13))),
            ?nb,
            if (marked && _canUnmark(m)) TextButton(style: lk, onPressed: () => _unmark(m), child: const Text('Markierung entfernen', style: TextStyle(fontSize: 13))),
          ]),
      ]),
    );
  }

  Widget _form(BuildContext context) {
    final others = _others;
    final groups = _byRole(others);
    final valid = others.map((p) => ji(p['user_id'])).toSet();
    final sel = others.where((p) => _to.contains(ji(p['user_id']))).map((p) => js(p['name'])).toList();
    final ownPos = s.sharing && s.selfAt != null;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
      // Empfänger: zugeklappt nur die Zusammenfassung, aufgeklappt Chips nach Spezialrolle
      InkWell(
          onTap: () => setState(() => _rcpOpen = !_rcpOpen),
          child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(children: [
                Icon(_rcpOpen ? Icons.expand_more : Icons.chevron_right, size: 20),
                Expanded(child: Text('An: ${sel.isEmpty ? 'alle' : sel.join(', ')}', maxLines: 2, overflow: TextOverflow.ellipsis)),
                if (_to.any(valid.contains)) TextButton(onPressed: () => setState(_to.clear), child: const Text('Alle')),
              ]))),
      if (_rcpOpen)
        ConstrainedBox(
            constraints: const BoxConstraints(maxHeight: 180),
            child: SingleChildScrollView(
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (groups.isEmpty) const Mut('Niemand sonst hat den Einsatz angenommen.'),
              for (final g in groups) ...[
                Padding(padding: const EdgeInsets.only(top: 4, bottom: 2), child: Text(g.$1, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13))),
                Wrap(spacing: 6, runSpacing: 4, children: [
                  for (final p in g.$2)
                    FilterChip(
                        visualDensity: VisualDensity.compact,
                        label: Text(js(p['name'])),
                        selected: _to.contains(ji(p['user_id'])),
                        onSelected: (v) => setState(() => v ? _to.add(ji(p['user_id'])!) : _to.remove(ji(p['user_id'])))),
                ]),
              ],
              const Padding(padding: EdgeInsets.only(top: 4), child: Mut('Benachrichtigt werden die ausgewählten Personen (keine Auswahl = alle). Lesen können alle im Einsatz.', size: 12)),
            ]))),
      CheckboxListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          value: _mark,
          onChanged: (v) => setState(() => _mark = v == true),
          title: Text(ownPos ? 'Als Markierung auf der Karte (am eigenen Standort)' : 'Als Markierung auf der Karte (Punkt wählen)')),
      Row(children: [
        Expanded(
            child: TextField(
                controller: _txt,
                minLines: 1,
                maxLines: 4,
                textInputAction: TextInputAction.send,
                onSubmitted: (_) => _send(),
                decoration: const InputDecoration(hintText: 'Nachricht', isDense: true, border: OutlineInputBorder()))),
        const SizedBox(width: 8),
        FilledButton(onPressed: _busy ? null : _send, child: const Text('Senden')),
      ]),
    ]);
  }
}
