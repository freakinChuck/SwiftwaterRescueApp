// Bildschirmfotos für die Bedienungsanleitung (Issue #37): erzeugt web/hilfe/img/*.jpg
//
// Teil «app»: Einsatz-App im Demo-Modus (index.html?demo), Szenario Reuss Luzern, Handy-Ansicht.
//   Die Uhr der Seite wird vorgestellt (sessionStorage sw_toff), damit das Szenario weiterläuft.
// Teil «admin»: Verwaltung auf einem lokalen Server mit frischer Datenbank, gefüllt mit Beispieldaten über die API.
//
// Aufruf (im Hauptordner, lokaler Server läuft: php -S localhost:8000 -t web):
//   node scripts/hilfe/shots.mjs                 alle Teile
//   node scripts/hilfe/shots.mjs app             nur die Einsatz-App
//   node scripts/hilfe/shots.mjs admin           nur die Verwaltung
//   node scripts/hilfe/shots.mjs app karte       nur einzelne Bilder (Namen ohne .jpg)
// Umgebung: SW_BASE (Standard http://localhost:8000/), SW_USER/SW_PASS (Konto mit allen Rechten, Standard das Erst-Admin-Konto
//   aus api/config.php), SW_NEWPASS (neues Passwort, falls das Konto noch ein eigenes setzen muss), SW_BROWSER (Pfad zu Edge/Chrome).
// Der Teil «admin» legt Beispieldaten an: nur gegen eine lokale Wegwerf-Datenbank laufen lassen, nie gegen Test oder Produktiv.
import { launch, tab, sleep } from './cdp.mjs';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = (process.env.SW_BASE || 'http://localhost:8000/').replace(/\/?$/, '/');
const OUT = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'web', 'hilfe', 'img');
mkdirSync(OUT, { recursive: true });
const [part = 'all', ...only] = process.argv.slice(2);
const want = n => !only.length || only.includes(n);
const LU = [47.0525, 8.3062]; // eigener Standort: Luzern, Reussufer

// Uhr vorstellen: Date.now() und new Date() um sw_toff Sekunden verschoben
const CLOCK = `(()=>{const o=+(sessionStorage.getItem('sw_toff')||0)*1000;if(!o)return;const D=Date,n=()=>D.now()+o;
function X(...a){if(!new.target)return new D(n()).toString();return a.length?new D(...a):new D(n())}
X.prototype=D.prototype;X.now=n;X.UTC=D.UTC;X.parse=D.parse;window.Date=X})()`;
// Hinweise (Toasts) und den Hinweis zur App-Installation aus dem Bild nehmen
const CLEAN = `(()=>{document.querySelectorAll('.toast').forEach(t=>t.remove());if(!document.getElementById('swShotCss')){const s=document.createElement('style');s.id='swShotCss';s.textContent='#toasts{display:none!important}';document.head.appendChild(s)}})()`;

async function shot(p, name, opt) { if (!want(name)) return; await p.ev(CLEAN); await sleep(300); await p.shot(join(OUT, name + '.jpg'), opt); console.log('  ' + name + '.jpg') }
const need = n => n.some(want);

// ---------- Einsatz-App im Demo-Modus ----------
async function app(b) {
  const p = await tab(b.port);
  await p.device('phone'); await p.geo(...LU); await p.prep(CLOCK);
  const at = async (sec, extra = '') => { await p.ev(`sessionStorage.setItem('sw_toff','${sec}');${extra}location.reload()`); await sleep(2500); await p.idle(); await p.ev(CLEAN) };
  const fit = async () => { await p.ev(`(()=>{try{map.invalidateSize()}catch(e){}})()`); await sleep(1200) };
  try {
    // Anmeldung (ohne Demo)
    await p.go(BASE + 'index.html'); await p.waitFor('input[type=password]');
    await shot(p, 'anmeldung');

    // Demo starten: Begrüssung und Alarm
    await p.go(BASE + 'index.html?demo'); await sleep(2500); await p.waitFor('#dmOk');
    await shot(p, 'demo-start');
    await p.click('#dmOk'); await sleep(1500);
    await p.ev(`toggleSheet(false)`); await sleep(600);
    await shot(p, 'hinweis-annehmen');
    await p.click('#joinBan'); await sleep(800);
    await shot(p, 'teilnahme');
    await p.click('[data-m="base"]'); await sleep(1500);

    // Szenario rund 6 Minuten nach dem Alarm (Start 2.5 min danach): Einsatzkräfte unterwegs, Suchgebiet steht
    await at(200); await sleep(4000); await fit();
    await shot(p, 'karte');
    if (need(['ansicht'])) { await p.click('#bView'); await sleep(600); await shot(p, 'ansicht'); await p.click('#bView') }
    if (need(['kamerafokus'])) { await p.click('#bCam'); await sleep(500); await shot(p, 'kamerafokus'); await p.ev(`document.body.click()`); await p.ev(`document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'}))`) }
    if (need(['einsatzkraefte'])) { await p.click('#bPeople'); await sleep(700); await shot(p, 'einsatzkraefte'); await p.click('#bPeopleX') }
    if (need(['nachrichten'])) { await p.click('#bMsg'); await sleep(700); await shot(p, 'nachrichten'); await p.click('#bMsgX') }
    if (need(['markierungen'])) { await p.click('#bPoi'); await sleep(700); await shot(p, 'markierungen'); await p.click('#bPoiX') }
    if (need(['menu-einsatz', 'menu-log', 'menu-info'])) {
      await p.click('#bMenu'); await sleep(700); await shot(p, 'menu-einsatz');
      await p.click('[data-mt="log"]'); await sleep(400); await shot(p, 'menu-log');
      await p.click('[data-mt="info"]'); await sleep(400); await shot(p, 'menu-info');
      await p.click('#bClose');
    }
    if (need(['flussdistanz'])) {
      await p.click('#bRd'); await sleep(500);
      await p.ev(`void map.fire('click',{latlng:L.latLng(47.0533,8.3032)})`); await sleep(400);
      await p.ev(`void map.fire('click',{latlng:L.latLng(47.0610,8.2800)})`);
      await p.waitFor('#rdBox'); for (let i = 0; i < 90 && await p.ev(`/geladen/.test(document.querySelector('#rdBox').textContent)`); i++) await sleep(500); await sleep(800);
      await p.ev(`void map.fitBounds([[47.0533,8.3032],[47.0610,8.2800]],{paddingTopLeft:[20,60],paddingBottomRight:[20,330]})`); await sleep(1500);
      // ohne erreichbares Gewässernetz (Overpass oder eigener Server) kein Bild, das bisherige bleibt
      if (await p.ev(`!!document.querySelector('#rdBox .rdk, #rdBox table') || /km/.test(document.querySelector('#rdBox').textContent)`)) await shot(p, 'flussdistanz');
      else console.log('  flussdistanz.jpg übersprungen: Gewässernetz nicht erreichbar');
      await p.ev(`rdClose()`);
    }
    if (need(['profil'])) { await p.click('#bMenu'); await p.click('#bPrefs'); await sleep(800); await shot(p, 'profil'); await p.click('#bPrefsX'); await p.ev(`toggleSheet(false)`) }

    // Szenario rund 7.5 Minuten nach dem Alarm: Rettling gefunden, noch nicht gerettet (die echte Zeit läuft mit)
    await at(300); await sleep(3000);
    if (need(['rettlinge'])) { await p.click('#bRes'); await sleep(700); await shot(p, 'rettlinge'); await p.click('#bResX') }
    if (need(['funk'])) { const vx = await p.ev(`!!document.querySelector('#bVx')&&document.querySelector('#bVx').getClientRects().length>0`); if (vx) { await p.click('#bVx'); await sleep(700); await shot(p, 'funk'); await p.click('#bVxX') } }
    if (need(['messstationen'])) { const h = await p.ev(`document.querySelector('#bHyd').getClientRects().length>0`); if (h) { await p.click('#bHyd'); await sleep(900); await shot(p, 'messstationen'); await p.click('#bHydX') } }

    // Leitungsrechte im Demo einschalten: Register «Leitung»
    if (need(['menu-leitung'])) {
      await p.click('#demoBadge'); await p.waitFor('#dmLead');
      await p.ev(`(()=>{const c=document.querySelector('#dmLead');if(!c.checked)c.click()})()`); await sleep(500);
      await p.ev(`document.querySelector('#dmOk').click()`); await sleep(2500);
      await p.click('#bMenu'); await p.click('[data-mt="lead"]'); await sleep(500);
      await shot(p, 'menu-leitung'); await p.click('#bClose');
    }

    // Demo ohne Daten: Start eines Einsatzes
    if (need(['start', 'start-formular'])) {
      await p.ev(`sessionStorage.removeItem('sw_toff')`);
      await p.go(BASE + 'index.html?demo=leer'); await sleep(2500);
      await p.ev(`(()=>{const b=document.querySelector('#dmOk');if(b)b.click()})()`); await sleep(1500);
      await p.click('#bMenu'); await sleep(700); await shot(p, 'start');
      await p.clickText('starten', '#sheet button'); await sleep(900); await shot(p, 'start-formular');
    }
    if (p.errs.length) console.log('Skriptfehler der Seite:', p.errs);
  } finally { await p.close() }
}

// ---------- Verwaltung ----------
async function api(r, body, tok) {
  const res = await fetch(BASE + 'api/index.php?r=' + encodeURIComponent(r), { method: 'POST', headers: { 'Content-Type': 'application/json', ...(tok ? { 'X-Token': tok } : {}) }, body: JSON.stringify(body || {}) });
  const j = await res.json().catch(() => ({ error: 'keine JSON-Antwort (' + res.status + ')' }));
  if (j.error) throw new Error(r + ': ' + j.error); return j;
}
async function seed() {
  const user = process.env.SW_USER || 'admin';
  let pass = process.env.SW_PASS || 'BITTE-AENDERN';
  let tok = (await api('login', { username: user, password: pass })).token;
  let me = (await api('me', {}, tok)).me;
  if (me.must_change) {
    if (!process.env.SW_NEWPASS) throw new Error('Das Konto muss ein eigenes Passwort setzen: SW_NEWPASS angeben');
    await api('me/password', { old: pass, new: process.env.SW_NEWPASS }, tok); pass = process.env.SW_NEWPASS;
    tok = (await api('login', { username: user, password: pass })).token; me = (await api('me', {}, tok)).me;
  }
  const users = await api('users', {}, tok);
  if (users.length < 3) { // Beispieldaten nur einmal anlegen
    console.log('  Beispieldaten anlegen …');
    const roles = (await api('uroles', {}, tok)).roles || [];
    const ek = roles.find(r => r.def) || roles[0];
    const names = [['mmuster', 'Mia Muster'], ['lkeller', 'Luca Keller'], ['sbrunner', 'Sara Brunner'], ['tfrei', 'Tim Frei'], ['nmeier', 'Nina Meier'], ['jhuber', 'Jan Huber']];
    for (const [u, n] of names) await api('user/save', { username: u, name: n, password: 'Start-' + Math.random().toString(36).slice(2, 10), uroles: ek ? [ek.id] : [] }, tok).catch(e => console.log('   ', e.message));
    const ids = (await api('users', {}, tok)).map(u => u.id);
    await api('group/save', { name: 'Bootsgruppe', members: ids.slice(0, 4) }, tok).catch(e => console.log('   ', e.message));
    await api('group/save', { name: 'Ufergruppe', members: ids.slice(3) }, tok).catch(e => console.log('   ', e.message));
    for (const [n, a, la, lo] of [['Bootseinwasserung Reusszopf', 'BE', 47.0627, 8.2789], ['Magazin Stützpunkt', 'MAG', 47.0489, 8.3105], ['Treffpunkt Seebrücke', 'TP', 47.0523, 8.3104]])
      await api('place/save', { name: n, abbr: a, lat: la, lon: lo, active: 1 }, tok).catch(e => console.log('   ', e.message));
    // ein beendeter Einsatz für Einsatzlog und «Meine Einsätze»
    const kinds = me.kinds || [];
    const k = kinds.find(x => !x.sec && !x.plans) || kinds[0];
    if (k) {
      const s = await api('incident/start', { kind_id: k.id, message: 'Person in der Reuss beim Nadelwehr, treibt flussabwärts', lat: 47.0537, lon: 8.3030, alarm_at: Math.floor(Date.now() / 1000) - 3600, record: 1, invite_all: 1 }, tok).catch(e => console.log('   ', e.message));
      if (s) {
        const modes = await api('modes', {}, tok);
        await api('incident/join', { mode: (modes.find(m => m.active) || {}).code || 'base' }, tok).catch(e => console.log('   ', e.message));
        await api('incident/location', { lat: 47.0525, lon: 8.3062, acc: 10 }, tok).catch(() => { });
        await api('incident/message', { text: 'Boot 1 wassert an der Reusszopf ein, Ufergruppe sichert die Geissmattbrücke.' }, tok).catch(e => console.log('   ', e.message));
        await api('incident/rescuee/save', { name: '' }, tok).catch(e => console.log('   ', e.message));
        await sleep(1500);
        await api('incident/end', {}, tok).catch(e => console.log('   ', e.message));
      }
    }
  }
  return tok;
}
// Bereiche der Verwaltung (Schlüssel = Hash), je ein Bild admin-<Schlüssel>.jpg
const ADMIN = ['benutzer', 'benutzerrollen', 'einsatzgruppen', 'einsatztypen', 'einrueckoptionen', 'spezialrollen', 'funktionen', 'standorte', 'karten', 'einsatzlog', 'anonymisierung', 'applog'];
async function admin(b) {
  if (!need([...ADMIN.map(k => 'admin-' + k), 'meine'])) return;
  const tok = await seed();
  const p = await tab(b.port);
  try {
    await p.device('desk');
    await p.go(BASE + 'legal.html'); await p.ev(`localStorage.setItem('sw_token',${JSON.stringify(tok)})`);
    for (const k of ADMIN) {
      if (!want('admin-' + k)) continue;
      await p.go(BASE + 'admin.html#' + k); await sleep(2500); await shot(p, 'admin-' + k);
    }
    if (want('meine')) { await p.go(BASE + 'admin.html#meine'); await sleep(2500); await shot(p, 'meine') }
    if (p.errs.length) console.log('Skriptfehler der Seite:', p.errs);
  } finally { await p.close() }
}

const b = await launch();
try {
  if (part === 'all' || part === 'app') { console.log('Einsatz-App (Demo):'); await app(b) }
  if (part === 'all' || part === 'admin') { console.log('Verwaltung:'); await admin(b) }
} finally { await b.close() }
