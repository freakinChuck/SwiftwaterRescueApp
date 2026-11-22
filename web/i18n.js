/* Mehrsprachigkeit (Issue #66): Deutsch, Englisch, Französisch, Italienisch.
   Übersetzt wird beim Anzeigen, nicht im Code: Ein MutationObserver ersetzt deutsche Textknoten und die Attribute title, placeholder,
   aria-label und alt (bei Knöpfen auch value) gemäss Wörterbuch i18n/<Sprache>.json. Schlüssel = deutscher Text (Leerraum zusammengefasst);
   Einträge mit {0}, {1} … sind Vorlagen für veränderliche Teile, die Teile selbst werden übersetzt, wenn es sie als Eintrag gibt.
   Erzeugt bzw. geprüft mit scripts/i18n/extract.py. Wird als erstes Skript im <head> geladen (vor demo.js, das localStorage umleitet).
   Nicht übersetzt: Elemente mit translate="no" bzw. Klasse notranslate (Eingaben der Personen), script/style/textarea, Eingabefelder.
   Sprache: eigene Wahl (sw_lang, auf dem Server users.lang), sonst die des Geräts, sonst die Vorgabe der Organisation (sw_langdef), sonst Deutsch.
   Der Server erfährt die angezeigte Sprache über den Header X-Lang (api() in common.js) und sendet Push in dieser Sprache. */
(function(){
const LANGS={de:'Deutsch',en:'English',fr:'Français',it:'Italiano'},LOCS={de:'de-CH',en:'en-GB',fr:'fr-CH',it:'it-CH'};
let ls=null;try{ls=window.localStorage;ls.getItem('x')}catch(e){ls=null}
const get=k=>{try{return ls?ls.getItem(k):null}catch(e){return null}},put=(k,v)=>{try{if(!ls)return;if(v==null)ls.removeItem(k);else ls.setItem(k,v)}catch(e){}};
const ok=l=>Object.prototype.hasOwnProperty.call(LANGS,l);
function devLang(){const ns=(navigator.languages&&navigator.languages.length?navigator.languages:[navigator.language||'']);
  for(const n of ns){const l=String(n||'').slice(0,2).toLowerCase();if(ok(l))return l}return null}
function pick(){let q=null;try{q=new URLSearchParams(location.search).get('lang')}catch(e){}
  if(q&&ok(q))return q;const s=get('sw_lang');if(s&&ok(s))return s;
  const d=get('sw_langdef');return devLang()||(d&&ok(d)?d:'de')}
const L=pick();
let VER='0';try{const s=document.currentScript;VER=(s&&new URL(s.src).searchParams.get('v'))||'0'}catch(e){}
window.LANG=L;window.LOC=LOCS[L];window.LANGS=LANGS;
try{document.documentElement.lang=L}catch(e){}

/* ---------- Wörterbuch ---------- */
let D=new Map(),TP=[],C=new Map(),ready=L==='de';
const norm=s=>s.replace(/[\s ]+/g,' ').trim();
const RX=s=>s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
function setDict(o){D=new Map();TP=[];C=new Map();
  for(const k in o){const v=o[k];if(typeof v!=='string'||!v)continue;
    if(/\{\d+\}/.test(k)){const lit=k.split(/\{\d+\}/),idx=[...k.matchAll(/\{(\d+)\}/g)].map(m=>+m[1]);
      const long=lit.reduce((a,b)=>b.length>a.length?b:a,'');if(long.replace(/[^A-Za-zÀ-ÿ]/g,'').length<3)continue;
      TP.push({re:new RegExp('^'+lit.map(RX).join('(.*?)')+'$','s'),idx,out:v,long,n:lit.join('').length})}
    else D.set(k,v)}
  TP.sort((a,b)=>b.n-a.n);ready=true}
function sub(v){const k=norm(v);if(!D.has(k))return v;return v.match(/^\s*/)[0]+D.get(k)+v.match(/\s*$/)[0]} // Leerraum um den Teil behalten
/* tr: übersetzt einen ganzen Text (ohne führenden/folgenden Leerraum), null = unverändert */
function tr(s){if(!s||L==='de'||!ready)return null;
  if(/\n\s*\n/.test(s)){let any=false;const o=s.split(/\n\s*\n/).map(p=>{const r=tr(p);if(r!=null){any=true;return r}return norm(p)});return any?o.join('\n\n'):null}
  const k=norm(s);if(k.length<2||!/[A-Za-zÄÖÜäöü]/.test(k))return null;
  if(C.has(k))return C.get(k);let r=D.has(k)?D.get(k):null;
  /* Vorlagen: unter gleich langen Treffern die, deren Teile bekannt sind (Wörterbuch) oder keine Buchstaben enthalten («{0} starten» vor «Einsatz {0}») */
  if(r===null){let best=null,bs=-1,bn=-1;for(const t of TP){if(best&&t.n<bn)break;if(k.length<t.n||!k.includes(t.long))continue;const m=t.re.exec(k);if(!m)continue;
      const cap={};let sc=0;t.idx.forEach((n,i)=>{cap[n]=m[i+1];const v=norm(m[i+1]);if(D.has(v)||!/[A-Za-zÄÖÜäöü]{2}/.test(v))sc++});
      if(sc>bs){best=[t,cap];bs=sc;bn=t.n}}
    if(best){const [t,cap]=best;r=t.out.replace(/\{(\d+)\}/g,(x,n)=>n in cap?sub(cap[n]):x)}}
  if(r===null&&/[:：]$/.test(k)&&k.length>2){const b=tr(k.slice(0,-1));if(b!=null)r=b+(L==='fr'?' :':':')} // Beschriftung mit Doppelpunkt
  if(C.size>20000)C.clear();C.set(k,r);return r}
/* T: für Texte ausserhalb des DOM (Benachrichtigungen, Downloads): Absätze einzeln */
function T(s,...a){let x=String(s??'');if(L!=='de'&&ready){x=x.split(/(\n\s*\n)/).map((p,i)=>i%2?p:(()=>{const lead=p.match(/^\s*/)[0],tail=p.match(/\s*$/)[0];const r=tr(p);return r==null?p:lead+r+tail})()).join('')}
  return a.length?x.replace(/\{(\d+)\}/g,(m,n)=>a[n]!==undefined?a[n]:m):x}
window.T=T;

/* ---------- DOM ---------- */
const SKIP={SCRIPT:1,STYLE:1,TEXTAREA:1,NOSCRIPT:1,CODE:1,PRE:1};
const ATTR=['title','placeholder','aria-label','alt'];
let done=new WeakMap(),adone=new WeakMap();
function skipEl(e){return SKIP[e.nodeName]||e.isContentEditable||e.getAttribute&&(e.getAttribute('translate')==='no'||e.classList&&e.classList.contains('notranslate'))}
function inSkip(n){for(let e=n.parentNode;e&&e.nodeType===1;e=e.parentNode)if(skipEl(e))return true;return false}
function txt(n){const v=n.data;if(done.get(n)===v)return;const r=tr(v);
  if(r!=null){const lead=v.match(/^\s*/)[0],tail=v.match(/\s*$/)[0],nv=lead+r+tail;done.set(n,nv);if(nv!==v)n.data=nv}else done.set(n,v)}
function attrs(e){let m=adone.get(e);for(const a of ATTR){const v=e.getAttribute(a);if(!v||m&&m[a]===v)continue;const r=tr(v);if(!m){m={};adone.set(e,m)}
    m[a]=r!=null?r:v;if(r!=null&&r!==v)e.setAttribute(a,r)}
  if(e.nodeName==='INPUT'&&/^(button|submit|reset)$/i.test(e.type)){const v=e.value;if(v&&!(m&&m.value===v)){const r=tr(v);if(!m){m={};adone.set(e,m)}m.value=r!=null?r:v;if(r!=null)e.value=r}}}
function walk(root){if(!root)return;if(root.nodeType===3){if(!inSkip(root))txt(root);return}
  if(root.nodeType!==1&&root.nodeType!==9&&root.nodeType!==11)return;if(root.nodeType===1){if(skipEl(root)||inSkip(root))return;attrs(root)}
  const w=document.createTreeWalker(root,5,{acceptNode:n=>n.nodeType===1&&skipEl(n)?2:1});let n;
  while((n=w.nextNode()))if(n.nodeType===3)txt(n);else attrs(n)}
let mo=null;
function start(){if(L==='de'||mo)return;
  mo=new MutationObserver(rs=>{for(const r of rs){if(r.type==='childList')r.addedNodes.forEach(walk);
      else if(r.type==='characterData'){if(!inSkip(r.target))txt(r.target)}
      else if(r.type==='attributes'&&r.target.nodeType===1&&!skipEl(r.target)&&!inSkip(r.target))attrs(r.target)}});
  mo.observe(document.documentElement,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:ATTR.concat('value')});
  walk(document.documentElement)}

/* ---------- Laden: zuerst der gemerkte Stand (sofort), dann frisch vom Server ---------- */
if(L!=='de'){
  const ck='sw_i18n_'+L;let have=null;
  try{const c=JSON.parse(get(ck)||'null');if(c&&c.d){setDict(c.d);have=c.v}}catch(e){}
  start();
  if(have!==VER||VER==='0')fetch('i18n/'+L+'.json?v='+VER,{cache:'no-cache'}).then(r=>r.ok?r.json():null).then(d=>{if(!d)return;
      const was=ready&&D.size;setDict(d);put(ck,JSON.stringify({v:VER,d}));
      if(!was||have!==VER){/* neu übersetzen: gemerkte Ergebnisse verwerfen */
        done=new WeakMap();adone=new WeakMap();walk(document.documentElement)}})
    .catch(()=>{});
}

/* ---------- Sprachwahl ---------- */
window.I18N={L,LANGS,LOCS,tr:s=>{const r=tr(s);return r==null?s:r},T,dev:devLang,
  chosen:()=>{const s=get('sw_lang');return s&&ok(s)?s:''},
  /* set: eigene Wahl merken ('' = automatisch) und neu laden */
  set(l,reload=true){put('sw_lang',l&&ok(l)?l:null);if(reload)location.reload()},
  def(l){if(l&&ok(l))put('sw_langdef',l)},
  /* orgDef: Vorgabe der Organisation (Route org, me.lang_default); gilt nur ohne eigene Wahl und ohne passende Sprache des Geräts */
  orgDef(l){if(!l||!ok(l)||get('sw_langdef')===l)return;put('sw_langdef',l);if(pick()!==L)location.reload()},
  /* me: Wahl des Kontos übernehmen (users.lang); ohne eigene Wahl auf dem Server meldet die App die hier gewählte (Rückgabe true = neu laden) */
  me(me,save){if(!me)return false;if(me.lang_default)put('sw_langdef',me.lang_default);const s=this.chosen(),m=me.lang&&ok(me.lang)?me.lang:'';
    if(m&&m!==s){put('sw_lang',m);if(pick()!==L){location.reload();return true}}
    else if(!m&&s&&save)save(s);
    else if(!m&&!s&&pick()!==L){location.reload();return true}
    return false},
  /* Auswahlfeld (Anmeldung, Profil): value '' = automatisch */
  select(id,cur){return `<select id="${id}" translate="no">${['',...Object.keys(LANGS)].map(k=>`<option value="${k}"${k===cur?' selected':''}>${k?LANGS[k]:'🌐 '+({de:'Automatisch',en:'Automatic',fr:'Automatique',it:'Automatico'})[L]+' ('+LANGS[devLang()||get('sw_langdef')||'de']+')'}</option>`).join('')}</select>`}};
})();
