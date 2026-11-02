/* Gemeinsamer Code für Einsatz-App (index.html) und Verwaltung (admin.html) */
const API='api/index.php';
const $=(s,r=document)=>r.querySelector(s), esc=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
let token=localStorage.getItem('sw_token'),onUnauth=()=>{}; // onUnauth setzt die jeweilige Seite
async function api(r,body){const o={headers:{'X-Token':token||''}};if(body){o.method='POST';o.body=JSON.stringify(body)}
  const res=await fetch(`${API}?r=${r}`,o);const j=await res.json().catch(()=>({error:'Serverfehler'}));
  if(res.status===401&&r!=='login'){token=null;localStorage.removeItem('sw_token');onUnauth()}if(j.error){const e=new Error(j.error);e.data=j;throw e}return j}
const fail=e=>alert(e.message);
/* ---------- Karte (Einsatz-App und Replay der Verwaltung) ---------- */
// Kartenanbieter austauschbar: neuen Eintrag ergänzen bzw. Standard über DEFAULT_BASE wählen
const DEFAULT_BASE='swisstopo_sat'; // Standard-Hintergrund; der Benutzer kann über das Ebenen-Symbol (oben rechts) umschalten, die Wahl wird gemerkt.
const MAP_PROVIDERS={
  swisstopo_sat:{label:'Luftbild (swisstopo)',url:'https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.swissimage/default/current/3857/{z}/{x}/{y}.jpeg',attr:'&copy; swisstopo',maxZoom:18},
  swisstopo:{label:'Landeskarte (swisstopo)',url:'https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.pixelkarte-farbe/default/current/3857/{z}/{x}/{y}.jpeg',attr:'&copy; swisstopo',maxZoom:18},
  osm:{label:'OpenStreetMap',url:'https://tile.openstreetmap.org/{z}/{x}/{y}.png',attr:'&copy; OpenStreetMap',maxZoom:19}
};
const fmtT=ts=>new Date(ts*1000).toLocaleTimeString('de-CH',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
/* ---------- Suchgebiet entlang des Flusslaufs (Einsatz-App und öffentliche Ansicht) ---------- */
const BANK_M=30;   // Breite des Ufergebiets pro Seite in Metern
const SNAP_M=300;  // maximale Distanz PLS zum Gewässer
// Radius des Gewässernetzes für eine Driftdistanz D, auf 1 km aufgerundet, damit alle Geräte dieselbe Abfrage stellen (Server-Cache)
const netRadius=D=>Math.ceil(Math.min(Math.max(5000,D*1.3),30000)/1000)*1000;
const geoDist=(a,b)=>L.latLng(a.lat,a.lon).distanceTo([b.lat,b.lon]);
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
function logout(){token=null;localStorage.removeItem('sw_token')}
/* ---------- Anmeldung ---------- */
function loginView(el,title,done){el.innerHTML=`<div class="card"><h1>${esc(title)}</h1><input id="u" placeholder="Benutzername" autocomplete="username"><input id="p" type="password" placeholder="Passwort" autocomplete="current-password"><button class="pri" id="go">Anmelden</button></div>`;
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
  d.querySelector('[data-ok]').onclick=async()=>{try{await save(d);d.remove()}catch(e){fail(e)}};return d}
// Eigenes Passwort ändern; forced = nach Zurücksetzen durch die Verwaltung (nur Abmelden als Alternative)
function pwDialog(forced,done){
  dlg('Passwort ändern',`${forced?'<div class="mut">Dein Passwort wurde neu gesetzt. Bitte vergib jetzt ein eigenes Passwort.</div>':''}
    <label class="mut">${forced?'Erhaltenes Startpasswort':'Aktuelles Passwort'}<input id="pwO" type="password" autocomplete="current-password"></label>
    <label class="mut">Neues Passwort<input id="pwN" type="password" autocomplete="new-password"></label>
    <label class="mut">Neues Passwort wiederholen<input id="pwR" type="password" autocomplete="new-password"></label>`,
    async d=>{const n=$('#pwN',d).value;if(n!==$('#pwR',d).value)throw new Error('Die neuen Passwörter stimmen nicht überein.');
      await api('me/password',{old:$('#pwO',d).value,new:n});alert('Passwort geändert.');done&&done()},
    'Speichern',forced?'Abmelden':'Abbrechen',forced?()=>{logout();done&&done()}:null)}
/* ---------- Einsatzlog im Gross-Modus ---------- */
let big=null;
const KIND_GROUPS={all:['Alle Einträge',null],join:['Beitritt / Austritt',['accept','decline','leave','kick']],role:['Rollen',['role']],move:['Fahrten / Suchgebiet',['move','area']],resc:['Rettlinge',['rescuee','resmark','rescued','mark','markdel']],msg:['Nachrichten',['message']],poi:['Markierungen / Bereiche',['poi','poidel']],inc:['Einsatz / Fliessgeschwindigkeit / Aufzeichnung / Öffentlich',['start','end','flow','rec','pub']]};
const KIND_COL={start:'#ff4d2e',end:'#ff4d2e',accept:'#35c27a',decline:'#8fb0bd',leave:'#8fb0bd',role:'#e6a100',flow:'#1d7fd1',message:'#cfd8dc',move:'#c78cff',area:'#ffd23f',kick:'#ff4d2e',mark:'#2ee6d6',markdel:'#8fb0bd',poi:'#ff9f1c',poidel:'#8fb0bd',rescuee:'#ff6b6b',resmark:'#ff9f1c',rescued:'#35c27a',rec:'#e05cb0',pub:'#7dd3fc',ext:'#a3e635'};
function openBigLog(title,get,live){big={title,get,live,desc:true,filter:'all',cache:[]};let o=$('#bigLog');if(o)o.remove();
  o=document.createElement('div');o.id='bigLog';o.setAttribute('role','dialog');o.setAttribute('aria-label','Einsatzlog');
  o.innerHTML=`<div id="bigHead"><h2 id="bigT"></h2><select id="bigF" aria-label="Filter">${Object.entries(KIND_GROUPS).map(([k,v])=>`<option value="${k}">${v[0]}</option>`).join('')}</select><button id="bigS"></button><button id="bigFs">Vollbild</button><button id="bigX" class="pri">Schliessen</button></div><div id="bigList"></div>`;
  document.body.appendChild(o);
  $('#bigF').onchange=e=>{big.filter=e.target.value;renderBigLog()};$('#bigS').onclick=()=>{big.desc=!big.desc;renderBigLog()};
  $('#bigFs').onclick=()=>{document.fullscreenElement?document.exitFullscreen():o.requestFullscreen&&o.requestFullscreen().catch(()=>{})};$('#bigX').onclick=closeBigLog;
  renderBigLog()}
function closeBigLog(){if(document.fullscreenElement)document.exitFullscreen().catch(()=>{});const o=$('#bigLog');if(o)o.remove();big=null}
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&big)closeBigLog()});
function renderBigLog(){const o=$('#bigLog');if(!big||!o)return;const got=big.get();if(got)big.cache=got;
  const g=KIND_GROUPS[big.filter][1],all=big.cache,l=all.filter(x=>!g||g.includes(x.kind)).sort((a,b)=>(big.desc?-1:1)*(a.ts-b.ts||a.id-b.id)); // Log und Nachrichten gemischt, daher nach Zeit
  $('#bigT').textContent=`${big.title} · ${l.length} von ${all.length} Einträgen${big.live&&!got?' · Einsatz beendet':''}`;
  $('#bigS').textContent=big.desc?'Neueste zuerst':'Älteste zuerst';
  $('#bigList').innerHTML=l.map(x=>`<div class="lg" style="border-left-color:${KIND_COL[x.kind]||'#888'}"><span class="t">${big.live?fmtT(x.ts):new Date(x.ts*1000).toLocaleString('de-CH')}</span><b>${esc(x.name||'System')}</b><span>${esc(x.text)}</span></div>`).join('')||'<div class="mut">Keine Einträge</div>'}
