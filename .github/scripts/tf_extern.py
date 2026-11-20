#!/usr/bin/env python3
# Release an externe TestFlight-Tester:innen geben, aufgerufen von .github/workflows/testflight-extern.yml.
# Umgebung: ASC_KEY_ID, ASC_ISSUER_ID, ASC_KEY_P8 (Inhalt der .p8-Datei oder base64), BUNDLE_ID, VERSION (z. B. 1.3.0),
#   BUILD (Build-Nummer, leer = automatisch), WIN_FROM/WIN_TO (JJMMTThhmm, Zeitfenster des Builds vom Release-Tag, leer = neuster der Version),
#   GROUP (Name der externen Gruppe), NOTES («Was zu testen ist», leer = unverändert), WAIT_MIN (Minuten auf Verarbeitung und Prüfung warten).
# Ablauf über die App Store Connect API: Build suchen → «Was zu testen ist» setzen → der Gruppe zuweisen → Tester automatisch
# benachrichtigen (autoNotifyEnabled) → zur Beta-Prüfung einreichen (falls nötig) → ist er genehmigt, Tester benachrichtigen
# (= für die Gruppe freigeben, sonst nimmt der öffentliche Link niemanden an).
import base64, json, os, sys, time, urllib.error, urllib.request

import jwt  # PyJWT mit cryptography (ES256)

API = 'https://api.appstoreconnect.apple.com'
E = os.environ.get


def note(kind, msg):
    print(f'::{kind}::{msg}', flush=True)


def fail(msg):
    note('error', msg)
    sys.exit(1)


def key():
    k = E('ASC_KEY_P8', '')
    if 'BEGIN PRIVATE KEY' not in k:
        k = base64.b64decode(k).decode()
    return k


KEY = key()
_tok = {'t': 0, 'v': ''}


def token():
    now = int(time.time())
    if now - _tok['t'] > 600:
        _tok['v'] = jwt.encode({'iss': E('ASC_ISSUER_ID'), 'iat': now, 'exp': now + 1200, 'aud': 'appstoreconnect-v1'},
                               KEY, algorithm='ES256', headers={'kid': E('ASC_KEY_ID'), 'typ': 'JWT'})
        _tok['t'] = now
    return _tok['v']


def call(method, path, body=None, ok_codes=()):
    url = path if path.startswith('http') else API + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method,
                                 headers={'Authorization': 'Bearer ' + token(), 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            raw = r.read()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as e:
        raw = e.read().decode(errors='replace')
        if e.code in ok_codes:
            return {'_status': e.code, '_body': raw}
        try:
            errs = json.loads(raw).get('errors', [])
            txt = '; '.join(f"{x.get('title', '')}: {x.get('detail', '')}" for x in errs) or raw
        except ValueError:
            txt = raw
        fail(f'{method} {path} → HTTP {e.code}: {txt}')


def q(params):
    return '?' + '&'.join(f'{k}={urllib.request.quote(str(v), safe=",")}' for k, v in params.items())


# App
bundle = E('BUNDLE_ID')
apps = call('GET', '/v1/apps' + q({'filter[bundleId]': bundle})).get('data', [])
if not apps:
    fail(f'Keine App mit der Bundle-ID {bundle} in App Store Connect (Schlüssel mit Zugriff auf die App?)')
app = apps[0]['id']

# Gruppe
gname = E('GROUP')
groups = call('GET', '/v1/betaGroups' + q({'filter[app]': app, 'filter[name]': gname, 'limit': 50})).get('data', [])
groups = [g for g in groups if g['attributes'].get('name') == gname]
if not groups:
    allg = call('GET', '/v1/betaGroups' + q({'filter[app]': app, 'limit': 50})).get('data', [])
    fail(f'Keine TestFlight-Gruppe «{gname}». Vorhanden: ' + ', '.join(g['attributes']['name'] for g in allg))
grp = groups[0]
if grp['attributes'].get('isInternalGroup'):
    fail(f'«{gname}» ist eine interne Gruppe; hier geht es um externe Tester:innen')
link = grp['attributes'].get('publicLink') if grp['attributes'].get('publicLinkEnabled') else None

# Build
ver, want = E('VERSION'), E('BUILD', '').strip()
wfrom, wto = E('WIN_FROM', '').strip(), E('WIN_TO', '').strip()
wait_s = max(0, int(E('WAIT_MIN', '0') or 0)) * 60
t0 = time.time()


def find_build():
    p = {'filter[app]': app, 'filter[preReleaseVersion.version]': ver, 'filter[expired]': 'false',
         'sort': '-uploadedDate', 'limit': 50, 'fields[builds]': 'version,processingState,uploadedDate,expired'}
    if want:
        p['filter[version]'] = want
    bs = call('GET', '/v1/builds' + q(p)).get('data', [])
    if not want and wfrom and wto:
        win = [b for b in bs if wfrom <= b['attributes']['version'] <= wto]
        if win:
            bs = win
        elif bs:
            note('warning', f'Kein Build der Version {ver} aus dem Zeitfenster des Release-Laufs ({wfrom}–{wto}); nehme den neusten')
    return bs[0] if bs else None


while True:
    b = find_build()
    st = b['attributes']['processingState'] if b else None
    if st == 'VALID':
        break
    if st in ('FAILED', 'INVALID'):
        fail(f"Build {b['attributes']['version']} ist {st} (Verarbeitung in App Store Connect fehlgeschlagen)")
    if time.time() - t0 >= wait_s:
        if b:
            fail(f"Build {b['attributes']['version']} der Version {ver} wird noch verarbeitet ({st}). Später erneut starten oder «Warten» erhöhen")
        fail(f'Kein Build der Version {ver}' + (f' mit Nummer {want}' if want else '') +
             ' in App Store Connect. Lief «Native Apps» auf dem Tag mit Upload nach TestFlight?')
    print(f'Warte auf den Build ({st or "noch nicht da"}) …', flush=True)
    time.sleep(60)

bid, bnum = b['id'], b['attributes']['version']
print(f'Build {ver} ({bnum}), hochgeladen {b["attributes"].get("uploadedDate")}', flush=True)

# «Was zu testen ist»
notes = E('NOTES', '').strip()
if notes:
    locs = call('GET', f'/v1/builds/{bid}/betaBuildLocalizations').get('data', [])
    if locs:
        for l in locs:
            call('PATCH', f"/v1/betaBuildLocalizations/{l['id']}",
                 {'data': {'type': 'betaBuildLocalizations', 'id': l['id'], 'attributes': {'whatToTest': notes[:4000]}}})
    else:
        al = call('GET', f'/v1/apps/{app}/betaAppLocalizations').get('data', [])
        loc = al[0]['attributes']['locale'] if al else 'de-DE'
        call('POST', '/v1/betaBuildLocalizations',
             {'data': {'type': 'betaBuildLocalizations', 'attributes': {'locale': loc, 'whatToTest': notes[:4000]},
                       'relationships': {'build': {'data': {'type': 'builds', 'id': bid}}}}})
    print('«Was zu testen ist» gesetzt', flush=True)

# Gruppe zuweisen (mehrfach zuweisen schadet nicht)
call('POST', f"/v1/betaGroups/{grp['id']}/relationships/builds", {'data': [{'type': 'builds', 'id': bid}]})
print(f'Build der Gruppe «{gname}» zugewiesen', flush=True)


def detail():
    return call('GET', f'/v1/builds/{bid}/buildBetaDetail')['data']


# Nach der Genehmigung automatisch an die Gruppe verteilen
d = detail()
if not d['attributes'].get('autoNotifyEnabled'):
    call('PATCH', f"/v1/buildBetaDetails/{d['id']}",
         {'data': {'type': 'buildBetaDetails', 'id': d['id'], 'attributes': {'autoNotifyEnabled': True}}})

STATE = {
    'MISSING_EXPORT_COMPLIANCE': 'Exportangaben fehlen',
    'READY_FOR_BETA_SUBMISSION': 'bereit zur Einreichung',
    'WAITING_FOR_BETA_REVIEW': 'wartet auf die Beta-Prüfung',
    'IN_BETA_REVIEW': 'in der Beta-Prüfung',
    'BETA_APPROVED': 'genehmigt, noch nicht freigegeben',
    'BETA_REJECTED': 'von Apple abgelehnt',
    'READY_FOR_BETA_TESTING': 'freigegeben (Testen)',
    'IN_BETA_TESTING': 'freigegeben (Testen)',
    'EXPIRED': 'abgelaufen',
}
submitted = notified = False
while True:
    s = detail()['attributes'].get('externalBuildState')
    print(f'Status extern: {s} ({STATE.get(s, "?")})', flush=True)
    if s in ('READY_FOR_BETA_TESTING', 'IN_BETA_TESTING'):
        msg = f'Build {ver} ({bnum}) ist für «{gname}» freigegeben'
        note('notice', msg + (f': {link}' if link else ''))
        break
    if s == 'BETA_APPROVED' and not notified:
        r = call('POST', '/v1/buildBetaNotifications',
                 {'data': {'type': 'buildBetaNotifications', 'relationships': {'build': {'data': {'type': 'builds', 'id': bid}}}}},
                 ok_codes=(409,))
        notified = True
        if '_status' in r:
            note('warning', f'Tester benachrichtigen abgelehnt: {r["_body"][:300]}')
        else:
            print('Tester benachrichtigt (Build freigegeben)', flush=True)
        time.sleep(10)
        continue
    if s == 'READY_FOR_BETA_SUBMISSION' and not submitted:
        r = call('POST', '/v1/betaAppReviewSubmissions',
                 {'data': {'type': 'betaAppReviewSubmissions', 'relationships': {'build': {'data': {'type': 'builds', 'id': bid}}}}},
                 ok_codes=(409,))
        submitted = True
        if '_status' in r:
            fail(f'Einreichen zur Beta-Prüfung abgelehnt: {r["_body"][:500]}')
        print('Zur Beta-Prüfung eingereicht', flush=True)
        continue
    if s == 'BETA_REJECTED':
        fail('Apple hat den Build in der Beta-Prüfung abgelehnt; Grund in App Store Connect beim Build')
    if s == 'EXPIRED':
        fail('Der Build ist abgelaufen; neuen Build hochladen')
    if s == 'MISSING_EXPORT_COMPLIANCE':
        fail('Exportangaben zur Verschlüsselung fehlen (ITSAppUsesNonExemptEncryption in Info.plist bzw. in App Store Connect beantworten)')
    if time.time() - t0 >= wait_s:
        note('notice', f'Build {ver} ({bnum}) ist «{STATE.get(s, s)}». Nach der Genehmigung durch Apple wird er automatisch '
                       f'an «{gname}» verteilt (autoNotifyEnabled); sonst diesen Workflow erneut starten')
        break
    time.sleep(60)
