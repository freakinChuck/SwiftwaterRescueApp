/* Funk & Konferenz (Issue #113): Durchsagen aufnehmen und abspielen, Mithören, Live-Konferenz über LiveKit.
   Gemeinsam für die Einsatz-App (index.html) und die Gastseite für Externe (funk.html). Die Seite übergibt mit vxInit die Anbindung an den Server (io):
   io.upload(body) → Promise, io.audio(id) → Promise<Blob>, io.join(ch) → Promise<{url, token, speak}>, io.change() (neu zeichnen), io.me (eigene User-ID, Gäste null), io.guest (Gast-ID) */
const LK_URL='https://cdn.jsdelivr.net/npm/livekit-client@2.23.0/dist/livekit-client.umd.js'; // feste Version, erst beim ersten Beitritt geladen
const VX={io:null,key:'',items:new Map(),heard:new Set(),first:true,vs:0,listen:true,queue:[],playing:null,audio:null,urls:new Map(),rec:null,conf:null,pend:0,max:60,unlocked:false};
const vxLs=(k,v)=>{try{if(v===undefined)return JSON.parse(localStorage.getItem(k)||'null');localStorage.setItem(k,JSON.stringify(v))}catch(e){return null}};
// key: z. B. Einsatz-ID (gehörte Durchsagen pro Gerät und Einsatz in sw_vxheard_<key>); Mithören pro Gerät in sw_vxlisten (sonst Vorgabe der Verwaltung)
function vxInit(io,key,listenDef){if(VX.key===String(key)&&VX.io){VX.io=io;return}
  vxStop();VX.io=io;VX.key=String(key);VX.items=new Map();VX.queue=[];VX.first=true;VX.vs=0;VX.heard=new Set(vxLs('sw_vxheard_'+key)||[]);
  const l=vxLs('sw_vxlisten');VX.listen=l===null?!!listenDef:!!l}
const vxSaveHeard=()=>vxLs('sw_vxheard_'+VX.key,[...VX.heard].slice(-500));
function vxSetListen(on){VX.listen=!!on;vxLs('sw_vxlisten',VX.listen?1:0);vxUnlock();if(!on){VX.queue=[]}else vxNext();VX.io&&VX.io.change()}
// Browser spielen Ton erst nach einer Bedienung: beim ersten Antippen der Seite ein stummes Audio-Element starten, das danach wiederverwendet wird
function vxUnlock(){if(VX.unlocked)return;VX.unlocked=true;try{const a=VX.audio||(VX.audio=new Audio());a.muted=true;a.src='data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQAAAAA=';const p=a.play();if(p&&p.catch)p.catch(()=>{VX.unlocked=false})}catch(e){VX.unlocked=false}}
document.addEventListener('pointerdown',vxUnlock,{capture:true,passive:true});
// neue Stände vom Server (inkrementell nach upd): beim ersten Abruf gilt alles Bisherige als gehört (kein Abspielen alter Durchsagen)
function vxMerge(list,now){let fresh=[];for(const it of list||[]){const had=VX.items.has(it.id);VX.items.set(it.id,it);
    if(VX.first||it.uid&&VX.io.me&&it.uid===VX.io.me||it.guest&&VX.io.guestName&&it.name===VX.io.guestName)VX.heard.add(it.id);
    else if(VX.conf&&VX.conf.ch===it.ch&&it.rec_at>=VX.conf.since-2)VX.heard.add(it.id); // live in der Konferenz gehört
    else if(!had&&!VX.heard.has(it.id))fresh.push(it)}
  if(now)VX.vs=now;VX.first=false;vxSaveHeard();
  if(VX.listen)fresh.filter(it=>it.audio&&!document.hidden).forEach(it=>{if(!VX.queue.includes(it.id)&&VX.playing!==it.id)VX.queue.push(it.id)});
  vxNext();return fresh}
const vxList=ch=>[...VX.items.values()].filter(x=>ch==null||x.ch===ch).sort((a,b)=>a.id-b.id);
const vxUnheard=ch=>vxList(ch).filter(x=>!VX.heard.has(x.id)).length;
const vxDur=ms=>{const s=Math.round((ms||0)/1000);return Math.floor(s/60)+':'+String(s%60).padStart(2,'0')};
// Balken als Andeutung der Aufnahme (gleich für dieselbe Durchsage; echte Pegel kennt der Server nicht)
function vxWave(it,n){n=n||Math.max(6,Math.min(24,Math.round((it.dur||1000)/400)));let h='',x=(it.id*2654435761)>>>0;for(let i=0;i<n;i++){x=(x*1103515245+12345)>>>0;h+=`<i style="height:${5+(x>>>16)%16}px"></i>`}return`<span class="vxw${VX.playing===it.id?' on':''}" aria-hidden="true">${h}</span>`}
async function vxUrl(id){if(VX.urls.has(id))return VX.urls.get(id);const b=await VX.io.audio(id);const u=URL.createObjectURL(b);VX.urls.set(id,u);return u}
// abspielen (von Hand oder aus der Warteschlange); Ende → gehört, nächste
async function vxPlay(id){const it=VX.items.get(id);vxUnlock();
  try{const u=await vxUrl(id);const a=VX.audio||(VX.audio=new Audio());a.muted=false;a.pause();a.src=u;VX.playing=id;VX.io.change();
    a.onended=a.onerror=()=>{if(VX.playing===id){VX.playing=null;VX.heard.add(id);vxSaveHeard();VX.io.change();vxNext()}};
    await a.play()}catch(e){VX.playing=null;VX.heard.add(id);vxSaveHeard();VX.io.change();if(e&&e.name==='NotAllowedError')toast('Der Browser hat das Abspielen blockiert: einmal auf die Seite tippen und erneut versuchen.','info');else if(it)toast('Durchsage konnte nicht abgespielt werden'+(e&&e.message?': '+e.message:''),'err');vxNext()}}
function vxNext(){if(VX.playing||!VX.queue.length||(VX.rec&&VX.rec.on))return;const id=VX.queue.shift();vxPlay(id)}
function vxStop(){VX.queue=[];if(VX.audio){VX.audio.pause();VX.audio.onended=null}if(VX.playing){VX.heard.add(VX.playing);VX.playing=null;vxSaveHeard()}VX.io&&VX.io.change()}
function vxMarkHeard(ch){vxList(ch).forEach(x=>VX.heard.add(x.id));vxSaveHeard()}
/* ---------- Aufnahme ---------- */
const vxMime=()=>{if(!window.MediaRecorder)return'';for(const m of['audio/mp4','audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus'])try{if(MediaRecorder.isTypeSupported(m))return m}catch(e){}return''};
const vxCanRec=()=>!!(navigator.mediaDevices&&navigator.mediaDevices.getUserMedia&&vxMime());
// Aufnahme starten; ch = Kanal. Während der Aufnahme pausiert die Wiedergabe; in der Konferenz desselben Kanals geht der Ton zusätzlich live hinaus.
async function vxRecStart(ch){if(VX.rec)return;const mime=vxMime();if(!mime){toast('Dieser Browser kann keine Tonaufnahmen machen.','err');return}
  if(VX.audio)VX.audio.pause();VX.playing=null;
  const r=VX.rec={ch,on:true,t0:Date.now(),at:Math.floor(Date.now()/1000),chunks:[],drop:false,mime};VX.io.change();
  if(VX.conf&&VX.conf.ch===ch&&VX.conf.speak)vxConfMic(true);
  try{r.stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true,channelCount:1}})}
  catch(e){VX.rec=null;VX.io.change();toast(e&&e.name==='NotAllowedError'?'Kein Zugriff aufs Mikrofon: bitte in den Einstellungen des Browsers bzw. der App erlauben.':'Mikrofon nicht verfügbar: '+(e&&e.message||e),'err');return}
  if(!r.on){r.stream.getTracks().forEach(t=>t.stop());VX.rec=null;VX.io.change();return} // schon losgelassen, bevor das Mikrofon bereit war
  r.mr=new MediaRecorder(r.stream,{mimeType:mime,audioBitsPerSecond:32000});r.mr.ondataavailable=e=>{if(e.data&&e.data.size)r.chunks.push(e.data)};
  r.mr.onstop=()=>vxRecDone(r);r.mr.start(250);r.t0=Date.now();
  r.tm=setInterval(()=>{if((Date.now()-r.t0)/1000>=VX.max)vxRecStop(false);else VX.io.change()},250)}
function vxRecStop(drop){const r=VX.rec;if(!r)return;r.on=false;if(drop)r.drop=true;clearInterval(r.tm);if(VX.conf&&VX.conf.speak&&!VX.conf.open)vxConfMic(false);
  if(r.mr&&r.mr.state!=='inactive')r.mr.stop();else if(!r.mr){/* Mikrofon noch nicht bereit: vxRecStart räumt auf */}}
async function vxRecDone(r){r.stream.getTracks().forEach(t=>t.stop());VX.rec=null;const ms=Date.now()-r.t0;VX.io.change();
  if(r.drop)return toast('Aufnahme verworfen.','info');if(ms<600)return toast('Zu kurz: die Sprechtaste gedrückt halten, während du sprichst.','info');
  const blob=new Blob(r.chunks,{type:r.mime.split(';')[0]});const data=await new Promise(ok=>{const f=new FileReader();f.onload=()=>ok(String(f.result).split(',')[1]||'');f.readAsDataURL(blob)});
  const body={ch:r.ch,data,mime:r.mime.split(';')[0],dur:Math.min(ms,VX.max*1000),rec_at:r.at};vxSend(body);vxNext()}
// senden; ohne Verbindung in sw_vxq_<key> merken und beim nächsten erfolgreichen Abruf nachsenden (vxFlush)
async function vxSend(body){VX.pend++;VX.io.change();try{await VX.io.upload(body);VX.io.poll&&VX.io.poll()}
  catch(e){if(e instanceof TypeError){const q=vxLs('sw_vxq_'+VX.key)||[];q.push(body);vxLs('sw_vxq_'+VX.key,q.slice(-20));toast('Keine Verbindung: die Durchsage wird gesendet, sobald die Verbindung zurück ist.','info')}else toast(e.message,'err')}
  finally{VX.pend--;VX.io.change()}}
async function vxFlush(){const q=vxLs('sw_vxq_'+VX.key)||[];if(!q.length||VX.flushing)return;VX.flushing=true;const rest=[];
  for(const b of q){try{await VX.io.upload(b)}catch(e){if(e instanceof TypeError)rest.push(b);else toast('Durchsage nicht nachgesendet: '+e.message,'err')}}
  vxLs('sw_vxq_'+VX.key,rest);VX.flushing=false;if(q.length>rest.length)toast(`${q.length-rest.length===1?'Eine Durchsage':(q.length-rest.length)+' Durchsagen'} nachgesendet.`,'ok')}
const vxQueued=()=>(vxLs('sw_vxq_'+VX.key)||[]).length;
// Sprechtaste: gedrückt halten (loslassen = senden, zur Seite wischen = verwerfen) oder, mit «Antippen» (sw_vxtap), antippen zum Starten und Stoppen; Leertaste auf dem Knopf
const vxTap=()=>!!vxLs('sw_vxtap');
function vxPttBind(btn,getCh){if(!btn)return;let x0=0,id=null;
  const start=()=>{const ch=getCh();if(ch==null)return toast('Kein Kanal zum Sprechen gewählt.','info');vxRecStart(ch)};
  btn.oncontextmenu=e=>e.preventDefault();
  // Loslassen auch ausserhalb des Knopfs (Finger verrutscht, Knopf neu gezeichnet): Zeiger am Dokument verfolgen, solange gesprochen wird
  const mv=e=>{if(id!==e.pointerId||!VX.rec)return;const d=Math.abs(e.clientX-x0)>80;if(d!==!!VX.rec.drop){VX.rec.drop=d;VX.io.change()}};
  const up=e=>{if(id!==e.pointerId)return;id=null;document.removeEventListener('pointermove',mv,true);document.removeEventListener('pointerup',up,true);document.removeEventListener('pointercancel',up,true);if(VX.rec)vxRecStop(VX.rec.drop||e.type==='pointercancel')};
  btn.onpointerdown=e=>{if(e.button)return;e.preventDefault();if(vxTap()){if(VX.rec)vxRecStop(false);else start();return}id=e.pointerId;x0=e.clientX;try{btn.setPointerCapture(id)}catch(x){}
    document.addEventListener('pointermove',mv,true);document.addEventListener('pointerup',up,true);document.addEventListener('pointercancel',up,true);start()};
  btn.onkeydown=e=>{if((e.key===' '||e.key==='Enter')&&!e.repeat){e.preventDefault();if(vxTap()&&VX.rec)vxRecStop(false);else if(!VX.rec)start()}};
  btn.onkeyup=e=>{if((e.key===' '||e.key==='Enter')&&!vxTap()&&VX.rec)vxRecStop(false)}}
const vxRecS=()=>VX.rec?Math.floor((Date.now()-VX.rec.t0)/1000):0;
/* ---------- Live-Konferenz (LiveKit) ---------- */
function vxLoadLk(){if(window.LivekitClient)return Promise.resolve();if(VX.lkLoad)return VX.lkLoad;
  return VX.lkLoad=new Promise((ok,no)=>{const s=document.createElement('script');s.src=LK_URL;s.onload=()=>ok();s.onerror=()=>{VX.lkLoad=null;no(new Error('LiveKit konnte nicht geladen werden'))};document.head.appendChild(s)})}
// beitreten (verlässt eine andere Konferenz); stumm, Senden über die Sprechtaste oder «Mikrofon offen»
async function vxConfJoin(ch){if(VX.conf&&VX.conf.ch===ch)return;if(VX.conf)await vxConfLeave();vxUnlock();
  const c=VX.conf={ch,since:Math.floor(Date.now()/1000),state:'connecting',speak:false,open:false,mic:false,speakers:new Set(),peers:[]};VX.io.change();
  try{await vxLoadLk();const j=await VX.io.join(ch);if(VX.conf!==c)return;c.speak=!!j.speak;const LK=window.LivekitClient;
    const room=c.room=new LK.Room({adaptiveStream:false,dynacast:false,audioCaptureDefaults:{echoCancellation:true,noiseSuppression:true,autoGainControl:true}});
    const peers=()=>{c.peers=[...room.remoteParticipants.values()].map(p=>({id:p.identity,name:p.name||p.identity}));VX.io.change()};
    room.on(LK.RoomEvent.TrackSubscribed,t=>{if(t.kind==='audio'){const el=t.attach();el.dataset.vx='1';el.hidden=true;document.body.appendChild(el)}})
      .on(LK.RoomEvent.TrackUnsubscribed,t=>t.detach().forEach(el=>el.remove()))
      .on(LK.RoomEvent.ActiveSpeakersChanged,sp=>{c.speakers=new Set(sp.map(p=>p.identity));VX.io.change()})
      .on(LK.RoomEvent.ParticipantConnected,peers).on(LK.RoomEvent.ParticipantDisconnected,peers)
      .on(LK.RoomEvent.Reconnecting,()=>{c.state='reconnecting';VX.io.change()}).on(LK.RoomEvent.Reconnected,()=>{c.state='on';VX.io.change()})
      .on(LK.RoomEvent.Disconnected,()=>{if(VX.conf===c){VX.conf=null;VX.io.conf&&VX.io.conf(0);VX.io.change()}});
    await room.connect(j.url,j.token,{autoSubscribe:true});await room.startAudio().catch(()=>{});
    if(VX.conf!==c){room.disconnect();return}c.state='on';peers();VX.io.conf&&VX.io.conf(ch)}
  catch(e){if(VX.conf===c)VX.conf=null;VX.io.change();toast('Konferenz: '+(e&&e.message||e),'err')}}
async function vxConfLeave(){const c=VX.conf;if(!c)return;VX.conf=null;try{if(c.room)await c.room.disconnect()}catch(e){}document.querySelectorAll('audio[data-vx]').forEach(el=>el.remove());VX.io.conf&&VX.io.conf(0);VX.io.change()}
async function vxConfMic(on){const c=VX.conf;if(!c||!c.room||!c.speak)return;try{await c.room.localParticipant.setMicrophoneEnabled(!!on);c.mic=!!on}catch(e){toast('Mikrofon: '+(e&&e.message||e),'err')}VX.io.change()}
function vxConfOpen(on){const c=VX.conf;if(!c)return;c.open=!!on;vxConfMic(on)}
const vxSpeaking=id=>!!(VX.conf&&VX.conf.speakers.has(id));
