/* Gemeinsamer Code für Einsatz-App (index.html) und Verwaltung (admin.html) */
const API='api/index.php';
const $=(s,r=document)=>r.querySelector(s), esc=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
let token=localStorage.getItem('sw_token'),onUnauth=()=>{}; // onUnauth setzt die jeweilige Seite
// Native App (Issue #108): Die Flutter-Hülle zeigt die Web-App in einer WebView und hängt «SwiftwaterRescueApp/<Version>» an den User-Agent an.
// Dann kein Web-Push und kein «App installieren» (Benachrichtigungen kommen über die App); swNative ruft einen Handler der App auf, falls vorhanden.
const NATIVE=/SwiftwaterRescueApp\//.test(navigator.userAgent);
const swNative=(h,...a)=>{try{const w=window.flutter_inappwebview;if(w&&w.callHandler)w.callHandler(h,...a)}catch(e){}};
// Datei herunterladen; in der WebView der App (kein Download von blob:) übernimmt die App die Datei (Handler swSave: Name, Typ, Base64)
function saveBlob(blob,name){const w=window.flutter_inappwebview;if(w&&w.callHandler){const r=new FileReader();r.onload=()=>swNative('swSave',name,blob.type||'application/octet-stream',String(r.result).split(',')[1]||'');r.readAsDataURL(blob);return}
  const l=document.createElement('a');l.href=URL.createObjectURL(blob);l.download=name;document.body.appendChild(l);l.click();setTimeout(()=>{URL.revokeObjectURL(l.href);l.remove()},3000)}
async function api(r,body){const o={headers:{'X-Token':token||''}};if(body){o.method='POST';o.body=JSON.stringify(body)}
  const res=await fetch(`${API}?r=${r}`,o);const j=await res.json().catch(()=>({error:'Serverfehler'}));
  if(res.status===401&&r!=='login'){token=null;localStorage.removeItem('sw_token');onUnauth();swNative('swUnauth')}if(j.error){const e=new Error(j.error);e.data=j;throw e}return j}
// Applikationslog (Issue #139): Skriptfehler an den Server melden (nur angemeldet, höchstens 5 je Seitenaufruf, gleiche Meldung einmal; der Server begrenzt zusätzlich).
// Nicht gemeldet: Fehler fremder Skripte ohne Angaben («Script error.»), Verbindungsabbrüche (offline) und abgelehnte API-Antworten (stehen schon im Log bzw. sind Eingabefehler).
const CERR={n:0,seen:new Set()};
function clientErr(msg,x={}){try{msg=String(msg==null?'':msg).slice(0,500);if(!token||!msg||CERR.n>=5||CERR.seen.has(msg)||/^Script error\.?$/i.test(msg))return;CERR.seen.add(msg);CERR.n++;
  fetch(`${API}?r=applog/client`,{method:'POST',keepalive:true,headers:{'X-Token':token},body:JSON.stringify({msg,page:location.pathname.split('/').pop()||'index.html',...x})}).catch(()=>{})}catch(e){}}
const cErrSkip=r=>!r||r.data||(r instanceof TypeError&&/fetch|network|load failed/i.test(r.message||''))||r.name==='AbortError';
window.addEventListener('error',e=>{if(!e.message||cErrSkip(e.error))return;clientErr(e.message,{src:String(e.filename||'').split('/').pop().split('?')[0],line:e.lineno,col:e.colno,stack:e.error&&e.error.stack?String(e.error.stack).slice(0,1500):undefined})});
window.addEventListener('unhandledrejection',e=>{const r=e.reason;if(cErrSkip(r))return;clientErr('Unbehandelt: '+(r&&r.message||r),{stack:r&&r.stack?String(r.stack).slice(0,1500):undefined})});
// Kurze Hinweise statt alert(): ohne Klick, verschwinden von selbst (Dauer nach Textlänge), Antippen schliesst sofort.
// kind: 'ok' (grün, Standard), 'info' (blau), 'err' (rot, länger sichtbar). Rückfragen (confirm) bleiben Dialoge.
function toast(t,kind='ok'){t=String(t==null?'':t);let c=document.getElementById('tsts');
  if(!c){c=document.createElement('div');c.id='tsts';document.body.appendChild(c)}
  const d=document.createElement('div');d.className='tst '+kind;d.setAttribute('role',kind==='err'?'alert':'status');d.textContent=t;
  const rm=()=>{d.classList.add('out');setTimeout(()=>d.remove(),250)};d.onclick=rm;c.appendChild(d);
  while(c.children.length>4)c.firstChild.remove();setTimeout(rm,Math.min(12000,(kind==='err'?5000:2500)+t.length*45))}
const fail=e=>toast(e instanceof TypeError?'Keine Verbindung zum Server. Bitte später nochmals versuchen.':e.message,'err'); // TypeError: fetch ohne Netz (Issue #67)
/* ---------- Karte (Einsatz-App und Replay der Verwaltung) ---------- */
// Kartenanbieter austauschbar: neuen Eintrag ergänzen bzw. Standard über DEFAULT_BASE wählen
const DEFAULT_BASE='swisstopo_sat'; // Standard-Hintergrund; der Benutzer kann über das Ebenen-Symbol (oben rechts) umschalten, die Wahl wird gemerkt.
// Fenster «Ansicht» (Issue #34, öffentliche Ansicht #48): Kategorien auf der Karte [Schlüssel, Bezeichnung, Funktion (feat)]. Neue Kartenelemente hier aufnehmen.
const VIEW=[['app','Einsatzkräfte mit App'],['ext','Externe Einsatzkräfte','ext'],['res','Rettlinge','res'],['pls','PLS'],['place','Feste Standorte','place'],['area','Suchgebiet','area'],['pt','Markierungen','poi'],['ar','Bereiche','poi'],['ln','Linien','poi'],['mm','Nachrichten-Markierungen','msg'],['air','Helikopter'],['hydro','Messstationen (Pegel & Abfluss)','hydro']]; // Helikopter: keine Funktion, sondern Hauptschalter und Schalter je Einsatz (st.air / ps.air)
const VIEW_ICON='<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 3l9 5-9 5-9-5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M3 13l9 5 9-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
// Inhalt des Fensters: Grundkarte (Radio vbase) und, wenn cats (Liste aus VIEW), Häkchen je Kategorie (data-vk) mit «Alle einblenden/ausblenden» (data-va)
function viewHtml(base,cats,vis){return'<div class="vh"><b>Ansicht</b><button id="bViewX" aria-label="Ansicht schliessen">✕</button></div><h4>Grundkarte</h4>'
  +Object.entries(MAP_PROVIDERS).map(([k,P])=>`<label><input type="radio" name="vbase" value="${k}" ${k===base?'checked':''}> ${esc(P.label)}</label>`).join('')
  +(cats?'<h4>Auf der Karte</h4><div class="row"><button data-va="1">Alle einblenden</button><button data-va="0">Alle ausblenden</button></div>'+cats.map(([k,t])=>`<label><input type="checkbox" data-vk="${k}" ${vis(k)?'checked':''}> ${t}</label>`).join(''):'')}
const MAP_PROVIDERS={
  swisstopo_sat:{label:'Luftbild (swisstopo)',url:'https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.swissimage/default/current/3857/{z}/{x}/{y}.jpeg',attr:'&copy; swisstopo',maxZoom:18},
  swisstopo:{label:'Landeskarte (swisstopo)',url:'https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.pixelkarte-farbe/default/current/3857/{z}/{x}/{y}.jpeg',attr:'&copy; swisstopo',maxZoom:18},
  osm:{label:'OpenStreetMap',url:'https://tile.openstreetmap.org/{z}/{x}/{y}.png',attr:'&copy; OpenStreetMap',maxZoom:19}
};
// Kleine Karten der Verwaltung (Auswahl- und Vorschaukarten, Änderungswunsch #96): alle Anbieter aus MAP_PROVIDERS, umschaltbar über eine Auswahl oben rechts, gemerkt in sw_base wie die übrigen Karten
function addBases(m){let sv=null;try{sv=localStorage.getItem('sw_base')}catch(e){}let cur=MAP_PROVIDERS[sv]?sv:DEFAULT_BASE;const ls={};
  Object.entries(MAP_PROVIDERS).forEach(([k,P])=>ls[k]=L.tileLayer(P.url,{attribution:P.attr,maxZoom:P.maxZoom}));ls[cur].addTo(m);
  const C=L.Control.extend({options:{position:'topright'},onAdd(){const d=L.DomUtil.create('div','leaflet-bar basesel');d.innerHTML=`<select aria-label="Grundkarte">${Object.entries(MAP_PROVIDERS).map(([k,P])=>`<option value="${k}" ${k===cur?'selected':''}>${esc(P.label)}</option>`).join('')}</select>`;
    L.DomEvent.disableClickPropagation(d);L.DomEvent.disableScrollPropagation(d);d.firstChild.onchange=e=>{const k=e.target.value;if(!ls[k]||k===cur)return;m.removeLayer(ls[cur]);cur=k;ls[k].addTo(m);try{localStorage.setItem('sw_base',k)}catch(_){}};return d}});
  new C().addTo(m);return m}
/* ---------- Helikopter / Luftlage (Issue #73): Anzeige in Einsatz-App, öffentlicher Ansicht und Replay ---------- */
// Daten vom Server (incident/air, public/air, air in incident/replay): hex, cs, reg, type, desc, cat (A7 = Helikopter), lat, lon, alt (ft), gnd, gs (kt), trk, vr (ft/min), at, rescue, pls_m, trail [[ts, lat, lon, alt]]
const AIR_STALE_S=20,AIR_GAP_S=60; // ältere Positionen blass; im Replay längere Lücken nicht interpolieren
const airCol=a=>a.rescue?'#e30613':a.cat==='A7'?'#ffd23f':'#7fc8ff';
const airName=a=>a.rescue?a.rescue+(a.cs?' '+a.cs:''):a.cs||a.reg||String(a.hex||'').toUpperCase();
const airAltS=a=>a.gnd?'am Boden':a.alt==null?'Höhe ?':Math.round(a.alt*.3048)+' m';
const airAltL=a=>a.gnd?'am Boden':a.alt==null?'unbekannt':`ca. ${Math.round(a.alt*.3048)} m ü. M. (${a.alt} ft, barometrisch)`;
const AIR_SVG={h:c=>`<circle cx="16" cy="12" r="10.5" fill="none" stroke="#fff" stroke-width="1.3" stroke-dasharray="3 2.5" opacity=".9"/><ellipse cx="16" cy="12.5" rx="4.2" ry="6.2" fill="${c}" stroke="#0f2430" stroke-width="1.4"/><path d="M15 18h2v9h-2z" fill="${c}" stroke="#0f2430" stroke-width="1"/><path d="M12 26.5h8v2h-8z" fill="${c}" stroke="#0f2430" stroke-width=".9"/>`,
  p:c=>`<path d="M16 3l2 9 10 5v2l-10-3-.5 9 3.5 3v1l-5-1.5-5 1.5v-1l3.5-3-.5-9-10 3v-2l10-5z" fill="${c}" stroke="#0f2430" stroke-width="1.2" stroke-linejoin="round"/>`};
// Marker: Symbol nach Kurs gedreht (rot = Rettungshelikopter, gelb = Helikopter, blau = übrige), Beschriftung mit Name und Höhe; rot = Drehung auf dem Bildschirm (Kurs minus Kartendrehung)
function airIconHtml(a,age,rot){const h=a.cat==='A7'||!!a.rescue;
  return`<div class="airk${age>AIR_STALE_S?' old':''}${a.rescue?' resc':''}"><div class="airr" style="transform:rotate(${Math.round(rot||0)}deg)"><svg viewBox="0 0 32 32" width="32" height="32" aria-hidden="true">${AIR_SVG[h?'h':'p'](airCol(a))}</svg></div><span>${esc(airName(a))} · ${esc(airAltS(a))}</span></div>`}
function airPop(a,now,fc){const age=Math.max(0,Math.round(now-a.at)),vr=a.vr?(a.vr>0?'steigt ':'sinkt ')+(Math.abs(a.vr)*.00508).toFixed(1)+' m/s':null;
  const rows=[['Rufzeichen',a.cs],['Kennzeichen',a.reg],['Typ',a.desc||a.type],['Transponder',String(a.hex||'').toUpperCase()],['Höhe',airAltL(a)],['Geschwindigkeit',a.gs!=null?Math.round(a.gs*1.852)+' km/h':null],['Kurs',a.trk!=null?a.trk+'°':null],['Vertikal',vr],
    ['Nächster fester Standort',a.near?`${a.near.n} (${(a.near.m/1000).toFixed(1)} km)`:null],['Distanz zum PLS',a.pls_m!=null?(a.pls_m/1000).toFixed(1)+' km Luftlinie':null],['Standort',fc?fc(a.lat,a.lon):null],['Letzte Position',fmtT(a.at)+` (vor ${age} s)`]];
  return`<div class="airpop"><b>${esc(airName(a))}</b><span class="t">${a.rescue?'Rettungshelikopter':a.cat==='A7'?'Helikopter':'Luftfahrzeug'} · ADS-B</span><table>${rows.filter(r=>r[1]).map(r=>`<tr><td>${r[0]}</td><td>${esc(r[1])}</td></tr>`).join('')}</table></div>`}
// Marker je Transponder-Adresse nachführen statt neu zeichnen (ein offenes Popup bleibt offen und wird aktualisiert); fehlende entfernen
function airSync(map,layer,list,now,fc){const m=layer._air||(layer._air=new Map()),seen=new Set(),b=map.getBearing?map.getBearing():0;
  list.forEach(a=>{seen.add(a.hex);const ll=[a.lat,a.lon],html=airIconHtml(a,now-a.at,(a.trk||0)-b),ic=()=>L.divIcon({className:'',html,iconSize:[32,32],iconAnchor:[16,16]});let x=m.get(a.hex);
    if(!x){x={mk:L.marker(ll,{icon:ic(),zIndexOffset:1500,keyboard:false}).bindPopup(''),tr:L.polyline([],{weight:2,opacity:.75,dashArray:'4 4',interactive:false})};x.tr.addTo(layer);x.mk.addTo(layer);m.set(a.hex,x)}
    else{x.mk.setLatLng(ll);if(x.html!==html)x.mk.setIcon(ic())}
    x.html=html;x.a=a;x.tr.setLatLngs([...(a.trail||[]).map(p=>[p[1],p[2]]),ll]).setStyle({color:airCol(a)});x.mk.setPopupContent(airPop(a,now,fc))});
  m.forEach((x,k)=>{if(!seen.has(k)){layer.removeLayer(x.mk);layer.removeLayer(x.tr);m.delete(k)}})}
// Karte gedreht (leaflet-rotate): Symbole nachdrehen, damit der Kurs stimmt
function airRot(map,layer){const b=map.getBearing?map.getBearing():0;(layer._air||new Map()).forEach(x=>{const e=x.mk.getElement&&x.mk.getElement(),s=e&&e.querySelector('.airr');if(s)s.style.transform=`rotate(${Math.round((x.a.trk||0)-b)}deg)`})}
// Quellenangabe der Datenquelle unten auf der Karte (ODbL u. a.)
function airAttr(map,d){const ac=map.attributionControl;if(!ac)return;const t=d&&d.src&&(d.air||[]).length?`Luftlage: <a href="${esc(d.src.site)}" target="_blank" rel="noopener">${esc(d.src.name)}</a>`:'';if(map._airAt===t)return;if(map._airAt)ac.removeAttribution(map._airAt);if(t)ac.addAttribution(t);map._airAt=t}
const fmtT=ts=>new Date(ts*1000).toLocaleTimeString('de-CH',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
/* ---------- Suchgebiet entlang des Flusslaufs (Einsatz-App und öffentliche Ansicht) ---------- */
const BANK_M=30;   // Breite des Ufergebiets pro Seite in Metern
const SNAP_M=300;  // maximale Distanz PLS zum Gewässer
// Radius des Gewässernetzes für eine Driftdistanz D, auf 1 km aufgerundet, damit alle Geräte dieselbe Abfrage stellen (Server-Cache)
const netRadius=D=>Math.ceil(Math.min(Math.max(5000,D*1.3),30000)/1000)*1000;
const geoDist=(a,b)=>L.latLng(a.lat,a.lon).distanceTo([b.lat,b.lon]);
// feste Standorte mit pts (Issue #96, #134): line 1 = Linie (z. B. Fahrrinne, 2–200 Punkte, breiter Strich), sonst Bereich (Fläche); o = Stil der Fläche
const placeGeo=(f,o)=>f.line?L.polyline(f.pts,{...o,weight:Math.max(4,(o.weight||0)+2),opacity:.95,fill:false}):L.polygon(f.pts,o);
const lineKm=pts=>pts.reduce((s,p,i)=>i?s+L.latLng(pts[i-1]).distanceTo(p):0,0)/1000;
// Markierungen der Einsatzleitung (pois): Kategorie im Fenster «Ansicht» je Art (Linien: Issue #150)
const poiVk=p=>p.kind==='area'?'ar':p.kind==='line'?'ln':'pt';
// Punkt auf halber Länge einer Linie (wie lineMid im Server)
function lineMidLL(pts){const d=[0];for(let i=1;i<pts.length;i++)d.push(d[i-1]+L.latLng(pts[i-1]).distanceTo(pts[i]));const h=d[d.length-1]/2;
  for(let i=1;i<pts.length;i++)if(d[i]>=h){const f=d[i]>d[i-1]?(h-d[i-1])/(d[i]-d[i-1]):0;return L.latLng(pts[i-1][0]+(pts[i][0]-pts[i-1][0])*f,pts[i-1][1]+(pts[i][1]-pts[i-1][1])*f)}return L.latLng(pts[0])}
const kmTxt=km=>km.toFixed(km<10?2:1);
const placeShape=f=>!f.pts?'':f.line?'Linie, ca. '+lineKm(f.pts).toFixed(lineKm(f.pts)<10?2:1)+' km':'Bereich';
// Overpass-Antwort → Wege und Verknüpfungen über gemeinsame Knoten
function riverNet(j){if(!j||!j.elements)throw new Error('ungültige Antwort');
  const ways=j.elements.filter(e=>e.geometry&&e.nodes),byNode=new Map();
  ways.forEach(w=>w.nodes.forEach((n,k)=>{(byNode.get(n)||byNode.set(n,[]).get(n)).push({w,k})}));return{ways,byNode}}
// Gewässernetz über den Server (r = overpass bzw. public/net). Erreicht der Server Overpass nicht (502 mit query), fragt das Gerät
// Overpass selbst mit der Abfrage und den Servern (Reihenfolge aus der Verwaltung) des Servers (andere IP-Adresse, Issue #28) und
// liefert das Ergebnis über put für den Cache aller ab. Mit put (Einsatz-App, angemeldet) meldet es jeden Versuch für die Statistik (overpass/stat, Issue #45).
async function overpassNet(r,body,put){try{return await api(r,body)}catch(e){const q=e.data&&e.data.query;if(!q)throw e;
  const tries=[],stat=()=>{if(put&&tries.length)api('overpass/stat',{results:tries}).catch(()=>{})};
  for(const url of e.data.servers||[]){const ac=new AbortController(),to=setTimeout(()=>ac.abort(),30000),t=Date.now(),bad=err=>tries.push({url,ok:0,ms:Date.now()-t,err});try{
    const res=await fetch(url,{method:'POST',body:new URLSearchParams({data:q}),signal:ac.signal});if(!res.ok){bad('HTTP '+res.status);continue}
    const j=await res.json();if(!j.elements){bad('Ungültige Antwort');continue}
    tries.push({url,ok:1,ms:Date.now()-t});stat();
    if(put)api(put,{...body,elements:j.elements.map(({type,id,nodes,geometry,tags})=>({type,id,nodes,geometry,tags:{waterway:tags&&tags.waterway,name:tags&&tags.name}}))}).catch(()=>{});
    return j}catch(x){bad(x.name==='AbortError'?'Zeitüberschreitung':x instanceof SyntaxError?'Ungültige Antwort':'Keine Antwort')}finally{clearTimeout(to)}}
  stat();
  throw e}}
// Weg ab Punkt k flussabwärts über rem Meter, an Verzweigungen alle Äste. Liefert Linien ab dem Startpunkt, null = von hier aus schon verfolgt.
// Verzweigungen: am Ende des Weges alle anschliessenden Wege, unterwegs Wege, die an einem Knoten beginnen (Issue #40: Seitenarm, Kanal;
// in OSM beginnt der Arm oft mitten im Weg des Hauptflusses). Jede Linie endet am ersten Posten (ctx.posts, ctx.cut = dessen Name).
// ctx.done verhindert, dass derselbe Einstieg (Weg, Knoten) mehrfach verfolgt wird, wenn Arme wieder zusammenfliessen.
function riverFollow(net,w,k,rem,seen,ctx){const key=w.id+':'+k;if((ctx.done.get(key)??-1)>=rem)return null;ctx.done.set(key,rem);
  const pts=[w.geometry[k]],out=[],last=w.geometry.length-1;seen=new Set(seen).add(w.id);
  const post=p=>{const po=ctx.posts.find(q=>geoDist(p,q)<120);if(po)ctx.cut=po.name;return!!po};
  const sub=(e,r)=>{const s=riverFollow(net,e.w,e.k,r,seen,ctx);return s?s.map(b=>pts.concat(b.slice(1))):[]};
  if(post(pts[0]))return[pts];
  for(let j=k;j<last;j++){
    for(const e of net.byNode.get(w.nodes[j])||[])if(e.k===0&&e.w!==w&&!seen.has(e.w.id))out.push(...sub(e,rem));
    const a=w.geometry[j],b=w.geometry[j+1],d=geoDist(a,b);
    if(d>=rem){const f=rem/d;pts.push({lat:a.lat+(b.lat-a.lat)*f,lon:a.lon+(b.lon-a.lon)*f});return[pts,...out]}
    pts.push(b);rem-=d;if(post(b))return[pts,...out]}
  const nx=(net.byNode.get(w.nodes[last])||[]).filter(e=>!seen.has(e.w.id)&&e.k<e.w.geometry.length-1).flatMap(e=>sub(e,rem));
  return nx.length?[...nx,...out]:[pts,...out]}
function corridor(pts,w){const C=L.CRS.EPSG3857,k=w/Math.cos(pts[0].lat*Math.PI/180),P=pts.map(p=>C.project(L.latLng(p.lat,p.lon))),A=[],B=[];
  P.forEach((p,j)=>{const a=P[Math.max(0,j-1)],b=P[Math.min(P.length-1,j+1)];let dx=b.x-a.x,dy=b.y-a.y;const n=Math.hypot(dx,dy)||1;dx/=n;dy/=n;
    A.push(C.unproject(L.point(p.x-dy*k,p.y+dx*k)));B.push(C.unproject(L.point(p.x+dy*k,p.y-dx*k)))});return A.concat(B.reverse())}
// Suchgebiet ab PLS (i) über die Driftdistanz D, endet am ersten Posten (posts: [{lat,lon,name}]); null = kein Gewässer nahe der PLS
function riverArea(net,i,D,posts){let best=null,bd=1e12;net.ways.forEach(w=>w.geometry.forEach((g,k)=>{const d=geoDist(g,i);if(d<bd){bd=d;best={w,k}}}));
  if(!best||bd>SNAP_M)return null;const r={lines:[],polys:[],cutBy:null,maxLen:0};
  const ctx={posts,done:new Map(),cut:null};
  (riverFollow(net,best.w,best.k,D,new Set(),ctx)||[]).forEach(pts=>{
    if(pts.length<2)return;let len=0;for(let j=1;j<pts.length;j++)len+=geoDist(pts[j-1],pts[j]);r.maxLen=Math.max(r.maxLen,len);
    r.lines.push(pts);r.polys.push(corridor(pts,BANK_M))});
  r.cutBy=ctx.cut;return r}
// Suchgebiet aus mehreren PLS (Issue #24): srcs = sources des Servers ([{lat,lon,at,own,names}], vermisste Rettlinge bzw. PLS des Einsatzes),
// nets = Map je PLS (srcKey) mit dem Gewässernetz, get(s,R) liefert die Overpass-Antwort für einen PLS, redraw nach dem Laden.
// Ergebnis: polys/lines (entlang Gewässer), circles (Rückfall {lat,lon,r}), info (Text), parts (je PLS: key, names, lat/lon, polys, circles), rs = Rettlinge (für den Hinweis ohne PLS)
const srcKey=s=>s.lat.toFixed(6)+','+s.lon.toFixed(6);
// Schlüssel eines Teilgebiets: PLS mit Zeitpunkt, wie die Zusammenfassung in plsSources()
const partKey=(lat,lon,at)=>lat.toFixed(6)+','+lon.toFixed(6)+'@'+at;
const fmtDT=ts=>new Date(ts*1000).toLocaleString('de-CH',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
function searchArea(srcs,nets,flow,now,posts,get,redraw,rs){const r={polys:[],lines:[],circles:[],info:[],parts:[]};
  if(!srcs.length){const miss=(rs||[]).filter(x=>!x.rescued_at&&x.lat==null);
    r.info.push(!(rs||[]).length?'Suchgebiet: Kein PLS gesetzt.':miss.length?'Suchgebiet: Kein PLS für '+miss.map(x=>'«'+x.name+'»').join(', ')+'. PLS des Einsatzes oder der Rettlinge setzen.':'Suchgebiet: Alle Rettlinge gefunden.');return r}
  if(!flow){r.info.push('Suchgebiet: Fliessgeschwindigkeit fehlt.');return r}
  srcs.forEach(s=>{const D=flow*(now-s.at),k=srcKey(s),who=s.names&&s.names.length?' «'+s.names.join('», «')+'»':'',lbl=srcs.length>1||who?`Suchgebiet ab PLS${who} (${fmtDT(s.at)}): `:'Suchgebiet: ';
    if(D<=0){r.info.push(lbl+'Der Zeitpunkt des PLS liegt in der Zukunft.');return}
    const p={key:partKey(s.lat,s.lon,s.at),names:s.names||[],lat:s.lat,lon:s.lon,polys:[],circles:[]};r.parts.push(p);
    let n=nets.get(k);
    if(!n||(!n.loading&&((!n.fail&&n.R<D*1.1&&n.R<30000)||(n.fail&&Date.now()>n.retryAt)))){const R=netRadius(D),old=n;n={...(old||{}),loading:true};nets.set(k,n);
      get(s,R).then(j=>({R,...riverNet(j)})).catch(e=>({R,fail:true,err:e.message,retryAt:Date.now()+20000})).then(v=>{if(nets.get(k)===n){nets.set(k,v);redraw()}})}
    const circle=why=>{const c={lat:s.lat,lon:s.lon,r:D};r.circles.push(c);p.circles.push(c);r.info.push(lbl+why+' Darstellung als Kreis, Radius '+Math.round(D)+' m.')};
    if(n.fail)return circle('Gewässerdaten nicht verfügbar ('+n.err+'), neuer Versuch folgt.');
    if(!n.ways)return circle('Gewässerdaten werden geladen.');
    const a=riverArea(n,s,D,posts);if(!a)return circle('Kein Gewässer nahe der PLS.');
    r.polys.push(...a.polys);r.lines.push(...a.lines);p.polys.push(...a.polys);
    r.info.push(lbl+`${(a.maxLen/1000).toFixed(1)} km Flusslauf ab PLS (Driftdistanz ${Math.round(D)} m), Ufergebiet ${BANK_M} m je Seite`+(a.cutBy?`, endet am Posten ${a.cutBy}.`:'.'))});
  // Kurzfassung bei mehreren PLS (Hinweis unten in der Karte, Details per Klick)
  if(srcs.length>1){const riv=r.parts.filter(p=>p.polys.length).length,circ=r.parts.length-riv,none=srcs.length-r.parts.length;
    r.short=`Suchgebiet aus ${srcs.length} PLS: ${riv} entlang Gewässer`+(circ?`, ${circ} als Kreis`:'')+(none?`, ${none} ohne Gebiet`:'')}
  return r}
/* ---------- Koordinaten ---------- */
// Standard in der Verwaltung (settings coord_sys), pro Person übersteuerbar (users.coord_sys); Schlüssel wie auf dem Server (COORDS)
const COORD_SYS={lv95:'LV95 (CH1903+, E / N)',lv03:'LV03 (CH1903, Y / X)',wgs84:'WGS84 Dezimalgrad',wgs84dms:'WGS84 Grad, Minuten, Sekunden'};
// WGS84 → LV95 mit den Näherungsformeln von swisstopo (Genauigkeit ca. 1 m, nur in der Schweiz sinnvoll)
function toLV95(lat,lon){const p=(lat*3600-169028.66)/1e4,l=(lon*3600-26782.5)/1e4;
  return[2600072.37+211455.93*l-10938.51*l*p-.36*l*p*p-44.54*l*l*l,1200147.07+308807.95*p+3745.25*l*l+76.63*p*p-194.56*l*l*p+119.79*p*p*p]}
const thou=n=>String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g,"'");
const dms=(v,pos,neg)=>{const a=Math.abs(v),d=Math.floor(a),m=Math.floor((a-d)*60),s=((a-d)*60-m)*60;return `${d}°${String(m).padStart(2,'0')}'${s.toFixed(1).padStart(4,'0')}"${v<0?neg:pos}`};
function fmtC(lat,lon,sys){if(lat==null||lon==null||sys==='none')return''; // none: persönlich ausgeblendet
  if(sys==='wgs84')return `${(+lat).toFixed(5)}, ${(+lon).toFixed(5)}`;
  if(sys==='wgs84dms')return `${dms(lat,'N','S')} ${dms(lon,'E','W')}`;
  const[e,n]=toLV95(lat,lon);return sys==='lv03'?`${thou(e-2e6)} / ${thou(n-1e6)}`:`${thou(e)} / ${thou(n)}`}
// Eingabe von Koordinaten (Vorlagen der externen Einsatzkräfte): WGS84 dezimal «47.38, 8.05», LV95 «2'660'000 / 1'250'000» oder LV03 «660000 250000»;
// LV95/LV03 → WGS84 mit den Näherungsformeln von swisstopo (ca. 1 m). Ergebnis {lat, lon} oder null
function parseC(s){const n=String(s||'').replace(/['’]/g,'').trim().split(/\s*[,;/]\s*|\s+/).filter(Boolean).map(Number);if(n.length!==2||n.some(x=>!isFinite(x)))return null;
  let[a,b]=n;if(Math.abs(a)<=90&&Math.abs(b)<=180)return{lat:a,lon:b};
  if(a<b)[a,b]=[b,a];if(a<1e6){a+=2e6;b+=1e6} // LV03 → LV95; Ost liegt in der Schweiz immer über Nord
  if(a<2.4e6||a>2.9e6||b<1.05e6||b>1.35e6)return null;
  const y=(a-2.6e6)/1e6,x=(b-1.2e6)/1e6;
  return{lat:(16.9023892+3.238272*x-.270978*y*y-.002528*x*x-.0447*y*y*x-.014*x*x*x)*100/36,lon:(2.6779094+4.728982*y+.791484*y*x+.1306*y*x*x-.0436*y*y*y)*100/36}}
// Rechte der angemeldeten Person aus ihren Benutzerrollen (Issue #63, me.perms vom Server); nur zum Ausblenden, der Server prüft selbst
const can=p=>!!(typeof me!=='undefined'&&me&&me.perms&&me.perms.includes(p));
// Einsatztyp einer Zeile (Issue #90): Name und Farbe vom Server; ältere gesicherte Reviews kennen nur is_exercise
const kindOf=x=>x.kind_name?{name:x.kind_name,color:x.kind_color}:{name:x.is_exercise?'Übung':'Einsatz',color:x.is_exercise?'#1d7fd1':'#ff4d2e'};
const kindFg=c=>{const m=/^#(..)(..)(..)$/.exec(c||'');if(!m)return'#fff';const[r,g,b]=m.slice(1).map(x=>parseInt(x,16));return r*.299+g*.587+b*.114>165?'#08161e':'#fff'};
const kindBadge=x=>{const k=kindOf(x);return`<span class="badge" style="background:${esc(k.color)};color:${kindFg(k.color)}">${esc(k.name)}</span>`};
const ADMIN_PERMS=['users','uroles','groups','avail','cfg_inc','ex_plan','cfg_func','cfg_ai','cfg_map','stats','cfg_my','aar','aar_rel','aar_cfg','aar_arch','legal','anon','applog']; // Rechte mit einem Bereich in der Verwaltung
const roleNames=m=>(m&&m.uroles||[]).map(r=>r.name).join(', ')||'keine Benutzerrolle';
// Abmelden: Server meldet die Sitzung ab (Gerätesitzung der App bzw. Browser-Token, Route logout), danach die App benachrichtigen (beendet das Konto, Issue #108)
function logout(){const t=token;if(t)fetch(`${API}?r=logout`,{method:'POST',headers:{'X-Token':t},body:'{}',keepalive:true}).catch(()=>{});
  token=null;localStorage.removeItem('sw_token');['sw_last','sw_boot'].forEach(k=>localStorage.removeItem(k));swNative('swLogout')} // letzter Stand gehört der abgemeldeten Person
/* ---------- Anmeldung ---------- */
function loginView(el,title,done){el.innerHTML=`<div class="card"><h1>${esc(title)}</h1><input id="u" placeholder="Benutzername" autocomplete="username"><input id="p" type="password" placeholder="Passwort" autocomplete="current-password"><button class="pri" id="go">Anmelden</button><a class="mut lglk" href="legal.html">Impressum · Lizenzen · Datenschutz</a></div>`;
  const go=async()=>{try{token=(await api('login',{username:$('#u').value,password:$('#p').value})).token;localStorage.setItem('sw_token',token);done()}catch(e){fail(e)}};
  $('#go').onclick=go;$('#p').onkeydown=e=>{if(e.key==='Enter')go()}}
/* ---------- Dialoge ---------- */
// Einfacher modaler Dialog; save(d) wirft bei Fehlern, dann bleibt der Dialog offen
// Kürzel und Farbe externer Einsatzkräfte und ihrer Vorlagen (Issue #39): Felder im Dialog, Wert lesen; Farbe ohne Häkchen = keine
const extStyleFields=x=>`<div class="row" style="align-items:flex-end"><label class="mut" style="flex:1">Kürzel (max. 4 Zeichen, auf dem Marker)<input id="xK" maxlength="4" placeholder="z. B. FW" value="${esc(x.abbr||'')}"></label><label class="mut" style="flex:0 0 auto"><span><input type="checkbox" id="xFc" ${x.color?'checked':''}> Farbe</span><input type="color" id="xF" value="${esc(x.color||'#e53935')}" style="width:4rem"></label></div><div class="mut">Die Farbe füllt den Marker, der Rand zeigt die Spezialrolle.</div>`;
function extStyleVal(d){return{abbr:$('#xK',d).value.trim(),color:$('#xFc',d).checked?$('#xF',d).value:''}}
function extStyleBind(d){$('#xF',d).addEventListener('input',()=>{$('#xFc',d).checked=true});return d}
function dlg(title,body,save,okText='Speichern',xText='Abbrechen',onX){const d=document.createElement('div');d.className='dlg';d.setAttribute('role','dialog');d.setAttribute('aria-modal','true');d.setAttribute('aria-label',title);
  d.innerHTML=`<div class="card"><h2 style="margin:0">${esc(title)}</h2>${body}<div class="row"><button class="pri" data-ok>${esc(okText)}</button><button data-x>${esc(xText)}</button></div></div>`;
  document.body.appendChild(d);const f=d.querySelector('input,select');f&&f.focus();
  d.querySelector('[data-x]').onclick=()=>{d.remove();onX&&onX()};
  // während des Speicherns gesperrt: ein zweiter Klick schickte sonst dieselbe Anfrage nochmals (z. B. Anonymisieren doppelt)
  const ok=d.querySelector('[data-ok]');ok.onclick=async()=>{if(ok.disabled)return;ok.disabled=true;try{await save(d);d.remove()}catch(e){fail(e)}finally{ok.disabled=false}};return d}
/* ---------- Abwesenheiten (Issue #91): abwesend bzw. Einsatzfähigkeit ausgesetzt = nicht aufgeboten, kein Alarm; laufende Einsätze bleiben sichtbar ---------- */
const ABS_KIND={abs:'Abwesend',sus:'Einsatzfähigkeit ausgesetzt'};
const absDT=ts=>new Date(ts*1000).toLocaleString('de-CH',{weekday:'short',day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit'});
const absUntil=a=>a.until!=null||a.until_at!=null?'bis '+absDT(a.until??a.until_at):'bis auf Weiteres';
// a = laufende Abwesenheit {kind, until, note} (me.absent, users[].absent, my_abs)
const absChip=a=>a?`<span class="abschip" title="${esc(ABS_KIND[a.kind]||ABS_KIND.abs)} ${esc(absUntil(a))}${a.note?' – '+esc(a.note):''}">⏸ ${esc(a.kind==='sus'?'ausgesetzt':'abwesend')} ${esc(absUntil(a))}</span>`:'';
const absLocal=ts=>new Date(ts*1000-new Date().getTimezoneOffset()*60000).toISOString().slice(0,16),absTs=v=>v?Math.floor(new Date(v).getTime()/1000)||0:0;
// Liste der Einträge (Route absences) mit Entfernen; who = Namen zeigen
function absRows(rs,who){return rs.length?rs.map(a=>`<div class="absrow"><span><b>${esc(ABS_KIND[a.kind]||ABS_KIND.abs)}</b>${who?' · '+esc(a.name)+(typeof gdots==='function'?gdots(a.user_id):''):''}<br><small class="mut">${a.now?'seit':'ab'} ${esc(absDT(a.from_at))}, ${esc(absUntil(a))}${a.note?' · '+esc(a.note):''}${who&&a.created_by!==a.user_id&&a.by_name?' · erfasst von '+esc(a.by_name):''}</small></span><span class="row" style="margin:0;flex:0 0 auto"><button data-abe="${a.id}">Ändern</button><button data-abx="${a.id}" aria-label="Eintrag entfernen">${a.now?'Beenden':'Entfernen'}</button></span></div>`).join(''):'<div class="mut">Keine Einträge.</div>'}
// Dialog erfassen/ändern: a = bestehender Eintrag oder null; people = Auswahl der Person (Verwaltung) oder null (eigene); done nach dem Speichern
function absDialog(a,people,done){const now=Date.now()/1000,mo=new Date();mo.setDate(mo.getDate()+1);mo.setHours(6,0,0,0);
  const d=dlg(a?'Eintrag ändern':'Abwesenheit erfassen',`${people&&!a?`<label class="mut">Person<select id="abU">${people.map(u=>`<option value="${u.id}">${esc(u.name)}</option>`).join('')}</select></label>`:''}
    <label class="mut">Art<select id="abK">${Object.entries(ABS_KIND).map(([k,v])=>`<option value="${k}" ${(a?a.kind:'abs')===k?'selected':''}>${esc(v)}</option>`).join('')}</select></label>
    <div class="mut">Beides wirkt gleich: nicht aufgeboten und kein Alarm. Laufende Einsätze bleiben sichtbar, freiwillig beitreten ist möglich.</div>
    <label class="mut">Von<input id="abF" type="datetime-local" value="${absLocal(a?a.from_at:now)}"></label>
    <label class="mut">Bis (leer = bis auf Weiteres)<input id="abT" type="datetime-local" value="${a&&a.until_at?absLocal(a.until_at):''}"></label>
    <div class="row"><button data-abq="m">Bis morgen 06:00</button><button data-abq="w">1 Woche</button><button data-abq="x">Bis auf Weiteres</button></div>
    <label class="mut">Bemerkung (optional)<input id="abN" maxlength="200" value="${esc(a&&a.note||'')}" placeholder="z. B. Ferien, Krankheit"></label>`,async d=>{
    const f=absTs($('#abF',d).value),t=absTs($('#abT',d).value);
    await api('absence/save',{id:a?a.id:undefined,user_id:people&&!a?+$('#abU',d).value:undefined,from:f,until:t||null,kind:$('#abK',d).value,note:$('#abN',d).value});toast('Gespeichert.','ok');done&&done()});
  d.querySelectorAll('[data-abq]').forEach(b=>b.onclick=()=>{const q=b.dataset.abq,fr=absTs($('#abF',d).value)||now;
    $('#abT',d).value=q==='x'?'':absLocal(q==='m'?Math.max(mo.getTime()/1000,fr+3600):fr+7*86400)});return d}
// Klicks einer Liste aus absRows: rs = Einträge, people/done wie absDialog
function absBind(el,rs,done){el.querySelectorAll('[data-abe]').forEach(b=>b.onclick=()=>absDialog(rs.find(a=>a.id===+b.dataset.abe),null,done));
  el.querySelectorAll('[data-abx]').forEach(b=>b.onclick=async()=>{const a=rs.find(x=>x.id===+b.dataset.abx);if(!confirm(a.now?'Eintrag jetzt beenden?':'Eintrag entfernen?'))return;try{await api('absence/remove',{id:a.id});toast(a.now?'Beendet.':'Entfernt.','ok');done&&done()}catch(e){fail(e)}})}
// Eigenes Passwort ändern; forced = nach Zurücksetzen durch die Verwaltung (nur Abmelden als Alternative)
function pwDialog(forced,done){
  dlg('Passwort ändern',`${forced?'<div class="mut">Dein Passwort wurde neu gesetzt. Bitte vergib jetzt ein eigenes Passwort.</div>':''}
    <label class="mut">${forced?'Erhaltenes Startpasswort':'Aktuelles Passwort'}<input id="pwO" type="password" autocomplete="current-password"></label>
    <label class="mut">Neues Passwort<input id="pwN" type="password" autocomplete="new-password"></label>
    <label class="mut">Neues Passwort wiederholen<input id="pwR" type="password" autocomplete="new-password"></label>`,
    async d=>{const n=$('#pwN',d).value;if(n!==$('#pwR',d).value)throw new Error('Die neuen Passwörter stimmen nicht überein.');
      await api('me/password',{old:$('#pwO',d).value,new:n});toast('Passwort geändert.');done&&done()},
    'Speichern',forced?'Abmelden':'Abbrechen',forced?()=>{logout();done&&done()}:null)}
/* ---------- Einsatzlog im Gross-Modus ---------- */
let big=null;
const KIND_GROUPS={all:['Alle Einträge',null],join:['Beitritt / Austritt',['accept','decline','leave','kick']],role:['Rollen',['role']],move:['Fahrten / Suchgebiet',['move','area']],resc:['Rettlinge',['rescuee','resmark','rescued','mark','markdel']],msg:['Nachrichten',['message']],poi:['Markierungen / Bereiche',['poi','poidel']],inc:['Einsatz / Fliessgeschwindigkeit / Aufzeichnung / Öffentlich',['start','end','flow','rec','pub','anon','air']]};
const KIND_COL={start:'#ff4d2e',end:'#ff4d2e',accept:'#35c27a',decline:'#8fb0bd',leave:'#8fb0bd',role:'#e6a100',flow:'#1d7fd1',message:'#cfd8dc',move:'#c78cff',area:'#ffd23f',kick:'#ff4d2e',mark:'#2ee6d6',markdel:'#8fb0bd',poi:'#ff9f1c',poidel:'#8fb0bd',rescuee:'#ff6b6b',resmark:'#ff9f1c',rescued:'#35c27a',rec:'#e05cb0',pub:'#7dd3fc',ext:'#a3e635',anon:'#8fb0bd',air:'#ffd23f'};
// anon (Issue #54, Verwaltung): async on => Fassung laden (anonymisiert oder nicht), dann Knopf «Anonym» in der Kopfzeile
function openBigLog(title,get,live,anon){big={title,get,live,anon,anonOn:false,desc:true,filter:'all',cache:[]};let o=$('#bigLog');if(o)o.remove();
  o=document.createElement('div');o.id='bigLog';o.setAttribute('role','dialog');o.setAttribute('aria-label','Einsatzlog');
  o.innerHTML=`<div id="bigHead"><h2 id="bigT"></h2><select id="bigF" aria-label="Filter">${Object.entries(KIND_GROUPS).map(([k,v])=>`<option value="${k}">${v[0]}</option>`).join('')}</select>${anon?'<button id="bigA" aria-pressed="false" title="Namen von Einsatzkräften, Rettlingen und Externen durch Bezeichnungen ersetzen">Anonym</button>':''}<button id="bigS"></button><button id="bigFs">Vollbild</button><button id="bigX" class="pri">Schliessen</button></div><div id="bigList"></div>`;
  document.body.appendChild(o);
  $('#bigF').onchange=e=>{big.filter=e.target.value;renderBigLog()};
  if(anon)$('#bigA').onclick=async()=>{const b=$('#bigA'),on=!big.anonOn;b.disabled=true;try{await anon(on);big.anonOn=on;b.setAttribute('aria-pressed',on);b.textContent=(on?'✓ ':'')+'Anonym'}catch(e){fail(e)}b.disabled=false;renderBigLog()};$('#bigS').onclick=()=>{big.desc=!big.desc;renderBigLog()};
  $('#bigFs').onclick=()=>{document.fullscreenElement?document.exitFullscreen():o.requestFullscreen&&o.requestFullscreen().catch(()=>{})};$('#bigX').onclick=closeBigLog;
  renderBigLog()}
function closeBigLog(){if(document.fullscreenElement)document.exitFullscreen().catch(()=>{});const o=$('#bigLog');if(o)o.remove();big=null}
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&big)closeBigLog()});
function renderBigLog(){const o=$('#bigLog');if(!big||!o)return;const got=big.get();if(got)big.cache=got;
  const g=KIND_GROUPS[big.filter][1],all=big.cache,l=all.filter(x=>!g||g.includes(x.kind)).sort((a,b)=>(big.desc?-1:1)*(a.ts-b.ts||a.id-b.id)); // Log und Nachrichten gemischt, daher nach Zeit
  $('#bigT').textContent=`${big.title} · ${l.length} von ${all.length} Einträgen${big.live&&!got?' · Einsatz beendet':''}`;
  $('#bigS').textContent=big.desc?'Neueste zuerst':'Älteste zuerst';
  $('#bigList').innerHTML=l.map(x=>`<div class="lg" style="border-left-color:${KIND_COL[x.kind]||'#888'}"><span class="t">${big.live?fmtT(x.ts):new Date(x.ts*1000).toLocaleString('de-CH')}</span><b>${esc(x.name||'System')}</b><span>${esc(x.text)}</span></div>`).join('')||'<div class="mut">Keine Einträge</div>'}
/* ---------- AI-Assistent (Issue #111): Fenster mit Vorschlag, Übernehmen / Verwerfen / Neu, zusätzlichem Wunsch und freien Fragen ---------- */
// Modus der Person je Funktion und Bereich (Einsatztyp-ID oder 'adm'; me.ai vom Server: aiMe, schon begrenzt auf das Erlaubte und die eigene Wahl): off, cmd (auf Abruf), comp (begleitend)
const aiM=(fn,ctx)=>(typeof me!=='undefined'&&me&&me.ai&&me.ai[fn]&&me.ai[fn].modes[String(ctx)])||'off';
const aiOn=(fn,ctx)=>aiM(fn,ctx)!=='off';
// je Einsatz: x.ai vom Server (mit der Spezialrolle der Person im Einsatz), sonst nach Einsatztyp
const aiMi=(fn,x)=>x&&x.ai?(x.ai[fn]||'off'):aiM(fn,x&&x.kind_id);
// Antworttext: Zeilenumbrüche bleiben, **fett** und Zwischentitel (#) werden hervorgehoben, sonst nur Text (kein HTML vom Anbieter)
const aiFmt=t=>esc(t||'').replace(/^#{1,6}\s*(.+)$/gm,'<b>$1</b>').replace(/\*\*([^*\n]+)\*\*/g,'<b>$1</b>');
const aiMeta=r=>`${r.cached?'Gespeicherter Vorschlag vom ':'Erstellt '}${new Date(r.ts*1000).toLocaleString('de-CH',{dateStyle:'short',timeStyle:'short'})}${r.tokens?` · ${r.tokens.toLocaleString('de-CH')} Tokens`:''}${r.anon?' · Namen ersetzt durch Bezeichnungen':''}`;
const aiCopy=async t=>{try{await navigator.clipboard.writeText(t||'');toast('In die Zwischenablage kopiert.')}catch(e){throw new Error('Kopieren nicht möglich, bitte den Text markieren')}};
// o: title, req (fn, id, field, all …), my (Meine Einsätze), pre (bereits geladener Vorschlag), show(r) → HTML, apply(r) (ohne: Kopieren), applyText
// chat: freie Fragen zu den Daten von req (about = req.fn); chatOnly: nur freie Fragen, ohne Auftrag (req null = ohne Daten)
// qLabel/qPh/qRows: Wunschfeld (steht zuoberst); side(el, d): breites Fenster mit Spalte rechts (z. B. Karte), einmal aufgerufen; after(res): nach jedem Anzeigen
// breite Bildschirme: Vorschlag links, freie Fragen rechts (ohne side); schmale: alles untereinander (common.css .aiwide)
function aiPanel(o){let res=o.pre||null,busy=false;const hist=[],only=!!o.chatOnly,chat=only||!!o.chat,sc=chat&&!only&&!o.side;
  const ch=chat?`<div class="aichat"><div class="aith" data-aith></div><label class="mut">${only?'Deine Frage':'Eigene Frage dazu (freie Eingabe)'}<textarea data-aic rows="${sc?3:2}" maxlength="2000" placeholder="z. B. Wo gab es Verzögerungen?"></textarea></label><div class="row"><button data-aisend>Fragen</button>${only?'':'<span class="mut" style="font-size:.8rem">Fragen und Antworten werden nicht gespeichert.</span>'}</div></div>`:'';
  const d=dlg('✨ '+o.title,`<div class="aiw${o.side||sc?' aiwide':''}"><div class="aimain">${only?'':`<label class="mut aiql">${esc(o.qLabel||'Zusätzlicher Wunsch (optional)')}<textarea data-aiq rows="${o.qRows||3}" maxlength="2000" placeholder="${esc(o.qPh||'z. B. kürzer, mehr zur Alarmierung')}"></textarea><span class="aim">Ctrl+Enter oder «↻ Neu» erstellt einen neuen Vorschlag mit diesem Wunsch.</span></label>`}
    <div class="mut aih">${only?'Antworten':'Vorschlag'} des AI-Assistenten: bitte prüfen, bevor du ${only?'sie verwendest':'ihn übernimmst'}. Es gehen nur die Daten dieser Ansicht an den Anbieter${o.req?'':' (hier keine Einsatzdaten)'}.</div>
    ${only?'':`<div class="aio" data-aio></div><div class="mut aim" data-aim></div>`}
    ${sc?'':ch}</div>${o.side?'<div class="aiside" data-aiside></div>':sc?`<div class="aiside aisc">${ch}</div>`:''}</div>`,
    async()=>{if(only){const l=hist.filter(h=>h.a).pop();if(!l)throw new Error('Es gibt noch keine Antwort');return aiCopy(l.a)}
      if(!res)throw new Error('Es gibt noch keinen Vorschlag');if(o.apply)return o.apply(res);return aiCopy(res.text)},
    only?'Letzte Antwort kopieren':o.apply?(o.applyText||'Übernehmen'):'Kopieren',only?'Schliessen':'Verwerfen');
  d.firstElementChild.classList.add('aip');if(o.side||sc)d.firstElementChild.classList.add(o.side?'wide':'wide2');
  const out=$('[data-aio]',d),meta=$('[data-aim]',d),q=$('[data-aiq]',d),ok=$('[data-ok]',d),th=$('[data-aith]',d),cq=$('[data-aic]',d),cs=$('[data-aisend]',d);
  const show=()=>{if(only)return;out.innerHTML=res?(o.show?o.show(res):`<div class="aitx">${aiFmt(res.text)}</div>`):'';meta.textContent=res?aiMeta(res):'';ok.disabled=!res;if(o.after)o.after(res)};
  const run=async fresh=>{if(busy||only)return;busy=true;nb.disabled=true;ok.disabled=true;out.innerHTML='<div class="mut aild">✨ Der AI-Assistent schreibt …</div>';meta.textContent='';if(o.after)o.after(null);
    try{res=await api('ai/ask'+(o.my?'&my=1':''),Object.assign({},o.req,fresh?{fresh:1}:{},q.value.trim()?{q:q.value.trim()}:{}))}
    catch(e){out.innerHTML=`<div class="aierr">${esc(e.message)}</div>`;res=res&&!fresh?res:null}
    busy=false;nb.disabled=false;if(res)show();else{ok.disabled=true;if(o.after)o.after(null)}};
  // freie Fragen: Verlauf nur im Fenster, geht bei jeder Frage mit (der Server nimmt die letzten 10)
  const drawTh=()=>{th.innerHTML=hist.map((h,i)=>`<div class="aiq">${esc(h.q)}</div>${h.a!=null?`<div class="aitx">${aiFmt(h.a)}</div><div class="row aia"><button class="sec" data-aicp="${i}">Kopieren</button><span class="mut aim">${esc(h.m||'')}</span></div>`:h.e?`<div class="aierr">${esc(h.e)}</div>`:'<div class="mut aild">✨ Der AI-Assistent schreibt …</div>'}`).join('');
    th.querySelectorAll('[data-aicp]').forEach(b=>b.onclick=()=>aiCopy(hist[+b.dataset.aicp].a).catch(fail));th.scrollTop=th.scrollHeight};
  const ask=async()=>{const t=cq.value.trim();if(!t||cs.disabled)return;cs.disabled=true;const h={q:t};hist.push(h);cq.value='';drawTh();
    const prev=hist.filter(x=>x!==h&&x.a!=null).map(x=>({q:x.q,a:x.a}));
    try{const r=await api('ai/ask'+(o.my?'&my=1':''),Object.assign(o.req?{about:o.req.fn,id:o.req.id}:{},{fn:'chat',q:t,msgs:prev}));h.a=r.text;h.m=aiMeta(r)}catch(e){h.e=e.message}
    cs.disabled=false;drawTh();cq.focus()};
  if(chat){cs.onclick=ask;cq.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();ask()}}}
  const nb=document.createElement('button');
  if(!only){nb.textContent='↻ Neu';nb.title='Neuen Vorschlag erstellen (mit dem zusätzlichen Wunsch)';nb.onclick=()=>run(true);ok.parentNode.appendChild(nb);
    q.onkeydown=e=>{if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();run(true)}};if(o.side)o.side($('[data-aiside]',d),d);if(res)show();else run(false)}
  else{ok.disabled=false;cq.focus()}
  return d}
// begleitender Modus: Vorschlag im Hintergrund holen (auto=1, der Server nimmt gespeicherte Vorschläge, solange sie gelten); Fehler still
const aiAuto=(req,my)=>api('ai/ask'+(my?'&my=1':''),Object.assign({auto:1},req)).catch(()=>null);
