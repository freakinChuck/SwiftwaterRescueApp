/* Gemeinsamer Code für Einsatz-App (index.html) und Verwaltung (admin.html) */
const API='api/index.php';
const $=(s,r=document)=>r.querySelector(s), esc=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
let token=localStorage.getItem('sw_token'),onUnauth=()=>{}; // onUnauth setzt die jeweilige Seite
async function api(r,body){const o={headers:{'X-Token':token||''}};if(body){o.method='POST';o.body=JSON.stringify(body)}
  const res=await fetch(`${API}?r=${r}`,o);const j=await res.json().catch(()=>({error:'Serverfehler'}));
  if(res.status===401&&r!=='login'){token=null;localStorage.removeItem('sw_token');onUnauth()}if(j.error)throw new Error(j.error);return j}
const fail=e=>alert(e.message);
const fmtT=ts=>new Date(ts*1000).toLocaleTimeString('de-CH',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
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
const KIND_GROUPS={all:['Alle Einträge',null],join:['Beitritt / Austritt',['accept','decline','leave','kick']],role:['Rollen',['role']],move:['Fahrten / Suchgebiet / Rettling',['move','area','mark','markdel']],msg:['Nachrichten',['message']],inc:['Einsatz / Fliessgeschwindigkeit',['start','end','flow']]};
const KIND_COL={start:'#ff4d2e',end:'#ff4d2e',accept:'#35c27a',decline:'#8fb0bd',leave:'#8fb0bd',role:'#e6a100',flow:'#1d7fd1',message:'#cfd8dc',move:'#c78cff',area:'#ffd23f',kick:'#ff4d2e',mark:'#2ee6d6',markdel:'#8fb0bd'};
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
  const g=KIND_GROUPS[big.filter][1],all=big.cache,l=all.filter(x=>!g||g.includes(x.kind)).sort((a,b)=>big.desc?b.id-a.id:a.id-b.id);
  $('#bigT').textContent=`${big.title} · ${l.length} von ${all.length} Einträgen${big.live&&!got?' · Einsatz beendet':''}`;
  $('#bigS').textContent=big.desc?'Neueste zuerst':'Älteste zuerst';
  $('#bigList').innerHTML=l.map(x=>`<div class="lg" style="border-left-color:${KIND_COL[x.kind]||'#888'}"><span class="t">${big.live?fmtT(x.ts):new Date(x.ts*1000).toLocaleString('de-CH')}</span><b>${esc(x.name||'System')}</b><span>${esc(x.text)}</span></div>`).join('')||'<div class="mut">Keine Einträge</div>'}
