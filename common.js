/* Gemeinsamer Code für Einsatz-App (index.html) und Verwaltung (admin.html) */
const API='api/index.php';
const $=(s,r=document)=>r.querySelector(s), esc=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
let token=localStorage.getItem('sw_token'),onUnauth=()=>{}; // onUnauth setzt die jeweilige Seite
async function api(r,body){const o={headers:{'X-Token':token||''}};if(body){o.method='POST';o.body=JSON.stringify(body)}
  const res=await fetch(`${API}?r=${r}`,o);const j=await res.json().catch(()=>({error:'Serverfehler'}));
  if(res.status===401&&r!=='login'){token=null;localStorage.removeItem('sw_token');onUnauth()}if(j.error)throw new Error(j.error);return j}
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
// Weg ab Punkt k flussabwärts über rem Meter, an Verzweigungen alle Äste
function riverFollow(net,w,k,rem,seen){const pts=[w.geometry[k]];seen=new Set(seen).add(w.id);
  for(let j=k+1;j<w.geometry.length;j++){const a=w.geometry[j-1],b=w.geometry[j],d=geoDist(a,b);
    if(d>=rem){const f=rem/d;pts.push({lat:a.lat+(b.lat-a.lat)*f,lon:a.lon+(b.lon-a.lon)*f});return[pts]}
    pts.push(b);rem-=d}
  const nexts=(net.byNode.get(w.nodes[w.nodes.length-1])||[]).filter(e=>!seen.has(e.w.id)&&e.k<e.w.geometry.length-1);
  if(!nexts.length)return[pts];
  return nexts.flatMap(e=>riverFollow(net,e.w,e.k,rem,seen).map(br=>pts.concat(br.slice(1))))}
function corridor(pts,w){const C=L.CRS.EPSG3857,k=w/Math.cos(pts[0].lat*Math.PI/180),P=pts.map(p=>C.project(L.latLng(p.lat,p.lon))),A=[],B=[];
  P.forEach((p,j)=>{const a=P[Math.max(0,j-1)],b=P[Math.min(P.length-1,j+1)];let dx=b.x-a.x,dy=b.y-a.y;const n=Math.hypot(dx,dy)||1;dx/=n;dy/=n;
    A.push(C.unproject(L.point(p.x-dy*k,p.y+dx*k)));B.push(C.unproject(L.point(p.x+dy*k,p.y-dx*k)))});return A.concat(B.reverse())}
// Suchgebiet ab PLS (i) über die Driftdistanz D, endet am ersten Posten (posts: [{lat,lon,name}]); null = kein Gewässer nahe der PLS
function riverArea(net,i,D,posts){let best=null,bd=1e12;net.ways.forEach(w=>w.geometry.forEach((g,k)=>{const d=geoDist(g,i);if(d<bd){bd=d;best={w,k}}}));
  if(!best||bd>SNAP_M)return null;const r={lines:[],polys:[],cutBy:null,maxLen:0};
  riverFollow(net,best.w,best.k,D,new Set()).forEach(pts=>{
    for(let j=0;j<pts.length;j++){const po=posts.find(p=>geoDist(pts[j],p)<120);if(po){pts=pts.slice(0,j+1);r.cutBy=po.name;break}}
    if(pts.length<2)return;let len=0;for(let j=1;j<pts.length;j++)len+=geoDist(pts[j-1],pts[j]);r.maxLen=Math.max(r.maxLen,len);
    r.lines.push(pts);r.polys.push(corridor(pts,BANK_M))});
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
function logout(){token=null;localStorage.removeItem('sw_token')}
/* ---------- Anmeldung ---------- */
function loginView(el,title,done){el.innerHTML=`<div class="card"><h1>${esc(title)}</h1><input id="u" placeholder="Benutzername" autocomplete="username"><input id="p" type="password" placeholder="Passwort" autocomplete="current-password"><button class="pri" id="go">Anmelden</button></div>`;
  const go=async()=>{try{token=(await api('login',{username:$('#u').value,password:$('#p').value})).token;localStorage.setItem('sw_token',token);done()}catch(e){fail(e)}};
  $('#go').onclick=go;$('#p').onkeydown=e=>{if(e.key==='Enter')go()}}
/* ---------- Dialoge ---------- */
// Einfacher modaler Dialog; save(d) wirft bei Fehlern, dann bleibt der Dialog offen
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
const KIND_GROUPS={all:['Alle Einträge',null],join:['Beitritt / Austritt',['accept','decline','leave','kick']],role:['Rollen',['role']],move:['Fahrten / Suchgebiet',['move','area']],resc:['Rettlinge',['rescuee','resmark','rescued','mark','markdel']],msg:['Nachrichten',['message']],poi:['Positionen / Bereiche',['poi','poidel']],inc:['Einsatz / Fliessgeschwindigkeit / Aufzeichnung / Öffentlich',['start','end','flow','rec','pub']]};
const KIND_COL={start:'#ff4d2e',end:'#ff4d2e',accept:'#35c27a',decline:'#8fb0bd',leave:'#8fb0bd',role:'#e6a100',flow:'#1d7fd1',message:'#cfd8dc',move:'#c78cff',area:'#ffd23f',kick:'#ff4d2e',mark:'#2ee6d6',markdel:'#8fb0bd',poi:'#ff9f1c',poidel:'#8fb0bd',rescuee:'#ff6b6b',resmark:'#ff9f1c',rescued:'#35c27a',rec:'#e05cb0',pub:'#7dd3fc'};
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
