/* Privacy choices and explicit per-player game preferences share one settings window. */
(() => {
 'use strict';if(window.alphanoPrivacy)return;
 let dialog,gameLoaded=false,firstPromise;
 const allowed=()=>/^v1\.media\.[a-f0-9]{32}$/.test(document.cookie.split('; ').find(c=>c.startsWith('alphano_privacy='))?.split('=')[1]||'');
 async function open(){
  try{if(window!==window.top&&window.top.alphanoPrivacy)return window.top.alphanoPrivacy.open();}catch(_){}
  if(!dialog){
   dialog=document.createElement('dialog');dialog.className='privacy-dialog game-settings-dialog';dialog.setAttribute('aria-labelledby','privacyTitle');
   dialog.innerHTML='<form method="dialog"><button class="privacy-close" aria-label="Chiudi preferenze">×</button></form><span class="privacy-eyebrow">Alphano · Il tuo modo di giocare</span><h2 id="privacyTitle">Preferenze di gioco</h2><p>Personalizza atmosfera, suoni e contenuti. Le impostazioni di gioco seguono il tuo personaggio; la scelta sui contenuti esterni vale per questo browser.</p><p data-first-preferences hidden>Conferma le tue preferenze per iniziare. Se chiudi senza salvare, questa schermata tornerà al prossimo accesso.</p><div data-game-preferences hidden></div><details class="game-privacy-section" open><summary><img src="/assets/placeholder.svg" alt="" width="36" height="36">Riservatezza e contenuti esterni</summary><p>Il motore base funziona con strumenti essenziali e senza analytics o pubblicità. Consulta le informative per gli eventuali servizi aggiunti dal gestore o dal provider.</p><div class="privacy-option"><div><strong>Sessione e preferenze</strong><p>Accesso, sicurezza e impostazioni di lettura.</p></div><span>Sempre attivi</span></div><label class="privacy-option"><div><strong>Contenuti multimediali esterni</strong><p>Avatar, immagini o musica ospitati altrove. Se li abiliti, i siti di origine ricevono il tuo IP e i dati tecnici della richiesta. Puoi giocare senza caricarli.</p></div><input type="checkbox" id="privacyMedia"></label></details><p class="privacy-status" role="status" hidden></p><div class="privacy-actions"><button type="button" data-privacy-save="essential">Salva senza contenuti esterni</button><button type="button" data-privacy-save="selection">Salva le preferenze</button></div><nav><a href="/legal/?type=cookies" target="_blank" rel="noopener">Dettagli sui cookie</a><a href="/legal/?type=privacy" target="_blank" rel="noopener">Informativa privacy</a></nav>';
   document.body.append(dialog);
   dialog.addEventListener('input',e=>{if(e.target.matches('[data-volume-control]'))e.target.closest('label').querySelector('output').textContent=e.target.value+'%';});
   dialog.addEventListener('click',async event=>{
    if(event.target.closest('[data-test-notification]')){const box=dialog.querySelector('[data-game-preferences]');window.alphanoExperience?.test({notification_sound:true,notification_volume:Number(box.querySelector('[name=notification_volume]').value),sound_theme:box.querySelector('[name=sound_theme]').value});return;}
    const button=event.target.closest('[data-privacy-save]');if(!button)return;
    const buttons=[...dialog.querySelectorAll('[data-privacy-save]')],status=dialog.querySelector('.privacy-status');buttons.forEach(b=>b.disabled=true);status.hidden=false;status.textContent='Salvataggio delle preferenze…';
    try{
     const response=await fetch('/api/privacy.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({version:'1',externalMedia:button.dataset.privacySave==='selection'&&dialog.querySelector('#privacyMedia').checked})});
     if(!response.ok)throw Error('Scelta sui contenuti non salvata. Riprova.');
     if(gameLoaded){
      const box=dialog.querySelector('[data-game-preferences]'),settings={};
      for(const key of ['map_effects','map_lighting','ambient_sound','notification_sound'])settings[key]=box.querySelector('[name='+key+']').checked;
      for(const key of ['ambient_volume','notification_volume'])settings[key]=Number(box.querySelector('[name='+key+']').value);
      settings.sound_theme=box.querySelector('[name=sound_theme]').value;
      settings.relationship_effects=box.querySelector('[name=relationship_effects]').value;
      settings.chess_skin=box.querySelector('[name=chess_skin]').value;
      box.querySelectorAll('input[name^=email_]').forEach(input=>settings[input.name]=input.checked);
      const result=await fetch('/api/experience.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({settings,confirm:true})});
      if(!result.ok)throw Error('Preferenze di gioco non salvate. Riprova prima di chiudere.');
     }
     location.reload();
    }catch(error){status.textContent=error.message;buttons.forEach(b=>b.disabled=false);}
   });
  }
  if(document.getElementById('app-script')){
   const status=dialog.querySelector('.privacy-status');
   try{const response=await fetch('/api/experience.php',{cache:'no-store'});if(!response.ok)throw Error();const data=await response.json();dialog.querySelector('[data-game-preferences]').innerHTML=data.form;dialog.querySelector('[data-game-preferences]').hidden=false;dialog.querySelector('[data-first-preferences]').hidden=data.confirmed;gameLoaded=true;status.hidden=true;dialog.querySelectorAll('[data-privacy-save]').forEach(b=>b.disabled=false);}
   catch(_){status.hidden=false;status.textContent='Impostazioni di gioco non disponibili: chiudi e riprova.';gameLoaded=false;dialog.querySelectorAll('[data-privacy-save]').forEach(b=>b.disabled=true);}
  }
  dialog.querySelector('#privacyMedia').checked=allowed();if(!dialog.open)dialog.showModal();
  return dialog;
 }
 function firstLogin(){return firstPromise??=(async()=>{
  try{const response=await fetch('/api/experience.php',{cache:'no-store'});if(!response.ok)return;const data=await response.json();if(data.confirmed)return;const box=await open();if(box?.open)await new Promise(resolve=>box.addEventListener('close',resolve,{once:true}));}catch(_){}
 })();}
 window.alphanoPrivacy={open,firstLogin,externalMediaAllowed:allowed};
 document.addEventListener('click',event=>{if(event.target.closest('[data-cookie-settings]')){event.preventDefault();open();}});
 if(window===window.top){const button=document.createElement('button');button.type='button';button.className='privacy-launcher';button.title='Preferenze di gioco e riservatezza';button.setAttribute('aria-label',button.title);button.textContent='◈';button.addEventListener('click',open);document.body.append(button);}
 // CSP already blocks remote resources, including dynamically inserted elements.
 // Preserve their markup and geometry and offer a deliberate way to enable them.
 function explain(){
  if(allowed())return;
  document.querySelectorAll('audio[src],video[src],iframe[src],img[src]').forEach(el=>{
   if(el.dataset.privacyExplained)return;
   let url;try{url=new URL(el.getAttribute('src'),location.href);}catch(_){return;}
   if(!['http:','https:'].includes(url.protocol)||url.origin===location.origin)return;
   el.dataset.privacyExplained='1';
   const button=document.createElement('button');button.type='button';button.className='privacy-blocked-media';button.textContent='Contenuto esterno bloccato · Preferenze';button.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();open();});if(el.id==='profile-avatar'||el.hasAttribute('data-privacy-compact')){button.classList.add('privacy-avatar-control');button.title=button.textContent;button.setAttribute('aria-label',button.textContent);button.textContent='◈';}
   if(el.tagName==='IMG'){el.removeAttribute('srcset');el.src=el.dataset.defaultSrc||'/assets/placeholder.svg';if(el.dataset.defaultSrc?.includes('/assets/'))el.classList.add('is-race-portrait');el.title='Immagine esterna disattivata. Apri Preferenze cookie per abilitarla.';}
   else{el.hidden=true;}
   el.after(button);
  });
 }
 explain();let pending=false;new MutationObserver(()=>{if(!pending){pending=true;queueMicrotask(()=>{pending=false;explain();});}}).observe(document.body,{childList:true,subtree:true});
})();
