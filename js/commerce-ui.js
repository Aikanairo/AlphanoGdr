/* Shared commerce dialogs and cross-frame refresh; no native browser alerts. */
(()=>{'use strict';
 const url=id=>'/commercio/?id='+Number(id);
 const refresh=detail=>{window.alphanoNotifications?.refresh();document.dispatchEvent(new CustomEvent('alphano:commerce-refresh',{detail}));};
 function broadcast(detail={}){
  refresh(detail);
  const frames=[...document.querySelectorAll('iframe')];let current=false;
  for(const frame of frames){try{if(Number(frame.contentDocument?.body.dataset.corporation)===Number(detail.corp))current=true;}catch{}frame.contentWindow?.postMessage({type:'alphano:commerce-refresh',detail},location.origin);}
  if(detail.action==='take'&&Number(detail.corp)>0){
   // Once assigned, continue in the work queue instead of leaving the order in the client list.
   document.querySelectorAll('dialog.ui-frame-notice').forEach(dialog=>{if(dialog.querySelector('iframe[title="Trattativa commerciale"]'))dialog.close('assigned');});
   if(!current){const destination='/corporazioni/trasferimenti.php?corp='+Number(detail.corp)+'#stven';if(window.alphanoWorkspace)window.alphanoWorkspace.open(destination,'I miei ordini');else if(location.pathname!=='/corporazioni/trasferimenti.php')location.assign(destination);}
  }
 }
 window.alphanoCommerce={
  open(id){return window.alphanoDialog?.notice({title:'Trattativa #'+Number(id),eyebrow:'Alphano · La bottega',message:'Prezzi, quantità e stato sono aggiornati. Rispondi direttamente da questa scheda.',contentUrl:url(id),buttonLabel:'Chiudi trattativa'});},
  notify(message,error=false,id=0){if(id&&!error)return this.open(id);return window.alphanoDialog?.notice({title:error?'Operazione da verificare':'Commercio',eyebrow:'Alphano · La bottega',message});},
  changed(detail={}){if(parent!==window)parent.postMessage({type:'alphano:commerce-changed',detail},location.origin);else broadcast(detail);}
 };
 window.addEventListener('message',e=>{if(e.origin!==location.origin)return;if(e.data?.type==='alphano:commerce-refresh')refresh(e.data.detail);if(e.data?.type==='alphano:commerce-changed'&&parent===window)broadcast(e.data.detail);});
})();
