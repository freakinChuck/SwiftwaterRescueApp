// Mehrsprachigkeit (Issue #66): Deutsch, Englisch, Französisch, Italienisch – wie web/i18n.js.
// Übersetzt wird beim Anzeigen: Alle Dateien blenden Text aus material.dart aus und nutzen das Text-Widget hier, das seinen Text
// (auch Text.rich mit allen TextSpan) über das Wörterbuch assets/i18n/<Sprache>.json übersetzt (Kopie von web/i18n, gleich gehalten
// über test/i18n_test.dart). Schlüssel = deutscher Text; Einträge mit {0}, {1} … sind Vorlagen, deren Teile ebenfalls übersetzt werden,
// wenn es sie als Eintrag gibt. Texte ausserhalb von Text-Widgets (labelText, hintText, tooltip, Meldungen, Teilen) über tr()/trn().
// Sprache: eigene Wahl (SharedPreferences «lang», '' = automatisch), sonst die des Geräts, sonst Deutsch. Der Server erfährt sie über
// den Header X-Lang (api.dart) und sendet Push in dieser Sprache.
import 'dart:convert';
import 'dart:ui' as ui;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart' as m;
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

const langs = {'de': 'Deutsch', 'en': 'English', 'fr': 'Français', 'it': 'Italiano'};

class _Tp {
  final RegExp re;
  final List<int> idx;
  final String out, long;
  final int n;
  _Tp(this.re, this.idx, this.out, this.long, this.n);
}

class I18n {
  static String lang = 'de';
  static String chosen = ''; // eigene Wahl, '' = automatisch
  static final ValueNotifier<int> changed = ValueNotifier(0); // die App baut sich bei einer neuen Sprache neu auf
  static Map<String, String> _ex = {};
  static List<_Tp> _tp = [];
  static final Map<String, String?> _c = {};

  static String device() {
    for (final l in ui.PlatformDispatcher.instance.locales) {
      if (langs.containsKey(l.languageCode)) return l.languageCode;
    }
    return 'de';
  }

  /// Sprache bestimmen und Wörterbuch laden (main() und der Hintergrunddienst)
  static Future<void> load() async {
    try {
      final p = await SharedPreferences.getInstance();
      await p.reload();
      chosen = p.getString('lang') ?? '';
    } catch (_) {}
    final l = langs.containsKey(chosen) ? chosen : device();
    if (l == lang && (l == 'de' || _ex.isNotEmpty)) return;
    lang = l;
    Map<String, dynamic> d = {};
    if (l != 'de') {
      try {
        d = jsonDecode(await rootBundle.loadString('assets/i18n/$l.json')) as Map<String, dynamic>;
      } catch (_) {}
    }
    setDict(d);
  }

  /// eigene Wahl speichern ('' = automatisch) und neu laden
  static Future<void> set(String l) async {
    final p = await SharedPreferences.getInstance();
    if (l.isEmpty) {
      await p.remove('lang');
    } else {
      await p.setString('lang', l);
    }
    lang = '';
    await load();
    changed.value++;
  }

  @visibleForTesting
  static void setDict(Map<String, dynamic> d, [String? l]) {
    if (l != null) lang = l;
    _ex = {};
    _tp = [];
    _c.clear();
    final ph = RegExp(r'\{(\d+)\}');
    d.forEach((k, v) {
      if (v is! String || v.isEmpty) return;
      if (!ph.hasMatch(k)) {
        _ex[k] = v;
        return;
      }
      final lit = k.split(ph);
      final long = lit.fold('', (a, b) => b.length > a.length ? b : a);
      if (long.replaceAll(RegExp(r'[^A-Za-zÀ-ÿ]'), '').length < 3) return;
      _tp.add(_Tp(RegExp('^${lit.map(RegExp.escape).join('(.*?)')}\$', dotAll: true), ph.allMatches(k).map((x) => int.parse(x.group(1)!)).toList(), v, long,
          lit.join().length));
    });
    _tp.sort((a, b) => b.n - a.n);
  }

  static String _norm(String s) => s.replaceAll(RegExp(r'[\s ]+'), ' ').trim();

  /// ganzen Text übersetzen, null = unverändert
  static String? _tr(String s) {
    if (lang == 'de' || s.isEmpty) return null;
    if (RegExp(r'\n\s*\n').hasMatch(s)) {
      var any = false;
      final o = s.split(RegExp(r'\n\s*\n')).map((p) {
        final r = _tr(p);
        if (r != null) any = true;
        return r ?? p.trim();
      }).join('\n\n');
      return any ? o : null;
    }
    final k = _norm(s);
    if (k.length < 2 || !RegExp(r'[A-Za-zÄÖÜäöü]').hasMatch(k)) return null;
    if (_c.containsKey(k)) return _c[k];
    String? r = _ex[k];
    if (r == null) {
      // wie i18n.js: unter gleich langen Treffern die, deren Teile bekannt sind oder keine Buchstaben enthalten
      _Tp? best;
      Map<int, String>? bcap;
      var bs = -1, bn = -1;
      for (final t in _tp) {
        if (best != null && t.n < bn) break;
        if (k.length < t.n || !k.contains(t.long)) continue;
        final mm = t.re.firstMatch(k);
        if (mm == null) continue;
        final cap = <int, String>{};
        var sc = 0;
        for (var i = 0; i < t.idx.length; i++) {
          final v = mm.group(i + 1)!;
          cap[t.idx[i]] = v;
          if (_ex.containsKey(_norm(v)) || !RegExp(r'[A-Za-zÄÖÜäöü]{2}').hasMatch(v)) sc++;
        }
        if (sc > bs) {
          best = t;
          bcap = cap;
          bs = sc;
          bn = t.n;
        }
      }
      if (best != null) {
        final t = best, cap = bcap!;
        r = t.out.replaceAllMapped(RegExp(r'\{(\d+)\}'), (x) {
          final v = cap[int.parse(x.group(1)!)];
          if (v == null) return x.group(0)!;
          final t = _ex[_norm(v)];
          return t == null ? v : '${RegExp(r'^\s*').firstMatch(v)!.group(0)}$t${RegExp(r'\s*$').firstMatch(v)!.group(0)}';
        });
      }
    }
    if (r == null && k.endsWith(':') && k.length > 2) {
      final b = _tr(k.substring(0, k.length - 1));
      if (b != null) r = b + (lang == 'fr' ? ' :' : ':');
    }
    if (_c.length > 20000) _c.clear();
    _c[k] = r;
    return r;
  }

  static String tr(String s) {
    final r = _tr(s);
    if (r == null) return s;
    final lead = RegExp(r'^\s*').firstMatch(s)!.group(0)!, tail = RegExp(r'\s*$').firstMatch(s)!.group(0)!;
    return '$lead$r$tail';
  }
}

/// Sprache wählen ('' = automatisch): speichern, Dienst und Server benachrichtigen erledigen die Aufrufer über [onLang]
Future<void> langSet(String l) async {
  await I18n.set(l);
  final f = onLang;
  if (f != null) await f(l);
}

/// gesetzt in main.dart: allen angemeldeten Konten die Sprache melden (users.lang) und den Dienst neu laden
Future<void> Function(String l)? onLang;

/// Text übersetzen (für Texte ausserhalb von Text-Widgets)
String tr(String s) => I18n.tr(s);
String? trn(String? s) => s == null ? null : I18n.tr(s);

/// Titel in Grossbuchstaben («EINSATZ», «EINSATZ BEENDET»): in gewöhnlicher Schreibweise übersetzen, dann wieder gross
String trUp(String s) {
  final r = I18n.tr(s);
  if (r != s || s != s.toUpperCase() || !RegExp(r'[A-ZÄÖÜ]{2}').hasMatch(s)) return r;
  final w = s.toLowerCase();
  final c = I18n.tr(w.isEmpty ? w : w[0].toUpperCase() + w.substring(1));
  return c.toUpperCase();
}

/// Text-Widget mit Übersetzung; gleiche Parameter wie material Text
class Text extends m.StatelessWidget {
  final String? data;
  final m.InlineSpan? textSpan;
  final m.TextStyle? style;
  final m.StrutStyle? strutStyle;
  final m.TextAlign? textAlign;
  final m.TextDirection? textDirection;
  final m.Locale? locale;
  final bool? softWrap;
  final m.TextOverflow? overflow;
  final m.TextScaler? textScaler;
  final int? maxLines;
  final String? semanticsLabel;
  final m.TextWidthBasis? textWidthBasis;
  final ui.TextHeightBehavior? textHeightBehavior;
  final m.Color? selectionColor;
  const Text(String this.data,
      {super.key,
      this.style,
      this.strutStyle,
      this.textAlign,
      this.textDirection,
      this.locale,
      this.softWrap,
      this.overflow,
      this.textScaler,
      this.maxLines,
      this.semanticsLabel,
      this.textWidthBasis,
      this.textHeightBehavior,
      this.selectionColor})
      : textSpan = null;
  const Text.rich(m.InlineSpan this.textSpan,
      {super.key,
      this.style,
      this.strutStyle,
      this.textAlign,
      this.textDirection,
      this.locale,
      this.softWrap,
      this.overflow,
      this.textScaler,
      this.maxLines,
      this.semanticsLabel,
      this.textWidthBasis,
      this.textHeightBehavior,
      this.selectionColor})
      : data = null;

  static m.InlineSpan _span(m.InlineSpan s) {
    if (s is! m.TextSpan) return s;
    return m.TextSpan(
        text: trn(s.text),
        children: s.children?.map(_span).toList(),
        style: s.style,
        recognizer: s.recognizer,
        mouseCursor: s.mouseCursor,
        onEnter: s.onEnter,
        onExit: s.onExit,
        semanticsLabel: s.semanticsLabel,
        locale: s.locale,
        spellOut: s.spellOut);
  }

  @override
  m.Widget build(m.BuildContext context) {
    if (textSpan != null) {
      return m.Text.rich(_span(textSpan!),
          style: style,
          strutStyle: strutStyle,
          textAlign: textAlign,
          textDirection: textDirection,
          locale: locale,
          softWrap: softWrap,
          overflow: overflow,
          textScaler: textScaler,
          maxLines: maxLines,
          semanticsLabel: semanticsLabel,
          textWidthBasis: textWidthBasis,
          textHeightBehavior: textHeightBehavior,
          selectionColor: selectionColor);
    }
    return m.Text(I18n.tr(data!),
        style: style,
        strutStyle: strutStyle,
        textAlign: textAlign,
        textDirection: textDirection,
        locale: locale,
        softWrap: softWrap,
        overflow: overflow,
        textScaler: textScaler,
        maxLines: maxLines,
        semanticsLabel: semanticsLabel,
        textWidthBasis: textWidthBasis,
        textHeightBehavior: textHeightBehavior,
        selectionColor: selectionColor);
  }
}
