#!/usr/bin/env python3
"""Einmalige Umstellung der App auf lib/i18n.dart (Issue #66): Text aus material.dart ausblenden, i18n.dart einbinden,
Texte ausserhalb von Text-Widgets (labelText, hintText, tooltip …) mit trn() umschliessen und dadurch ungültige const entfernen.
Mehrfach ausführbar (schon Umgestelltes bleibt)."""
import os, re, sys

ROOT = os.path.join(os.path.dirname(__file__), '..', '..', 'mobile', 'app', 'lib')
ARGS = r'\b(labelText|hintText|helperText|errorText|suffixText|prefixText|tooltip|semanticLabel)\s*:\s*'


def expr_end(s, i):
    """Ende des Ausdrucks ab i (bis zum nächsten , ) ] } auf oberster Ebene)."""
    d = 0
    n = len(s)
    while i < n:
        c = s[i]
        if c in '\'"':
            q = s[i:i + 3] if s[i:i + 3] in ("'''", '"""') else c
            i += len(q)
            while i < n and s[i:i + len(q)] != q:
                if s[i] == '\\':
                    i += 2; continue
                if s[i] == '$' and s[i + 1:i + 2] == '{':
                    j = i + 2; dd = 1
                    while j < n and dd:
                        if s[j] == '{': dd += 1
                        elif s[j] == '}': dd -= 1
                        elif s[j] in '\'"':
                            qq = s[j]; j += 1
                            while j < n and s[j] != qq:
                                j += 2 if s[j] == '\\' else 1
                        j += 1
                    i = j; continue
                i += 1
            i += len(q); continue
        if c in '([{':
            d += 1
        elif c in ')]}':
            if d == 0:
                return i
            d -= 1
        elif c == ',' and d == 0:
            return i
        i += 1
    return i


def wrap(s):
    out = []
    pos = 0
    for mo in re.finditer(ARGS, s):
        st = mo.end()
        if st < pos:
            continue
        if s.startswith('trn(', st) or s.startswith('tr(', st) or s.startswith('null', st):
            continue
        # Deklarationen wie «final String? labelText;» überspringen
        e = expr_end(s, st)
        val = s[st:e].rstrip()
        if not val or val.startswith('{') or val.endswith('=>') or re.match(r'(final|String|this)\b', val):
            continue
        out.append(s[pos:st]); out.append('trn(' + val + ')'); pos = st + len(val)
    out.append(s[pos:])
    return ''.join(out)


def unconst(s):
    """const vor Ausdrücken entfernen, die tr(/trn( enthalten."""
    changed = True
    while changed:
        changed = False
        for mo in re.finditer(r'\bconst\s+(?=[A-Z_a-z][\w.<>, ?]*\(|\[|\{|<)', s):
            pre = s[max(0, mo.start() - 40):mo.start()]
            if re.search(r'(static|final|var|=\s*$)', pre.split('\n')[-1]) and re.search(r'\b(static|final|var)\s*$|\bstatic\s+$', pre):
                continue
            j = mo.end()
            # Konstruktor: bis zur Klammer, dann Ende der Klammer
            k = j
            while k < len(s) and s[k] not in '([{':
                k += 1
            e = k + 1
            while True:  # bis zur schliessenden Klammer (expr_end hält auch bei Kommas)
                e = expr_end(s, e)
                if e >= len(s) or s[e] in ')]}':
                    break
                e += 1
            body = s[k:e + 1]
            if re.search(r'\btrn?\(', body):
                s = s[:mo.start()] + s[mo.end():]
                changed = True
                break
    return s


def main():
    for dp, _, fs in os.walk(ROOT):
        for fn in fs:
            if not fn.endswith('.dart') or fn == 'i18n.dart':
                continue
            p = os.path.join(dp, fn)
            s = open(p, encoding='utf-8').read()
            o = s
            uses = re.search(r'\bText(\.rich)?\(|' + ARGS, s)
            if not uses:
                continue
            s = re.sub(r"import 'package:flutter/(material|widgets)\.dart';", r"import 'package:flutter/\1.dart' hide Text;", s)
            rel = os.path.relpath(os.path.join(ROOT, 'i18n.dart'), dp).replace(os.sep, '/')
            if 'i18n.dart' not in s:
                # nach dem letzten import
                ims = list(re.finditer(r"^import [^\n]+;\n", s, re.M))
                at = ims[-1].end() if ims else 0
                s = s[:at] + f"import '{rel}';\n" + s[at:]
            s = wrap(s)
            s = unconst(s)
            if s != o:
                open(p, 'w', encoding='utf-8').write(s)
                print('umgestellt', os.path.relpath(p, ROOT))


if __name__ == '__main__':
    main()
