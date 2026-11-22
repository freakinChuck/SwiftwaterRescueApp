#!/usr/bin/env python3
"""Übersetzungen zusammenführen (Issue #66).

Eingabe: Dateien mit Zeilen «Nummer ‖ Englisch ‖ Französisch ‖ Italienisch» (bzw. «Nummer ‖ =» = nicht übersetzen),
die Nummer bezieht sich auf die Liste order.json (deutsche Texte in Bearbeitungsreihenfolge).
  python3 scripts/i18n/merge.py <order.json> <Datei> [<Datei> …]
Prüft die Platzhalter {n}, ergänzt web/i18n/<Sprache>.json (bestehende Einträge werden überschrieben) und kopiert
die Wörterbücher nach mobile/app/assets/i18n/. Ohne Dateien: nur sortieren und kopieren.
"""
import json, os, re, shutil, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
LANGS = ['en', 'fr', 'it']
SEP = '‖'


def load(l):
    p = os.path.join(ROOT, 'web', 'i18n', l + '.json')
    return json.load(open(p, encoding='utf-8')) if os.path.exists(p) else {}


def main():
    order = json.load(open(sys.argv[1], encoding='utf-8')) if len(sys.argv) > 1 else []
    dic = {l: load(l) for l in LANGS}
    bad = 0
    for f in sys.argv[2:]:
        for ln, line in enumerate(open(f, encoding='utf-8'), 1):
            line = line.rstrip('\n')
            if not line.strip():
                continue
            parts = [p.strip() for p in line.split(SEP)]
            try:
                i = int(parts[0])
            except ValueError:
                print(f'{f}:{ln}: keine Nummer'); bad += 1; continue
            src = order[i]
            if len(parts) == 2 and parts[1] == '=':
                continue
            if len(parts) != 4:
                print(f'{f}:{ln}: {len(parts)} Teile statt 4: {line[:80]}'); bad += 1; continue
            ph = sorted(re.findall(r'\{\d+\}', src))
            for l, t in zip(LANGS, parts[1:]):
                if sorted(re.findall(r'\{\d+\}', t)) != ph:
                    print(f'{f}:{ln} [{l}]: Platzhalter {ph} ≠ {t[:80]}'); bad += 1; continue
                if t and t != src:
                    dic[l][src] = t
    os.makedirs(os.path.join(ROOT, 'web', 'i18n'), exist_ok=True)
    dst = os.path.join(ROOT, 'mobile', 'app', 'assets', 'i18n')
    os.makedirs(dst, exist_ok=True)
    for l in LANGS:
        p = os.path.join(ROOT, 'web', 'i18n', l + '.json')
        json.dump(dict(sorted(dic[l].items())), open(p, 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
        shutil.copy(p, os.path.join(dst, l + '.json'))
        print(l, len(dic[l]), 'Einträge')
    if bad:
        print(bad, 'Fehler'); sys.exit(1)


if __name__ == '__main__':
    main()
