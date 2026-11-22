// Dokumente in der nativen Einsatz-App (Issue #229, Gegenstück zu web/docs.js und dem Block «Dokumente» in web/index.html):
// Seitenfenster «Dokumente» (Kartenknopf, Abschnitte je Einsatztyp, Ordner, Suche), Ansicht eines Eintrags als eigene Seite
// (Seite als Markdown, Bild, Text; übrige Dateien öffnen bzw. teilen; Link im Browser), Lesebestätigung, Favorit, Vorschlag,
// Offline-Ablage im App-Ordner, Karte «Unterlagen» im Menü, Knopf bei festen Standorten, Freigabe für Externe (Leitung),
// Profil-Karte «Dokumente». Daten: me.docs / me.docs_cfg (Lesebestätigungen, Offline-Liste), incident/state docs (Einsatztyp,
// Standorte, Freigaben für Externe), Routen docs, doc, doc/get, doc/fav, doc/ack, doc/sug, doc/sugs, doc/sug/remove, doc/view,
// incident/doc/ext/save|remove.
import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api.dart';
import 'ctx.dart';
import 'store.dart';
import 'util.dart';

// ---------- Grundlagen ----------
J docMe(IncStore s) => s.me?['docs'] is Map ? jm(s.me!['docs']) : <String, dynamic>{};
J docCfg(IncStore s) => s.me?['docs_cfg'] is Map ? jm(s.me!['docs_cfg']) : <String, dynamic>{};

/// Kartenknopf «Dokumente» (docBtn): Ablage vorhanden, im Einsatz nur mit Funktion docs
bool docBtn(IncStore s) => !s.demo && jb(docMe(s)['on']) && (s.inc == null || s.feat('docs'));

/// offene Lesebestätigungen (Zähler am Kartenknopf)
List<J> docAcks(IncStore s) => jl(docMe(s)['ack']);

/// Einträge des Einsatzes aus incident/state (Einsatztyp kind_hit, Standorte places)
List _ids(dynamic v) => v is List ? v : const [];
J docInc(IncStore s) => s.st?['docs'] is Map ? jm(s.st!['docs']) : <String, dynamic>{};
int docKid(IncStore s) => ji(jm(s.inc?['kind'])['id']) ?? 0;

const _icon = {'page': '📄', 'link': '🔗', 'pdf': '📕', 'img': '🖼️', 'text': '📝', 'audio': '🎧', 'video': '🎬', 'dl': '📎'};
String dkIco(J d) => js(d['kind']) == 'file' ? (_icon[js(jm(d['file'])['show'])] ?? '📎') : (_icon[js(d['kind'])] ?? '📄');
String dkSize(num? b) => b == null ? '' : (b < 1024 ? '$b B' : (b < 1048576 ? '${(b / 1024).round()} KB' : '${(b / 1048576).toStringAsFixed(b < 10485760 ? 1 : 0).replaceAll('.', ',')} MB'));
String dkDate(num? t) {
  if (t == null) return '';
  final d = DateTime.fromMillisecondsSinceEpoch((t * 1000).round());
  return '${d.day}.${d.month}.${d.year}';
}

String dkKindTxt(J d) {
  final f = d['file'] is Map ? jm(d['file']) : null;
  return switch (js(d['kind'])) {
    'page' => 'Seite',
    'link' => 'Link',
    _ => f == null ? 'Datei (noch leer)' : '${js(f['ext']).toUpperCase()}${f['pages'] != null ? ', ${f['pages']} S.' : ''}, ${dkSize(jn(f['bytes']))}',
  };
}

// ---------- Offline-Ablage (Gegenstück zu dkOffSync: Cache-API im Browser) ----------
Future<Directory> _offDir(IncStore s) async {
  final d = Directory('${(await getApplicationSupportDirectory()).path}/docs/${s.acc.id}');
  if (!await d.exists()) await d.create(recursive: true);
  return d;
}

bool _offBusy = false;

/// Offline-Liste von me.docs.off nachführen: fehlende Fassungen laden, alte und nicht mehr gewünschte löschen
Future<void> docOffSync(IncStore s) async {
  if (_offBusy || s.demo || !jb(docMe(s)['on'])) return;
  _offBusy = true;
  try {
    final dir = await _offDir(s), want = {for (final x in jl(docMe(s)['off'])) ji(x['id'])!: ji(x['ver']) ?? 1};
    for (final f in dir.listSync().whereType<File>()) {
      final m = RegExp(r'^(\d+)_(\d+)\.(json|bin)$').firstMatch(f.uri.pathSegments.last);
      if (m == null || want[int.parse(m.group(1)!)] != int.parse(m.group(2)!)) await f.delete();
    }
    for (final e in want.entries) {
      final mj = File('${dir.path}/${e.key}_${e.value}.json');
      if (await mj.exists()) continue;
      final d = await s.api.call('doc', query: {'id': '${e.key}'});
      if (js(d['kind']) == 'file' && d['file'] != null) await File('${dir.path}/${e.key}_${e.value}.bin').writeAsBytes(await docBytes(s, e.key));
      await mj.writeAsString(jsonEncode(d));
    }
  } catch (_) {
    // ohne Verbindung oder Fehler einzelner Einträge: beim nächsten Mal erneut
  } finally {
    _offBusy = false;
  }
}

Future<J?> _offMeta(IncStore s, int id) async {
  try {
    final dir = await _offDir(s);
    final f = dir.listSync().whereType<File>().where((f) => RegExp('^${id}_\\d+\\.json\$').hasMatch(f.uri.pathSegments.last)).firstOrNull;
    return f == null ? null : jm(jsonDecode(await f.readAsString()));
  } catch (_) {
    return null;
  }
}

Future<List<int>?> _offBytes(IncStore s, int id, int ver) async {
  final f = File('${(await _offDir(s)).path}/${id}_$ver.bin');
  return await f.exists() ? f.readAsBytes() : null;
}

/// Datei eines Eintrags (doc/get, kein JSON)
Future<List<int>> docBytes(IncStore s, int id) async {
  final a = s.api;
  final r = await http.get(a.uri('doc/get', {'id': '$id', 'dl': '1'}), headers: {'X-Token': a.token ?? '', 'User-Agent': appUa}).timeout(const Duration(seconds: 120));
  if (r.statusCode != 200) {
    var m = 'Datei nicht verfügbar';
    try {
      m = '${jsonDecode(utf8.decode(r.bodyBytes))['error']}';
    } catch (_) {}
    throw ApiError(m, r.statusCode);
  }
  return r.bodyBytes;
}

/// Eintrag laden: online, sonst aus der Offline-Ablage (off = 1)
Future<J> docLoad(IncStore s, int id) async {
  try {
    return await s.api.call('doc', query: {'id': '$id'});
  } on ApiError catch (e) {
    if (!e.offline) rethrow;
    final m = await _offMeta(s, id);
    if (m == null) throw ApiError('Ohne Verbindung nicht verfügbar (nicht offline gespeichert)');
    return m..['off'] = 1;
  }
}

/// nach Änderungen (Bestätigung, Favorit): me neu laden, damit Zähler und Menü stimmen
Future<void> docReload(IncStore s) async {
  await s.reloadMe();
  unawaited(docOffSync(s));
}

// ---------- Markdown (Teilmenge wie dkMd: Überschriften, Listen, Zitate, Tabellen, fett/kursiv, Links, Bilder img:<id>) ----------
List<Widget> dkMd(BuildContext c, String txt, J imgs, {void Function(int id)? openDoc}) {
  final t = Theme.of(c).textTheme, out = <Widget>[], para = <String>[];
  final lines = txt.replaceAll('\r', '').split('\n');
  void flush() {
    if (para.isEmpty) return;
    out.add(Padding(padding: const EdgeInsets.only(bottom: 8), child: _inl(c, para.join(' '), openDoc)));
    para.clear();
  }

  for (var i = 0; i < lines.length; i++) {
    final l = lines[i], tr = l.trim();
    final img = RegExp(r'^!\[([^\]]*)\]\(img:(\d+)\)$').firstMatch(tr);
    final h = RegExp(r'^(#{1,4})\s+(.*?)(\s*\{#[^}]*\})?$').firstMatch(tr);
    if (tr.isEmpty) {
      flush();
    } else if (img != null) {
      flush();
      final d = js(imgs[img.group(2)]);
      final b64 = d.contains(',') ? d.substring(d.indexOf(',') + 1) : '';
      if (b64.isNotEmpty) {
        try {
          out.add(
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Column(children: [Image.memory(base64Decode(b64)), if (img.group(1)!.isNotEmpty) Mut(img.group(1)!, size: 12)]),
            ),
          );
        } catch (_) {}
      }
    } else if (h != null) {
      flush();
      final n = h.group(1)!.length;
      out.add(
        Padding(
          padding: const EdgeInsets.only(top: 6, bottom: 6),
          child: Text(h.group(2)!, style: (n == 1 ? t.titleLarge : (n == 2 ? t.titleMedium : t.titleSmall))?.copyWith(fontWeight: FontWeight.bold)),
        ),
      );
    } else if (RegExp(r'^([-*]|\d+\.)\s+').hasMatch(tr)) {
      flush();
      final num = RegExp(r'^\d+\.').hasMatch(tr);
      var k = 1;
      while (i < lines.length && RegExp(r'^([-*]|\d+\.)\s+').hasMatch(lines[i].trim())) {
        final it = lines[i].trim().replaceFirst(RegExp(r'^([-*]|\d+\.)\s+'), '');
        out.add(
          Padding(
            padding: const EdgeInsets.only(left: 8, bottom: 3),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(width: 22, child: Text(num ? '${k++}.' : '•')),
                Expanded(child: _inl(c, it, openDoc)),
              ],
            ),
          ),
        );
        i++;
      }
      i--;
      out.add(const SizedBox(height: 6));
    } else if (tr.startsWith('>')) {
      flush();
      final q = <String>[];
      while (i < lines.length && lines[i].trim().startsWith('>')) {
        q.add(lines[i].trim().replaceFirst(RegExp(r'^>\s?'), ''));
        i++;
      }
      i--;
      final warn = q.isNotEmpty && q.first.startsWith('!');
      if (warn) q[0] = q[0].replaceFirst(RegExp(r'^!\s*'), '');
      final col = warn ? accRed : const Color(0xFF1D7FD1);
      out.add(
        Container(
          margin: const EdgeInsets.only(bottom: 8),
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: col.withValues(alpha: .12),
            border: Border(left: BorderSide(color: col, width: 4)),
          ),
          child: _inl(c, q.join(' '), openDoc),
        ),
      );
    } else if (tr.startsWith('|')) {
      flush();
      final rows = <List<String>>[];
      while (i < lines.length && lines[i].trim().startsWith('|')) {
        final r = lines[i].trim().replaceAll(RegExp(r'^\||\|$'), '').split('|').map((x) => x.trim()).toList();
        if (!r.every((x) => RegExp(r'^:?-{2,}:?$').hasMatch(x))) rows.add(r);
        i++;
      }
      i--;
      final w = rows.fold<int>(0, (m, r) => r.length > m ? r.length : m);
      out.add(
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Table(
              defaultColumnWidth: const IntrinsicColumnWidth(),
              border: TableBorder(horizontalInside: BorderSide(color: Theme.of(c).dividerColor)),
              children: [
                for (var k = 0; k < rows.length; k++)
                  TableRow(
                    children: [
                      for (var j = 0; j < w; j++)
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          child: k == 0 ? Text(j < rows[k].length ? rows[k][j] : '', style: const TextStyle(fontWeight: FontWeight.bold)) : _inl(c, j < rows[k].length ? rows[k][j] : '', openDoc),
                        ),
                    ],
                  ),
              ],
            ),
          ),
        ),
      );
    } else {
      para.add(tr);
    }
  }
  flush();
  return out;
}

/// Zeile mit **fett**, *kursiv*, `Code` und [Links](…) (`doc:ID` öffnet einen anderen Eintrag)
Widget _inl(BuildContext c, String s, void Function(int id)? openDoc) {
  final sp = <InlineSpan>[], re = RegExp(r'\*\*(.+?)\*\*|\*(.+?)\*|`(.+?)`|\[([^\]]+)\]\(([^)\s]+)\)');
  var p = 0;
  for (final m in re.allMatches(s)) {
    if (m.start > p) sp.add(TextSpan(text: s.substring(p, m.start)));
    if (m.group(1) != null) {
      sp.add(
        TextSpan(
          text: m.group(1),
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
      );
    } else if (m.group(2) != null) {
      sp.add(
        TextSpan(
          text: m.group(2),
          style: const TextStyle(fontStyle: FontStyle.italic),
        ),
      );
    } else if (m.group(3) != null) {
      sp.add(
        TextSpan(
          text: m.group(3),
          style: const TextStyle(fontFamily: 'monospace'),
        ),
      );
    } else {
      final u = m.group(5)!, d = RegExp(r'^doc:(\d+)$').firstMatch(u);
      sp.add(
        WidgetSpan(
          alignment: PlaceholderAlignment.baseline,
          baseline: TextBaseline.alphabetic,
          child: GestureDetector(
            onTap: () {
              if (d != null) {
                openDoc?.call(int.parse(d.group(1)!));
              } else if (RegExp(r'^(https?:|mailto:|tel:)').hasMatch(u)) {
                launchUrl(Uri.parse(u), mode: LaunchMode.externalApplication);
              }
            },
            child: Text(
              m.group(4)!,
              style: const TextStyle(color: Color(0xFF4FA3E8), decoration: TextDecoration.underline),
            ),
          ),
        ),
      );
    }
    p = m.end;
  }
  if (p < s.length) sp.add(TextSpan(text: s.substring(p)));
  return Text.rich(TextSpan(children: sp));
}

// ---------- Zeile eines Eintrags (wie dkRow in docs.js, Konzept #229 Bild 1/2) ----------
const _plBlue = Color(0xFF5AA9FF), _favGold = Color(0xFFF2C94C);

/// offline auf diesem Gerät: immer bzw. als Favorit (wie auf dem Server)
bool dkOffOn(J d) => ji(d['offline']) == 2 || (ji(d['offline']) == 1 && jb(d['fav']));

/// Chip mit Rand wie .dkchip
Widget dkChip(String t, Color c) => Container(
  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1),
  decoration: BoxDecoration(border: Border.all(color: c), borderRadius: BorderRadius.circular(10)),
  child: Text(t, style: TextStyle(color: c, fontSize: 11.5, fontWeight: FontWeight.w600)),
);

/// Text mit hervorgehobenen Suchwörtern
Widget dkHl(String t, RegExp? re, TextStyle st) {
  if (re == null) return Text(t, style: st);
  final sp = <TextSpan>[];
  var p = 0;
  for (final m in re.allMatches(t)) {
    if (m.start > p) sp.add(TextSpan(text: t.substring(p, m.start)));
    sp.add(TextSpan(text: m.group(0), style: const TextStyle(backgroundColor: Color(0x66E8B545))));
    p = m.end;
  }
  if (p < t.length) sp.add(TextSpan(text: t.substring(p)));
  return Text.rich(TextSpan(style: st, children: sp));
}

/// meta: statt Art · Grösse · Stand (Suche: Ordnerpfad · Art); snip: Fundstelle (Präfix, Text); places: Namen der Standorte
Widget dkRow(BuildContext c, J d, VoidCallback onTap, {VoidCallback? onFav, String? meta, (String, String, bool)? snip, List<String> places = const [], RegExp? hl}) {
  final m = d['mine'] is Map ? jm(d['mine']) : null, ack = m != null && js(m['st']) != 'ok', f = js(d['kind']) == 'file' && d['file'] is Map ? jm(d['file']) : null;
  final mt = meta ?? [dkArt(d), if (f != null && f['bytes'] != null) dkSize(jn(f['bytes'])!), if (d['updated'] != null) 'Stand ${dkDate(jn(d['updated']))}'].join(' · ');
  final mut = Theme.of(c).hintColor;
  final chips = <Widget>[
    if (dkOffOn(d)) dkChip('⬇ offline', okGreen),
    if (ack) dkChip('${js(m['st']) == 'over' ? 'überfällig' : 'bestätigen'}${m['due'] != null ? ' bis ${dkDate(jn(m['due']))}' : ''}', js(m['st']) == 'over' ? accRed : accOrange),
    if (jb(d['review'])) dkChip('prüfen', const Color(0xFFC08BFF)),
    for (final p in places) dkChip('📍 $p', _plBlue),
  ];
  return InkWell(
    onTap: onTap,
    child: Container(
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 2),
      decoration: BoxDecoration(border: Border(bottom: BorderSide(color: Theme.of(c).dividerColor))),
      child: Row(
        children: [
          dkBadge(d),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                dkHl(js(d['title']), hl, const TextStyle(fontWeight: FontWeight.bold)),
                Mut(mt, size: 12),
                if (snip != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 1),
                    child: Text.rich(
                      TextSpan(
                        style: TextStyle(fontSize: 12, fontStyle: FontStyle.italic, color: mut),
                        children: [
                          TextSpan(text: snip.$1),
                          if (snip.$3) const TextSpan(text: '«'),
                          WidgetSpan(alignment: PlaceholderAlignment.baseline, baseline: TextBaseline.alphabetic, child: dkHl(snip.$2, hl, TextStyle(fontSize: 12, fontStyle: FontStyle.italic, color: mut))),
                          if (snip.$3) const TextSpan(text: '»'),
                        ],
                      ),
                    ),
                  ),
                if (chips.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 3), child: Wrap(spacing: 4, runSpacing: 3, children: chips)),
              ],
            ),
          ),
          const SizedBox(width: 6),
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              OutlinedButton(
                onPressed: onTap,
                style: OutlinedButton.styleFrom(minimumSize: const Size(0, 34), padding: const EdgeInsets.symmetric(horizontal: 12), visualDensity: VisualDensity.compact),
                child: const Text('Öffnen'),
              ),
              if (onFav != null)
                IconButton(
                  onPressed: onFav,
                  visualDensity: VisualDensity.compact,
                  tooltip: jb(d['fav']) ? 'Favorit entfernen' : 'Als Favorit (wird offline gespeichert, wenn die Redaktion es erlaubt)',
                  icon: Text(jb(d['fav']) ? '★' : '☆', style: TextStyle(fontSize: 20, color: jb(d['fav']) ? _favGold : mut)),
                ),
            ],
          ),
        ],
      ),
    ),
  );
}

/// Abschnitt mit umrandetem Kopf wie .dksec (aufklappbar)
class DkSec extends StatefulWidget {
  const DkSec(this.title, this.children, {super.key});
  final String title;
  final List<Widget> children;
  @override
  State<DkSec> createState() => _DkSecState();
}

class _DkSecState extends State<DkSec> {
  bool _open = true;
  @override
  Widget build(BuildContext c) => Padding(
    padding: const EdgeInsets.only(top: 10),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        InkWell(
          onTap: () => setState(() => _open = !_open),
          borderRadius: BorderRadius.circular(8),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            decoration: BoxDecoration(border: Border.all(color: Theme.of(c).dividerColor), borderRadius: BorderRadius.circular(8)),
            child: Text('${_open ? '▾' : '▸'}  ${widget.title}', style: const TextStyle(fontWeight: FontWeight.bold)),
          ),
        ),
        if (_open) ...widget.children,
      ],
    ),
  );
}

// ---------- Seitenfenster «Dokumente» ----------
class DocsPanel extends StatefulWidget {
  const DocsPanel({super.key, required this.s, this.map});
  final IncStore s;
  final MapCtl? map; // ohne Karte (aus dem Profil) als eigene Seite
  @override
  State<DocsPanel> createState() => _DocsPanelState();
}

class _DocsPanelState extends State<DocsPanel> {
  IncStore get s => widget.s;
  J? _l;
  String? _err;
  int? _f; // geöffneter Ordner
  String _q = '';
  List<J>? _hits;
  Timer? _qt;
  final _qc = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
    unawaited(docOffSync(s));
  }

  @override
  void dispose() {
    _qt?.cancel();
    _qc.dispose();
    super.dispose();
  }

  File? _cacheFile;
  Future<void> _load() async {
    try {
      _cacheFile ??= File('${(await _offDir(s)).path}/list.json');
      final r = await s.api.call('docs', query: {'kid': '${docKid(s)}'});
      unawaited(_cacheFile!.writeAsString(jsonEncode(r)));
      if (mounted) setState(() => _l = r);
    } on ApiError catch (e) {
      J? c;
      if (e.offline && _cacheFile != null && await _cacheFile!.exists()) c = jm(jsonDecode(await _cacheFile!.readAsString()));
      if (mounted) setState(() => c != null ? _l = c : _err = e.message);
    }
  }

  void _open(int id) => docOpen(
    context,
    s,
    id,
    onChange: () async {
      await docReload(s);
      _load();
    },
  );

  void _search(String v) {
    _q = v;
    _qt?.cancel();
    if (v.trim().length < 2) return setState(() => _hits = null);
    setState(() => _hits = null);
    _qt = Timer(const Duration(milliseconds: 300), () async {
      try {
        final r = await s.api.call('docs', query: {'q': v});
        if (v == _q && mounted) setState(() => _hits = jl(r['hits']));
      } catch (_) {
        // ohne Verbindung: nur Titel, Beschreibung, Schlagwörter
        final w = v.toLowerCase().split(RegExp(r'\s+')).where((x) => x.length > 1);
        if (mounted) {
          setState(
            () =>
                _hits = jl(_l?['docs'])
                    .where((d) => w.every((x) => '${js(d['title'])} ${js(d['descr'])} ${js(d['tags'])}'.toLowerCase().contains(x)))
                    .map((d) => <String, dynamic>{'id': d['id'], 'snip': d['descr']})
                    .toList(),
          );
        }
      }
    });
  }

  List<J> get _docs => jl(_l?['docs']);
  List<J> get _folders => jl(_l?['folders']);
  J? _byId(int? id) => _docs.where((d) => ji(d['id']) == id).firstOrNull;

  /// Abschnitte gemäss eigener Anordnung bzw. Vorgabe des Einsatztyps (dkSecsFor)
  List<J> _secs() => docSecsFor(_l!, docKid(s));

  Future<void> _fav(J d) async {
    try {
      await s.api.call('doc/fav', body: {'id': ji(d['id']), 'on': jb(d['fav']) ? 0 : 1});
      setState(() => d['fav'] = jb(d['fav']) ? 0 : 1);
      unawaited(docOffSync(s));
    } catch (e) {
      s.fail(e);
    }
  }

  Widget _row(J d, {String? meta, (String, String, bool)? snip, List<String> places = const [], RegExp? hl}) =>
      dkRow(context, d, () => _open(ji(d['id'])!), onFav: () => _fav(d), meta: meta, snip: snip, places: places, hl: hl);

  Widget _sec(String t, List<J> ds, {List<String> Function(J d)? places}) => ds.isEmpty
      ? const SizedBox.shrink()
      : DkSec('$t (${ds.length})', [for (final d in ds) _row(d, places: places?.call(d) ?? const [])]);

  /// Inhalt eines Ordners samt allen Unterordnern: «5 Dokumente · 2 Unterordner» (wie fCnt in docs.js)
  String _fCnt(int? id) {
    var n = 0, k = 0;
    void go(int? i) {
      n += _docs.where((d) => ji(d['folder_id']) == i && jb(d['active'])).length;
      for (final x in _folders.where((x) => ji(x['parent_id']) == i && jb(x['active']))) {
        k++;
        go(ji(x['id']));
      }
    }

    go(id);
    return '${n == 1 ? '1 Dokument' : '$n Dokumente'}${k > 0 ? ' · ${k == 1 ? '1 Unterordner' : '$k Unterordner'}' : ''}';
  }

  List<Widget> _folderList(int? pid) {
    final fs = _folders.where((f) => ji(f['parent_id']) == pid && jb(f['active'])).toList();
    final ds = pid == null ? <J>[] : _docs.where((d) => ji(d['folder_id']) == pid && jb(d['active'])).toList();
    if (fs.isEmpty && ds.isEmpty) return [const Mut('Leer.')];
    return [
      for (final f in fs)
        InkWell(
          onTap: () => setState(() => _f = ji(f['id'])),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 2),
            child: Row(
              children: [
                Container(
                  width: 44,
                  height: 38,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: const Color(0xFF3B4B5B), borderRadius: BorderRadius.circular(6)),
                  child: const Text('📁', style: TextStyle(fontSize: 18)),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${js(f['name'])}${jb(f['lock']) ? ' 🔒' : ''}', style: const TextStyle(fontWeight: FontWeight.bold)),
                      Mut('${_fCnt(ji(f['id']))}${js(f['descr']).isNotEmpty ? ' · ${js(f['descr'])}' : ''}', size: 12),
                    ],
                  ),
                ),
                const Mut('›'),
              ],
            ),
          ),
        ),
      for (final d in ds) _row(d),
    ];
  }

  List<Widget> _start() {
    final L = _l!, t = jm(jm(L['cfg'])['secs']), inc = docInc(s), il = jl(inc['list']), n = ji(jm(L['cfg'])['n']) ?? 10;
    final pl = {for (final p in s.places) ji(p['id']): js(p['name'])};
    final out = <Widget>[];
    for (final sc in _secs()) {
      final k = js(sc['k']), tt = js(t[k]);
      switch (k) {
        case 'ack':
          out.add(
            _sec(tt, _docs.where((d) => d['mine'] is Map && js(jm(d['mine'])['st']) != 'ok').toList()..sort((a, b) => (jn(jm(a['mine'])['due']) ?? 9e12).compareTo(jn(jm(b['mine'])['due']) ?? 9e12))),
          );
        case 'fav':
          out.add(_sec('★ $tt', _docs.where((d) => jb(d['fav'])).toList()));
        case 'place':
          final ds = il.where((d) => _ids(d['places']).isNotEmpty).map((d) => _byId(ji(d['id'])) ?? d).toList();
          out.add(
            _sec(
              tt,
              ds,
              places: (d) => _ids(il.firstWhere((x) => ji(x['id']) == ji(d['id']))['places']).map((i) => pl[i] ?? 'Standort').toList(),
            ),
          );
        case 'kind':
          out.add(_sec(tt, il.where((d) => jb(d['kind_hit'])).map((d) => _byId(ji(d['id'])) ?? d).toList()));
        case 'recent':
          out.add(_sec(tt, (_docs.where((d) => d['seen'] != null).toList()..sort((a, b) => jn(b['seen'])!.compareTo(jn(a['seen'])!))).take(n).toList()));
        case 'new':
          final lim = DateTime.now().millisecondsSinceEpoch / 1000 - 30 * 86400;
          out.add(_sec(tt, (_docs.where((d) => (jn(d['updated']) ?? 0) > lim).toList()..sort((a, b) => jn(b['updated'])!.compareTo(jn(a['updated'])!))).take(n).toList()));
        case 'folders':
          out.add(DkSec(tt, _folderList(null)));
      }
    }
    if (out.isEmpty) out.add(const Mut('Noch keine Dokumente.'));
    if (jb(jm(L['cfg'])['own'])) {
      out.add(
        Align(
          alignment: Alignment.centerLeft,
          child: OutlinedButton(onPressed: () => docOrdDlg(context, s, L, docKid(s), _load), child: const Text('Abschnitte anordnen')),
        ),
      );
    }
    return out;
  }

  List<Widget> _folder(int id) {
    final path = <J>[];
    var f = _folders.where((x) => ji(x['id']) == id).firstOrNull;
    final cur = f;
    while (f != null) {
      path.insert(0, f);
      final p = ji(f['parent_id']);
      f = _folders.where((x) => ji(x['id']) == p).firstOrNull;
    }
    return [
      Wrap(
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          TextButton(onPressed: () => setState(() => _f = null), child: const Text('Dokumente')),
          for (final p in path) ...[const Text('›'), TextButton(onPressed: () => setState(() => _f = ji(p['id'])), child: Text(js(p['name'])))],
        ],
      ),
      if (js(cur?['descr']).isNotEmpty) Mut(js(cur!['descr'])),
      ..._folderList(id),
      if (jb(cur?['sug']))
        Align(
          alignment: Alignment.centerLeft,
          child: OutlinedButton(
            onPressed: () => docSugDlg(context, s, folderId: id),
            child: const Text('+ Eintrag vorschlagen'),
          ),
        ),
    ];
  }

  List<Widget> _results() {
    final h = _hits;
    if (h == null) return [const Mut('Wird gesucht …')];
    if (h.isEmpty) return [const Mut('Keine Treffer.')];
    // Fundstelle wie im Konzept (Bild 2): Ordnerpfad · Art, darunter «S. n: «…»», «Schlagwort: …» oder «Beschreibung: «…»»
    final ws = _q.trim().split(RegExp(r'\s+')).where((w) => w.length > 1).map(RegExp.escape).toList();
    final re = ws.isEmpty ? null : RegExp(ws.join('|'), caseSensitive: false);
    String path(int? id) {
      final r = <String>[];
      var f = _folders.where((x) => ji(x['id']) == id).firstOrNull;
      while (f != null) {
        r.insert(0, js(f['name']));
        final p = ji(f['parent_id']);
        f = _folders.where((x) => ji(x['id']) == p).firstOrNull;
      }
      return r.join(' › ');
    }

    return [
      DkSec('Treffer (${h.length})', [
        for (final x in h)
          if (_byId(ji(x['id'])) case final d?)
            _row(
              d,
              hl: re,
              meta: [path(ji(d['folder_id'])), js(d['kind']) == 'link' ? 'externe Seite' : dkArt(d)].where((y) => y.isNotEmpty).join(' · '),
              snip: js(x['where']) == 'title' || js(x['snip']).isEmpty
                  ? null
                  : switch (js(x['where'])) {
                      'tags' => ('Schlagwort: ', js(x['snip']), false),
                      'descr' => ('Beschreibung: ', js(x['snip']), true),
                      _ => (x['page'] != null ? 'S. ${x['page']}: ' : '', js(x['snip']), true),
                    },
            ),
      ]),
      const Padding(
        padding: EdgeInsets.only(top: 10),
        child: Mut('Gesucht wird in Titel, Beschreibung, Schlagwörtern, Seiten und im Text der PDFs. Nur Dokumente, die du sehen darfst.', size: 12),
      ),
    ];
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: s,
    builder: (c, _) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        PanelHead('📚 Dokumente', onClose: () => widget.map != null ? widget.map!.openPanel(null) : Navigator.pop(context)),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 6),
          child: TextField(
            controller: _qc,
            onChanged: _search,
            decoration: const InputDecoration(isDense: true, prefixIcon: Icon(Icons.search), hintText: 'Dokumente durchsuchen …'),
          ),
        ),
        Expanded(
          child: _l == null
              ? Center(child: _err != null ? Mut(_err!) : const CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.fromLTRB(12, 0, 12, 12), children: _q.trim().length >= 2 ? _results() : (_f != null ? _folder(_f!) : _start())),
                ),
        ),
        Container(
          padding: const EdgeInsets.fromLTRB(12, 6, 12, 8),
          decoration: BoxDecoration(border: Border(top: BorderSide(color: Theme.of(c).dividerColor))),
          child: const Mut('★ = Favorit · ⬇ = auf diesem Gerät offline gespeichert', size: 11.5),
        ),
      ],
    ),
  );
}

/// eigene Anordnung vor der Vorgabe; fehlende Abschnitte aus der Vorgabe ergänzt (dkSecsFor)
List<J> docSecsFor(J L, int kid) {
  final c = jm(L['cfg']), my = L['my_view'] is Map ? jm(L['my_view']) : <String, dynamic>{}, view = jm(c['view']);
  final own = jb(c['own']) ? (my['$kid'] ?? my['0']) : null, def = jl(view['$kid'] ?? view['0']);
  final l = own is List ? [...own.map((x) => jm(x)), ...def.where((d) => !own.any((o) => jm(o)['k'] == d['k']))] : def;
  return l.where((x) => jb(x['on'])).toList();
}

/// eigene Reihenfolge und Auswahl der Abschnitte (doc/view, je Einsatztyp; «Vorgabe» löscht die eigene)
Future<void> docOrdDlg(BuildContext c, IncStore s, J L, int kid, Future<void> Function() done) async {
  final cf = jm(L['cfg']), t = jm(cf['secs']), my = L['my_view'] is Map ? jm(L['my_view']) : <String, dynamic>{}, view = jm(cf['view']);
  final def = jl(view['$kid'] ?? view['0']), own = my['$kid'] ?? my['0'];
  final ls = [
    ...(own is List ? [...own.map((x) => jm(x)), ...def.where((d) => !own.any((o) => jm(o)['k'] == d['k']))] : def).map((x) => {...x}),
  ];
  final r = await showDialog<String>(
    context: c,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => AlertDialog(
        title: const Text('Abschnitte anordnen'),
        content: SizedBox(
          width: 400,
          child: ListView(
            shrinkWrap: true,
            children: [
              Mut(kid > 0 ? 'Gilt für diesen Einsatztyp.' : 'Gilt ohne Einsatz.'),
              for (var i = 0; i < ls.length; i++)
                Row(
                  children: [
                    Checkbox(value: jb(ls[i]['on']), onChanged: (v) => set(() => ls[i]['on'] = v == true ? 1 : 0)),
                    Expanded(child: Text(js(t[js(ls[i]['k'])]))),
                    IconButton(visualDensity: VisualDensity.compact, icon: const Icon(Icons.arrow_upward, size: 18), onPressed: i == 0 ? null : () => set(() => ls.insert(i - 1, ls.removeAt(i)))),
                    IconButton(
                      visualDensity: VisualDensity.compact,
                      icon: const Icon(Icons.arrow_downward, size: 18),
                      onPressed: i == ls.length - 1 ? null : () => set(() => ls.insert(i + 1, ls.removeAt(i))),
                    ),
                  ],
                ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d), child: const Text('Abbrechen')),
          TextButton(onPressed: () => Navigator.pop(d, 'def'), child: const Text('Vorgabe')),
          FilledButton(onPressed: () => Navigator.pop(d, 'ok'), child: const Text('Speichern')),
        ],
      ),
    ),
  );
  if (r == null) return;
  try {
    await s.api.call(
      'doc/view',
      body: {
        'kid': kid,
        if (r == 'ok') 'secs': ls.map((x) => {'k': x['k'], 'on': jb(x['on']) ? 1 : 0}).toList(),
      },
    );
    s.toast('Gespeichert.');
    await done();
  } catch (e) {
    s.fail(e);
  }
}

// ---------- Ansicht eines Eintrags ----------
Future<void> docOpen(BuildContext c, IncStore s, int id, {VoidCallback? onChange, int page = 0}) async {
  J d;
  try {
    d = await docLoad(s, id);
  } catch (e) {
    s.fail(e);
    return;
  }
  if (!c.mounted) return;
  await Navigator.of(c).push(
    MaterialPageRoute(
      builder: (_) => DocPage(s: s, doc: d),
    ),
  );
  onChange?.call();
}

class DocPage extends StatefulWidget {
  const DocPage({super.key, required this.s, required this.doc});
  final IncStore s;
  final J doc;
  @override
  State<DocPage> createState() => _DocPageState();
}

class _DocPageState extends State<DocPage> {
  IncStore get s => widget.s;
  late J d = widget.doc;
  List<int>? _bytes;
  String? _err;
  bool _busy = false;

  int get id => ji(d['id'])!;
  J? get file => d['file'] is Map ? jm(d['file']) : null;
  bool get off => jb(d['off']);

  @override
  void initState() {
    super.initState();
    final sh = js(file?['show']);
    if (js(d['kind']) == 'file' && file != null && (sh == 'img' || sh == 'text')) _fetch();
  }

  Future<List<int>> _get() async {
    if (_bytes != null) return _bytes!;
    if (off) {
      final b = await _offBytes(s, id, ji(d['ver']) ?? 1);
      if (b == null) throw ApiError('Datei nicht offline gespeichert');
      return _bytes = b;
    }
    try {
      return _bytes = await docBytes(s, id);
    } on ApiError catch (e) {
      if (!e.offline) rethrow;
      final b = await _offBytes(s, id, ji(d['ver']) ?? 1);
      if (b == null) rethrow;
      return _bytes = b;
    }
  }

  Future<void> _fetch() async {
    try {
      await _get();
      if (mounted) setState(() {});
    } catch (e) {
      if (mounted) setState(() => _err = '$e');
    }
  }

  /// Datei an eine andere App geben (PDF-Betrachter, Office …) bzw. speichern
  Future<void> _share() async {
    setState(() => _busy = true);
    try {
      final b = await _get(), f = File('${(await getTemporaryDirectory()).path}/${js(file!['name']).replaceAll(RegExp(r'[\\/:*?"<>|]'), '_')}');
      await f.writeAsBytes(b);
      await SharePlus.instance.share(ShareParams(files: [XFile(f.path)], title: js(d['title'])));
    } catch (e) {
      s.fail(e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reload() async {
    try {
      final n = await docLoad(s, id);
      if (mounted) setState(() => d = n);
    } catch (_) {}
  }

  Future<void> _ack() async {
    if (!await confirmDlg(context, 'Die Bestätigung gilt für Version ${ji(d['ver']) ?? 1}.', title: '«${js(d['title'])}» gelesen und verstanden?', ok: 'Bestätigen')) return;
    try {
      await s.api.call('doc/ack', body: {'id': id});
      s.toast('Danke, bestätigt.');
      await _reload();
      unawaited(docReload(s));
    } catch (e) {
      s.fail(e);
    }
  }

  Widget? _ackBox() {
    if (!jb(d['ack']) || off) return null;
    final m = d['mine'] is Map ? jm(d['mine']) : null;
    Widget box(Color col, List<Widget> ch) => Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: col.withValues(alpha: .15),
        border: Border.all(color: col),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Wrap(spacing: 10, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: ch),
    );
    if (m != null && js(m['st']) != 'ok') {
      return box(js(m['st']) == 'over' ? accRed : accOrange, [
        Text('${js(m['st']) == 'over' ? '⚠ Überfällig: ' : ''}Bitte lesen und bestätigen${m['due'] != null ? ' bis ${dkDate(jn(m['due']))}' : ''}.'),
        FilledButton(onPressed: _ack, child: const Text('Gelesen und verstanden')),
      ]);
    }
    if (d['my_ack'] != null) return box(okGreen, [Text('✓ Bestätigt am ${dkDate(jn(d['my_ack']))}${m == null ? ' (freiwillig)' : ''}')]);
    return box(const Color(0xFF1D7FD1), [const Text('Für alle, die mögen: Lesebestätigung freiwillig.'), OutlinedButton(onPressed: _ack, child: const Text('Bestätigen'))]);
  }

  List<Widget> _body() {
    switch (js(d['kind'])) {
      case 'page':
        final w = dkMd(context, js(d['body']), d['imgs'] is Map ? jm(d['imgs']) : {}, openDoc: (i) => docOpen(context, s, i));
        return w.isEmpty ? [const Mut('Diese Seite ist noch leer.')] : w;
      case 'link':
        return [
          Text(js(d['descr']).isNotEmpty ? js(d['descr']) : 'Externer Link'),
          const SizedBox(height: 10),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton(
              onPressed: () => launchUrl(Uri.parse(js(d['url'])), mode: LaunchMode.externalApplication),
              child: Text('Öffnen: ${js(d['url']).replaceFirst(RegExp(r'^https?://'), '').characters.take(60)}'),
            ),
          ),
        ];
    }
    final f = file;
    if (f == null) return [const Mut('Noch keine Datei hochgeladen.')];
    final sh = js(f['show']);
    if (sh == 'img' || sh == 'text') {
      if (_err != null) return [Mut(_err!)];
      if (_bytes == null) {
        return [const Center(child: Padding(padding: EdgeInsets.all(20), child: CircularProgressIndicator()))];
      }
      if (sh == 'img') return [InteractiveViewer(maxScale: 5, child: Image.memory(Uint8List.fromList(_bytes!)))];
      final t = utf8.decode(_bytes!, allowMalformed: true);
      return js(f['ext']) == 'md' ? dkMd(context, t, {}) : [SelectableText(t.length > 500000 ? t.substring(0, 500000) : t, style: const TextStyle(fontFamily: 'monospace', fontSize: 12))];
    }
    return [
      Text('${js(f['name'])} (${dkSize(jn(f['bytes']))})'),
      const SizedBox(height: 6),
      Mut(sh == 'pdf' ? 'Öffnet im PDF-Betrachter des Geräts.' : 'Öffnet in einer passenden App des Geräts.'),
      const SizedBox(height: 10),
      Align(
        alignment: Alignment.centerLeft,
        child: FilledButton.icon(onPressed: _busy ? null : _share, icon: const Icon(Icons.open_in_new), label: Text(_busy ? 'Wird geladen …' : 'Öffnen')),
      ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final path = jl(d['path']).map((p) => js(p['name'])).join(' › ');
    final ab = _ackBox();
    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (path.isNotEmpty) Text(path, style: const TextStyle(fontSize: 12)),
            Text('${dkIco(d)} ${js(d['title'])}', overflow: TextOverflow.ellipsis),
          ],
        ),
        actions: [
          if (!off)
            IconButton(
              tooltip: jb(d['fav']) ? 'Aus den Favoriten entfernen' : 'Zu den Favoriten',
              icon: Text(jb(d['fav']) ? '★' : '☆', style: const TextStyle(fontSize: 22, color: accOrange)),
              onPressed: () async {
                try {
                  await s.api.call('doc/fav', body: {'id': id, 'on': jb(d['fav']) ? 0 : 1});
                  setState(() => d['fav'] = jb(d['fav']) ? 0 : 1);
                  unawaited(docReload(s));
                } catch (e) {
                  s.fail(e);
                }
              },
            ),
          if (js(d['kind']) == 'file' && file != null) IconButton(tooltip: 'Teilen / Speichern', icon: const Icon(Icons.share), onPressed: _busy ? null : _share),
          if (jb(d['sug']) && !off)
            IconButton(
              tooltip: 'Vorschlagen',
              icon: const Icon(Icons.rate_review_outlined),
              onPressed: () => docSugDlg(context, s, folderId: ji(d['folder_id']), doc: d),
            ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(14),
        children: [
          Mut(
            '${dkKindTxt(d)}${(ji(d['ver']) ?? 1) > 1 ? ' · Version ${d['ver']}' : ''}${d['updated'] != null ? ' · Stand ${dkDate(jn(d['updated']))}' : ''}${jb(d['review']) ? ' · Überprüfung fällig' : ''}${off ? ' · offline gespeichert' : ''}',
            size: 12,
          ),
          const SizedBox(height: 8),
          if (js(d['descr']).isNotEmpty && js(d['kind']) != 'link') Padding(padding: const EdgeInsets.only(bottom: 8), child: Mut(js(d['descr']))),
          ?ab,
          ..._body(),
        ],
      ),
    );
  }
}

// ---------- Vorschlag an die Redaktion (dkSugDlg; Datei nur im Browser) ----------
Future<void> docSugDlg(BuildContext c, IncStore s, {int? folderId, J? doc}) async {
  final tc = TextEditingController(), nc = TextEditingController();
  final ok = await showDialog<bool>(
    context: c,
    builder: (d) => AlertDialog(
      title: Text(doc != null ? 'Vorschlag zu «${js(doc['title'])}»' : 'Neuen Eintrag vorschlagen'),
      content: SizedBox(
        width: 420,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Mut(
              'Die Redaktion des Ordners erhält deinen Vorschlag im Eingang und entscheidet, ob sie ihn übernimmt. Du wirst benachrichtigt. Dateien (z. B. eine neue Version) schlägst du im Browser vor.',
            ),
            if (doc == null)
              TextField(
                controller: tc,
                maxLength: 200,
                decoration: const InputDecoration(labelText: 'Titel', hintText: 'z. B. Einsatzplan Reuss Luzern'),
              ),
            TextField(
              controller: nc,
              maxLines: 4,
              maxLength: 4000,
              decoration: InputDecoration(labelText: 'Mitteilung an die Redaktion', hintText: doc != null ? 'Was ist falsch oder veraltet?' : 'Worum geht es? Bei einer Seite: der Text.'),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(d, false), child: const Text('Abbrechen')),
        FilledButton(onPressed: () => Navigator.pop(d, true), child: const Text('Senden')),
      ],
    ),
  );
  if (ok != true) return;
  try {
    await s.api.call('doc/sug', body: {'folder_id': folderId, 'doc_id': doc?['id'], 'kind': doc != null ? 'note' : 'new', 'title': doc != null ? null : tc.text, 'note': nc.text});
    s.toast('Vorschlag an die Redaktion gesendet.');
  } catch (e) {
    s.fail(e);
  }
}

// ---------- Menü: Karte «Unterlagen» (docCard) ----------
Widget? docCard(BuildContext c, IncStore s, MapCtl map) {
  if (!docBtn(s)) return null;
  final n = ji(docCfg(s)['menu_n']) ?? 5, ack = docAcks(s);
  final kd = s.inc == null ? <J>[] : jl(docInc(s)['list']).where((d) => jb(d['kind_hit']) && !ack.any((a) => a['id'] == d['id'])).take(n).toList();
  if (ack.isEmpty && kd.isEmpty) return null;
  Widget row(J d, String ic) => InkWell(
    onTap: () => docOpen(c, s, ji(d['id'])!, onChange: () => docReload(s)),
    child: Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          SizedBox(width: 26, child: Text(ic)),
          Expanded(
            child: Wrap(
              spacing: 6,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(js(d['title']), style: const TextStyle(fontWeight: FontWeight.bold)),
                if (d['st'] != null)
                  Chip2('${js(d['st']) == 'over' ? 'überfällig' : 'bestätigen'}${d['due'] != null ? ' bis ${dkDate(jn(d['due']))}' : ''}', color: js(d['st']) == 'over' ? accRed : accOrange),
              ],
            ),
          ),
        ],
      ),
    ),
  );
  return MCard(
    title: '📚 Unterlagen',
    buttons: [
      OutlinedButton(
        onPressed: () {
          map.closeMenu();
          map.openPanel('docs');
        },
        child: const Text('Alle Dokumente'),
      ),
    ],
    children: [
      if (ack.isNotEmpty) ...[
        const Text('Lesen und bestätigen', style: TextStyle(fontWeight: FontWeight.bold)),
        ...ack.take(n).map((d) => row(d, '✍️')),
        if (ack.length > n) Mut('… und ${ack.length - n} weitere'),
      ],
      if (kd.isNotEmpty) ...[
        const Padding(
          padding: EdgeInsets.only(top: 6),
          child: Text('Zu diesem Einsatz', style: TextStyle(fontWeight: FontWeight.bold)),
        ),
        ...kd.map((d) => row(d, dkIco(d))),
      ],
    ],
  );
}

// ---------- fester Standort: Knopf im Info-Blatt (placeDocBtn) ----------
List<J> placeDocs(IncStore s, J p) => !docBtn(s) ? [] : jl(docInc(s)['list']).where((d) => _ids(d['places']).contains(ji(p['id']))).toList();

// farbiges Kästchen mit der Art wie dkBadge in docs.js
const _bcol = {'pdf': Color(0xFFD93B3B), 'img': Color(0xFF2F9E62), 'text': Color(0xFF5B6B7D), 'audio': Color(0xFF8A5CD6), 'video': Color(0xFF8A5CD6), 'dl': Color(0xFF5B6B7D), 'page': Color(0xFF2B7DE9), 'link': Color(0xFF178A96)};
Widget dkBadge(J d) {
  final k = js(d['kind']), f = d['file'] is Map ? jm(d['file']) : null;
  final t = k == 'page' ? 'TEXT' : (k == 'link' ? 'LINK' : (f != null ? js(f['ext']).toUpperCase() : '–'));
  return Container(
      constraints: const BoxConstraints(minWidth: 44),
      height: 38,
      padding: const EdgeInsets.symmetric(horizontal: 5),
      alignment: Alignment.center,
      decoration: BoxDecoration(color: _bcol[k == 'file' ? (f != null && js(f['show']).isNotEmpty ? js(f['show']) : 'dl') : k] ?? _bcol['dl'], borderRadius: BorderRadius.circular(6)),
      child: Text(t.length > 4 ? t.substring(0, 4) : t, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w800)));
}

String dkArt(J d) => switch (js(d['kind'])) { 'page' => 'Seite', 'link' => 'Link', _ => d['file'] is Map ? js(jm(d['file'])['ext']).toUpperCase() : 'Datei' };

/// Dokumente im Info-Blatt eines festen Standorts (Konzept #229, Bild 3): Art, Titel, offline, Stand; Antippen öffnet das Dokument.
/// c = Kontext der Karte (bleibt gültig), sc = Kontext des Blatts (wird geschlossen)
List<Widget> placeDocsBox(BuildContext c, BuildContext sc, IncStore s, J p) {
  final ds = placeDocs(s, p);
  if (ds.isEmpty) return [];
  return [
    const Padding(padding: EdgeInsets.only(top: 12), child: Divider(height: 1)),
    const Padding(padding: EdgeInsets.only(top: 8, bottom: 2), child: Mut('📚 Dokumente zu diesem Standort')),
    for (final d in ds)
      Padding(
        padding: const EdgeInsets.only(top: 6),
        child: Material(
          color: Theme.of(sc).colorScheme.surfaceContainerHighest,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8), side: BorderSide(color: Theme.of(sc).dividerColor)),
          child: InkWell(
            borderRadius: BorderRadius.circular(8),
            onTap: () {
              Navigator.pop(sc);
              docOpen(c, s, ji(d['id'])!, onChange: () => docReload(s));
            },
            child: Padding(
              padding: const EdgeInsets.all(8),
              child: Row(children: [
                dkBadge(d),
                const SizedBox(width: 10),
                Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(js(d['title'])),
                  Mut(
                      [
                        ji(d['offline']) == 2 || (ji(d['offline']) == 1 && ji(d['fav']) == 1) ? '⬇ offline' : dkArt(d),
                        if (d['updated'] != null) 'Stand ${dkDate(jn(d['updated']))}',
                        if (d['mine'] is Map && js(jm(d['mine'])['st']) != 'ok') js(jm(d['mine'])['st']) == 'over' ? 'überfällig' : 'bestätigen',
                      ].join(' · '),
                      size: 12),
                ])),
              ]),
            ),
          ),
        ),
      ),
  ];
}

// ---------- Leitung: Dokumente für Externe (docExtCard) ----------
String docExtUrl(IncStore s, String t) => '${s.api.base}dok.html?k=$t';

Future<void> docShare(IncStore s, String t, String title) => SharePlus.instance.share(ShareParams(text: '$title: ${docExtUrl(s, t)}', subject: 'Dokument'));

Widget? docExtCard(BuildContext c, IncStore s) {
  final d = docInc(s), cf = d['ext_cfg'] is Map ? jm(d['ext_cfg']) : null;
  if (d['ext'] is! List || cf == null || !s.feat('docs')) return null;
  final ex = jl(d['ext']);
  return MCard(
    title: '📚 Dokumente für Externe',
    buttons: [FilledButton(onPressed: () => _docExtAdd(c, s, cf), child: const Text('+ Dokument freigeben'))],
    children: [
      Mut(
        'Gib einzelne Unterlagen an Externe ohne App weiter: ${[if (jb(cf['guest'])) 'auf der Funk-Gastseite', if (jb(cf['link'])) 'über einen eigenen Link'].join(' oder ')}. Die Freigabe gilt für die Fassung von jetzt, bis zum Einsatzende oder bis du sie zurücknimmst.',
      ),
      for (final x in ex)
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(js(x['title']), style: const TextStyle(fontWeight: FontWeight.bold)),
                    Mut(
                      '${js(x['token']).isNotEmpty ? 'eigener Link' : (x['guest_id'] != null ? 'Funk-Link «${js(x['guest_label'])}»' : 'alle Funk-Links')} · ${ji(x['hits']) ?? 0} Abruf${ji(x['hits']) == 1 ? '' : 'e'}',
                      size: 12,
                    ),
                  ],
                ),
              ),
              if (js(x['token']).isNotEmpty) smallBtn('Teilen', () => docShare(s, js(x['token']), js(x['title']))),
              IconButton(
                tooltip: 'Freigabe zurücknehmen',
                icon: const Icon(Icons.close),
                onPressed: () async {
                  if (await confirmDlg(c, 'Externe sehen das Dokument danach nicht mehr.', title: 'Freigabe von «${js(x['title'])}» zurücknehmen?', ok: 'Zurücknehmen', danger: true)) {
                    await s.act('incident/doc/ext/remove', {'id': x['id']});
                  }
                },
              ),
            ],
          ),
        ),
    ],
  );
}

Future<void> _docExtAdd(BuildContext c, IncStore s, J cf) async {
  J L;
  try {
    L = await s.api.call('docs', query: {'kid': '${docKid(s)}'});
  } catch (e) {
    return s.fail(e);
  }
  final ds = jl(L['docs']).where((x) => jb(x['active']) && (js(x['kind']) != 'file' || x['file'] != null)).toList();
  if (ds.isEmpty) return s.toast('Keine Dokumente zum Freigeben.', 'info');
  final gl = jl(jm(s.st?['voice'])['guest_links']);
  int doc = ji(ds[0]['id'])!;
  String w = jb(cf['guest']) ? 'g' : 'l';
  int? g;
  if (!c.mounted) return;
  final ok = await showDialog<bool>(
    context: c,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => AlertDialog(
        title: const Text('Dokument für Externe freigeben'),
        content: SizedBox(
          width: 420,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              DropdownButtonFormField<int>(
                initialValue: doc,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Dokument'),
                items: [
                  for (final x in ds)
                    DropdownMenuItem(
                      value: ji(x['id']),
                      child: Text(js(x['title']), overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: (v) => set(() => doc = v ?? doc),
              ),
              RadioGroup<String>(
                groupValue: w,
                onChanged: (v) => set(() => w = v ?? w),
                child: Column(
                  children: [
                    if (jb(cf['guest'])) const RadioListTile<String>(contentPadding: EdgeInsets.zero, value: 'g', title: Text('Auf der Funk-Gastseite')),
                    if (jb(cf['link'])) const RadioListTile<String>(contentPadding: EdgeInsets.zero, value: 'l', title: Text('Eigener Link (ohne Anmeldung, zum Teilen)')),
                  ],
                ),
              ),
              if (jb(cf['guest']) && w == 'g')
                DropdownButtonFormField<int?>(
                  initialValue: g,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Für'),
                  items: [
                    const DropdownMenuItem(value: null, child: Text('Alle Funk-Links')),
                    for (final x in gl) DropdownMenuItem(value: ji(x['id']), child: Text(js(x['label']))),
                  ],
                  onChanged: (v) => set(() => g = v),
                ),
              const Mut('Nur Ordner, die die Verwaltung für Externe freigegeben hat.'),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d, false), child: const Text('Abbrechen')),
          FilledButton(onPressed: () => Navigator.pop(d, true), child: const Text('Freigeben')),
        ],
      ),
    ),
  );
  if (ok != true) return;
  final r = await s.act('incident/doc/ext/save', {'doc_id': doc, 'guest_id': w == 'g' ? g : null, 'link': w == 'l' ? 1 : 0});
  if (r == null) return;
  if (js(r['token']).isNotEmpty) {
    await docShare(s, js(r['token']), js(ds.firstWhere((x) => ji(x['id']) == doc)['title']));
  } else {
    s.toast('Auf der Funk-Gastseite freigegeben.');
  }
}

// ---------- Profil: Karte «Dokumente» (docPrefs) ----------
class DocPrefs extends StatefulWidget {
  const DocPrefs({super.key, required this.s, required this.openPanel});
  final IncStore s;
  final VoidCallback openPanel;
  @override
  State<DocPrefs> createState() => _DocPrefsState();
}

class _DocPrefsState extends State<DocPrefs> {
  IncStore get s => widget.s;
  bool _busy = false;

  Future<void> _sugs() async {
    J r;
    try {
      r = await s.api.call('doc/sugs', query: {'mine': '1'});
    } catch (e) {
      return s.fail(e);
    }
    const st = {'open': 'offen', 'taken': 'übernommen', 'done': 'erledigt', 'rejected': 'abgelehnt'};
    final l = jl(r['list']);
    if (!mounted) return;
    await showDialog<void>(
      context: context,
      builder: (d) => AlertDialog(
        title: const Text('Meine Vorschläge'),
        content: SizedBox(
          width: 420,
          child: l.isEmpty
              ? const Mut('Noch keine Vorschläge. Vorschlagen kannst du im Fenster «Dokumente» bei einem Eintrag oder Ordner.')
              : ListView(
                  shrinkWrap: true,
                  children: [
                    for (final x in l)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 4),
                        child: Row(
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    js(x['doc_title']).isNotEmpty ? js(x['doc_title']) : (js(x['title']).isNotEmpty ? js(x['title']) : 'Neuer Eintrag'),
                                    style: const TextStyle(fontWeight: FontWeight.bold),
                                  ),
                                  Mut('${js(x['folder'])} · ${fmtDT(jn(x['at']))} · ${st[js(x['state'])] ?? js(x['state'])}${js(x['reason']).isNotEmpty ? ' – ${js(x['reason'])}' : ''}', size: 12),
                                ],
                              ),
                            ),
                            if (js(x['state']) == 'open')
                              smallBtn('Zurückziehen', () async {
                                if (!await confirmDlg(d, 'Die Redaktion sieht ihn danach nicht mehr.', title: 'Vorschlag zurückziehen?', ok: 'Zurückziehen')) return;
                                try {
                                  await s.api.call('doc/sug/remove', body: {'id': x['id']});
                                  s.toast('Zurückgezogen.');
                                  if (d.mounted) Navigator.pop(d);
                                } catch (e) {
                                  s.fail(e);
                                }
                              }),
                          ],
                        ),
                      ),
                  ],
                ),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(d), child: const Text('Schliessen'))],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final o = jl(docMe(s)['off']), b = o.fold<num>(0, (a, x) => a + (jn(x['bytes']) ?? 0)), ack = docAcks(s);
    return MCard(
      title: 'Dokumente',
      buttons: [
        OutlinedButton(onPressed: widget.openPanel, child: const Text('Alle Dokumente')),
        OutlinedButton(
          onPressed: _busy
              ? null
              : () async {
                  setState(() => _busy = true);
                  await docOffSync(s);
                  if (mounted) setState(() => _busy = false);
                  s.toast('Offline-Stand nachgeführt.');
                },
          child: const Text('Jetzt offline speichern'),
        ),
        OutlinedButton(onPressed: _sugs, child: const Text('Meine Vorschläge')),
      ],
      children: [
        Mkv([('Offline', o.isEmpty ? 'keine' : '${o.length} Einträge (${dkSize(b)})'), ('Bestätigen', ack.isEmpty ? 'nichts offen' : '${ack.length} offen')]),
        const Mut('Offline gespeichert werden Einträge, die die Redaktion dafür markiert hat, sowie deine Favoriten, wenn der Eintrag es erlaubt.'),
      ],
    );
  }
}
