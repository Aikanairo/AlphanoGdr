/* Live composer inspired by the supplied V2 layout; game actions retain their server endpoints. */
(()=>{
 'use strict';const boot=()=>{
 const root=document.querySelector('.g-composer'),input=document.getElementById('comodo');if(!root||!input)return;
 const hint=document.getElementById('composer-hint'),type=document.getElementById('messageType'),mood=document.getElementById('moodSelect'),npc=document.getElementById('ai-chat-npc'),counter=document.getElementById('composer-count');let timer,savedNpc=null,touched=false;
 const key='alphano:chat-saved-draft:'+window.myPgId+':'+window.chatRoom;
 function note(text){clearTimeout(timer);hint.textContent=text;root.classList.add('g-has-notice');timer=setTimeout(()=>{root.classList.remove('g-has-notice');updateHint();},5500);}
 function updateHint(){if(root.classList.contains('g-has-notice'))return;const text=input.value.trimStart(),kind=text.startsWith('+')?'action':text.startsWith('//')?'ooc':text.startsWith('#')?'master':'speech';const messages={speech:'Scrivi le parole del tuo personaggio…',action:'Descrivi ciò che il personaggio tenta di fare…',ooc:'Scrivi un commento fuori gioco…',master:'Descrivi l’esito della scena…'};input.placeholder=messages[kind];hint.textContent=kind==='action'?'Descrivi il tentativo: sarà il gioco a stabilirne l’esito.':'Descrivi ciò che tenti: il gioco ne stabilirà l’esito.';}
 function count(){counter.textContent=Array.from(input.value).length.toLocaleString('it-IT')+' caratteri';input.style.height='auto';input.style.height=Math.min(210,Math.max(38,input.scrollHeight))+'px';}
 function restoreNpc(){if(savedNpc===null||touched)return;if([...npc.options].some(o=>o.value===savedNpc)){npc.value=savedNpc;savedNpc=null;}}
 npc.addEventListener('change',()=>{touched=true;});window.addEventListener('alphano:npc-options',restoreNpc);
 document.getElementById('composer-save').addEventListener('click',()=>{
  if(!input.value.trim()){note('Scrivi un testo prima di salvare la bozza.');input.focus();return;}
  try{localStorage.setItem(key,JSON.stringify({text:input.value,mood:mood.value,npc:npc.value,saved_at:Date.now()}));note('Bozza salvata su questo dispositivo. Potrai ritrovarla riaprendo questa chat.');}catch(_){note('Il browser non consente di salvare la bozza. Il testo resta nel campo.');}
 });
 const restore=()=>{try{const saved=JSON.parse(localStorage.getItem(key)||'null');if(saved&&typeof saved.text==='string'&&!input.value&&new TextEncoder().encode(saved.text).length<=16000){input.value=saved.text;if([...mood.options].some(o=>o.value===saved.mood)){mood.value=saved.mood;mood.dispatchEvent(new Event('change'));}savedNpc=String(saved.npc??'0');restoreNpc();input.dispatchEvent(new Event('input'));note('Bozza ripristinata. Controlla il testo prima di inviarlo.');}}catch(_){}count();updateHint();};
 input.addEventListener('input',()=>{count();updateHint();});type.addEventListener('change',updateHint);
 window.addEventListener('alphano:message-sent',event=>{try{const saved=JSON.parse(localStorage.getItem(key)||'null');if(saved&&saved.text.trim()===String(event.detail?.text||'').trim())localStorage.removeItem(key);}catch(_){} });
 if(document.readyState==='complete')restore();else window.addEventListener('load',restore,{once:true});count();updateHint();
 window.addEventListener('pagehide',()=>{clearTimeout(timer);});
 };if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
