// Kleiner Treiber für Edge/Chrome über das DevTools-Protokoll (Issue #37), ohne zusätzliche Pakete (Node ab 22: fetch und WebSocket eingebaut).
// Startet den Browser ohne Fenster mit einem eigenen, leeren Profil, öffnet je Aufnahme einen Tab und macht Bildschirmfotos.
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CANDIDATES = [
  process.env.SW_BROWSER,
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/microsoft-edge',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].filter(Boolean);

export const sleep = ms => new Promise(r => setTimeout(r, ms));

export async function launch({ port = 9333 } = {}) {
  const exe = CANDIDATES.find(p => existsSync(p));
  if (!exe) throw new Error('Kein Edge/Chrome gefunden (Pfad über SW_BROWSER angeben)');
  const prof = mkdtempSync(join(tmpdir(), 'sw-hilfe-'));
  const proc = spawn(exe, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${prof}`, '--no-first-run', '--no-default-browser-check',
    '--disable-extensions', '--hide-scrollbars', '--mute-audio', '--lang=de-CH', '--window-size=1280,900'], { stdio: 'ignore' });
  let ver = null;
  for (let i = 0; i < 50 && !ver; i++) { await sleep(200); try { ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json() } catch (e) { } }
  if (!ver) { proc.kill(); throw new Error('Browser antwortet nicht'); }
  const close = async () => { proc.kill(); await sleep(500); try { rmSync(prof, { recursive: true, force: true }) } catch (e) { } };
  return { port, close };
}

// Ein Tab mit Befehlen send(method, params) und Hilfen für die Aufnahmen
export async function tab(port) {
  const t = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
  const ws = new WebSocket(t.webSocketDebuggerUrl);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej });
  let id = 0; const wait = new Map(), errs = [];
  ws.onmessage = ev => {
    const m = JSON.parse(ev.data);
    if (m.id && wait.has(m.id)) { const [res, rej] = wait.get(m.id); wait.delete(m.id); m.error ? rej(new Error(m.error.message)) : res(m.result) }
    else if (m.method === 'Runtime.exceptionThrown') errs.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
  };
  const send = (method, params = {}) => new Promise((res, rej) => { const i = ++id; wait.set(i, [res, rej]); ws.send(JSON.stringify({ id: i, method, params })) });
  await send('Page.enable'); await send('Runtime.enable');
  const ev = async (js) => {
    const r = await send('Runtime.evaluate', { expression: js, awaitPromise: true, returnByValue: true, userGesture: true });
    if (r.exceptionDetails) throw new Error('Skript: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    return r.result.value;
  };
  const p = {
    send, ev, errs,
    async device(kind) {
      const phone = kind === 'phone';
      await send('Emulation.setDeviceMetricsOverride', phone ? { width: 390, height: 844, deviceScaleFactor: 1.5, mobile: true } : { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false });
      await send('Emulation.setTouchEmulationEnabled', { enabled: phone });
      if (phone) await send('Emulation.setUserAgentOverride', { userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36', acceptLanguage: 'de-CH' });
    },
    async geo(lat, lon) {
      await send('Browser.grantPermissions', { permissions: ['geolocation', 'notifications'] }).catch(() => { });
      await send('Emulation.setGeolocationOverride', { latitude: lat, longitude: lon, accuracy: 15 });
    },
    // vor jedem Laden: Uhr um sec Sekunden vorstellen (Demo-Szenario weiterlaufen lassen), Hinweise unterdrücken
    async prep(js) { await send('Page.addScriptToEvaluateOnNewDocument', { source: js }) },
    async go(url) { await send('Page.navigate', { url }); await p.idle() },
    async idle(ms = 1500) { await sleep(ms); for (let i = 0; i < 40; i++) { if (await ev('document.readyState') === 'complete') break; await sleep(250) } },
    async waitFor(sel, ms = 15000) { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (await ev(`!!(()=>{const e=document.querySelector(${JSON.stringify(sel)});return e&&!e.closest('[hidden]')&&e.getClientRects().length>0})()`)) return; await sleep(250) } throw new Error('Nicht gefunden: ' + sel) },
    async click(sel) { await p.waitFor(sel); await ev(`document.querySelector(${JSON.stringify(sel)}).click()`); await sleep(600) },
    // Knopf über den sichtbaren Text wählen (z. B. «Annehmen»)
    async clickText(txt, scope = 'button,a,summary,label') {
      const ok = await ev(`(()=>{const t=${JSON.stringify(txt)};const e=[...document.querySelectorAll(${JSON.stringify(scope)})].find(e=>e.getClientRects().length>0&&!e.closest('[hidden]')&&e.textContent.trim().includes(t));if(e){e.click();return true}return false})()`);
      if (!ok) throw new Error('Kein Knopf mit Text: ' + txt); await sleep(600);
    },
    async shot(file, { quality = 82, clip } = {}) {
      const r = await send('Page.captureScreenshot', { format: 'jpeg', quality, captureBeyondViewport: false, ...(clip ? { clip: { ...clip, scale: 1 } } : {}) });
      const { writeFileSync } = await import('node:fs'); writeFileSync(file, Buffer.from(r.data, 'base64'));
    },
    async close() { try { ws.close() } catch (e) { } await fetch(`http://127.0.0.1:${port}/json/close/${t.id}`).catch(() => { }) },
  };
  return p;
}
