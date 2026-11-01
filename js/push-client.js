/* Explicit per-browser opt-in. Never request notification permission during page load. */
(() => {
 'use strict';
 const supported=!!(window.isSecureContext&&'Notification' in window&&'PushManager' in window&&navigator.serviceWorker);
 const controls=()=>Array.from(document.querySelectorAll('[data-push-controls]'));
 let config=null,subscription=null,enabled=false,busy=false,feedback='';
 function render(message){
  if(message!==undefined)feedback=message;
  controls().forEach(box=>{
   const status=box.querySelector('[data-push-status]'),on=box.querySelector('[data-push-enable]'),off=box.querySelector('[data-push-disable]');
   if(status)status.textContent=feedback||(!supported?'Questo browser non supporta le notifiche push. La posta e gli avvisi nel gioco restano disponibili.':!config?.configured?'Le notifiche del browser saranno disponibili dopo la configurazione del server.':enabled?'Attive su questo browser. Anche a gioco chiuso, se il browser consente le attività in background.':Notification.permission==='denied'?'Notifiche bloccate nelle impostazioni del browser. Puoi riabilitarle da lì.':'Disattivate. Premi «Attiva notifiche» per scegliere se consentirle al browser.');
   if(on){on.disabled=busy||!supported||!config?.configured||Notification.permission==='denied';on.hidden=enabled;}
   if(off){off.disabled=busy;off.hidden=!enabled;}
  });
 }
 async function request(action,extra){
  const response=await fetch('/api/push.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':config.csrfToken},body:JSON.stringify({action,...extra})});
  const result=await response.json();if(!response.ok)throw Error(result.error||'Notifiche non disponibili. Riprova più tardi.');return result;
 }
 function keyBytes(value){const raw=atob(value.replace(/-/g,'+').replace(/_/g,'/'));return Uint8Array.from(raw,c=>c.charCodeAt(0));}
 function sameKey(sub,key){const stored=sub.options?.applicationServerKey;if(!stored)return true;const bytes=new Uint8Array(stored),expected=keyBytes(key);return bytes.length===expected.length&&bytes.every((b,i)=>b===expected[i]);}
 async function inspect(){
  if(!supported||!config)return;
  enabled=false;
  const registration=await navigator.serviceWorker.getRegistration('/');
  subscription=await registration?.pushManager.getSubscription();
  if(subscription){const state=await request('status',{endpoint:subscription.endpoint});enabled=state.enabled&&(!config.public_key||sameKey(subscription,config.public_key));}
 }
 async function enable(){
  if(busy||!supported||!config?.configured)return;
  busy=true;render('Attivazione delle notifiche…');
  let created=false;
  try{
   // This call happens synchronously inside the button click, before any await.
   const permission=Notification.permission==='granted'?Promise.resolve('granted'):Notification.requestPermission();
   if(await permission!=='granted'){render('Consenso non concesso. Puoi continuare a usare gli avvisi nel gioco.');return;}
   const registration=await navigator.serviceWorker.register('/sw-push.js',{scope:'/'});
   await navigator.serviceWorker.ready;
   subscription=await registration.pushManager.getSubscription();
   if(subscription&&!sameKey(subscription,config.public_key)){await subscription.unsubscribe();subscription=null;}
   if(!subscription){subscription=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:keyBytes(config.public_key)});created=true;}
   await request('subscribe',{subscription:subscription.toJSON()});enabled=true;
   render('Notifiche attivate su questo browser. I messaggi privati non compaiono nell’anteprima.');
  }catch(error){if(created&&subscription)await subscription.unsubscribe().catch(()=>{});enabled=false;render(error.message||'Attivazione non completata. Riprova più tardi.');}
  finally{busy=false;render();}
 }
 async function disable(){
  if(busy||!subscription)return;busy=true;render('Disattivazione delle notifiche…');
  try{await request('unsubscribe',{endpoint:subscription.endpoint});await subscription.unsubscribe();subscription=null;enabled=false;render('Notifiche disattivate su questo browser.');}
  catch(error){render(error.message);return;}finally{busy=false;render();}
 }
 function bind(){controls().forEach(box=>{box.querySelector('[data-push-enable]')?.addEventListener('click',enable);box.querySelector('[data-push-disable]')?.addEventListener('click',disable);});render();}
 async function start(){
  bind();if(!controls().length&&!document.getElementById?.('app-script'))return;
  try{const response=await fetch('/api/push.php',{cache:'no-store',credentials:'same-origin'});if(!response.ok)return;config=await response.json();await inspect();render();}catch(_){render('Impossibile verificare le notifiche. Riprova più tardi.');}
 }
 // SW uses one visible top-level client, while polling uses the same event key.
 if(supported)navigator.serviceWorker.addEventListener('message',event=>{
  if(!enabled||event.data?.type!=='alphano:push-mail'||typeof event.data.event!=='string')return;
  window.alphanoNotifications?.refresh();
  window.alphanoExperience?.ready().then(()=>window.alphanoExperience.notifyOnce(event.data.event)).catch(()=>{});
 });
 document.addEventListener?.('visibilitychange',()=>{if(!document.hidden&&config){feedback='';inspect().then(()=>render()).catch(()=>{});}});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
