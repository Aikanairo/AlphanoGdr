/* Presentazione della scena: regole e generazione restano sul server. */
(() => {
 'use strict';
 const init=()=>{
  const $=id=>document.getElementById(id),root=document.body,drawer=$('chat-drawer'),input=$('comodo');
  const sessionDialog=$('sessionModalOverlay');
  window.OpenSessionManager=()=>{if(!sessionDialog)return;close();const frame=$('session-manager-frame');frame.src='/sessione/sessione.php?chat='+encodeURIComponent(window.chatRoom);if(!sessionDialog.open)sessionDialog.showModal();};
  window.CloseSessionManager=()=>{sessionDialog?.close();window.dispatchEvent(new Event('alphano:refresh-chat'));};
  for(const id of ['sessionBtn','scene-master-control'])$(id)?.addEventListener('click',window.OpenSessionManager);
  $('sessionModalClose')?.addEventListener('click',window.CloseSessionManager);
  sessionDialog?.addEventListener('close',()=>{ $('session-manager-frame')?.removeAttribute('src');window.dispatchEvent(new Event('alphano:refresh-chat'));});
  let opened=null,quest=null,offset=0,cueKey='',acknowledged='',presenceBusy=false,returnFocus=null;
  const icon=name=>window.alphanoUI?.icon(name)||document.createTextNode('');
  const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;if(cls)n.className=cls;return n;};
  const title={menu:'Menu e pannelli',luogo:'Luogo e ambientazione',descrizione:'Descrizione',presenti:'Personaggi e presenze',scontro:'Situazione della quest',opzioni:'Opzioni della chat'};
  const basePlaceholder=input?.placeholder||'';
  function close(){drawer.hidden=true;$('chat-mobile-menu')?.setAttribute('aria-expanded','false');$('chat-drawer-backdrop').hidden=true;opened=null;document.querySelectorAll('.scene-actions [data-view]').forEach(b=>{b.classList.toggle('active',b.dataset.view==='chat');b.setAttribute('aria-expanded','false');});returnFocus?.focus({preventScroll:true});}
  function open(kind,button){if(kind==='chat'||opened===kind){close();return;}returnFocus=button;opened=kind;drawer.hidden=false;$('chat-drawer-backdrop').hidden=false;$('chat-drawer-title').textContent=title[kind];
   drawer.querySelectorAll('.chat-drawer-body > section').forEach(s=>s.hidden=s.id!==kind);document.querySelectorAll('.scene-actions [data-view]').forEach(b=>{b.classList.toggle('active',b.dataset.view===kind);b.setAttribute('aria-expanded',String(b.dataset.view===kind));});$('chat-drawer-close').focus({preventScroll:true});if(kind==='presenti')presence();if(kind==='scontro')combat();
  }
  document.querySelectorAll('.scene-actions [data-view]').forEach(b=>b.addEventListener('click',()=>open(b.dataset.view,b)));
  window.addEventListener('alphano:open-panel',e=>open(e.detail,document.querySelector('.scene-actions [data-view="'+e.detail+'"]')));
  $('chat-mobile-menu')?.addEventListener('click',()=>{open(opened==='menu'?'chat':'menu',$('chat-mobile-menu'));$('chat-mobile-menu').setAttribute('aria-expanded',String(opened==='menu'));});
  document.querySelectorAll('[data-scene-open]').forEach(b=>b.addEventListener('click',()=>open(b.dataset.sceneOpen,b)));
  const mobileLinks=document.querySelector('.scene-mobile-links');
  for(const [source,label] of [['sessionBtn','Session Manager'],['recordBtn','Registra / salva giocata']])if($(source)&&mobileLinks){const b=el('button',label);b.type='button';b.addEventListener('click',()=>{close();$(source).click();});mobileLinks.append(b);}
  if($('moodSelect')&&mobileLinks){const label=el('label','Umore'),select=$('moodSelect').cloneNode(true);select.id='scene-mobile-mood';select.addEventListener('change',()=>{$('moodSelect').value=select.value;$('moodSelect').dispatchEvent(new Event('change'));});label.append(select);mobileLinks.append(label);}
  const returnPlace=()=>{if(window.parent!==window&&typeof window.parent.alphanoReturnToLocation==='function')window.parent.alphanoReturnToLocation();else location.href='/main.php';};
  $('scene-return')?.addEventListener('click',returnPlace);
  if(mobileLinks){const back=el('button','Torna al luogo');back.type='button';back.addEventListener('click',returnPlace);mobileLinks.append(back);}
  $('chat-game-menu')?.addEventListener('click',()=>{close();if(window.parent!==window)window.parent.alphanoMobileMenu?.();else location.href='/main.php';});
  if(window.parent!==window)window.parent.alphanoSceneFocus?.(true);
  window.addEventListener('pagehide',()=>{if(window.parent!==window)window.parent.alphanoSceneFocus?.(false);},{once:true});
  $('chat-drawer-close')?.addEventListener('click',close);$('chat-drawer-backdrop')?.addEventListener('click',close);document.addEventListener('keydown',e=>{if(e.key==='Escape'&&opened)close();if(e.key==='Tab'&&opened){const controls=[...drawer.querySelectorAll('button,a[href],input,select,textarea')].filter(n=>!n.disabled&&n.getClientRects().length);const first=controls[0],last=controls[controls.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}}});
  let prefs={portraits:true,compact:false,darkpaper:false,font:16};try{Object.assign(prefs,JSON.parse(localStorage.getItem('alphano.chat.reading')||'{}'));}catch(_){}
  prefs.font=Math.max(14,Math.min(21,Number(prefs.font)||16));
  function reading(){root.classList.toggle('chat-noport',!prefs.portraits);root.classList.toggle('chat-compact',!!prefs.compact);root.classList.toggle('chat-darkpaper',!!prefs.darkpaper);root.style.setProperty('--chat-font',prefs.font+'px');if($('optFontValue'))$('optFontValue').textContent=prefs.font+' px';try{localStorage.setItem('alphano.chat.reading',JSON.stringify(prefs));}catch(_){};}
  for(const [id,key] of [['optPortrait','portraits'],['optCompact','compact'],['optDark','darkpaper']])if($(id)){$(id).checked=!!prefs[key];$(id).addEventListener('change',e=>{prefs[key]=e.target.checked;reading();});}
  if($('optFont')){$('optFont').value=prefs.font;$('optFont').addEventListener('input',e=>{prefs.font=Number(e.target.value);reading();});}reading();
  $('chat-expand')?.addEventListener('click',()=>{const parent=window.parent;if(parent!==window&&typeof parent.alphanoToggleReading==='function'){const wide=parent.alphanoToggleReading();$('chat-expand').setAttribute('aria-pressed',String(wide));$('chat-expand').setAttribute('aria-label',wide?'Ripristina colonna destra':'Espandi la chat');}});
  async function presence(){if(presenceBusy)return;presenceBusy=true;const box=$('scene-presence');box.textContent='Aggiornamento delle presenze…';try{const r=await fetch('/chat/tools.php?chat='+encodeURIComponent(window.chatRoom)+'&action=presence',{cache:'no-store'}),data=await r.json();if(!r.ok)throw Error(data.error);box.replaceChildren();for(const p of data.characters){const row=el('a',null,'scene-mini-person');row.href=p.profile_url;const img=el('img',null,'scene-presence-portrait');const fallback=p.portrait_default||'/assets/placeholder.svg';img.src=p.portrait||fallback;img.dataset.defaultSrc=fallback;img.setAttribute('data-privacy-compact','');img.alt='Ritratto di '+p.name;img.width=48;img.height=48;img.loading='lazy';img.addEventListener('error',()=>{if(img.getAttribute('src')!==fallback)img.src=fallback;},{once:true});const copy=el('div');copy.append(el('strong',p.name),el('small',p.role));row.append(img,copy);box.append(row);}if(!data.characters.length)box.textContent='Nessun altro personaggio presente.';}catch(e){box.textContent=e.message;}finally{presenceBusy=false;}}
  function combat(){const box=$('scene-combat-detail');if(!box)return;box.replaceChildren();if(!quest){box.append(el('p','Nessuna quest attiva: il gioco prosegue liberamente.'));}else{
   const list=el('dl',null,'scene-detail-list');for(const [key,value] of [['Sessione',quest.name],['Regia',quest.mode==='human'?'Master umano':'Master IA'],['Giro',quest.round],['Stato',quest.phase_label],['Coda',(quest.status==='active'?quest.remaining:quest.next_queue).map(p=>p.name).join(' → ')]])list.append(el('dt',key),el('dd',value));box.append(list);if(quest.progress?.label)box.append(el('p',quest.progress.label));}
   if($('scene-takeover'))$('scene-takeover').hidden=!quest?.can_control||quest.mode==='human';if($('scene-release'))$('scene-release').hidden=!quest?.can_control||quest.mode!=='human';
  }
  async function conductor(mode){if(!quest)return;try{const response=await fetch('/api/ai-engine.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.chatToken},body:JSON.stringify({action:'conductor',quest_id:quest.id,version:quest.version,mode,reason:mode==='human'?'Regia assunta dal Master presente':'Regia restituita al Master IA'})}),data=await response.json();if(!response.ok)throw Error(data.error);await window.alphanoDialog.notice({title:'Regia aggiornata',message:mode==='human'?'Dirigi ora la quest. I personaggi dichiarano nell’ordine indicato; al termine del giro scrivi l’esito con Esito Master o #. L’IA annuncia soltanto i turni.':'La regia automatica riprende dalla situazione registrata.'});window.dispatchEvent(new Event('alphano:refresh-chat'));}catch(e){window.alphanoDialog.notice({message:e.message});}}
  $('scene-takeover')?.addEventListener('click',()=>conductor('human'));$('scene-release')?.addEventListener('click',()=>conductor('auto'));$('scene-manager')?.addEventListener('click',()=>window.OpenSessionManager?.());
  function clock(){const w=quest?.turn_window;if(!w)return;const bar=$('quest-chat-status');let node=$('quest-countdown');if(!node&&bar){node=el('span',null,'quest-countdown');node.id='quest-countdown';bar.append(node);}if(!node)return;node.hidden=!w.active;if(!w.active)return;const left=Math.max(0,Math.ceil(w.deadline-(Date.now()/1000+offset))),m=Math.floor(left/60),s=left%60;node.textContent=(w.mine?'Il tuo turno':'Tempo del turno')+' · '+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');node.classList.toggle('is-urgent',left<120);}
  window.addEventListener('alphano:quest',e=>{quest=e.detail;const w=quest?.turn_window;offset=w?w.server_time-Date.now()/1000:0;if(w?.mine){cueKey=w.key;if(acknowledged!==cueKey){input?.classList.add('is-your-turn');if(input&&!input.value)input.placeholder='È il tuo turno';}}else{input?.classList.remove('is-your-turn');if(input)input.placeholder=basePlaceholder;}combat();clock();});
  input?.addEventListener('input',()=>{input.style.height='auto';input.style.height=Math.min(120,input.scrollHeight)+'px';});
  const acknowledge=()=>{acknowledged=cueKey;input?.classList.remove('is-your-turn');if(input)input.placeholder=basePlaceholder;};
  for(const event of ['focus','pointerdown','input'])input?.addEventListener(event,acknowledge);
  const tick=setInterval(clock,1000);window.addEventListener('pagehide',()=>clearInterval(tick),{once:true});
  const toast=$('ai-chat-status');let toastTimer;if(toast){const observer=new MutationObserver(()=>{clearTimeout(toastTimer);toast.classList.toggle('is-visible',!!toast.textContent.trim());if(toast.textContent.trim())toastTimer=setTimeout(()=>toast.classList.remove('is-visible'),4500);});observer.observe(toast,{childList:true,characterData:true,subtree:true});window.addEventListener('pagehide',()=>{observer.disconnect();clearTimeout(toastTimer);},{once:true});}
  let diceBusy=false;
  $('diceBtn')?.addEventListener('click',async()=>{if(diceBusy)return;diceBusy=true;try{const r=await fetch('/chat/tools.php?chat='+encodeURIComponent(window.chatRoom),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.chatToken},body:JSON.stringify({action:'dice',request_id:crypto.randomUUID()})}),data=await r.json();if(!r.ok)throw Error(data.error);window.dispatchEvent(new Event('alphano:refresh-chat'));}catch(e){window.alphanoDialog.notice({title:'Dadi della scena',message:e.message});}finally{diceBusy=false;}});
  if(!input)open('luogo');
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
