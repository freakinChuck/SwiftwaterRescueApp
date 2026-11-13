/* Demo-Modus (Issue #74): Die Einsatz-App ohne Server ausprobieren, mit oder ohne Anmeldung.
   Aufruf: index.html?demo (Beispieldaten), ?demo=leer (ohne Einsatz). Ohne den Parameter tut diese Datei nichts.
   - Läuft vollständig im Browser: Alle Aufrufe an api/index.php (api(), Applikationslog, Abmelden) fängt ein eigenes fetch ab und
     beantwortet sie aus einem simulierten Backend (DEMO_ROUTES). Der Server erhält nichts.
   - Eigener Speicher: localStorage zeigt im Demo auf einen eigenen Bereich in sessionStorage (Präfix «swdemo:»). Anmeldung, letzter Stand,
     quittierte Alarme und Ansicht der echten App bleiben unberührt; der Demo-Stand endet mit dem Tab.
   - Beispieldaten: ein laufender Einsatz an der Reuss in Luzern; simulierte Einsatzkräfte nehmen an, fahren hin, setzen Markierungen,
     senden Nachrichten und finden den Rettling (DEMO_BSP). In einem selbst gestarteten Einsatz nehmen sie ebenfalls an (DEMO_GEN).
   - Externe Dienste wie in der echten App direkt vom Gerät: Kartenkacheln und das Gewässernetz (Overpass, Rückfall im Gerät wie Issue #28).
   Muss vor common.js geladen werden (common.js liest beim Laden den Anmeldeschlüssel aus localStorage). */
// var statt const: common.js liest window.DEMO
var DEMO=(()=>{const q=new URLSearchParams(location.search);if(!q.has('demo'))return null;
  const want=q.get('demo')==='leer'?'leer':'bsp',SS=window.sessionStorage,PFX='swdemo:';
  // ---------- eigener Speicher statt localStorage ----------
  const mem={},sg=k=>{try{return SS.getItem(k)}catch(e){return mem[k]??null}},ss=(k,v)=>{try{SS.setItem(k,v)}catch(e){mem[k]=v}},sr=k=>{try{SS.removeItem(k)}catch(e){}delete mem[k]};
  const keys=()=>{const o=[];try{for(let i=0;i<SS.length;i++){const k=SS.key(i);if(k.startsWith(PFX))o.push(k.slice(PFX.length))}}catch(e){Object.keys(mem).forEach(k=>{if(k.startsWith(PFX))o.push(k.slice(PFX.length))})}return o};
  const store={getItem:k=>sg(PFX+k),setItem:(k,v)=>ss(PFX+k,String(v)),removeItem:k=>sr(PFX+k),clear:()=>keys().forEach(k=>sr(PFX+k)),key:i=>keys()[i]??null,get length(){return keys().length}};
  try{Object.defineProperty(window,'localStorage',{configurable:true,get:()=>store})}catch(e){console.warn('Demo: localStorage nicht umgeleitet',e)}
  const now=()=>Math.floor(Date.now()/1000);
  // ---------- feste Daten ----------
  const FEAT={area:1,res:1,msg:1,poi:1,ext:1,rd:1,pub:0,rec:1,nav:1,place:1,hydro:1,pinfo:1}; // öffentliche Ansicht braucht den Server
  const KINDS=[{id:1,name:'Einsatz',color:'#ff4d2e',alarm:'crit',alarm_name:'Kritisch',mode_up:0,msg_lvl:'normal',end_lvl:'normal',rec:0,air:1,grp_off:[],grp_def:[],persons:0,open_all:0,plans:0,snd:null,can:1,no_grp:0,features:FEAT},
               {id:2,name:'Übung',color:'#1d7fd1',alarm:'normal',alarm_name:'Normal',mode_up:1,msg_lvl:'normal',end_lvl:'normal',rec:1,air:1,grp_off:[],grp_def:[],persons:0,open_all:0,plans:0,snd:null,can:1,no_grp:0,features:FEAT}];
  const ROLES=[{id:1,name:'Einsatzleitung',color:'#d62828',is_lead:1,is_post:0,can_message:1},{id:2,name:'Rettungsboot',color:'#1d7fd1',is_lead:0,is_post:0,can_message:1},
               {id:3,name:'Rettungsschlitten',color:'#e6a100',is_lead:0,is_post:0,can_message:1},{id:4,name:'Sicherungs- und Beobachtungsposten',color:'#2a9d5c',is_lead:0,is_post:1,can_message:1}];
  const MODES=[{id:1,code:'base',name:'Anfahrt via Stützpunkt',share_location:1,active:1,lead_rights:0,counts:1},{id:2,code:'direct',name:'Anfahrt direkt',share_location:1,active:1,lead_rights:0,counts:1},
               {id:3,code:'logistics',name:'Logistik',share_location:1,active:1,lead_rights:0,counts:1},{id:4,code:'coordinator',name:'Koordinator KP Rück',share_location:0,active:1,lead_rights:1,counts:0}];
  const GROUPS=[{id:1,name:'Wasserrettung Stadt',color:'#1d7fd1'},{id:2,name:'Wasserrettung Land',color:'#2a9d5c'}];
  const SYMS=[['sz-el','sz','Führung','Einsatzleitung (Ei Ltg)',1],['sz-warterm','sz','Führung','Warteraum Rettungsmittel (Warterm)',0],['sz-helipl','sz','Führung','Helikopterlandeplatz (Helipl)',0],
    ['sz-patsst','sz','Sanität','Patientensammelstelle (Pat Sst)',0],['sz-sanhist','sz','Sanität','Sanitätshilfsstelle (San Hist)',0],['sz-tsst','sz','Sanität','Totensammelstelle (TSst)',0],
    ['sz-sstunv','sz','Betreuung','Sammelstelle Unverletzte (Sst Unv)',0],['sz-betreu','sz','Betreuung','Betreuungsstelle (Betreu Stel)',0],['sz-medien','sz','Betreuung','Medientreffpunkt (Medien Tpt)',0],
    ['sz-streugut','sz','Betreuung','Streugut-Sammelstelle (Streugut Sst)',0],['sw-ew','sw','Wasserrettung','Bootseinwasserung',0],['sw-bo','sw','Wasserrettung','Boot im Einsatz',0],
    ['sw-sp','sw','Wasserrettung','Sicherungsposten',0],['sw-bp','sw','Wasserrettung','Beobachtungsposten',0],['sw-tr','sw','Wasserrettung','Seilsicherung / Traverse',0],
    ['sw-st','sw','Wasserrettung','Rettungsstelle Ufer',0],['sw-kp','sw','Führung','Kommandoposten',0],['sw-tp','sw','Führung','Treffpunkt / Sammelplatz',0],['sw-br','sw','Führung','Bereitstellungsraum',0],
    ['sw-sh','sw','Sanität','Sanitätshilfsstelle',0],['sw-ps','sw','Sanität','Patientensammelstelle',0],['sw-hl','sw','Sanität','Helikopter-Landeplatz',0],['sw-am','sw','Sanität','Ambulanz',0],
    ['sw-fw','sw','Partner','Feuerwehr',0],['sw-po','sw','Partner','Polizei',0],['sw-zs','sw','Partner','Zivilschutz',0],['sw-we','sw','Gefahren','Gefahrenstelle (Wehr, Schwelle)',0],
    ['sw-wa','sw','Gefahren','Walze / Rücklauf',0],['sw-ab','sw','Allgemein','Absperrung',0],['sw-md','sw','Allgemein','Materialdepot',0],['sw-pk','sw','Allgemein','Parkplatz Fahrzeuge',0],['sw-fo','sw','Allgemein','Fundort Gegenstand',0]]
    .map(([k,set,cat,name,foot])=>({k,set,cat,name,foot}));
  const SYM=k=>SYMS.find(s=>s.k===k);
  const ME_ID=1;
  // simulierte Einsatzkräfte (fiktive Namen)
  const SIM={101:{name:'Anna Muster',g:[1]},102:{name:'Beat Beispiel',g:[1]},103:{name:'Carla Probst',g:[1]},104:{name:'David Fischer',g:[2]},105:{name:'Eva Keller',g:[2]},106:{name:'Fabian Roth',g:[2]}};
  const OVERPASS=['https://overpass-api.de/api/interpreter','https://maps.mail.ru/osm/tools/overpass/api/interpreter','https://overpass.kumi.systems/api/interpreter'];
  // Beispielort: Reuss in Luzern (Koordinaten ungefähr, nur für die Simulation)
  const P={pls:[47.0534,8.3033],kp:[47.05295,8.30415],sp:[47.0487,8.3097],ew:[47.0612,8.2862],fw:[47.0645,8.2820],post:[47.0688,8.2760],find:[47.0595,8.2902],jacke:[47.0580,8.2927],
    r2:[47.0556,8.2970],r3:[47.0570,8.2940],r4:[47.0588,8.2915],r5:[47.0603,8.2890],r6:[47.0615,8.2865],
    home:{101:[47.0440,8.3220],102:[47.0700,8.3150],103:[47.0390,8.2850],104:[47.0800,8.2900],105:[47.0300,8.3000],106:[47.0500,8.3300]}};
  const PLACES=[{id:1,plan_id:null,cat_id:1,name:'Stützpunkt Wasserrettung (Demo)',descr:'Boote, Schlitten und Material. Fiktiver Standort für den Demo-Modus.',abbr:'SP',color:'#1d7fd1',lat:P.sp[0],lon:P.sp[1],pts:null,line:0,active:1,sort:null},
    {id:2,plan_id:null,cat_id:1,name:'Bootseinwasserung Reusszopf (Demo)',descr:'Einwasserung für Boote, Zufahrt über die Uferstrasse. Fiktiv.',abbr:'EW',color:'#2a9d5c',lat:P.ew[0],lon:P.ew[1],pts:null,line:0,active:1,sort:null}];
  const PLACE_CATS=[{id:1,name:'Wasserrettung',def_on:1}];
  const HYDRO=[{id:'D2152',name:'Luzern, Geissmattbrücke (Demo)',water:'Reuss',type:'river',lat:47.0556,lon:8.2968,flow:118.4,height:432.71,level:null,temp:9.8,danger:2,cls:'hoch'},
    {id:'D2025',name:'Emmenbrücke (Demo)',water:'Kleine Emme',type:'river',lat:47.0726,lon:8.2738,flow:34.2,height:427.35,level:null,temp:8.9,danger:1,cls:'mittel'}];

  // ---------- Zustand (sessionStorage, endet mit dem Tab) ----------
  const SK='swdemo:__state';let S=null;
  try{S=JSON.parse(sg(SK)||'null')}catch(e){S=null}
  const fresh=mode=>{const t=now(),s={v:1,mode,sim:1,lead:1,seq:{inc:900000,log:1,msg:1,poi:1,res:1,ext:1,abs:1},inc:null,last:null,
      me:{id:ME_ID,username:'demo',name:'Alex Demo',photo:null,exercise_mode:0,coord_sys:null,nav_app:null,place_view:{},hydro:['D2152'],ai_modes:[]},abs:[],welcome:0,reply:[]};
    if(mode==='bsp')bspStart(s,t-150);return s};
  const save=()=>ss(SK,JSON.stringify(S));
  function reset(mode){keys().forEach(k=>sr(PFX+k));sr(SK);S=fresh(mode);save();ss(PFX+'sw_token','demo')}

  // ---------- Hilfen ----------
  const hav=(a,b,c,d)=>{const R=6371000,r=x=>x*Math.PI/180,x=r(c-a),y=r(d-b),h=Math.sin(x/2)**2+Math.cos(r(a))*Math.cos(r(c))*Math.sin(y/2)**2;return 2*R*Math.asin(Math.sqrt(h))};
  const off=(p,e,n)=>[p[0]+n/111320,p[1]+e/(111320*Math.cos(p[0]*Math.PI/180))]; // Versatz in Metern Ost/Nord
  const f5=x=>Number(x).toFixed(5),hm=t=>new Date(t*1000).toLocaleTimeString('de-CH',{hour:'2-digit',minute:'2-digit'});
  const fail=(msg,st=400)=>{const e=new Error(msg);e.st=st;throw e};
  const uname=id=>id===ME_ID?S.me.name:(SIM[id]||{}).name||'?';
  const role=id=>ROLES.find(r=>r.id===id);
  const mode=c=>MODES.find(m=>m.code===c);
  const I=()=>S.inc;
  const logev=(uid,kind,text,ts)=>{const i=I();if(i)i.log.push({id:S.seq.log++,ts:ts||now(),kind,text,uid})};
  const part=(id=ME_ID)=>I()&&I().parts.find(p=>p.user_id===id);
  const active=(id=ME_ID)=>{const p=part(id);return!!(p&&p.left_at==null&&p.mode!=='declined')};
  const isLead=()=>{const i=I(),p=part();if(!i||!p)return false;return i.created_by===ME_ID||(p.role_id&&role(p.role_id).is_lead)||!!(mode(p.mode)||{}).lead_rights};
  const canManage=()=>active()&&(isLead()||!!S.lead);
  const needInc=()=>I()||fail('Kein aktiver Einsatz');
  const needLead=m=>{needInc();if(!canManage())fail(m||'Nur die Einsatzleitung kann das',403)};
  const needPart=m=>{needInc();if(!active()&&!canManage())fail(m||'Nur Personen, die den Einsatz angenommen haben, können das',403)};
  const ptsIn=v=>(Array.isArray(v)?v:[]).map(q=>{if(!Array.isArray(q)||q.length!==2||!isFinite(q[0])||!isFinite(q[1]))fail('Ungültige Koordinaten');return[+(+q[0]).toFixed(7),+(+q[1]).toFixed(7)]});
  const kindOf=i=>KINDS.find(k=>k.id===i.kind_id)||KINDS[0];
  const unknownName=()=>{const n=I().res.filter(r=>r.name.startsWith('Unbekannter Rettling')).length;return n?'Unbekannter Rettling '+(n+1):'Unbekannter Rettling'};
  function newInc(o){const t=o.alarm_at||now();return{id:++S.seq.inc,message:o.message,lat:o.lat??null,lon:o.lon??null,pls_at:null,alarm_at:t,ended_at:null,flow_ms:null,created_by:o.by,kind_id:o.kind_id||1,
    record:o.record?1:0,recorded:o.record?1:0,air:o.air==null?1:o.air?1:0,open_join:0,script:o.script,t0:t,done:{},parts:[],log:[],msgs:[],pois:[],res:[],ext:[],marks:[],homes:{}}}
  const addPart=(uid,m,ts)=>{const i=I();let p=part(uid);const prev=p&&{...p};
    if(!p){p={id:i.parts.length+1,incident_id:i.id,user_id:uid,mode:m,role_id:null,lat:null,lon:null,loc_at:null,left_at:null,log_lat:null,log_lon:null,log_at:null,fast_at:null,label:null};i.parts.push(p)}
    p.mode=m;p.left_at=null;const md=mode(m);if(md&&!md.share_location){p.lat=p.lon=p.loc_at=null}if(md&&md.lead_rights)p.role_id=null;
    if(!prev||prev.mode!==m||prev.left_at!=null)logev(uid,'accept',(prev&&prev.left_at!=null?'ist wieder eingestiegen':'hat den Einsatz angenommen')+' ('+md.name+')',ts);return p};
  const setRole=(uid,rid,ts)=>{const p=part(uid);if(!p||p.role_id===rid)return;p.role_id=rid;logev(uid,'role',rid?'übernimmt die Rolle '+role(rid).name:'legt die Spezialrolle ab',ts)};
  const addMsg=(uid,text,o={})=>{const i=I(),p=part(uid);const m={id:S.seq.msg++,from_user:uid,text,created:o.ts||now(),recipients:o.to||null,lat:p&&p.lat!=null?p.lat:null,lon:p&&p.lon!=null?p.lon:null,mark:o.mark?1:0,
    mark_lat:o.mark?o.mark[0]:null,mark_lon:o.mark?o.mark[1]:null,unmarked:null};i.msgs.push(m);return m};
  const addPoi=(uid,kind,name,color,pts,sym,ts)=>{const i=I(),p={id:S.seq.poi++,kind,name,color,pts,sym:sym||null,created_by:uid,created:ts||now(),removed:null};i.pois.push(p);
    let txt;if(kind==='point')txt=`setzt die Markierung «${name}»${sym&&name!==SYM(sym).name?' (Symbol '+SYM(sym).name+')':''} (${f5(pts[0][0])}, ${f5(pts[0][1])})`;
    else if(kind==='line'){let m=0;for(let j=1;j<pts.length;j++)m+=hav(...pts[j-1],...pts[j]);txt=`zeichnet die Linie «${name}» (${pts.length} Punkte, ca. ${(m/1000).toFixed(m<1e4?2:1)} km)`}
    else{const k=111320*Math.cos(pts[0][0]*Math.PI/180);let a=0;for(let j=0;j<pts.length;j++){const u=pts[j],v=pts[(j+1)%pts.length];a+=u[1]*k*v[0]*111320-v[1]*k*u[0]*111320}txt=`markiert den Bereich «${name}» (${pts.length} Punkte, ca. ${(Math.abs(a)/2/1e4).toFixed(1)} ha)`}
    logev(uid,'poi',txt,ts);return p};
  const addRes=(uid,name,descr,ts)=>{const r={id:S.seq.res++,name,descr:descr||'',lat:null,lon:null,loc_at:null,loc_by:null,rescued_at:null,rescued_by:null,created_by:uid,created:ts||now(),pls_lat:null,pls_lon:null,pls_at:null,removed:null};
    I().res.push(r);logev(uid,'rescuee',`erfasst den Rettling «${name}»${r.descr?': '+r.descr:''}`,ts);return r};
  const locRes=(uid,r,ll,own,ts)=>{r.lat=ll[0];r.lon=ll[1];r.loc_at=ts||now();r.loc_by=uid;logev(uid,'resmark',`markiert den Standort von «${r.name}» ${own?'am eigenen Standort':'auf der Karte'} (${f5(ll[0])}, ${f5(ll[1])})`,ts)};
  const rescue=(uid,r,on,ts)=>{if(on===(r.rescued_at!=null))return;r.rescued_at=on?ts||now():null;r.rescued_by=on?uid:null;logev(uid,'rescued',on?`meldet «${r.name}» als gerettet`:`nimmt «gerettet» bei «${r.name}» zurück`,ts)};
  const addExt=(uid,o,ts)=>{const e={id:S.seq.ext++,name:o.name,descr:o.descr||'',role_id:o.role_id||null,abbr:o.abbr||null,color:o.color||null,lat:o.lat,lon:o.lon,created_by:uid,created:ts||now(),moved:null,removed:null,manual:o.manual?1:0};
    I().ext.push(e);logev(uid,'ext',`erfasst die externe Einsatzkraft «${e.name}» (${e.role_id?role(e.role_id).name:'ohne Spezialrolle'}, ${f5(e.lat)}, ${f5(e.lon)})`,ts);return e};

  // ---------- Szenario mit Beispieldaten: Person in der Reuss bei Luzern ----------
  function bspStart(s,t0){const keep=S;S=s;S.inc=newInc({message:'Person beim Nadelwehr in die Reuss gestürzt, treibt flussabwärts Richtung Reusszopf. Einsatz Wasserrettung.',lat:P.pls[0],lon:P.pls[1],alarm_at:t0,by:101,kind_id:1,script:'bsp'});
    logev(101,'start','hat den Einsatz gestartet (Einsatz, mit Helikoptern): '+S.inc.message,t0);simRun(t0+150,true);if(keep&&keep!==s)S=keep}
  // Wegpunkte je Person: [Sekunden nach Alarm, lat, lon]; dazwischen linear (Fahrt bzw. Fussweg)
  const W=(t,p)=>[t,p[0],p[1]];
  const BSP_ROUTE={101:[W(20,P.home[101]),W(240,P.kp)],102:[W(40,P.home[102]),W(200,P.sp),W(230,P.sp),W(330,P.ew),W(360,P.r6),W(430,P.r5),W(500,off(P.find,-15,-12)),W(560,off(P.find,-15,-12)),W(640,P.ew)],
    103:[W(170,P.home[103]),W(320,P.ew),W(360,off(P.r6,6,4)),W(430,off(P.r5,6,4)),W(500,off(P.find,-10,-8)),W(560,off(P.find,-10,-8)),W(640,off(P.ew,5,5))],
    104:[W(200,P.home[104]),W(380,P.r3),W(470,P.r4),W(495,off(P.find,8,10))],105:[W(260,P.home[105]),W(460,P.post)],106:[W(330,P.home[106]),W(520,off(P.kp,25,-15))]};
  const SECTOR=[[47.0559,8.2961],[47.0575,8.2946],[47.0618,8.2873],[47.0612,8.2856],[47.0567,8.2932],[47.0551,8.2962]];
  const BSP=[[20,101,i=>addPart(101,'direct',i)],[25,101,i=>setRole(101,1,i)],[40,102,i=>addPart(102,'base',i)],[45,102,i=>setRole(102,2,i)],
    [60,101,i=>addRes(101,'Unbekannter Rettling','Mann, ca. 40 Jahre, dunkle Jacke, Jeans. Von Passanten beim Nadelwehr gesehen.',i)],
    [75,101,i=>{I().flow_ms=1.8;logev(101,'flow','setzt die Fliessgeschwindigkeit auf 1.8 m/s',i)}],
    [90,101,i=>addPoi(101,'point','Kommandoposten','#d62828',[P.kp],'sw-kp',i)],
    [110,101,i=>addExt(101,{name:'Feuerwehr Luzern',descr:'Beobachtung von der Brücke Reussbühl',role_id:4,abbr:'FW',color:'#e53935',lat:P.fw[0],lon:P.fw[1]},i)],
    [130,101,i=>addMsg(101,'KP bei der Spreuerbrücke. Boote via Stützpunkt, Einwassern beim Reusszopf. Schlitten sucht das linke Ufer ab Geissmattbrücke ab.',{ts:i})],
    [170,103,i=>addPart(103,'direct',i)],[175,103,i=>setRole(103,2,i)],[200,104,i=>addPart(104,'base',i)],[210,104,i=>setRole(104,3,i)],
    [230,102,i=>addMsg(102,'Boot 1 ab Stützpunkt unterwegs zur Einwasserung Reusszopf, ETA 3 min.',{ts:i})],
    [260,105,i=>addPart(105,'direct',i)],[265,105,i=>setRole(105,4,i)],
    [300,101,i=>addPoi(101,'area','Suchsektor Boot 1','#1d7fd1',SECTOR,null,i)],[330,106,i=>addPart(106,'logistics',i)],
    [365,102,i=>addMsg(102,'Boot 1 im Wasser, suchen flussaufwärts Richtung Geissmattbrücke.',{ts:i})],
    [420,103,i=>addMsg(103,'Dunkle Jacke im Ufergebüsch rechts gesichtet, Markierung gesetzt.',{ts:i,mark:P.jacke})],
    [470,105,i=>addMsg(105,'Sicherungsposten Emmenmündung bezogen, Wurfsack bereit.',{ts:i})],
    [490,104,i=>{const r=I().res[0];if(r&&r.lat==null&&!r.removed){locRes(104,r,P.find,true,i);addMsg(104,'Rettling am linken Ufer gesichtet, hält sich an einem Ast fest! Boot 1 bitte kommen.',{ts:i})}}],
    [540,102,i=>{const r=I().res[0];if(r&&!r.removed){rescue(102,r,true,i);addMsg(102,'Person geborgen, ansprechbar, stark unterkühlt. Übergabe an den Rettungsdienst bei der Einwasserung Reusszopf.',{ts:i})}}],
    [560,106,i=>addMsg(106,'Decken und Wärmepacks beim KP bereit.',{ts:i})],
    [620,101,i=>addMsg(101,'Danke allen. Rettling dem Rettungsdienst übergeben. Rückzug zum Stützpunkt, Material kontrollieren.',{ts:i})],
    [1200,101,i=>{if(!S.lead&&I())endInc(101,i)}]];
  // ---------- eigener Einsatz: simulierte Einsatzkräfte nehmen an und fahren zum PLS ----------
  const GEN=[[15,101,i=>addPart(101,'direct',i)],[18,101,i=>setRole(101,1,i)],[25,102,i=>addPart(102,'base',i)],[28,102,i=>setRole(102,2,i)],
    [40,101,i=>addMsg(101,'Bin unterwegs zum PLS und übernehme die Einsatzleitung vor Ort.',{ts:i})],[45,103,i=>addPart(103,'direct',i)],[48,103,i=>setRole(103,2,i)],
    [60,104,i=>addPart(104,'base',i)],[63,104,i=>setRole(104,3,i)],[90,102,i=>addMsg(102,'Boot wird am Stützpunkt vorbereitet, ETA ca. 8 min.',{ts:i})],
    [100,105,i=>addPart(105,'direct',i)],[103,105,i=>setRole(105,4,i)],[130,106,i=>addPart(106,'logistics',i)],
    [220,101,i=>addMsg(101,'Vor Ort beim PLS. Bitte Standort teilen und Suchgebiet gemäss Karte absuchen.',{ts:i})],
    [320,105,i=>addMsg(105,'Posten flussabwärts bezogen.',{ts:i})]];
  // Wegpunkte relativ zum Bezugspunkt (PLS, sonst Standort der Person): Versatz [Ost, Nord] in Metern, Ankunft in s nach dem Start
  const GEN_ROUTE={101:[15,[-2600,1800],[-60,40],220],102:[25,[3100,2400],[120,-90],280],103:[45,[-1800,-2600],[130,-80],300],104:[60,[2200,-3000],[260,180],330],105:[100,[-3500,-900],[-700,-500],330],106:[130,[1500,3300],[-40,70],360]};
  const anchor=i=>i.lat!=null?[i.lat,i.lon]:(i.res.find(r=>r.pls_lat!=null)?[i.res.find(r=>r.pls_lat!=null).pls_lat,i.res.find(r=>r.pls_lat!=null).pls_lon]:(part()&&part().lat!=null?[part().lat,part().lon]:null));
  function route(i,uid){if(i.script==='bsp')return BSP_ROUTE[uid];const g=GEN_ROUTE[uid];if(!g)return null;
    if(!i.homes[uid]){const a=anchor(i);if(!a)return null;i.homes[uid]={from:off(a,...g[1]),to:off(a,...g[2])}}const h=i.homes[uid];return[[g[0],...h.from],[g[3],...h.to]]}
  function posAt(r,t){if(!r||!r.length)return null;if(t<=r[0][0])return[r[0][1],r[0][2]];for(let j=1;j<r.length;j++)if(t<=r[j][0]){const a=r[j-1],b=r[j],f=(t-a[0])/Math.max(1,b[0]-a[0]);return[a[1]+(b[1]-a[1])*f,a[2]+(b[2]-a[2])*f]}
    const l=r[r.length-1];return[l[1],l[2]]}
  // Simulation bis zum Zeitpunkt t: fällige Schritte einmal ausführen (auch rückwirkend, z. B. nach Neuladen), Standorte nachführen
  function simRun(t,init){const i=I();if(!i||i.ended_at)return;if(!S.sim&&!init)return;const steps=i.script==='bsp'?BSP:GEN,rel=t-i.t0;
    for(let k=0;k<steps.length;k++){const[at,uid,fn]=steps[k];if(i.done[k]||rel<at)continue;i.done[k]=1;const p=part(uid);if(p&&p.kicked)continue;fn(i.t0+at);if(!I())return}i.parts.forEach(p=>{if(p.user_id===ME_ID||p.left_at!=null||p.mode==='declined')return;const md=mode(p.mode);if(md&&!md.share_location)return;
      const ll=posAt(route(i,p.user_id),rel);if(!ll)return;const w=Math.sin(t/23+p.user_id)*4,v=Math.cos(t/31+p.user_id)*4;const q=off(ll,w,v);p.lat=+q[0].toFixed(7);p.lon=+q[1].toFixed(7);p.loc_at=t});
    // Antworten auf eigene Nachrichten
    S.reply=(S.reply||[]).filter(r=>{if(r.at>t)return true;if(I()&&active(r.uid))addMsg(r.uid,r.text,{ts:r.at,to:[ME_ID]});return false})}
  function endInc(uid,t){const i=I();if(!i)return;if(i.record)i.record=0;i.ended_at=t||now();logev(uid,'end','hat den Einsatz beendet',t);S.last={id:i.id,ended_at:i.ended_at};S.inc=null}
  // Rega-Helikopter über dem Suchgebiet (Beispieldaten) bzw. über dem Bezugspunkt (eigener Einsatz)
  function heli(t){const i=I();if(!i||!i.air)return[];const rel=t-i.t0,a=i.script==='bsp'?{c:P.find,from:380,to:660}:{c:anchor(i),from:150,to:480};if(!a.c||rel<a.from-60||rel>a.to+80)return[];
    const at=x=>{const r=x-i.t0;let ll,trk,alt;if(r<a.from){const f=(r-a.from+60)/60;ll=off(a.c,6000*(1-f)+600,9000*(1-f));trk=214;alt=2600-1000*f}
      else if(r<=a.to){const w=(r-a.from)/90*2*Math.PI;ll=off(a.c,600*Math.cos(w),600*Math.sin(w));trk=(360-w*180/Math.PI)%360;alt=1600}
      else{const f=(r-a.to)/80;ll=off(a.c,600+9000*f,6000*f);trk=56;alt=1600+1500*f}return[ll,(trk+360)%360,Math.round(alt)]};
    const [ll,trk,alt]=at(t),pl=i.lat!=null?hav(i.lat,i.lon,ll[0],ll[1]):null;
    return[{hex:'4b0d3e',cs:'RGA3',reg:'HB-ZRD',type:'EC45',desc:'Airbus H145 (Demo)',cat:'A7',lat:ll[0],lon:ll[1],alt,gnd:0,gs:rel>=a.from&&rel<=a.to?60:110,trk,vr:0,at:t,rescue:'Rega',pls_m:pl==null?null:Math.round(pl),near:null,
      trail:[60,45,30,15].map(d=>t-d).filter(x=>x-i.t0>=a.from-60).map(x=>{const[q,,h]=at(x);return[x,+q[0].toFixed(6),+q[1].toFixed(6),h]})}]}

  // ---------- Antworten ----------
  const kindPub=k=>({...k});
  const meOut=()=>({...S.me,must_change:0,uroles:[{id:3,name:'Einsatzkraft (Demo)',sys:null,perms:['inc_start','ex_start']}],is_admin:0,perms:['inc_start','ex_start',...(S.lead?['inc_lead']:[])],
    coord_default:'lv95',rd_speeds:[10,20,30],kinds:KINDS.map(kindPub),nav_apps:['google','apple','waze','geo'],nav_default:'google',symbols:SYMS,sym_free:1,sym_sets:{sz:'Taktische Symbole',sw:'Swiftwater'},
    features:{1:FEAT,2:FEAT},my_runs:'off',groups:GROUPS.slice(0,1),absent:absNow(),ai:null,org:'Demo-Modus',session:0,demo:S.mode});
  function absNow(){const t=now();return S.abs.find(a=>!a.removed&&a.from_at<=t&&(a.until_at==null||a.until_at>t))||null}
  const sym=p=>p.sym?{...p,sym_name:(SYM(p.sym)||{}).name||p.sym,sym_foot:(SYM(p.sym)||{}).foot||0}:p;
  function sources(i,res){const ip=i.lat!=null?{lat:i.lat,lon:i.lon,at:i.pls_at||i.alarm_at,own:0}:null;if(!res.length)return ip?[{...ip,names:[]}]:[];const o={};
    res.forEach(r=>{if(r.rescued_at!=null||r.lat!=null)return;const p=r.pls_lat!=null?{lat:r.pls_lat,lon:r.pls_lon,at:r.pls_at,own:1}:ip;if(!p)return;const k=p.lat+','+p.lon+','+p.at;(o[k]=o[k]||{...p,names:[]}).names.push(r.name)});return Object.values(o)}
  function logRows(i){const rows=i.log.filter(l=>l.kind!=='message').map(l=>({id:l.id,ts:l.ts,kind:l.kind,text:l.text,name:l.uid==null?null:uname(l.uid)}));
    i.msgs.forEach(m=>{const to=m.recipients?m.recipients.map(uname).join(', '):'alle';rows.push({id:1e9+m.id,ts:m.created,kind:'message',name:uname(m.from_user),
      text:`Nachricht an ${to}: ${m.text}`+(m.lat!=null?` · Standort ${f5(m.lat)}, ${f5(m.lon)}`:'')+(m.mark_lat!=null?` · Kartenmarkierung ${f5(m.mark_lat)}, ${f5(m.mark_lon)}${m.mark?'':' (entfernt)'}`:'')})});
    return rows.sort((a,b)=>b.ts-a.ts||b.id-a.id).slice(0,1000)}
  function hydroInc(i){if(i.lat==null&&!i.res.some(r=>r.pls_lat!=null))return[];const a=anchor(i)||P.pls,t=i.alarm_at+30;
    return HYDRO.slice(0,i.script==='bsp'?2:1).map(h=>{const d=Math.round(hav(a[0],a[1],h.lat,h.lon));return{phase:'start',ts:t,station:h.id,name:h.name,water:h.water,lat:h.lat,lon:h.lon,dist:i.script==='bsp'?d:null,at:t-300,danger:h.danger,
      v:{flow:h.flow,height:h.height,level:h.level,temp:h.temp,danger:h.danger}}})}
  function state(qs){const t=now();simRun(t);const i=I(),me=S.me;
    if(!i)return{incident:null,air_home:0,my_abs:absNow(),blocked:0,last_ended:S.last,places:PLACES,place_cats:PLACE_CATS,kinds:KINDS,features:{1:FEAT,2:FEAT},ex_plans:[]};
    const K=kindOf(i),since=+qs.get('since')||0,mgr=canManage(),act=active(),res=i.res.filter(r=>!r.removed).map(r=>({...r,loc_name:r.loc_by?uname(r.loc_by):null,rescued_name:r.rescued_by?uname(r.rescued_by):null}));
    res.forEach(r=>delete r.removed);
    const inc={id:i.id,message:i.message,lat:i.lat,lon:i.lon,alarm_at:i.alarm_at,ended_at:null,flow_ms:i.flow_ms,created_by:i.created_by,is_exercise:K.plans?1:0,record:i.record,recorded:i.recorded,public_anon:1,
      pls_at:i.pls_at||i.alarm_at,plan_id:null,public_log:1,rp_anon:null,log_anon:0,air:i.air,view_anon:0,txt_anon:0,part_anon:0,kind_id:K.id,open_join:i.open_join,kind_name:K.name,kind_color:K.color,kind:kindPub(K),public:0,aufgebot:null};
    const lvl=K.mode_up&&me.exercise_mode?'crit':K.alarm,canRead=act||mgr;
    return{incident:inc,pois:i.pois.filter(p=>!p.removed).map(p=>sym({id:p.id,kind:p.kind,name:p.name,color:p.color,pts:p.pts,sym:p.sym,created_by:p.created_by,created:p.created,by_name:uname(p.created_by)})),
      rescuees:res,externals:i.ext.filter(e=>!e.removed).map(({removed,manual,...e})=>({...e,by_name:uname(e.created_by)})),places:PLACES,place_cats:PLACE_CATS,
      ext_templates:mgr?[{id:1,name:'Seepolizei Luzern',descr:'Polizeiboot',role_id:null,abbr:'POL',color:'#3949ab',lat:null,lon:null},{id:2,name:'Feuerwehr – Beobachtungsposten',descr:'',role_id:4,abbr:'FW',color:'#e53935',lat:null,lon:null}]:[],
      ext_inc_templates:mgr?[...new Map(i.ext.filter(e=>e.manual).reverse().map(e=>[e.name.toLowerCase(),{name:e.name,descr:e.descr,role_id:e.role_id,abbr:e.abbr,color:e.color}])).values()]:[],
      sources:sources(i,res),participants:i.parts.map(p=>({user_id:p.user_id,mode:p.mode,role_id:p.role_id,lat:p.lat,lon:p.lon,loc_at:p.loc_at,left_at:p.left_at,name:uname(p.user_id),photo:p.user_id===ME_ID?me.photo:null,groups:p.user_id===ME_ID?[1]:(SIM[p.user_id]||{}).g||[]})),
      groups:GROUPS,marks:[],messages:canRead?i.msgs.filter(m=>m.id>since).map(m=>({id:m.id,from_user:m.from_user,text:m.text,created:m.created,recipients:m.recipients,lat:m.lat,lon:m.lon,mark_lat:m.mark_lat,mark_lon:m.mark_lon,from_name:uname(m.from_user)})):[],
      msg_marks:canRead?i.msgs.filter(m=>m.mark).map(m=>({id:m.id,from_user:m.from_user,text:m.text,created:m.created,lat:m.mark_lat,lon:m.mark_lon,from_name:uname(m.from_user)})):[],
      me:part()?{...part()}:null,is_lead:isLead(),can_manage:mgr,ai:null,can_flow:act||mgr,can_message:act||mgr,can_read_messages:canRead,critical:lvl==='crit',level:lvl,msg_level:K.msg_lvl,end_level:K.end_lvl,
      alarm:i.created_by!==ME_ID,absent:0,my_abs:absNow(),features:FEAT,air:i.air?1:0,air_inc:i.air?1:0,ex_lead:false,ex_title:null,ex_injects:[],log:act||mgr?logRows(i):null,hydro:hydroInc(i),now:t}}
  // Overpass: wie der Server ohne Antwort (502 mit fertiger Abfrage), das Gerät fragt dann direkt (overpassNet in common.js); Ergebnisse bleiben bis zum Neuladen im Speicher
  const OPC=new Map(),opKey=b=>JSON.stringify(b.around||b.bbox||null);
  function overpassQ(b){const f=x=>(+x).toFixed(5);let sel;if(b.around){const[r,la,lo]=b.around.map(Number);if(!(r>=100&&r<=30000))fail('Ungültiger Umkreis');sel=`around:${Math.round(r)},${f(la)},${f(lo)}`}
    else if(b.bbox)sel=b.bbox.map(f).join(',');else fail('Umkreis oder Ausschnitt fehlt');return`[out:json][timeout:25];way(${sel})["waterway"~"^(river|stream|canal)$"];out geom;`}
  const R={
    'login':()=>({token:'demo'}),'logout':()=>{setTimeout(exit,50);return{ok:true}},
    'me':()=>({me:meOut()}),
    'me/settings':b=>{['exercise_mode','coord_sys','nav_app','place_view','hydro','ai_modes'].forEach(k=>{if(k in b)S.me[k]=k==='coord_sys'||k==='nav_app'?(b[k]||null):b[k]});return{ok:true}},
    'me/password':()=>fail('Im Demo-Modus gibt es kein Passwort.'),'me/photo':b=>{S.me.photo=b.photo||null;return{ok:true}},'me/sessions':()=>[],'me/session/remove':()=>({ok:true}),
    'roles':()=>ROLES,'modes':()=>MODES,
    'incident/state':(b,qs)=>state(qs),
    'start/options':()=>({groups:[],kinds:KINDS,start:{1:null,2:null},add:{1:null,2:null},people:[],kind_people:{1:null,2:null},kind_absent:{1:0,2:0}}),
    'incident/start':b=>{if(I())fail('Es läuft bereits ein Einsatz');if(!String(b.message||'').trim())fail('Die Meldung ist Pflicht');const K=KINDS.find(k=>k.id===+b.kind_id)||KINDS[0],t=now();
      const at=b.alarm_at?Math.min(+b.alarm_at,t):t,pls=b.lat!=null&&b.lon!=null&&b.lat!==''&&isFinite(b.lat)&&isFinite(b.lon);
      S.inc=newInc({message:String(b.message).trim(),lat:pls?+b.lat:null,lon:pls?+b.lon:null,alarm_at:at,by:ME_ID,kind_id:K.id,record:b.record&&FEAT.rec,air:b.air==null?K.air:b.air,script:'gen'});S.inc.t0=t;
      logev(ME_ID,'start',`hat den Einsatz gestartet (${K.name}${S.inc.record?', mit Aufzeichnung':''}${S.inc.air?', mit Helikoptern':''}${pls?'':', ohne PLS'}): ${S.inc.message}`);return{id:S.inc.id}},
    'incident/join':b=>{needInc();if(b.mode==='declined'){const p=part();if(!p){I().parts.push({id:I().parts.length+1,incident_id:I().id,user_id:ME_ID,mode:'declined',role_id:null,lat:null,lon:null,loc_at:null,left_at:null});logev(ME_ID,'decline','hat den Einsatz abgelehnt')}return{ok:true}}
      const m=mode(String(b.mode||''));if(!m||!m.active)fail('Ungültige Auswahl');addPart(ME_ID,m.code);return{ok:true}},
    'incident/leave':()=>{needInc();const p=part();if(p&&p.left_at==null&&p.mode!=='declined'){p.left_at=now();p.role_id=null;logev(ME_ID,'leave','hat den Einsatz verlassen')}return{ok:true}},
    'incident/role':b=>{needInc();const p=part()||fail('Nicht im Einsatz');if(mode(p.mode).lead_rights)fail('Mit dieser Einrückoption gibt es keine Spezialrolle');setRole(ME_ID,b.role_id?+b.role_id:null);return{ok:true}},
    'incident/role/set':b=>{needLead('Nur die Einsatzleitung kann Spezialrollen anderer Personen ändern');const u=+b.user_id,p=part(u);if(!p||p.left_at!=null)fail('Die Person ist nicht im Einsatz');
      if(mode(p.mode).lead_rights)fail('Mit dieser Einrückoption gibt es keine Spezialrolle');const rid=b.role_id?+b.role_id:null;if(rid!==p.role_id){p.role_id=rid;logev(ME_ID,'role',rid?`teilt ${uname(u)} die Rolle ${role(rid).name} zu`:`entfernt die Spezialrolle von ${uname(u)}`)}return{ok:true}},
    'incident/location':b=>{needInc();const p=part();if(!p||p.left_at!=null||p.mode==='declined'||!mode(p.mode).share_location)return{ok:true};p.lat=+b.lat;p.lon=+b.lon;p.loc_at=now();return{ok:true}},
    'incident/event':b=>{needInc();if(b.kind==='area'&&!I().log.some(l=>l.uid===ME_ID&&l.kind==='area'))logev(ME_ID,'area','hat das Suchgebiet erreicht');return{ok:true}},
    'incident/flow':b=>{needPart('Nur Personen, die am Einsatz teilnehmen, können die Fliessgeschwindigkeit setzen');const v=+b.flow_ms;if(!(v>0&&v<=15))fail('Fliessgeschwindigkeit muss zwischen 0 und 15 m/s liegen');
      I().flow_ms=v;logev(ME_ID,'flow','setzt die Fliessgeschwindigkeit auf '+v+' m/s');return{ok:true}},
    'incident/mark/remove':()=>({ok:true}),
    'incident/poi':b=>{needLead('Nur die Einsatzleitung kann Markierungen und Bereiche setzen');const k=['area','line'].includes(b.kind)?b.kind:'point',pts=ptsIn(b.pts);let s=null;
      if(k==='point'){s=String(b.sym||'').trim()||null;if(s&&!SYM(s))fail('Dieses Symbol steht nicht zur Wahl')}let n=String(b.name||'').trim();if(!n&&s)n=SYM(s).name;if(!n)fail('Bezeichnung fehlt');
      if(k==='point'?pts.length!==1:pts.length<(k==='line'?2:3)||pts.length>200)fail({point:'Für eine Markierung ist genau ein Punkt nötig',line:'Eine Linie braucht 2 bis 200 Punkte',area:'Ein Bereich braucht 3 bis 200 Punkte'}[k]);
      return{ok:true,id:addPoi(ME_ID,k,n.slice(0,80),/^#[0-9a-f]{6}$/i.test(b.color||'')?b.color:'#ff9f1c',pts,s).id}},
    'incident/poi/remove':b=>{needLead('Nur die Einsatzleitung kann Markierungen und Bereiche entfernen');const p=I().pois.find(x=>x.id===+b.id&&!x.removed)||fail('Markierung nicht gefunden',404);p.removed=now();
      logev(ME_ID,'poidel',({area:'entfernt den Bereich «',line:'entfernt die Linie «'}[p.kind]||'entfernt die Markierung «')+p.name+'»');return{ok:true}},
    'incident/rescuee/save':b=>{needPart('Nur Personen, die den Einsatz angenommen haben, können Rettlinge erfassen');const i=I(),r=b.id?i.res.find(x=>x.id===+b.id&&!x.removed)||fail('Rettling nicht gefunden',404):null;
      let n=String(b.name||'').trim().slice(0,80);const d=String(b.descr||'').trim().slice(0,1000);if(!n)n=r&&r.name.startsWith('Unbekannter Rettling')?r.name:unknownName();
      const has='pls' in b,pl=has&&b.pls?[+b.pls.lat,+b.pls.lon,b.pls.at?+b.pls.at:(i.pls_at||i.alarm_at)]:[null,null,null];
      const plsTxt=x=>pl[0]==null?`übernimmt für «${x}» den PLS des Einsatzes`:`setzt den PLS von «${x}» (${f5(pl[0])}, ${f5(pl[1])}, ${hm(pl[2])})`;
      if(r){if(r.name!==n||r.descr!==d)logev(ME_ID,'rescuee',(r.name!==n?`ändert den Rettling «${r.name}» zu «${n}»`:`ändert die Beschreibung von «${n}»`)+(d?': '+d:''));r.name=n;r.descr=d;
        if(has&&(r.pls_lat!==pl[0]||r.pls_lon!==pl[1]||r.pls_at!==pl[2])){[r.pls_lat,r.pls_lon,r.pls_at]=pl;logev(ME_ID,'rescuee',plsTxt(n))}return{ok:true,id:r.id}}
      const x=addRes(ME_ID,n,d);[x.pls_lat,x.pls_lon,x.pls_at]=pl;if(pl[0]!=null)logev(ME_ID,'rescuee',plsTxt(n));return{ok:true,id:x.id}},
    'incident/rescuee/locate':b=>{needPart('Nur Personen, die den Einsatz angenommen haben, können den Standort markieren');const r=I().res.find(x=>x.id===+b.id&&!x.removed)||fail('Rettling nicht gefunden',404);
      if(b.clear){if(r.lat!=null){r.lat=r.lon=r.loc_at=r.loc_by=null;logev(ME_ID,'resmark',`setzt den Fundort von «${r.name}» zurück`)}return{ok:true}}
      let ll;if(b.own){const p=part();if(!p||p.lat==null)fail('Dein Standort ist nicht bekannt: Standortfreigabe erlauben oder Position auf der Karte wählen');ll=[p.lat,p.lon]}
      else{if(!isFinite(b.lat)||!isFinite(b.lon))fail('Ungültige Koordinaten');ll=[+(+b.lat).toFixed(7),+(+b.lon).toFixed(7)]}locRes(ME_ID,r,ll,!!b.own);return{ok:true}},
    'incident/rescuee/rescued':b=>{needPart('Nur Personen, die den Einsatz angenommen haben, können Rettlinge als gerettet markieren');const r=I().res.find(x=>x.id===+b.id&&!x.removed)||fail('Rettling nicht gefunden',404);rescue(ME_ID,r,!!b.rescued);return{ok:true}},
    'incident/rescuee/remove':b=>{needLead('Nur die Einsatzleitung kann Rettlinge entfernen');const r=I().res.find(x=>x.id===+b.id&&!x.removed)||fail('Rettling nicht gefunden',404);r.removed=now();logev(ME_ID,'rescuee',`entfernt den Rettling «${r.name}»`);return{ok:true}},
    'incident/external/save':b=>{needLead('Nur die Einsatzleitung kann externe Einsatzkräfte erfassen');const n=String(b.name||'').trim().slice(0,80);if(!n)fail('Name fehlt');
      const st={abbr:String(b.abbr||'').trim().slice(0,4)||null,color:/^#[0-9a-f]{6}$/i.test(b.color||'')?b.color:null},rid=b.role_id?+b.role_id:null,ll=b.lat!=null&&b.lon!=null?[+(+b.lat).toFixed(7),+(+b.lon).toFixed(7)]:null;
      if(b.id){const e=I().ext.find(x=>x.id===+b.id&&!x.removed)||fail('Externe Einsatzkraft nicht gefunden',404);if(e.name!==n||e.descr!==String(b.descr||'')||e.role_id!==rid)logev(ME_ID,'ext',`ändert die externe Einsatzkraft «${e.name}»`+(e.name!==n?` zu «${n}»`:''));
        Object.assign(e,{name:n,descr:String(b.descr||''),role_id:rid,...st});if(ll&&(ll[0]!==e.lat||ll[1]!==e.lon)){e.lat=ll[0];e.lon=ll[1];e.moved=now();logev(ME_ID,'ext',`verschiebt die externe Einsatzkraft «${n}» (${f5(ll[0])}, ${f5(ll[1])})`)}return{ok:true,id:e.id}}
      if(!ll)fail('Standort fehlt');return{ok:true,id:addExt(ME_ID,{name:n,descr:String(b.descr||''),role_id:rid,...st,lat:ll[0],lon:ll[1],manual:b.manual}).id}},
    'incident/external/remove':b=>{needLead('Nur die Einsatzleitung kann externe Einsatzkräfte entfernen');const e=I().ext.find(x=>x.id===+b.id&&!x.removed)||fail('Externe Einsatzkraft nicht gefunden',404);e.removed=now();logev(ME_ID,'ext',`entfernt die externe Einsatzkraft «${e.name}»`);return{ok:true}},
    'incident/kick':b=>{needLead('Nur die Einsatzleitung kann Einsatzkräfte entfernen');const u=+b.user_id;if(u===ME_ID)fail('Zum Verlassen den Knopf «Einsatz verlassen» verwenden');const p=part(u);if(!p||p.left_at!=null)fail('Die Person ist nicht im Einsatz');
      p.left_at=now();p.role_id=null;p.kicked=1;logev(ME_ID,'kick',`hat ${uname(u)} aus dem Einsatz entfernt`);return{ok:true}},
    'incident/message':b=>{needInc();if(!active())fail('Nachrichten können nur Personen senden, die den Einsatz angenommen haben');const tx=String(b.text||'').trim();if(!tx)fail('Text fehlt');
      const act=I().parts.filter(p=>p.left_at==null&&p.mode!=='declined').map(p=>p.user_id);let to=null;if(b.to&&b.to.length){to=b.to.map(Number).filter(x=>act.includes(x));if(!to.length)fail('Die gewählten Personen haben den Einsatz nicht angenommen')}
      let mk=null;if(b.mark){mk=isFinite(b.mark_lat)&&isFinite(b.mark_lon)&&b.mark_lat!=null?[+b.mark_lat,+b.mark_lon]:(part().lat!=null?[part().lat,part().lon]:null);if(!mk)fail('Für die Markierung ist ein Standort nötig: Position auf der Karte wählen')}
      addMsg(ME_ID,tx,{to,mark:mk});if(S.sim){const r=(to||act).find(u=>u!==ME_ID&&SIM[u]&&active(u))||(to?null:act.find(u=>SIM[u]));if(r)S.reply.push({uid:r,at:now()+6,text:/\?\s*$/.test(tx)?'Kläre ich ab und melde mich.':'Verstanden.'})}return{ok:true}},
    'incident/message/unmark':b=>{needInc();const m=I().msgs.find(x=>x.id===+b.id)||fail('Nachricht nicht gefunden',404);if(m.from_user!==ME_ID&&!canManage())fail('Nur die sendende Person oder die Einsatzleitung kann die Markierung entfernen',403);
      if(m.mark){m.mark=0;m.unmarked=now();logev(ME_ID,'markdel','entfernt die Kartenmarkierung einer Nachricht')}return{ok:true}},
    'incident/pls':b=>{needLead('Nur die Einsatzleitung kann den PLS des Einsatzes ändern');const i=I();if(b.clear){i.lat=i.lon=i.pls_at=null;logev(ME_ID,'pls','entfernt den PLS des Einsatzes');return{ok:true}}
      if(!isFinite(b.lat)||!isFinite(b.lon))fail('Ungültige Koordinaten');const had=i.lat!=null,at=b.at?+b.at:i.alarm_at;i.lat=+b.lat;i.lon=+b.lon;i.pls_at=at===i.alarm_at?null:at;
      logev(ME_ID,'pls',`${had?'ändert':'setzt'} den PLS des Einsatzes (${f5(i.lat)}, ${f5(i.lon)}, ${hm(at)})`);return{ok:true}},
    'incident/record':b=>{needLead('Nur die Einsatzleitung kann die Aufzeichnung steuern');const i=I(),T={start:[[0],1,i.recorded?'nimmt die Aufzeichnung wieder auf':'startet die Aufzeichnung'],pause:[[1],2,'pausiert die Aufzeichnung'],resume:[[2],1,'setzt die Aufzeichnung fort'],stop:[[1,2],0,'beendet die Aufzeichnung']}[b.action];
      if(!T)fail('Unbekannte Aktion');if(!T[0].includes(i.record))return{ok:true,record:i.record};i.record=T[1];if(T[1])i.recorded=1;logev(ME_ID,'rec',T[2]);return{ok:true,record:i.record}},
    'incident/public':()=>fail('Die öffentliche Ansicht gibt es im Demo-Modus nicht.'),
    'incident/open':b=>{needLead();I().open_join=b.on?1:0;return{ok:true}},
    'incident/invite/add':()=>fail('Im Demo-Modus sind bereits alle Personen aufgeboten.'),
    'incident/air/set':b=>{needLead();const on=b.on?1:0;if(on!==I().air){I().air=on;logev(ME_ID,'air',on?'schaltet die Helikopter ein':'schaltet die Helikopter aus')}return{ok:true}},
    'incident/air':()=>{const i=needInc(),t=now();return{air:heli(t),at:t,src:{name:'Demo (simuliert)',site:''},err:null,radius_km:15,now:t}},
    'air/home':()=>fail('Helikopter ohne Einsatz gibt es im Demo-Modus nicht.',403),
    'incident/end':()=>{needLead('Nur die Einsatzleitung kann den Einsatz beenden');endInc(ME_ID);return{ok:true}},
    'ex/start':()=>fail('Vorbereitete Übungen gibt es im Demo-Modus nicht.'),'ex/release':()=>fail('Vorbereitete Übungen gibt es im Demo-Modus nicht.'),
    'absences':()=>{const t=now();return S.abs.filter(a=>!a.removed&&(a.until_at==null||a.until_at>t)).map(a=>({...a,name:S.me.name,by_name:S.me.name,now:a.from_at<=t?1:0}))},
    'absence/save':b=>{const t=now(),from=b.from?Math.max(+b.from,t-60):t,until=b.until!=null&&b.until!==''?+b.until:null;if(until!=null&&until<=Math.max(from,t))fail('«Bis» muss nach «Von» und in der Zukunft liegen');
      const k=['abs','sus'].includes(b.kind)?b.kind:'abs',nt=String(b.note||'').trim().slice(0,200)||null;if(b.id){const a=S.abs.find(x=>x.id===+b.id&&!x.removed)||fail('Eintrag nicht gefunden',404);Object.assign(a,{from_at:from,until_at:until,kind:k,note:nt});return{ok:true,id:a.id}}
      const a={id:S.seq.abs++,user_id:ME_ID,from_at:from,until_at:until,kind:k,note:nt,created_by:ME_ID,created_at:t,removed:0};S.abs.push(a);return{ok:true,id:a.id}},
    'absence/remove':b=>{const a=S.abs.find(x=>x.id===+b.id&&!x.removed)||fail('Eintrag nicht gefunden',404);a.removed=1;return{ok:true}},
    'hydro':b=>{const ids=b.ids?b.ids.map(String):HYDRO.map(h=>h.id),t=now();return{stations:HYDRO.filter(h=>ids.includes(h.id)).map(h=>({...h,at:t-240})),at:t-240,err:null}},
    'overpass':b=>{const k=opKey(b);if(OPC.has(k))return OPC.get(k);const e=new Error('Gewässernetz vom Gerät laden');e.st=502;e.data={error:e.message,query:overpassQ(b),servers:OVERPASS};throw e},
    'overpass/put':b=>{if(Array.isArray(b.elements))OPC.set(opKey(b),{elements:b.elements});return{ok:true}},'overpass/stat':()=>({ok:true}),
    'push/key':()=>fail('Im Demo-Modus gibt es keine Push-Benachrichtigungen.'),'push/subscribe':()=>({ok:true}),'push/unsubscribe':()=>({ok:true}),
    'ai/ask':()=>fail('Den AI-Assistenten gibt es im Demo-Modus nicht.'),'applog/client':()=>({ok:true}),'legal':()=>fail('Nicht im Demo-Modus')};
  function handle(r,b,qs){const fn=R[r];if(!fn)fail('Im Demo-Modus nicht verfügbar',404);const o=fn(b||{},qs);return o}
  // ---------- fetch abfangen: api/index.php nie ans Netz ----------
  const realFetch=window.fetch.bind(window);
  window.fetch=async function(input,init){let u;try{u=new URL(typeof input==='string'?input:input.url,location.href)}catch(e){return realFetch(input,init)}
    if(u.origin!==location.origin||!/\/api\/index\.php$/.test(u.pathname))return realFetch(input,init);
    let b={};try{b=init&&init.body?JSON.parse(init.body):{}}catch(e){b={}}const r=u.searchParams.get('r')||'';let st=200,o;
    try{o=handle(r,b,u.searchParams)}catch(e){st=e.st||500;o=e.data||{error:e.st?e.message:'Fehler im Demo-Modus: '+e.message};if(!e.st)console.error(e)}
    save();await new Promise(res=>setTimeout(res,40+Math.random()*80)); // etwas Verzögerung wie übers Netz
    return new Response(JSON.stringify(o),{status:st,headers:{'Content-Type':'application/json'}})};
  // ---------- Hinweis und Steuerung ----------
  function exit(){location.href=location.pathname.replace(/[^/]*$/,'')+'index.html'}
  function restart(m){reset(m);location.href=location.pathname+'?demo'+(m==='leer'?'=leer':'')}
  const H=s=>String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  function panel(first){document.querySelectorAll('.demoDlg').forEach(x=>x.remove());const d=document.createElement('div');d.className='dlg demoDlg';d.setAttribute('role','dialog');d.setAttribute('aria-modal','true');d.setAttribute('aria-label','Demo-Modus');
    d.innerHTML=`<div class="card"><h3>🎓 Demo-Modus</h3><p class="mut">Hier kannst du die Einsatz-App gefahrlos ausprobieren. Alles läuft nur in diesem Browser-Tab: nichts geht an den Server, niemand wird alarmiert, und deine echten Einsätze und Einstellungen bleiben unberührt. Schliesst du den Tab, ist der Demo-Stand weg.</p>
      <p class="mut">${S.mode==='bsp'?'<b>Beispieldaten:</b> Ein Einsatz an der Reuss in Luzern läuft bereits (fiktiv). Nimm ihn über das Menü ☰ an und beobachte, wie die simulierten Einsatzkräfte anfahren, Nachrichten senden und den Rettling finden.':'<b>Leer:</b> Es läuft kein Einsatz. Starte über ☰ einen eigenen und setze einen PLS an einem Fluss; simulierte Einsatzkräfte nehmen an und fahren hin.'}</p>
      <label class="row" style="align-items:center;margin:.4rem 0"><input type="checkbox" id="dmSim" ${S.sim?'checked':''}><span>Andere Einsatzkräfte simulieren (Annahmen, Standorte, Nachrichten)</span></label>
      <label class="row" style="align-items:center;margin:.4rem 0"><input type="checkbox" id="dmLead" ${S.lead?'checked':''}><span>Nach dem Annehmen mit Rechten der Einsatzleitung (alle Funktionen). Ohne Häkchen wie eine gewöhnliche Einsatzkraft.</span></label>
      <div class="row" style="flex-wrap:wrap;gap:.4rem;margin-top:.6rem"><button id="dmOk" class="pri">${first?'Los geht’s':'Schliessen'}</button><button id="dmBsp">Neu mit Beispieldaten</button><button id="dmLeer">Neu ohne Daten</button><button id="dmX">Demo beenden</button></div></div>`;
    document.body.appendChild(d);const c=()=>{d.remove();S.welcome=1;save()};
    d.querySelector('#dmOk').onclick=c;d.onclick=e=>{if(e.target===d)c()};d.onkeydown=e=>{if(e.key==='Escape')c()};
    d.querySelector('#dmSim').onchange=e=>{S.sim=e.target.checked?1:0;save()};
    d.querySelector('#dmLead').onchange=e=>{S.lead=e.target.checked?1:0;save();if(typeof me!=='undefined'&&me)me.perms=meOut().perms;if(typeof poll==='function')poll()};
    d.querySelector('#dmBsp').onclick=()=>restart('bsp');d.querySelector('#dmLeer').onclick=()=>restart('leer');d.querySelector('#dmX').onclick=exit;d.querySelector('#dmOk').focus()}
  function badge(){if(document.getElementById('demoBadge'))return;const b=document.createElement('button');b.id='demoBadge';b.type='button';b.textContent='DEMO';b.title='Demo-Modus: Einstellungen, neu starten oder beenden';
    b.setAttribute('aria-label','Demo-Modus: Einstellungen, neu starten oder beenden');b.onclick=()=>panel(false);document.body.appendChild(b);document.body.classList.add('demo')}
  // Zustand erst hier anlegen (Szenario braucht die Funktionen oben): neu bei fehlendem Stand oder anderer Wahl im Aufruf (?demo=leer)
  if(!S||S.v!==1||(q.get('demo')&&S.mode!==want))reset(want);
  if(!sg(PFX+'sw_token'))ss(PFX+'sw_token','demo');
  document.addEventListener('DOMContentLoaded',()=>{badge();if(!S.welcome)setTimeout(()=>panel(true),300);document.title='Demo · '+document.title});
  return{mode:()=>S.mode,panel,exit,restart};
})();
