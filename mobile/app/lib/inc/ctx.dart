// Gemeinsame Bausteine der Fenster der nativen Einsatz-App: Schnittstelle zur Karte (MapCtl), Karten im Menü (mcard/mkv),
// Rückfragen und Eingaben, Navigation (navGo/navTo), Farbwahl, Admin-Bereich im Browser.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

import 'store.dart';
import 'web.dart';
import 'util.dart';

/// Was die Fenster von der Karte brauchen (umgesetzt in map_page.dart).
abstract class MapCtl {
  /// Karte auf einen Punkt schieben (Zoom bleibt; zoom setzt ihn)
  void centerOn(LatLng p, {double? zoom});

  /// Ausschnitt auf Punkte (ein Punkt: Zoom 15)
  void fitPts(List<LatLng> pts);

  /// Einsatzkraft folgen (Teilnahme-ID bzw. user_id), null = beenden
  void follow(int? userId);
  int? get followId;

  /// Punkt auf der Karte antippen lassen (Hinweis oben), null = abgebrochen
  Future<LatLng?> pickPoint(String hint);

  /// Bereich zeichnen (Eckpunkte antippen, Rückgängig/Fertig/Abbrechen), null = abgebrochen
  Future<List<LatLng>?> drawArea(String hint, {Color color});

  /// Seitenfenster öffnen bzw. schliessen: people | msgs | res | pois | rd | null
  void openPanel(String? p);

  /// Seitenmenü (☰) schliessen
  void closeMenu();

  /// Kamerafokus auf ein Teilgebiet des Suchgebiets (AreaPart.key), null = ganzes Gebiet
  void fitArea([String? key]);

  /// Einsatzgebiet zeigen (Suchgebiet und PLS, ohne Einsatzkräfte), auch wenn es erst noch berechnet wird
  void focusArea();

  /// Werkzeug Flussdistanz öffnen
  void openRd();

  /// Personeninfo (Issue #105) auf der Karte zeigen: `u<user_id>` bzw. `x<id der Externen>`, zentriert die Person
  void personInfo(String key);
}

const appBlue = Color(0xFF0B3A5B), accRed = Color(0xFFFF4D2E), accOrange = Color(0xFFFF9F1C), okGreen = Color(0xFF35C27A);

/// Farben für Markierungen und Bereiche (POI_COLORS)
const poiColors = [('#ff9f1c', 'Orange'), ('#ff4d2e', 'Rot'), ('#ffd23f', 'Gelb'), ('#35c27a', 'Grün'), ('#1d7fd1', 'Blau'), ('#c78cff', 'Violett')];

/// Karte im Menü (mcard): Titel, Inhalt, Knöpfe
class MCard extends StatelessWidget {
  const MCard({super.key, this.title, this.trailing, this.children = const [], this.buttons = const [], this.color});
  final String? title;
  final Widget? trailing;
  final List<Widget> children;
  final List<Widget> buttons;
  final Color? color;
  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 5, horizontal: 0),
      color: color,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (title != null)
            Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(children: [Expanded(child: Text(title!, style: t.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold))), ?trailing])),
          ...children,
          if (buttons.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Wrap(spacing: 8, runSpacing: 6, children: buttons)),
        ]),
      ),
    );
  }
}

/// Angaben als Zeilen Bezeichnung – Wert (mkv), leere Werte fehlen
class Mkv extends StatelessWidget {
  const Mkv(this.rows, {super.key});
  final List<(String, String)> rows;
  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: rows
            .where((r) => r.$2.isNotEmpty)
            .map((r) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  SizedBox(width: 110, child: Text(r.$1, style: t.textTheme.bodySmall?.copyWith(color: t.colorScheme.onSurfaceVariant))),
                  Expanded(child: Text(r.$2, style: t.textTheme.bodyMedium)),
                ])))
            .toList());
  }
}

/// gedämpfter Text (mut)
class Mut extends StatelessWidget {
  const Mut(this.text, {super.key, this.size});
  final String text;
  final double? size;
  @override
  Widget build(BuildContext context) => Text(text, style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant, fontSize: size ?? 13));
}

/// farbiges Kennzeichen (z. B. Einsatztyp, Spezialrolle)
class Chip2 extends StatelessWidget {
  const Chip2(this.text, {super.key, this.color, this.fg});
  final String text;
  final Color? color, fg;
  @override
  Widget build(BuildContext context) {
    final c = color ?? Theme.of(context).colorScheme.secondaryContainer;
    return Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(10)),
        child: Text(text, style: TextStyle(color: fg ?? fgOn(c), fontSize: 12, fontWeight: FontWeight.w600)));
  }
}

/// Rückfrage (confirm/dlg)
Future<bool> confirmDlg(BuildContext c, String text, {String title = 'Bitte bestätigen', String ok = 'OK', String cancel = 'Abbrechen', bool danger = false}) async {
  final r = await showDialog<bool>(
      context: c,
      builder: (d) => AlertDialog(title: Text(title), content: Text(text), actions: [
            TextButton(onPressed: () => Navigator.pop(d, false), child: Text(cancel)),
            FilledButton(style: danger ? FilledButton.styleFrom(backgroundColor: accRed) : null, onPressed: () => Navigator.pop(d, true), child: Text(ok)),
          ]));
  return r == true;
}

/// einzelne Eingabe, null = abgebrochen
Future<String?> inputDlg(BuildContext c, String title, {String label = '', String value = '', bool multi = false, TextInputType? type, String ok = 'OK', String? hint}) async {
  final t = TextEditingController(text: value);
  return showDialog<String>(
      context: c,
      builder: (d) => AlertDialog(
              title: Text(title),
              content: TextField(
                  controller: t,
                  autofocus: true,
                  keyboardType: type ?? (multi ? TextInputType.multiline : null),
                  maxLines: multi ? 5 : 1,
                  minLines: 1,
                  decoration: InputDecoration(labelText: label.isEmpty ? null : label, hintText: hint)),
              actions: [
                TextButton(onPressed: () => Navigator.pop(d), child: const Text('Abbrechen')),
                FilledButton(onPressed: () => Navigator.pop(d, t.text), child: Text(ok)),
              ]));
}

/// Formular mit mehreren Feldern in einem Dialog; build erhält setState des Dialogs. Rückgabe true = OK.
Future<bool> formDlg(BuildContext c, String title, Widget Function(BuildContext, StateSetter) build, {String ok = 'Speichern', bool Function()? valid}) async {
  final r = await showDialog<bool>(
      context: c,
      builder: (d) => StatefulBuilder(
          builder: (d2, ss) => AlertDialog(
                  title: Text(title),
                  scrollable: true,
                  content: SizedBox(width: 420, child: build(d2, ss)),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(d, false), child: const Text('Abbrechen')),
                    FilledButton(onPressed: () => (valid == null || valid()) ? Navigator.pop(d, true) : null, child: Text(ok)),
                  ])));
  return r == true;
}

/// Farbwahl als Kreise
class ColorPick extends StatelessWidget {
  const ColorPick({super.key, required this.value, required this.onChanged, this.colors = poiColors});
  final String value;
  final ValueChanged<String> onChanged;
  final List<(String, String)> colors;
  @override
  Widget build(BuildContext context) => Wrap(
      spacing: 8,
      children: colors
          .map((c) => InkWell(
              onTap: () => onChanged(c.$1),
              child: Tooltip(
                  message: c.$2,
                  child: Container(
                      width: 32,
                      height: 32,
                      decoration: BoxDecoration(
                          color: hexColor(c.$1),
                          shape: BoxShape.circle,
                          border: Border.all(color: value.toLowerCase() == c.$1 ? Theme.of(context).colorScheme.onSurface : Colors.transparent, width: 3))))))
          .toList());
}

/// Navigations-Apps (NAV_APPS)
const navApps = {'google': 'Google Maps', 'apple': 'Apple Karten', 'waze': 'Waze', 'geo': 'Andere App (Auswahl)'};

bool navOk(IncStore s, String k) {
  final l = s.me?['nav_apps'] is List ? (s.me!['nav_apps'] as List).map((e) => '$e').toList() : ['google'];
  return l.contains(k) && !(k == 'apple' && Platform.isAndroid) && !(k == 'geo' && !Platform.isAndroid);
}

String navApp(IncStore s) {
  final c = [js(s.me?['nav_app']), js(s.me?['nav_default']), ...(s.me?['nav_apps'] is List ? (s.me!['nav_apps'] as List).map((e) => '$e') : const <String>[])];
  return c.firstWhere((k) => k.isNotEmpty && navOk(s, k), orElse: () => 'google');
}

/// Ziel direkt an die Navigations-App übergeben (navGo)
Future<void> navGo(IncStore s, double lat, double lon, String name) async {
  final ll = '${lat.toStringAsFixed(6)},${lon.toStringAsFixed(6)}', q = Uri.encodeComponent(name.isEmpty ? 'Ziel' : name);
  final u = switch (navApp(s)) {
    'apple' => 'https://maps.apple.com/?daddr=$ll&q=$q',
    'waze' => 'https://waze.com/ul?ll=$ll&navigate=yes',
    'geo' => 'geo:$ll?q=$ll($q)',
    _ => 'https://www.google.com/maps/dir/?api=1&destination=$ll',
  };
  try {
    await launchUrl(Uri.parse(u), mode: LaunchMode.externalApplication);
  } catch (_) {
    s.toast('Navigations-App nicht verfügbar', 'err');
  }
}

/// Rückfrage vor dem Navigieren (navTo), für Marker ohne Popup
Future<void> navTo(BuildContext c, IncStore s, double lat, double lon, String name) async {
  if (!s.feat('nav')) return;
  final cs = s.fc(lat, lon);
  if (await confirmDlg(c, '$name\n${cs.isNotEmpty ? '$cs · ' : ''}WGS84 ${lat.toStringAsFixed(6)},${lon.toStringAsFixed(6)}',
      title: 'Navigieren', ok: '🧭 Mit ${navApps[navApp(s)]} navigieren')) {
    await navGo(s, lat, lon, name);
  }
}

/// Knopf 🧭 (navBtn): nur mit Funktion «Navigation» und Standort
Widget? navBtn(IncStore s, dynamic lat, dynamic lon, String name, {String? label}) {
  final a = jd(lat), b = jd(lon);
  if (!s.feat('nav') || a == null || b == null) return null;
  return label == null
      ? IconButton(tooltip: 'Zu $name navigieren', icon: const Text('🧭', style: TextStyle(fontSize: 18)), onPressed: () => navGo(s, a, b, name), visualDensity: VisualDensity.compact)
      : OutlinedButton(onPressed: () => navGo(s, a, b, name), child: Text('🧭 $label'));
}

/// Seite der Web-App (Verwaltung, Meine Einsätze, Rechtliches) in der App öffnen, angemeldet mit dem Konto (web.dart)
void openWeb(BuildContext context, IncStore s, String page) {
  Navigator.of(context).push(MaterialPageRoute(builder: (_) => WebPage(s: s, page: page)));
}

/// kleiner Knopf in Listen
Widget smallBtn(String t, VoidCallback? f, {bool primary = false, Color? color}) => primary
    ? FilledButton(onPressed: f, style: FilledButton.styleFrom(visualDensity: VisualDensity.compact, backgroundColor: color, padding: const EdgeInsets.symmetric(horizontal: 12)), child: Text(t))
    : OutlinedButton(onPressed: f, style: OutlinedButton.styleFrom(visualDensity: VisualDensity.compact, foregroundColor: color, padding: const EdgeInsets.symmetric(horizontal: 12)), child: Text(t));

/// Kopf eines Seitenfensters mit Titel und ✕
class PanelHead extends StatelessWidget {
  const PanelHead(this.title, {super.key, required this.onClose, this.actions = const []});
  final String title;
  final VoidCallback onClose;
  final List<Widget> actions;
  @override
  Widget build(BuildContext context) => Padding(
      padding: const EdgeInsets.fromLTRB(12, 6, 4, 0),
      child: Row(children: [
        Expanded(child: Text(title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold))),
        ...actions,
        IconButton(onPressed: onClose, icon: const Icon(Icons.close), tooltip: 'Schliessen'),
      ]));
}

/// einklappbare Gruppe mit Titel und Anzahl
class FoldGroup extends StatelessWidget {
  const FoldGroup({super.key, required this.title, required this.open, required this.onToggle, required this.children, this.count, this.trailing});
  final String title;
  final bool open;
  final VoidCallback onToggle;
  final List<Widget> children;
  final int? count;
  final Widget? trailing;
  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        InkWell(
            onTap: onToggle,
            child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Row(children: [
                  Icon(open ? Icons.expand_more : Icons.chevron_right, size: 20),
                  Expanded(child: Text('$title${count != null ? ' ($count)' : ''}', style: const TextStyle(fontWeight: FontWeight.bold))),
                  ?trailing,
                ]))),
        if (open) ...children,
      ]);
}

/// Profilbild bzw. Initialen
Widget avatar(J p, {double r = 16, Color? border}) {
  final ph = js(p['photo']);
  final n = js(p['name']).trim().split(RegExp(r'\s+')).where((x) => x.isNotEmpty).map((x) => x[0]).take(2).join().toUpperCase();
  ImageProvider? img;
  if (ph.startsWith('data:image/')) {
    try {
      img = MemoryImage(UriData.parse(ph).contentAsBytes());
    } catch (_) {}
  }
  return Container(
      decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: border ?? Colors.white, width: 2.5)),
      child: CircleAvatar(radius: r, backgroundColor: appBlue, backgroundImage: img, child: img == null ? Text(n, style: TextStyle(fontSize: r * .7, color: Colors.white)) : null));
}
