#!/usr/bin/env python3
# Release (Issue #175), aufgerufen von .github/workflows/release.yml: neue Version bestimmen und Release Notes in web/changes.json schreiben.
#   release.py <patch|minor|major>   (Umgebung: NOTES = zusätzliche Punkte, je Zeile einer; GH_TOKEN, GITHUB_REPOSITORY für die erzeugten Notes)
# Version: Datei VERSION. Gibt es den Tag v<VERSION> noch nicht (erstes Release), wird VERSION selbst veröffentlicht, sonst erhöht.
# Punkte: NOTES plus die Titel der gemergten PRs seit dem letzten Tag (GitHub «generate-notes»); steht die Version schon in changes.json
# (von Hand vorbereitet), bleiben deren Punkte und NOTES kommt dazu. Ausgabe für GITHUB_OUTPUT: version, prev; Datei release-notes.md für den GitHub-Release.
import datetime, json, os, re, subprocess, sys

def sh(*a, check=True):
    r = subprocess.run(a, capture_output=True, text=True)
    if check and r.returncode:
        sys.exit(f'{" ".join(a)}: {r.stderr.strip()}')
    return r.stdout.strip() if r.returncode == 0 else ''

bump = sys.argv[1] if len(sys.argv) > 1 else 'minor'
cur = open('VERSION', encoding='utf-8').read().strip()
if not re.fullmatch(r'\d+\.\d+\.\d+', cur):
    sys.exit(f'VERSION ungültig: {cur!r}')
tags = sh('git', 'tag', '--list', 'v[0-9]*').split()
prev = sh('git', 'describe', '--tags', '--abbrev=0', '--match', 'v[0-9]*', 'HEAD', check=False)
if f'v{cur}' in tags:
    ma, mi, pa = map(int, cur.split('.'))
    new = {'major': f'{ma + 1}.0.0', 'minor': f'{ma}.{mi + 1}.0', 'patch': f'{ma}.{mi}.{pa + 1}'}[bump]
else:
    new = cur
if f'v{new}' in tags:
    sys.exit(f'Tag v{new} gibt es schon')

# Titel der gemergten PRs seit dem letzten Tag
gen = ''
args = ['gh', 'api', f'repos/{os.environ["GITHUB_REPOSITORY"]}/releases/generate-notes', '-f', f'tag_name=v{new}', '-f', 'target_commitish=main', '--jq', '.body']
if prev:
    args += ['-f', f'previous_tag_name={prev}']
gen = sh(*args, check=False)
prs = []
for l in gen.splitlines():
    m = re.match(r'^\* (.+?) by @\S+ in https://\S+/pull/(\d+)\s*$', l.strip())
    if m:
        t = m.group(1).strip()
        prs.append(t if re.search(r'\(#\d+\)', t) else f'{t} (#{m.group(2)})')
notes = [l.strip().lstrip('-*• ').strip() for l in os.environ.get('NOTES', '').replace('\\n', '\n').splitlines()]
notes = [n for n in notes if n]

p = 'web/changes.json'
try:
    data = json.load(open(p, encoding='utf-8'))
except FileNotFoundError:
    data = {'versions': []}
vs = data.setdefault('versions', [])
today = datetime.date.today().isoformat()
e = next((v for v in vs if v.get('version') == new), None)
if e:  # von Hand vorbereitet: deren Punkte behalten
    e['date'] = today
    e['items'] = list(dict.fromkeys(notes + e.get('items', [])))
else:
    e = {'version': new, 'date': today, 'items': list(dict.fromkeys(notes + prs)) or ['Fehlerbehebungen und kleinere Verbesserungen.']}
    vs.insert(0, e)
vs.sort(key=lambda v: tuple(map(int, v['version'].split('.'))), reverse=True)
with open(p, 'w', encoding='utf-8') as f:
    json.dump(data, f, ensure_ascii=False, indent=2)
    f.write('\n')
with open('VERSION', 'w', encoding='utf-8') as f:
    f.write(new + '\n')

body = '## Was ist neu\n\n' + '\n'.join(f'- {t}' for t in e['items']) + '\n'
full = re.search(r'\*\*Full Changelog\*\*.*', gen)
if full:
    body += '\n' + full.group(0) + '\n'
open('release-notes.md', 'w', encoding='utf-8').write(body)
with open(os.environ.get('GITHUB_OUTPUT', '/dev/null'), 'a') as f:
    f.write(f'version={new}\nprev={prev}\n')
print(f'Version {new} (vorher {prev or "kein Tag"}), {len(e["items"])} Punkte')
