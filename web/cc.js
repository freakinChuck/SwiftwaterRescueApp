/* Einsatzkonzepte (Issue #260), Einsatz-App. Nutzt die Bausteine aus common.js und index.html (st, me, map, api, toast, ask, esc, $, feat, fcs,
   symImg, togglePanel, panel, poll, draw, drawMap, mcard, navBtn, fmtT, ccAreaGeo aus drawArea).
   - Hinweis auf der Karte (#ccBan, nur Einsatzleitung): vorgeschlagene Konzepte (PLS im Geltungsbereich) und Anschlusskonzepte (das Suchgebiet erreicht
     den Geltungsbereich eines weiteren Konzepts, Einstellung des Einsatztyps «next»), mit «Vorschau …», «Laden» und «Später» (pro Gerät, sw_cclater_<Einsatz>).
   - Seitenfenster «Einsatzkonzept» (#ccp, panel 'cc'): ohne gewähltes Konzept die Liste aller Konzepte des Einsatztyps (passende zuerst), sonst die Elemente
     mit Häkchen (vorausgewählt gemäss Verwaltung, bereits geladene ausgegraut), Vorschau gestrichelt auf der Karte, «N von M Elementen laden».
   - Karte «Einsatzkonzepte» im Register «Leitung» (ccLeadCard): geladene (offene Aktionen auslösen/verwerfen, Zeigen, Elemente …, Entfernen), Vorschläge,
     Anschluss, passend von Hand, «Anderes Konzept laden …».
   - Fenster «Markierungen»: Untergruppe je geladenem Konzept (index.html, ccPoiGroups); Posten mit «noch nicht besetzt» bzw. «besetzt durch …» (ccPostTxt). */
const CC={list:null,key:null,postM:150,prev:null,sel:null,lay:null,busy:0,fitKey:null};
const CCK={post:'Posten',point:'Markierung',area:'Bereich',line:'Linie',ext:'Externe Einsatzkraft',msg:'Nachricht an alle',doc:'Dokumente',stop:'Flusssperre',voice:'Funkkanäle',invite:'Nachbieten',opt:'Einstellung des Einsatzes'};
const CC_FEAT={post:'poi',point:'poi',area:'poi',line:'poi',ext:'ext',msg:'msg',doc:'docs',stop:'area',voice:'voice'};
const CC_GRP=[['Posten',['post','ext']],['Markierungen',['point']],['Bereiche und Linien',['area','line']],['Weiteres',['msg','doc','stop','voice','invite','opt']]];
const ccOn=()=>!!(st&&st.incident&&st.cc&&feat('cc'));
const ccLead=()=>ccOn()&&!!st.can_manage;
const ccS=()=>(st&&st.cc)||{loaded:[],sug:[],fit:[],dismissed:[]};
const ccName=id=>((ccS().loaded||[]).find(x=>x.id===id)||{}).name;
// Liste der Konzepte des Einsatztyps (incident/concepts), neu bei geändertem Stand der Konzepte oder der geladenen
async function ccFetch(force){if(!ccLead())return null;const s=ccS(),k=st.incident.id+':'+s.ver+':'+(s.loaded||[]).map(x=>x.id+'/'+x.n).join(',')+':'+(s.sug||[]).length;
  if(!force&&CC.key===k)return CC.list;CC.key=k;try{const r=await api('incident/concepts');CC.list=r.list||[];CC.postM=r.post_m||150;ccBan();renderCc();if(!document.activeElement.matches('input,textarea,select'))draw()}catch(e){CC.key=null}return CC.list}
// Geometrie: Punkt im Polygon, Abstand zu Linien (wie concepts.php)
function ccPip(la,lo,poly){let inn=false;for(let i=0,j=poly.length-1;i<poly.length;j=i++){const [ya,xa]=poly[i],[yb,xb]=poly[j];if((ya>la)!==(yb>la)&&lo<(xb-xa)*(la-ya)/((yb-ya)||1e-12)+xa)inn=!inn}return inn}
function ccSeg(la,lo,a,b){const k=111320*Math.cos(la*Math.PI/180),px=(lo-a[1])*k,py=(la-a[0])*111320,bx=(b[1]-a[1])*k,by=(b[0]-a[0])*111320,l=bx*bx+by*by,t=l?Math.max(0,Math.min(1,(px*bx+py*by)/l)):0;return Math.hypot(px-t*bx,py-t*by)}
function ccHit(c,la,lo){if(c.scope==='river'){const r=c.river||{},buf=r.buf||150;return (r.lines||[]).some(l=>l.some((p,i)=>i&&ccSeg(la,lo,l[i-1],p)<=buf))}return (c.area||[]).length>=3&&ccPip(la,lo,c.area)}
function ccGeo(c){return c.scope==='river'?((c.river||{}).lines||[]):[c.area||[]]}
// Anschlusskonzepte: noch nicht geladen, nicht abgelehnt, nicht passend zum PLS, und das Suchgebiet berührt den Geltungsbereich
function ccNext(){const s=ccS();if(!ccLead()||!s.next||!CC.list||!window.ccAreaGeo)return[];const g=window.ccAreaGeo,pts=[];
  (g.lines||[]).forEach(l=>l.forEach(p=>pts.push([p.lat,p.lon])));(g.circles||[]).forEach(c=>{for(let a=0;a<16;a++){const d=c.r/111320;pts.push([c.lat+d*Math.cos(a*Math.PI/8),c.lon+d*Math.sin(a*Math.PI/8)/Math.cos(c.lat*Math.PI/180)])}pts.push([c.lat,c.lon])});
  return CC.list.filter(c=>!c.state&&!c.fit&&c.mode!=='off'&&pts.some(p=>ccHit(c,p[0],p[1])))}
// «Später» pro Gerät und Einsatz
const ccLaterKey=()=>'sw_cclater_'+(st&&st.incident?st.incident.id:0);
function ccLaterGet(){try{return JSON.parse(localStorage.getItem(ccLaterKey())||'[]')}catch(e){return[]}}
function ccLaterAdd(k){const a=ccLaterGet();a.push(k);try{localStorage.setItem(ccLaterKey(),JSON.stringify(a))}catch(e){}ccBan()}
// Hinweis auf der Karte: Vorschläge (s:<inc_concepts.id>) und Anschlusskonzepte (n:<Konzept>)
function ccBan(){let b=$('#ccBan');if(!b)return;const later=ccLaterGet(),s=ccS();
  const L=!ccLead()||panel==='cc'?[]:[...(s.sug||[]).map(x=>({k:'s:'+x.id,cid:x.concept_id,name:x.name,n:x.n,why:'Der PLS liegt im Geltungsbereich'})),...ccNext().map(c=>({k:'n:'+c.id,cid:c.id,name:c.name,n:c.items.length,why:'Das Suchgebiet erreicht den Geltungsbereich (Anschluss)',next:1}))].filter(x=>!later.includes(x.k));
  if(!L.length){b.hidden=true;b.dataset.k='';return}const x=L[0],k=x.k+'|'+L.length;if(b.dataset.k===k&&!b.hidden)return;b.dataset.k=k;b.hidden=false;
  b.innerHTML=`<b>📋 ${x.next?'Anschlusskonzept':'Einsatzkonzept'} verfügbar</b><span class="notranslate" translate="no">«${esc(x.name)}»</span><div class="mut">${esc(x.why)} · ${x.n} ${x.n===1?'Element':'Elemente'}${L.length>1?' · '+(L.length-1)+' weitere':''}</div><div class="bb"><button class="pri" data-ccv="${x.cid}">Vorschau …</button><button data-ccl="${x.cid}">Laden</button><button data-cclater="${esc(x.k)}">Später</button></div>`}
// Posten: besetzt, wenn eine aktive Einsatzkraft mit der Spezialrolle höchstens post_m entfernt ist
function ccPostTxt(p){if(!p.cc_role)return'';const pt=p.pts&&p.pts[0];if(!pt)return'';const rn=(roles.find(r=>r.id===p.cc_role)||{}).name||'Spezialrolle';
  const near=(st.participants||[]).filter(x=>!x.left_at&&x.mode!=='declined'&&x.role_id===p.cc_role&&x.lat!=null&&L.latLng(x.lat,x.lon).distanceTo(pt)<=CC.postM);
  return 'Auftrag '+rn+' · '+(near.length?'besetzt durch '+near.map(x=>x.name).join(', '):'noch nicht besetzt')}
// Untergruppen im Fenster «Markierungen»: [{id, name, items}] je geladenem Konzept mit Markierungen
function ccPoiGroups(ps){const ids=[...new Set(ps.filter(p=>p.cc_id).map(p=>p.cc_id))];return ids.map(id=>({id,name:ccName(id)||'Einsatzkonzept',items:ps.filter(p=>p.cc_id===id)}))}
// Element in Kurzform
function ccItemTxt(it){const r=it.refs||{};if(it.kind==='post'){const rn=(roles.find(x=>x.id===it.role_id)||{}).name;const n=(r.users||[]).length+(r.groups||[]).length;return 'Auftrag'+(rn?' · '+rn:'')+(n?' · Zuteilung an '+n+(n===1?' Person bzw. Gruppe':' Personen bzw. Gruppen'):'')}
  if(it.kind==='msg')return '«'+(it.text||'')+'»';if(it.kind==='ext')return 'Externe Einsatzkraft'+(it.descr?' · '+it.descr:'');if(it.kind==='doc')return (r.docs||[]).length+' Dokument(e) im Fenster «Dokumente»';
  if(it.kind==='invite')return (r.groups||[]).length+' Einsatzgruppe(n), mit Alarm';if(it.kind==='voice')return (r.ids||[]).length+' Kanal/Kanäle';if(it.kind==='stop')return r.off?'im Suchgebiet nicht berücksichtigen':'im Suchgebiet berücksichtigen';
  return it.descr||CCK[it.kind]||''}
function ccItemIc(it){if(it.sym)return `<span class="sws">${symImg(it.sym,24)}</span>`;if(it.kind==='ext')return `<span class="ccext" style="background:${esc(it.color||'#5a6b74')}">${esc(it.abbr||'EX')}</span>`;
  if(it.kind==='area'||it.kind==='line')return `<span class="sw" style="background:${esc(it.color||'#ff9f1c')}${it.kind==='line'?';height:4px;align-self:center':''}"></span>`;
  return `<span class="sws ccic">${{msg:'✉',doc:'📄',stop:'⛔',voice:'🎙',invite:'📣',opt:'⚙',post:'⚑',point:'⚑'}[it.kind]||'•'}</span>`}
const ccOk=it=>!CC_FEAT[it.kind]||feat(CC_FEAT[it.kind]);
// Seitenfenster
function ccOpen(cid){CC.prev=cid;CC.sel=null;if(cid!=null){const c=(CC.list||[]).find(x=>x.id===cid);if(c)CC.sel=new Set(c.items.filter(x=>x.def_on&&ccOk(x)&&!c.done.includes(x.id)).map(x=>x.id))}
  if(panel!=='cc')togglePanel('cc',true);else renderCc();if(!CC.list)ccFetch(true);if(cid!=null)ccFitPrev()}
function ccPrevClear(){if(CC.lay){map.removeLayer(CC.lay);CC.lay=null}}
function ccFitPrev(){const c=(CC.list||[]).find(x=>x.id===CC.prev);if(!c)return;const b=L.latLngBounds([]);ccGeo(c).forEach(l=>l.forEach(p=>b.extend(p)));c.items.forEach(it=>{if(it.lat!=null)b.extend([it.lat,it.lon]);(it.pts||[]).forEach(p=>b.extend(p))});
  if(b.isValid())map.fitBounds(b,{paddingTopLeft:[innerWidth>=760?20:10,70],paddingBottomRight:[innerWidth>=760?360:10,innerWidth>=760?40:Math.round(innerHeight*.55)]})}
function ccDrawPrev(c){ccPrevClear();if(!c)return;const g=L.layerGroup();
  ccGeo(c).forEach(l=>l.length>1&&g.addLayer(c.scope==='river'?L.polyline(l,{color:'#b18cff',weight:Math.max(6,Math.min(30,((c.river||{}).buf||150)/8)),opacity:.35,interactive:false}):L.polygon(l,{color:'#b18cff',weight:3,dashArray:'8 6',fillColor:'#7b4bd6',fillOpacity:.08,interactive:false})));
  c.items.filter(it=>CC.sel&&CC.sel.has(it.id)).forEach(it=>{const tip=it.name.replace(/ –.*/,'');
    if((it.kind==='post'||it.kind==='point')&&it.lat!=null)g.addLayer(L.marker([it.lat,it.lon],{interactive:false,icon:L.divIcon({className:'symk ccpv',html:it.sym?symImg(it.sym,30):'<span class="ccflag" style="color:'+esc(it.color||'#ff9f1c')+'">⚑</span>',iconSize:[30,30],iconAnchor:[15,15]})}).bindTooltip(esc(tip),{permanent:true,direction:'right',offset:[14,0],className:'cclbl'}));
    else if(it.kind==='ext'&&it.lat!=null)g.addLayer(L.marker([it.lat,it.lon],{interactive:false,icon:L.divIcon({className:'',html:`<span class="ccext big" style="background:${esc(it.color||'#5a6b74')}">${esc(it.abbr||'EX')}</span>`,iconSize:[26,26],iconAnchor:[13,13]})}).bindTooltip(esc(tip),{permanent:true,direction:'right',offset:[14,0],className:'cclbl'}));
    else if(it.kind==='area'&&it.pts)g.addLayer(L.polygon(it.pts,{color:it.color||'#ff9f1c',weight:2,dashArray:'6 5',fillOpacity:.15,interactive:false}));
    else if(it.kind==='line'&&it.pts)g.addLayer(L.polyline(it.pts,{color:it.color||'#ff9f1c',weight:4,dashArray:'6 5',interactive:false}))});
  CC.lay=g.addTo(map)}
function renderCc(){const o=$('#ccp');if(!o)return;const on=panel==='cc'&&ccLead();o.hidden=!on;if(!on){ccPrevClear();return}
  const L0=CC.list,c=CC.prev!=null&&L0?L0.find(x=>x.id===CC.prev):null,T=$('#ccpT'),F=$('#ccpF'),B=$('#ccpL');
  if(!L0){T.textContent='Einsatzkonzepte';B.innerHTML='<div class="mut" style="padding:.6rem 0">Wird geladen …</div>';F.innerHTML='';return}
  if(!c){ccPrevClear();T.textContent='Einsatzkonzept laden';const ls=[...L0].sort((a,b)=>b.fit-a.fit||(a.state==='loaded')-(b.state==='loaded'));
    B.innerHTML=ls.length?ls.map(x=>`<div class="pi ccli"><span class="sws ccic">📋</span><div class="nm"><span translate="no" class="notranslate">${esc(x.name)}</span>${x.fit?'<span class="ccchip">passt zum PLS</span>':''}${x.state==='loaded'?'<span class="ccchip gr">geladen</span>':''}<small>${x.items.length} ${x.items.length===1?'Element':'Elemente'}${x.descr?' · '+esc(x.descr):''}</small></div><div class="pa"><button data-ccv="${x.id}">Vorschau …</button></div></div>`).join(''):'<div class="mut" style="padding:.6rem 0">Für diesen Einsatztyp gibt es keine Einsatzkonzepte. Die Verwaltung legt sie unter Einsatz → «Einsatzkonzepte» an.</div>';
    F.innerHTML='';return}
  T.textContent='Einsatzkonzept laden';if(!CC.sel)CC.sel=new Set(c.items.filter(x=>x.def_on&&ccOk(x)&&!c.done.includes(x.id)).map(x=>x.id));
  const row=it=>{const dn=c.done.includes(it.id),ok=ccOk(it);return `<div class="pi${dn||!ok?' ccoff':''}"><input type="checkbox" data-cci="${it.id}" ${CC.sel.has(it.id)?'checked':''} ${dn||!ok?'disabled':''} aria-label="${esc(it.name)} laden">${ccItemIc(it)}<div class="nm"><span translate="no" class="notranslate">${esc(it.name)}</span><small>${esc(ccItemTxt(it))}${dn?' · bereits geladen':''}${!ok?' · Funktion für diesen Einsatztyp ausgeschaltet':''}${CCK[it.kind]&&['msg','invite'].includes(it.kind)?' · löst Meldungen aus':''}</small></div>${it.lat!=null||it.pts?`<div class="pa"><button data-ccz="${it.id}">Zeigen</button></div>`:''}</div>`};
  B.innerHTML=`<div class="ccinfo"><b translate="no" class="notranslate">${esc(c.name)}</b>${c.fit?'<span class="ccchip">passt zum PLS</span>':''}${c.state==='loaded'?'<span class="ccchip gr">geladen</span>':''}${c.descr?`<div class="mut">${esc(c.descr)}</div>`:''}<div class="mut">Geltungsbereich: ${c.scope==='river'?'entlang Gewässer'+((c.river||{}).km?', ca. '+c.river.km+' km':''):'Bereich auf der Karte'}</div></div>`
    +CC_GRP.map(([t,ks])=>{const its=c.items.filter(x=>ks.includes(x.kind));return its.length?`<div class="psh"><span class="nochk"></span><b class="ccgh">${t} (${its.length})</b></div>`+its.map(row).join(''):''}).join('');
  const n=[...CC.sel].length,m=c.items.filter(x=>ccOk(x)&&!c.done.includes(x.id)).length;
  F.innerHTML=`<div class="ccfoot"><button class="pri" id="ccGo" ${n?'':'disabled'}>${n} von ${m} ${m===1?'Element':'Elementen'} laden</button><button data-ccback>Andere Konzepte</button><div class="mut">Geladene Elemente sind gewöhnliche Markierungen, Bereiche, Linien und Externe des Einsatzes: einzeln änderbar oder mit dem Konzept wieder entfernbar.</div></div>`;
  ccDrawPrev(c)}
async function ccLoad(cid,items){if(CC.busy)return;CC.busy=1;try{const r=await api('incident/concept/load',items?{id:cid,items}:{id:cid});toast('Einsatzkonzept geladen.','ok');if(panel==='cc')togglePanel('cc',false);CC.prev=null;await poll();ccFetch(true);return r}catch(e){fail(e)}finally{CC.busy=0}}
// Karte «Einsatzkonzepte» im Register «Leitung»
function ccLeadCard(){if(!ccOn()||!st.can_manage)return'';const s=ccS(),ls=CC.list||[],cn=id=>(ls.find(x=>x.id===id)||{});
  const pend=x=>(x.pend||[]).length?`<div class="ccpend"><b>Offen (beim automatischen Laden nicht ausgelöst):</b> ${x.pend.map(p=>esc(p.name)).join(', ')}<div class="mbtns"><button data-ccrun="${x.id}">Jetzt auslösen</button><button data-ccdrop="${x.id}">Verwerfen</button></div></div>`:'';
  const ld=(s.loaded||[]).map(x=>`<div class="ccrow"><b translate="no" class="notranslate">${esc(x.name)}</b> <span class="ccchip">${x.auto?'automatisch geladen':'geladen'}</span><div class="mut">${fmtT(x.at)}${x.by_name?' von '+esc(x.by_name):''} · ${x.n} ${x.n===1?'Element':'Elemente'}</div>${pend(x)}<div class="mbtns"><button data-ccshow="${x.id}">Zeigen</button><button data-ccv="${x.concept_id}">Elemente …</button><button data-ccrm="${x.id}">Entfernen</button></div></div>`).join('');
  const sg=(s.sug||[]).map(x=>`<div class="ccrow"><b translate="no" class="notranslate">${esc(x.name)}</b> <span class="ccchip">vorgeschlagen</span><div class="mut">Der PLS liegt im Geltungsbereich · ${x.n} ${x.n===1?'Element':'Elemente'}</div><div class="mbtns"><button class="pri" data-ccv="${x.concept_id}">Vorschau …</button><button data-ccl="${x.concept_id}">Laden</button><button data-ccdis="${x.id}">Nicht laden</button></div></div>`).join('');
  const nx=ccNext().map(c=>`<div class="ccrow"><b translate="no" class="notranslate">${esc(c.name)}</b> <span class="ccchip gr">Anschluss</span><div class="mut">Das Suchgebiet erreicht den Geltungsbereich.</div><div class="mbtns"><button data-ccv="${c.id}">Vorschau …</button></div></div>`).join('');
  const hand=(s.fit||[]).filter(id=>!(s.loaded||[]).some(x=>x.concept_id===id)&&!(s.sug||[]).some(x=>x.concept_id===id)).map(id=>{const c=cn(id);return c.id?`<div class="ccrow"><b translate="no" class="notranslate">${esc(c.name)}</b> <span class="ccchip">passt zum PLS</span><div class="mbtns"><button data-ccv="${c.id}">Vorschau …</button></div></div>`:''}).join('');
  const body=ld+sg+nx+hand;
  return mcard('Einsatzkonzepte',(body||`<div class="mut">${CC.list&&!CC.list.length?'Für diesen Einsatztyp gibt es keine Einsatzkonzepte.':'Kein Einsatzkonzept geladen.'}</div>`)+`<div class="mbtns"><button data-ccall>${(s.loaded||[]).length?'Anderes Konzept laden …':'Einsatzkonzept laden …'}</button></div>`)}
// Zeigen: Ausschnitt mit allen Markierungen und Externen eines geladenen Konzepts
function ccShow(id){const b=L.latLngBounds([]);(st.pois||[]).filter(p=>p.cc_id===id).forEach(p=>p.pts.forEach(q=>b.extend(q)));(st.externals||[]).filter(e=>e.cc_id===id).forEach(e=>b.extend([e.lat,e.lon]));
  if(!b.isValid()){toast('Keine Elemente auf der Karte.','info');return}toggleSheet(false);map.fitBounds(b,{padding:[40,40],maxZoom:17})}
function ccPaint(){if(!$('#ccBan'))return;if(ccLead())ccFetch();else{CC.list=null;CC.key=null;if(panel==='cc')togglePanel('cc',false)}ccBan()}
document.addEventListener('click',async e=>{const b=e.target.closest('[data-ccv],[data-ccl],[data-cclater],[data-ccall],[data-ccback],[data-ccz],[data-ccrun],[data-ccdrop],[data-ccrm],[data-ccdis],[data-ccshow],#ccGo,#bCcX');if(!b)return;const d=b.dataset;
  if(b.id==='bCcX'){CC.prev=null;togglePanel('cc',false);return}
  if(d.ccv){if(!CC.list)await ccFetch(true);ccOpen(+d.ccv);return}
  if(d.ccall!=null){if(!CC.list)await ccFetch(true);ccOpen(null);return}
  if(d.ccback!=null){CC.prev=null;renderCc();return}
  if(d.cclater){ccLaterAdd(d.cclater);return}
  if(d.ccl){const c=(CC.list||[]).find(x=>x.id===+d.ccl);if(!await ask(`Einsatzkonzept «${c?c.name:''}» laden?\n\nDie vorausgewählten Elemente erscheinen für alle auf der Karte.`))return;ccLoad(+d.ccl);return}
  if(b.id==='ccGo'){const c=(CC.list||[]).find(x=>x.id===CC.prev);if(!c)return;const its=[...CC.sel],msg=c.items.filter(x=>its.includes(x.id)&&(['msg','invite'].includes(x.kind)||x.kind==='post'&&((x.refs.users||[]).length||(x.refs.groups||[]).length)));
    if(msg.length&&!await ask(`Einsatzkonzept laden?\n\nDabei gehen Meldungen hinaus: ${msg.map(x=>x.name).join(', ')}.`))return;ccLoad(c.id,its);return}
  if(d.ccz){const c=(CC.list||[]).find(x=>x.id===CC.prev),it=c&&c.items.find(x=>x.id===+d.ccz);if(!it)return;if(it.pts&&it.pts.length>1)map.fitBounds(it.pts,{padding:[40,40]});else map.panTo([it.lat,it.lon]);return}
  if(d.ccrun){b.disabled=true;api('incident/concept/run',{id:+d.ccrun}).then(()=>{toast('Ausgelöst.','ok');poll()}).catch(x=>{b.disabled=false;fail(x)});return}
  if(d.ccdrop){if(!await ask('Offene Aktionen verwerfen?\n\nNachrichten, Zuteilungen und Nachbieten des Konzepts werden dann nicht mehr ausgelöst.'))return;api('incident/concept/drop',{id:+d.ccdrop}).then(poll).catch(fail);return}
  if(d.ccrm){const x=(ccS().loaded||[]).find(y=>y.id===+d.ccrm);if(!await ask(`Einsatzkonzept «${x?x.name:''}» entfernen?\n\nDie Markierungen, Bereiche, Linien und Externen des Konzepts verschwinden für alle. Nachrichten, Funkkanäle, Flusssperren und Nachbieten bleiben.`))return;
    api('incident/concept/remove',{id:+d.ccrm}).then(r=>{toast(r.n===1?'1 Element entfernt.':r.n+' Elemente entfernt.','ok');poll();ccFetch(true)}).catch(fail);return}
  if(d.ccdis){api('incident/concept/dismiss',{id:+d.ccdis}).then(poll).catch(fail);return}
  if(d.ccshow){ccShow(+d.ccshow)}});
document.addEventListener('change',e=>{const t=e.target;if(!t.dataset||!t.dataset.cci||!CC.sel)return;const id=+t.dataset.cci;if(t.checked)CC.sel.add(id);else CC.sel.delete(id);renderCc()});
