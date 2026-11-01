/* Open signup when requested; the native details and form also work without JS. */
(() => {
 'use strict';
 const registration=document.getElementById('registrazione');
 function reveal(){const hash=location.hash;if((hash==='#registrazione'||hash==='#register')&&registration){registration.open=true;registration.scrollIntoView({block:'start'});}else if(hash==='#login')document.getElementById('accesso')?.scrollIntoView({block:'start'});}
 document.querySelectorAll('a[href="#registrazione"]').forEach(link=>link.addEventListener('click',()=>{if(registration)registration.open=true;}));
 window.addEventListener('hashchange',reveal);reveal();
})();

/* Progressive enhancement: ordinary recovery links and POST forms remain usable without JS. */
(() => {
 'use strict';
 const modal=document.getElementById('portal-account-modal');
 if(!modal || typeof modal.showModal!=='function')return;
 const content=modal.querySelector('.portal-modal-content');
 let returnFocus=null, pending=false;
 function close(){if(!pending)modal.close();}
 function open(trigger){returnFocus=modal.contains(trigger)?document.querySelector('#accesso input[name=password]'):trigger; if(!modal.open)modal.showModal();}
 modal.querySelector('.portal-modal-close').addEventListener('click',close);
 modal.addEventListener('cancel',e=>{if(pending)e.preventDefault();});
 modal.addEventListener('close',()=>{returnFocus?.focus({preventScroll:true});});
 function recoveryLink(link){link.addEventListener('click',e=>{e.preventDefault();open(link);loadRecovery();});}
 async function loadRecovery(form){
  if(pending)return;
  pending=true;
  const closeButton=modal.querySelector('.portal-modal-close');
  closeButton.disabled=true;
  if(!form)content.innerHTML='<p class="modal-loading" role="status">Prepariamo il tuo ritorno…</p>';
  else form.setAttribute('aria-busy','true');
  try{
   const response=await fetch('/recuperopasswd.php',{method:form?'POST':'GET',body:form?new FormData(form):undefined,credentials:'same-origin',headers:{'Accept':'text/html'},signal:AbortSignal.timeout(15000)});
   if(!response.ok)throw new Error('recovery');
   const page=new DOMParser().parseFromString(await response.text(),'text/html');
   const card=page.querySelector('.account-recovery-card');
   if(!card)throw new Error('recovery');
   content.replaceChildren(document.importNode(card,true));
   modal.setAttribute('aria-labelledby','recovery-title');
   const next=content.querySelector('form');
   if(next)next.addEventListener('submit',e=>{e.preventDefault();loadRecovery(next);});
   content.querySelector('nav a')?.addEventListener('click',e=>{e.preventDefault();close();});
   const focus=content.querySelector('[role=alert], [role=status], input:not([type=hidden])')||content.querySelector('h1');
   if(focus && !focus.matches('input'))focus.setAttribute('tabindex','-1');focus?.focus({preventScroll:true});
  }catch{
   content.innerHTML='<section class="account-recovery"><h2>Un momento di attesa</h2><p role="alert">Non è stato possibile caricare il recupero. Riprova dalla pagina dedicata.</p><a href="/recuperopasswd.php">Apri il recupero password →</a></section>';
  }finally{pending=false;closeButton.disabled=false;}
 }
 document.querySelectorAll('a[href="/recuperopasswd.php"]').forEach(recoveryLink);
 const error=document.getElementById('portal-login-error');
 if(error){
  content.innerHTML='<section class="account-recovery-card"><header class="account-recovery-heading"><div class="ui-eyebrow">La tua storia ti aspetta</div><h1 id="login-modal-title">Riproviamo insieme.</h1><p>Non siamo riusciti a farti entrare.</p></header><div class="account-recovery"><div class="modal-error-slot"></div><div class="account-recovery-actions"><button type="button" class="modal-retry">Torna all’accesso →</button></div><a class="modal-secondary" href="/recuperopasswd.php">Hai dimenticato la password?</a></div></section>';
  content.querySelector('.modal-error-slot').append(error);
  content.querySelector('.modal-retry').addEventListener('click',close);
  recoveryLink(content.querySelector('.modal-secondary'));
  modal.setAttribute('aria-labelledby','login-modal-title');
  open(document.querySelector('#accesso input[name=password]'));
  const title=content.querySelector('h1');title.setAttribute('tabindex','-1');title.focus({preventScroll:true});
 }
})();
