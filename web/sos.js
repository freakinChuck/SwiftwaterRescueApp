/* Notruf (SOS) für Einsatzkräfte (Issue #240), Einsatz-App. Nutzt die Bausteine aus common.js und index.html (st, me, map, api, toast, ask, esc, $,
   notify, ackGet/ackAdd, sysNote, alarmTone, selfAt, navGo, feat, fc).
   - Knopf «SOS» links unter ☰ (#sosB): nur im Einsatz mit Teilnahme und eingeschalteter Funktion «sos»; auslösen durch langes Drücken (sos_cfg.hold s,
     Fortschritt im Ring), danach Countdown (sos_cfg.countdown s) mit «Abbrechen» und «Sofort senden» gegen Fehlauslösungen. Läuft der eigene Notruf,
     wird der Knopf zu «SOS läuft», Antippen fragt «Notruf beenden?».
   - Empfänger (Server: to_me) erhalten ein Vollbild (#sosAl) mit Name, Distanz, 🧭, «Zeigen» und «Quittieren»; der Ton wiederholt sich bis zum Quittieren
     (Quittierschlüssel x:<id> in sw_acked, wie die Alarme). Neue Notrufe richten die Karte auf die Person aus.
   - Leiste oben (#sosBar): je laufendem Notruf «SOS – Name – vor N min», quittiert von, Knöpfe Zeigen, 🧭, Quittieren, Beenden (eigener oder Einsatzleitung).
   - Karte: roter, blinkender Ring am Standort (layers.sos).
   - Funk (sos_cfg.conf): neuer Notruf mit eigenem Ton, solange man in der Live-Konferenz ist oder mithört. */
const SOS={known:null,shown:null,al:null,loop:null,hold:null,cd:null,key:''};
const sosList=()=>(st&&st.incident&&st.sos)||[];
const sosCfg=()=>(st&&st.sos_cfg)||(me&&me.sos_cfg)||{hold:2,countdown:5,conf:1};
const sosMine=()=>sosList().find(s=>s.mine);
// Knopf nur mit aktiver Teilnahme am angezeigten Einsatz (der Server nimmt den Notruf nur dort an)
const sosMay=()=>{const m=st&&st.incident&&st.me;return !!(st&&st.incident&&st.sos_cfg&&feat('sos')&&!st.blocked&&m&&!m.left_at&&m.mode!=='declined')};
const sosAgo=s=>{const m=Math.max(0,Math.floor((((st&&st.now)||Date.now()/1000)-s.at)/60));return m<1?'gerade eben':'vor '+m+' min'};
function sosDist(s){const p=selfAt&&selfAt();if(!p||s.lat==null)return'';const d=map?map.distance([p.lat,p.lon],[s.lat,s.lon]):0;return d<1000?Math.round(d)+' m entfernt':(d/1000).toFixed(1)+' km entfernt'}
// Ton für Notrufe: drei hohe, kurze Töne (unterscheidet sich vom Alarm)
function sosTone(){try{[0,180,360].forEach(t=>setTimeout(()=>tone(1320,140),t));navigator.vibrate&&navigator.vibrate([500,150,500,150,500])}catch(e){}}
// fehlende Elemente einsetzen (shell() baut #app neu auf)
function sosEls(){const a=$('#app');if(!a||!$('#map'))return false;if($('#sosB'))return true;
  a.insertAdjacentHTML('beforeend',`<button id="sosB" class="fab" hidden aria-label="Notruf (SOS) auslösen: lange drücken" title="Notruf (SOS): lange drücken"><span class="sosr"></span><span class="sost">SOS</span></button><div id="sosBar" hidden></div>`);
  sosBind($('#sosB'));$('#sosBar').onclick=sosBarClick;return true}
// langes Drücken: Zeiger am Dokument verfolgen (wie die Sprechtaste), Abbruch beim Loslassen vor Ablauf
function sosBind(b){const stop=()=>{if(SOS.hold){clearTimeout(SOS.hold.t);SOS.hold=null}b.classList.remove('hold');document.removeEventListener('pointerup',stop);document.removeEventListener('pointercancel',stop)};
  b.addEventListener('pointerdown',e=>{if(sosMine()){return}e.preventDefault();stop();const h=Math.max(1,+sosCfg().hold||2);b.style.setProperty('--sosh',h+'s');b.classList.add('hold');
    SOS.hold={t:setTimeout(()=>{stop();sosCountdown()},h*1000)};document.addEventListener('pointerup',stop);document.addEventListener('pointercancel',stop)});
  b.addEventListener('contextmenu',e=>e.preventDefault());
  b.addEventListener('click',async()=>{const m=sosMine();if(m){if(await ask('Notruf beenden?\n\nAlle, die alarmiert wurden, erhalten die Meldung «Notruf beendet».'))sosEnd(m.id);return}
    if(!SOS.cd&&!SOS.hold)toast('Für einen Notruf den Knopf «SOS» '+(Math.max(1,+sosCfg().hold||2))+' s gedrückt halten.','info')});
  b.addEventListener('keydown',e=>{if(e.key==='Enter'&&!sosMine()){e.preventDefault();sosCountdown()}})}
// Countdown gegen Fehlauslösungen; 0 s = sofort senden
function sosCountdown(){if(SOS.cd||sosMine())return;let n=Math.max(0,+sosCfg().countdown||0);if(!n)return sosSend();
  const d=document.createElement('div');d.id='sosCd';d.setAttribute('role','alertdialog');
  d.innerHTML=`<div class="sosbox"><b>Notruf (SOS)</b><div class="sosn" id="sosCdN">${n}</div><div>Der Notruf wird in <span id="sosCdT">${n}</span> s gesendet.</div><div class="sosbt"><button class="btn sec" id="sosCdX">Abbrechen</button><button class="btn pri" id="sosCdS">Sofort senden</button></div></div>`;
  document.body.appendChild(d);navigator.vibrate&&navigator.vibrate(200);
  const end=()=>{clearInterval(SOS.cd);SOS.cd=null;d.remove()};
  SOS.cd=setInterval(()=>{n--;if(n<=0){end();sosSend();return}$('#sosCdN').textContent=n;$('#sosCdT').textContent=n;tone(880,120)},1000);
  $('#sosCdX').onclick=()=>{end();toast('Notruf abgebrochen','info')};$('#sosCdS').onclick=()=>{end();sosSend()};$('#sosCdX').focus()}
function sosSend(){const p=selfAt&&selfAt(),b=p?{on:1,lat:p.lat,lon:p.lon}:{on:1};
  api('incident/sos',b).then(()=>{toast('Notruf gesendet. Die Einsatzleitung und Einsatzkräfte in der Nähe sind alarmiert.','ok');poll()}).catch(e=>toast('Notruf fehlgeschlagen: '+e.message+' – bitte über Funk oder Telefon melden!','err'))}
function sosEnd(id){api('incident/sos',{id}).then(()=>{toast('Notruf beendet','ok');poll()}).catch(fail)}
function sosAck(id){ackAdd('x:'+id);sosAlClose(id);api('incident/sos/ack',{id}).then(()=>poll()).catch(fail)}
function sosShow(s){if(!map||s.lat==null)return;if(narrow()&&typeof toggleSheet==='function')toggleSheet(false);if(typeof stopFollow==='function')stopFollow();map.setView([s.lat,s.lon],Math.max(map.getZoom(),16))}
// Vollbild für Empfänger; Ton alle 4 s bis zum Quittieren
function sosAlOpen(s){if(SOS.al===s.id)return;sosAlClose();SOS.al=s.id;const d=document.createElement('div');d.id='sosAl';d.setAttribute('role','alertdialog');d.dataset.id=s.id;$('#app').appendChild(d);sosAlDraw(s);
  sosTone();SOS.loop=setInterval(sosTone,4000);sysNote('SOS',s.name+' braucht Hilfe (Notruf)','x:'+s.id,true,false)}
function sosAlDraw(s){const d=$('#sosAl');if(!d||+d.dataset.id!==s.id)return;
  d.innerHTML=`<div class="sosbox"><div class="sosh">SOS – NOTRUF</div><div class="sosnm notranslate" translate="no">${esc(s.name||'Einsatzkraft')}</div><div>${esc(sosAgo(s))}${s.lat!=null?' · '+esc(sosDist(s)):' · ohne Standort'}</div>
  ${s.lat!=null&&fc(s.lat,s.lon)?`<div class="mut">${esc(fc(s.lat,s.lon))}</div>`:''}${s.ack_name?`<div>Quittiert von <span translate="no">${esc(s.ack_name)}</span></div>`:''}
  <div class="sosbt">${s.lat!=null&&feat('nav')?`<button class="btn" data-sosnav="${s.id}">🧭 Navigieren</button>`:''}${s.lat!=null?`<button class="btn" data-sosshow="${s.id}">Zeigen</button>`:''}<button class="btn pri" data-sosack="${s.id}">Quittieren</button></div></div>`;
  d.onclick=sosBarClick}
function sosAlClose(id){if(id!=null&&SOS.al!==id)return;clearInterval(SOS.loop);SOS.loop=null;SOS.al=null;const d=$('#sosAl');d&&d.remove()}
function sosBarClick(e){const b=e.target.closest('[data-sosack],[data-sosnav],[data-sosshow],[data-sosend]');if(!b)return;const id=+(b.dataset.sosack||b.dataset.sosnav||b.dataset.sosshow||b.dataset.sosend),s=sosList().find(x=>x.id===id);
  if(b.dataset.sosack)return sosAck(id);if(!s)return;
  if(b.dataset.sosnav){ackAdd('x:'+id);sosAlClose(id);navGo(s.lat,s.lon,'SOS '+(s.name||''));return}
  if(b.dataset.sosshow){if(SOS.al===id)sosAck(id);sosShow(s);return}
  if(b.dataset.sosend)(async()=>{if(await ask(s.mine?'Notruf beenden?':'Notruf von '+(s.name||'Einsatzkraft')+' beenden?\n\nNur beenden, wenn die Person in Sicherheit ist.'))sosEnd(id)})()}
// nach jedem Polling: neue Notrufe melden (Vollbild, Kamera, Ton im Funk), beendete schliessen
function sosPoll(nx){const l=(nx&&nx.incident&&nx.sos)||[],ids=l.map(s=>s.id),first=SOS.known===null;SOS.known=SOS.known||new Set();
  l.forEach(s=>{const fresh=!SOS.known.has(s.id);SOS.known.add(s.id);if(s.mine)return;
    if(s.to_me&&!ackGet().includes('x:'+s.id))sosAlOpen(s);else if(SOS.al===s.id)sosAlDraw(s);
    if(fresh&&!first){if(s.to_me&&s.lat!=null)setTimeout(()=>sosShow(s),50);
      if((nx.sos_cfg||{}).conf&&typeof VX!=='undefined'&&(VX.conf||VX.listen)&&!s.to_me)sosTone()}});
  if(SOS.al!=null&&!ids.includes(SOS.al)){const id=SOS.al;sosAlClose();toast('Notruf beendet','info');ackAdd('x:'+id)}
  [...SOS.known].forEach(id=>{if(!ids.includes(id)&&!first)SOS.known.delete(id)})}
// nach jedem paint(): Knopf, Leiste, Marker
function sosPaint(){if(!sosEls())return;const b=$('#sosB'),bar=$('#sosBar'),l=sosList(),m=sosMine(),may=sosMay();
  b.hidden=!may&&!m;document.body.classList.toggle('sosk',!b.hidden);b.classList.toggle('on',!!m);b.querySelector('.sost').textContent=m?'SOS läuft':'SOS';b.title=m?'Eigener Notruf läuft – antippen zum Beenden':'Notruf (SOS): lange drücken';
  const key=JSON.stringify([l.map(s=>[s.id,s.ack_name,s.lat!=null,sosAgo(s)]),st&&st.can_manage]);
  if(SOS.key!==key){SOS.key=key;bar.hidden=!l.length;document.body.classList.toggle('sosb',!!l.length);
    bar.innerHTML=l.map(s=>`<div class="sosi"><b>SOS</b> – <span translate="no">${esc(s.mine?'Dein Notruf':s.name||'Einsatzkraft')}</span> – ${esc(sosAgo(s))}${s.ack_name?` <small>(quittiert von <span translate="no">${esc(s.ack_name)}</span>)</small>`:s.mine?' <small>(noch nicht quittiert)</small>':''}
      <span class="sosbt">${s.lat!=null&&!s.mine?`<button class="btn" data-sosshow="${s.id}">Zeigen</button>`:''}${s.lat!=null&&!s.mine&&feat('nav')?`<button class="btn" data-sosnav="${s.id}" title="Navigieren">🧭</button>`:''}${!s.mine&&!s.ack_name?`<button class="btn" data-sosack="${s.id}">Quittieren</button>`:''}${s.mine||st.can_manage?`<button class="btn sec" data-sosend="${s.id}">Beenden</button>`:''}</span></div>`).join('')}
  sosDraw(l)}
function sosDraw(l){if(!map)return;if(layers.sos){map.removeLayer(layers.sos);layers.sos=null}const ms=l.filter(s=>s.lat!=null).map(s=>L.marker([s.lat,s.lon],{icon:L.divIcon({className:'',html:'<div class="sosmk"><span>SOS</span></div>',iconSize:[56,56],iconAnchor:[28,28]}),zIndexOffset:2000,interactive:true})
  .bindTooltip(esc('SOS – '+(s.name||'Einsatzkraft')),{permanent:true,direction:'bottom',offset:[0,22],className:'sostip'}).on('click',()=>{if(!s.mine&&feat('nav'))navTo(s.lat,s.lon,'SOS '+(s.name||''))}));
  if(ms.length)layers.sos=L.layerGroup(ms).addTo(map)}
