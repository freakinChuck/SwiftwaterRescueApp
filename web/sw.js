/* Service Worker der Einsatz-App (Issue #67): installierbar (PWA), offline der letzte Stand, Web-Push.
   - Eigene Seiten (HTML/JS/CSS, Manifest, Icons): zuerst Netz, sonst Zwischenspeicher. So gilt nach einem Update immer der neue Stand (vgl. Issue #19).
   - Bibliotheken vom CDN (Leaflet, leaflet-rotate): aus dem Zwischenspeicher, sonst Netz (feste Versionen).
   - Kartenkacheln: zuerst Netz, offline die zuletzt gesehenen (höchstens TILE_MAX). Hosts aller Kartenanbieter aus MAP_SOURCES (Issue #106).
   - Eigene OSM-Karte (tiles/*.pmtiles, Teilabfragen): je Abschnitt (Range) wie eine Kachel, die Cache-API nimmt keine 206-Antworten, deshalb als 200 mit dem Status im Kopf.
   - Die API (api/index.php) geht nie über den Zwischenspeicher; den letzten Stand merkt sich die App selbst (localStorage sw_last). */
const SHELL='sw-shell-1',LIBS='sw-libs-1',TILES='sw-tiles-1',TILE_MAX=1500;
const PRE=['./','index.html','common.js?v=0','common.css?v=0','manifest.webmanifest','img/icon-192.png','img/icon-512.png'];
const CDN=/^https:\/\/(cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net)\//,TILE=/^https:\/\/(wmts\.geo\.admin\.ch|tile\.openstreetmap\.org|tiles\.maps\.eox\.at|mapsneu\.wien\.gv\.at|data\.geopf\.fr|[a-c]\.tile\.opentopomap\.org|[a-c]\.tile\.openstreetmap\.fr|sgx\.geodatenzentrum\.de|[a-d]\.basemaps\.cartocdn\.com)\//;

self.addEventListener('install',e=>{e.waitUntil(caches.open(SHELL).then(c=>Promise.all(PRE.map(u=>c.add(u).catch(()=>{})))).then(()=>self.skipWaiting()))});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>![SHELL,LIBS,TILES].includes(k)).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});

async function netFirst(req,cache,key){const c=await caches.open(cache);
  try{const r=await fetch(req);if(r&&(r.ok||r.type==='opaque'))c.put(key||req,r.clone());return r}
  catch(e){const m=await c.match(key||req,{ignoreSearch:cache===SHELL&&/\.(js|css)$/.test(new URL(req.url).pathname)});if(m)return m;
    if(req.mode==='navigate'){const i=await c.match('index.html')||await c.match('./');if(i)return i}throw e}}
async function cacheFirst(req){const c=await caches.open(LIBS),m=await c.match(req);if(m)return m;const r=await fetch(req);if(r&&(r.ok||r.type==='opaque'))c.put(req,r.clone());return r}
let tileN=0;async function tile(req){const c=await caches.open(TILES);
  try{const r=await fetch(req);if(r&&(r.ok||r.type==='opaque')){await c.put(req,r.clone());if(++tileN%100===0)trim(c)}return r}
  catch(e){const m=await c.match(req);if(m)return m;throw e}}
async function pmt(req){const c=await caches.open(TILES),k=new URL(req.url);k.searchParams.set('_swr',req.headers.get('range')||'');
  try{const r=await fetch(req);if(r&&r.ok){const h=new Headers();['content-type','content-range','content-length','etag'].forEach(x=>{const v=r.headers.get(x);if(v)h.set(x,v)});h.set('x-sw-status',String(r.status));
      c.put(k.href,new Response(await r.clone().arrayBuffer(),{status:200,headers:h})).catch(()=>{});if(++tileN%100===0)trim(c)}return r}
  catch(e){const m=await c.match(k.href);if(m)return new Response(await m.arrayBuffer(),{status:+(m.headers.get('x-sw-status')||200),headers:m.headers});throw e}}
async function trim(c){const ks=await c.keys();for(let i=0;i<ks.length-TILE_MAX;i++)await c.delete(ks[i])} // älteste zuerst

self.addEventListener('fetch',e=>{const q=e.request;if(q.method!=='GET')return;const u=new URL(q.url);
  if(u.origin===location.origin){if(u.pathname.includes('/api/'))return; // API nie zwischenspeichern
    if(u.pathname.endsWith('.pmtiles'))return e.respondWith(pmt(q));
    // Navigation: Seite ohne Abfrageparameter merken (public.html?k=… nicht unter jedem Schlüssel)
    return e.respondWith(netFirst(q,SHELL,q.mode==='navigate'?u.origin+u.pathname:undefined))}
  if(CDN.test(q.url))return e.respondWith(cacheFirst(q));
  if(TILE.test(q.url))return e.respondWith(tile(q))});

/* ---------- Web-Push ---------- */
// Payload vom Server: t (Titel), b (Text), tag (wie die Quittierschlüssel der App: i:<Einsatz>, m:<Nachricht>, e:<Einsatz>), crit (kritischer Alarm), stay (bleibt stehen), silent (ohne Ton und Vibration)
// Ist die App sichtbar im Vordergrund, zeigt sie die Meldung selbst (Polling); sonst die Systembenachrichtigung.
self.addEventListener('push',e=>{let m={};try{m=e.data?e.data.json():{}}catch(_){m={t:'Swiftwater Rescue',b:e.data?e.data.text():''}}
  e.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(cs=>{
    if(cs.some(c=>c.visibilityState==='visible'&&c.focused))return;
    return self.registration.showNotification(m.t||'Swiftwater Rescue',{body:m.b||'',tag:m.tag||undefined,renotify:true,requireInteraction:!!(m.crit||m.stay),
      ...(m.silent?{silent:true}:{vibrate:m.crit?[600,200,600,200,600,200,600]:[200]}),icon:'img/icon-192.png',badge:'img/badge-96.png',data:{url:'./'}})}))}); // silent und vibrate zusammen wirft einen Fehler
// Klick: offene App in den Vordergrund holen, sonst öffnen. Alarm (i:), Nachricht (m:) und Entfernen (k:) gehören zum laufenden Einsatz:
// die App zeigt dann das Einsatzgebiet (Feedback zu #108; offene App über postMessage, neue über ?fokus=1)
self.addEventListener('notificationclick',e=>{e.notification.close();const inc=/^[imk]:/.test(e.notification.tag||'');
  e.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(cs=>{const c=cs.find(c=>new URL(c.url).pathname.endsWith('/')||c.url.includes('index.html'));
    if(c){if(inc)c.postMessage({sw:'focus'});return c.focus()}
    return self.clients.openWindow(((e.notification.data&&e.notification.data.url)||'./')+(inc?'?fokus=1':''))}))});
// Push-Dienst hat das Abonnement erneuert: neu beim Server anmelden geht hier nicht (kein Token); die App meldet es beim nächsten Start neu an (pushSync)
