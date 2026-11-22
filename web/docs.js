// Dokumente (Issue #229): gemeinsamer Code für Einsatz-App, Verwaltung, Gastseite Funk und dok.html.
// Ansicht (dkView/dkOpen), Markdown der Seiten (dkMd), Hochladen in Stücken mit Prüfsumme und PDF-Text (dkUpload), Offline-Ablage (dkOffSync),
// Fenster «Dokumente» mit Abschnitten, Ordnern und Suche (dkPanel). Braucht api(), esc(), toast(), fail(), dlg(), ask() und token aus common.js;
// die Gastseiten übergeben eigene Abrufe (o.blob). PDF-Anzeige und -Text mit pdf.js von cdnjs, geladen erst beim Gebrauch.
const DK_PDF='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
const DK_ICON={pdf:'📕',img:'🖼️',text:'📄',audio:'🎧',video:'🎬',dl:'📎',page:'📝',link:'🔗',folder:'📁'};
const dkIco=d=>d.kind==='file'?DK_ICON[(d.file||{}).show||'dl']||'📎':DK_ICON[d.kind]||'📄';
// farbiges Kästchen mit der Art (PDF, TEXT …), Verwaltung und Popup fester Standorte
const DK_BCOL={pdf:'#d93b3b',img:'#2f9e62',text:'#5b6b7d',audio:'#8a5cd6',video:'#8a5cd6',dl:'#5b6b7d',page:'#2b7de9',link:'#178a96'};
const dkBadge=d=>{const f=d.kind==='file'?d.file:null,t=d.kind==='page'?'TEXT':d.kind==='link'?'LINK':f?f.ext.toUpperCase().slice(0,4):'–';return`<span class="dkbg" style="background:${DK_BCOL[d.kind==='file'?(f&&f.show)||'dl':d.kind]}">${esc(t)}</span>`};
const dkArt=d=>d.kind==='page'?'Seite':d.kind==='link'?'Link':d.file?d.file.ext.toUpperCase():'Datei';
const dkSize=b=>b==null?'':b<1024?b+' B':b<1048576?Math.round(b/1024)+' KB':(b/1048576).toFixed(b<10485760?1:0).replace('.',',')+' MB';
const dkDate=t=>t?new Date(t*1000).toLocaleDateString(LOC):'';
const dkKindTxt=d=>d.kind==='page'?'Seite':d.kind==='link'?'Link':d.file?d.file.ext.toUpperCase()+(d.file.pages?', '+d.file.pages+' S.':'')+', '+dkSize(d.file.bytes):'Datei (noch leer)';
let dkPdfP=null;
function dkPdfLib(){if(window.pdfjsLib)return Promise.resolve(pdfjsLib);return dkPdfP=dkPdfP||new Promise((ok,no)=>{const s=document.createElement('script');s.src=DK_PDF+'pdf.min.js';
  s.onload=()=>{pdfjsLib.GlobalWorkerOptions.workerSrc=DK_PDF+'pdf.worker.min.js';ok(pdfjsLib)};s.onerror=()=>{dkPdfP=null;no(new Error('Die PDF-Anzeige konnte nicht geladen werden (Verbindung?)'))};document.head.appendChild(s)})}
// Text je Seite für die Suche (höchstens 500 Seiten); null = nicht lesbar (z. B. gescannt ohne Text)
async function dkPdfText(buf){try{const L=await dkPdfLib(),p=await L.getDocument({data:buf.slice(0)}).promise,n=Math.min(p.numPages,500),t=[];
  for(let i=1;i<=n;i++){const c=await(await p.getPage(i)).getTextContent();t.push(c.items.map(x=>x.str).join(' ').replace(/\s+/g,' ').trim())}
  return{text:t.some(x=>x)?t:null,pages:p.numPages}}catch(e){return{text:null,pages:null}}}
/* ---------- Markdown der Seiten (wie hilfe.html, ohne Anker; Bilder img:<id>, Verweise doc:<id>) ---------- */
function dkInl(s,imgs){s=esc(s);
  s=s.replace(/!\[([^\]]*)\]\(img:(\d+)\)/g,(m,a,i)=>imgs&&imgs[i]?`<img src="${esc(imgs[i])}" alt="${a}">`:`<span class="mut">[Bild ${i}]</span>`);
  s=s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g,(m,t,u)=>{u=u.replace(/&amp;/g,'&');const d=u.match(/^doc:(\d+)$/);if(d)return`<a href="#" data-dkopen="${d[1]}">${t}</a>`;
    return/^(https?:|mailto:|tel:)/i.test(u)?`<a href="${esc(u)}" target="_blank" rel="noopener">${t}</a>`:t});
  s=s.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g,(m,a,u)=>`${a}<a href="${u}" target="_blank" rel="noopener">${u}</a>`);
  return s.replace(/`([^`]+)`/g,'<code>$1</code>').replace(/\*\*([^*]+)\*\*/g,'<b>$1</b>').replace(/(^|[\s(«])\*([^*\s][^*]*)\*/g,'$1<i>$2</i>')}
function dkMd(txt,imgs){const L=String(txt||'').replace(/\r/g,'').split('\n'),out=[],para=[];let i=0;const flush=()=>{if(para.length){out.push('<p>'+dkInl(para.join(' '),imgs)+'</p>');para.length=0}};
  const li=/^\s*([-*]|\d+\.)\s/;
  while(i<L.length){const l=L[i];let m=l.match(/^(#{1,4})\s+(.*)$/);
    if(m){flush();out.push(`<h${m[1].length+1}>${dkInl(m[2],imgs)}</h${m[1].length+1}>`);i++;continue}
    if(/^\s*$/.test(l)){flush();i++;continue}
    if(/^\s*(---|\*\*\*)\s*$/.test(l)){flush();out.push('<hr>');i++;continue}
    m=l.match(/^!\[([^\]]*)\]\(img:(\d+)\)\s*$/);if(m){flush();out.push(`<figure>${imgs&&imgs[m[2]]?`<img src="${esc(imgs[m[2]])}" alt="${esc(m[1])}">`:''}${m[1]?`<figcaption>${dkInl(m[1],imgs)}</figcaption>`:''}</figure>`);i++;continue}
    if(/^>/.test(l)){flush();const b=[];while(i<L.length&&/^>/.test(L[i]))b.push(L[i++].replace(/^>\s?/,''));const w=/^!\s/.test(b[0]);if(w)b[0]=b[0].slice(2);out.push(`<blockquote${w?' class="w"':''}>${dkMd(b.join('\n'),imgs)}</blockquote>`);continue}
    if(/^\|/.test(l)){flush();const rows=[];while(i<L.length&&/^\|/.test(L[i]))rows.push(L[i++]);const cells=r=>r.trim().replace(/^\||\|$/g,'').split('|').map(c=>c.trim());
      const hd=cells(rows[0]),body=rows.slice(/^\|[\s:|-]+\|$/.test(rows[1]||'')?2:1);out.push(`<div class="tw"><table><thead><tr>${hd.map(c=>`<th>${dkInl(c,imgs)}</th>`).join('')}</tr></thead><tbody>${body.map(r=>`<tr>${cells(r).map(c=>`<td>${dkInl(c,imgs)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);continue}
    if(li.test(l)){flush();const ol=/^\s*\d+\./.test(l),its=[];while(i<L.length&&(li.test(L[i])||(/^\s{2,}\S/.test(L[i])&&its.length))){if(li.test(L[i]))its.push(L[i].replace(/^\s*([-*]|\d+\.)\s+/,'').replace(/^\[( |x)\]\s/i,(x,c)=>c.trim()?'☑ ':'☐ '));else its[its.length-1]+=' '+L[i].trim();i++}
      out.push(`<${ol?'ol':'ul'}>${its.map(t=>`<li>${dkInl(t,imgs)}</li>`).join('')}</${ol?'ol':'ul'}>`);continue}
    para.push(l.trim());i++}
  flush();return out.join('\n')}
/* ---------- Offline (Cache-API, je Gerät; nur in der Einsatz-App) ---------- */
const DK_CACHE='sw-docs-1';
const dkCaches=()=>typeof caches!=='undefined'&&window.isSecureContext;
const dkKey=(id,v,t)=>new Request(location.origin+'/__swdocs/'+id+'/'+v+'/'+t);
async function dkCachePut(id,v,meta,blob){if(!dkCaches())return;try{const c=await caches.open(DK_CACHE);await c.put(dkKey(id,v,'m'),new Response(JSON.stringify(meta),{headers:{'Content-Type':'application/json'}}));if(blob)await c.put(dkKey(id,v,'f'),new Response(blob,{headers:{'Content-Type':blob.type||'application/octet-stream'}}))}catch(e){}}
async function dkCacheGet(id,v,t){if(!dkCaches())return null;try{const c=await caches.open(DK_CACHE);if(v==null){const k=(await c.keys()).map(r=>r.url.split('/__swdocs/')[1]).filter(u=>u&&u.startsWith(id+'/')&&u.endsWith('/'+t)).map(u=>+u.split('/')[1]).sort((a,b)=>b-a);if(!k.length)return null;v=k[0]}
  const r=await c.match(dkKey(id,v,t));return r?(t==='m'?await r.json():await r.blob()):null}catch(e){return null}}
// gemäss me.docs.off nachführen: fehlende Versionen laden (bis off_mb), veraltete und nicht mehr vorgesehene entfernen
let dkOffBusy=false;
async function dkOffSync(off,cfg){if(dkOffBusy||!dkCaches()||!navigator.onLine)return;dkOffBusy=true;try{const c=await caches.open(DK_CACHE),keys=await c.keys(),want=new Map((off||[]).map(x=>[x.id,x.ver]));
  for(const r of keys){const[id,v]=r.url.split('/__swdocs/')[1].split('/').map(Number);if(want.get(id)!==v)await c.delete(r)}
  let used=0;const max=((cfg&&cfg.off_mb)||0)*1048576;
  for(const x of off||[]){const has=await c.match(dkKey(x.id,x.ver,'m'));if(has){used+=x.bytes||0;continue}if(used+(x.bytes||0)>max)continue;
    try{const m=await api('doc&id='+x.id+'&off=1');const b=m.kind==='file'&&m.file?await dkBlob(x.id):null;await dkCachePut(x.id,m.ver,m,b);used+=x.bytes||0}catch(e){}}}
  finally{dkOffBusy=false}}
const dkOffHas=async id=>!!(await dkCacheGet(id,null,'m'));
/* ---------- Abruf ---------- */
async function dkBlob(id,q=''){const r=await fetch('api/index.php?r=doc/get&id='+id+q,{headers:{'X-Token':token||''}});if(!r.ok){let m='Datei nicht verfügbar';try{m=(await r.json()).error||m}catch(e){}throw new Error(m)}return r.blob()}
// Eintrag laden: online über die API, sonst aus der Offline-Ablage (off = aus der Ablage)
async function dkLoad(id){try{const d=await api('doc&id='+id);return d}catch(e){if(!(e instanceof TypeError))throw e;const m=await dkCacheGet(id,null,'m');if(!m)throw new Error('Ohne Verbindung nicht verfügbar (nicht offline gespeichert)');m.off=1;return m}}
/* ---------- Ansicht ---------- */
// el: Behälter; d: Eintrag (title, kind, body, url, imgs, file {name, ext, show}); blob(dl) liefert die Datei; page = Seite im PDF, hl = Suchbegriff
async function dkView(el,d,blob,o={}){el.innerHTML='';el.classList.add('dkv');
  if(d.kind==='page'){el.innerHTML=`<div class="dkmd">${dkMd(d.body,d.imgs)||'<p class="mut">Diese Seite ist noch leer.</p>'}</div>`;return}
  if(d.kind==='link'){el.innerHTML=`<div class="dkmd"><p>${d.descr?esc(d.descr):'Externer Link'}</p><p><a class="btn pri" href="${esc(d.url)}" target="_blank" rel="noopener">Öffnen: ${esc(String(d.url).replace(/^https?:\/\//,'').slice(0,60))}</a></p></div>`;return}
  if(!d.file){el.innerHTML='<div class="mut">Noch keine Datei hochgeladen.</div>';return}
  const sh=d.file.show;el.innerHTML='<div class="mut">Wird geladen …</div>';let b;try{b=await blob(false)}catch(e){el.innerHTML=`<div class="mut">${esc(e.message)}</div>`;return}
  const u=URL.createObjectURL(b);el._url&&URL.revokeObjectURL(el._url);el._url=u;
  if(sh==='img'){el.innerHTML=`<img class="dkimg" src="${u}" alt="${esc(d.title)}">`;return}
  if(sh==='audio'||sh==='video'){el.innerHTML=`<${sh} controls src="${u}" class="dkmed"></${sh}>`;return}
  if(sh==='text'){const t=await b.text();el.innerHTML=d.file.ext==='md'?`<div class="dkmd">${dkMd(t)}</div>`:`<pre class="dktxt">${esc(t.slice(0,500000))}</pre>`;return}
  if(sh==='pdf'){try{const L=await dkPdfLib(),p=await L.getDocument({data:await b.arrayBuffer()}).promise;
      el.innerHTML=`<div class="dkpbar"><span>${p.numPages} ${p.numPages>1?'Seiten':'Seite'}</span><button data-dkz="-1" aria-label="Verkleinern">−</button><button data-dkz="0" class="dkzv" title="Auf Breite einpassen">100 %</button><button data-dkz="1" aria-label="Vergrössern">+</button></div><div class="dkpages"></div>`;
      // Bildlauf und Zoom im Behälter (.dkbody); die Seite selbst zoomt in der Einsatz-App nie
      const box=el.querySelector('.dkpages'),sc=el.closest('.dkbody')||el,zv=el.querySelector('.dkzv'),dpr=devicePixelRatio||1;let z=1;const pages=[];
      for(let i=1;i<=p.numPages;i++){const c=document.createElement('div');c.className='dkpg';c.dataset.n=i;c.style.minHeight='40vh';box.appendChild(c);pages.push(c)}
      const fitW=()=>Math.min(sc.clientWidth||800,1400)-24;
      const draw=async c=>{if(c._z===z)return;const zz=z;c._z=zz;const pg=await p.getPage(+c.dataset.n),v0=pg.getViewport({scale:1}),w=fitW()*zz,v=pg.getViewport({scale:w/v0.width*Math.min(dpr*zz,4)/zz}),cv=document.createElement('canvas');
        cv.width=v.width;cv.height=v.height;cv.style.width=w+'px';cv.style.height=(w*v0.height/v0.width)+'px';await pg.render({canvasContext:cv.getContext('2d'),viewport:v}).promise;if(c._z!==zz)return;
        c.innerHTML='';c.style.minHeight='';c.appendChild(cv);const n=document.createElement('div');n.className='dkpn';n.textContent='Seite '+c.dataset.n+' von '+p.numPages;c.appendChild(n)};
      const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting)draw(e.target).catch(()=>{})}),{root:sc===el?null:sc,rootMargin:'600px'});pages.forEach(c=>io.observe(c));
      // neuer Zoom: Stelle in der Mitte des Bildes bleibt stehen; gezeichnete Seiten sofort auf die neue Grösse skalieren, danach scharf neu zeichnen
      const zoom=nz=>{nz=Math.max(.5,Math.min(4,Math.round(nz*100)/100));if(nz===z)return;const f=nz/z,cx=sc.scrollLeft+sc.clientWidth/2,cy=sc.scrollTop+sc.clientHeight/2;z=nz;zv.textContent=Math.round(z*100)+' %';
        pages.forEach(c=>{const cv=c.querySelector('canvas');if(cv){cv.style.width=parseFloat(cv.style.width)*f+'px';cv.style.height=parseFloat(cv.style.height)*f+'px'}else c.style.minHeight=40*z+'vh'});
        sc.scrollLeft=cx*f-sc.clientWidth/2;sc.scrollTop=cy*f-sc.clientHeight/2;clearTimeout(zoom.t);zoom.t=setTimeout(()=>pages.forEach(c=>{if(c._z&&c.getBoundingClientRect().bottom>-800&&c.getBoundingClientRect().top<innerHeight+800)draw(c);else c._z=c.querySelector('canvas')?-1:0}),250)};
      el.querySelectorAll('[data-dkz]').forEach(x=>x.onclick=()=>zoom(+x.dataset.dkz?z+(+x.dataset.dkz)*.25:1));
      // Zwei Finger (Handy) bzw. Ctrl + Mausrad / Trackpad
      let t0=null;const dist=t=>Math.hypot(t[0].clientX-t[1].clientX,t[0].clientY-t[1].clientY);
      sc.addEventListener('touchstart',e=>{if(e.touches.length===2)t0={d:dist(e.touches),z}},{passive:true});
      sc.addEventListener('touchmove',e=>{if(t0&&e.touches.length===2){e.preventDefault();box.style.transformOrigin='center top';box.style.transform=`scale(${Math.max(.5,Math.min(4,t0.z*dist(e.touches)/t0.d))/z})`;t0.last=dist(e.touches)}},{passive:false});
      sc.addEventListener('touchend',e=>{if(t0&&e.touches.length<2){box.style.transform='';const r=t0.last?t0.z*t0.last/t0.d:z;t0=null;zoom(r)}});
      sc.addEventListener('wheel',e=>{if(!e.ctrlKey)return;e.preventDefault();zoom(z*(e.deltaY<0?1.1:1/1.1))},{passive:false});
      if(o.page>1&&pages[o.page-1])setTimeout(()=>pages[o.page-1].scrollIntoView(),50);return}
    catch(e){el.innerHTML=`<div class="mut">Das PDF lässt sich hier nicht anzeigen (${esc(e.message)}).</div><p><a class="btn" href="${u}" target="_blank" rel="noopener">Im Browser öffnen</a></p>`;return}}
  el.innerHTML=`<div class="dkmd"><p>${esc(d.file.name)} (${dkSize(d.file.bytes)}) lässt sich hier nicht anzeigen.</p>${o.nodl?'<p class="mut">Herunterladen ist für Externe ausgeschaltet.</p>':'<p><button class="pri" data-dkdl>Herunterladen</button></p>'}</div>`;
  if(!o.nodl)el.querySelector('[data-dkdl]').onclick=()=>dkSave(b,d.file.name)}
function dkSave(b,name){if(typeof saveBlob==='function')return saveBlob(b,name);const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=name;a.click()}
// Vollbild-Ansicht eines Eintrags (Einsatz-App, Verwaltung): Kopf mit Pfad, Favorit, Lesebestätigung, Vorschlag, Herunterladen
// o: {page, onChange, edit (Verwaltung: Funktion zum Bearbeiten), noFav}
async function dkOpen(id,o={}){let d;try{d=await dkLoad(id)}catch(e){return fail(e)}
  const ov=document.createElement('div');ov.className='dkov';ov.setAttribute('role','dialog');ov.setAttribute('aria-modal','true');ov.setAttribute('aria-label',d.title);document.body.appendChild(ov);
  const close=()=>{ov.remove();removeEventListener('keydown',key);const v=ov.querySelector('.dkv');v&&v._url&&URL.revokeObjectURL(v._url);o.onChange&&o.onChange()},key=e=>{if(e.key==='Escape'&&document.body.lastElementChild===ov)close()};addEventListener('keydown',key);
  const ackH=()=>{if(!d.ack||d.off)return'';const m=d.mine;if(m&&m.st!=='ok')return`<div class="dkack ${m.st}"><span>${m.st==='over'?'⚠ Überfällig: ':''}Bitte lesen und bestätigen${m.due?' bis '+dkDate(m.due):''}.</span><button class="pri" data-dkack>Gelesen und verstanden</button></div>`;
    if(d.my_ack)return`<div class="dkack ok"><span>✓ Bestätigt am ${dkDate(d.my_ack)}${m?'':' (freiwillig)'}</span></div>`;return`<div class="dkack fr"><span>Für alle, die mögen: Lesebestätigung freiwillig.</span><button data-dkack>Bestätigen</button></div>`};
  const draw=()=>{ov.innerHTML=`<div class="dkhd"><button data-dkx aria-label="Schliessen">✕</button><div class="dkt"><div class="mut dkpath">${(d.path||[]).map(p=>esc(p.name)).join(' › ')}</div><b>${dkIco(d)} ${esc(d.title)}</b>
    <div class="mut">${esc(dkKindTxt(d))}${d.ver>1?' · Version '+d.ver:''}${d.updated?' · Stand '+dkDate(d.updated):''}${d.review?' · <span class="dkrev">Überprüfung fällig</span>':''}${d.off?' · offline gespeichert':''}</div></div>
    <div class="dkbt">${o.noFav||d.off?'':`<button data-dkfav aria-pressed="${d.fav?'true':'false'}" title="${d.fav?'Aus den Favoriten entfernen':'Zu den Favoriten'}">${d.fav?'★':'☆'}</button>`}${d.kind==='file'&&d.file?'<button data-dkdl title="Herunterladen">⤓</button>':''}${d.sug&&!d.off?'<button data-dksug>Vorschlagen</button>':''}${o.edit&&d.edit?'<button data-dked>Bearbeiten</button>':''}</div></div>
    ${d.descr&&d.kind!=='link'?`<div class="dkds mut">${esc(d.descr)}</div>`:''}${ackH()}<div class="dkbody"></div>`;
    ov.querySelector('[data-dkx]').onclick=close;
    const fv=ov.querySelector('[data-dkfav]');if(fv)fv.onclick=async()=>{try{await api('doc/fav',{id,on:d.fav?0:1});d.fav=d.fav?0:1;draw()}catch(e){fail(e)}};
    const dl=ov.querySelector('[data-dkdl]');if(dl)dl.onclick=async()=>{try{dkSave(d.off?await dkCacheGet(id,d.ver,'f'):await dkBlob(id,'&dl=1'),d.file.name)}catch(e){fail(e)}};
    const ac=ov.querySelector('[data-dkack]');if(ac)ac.onclick=async()=>{if(!await ask(`«${d.title}» gelesen und verstanden?\n\nDie Bestätigung gilt für Version ${d.ver}.`,{ok:'Bestätigen'}))return;try{await api('doc/ack',{id});d=await dkLoad(id);draw();toast('Danke, bestätigt.','ok')}catch(e){fail(e)}};
    const sg=ov.querySelector('[data-dksug]');if(sg)sg.onclick=()=>dkSugDlg({folder_id:d.folder_id,doc:d});
    const ed=ov.querySelector('[data-dked]');if(ed)ed.onclick=()=>{close();o.edit(d)};
    ov.querySelector('.dkbody').addEventListener('click',e=>{const a=e.target.closest('[data-dkopen]');if(a){e.preventDefault();dkOpen(+a.dataset.dkopen,o)}});
    dkView(ov.querySelector('.dkbody'),d,async dl=>d.off?(await dkCacheGet(id,d.ver,'f'))||Promise.reject(new Error('Datei nicht offline gespeichert')):dkBlob(id,dl?'&dl=1':''),{page:o.page})};
  draw();return ov}
/* ---------- Hochladen ---------- */
const dkHex=b=>[...new Uint8Array(b)].map(x=>x.toString(16).padStart(2,'0')).join('');
function dkB64(u8){let s='';for(let i=0;i<u8.length;i+=32768)s+=String.fromCharCode.apply(null,u8.subarray(i,i+32768));return btoa(s)}
// f: File; to: {doc_id, major} (neue Version) oder {sug: {folder_id, doc_id, kind, title, note}}; prog(Anteil 0–1)
// leere Datei (0 Bytes), z. B. ein Platzhalter von OneDrive o. ä.
const dkEmpty=f=>`Die Datei «${f.name}» ist leer (0 Bytes). Liegt sie nur in der Cloud (OneDrive …)? Bitte zuerst lokal speichern.`;
async function dkUpload(f,to,cfg,prog=()=>{}){const ext=(f.name.split('.').pop()||'').toLowerCase(),ok=(cfg.types||[]).some(t=>t.ext.includes(ext))||(cfg.free||[]).includes(ext);
  if(!ok)throw new Error(`Dateien .${ext} sind nicht erlaubt. Erlaubt: ${(cfg.types||[]).flatMap(t=>t.ext).concat(cfg.free||[]).map(x=>'.'+x).join(', ')}`);
  if(!f.size)throw new Error(dkEmpty(f));
  if(f.size>cfg.max_mb*1048576)throw new Error(`Die Datei ist grösser als ${cfg.max_mb} MB`);
  const buf=await f.arrayBuffer(),sha=dkHex(await crypto.subtle.digest('SHA-256',buf)),u8=new Uint8Array(buf),ch=cfg.chunk||1048576,up=dkHex(crypto.getRandomValues(new Uint8Array(8))),n=Math.ceil(u8.length/ch);
  // Datei nicht (ganz) lesbar, z. B. nur in der Cloud abgelegt oder inzwischen geändert: nicht hochladen
  if(!u8.length||u8.length!==f.size)throw new Error(`Die Datei konnte nicht ganz gelesen werden (${u8.length} von ${f.size} Bytes). Liegt sie nur in der Cloud (OneDrive …)? Bitte zuerst lokal speichern.`);
  let tx=null;if(ext==='pdf'&&to.doc_id){prog(0.02);tx=await dkPdfText(buf)}
  for(let i=0;i<n;i++){await api('doc/upload/put',{up,n:i,data:dkB64(u8.subarray(i*ch,(i+1)*ch)),...(i?{}:{name:f.name,size:u8.length,doc_id:to.doc_id||null,sug:to.sug?to.sug.folder_id:null})});prog((i+1)/n)}
  return api('doc/upload/done',{up,name:f.name,size:u8.length,sha,parts:n,...(to.doc_id?{doc_id:to.doc_id,major:to.major?1:0,text:tx&&tx.text,pages:tx&&tx.pages}:{sug:to.sug})})}
// Vorschlag: neuer Eintrag (Ordner), neue Version oder Hinweis (Eintrag); mit oder ohne Datei
function dkSugDlg(o){const d=o.doc,cfg=(typeof me!=='undefined'&&me.docs_cfg)||{};
  const k0=d?(d.kind==='file'?'ver':'note'):'new';
  const x=dlg(d?'Vorschlag zu «'+d.title+'»':'Neuen Eintrag vorschlagen',`<div class="mut">Die Redaktion des Ordners erhält deinen Vorschlag im Eingang und entscheidet, ob sie ihn übernimmt. Du wirst benachrichtigt.</div>
    ${d?`<label class="mut">Art<select id="sgK">${d.kind==='file'?'<option value="ver">Neue Version (Datei)</option>':''}<option value="note" ${k0==='note'?'selected':''}>Hinweis (Fehler, veraltet …)</option></select></label>`:'<input type="hidden" id="sgK" value="new">'}
    ${d?'':'<label class="mut">Titel<input id="sgT" maxlength="200" placeholder="z. B. Einsatzplan Reuss Luzern"></label>'}
    <label class="mut">Mitteilung an die Redaktion<textarea id="sgN" rows="3" maxlength="4000" placeholder="${d?'Was ist falsch oder veraltet?':'Worum geht es? Bei einer Seite: der Text.'}"></textarea></label>
    <label class="mut" id="sgFl">Datei (optional)<input type="file" id="sgF"></label><div class="mut" id="sgP"></div>`,async x=>{const k=$('#sgK',x).value,f=$('#sgF',x).files[0],sg={folder_id:o.folder_id,doc_id:d?d.id:null,kind:k,title:d?null:$('#sgT',x).value,note:$('#sgN',x).value};
      if(f)await dkUpload(f,{sug:sg},cfg,p=>$('#sgP',x).textContent='Wird hochgeladen … '+Math.round(p*100)+' %');else await api('doc/sug',sg);toast('Vorschlag an die Redaktion gesendet.','ok')},'Senden');
  const sync=()=>{$('#sgFl',x).hidden=$('#sgK',x).value==='note'};$('#sgK',x).onchange=sync;sync();return x}
/* ---------- Fenster «Dokumente» (Einsatz-App) ---------- */
// L: Antwort von docs; ctx: {kid, inc: incident/state docs (Einsatztyp, Standorte), places: [{id, name}], mineOnly}
// eigene Reihenfolge (doc/view) vor der Vorgabe des Einsatztyps; Abschnitte, die dort fehlen (z. B. neu), kommen aus der Vorgabe dazu
const dkSecsFor=(L,kid)=>{const c=L.cfg,my=L.my_view||{},own=c.own&&(my[kid]||my['0']),def=c.view[kid]||c.view['0']||[];return(own?own.concat(def.filter(d=>!own.some(o=>o.k===d.k))):def).filter(s=>s.on)};
// offline auf diesem Gerät (wie auf dem Server: immer bzw. als Favorit)
const dkOffOn=d=>d.offline===2||d.offline===1&&!!d.fav;
// Zeile wie im Konzept (Bild 1/2): Art, Titel, Angaben, Fundstelle, Chips; rechts «Öffnen» und Favorit
// o: page (Seite der Fundstelle), meta (statt Art · Grösse · Stand), snip (HTML der Fundstelle), chips (HTML), title (HTML des Titels)
function dkRow(d,o={}){const m=d.mine,ack=m&&m.st!=='ok',f=d.kind==='file'?d.file:null,pg=o.page?` data-dkpage="${o.page}"`:'';
  const meta=o.meta!=null?o.meta:[dkArt(d),f&&f.bytes!=null?dkSize(f.bytes):'',d.updated?'Stand '+dkDate(d.updated):''].filter(x=>x).join(' · ');
  const ch=[dkOffOn(d)?'<span class="dkchip on">⬇ offline</span>':'',ack?`<span class="dkchip ${m.st}">${m.st==='over'?'überfällig':'bestätigen'}${m.due?' bis '+dkDate(m.due):''}</span>`:'',d.review?'<span class="dkchip rev">prüfen</span>':'',o.chips||''].join('');
  return`<div class="dkr${d.active?'':' off'}" data-dkid="${d.id}"${pg}>${dkBadge(d)}<span class="dkn"><b>${o.title||esc(d.title)}</b><small class="mut">${esc(meta)}</small>${o.snip?`<small class="dksn">${o.snip}</small>`:''}${ch?`<span class="dkchs">${ch}</span>`:''}</span>
  <span class="dkra"><button type="button" data-dkid="${d.id}"${pg}>Öffnen</button><button type="button" class="dkst${d.fav?' on':''}" data-dkfv="${d.id}" aria-pressed="${d.fav?'true':'false'}" aria-label="${d.fav?'Favorit entfernen':'Als Favorit'}" title="${d.fav?'Favorit entfernen':'Als Favorit (wird offline gespeichert, wenn die Redaktion es erlaubt)'}">${d.fav?'★':'☆'}</button></span></div>`}
function dkPanel(el,L,ctx={}){const st={f:null,q:'',hits:null};let qT=0;
  const docs=L.docs,byId=new Map(docs.map(d=>[d.id,d])),fold=L.folders,kid=ctx.kid||0,inc=ctx.inc||{list:[]};
  const pl=new Map((ctx.places||[]).map(p=>[p.id,p.name]));
  const sec=(t,ds,key,o)=>ds.length?`<details class="dksec" open data-dks="${key}"><summary>${t} (${ds.length})</summary>${ds.map(d=>dkRow(d,o?o(d):{})).join('')}</details>`:'';
  const secs=()=>dkSecsFor(L,kid).map(s=>{const t=L.cfg.secs[s.k];
    if(s.k==='ack')return sec(t,docs.filter(d=>d.mine&&d.mine.st!=='ok').sort((a,b)=>(a.mine.due||9e12)-(b.mine.due||9e12)),'ack');
    if(s.k==='fav')return sec('★ '+t,docs.filter(d=>d.fav),'fav');
    if(s.k==='place'){const ds=(inc.list||[]).filter(d=>d.places&&d.places.length).map(d=>byId.get(d.id)||d);if(!ds.length)return'';
      return sec(t,ds,'place',d=>({chips:(inc.list.find(x=>x.id===d.id).places||[]).map(i=>`<span class="dkchip pl">📍 ${esc(pl.get(i)||'Standort')}</span>`).join('')}))}
    if(s.k==='kind')return sec(t,(inc.list||[]).filter(d=>d.kind_hit).map(d=>byId.get(d.id)||d),'kind');
    if(s.k==='recent')return sec(t,docs.filter(d=>d.seen).sort((a,b)=>b.seen-a.seen).slice(0,L.cfg.n),'recent');
    if(s.k==='new'){const lim=Date.now()/1000-30*86400;return sec(t,docs.filter(d=>d.updated>lim).sort((a,b)=>b.updated-a.updated).slice(0,L.cfg.n),'new')}
    if(s.k==='folders')return`<details class="dksec" open data-dks="folders"><summary>${t}</summary>${folderList(null)}</details>`;return''}).join('');
  // Inhalt eines Ordners samt allen Unterordnern: «5 Dokumente · 2 Unterordner»
  const fCnt=id=>{let n=0,k=0;const go=i=>{n+=docs.filter(d=>d.folder_id===i&&d.active).length;fold.filter(x=>x.parent_id===i&&x.active).forEach(x=>{k++;go(x.id)})};go(id);
    return(n===1?'1 Dokument':n+' Dokumente')+(k?' · '+(k===1?'1 Unterordner':k+' Unterordner'):'')};
  const folderList=pid=>{const fs=fold.filter(f=>f.parent_id===pid&&f.active),ds=pid==null?[]:docs.filter(d=>d.folder_id===pid&&d.active);
    return fs.map(f=>`<button class="dkr dkfo" data-dkf="${f.id}"><span class="dkbg dkfb">📁</span><span class="dkn"><b>${esc(f.name)}</b>${f.lock?' <span class="mut" title="Nur für bestimmte Personen">🔒</span>':''}<small class="mut">${esc(fCnt(f.id))}${f.descr?' · '+esc(f.descr):''}</small></span><span class="dkra mut">›</span></button>`).join('')+ds.map(d=>dkRow(d)).join('')||'<div class="mut">Leer.</div>'};
  const path=id=>{const r=[];let f=fold.find(x=>x.id===id);while(f){r.unshift(f);f=fold.find(x=>x.id===f.parent_id)}return r};
  const draw=()=>{const f=st.f!=null?fold.find(x=>x.id===st.f):null;let body;
    if(st.q.trim().length>=2){const h=st.hits,ws=st.q.trim().split(/\s+/).filter(w=>w.length>1).map(w=>w.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')),re=ws.length?new RegExp('('+ws.join('|')+')','gi'):null,mk=t=>re?esc(t).replace(re,'<mark>$1</mark>'):esc(t);
      // Fundstelle wie im Konzept (Bild 2): Ordnerpfad · Art, darunter «S. n: «…»», «Schlagwort: …» oder «Beschreibung: «…»»
      const hit=x=>{const d=byId.get(x.id);if(!d)return'';const w=x.where==='tags'?'Schlagwort: ':x.where==='descr'?'Beschreibung: ':x.page?'S. '+x.page+': ':'';
        return dkRow(d,{page:x.page,title:mk(d.title),meta:[path(d.folder_id).map(p=>p.name).join(' › '),d.kind==='link'?'externe Seite':dkArt(d)].filter(y=>y).join(' · '),snip:x.where==='title'||!x.snip?'':w+(x.where==='tags'?mk(x.snip):'«'+mk(x.snip)+'»')})};
      body=h==null?'<div class="mut">Wird gesucht …</div>':h.length?`<details class="dksec" open><summary>Treffer (${h.length})</summary>${h.map(hit).join('')}</details><div class="mut dkhint">Gesucht wird in Titel, Beschreibung, Schlagwörtern, Seiten und im Text der PDFs. Nur Dokumente, die du sehen darfst.</div>`:'<div class="mut">Keine Treffer.</div>'}
    else if(f)body=`<div class="dkcrumb"><a href="#" data-dkf="">Dokumente</a>${path(f.id).map(p=>` › <a href="#" data-dkf="${p.id}">${esc(p.name)}</a>`).join('')}</div>${f.descr?`<div class="mut">${esc(f.descr)}</div>`:''}${folderList(f.id)}${f.sug?`<div class="row"><button data-dksugf="${f.id}">+ Eintrag vorschlagen</button></div>`:''}`;
    else body=(secs()||'<div class="mut">Noch keine Dokumente.</div>')+(L.cfg.own?'<div class="row"><button data-dkord>Abschnitte anordnen</button></div>':'');
    el.querySelector('.dkres').innerHTML=body};
  el.innerHTML=`<div class="dkq"><input type="search" placeholder="Dokumente durchsuchen …" aria-label="Dokumente durchsuchen (auch im Text der PDF)" autocomplete="off"></div><div class="dkres"></div>`;
  const q=el.querySelector('input');q.oninput=()=>{st.q=q.value;clearTimeout(qT);if(st.q.trim().length<2){st.hits=null;draw();return}st.hits=null;draw();
    qT=setTimeout(async()=>{const v=st.q;try{const r=await api('docs&q='+encodeURIComponent(v));if(v===st.q){st.hits=r.hits;draw()}}catch(e){ // ohne Verbindung: nur Titel, Beschreibung, Schlagwörter
      const w=v.toLowerCase().split(/\s+/).filter(x=>x.length>1);st.hits=docs.filter(d=>w.every(x=>(d.title+' '+d.descr+' '+d.tags).toLowerCase().includes(x))).map(d=>({id:d.id,snip:d.descr}));draw()}},300)};
  el.onclick=async e=>{const fv=e.target.closest('[data-dkfv]');if(fv){e.preventDefault();const d=byId.get(+fv.dataset.dkfv);if(!d)return;
      try{await api('doc/fav',{id:d.id,on:d.fav?0:1});d.fav=d.fav?0:1;draw();ctx.onChange&&ctx.onChange()}catch(err){fail(err)}return}
    const a=e.target.closest('[data-dkf],[data-dkid],[data-dksugf],[data-dkord]');if(!a)return;e.preventDefault();
    if(a.dataset.dkord!=null)return dkOrdDlg(L,kid,ctx.onChange);
    if(a.dataset.dksugf)return dkSugDlg({folder_id:+a.dataset.dksugf});
    if(a.dataset.dkf!=null){st.f=a.dataset.dkf?+a.dataset.dkf:null;st.q='';q.value='';draw();return}
    dkOpen(+a.dataset.dkid,{page:+a.dataset.dkpage||0,onChange:ctx.onChange})};
  draw();return{draw,openFolder:id=>{st.f=id;draw()}}}
// eigene Reihenfolge und Auswahl der Abschnitte (je Einsatztyp, 0 = ohne Einsatz); «Vorgabe» löscht die eigene
function dkOrdDlg(L,kid,done){const c=L.cfg,my=L.my_view||{},def=c.view[kid]||c.view['0']||[],own=my[kid]||my['0'];let ls=(own?own.concat(def.filter(d=>!own.some(o=>o.k===d.k))):def).map(x=>({...x}));
  const x=dlg('Abschnitte anordnen','<div class="mut">Reihenfolge und Auswahl gelten nur für dich'+(kid?' und diesen Einsatztyp':' ohne Einsatz')+'.</div><div id="dkOl"></div><div class="row"><button id="dkOd">Vorgabe der Verwaltung</button></div>',async()=>{await api('doc/view',{kid,secs:ls.map(s=>({k:s.k,on:s.on?1:0}))});L.my_view=Object.assign(L.my_view||{},{[kid]:ls});done&&done()});
  const draw=()=>{$('#dkOl',x).innerHTML=ls.map((s,i)=>`<div class="dkvr"><label class="row" style="align-items:center;flex:1;margin:0"><input type="checkbox" data-i="${i}" ${s.on?'checked':''}> <span>${esc(c.secs[s.k]||s.k)}</span></label><button data-u="${i}" ${i?'':'disabled'} aria-label="nach oben">↑</button><button data-d="${i}" ${i<ls.length-1?'':'disabled'} aria-label="nach unten">↓</button></div>`).join('')};
  x.addEventListener('click',e=>{const b=e.target.closest('[data-u],[data-d]');if(!b)return;const i=+(b.dataset.u??b.dataset.d),j=b.dataset.u!=null?i-1:i+1;[ls[i],ls[j]]=[ls[j],ls[i]];draw()});
  x.addEventListener('change',e=>{if(e.target.dataset.i!=null)ls[+e.target.dataset.i].on=e.target.checked});
  $('#dkOd',x).onclick=async()=>{try{await api('doc/view',{kid});if(L.my_view)delete L.my_view[kid];x.remove();done&&done()}catch(e){fail(e)}};draw()}
/* ---------- Externe (Funk-Gastseite, eigener Link dok.html) ---------- */
// d: Antwort von guest/doc bzw. ext/doc; blob(dl) lädt die Datei; el = Behälter (dok.html) oder leer = Vollbild über der Seite
function dkExtOpen(d,blob,el){let ov=el;const own=!el;if(own){ov=document.createElement('div');ov.className='dkov';ov.setAttribute('role','dialog');ov.setAttribute('aria-modal','true');ov.setAttribute('aria-label',d.title);document.body.appendChild(ov)}
  const close=()=>{ov.remove();removeEventListener('keydown',key)},key=e=>{if(e.key==='Escape'&&own)close()};if(own)addEventListener('keydown',key);
  ov.innerHTML=`<div class="dkhd">${own?'<button data-dkx aria-label="Schliessen">✕</button>':''}<div class="dkt"><b>${dkIco(d)} ${esc(d.title)}</b><div class="mut">${esc(dkKindTxt(d))}${d.ver>1?' · Version '+d.ver:''}</div></div>
    <div class="dkbt">${d.kind==='file'&&d.file&&d.dl?'<button data-dkdl title="Herunterladen">⤓</button>':''}</div></div>${d.descr&&d.kind!=='link'?`<div class="dkds mut">${esc(d.descr)}</div>`:''}<div class="dkbody"></div>`;
  if(own)ov.querySelector('[data-dkx]').onclick=close;const dl=ov.querySelector('[data-dkdl]');if(dl)dl.onclick=async()=>{try{dkSave(await blob(true),d.file.name)}catch(e){toast(e.message,'err')}};
  ov.querySelector('.dkbody').addEventListener('click',e=>{if(e.target.closest('[data-dkopen]'))e.preventDefault()}); // Verweise auf andere Einträge gelten für Externe nicht
  dkView(ov.querySelector('.dkbody'),d,blob,{nodl:!d.dl});return ov}
