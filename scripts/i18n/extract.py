#!/usr/bin/env python3
"""Mehrsprachigkeit (Issue #66): sammelt die deutschen Texte aus Web, API und App.

Übersetzt wird nicht im Code, sondern beim Anzeigen (web/i18n.js, mobile/app/lib/i18n.dart,
PHP i18nTr): Schlüssel ist der deutsche Text, so wie er als Textknoten, Attribut (title,
placeholder …) bzw. Text-Widget erscheint. Veränderliche Teile (${…}, $var, 'a'+x+'b')
werden zu Platzhaltern {0}, {1} … («Vorlagen»).

Aufruf (im Hauptordner):
  python3 scripts/i18n/extract.py            -> scripts/i18n/strings.json (alle Texte mit Fundorten)
  python3 scripts/i18n/extract.py --missing  -> listet Texte ohne Übersetzung je Sprache
"""
import html, json, os, re, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
LANGS = ['en', 'fr', 'it']
SRC = (
    [f'web/{f}' for f in ('index.html', 'admin.html', 'public.html', 'funk.html', 'dok.html', 'legal.html', 'hilfe.html',
                          'common.js', 'demo.js', 'voice.js', 'docs.js', 'sw.js')]
    + ['web/api/index.php', 'web/api/fed.php', 'web/api/docs.php']
)
DART_DIR = 'mobile/app/lib'

PH = '\x00'  # Platzhalter-Marke im Rohtext: \x00<n>\x00


def ph(n):
    return f'{PH}{n}{PH}'


# ---------------------------------------------------------------- Prüfung, ob ein Stück Text angezeigt wird
SQL = re.compile(r'\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|FROM|WHERE|JOIN|VALUES|PRAGMA|DROP|INDEX|TABLE|ORDER BY|GROUP BY)\b')
CODE = re.compile(r'=>|\bfunction\b|\breturn\b|\bconst\b|\blet\b|;\s*$|^\s*[.#\[][\w-]|\$\(|\)\s*\{|^[\w-]+:\s*[\w#-]+;|[{}]\s*$|^\s*@media|https?://|^data:|\\[dws]|\(\?:')


def textual(s):
    raw = re.sub(PH + r'\d+' + PH, '', s)
    if not re.search(r'[A-Za-zÄÖÜäöüÀ-ÿ]{2}', raw):
        return False
    if not re.search(r'[a-zäöüß]', raw):  # nur Grossbuchstaben: Kürzel, Konstanten
        return False
    if SQL.search(raw) or CODE.search(raw):
        return False
    if re.fullmatch(r'[\w\-./:#?=&%@+*,\[\]()|^$!<>~\'"]+', raw) and not re.search(r'[äöüÄÖÜ]', raw):
        # ein Wort: nur «Grossbuchstabe + klein» (deutsches Nomen/Label) zählt, nicht ascii klein/kamelfall
        if not re.fullmatch(r'[A-ZÄÖÜ][a-zäöüß]+(?:[-/][A-ZÄÖÜa-zäöüß][a-zäöüß]+)*[.:!?]?', raw):
            return False
    if re.search(r'\b(px|rem|em|vh|vw)\b', raw) and re.search(r'\d(px|rem|em)', raw):
        return False
    return True


def norm(s):
    s = s.replace(' ', ' ')
    s = re.sub(r'[ \t\r\n]+', ' ', s).strip()
    return s


def renum(s):
    """Platzhalter je Stück neu ab 0 nummerieren und als {n} schreiben."""
    m = {}

    def r(mo):
        k = mo.group(1)
        if k not in m:
            m[k] = len(m)
        return '{%d}' % m[k]
    return re.sub(PH + r'(\d+)' + PH, r, s)


ATTRS = re.compile(r'''\b(title|placeholder|aria-label|alt|data-tip|label)\s*=\s*(?:"([^"]*)"|'([^']*)')''')
TAG = re.compile(r'<(?:[a-zA-Z][\w-]*|/[a-zA-Z][\w-]*|!--)(?:[^>"\']|"[^"]*"|\'[^\']*\')*>')


def fragments(s, htmlish):
    """Zerlegt einen Text (mit Platzhaltern) an HTML-Tags in Stücke, wie sie als Textknoten erscheinen."""
    out = []
    if htmlish and TAG.search(s):
        pos = 0
        for mo in TAG.finditer(s):
            out.append(s[pos:mo.start()])
            for a in ATTRS.finditer(mo.group(0)):
                out.append(a.group(2) if a.group(2) is not None else a.group(3))
            pos = mo.end()
        out.append(s[pos:])
        out = [html.unescape(x) for x in out]
    else:
        out = [s]
        if htmlish and '&' in s:
            out = [html.unescape(s)]
    res = []
    for x in out:
        # Zeilen mit \n\n (ask(): Frage und Erklärung) getrennt, so erscheinen sie
        for y in re.split(r'\n\s*\n', x):
            y = norm(y)
            if y and textual(y):
                res.append(renum(y))
    return res


# ---------------------------------------------------------------- JavaScript
SIMPLE = r'''[\w$.]+(?:\((?:[^()'"`]|\([^()'"`]*\))*\))?(?:(?:\.[\w$]+|\[[\w$.'"]*\])(?:\((?:[^()'"`]|\([^()'"`]*\))*\))?)*'''
REGEX_BEFORE = set('(,=:[!&|?{};+-*%<>~^')


def js_strings(src):
    """Liefert [(start, end, text_mit_platzhaltern)] für alle String-Literale (Vorlagen samt ${} verschachtelt)."""
    res = []
    i, n = 0, len(src)
    last = ''  # letztes Zeichen ausserhalb von Leerraum/Kommentaren
    lastword = ''

    def scan_template(i):
        # i zeigt hinter `
        buf, k = [], 0
        while i < n:
            c = src[i]
            if c == '\\':
                buf.append(unesc(src[i:i + 2], src, i)); i += 2; continue
            if c == '`':
                return i + 1, ''.join(buf)
            if c == '$' and i + 1 < n and src[i + 1] == '{':
                j = scan_code(i + 2, '}')
                buf.append(ph(k)); k += 1
                i = j
                continue
            buf.append(c); i += 1
        return i, ''.join(buf)

    def unesc(t, s, i):
        if len(t) < 2:
            return t
        c = t[1]
        return {'n': '\n', 't': '\t', 'r': '', "'": "'", '"': '"', '\\': '\\', '`': '`', '$': '$'}.get(c, c)

    def scan_str(i, q):
        buf = []
        while i < n:
            c = src[i]
            if c == '\\':
                if src[i + 1] == 'u' and re.match(r'[0-9a-fA-F]{4}', src[i + 2:i + 6]):
                    buf.append(chr(int(src[i + 2:i + 6], 16))); i += 6; continue
                buf.append(unesc(src[i:i + 2], src, i)); i += 2; continue
            if c == q or c == '\n':
                return i + 1, ''.join(buf)
            buf.append(c); i += 1
        return i, ''.join(buf)

    def scan_code(i, end):
        nonlocal last, lastword
        depth = 0
        while i < n:
            c = src[i]
            if c in ' \t\r\n':
                i += 1; continue
            if c == '/' and src[i + 1:i + 2] == '/':
                j = src.find('\n', i); i = n if j < 0 else j; continue
            if c == '/' and src[i + 1:i + 2] == '*':
                j = src.find('*/', i + 2); i = n if j < 0 else j + 2; continue
            if c in '\'"':
                s0 = i; i, t = scan_str(i + 1, c); res.append((s0, i, t)); last = 'x'; continue
            if c == '`':
                s0 = i; i, t = scan_template(i + 1); res.append((s0, i, t)); last = 'x'; continue
            if c == '/' and (last in REGEX_BEFORE or last == '' or lastword in ('return', 'typeof', 'case')):
                # Regex-Literal überspringen
                j = i + 1; cls = False
                while j < n and src[j] != '\n':
                    if src[j] == '\\':
                        j += 2; continue
                    if src[j] == '[':
                        cls = True
                    elif src[j] == ']':
                        cls = False
                    elif src[j] == '/' and not cls:
                        break
                    j += 1
                i = j + 1
                while i < n and src[i].isalpha():
                    i += 1
                last = 'x'; continue
            if end and c == '{':
                depth += 1
            if end and c == '}':
                if depth == 0:
                    return i + 1
                depth -= 1
            if c.isalnum() or c in '_$':
                j = i
                while j < n and (src[j].isalnum() or src[j] in '_$'):
                    j += 1
                lastword = src[i:j]; last = 'x'; i = j; continue
            last = c; lastword = ''
            i += 1
        return i

    scan_code(0, None)
    res.sort()
    return res


def merge_concat(src, lits, op, simple):
    """'a'+x+'b' bzw. 'a'.$x.'b' zu einer Vorlage zusammenführen. Liefert Liste von Texten."""
    out, used = [], set()
    plus = re.compile(r'\s*' + re.escape(op) + r'\s*')
    i = 0
    while i < len(lits):
        s0, e0, t0 = lits[i]
        parts = [t0]; k = 0; e = e0; j = i
        merged = False
        # Ausdruck vor dem ersten Literal: x+'a'
        pre = re.search(r'(' + simple + r')\s*' + re.escape(op) + r'\s*$', src[max(0, s0 - 120):s0])
        if pre and not re.fullmatch(r'\d+|[\'"`].*', pre.group(1)) and src[max(0, s0 - 120):s0][:pre.start()][-1:] not in ('+', '.') \
                and textual(t0) and not TAG.search(t0):
            parts = [ph(90), t0]; merged = True
        while True:
            m = plus.match(src, e)
            if not m:
                break
            m2 = re.compile(simple).match(src, m.end())
            if j + 1 < len(lits) and lits[j + 1][0] == m.end():
                # direkt nächstes Literal: 'a'+'b'
                j += 1; parts.append(lits[j][2]); e = lits[j][1]; merged = True; continue
            if not m2 or m2.end() == m.end():
                break
            m3 = plus.match(src, m2.end())
            parts.append(ph(91 + k)); k += 1; merged = True
            if m3 and j + 1 < len(lits) and lits[j + 1][0] == m3.end():
                j += 1; parts.append(lits[j][2]); e = lits[j][1]; continue
            break
        txt = ''.join(parts)
        if merged:
            out.append(txt)
            for q in range(i, j + 1):
                used.add(q)
        i = j + 1
    for q, (_, _, t) in enumerate(lits):
        if q not in used:
            out.append(t)
    return out


def from_js(src):
    lits = js_strings(src)
    texts = merge_concat(src, lits, '+', SIMPLE)
    res = []
    for t in texts:
        res += fragments(t, True)
    return res


def from_html(src):
    res = []
    # Skripte
    for mo in re.finditer(r'<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>', src, re.S):
        res += from_js(mo.group(1))
    body = re.sub(r'<script[^>]*>.*?</script>|<style[^>]*>.*?</style>|<!--.*?-->', ' ', src, flags=re.S)
    res += fragments(body, True)
    return res


# ---------------------------------------------------------------- PHP
PSIMPLE = r'''\$?[\w]+(?:\((?:[^()'"]|\([^()'"]*\))*\))?(?:(?:->[\w]+|\[[\w$'"]*\])(?:\((?:[^()'"]|\([^()'"]*\))*\))?)*'''


def php_strings(src):
    res = []
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        if c == '/' and src[i + 1:i + 2] == '/' or c == '#' and src[i + 1:i + 2] != '[':
            j = src.find('\n', i); i = n if j < 0 else j; continue
        if c == '/' and src[i + 1:i + 2] == '*':
            j = src.find('*/', i + 2); i = n if j < 0 else j + 2; continue
        if c == "'":
            s0 = i; i += 1; buf = []
            while i < n and src[i] != "'":
                if src[i] == '\\' and src[i + 1] in "'\\":
                    buf.append(src[i + 1]); i += 2; continue
                buf.append(src[i]); i += 1
            i += 1; res.append((s0, i, ''.join(buf))); continue
        if c == '"':
            s0 = i; i += 1; buf = []; k = 0
            while i < n and src[i] != '"':
                ch = src[i]
                if ch == '\\':
                    buf.append({'n': '\n', 't': '\t', '"': '"', '\\': '\\', '$': '$', 'r': ''}.get(src[i + 1], '\\' + src[i + 1])); i += 2; continue
                if ch == '{' and src[i + 1:i + 2] == '$':
                    d = 0; j = i
                    while j < n:
                        if src[j] == '{':
                            d += 1
                        elif src[j] == '}':
                            d -= 1
                            if d == 0:
                                break
                        j += 1
                    buf.append(ph(k)); k += 1; i = j + 1; continue
                if ch == '$' and re.match(r'[A-Za-z_]', src[i + 1:i + 2]):
                    m = re.compile(r'\$[A-Za-z_]\w*(?:\[[^\]]*\]|->\w+)?').match(src, i)
                    buf.append(ph(k)); k += 1; i = m.end(); continue
                buf.append(ch); i += 1
            i += 1; res.append((s0, i, ''.join(buf))); continue
        i += 1
    return res


def from_php(src):
    lits = php_strings(src)
    texts = merge_concat(src, lits, '.', PSIMPLE)
    res = []
    for t in texts:
        res += fragments(t, True)
    return res


# ---------------------------------------------------------------- Dart
DSIMPLE = r'''[\w$.!?]+(?:\((?:[^()'"]|\([^()'"]*\))*\))?(?:(?:\.[\w$]+|\[[\w$.'"]*\])(?:\((?:[^()'"]|\([^()'"]*\))*\))?)*'''


def dart_strings(src):
    res = []
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        if c == '/' and src[i + 1:i + 2] == '/':
            j = src.find('\n', i); i = n if j < 0 else j; continue
        if c == '/' and src[i + 1:i + 2] == '*':
            j = src.find('*/', i + 2); i = n if j < 0 else j + 2; continue
        if c in '\'"':
            raw = i > 0 and src[i - 1] == 'r'
            s0 = i
            q = src[i:i + 3] if src[i:i + 3] in ("'''", '"""') else c
            i += len(q); buf = []; k = 0
            while i < n and src[i:i + len(q)] != q:
                ch = src[i]
                if len(q) == 1 and ch == '\n':
                    break
                if ch == '\\' and not raw:
                    buf.append({'n': '\n', 't': '\t', "'": "'", '"': '"', '\\': '\\', '$': '$', 'r': ''}.get(src[i + 1], src[i + 1])); i += 2; continue
                if ch == '$' and not raw:
                    if src[i + 1:i + 2] == '{':
                        d = 0; j = i + 1
                        while j < n:
                            if src[j] == '{':
                                d += 1
                            elif src[j] == '}':
                                d -= 1
                                if d == 0:
                                    break
                            elif src[j] in '\'"':
                                # verschachtelter String
                                qq = src[j]; j += 1
                                while j < n and src[j] != qq:
                                    j += 2 if src[j] == '\\' else 1
                            j += 1
                        inner = src[i + 2:j]
                        for _, _, t in dart_strings(inner):
                            res.append((-1, -1, t))
                        buf.append(ph(k)); k += 1; i = j + 1; continue
                    m = re.compile(r'\$[A-Za-z_]\w*').match(src, i)
                    if m:
                        buf.append(ph(k)); k += 1; i = m.end(); continue
                buf.append(ch); i += 1
            i += len(q); res.append((s0, i, ''.join(buf))); continue
        i += 1
    return [r for r in res if r[0] >= 0] + [r for r in res if r[0] < 0]


def from_dart(src):
    lits = dart_strings(src)
    pos = sorted([l for l in lits if l[0] >= 0])
    texts = merge_concat(src, pos, '+', DSIMPLE) + [l[2] for l in lits if l[0] < 0]
    res = []
    for t in texts:
        if 'import ' in t or t.startswith('package:'):
            continue
        res += fragments(t, False)
    return res


def collect():
    found = {}

    def add(lst, f):
        for s in lst:
            found.setdefault(s, set()).add(f)
    for f in SRC:
        p = os.path.join(ROOT, f)
        src = open(p, encoding='utf-8').read()
        if f.endswith('.html'):
            add(from_html(src), f)
        elif f.endswith('.php'):
            add(from_php(src), f)
        else:
            add(from_js(src), f)
    for dp, _, fs in os.walk(os.path.join(ROOT, DART_DIR)):
        for fn in fs:
            if fn.endswith('.dart'):
                p = os.path.join(dp, fn)
                add(from_dart(open(p, encoding='utf-8').read()), os.path.relpath(p, ROOT))
    # Platzhalter allein mit Satzzeichen weg
    return {k: sorted(v) for k, v in found.items() if re.search(r'[A-Za-zÄÖÜäöü]{2}', re.sub(r'\{\d+\}', '', k))}


def load(lang):
    p = os.path.join(ROOT, 'web', 'i18n', lang + '.json')
    if not os.path.exists(p):
        return {}
    return json.load(open(p, encoding='utf-8'))


if __name__ == '__main__':
    st = collect()
    if '--missing' in sys.argv:
        for l in LANGS:
            d = load(l)
            miss = [k for k in st if k not in d]
            print(l, len(miss), 'von', len(st), 'fehlen')
            if '-v' in sys.argv:
                for k in miss:
                    print('  ', k)
    else:
        out = os.path.join(ROOT, 'scripts', 'i18n', 'strings.json')
        json.dump(st, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=0, sort_keys=True)
        print(len(st), 'Texte,', sum(len(k) for k in st), 'Zeichen ->', os.path.relpath(out, ROOT))
