/* Completion is a GET page: the redirect never replays the installation POST. */
(() => {
 'use strict';
 if(!document.querySelector('[data-install-complete]'))return;
 const counter=document.querySelector('[data-complete-seconds]');
 const pause=document.querySelector('[data-complete-pause]');
 const message=document.querySelector('.setup-complete-countdown');
 if(!counter||!pause||!message)return;
 let remaining=15;
 const timer=window.setInterval(()=>{
  remaining-=1;counter.textContent=String(remaining);
  if(remaining<=0){window.clearInterval(timer);window.location.replace('/?preview=1');}
 },1000);
 function stop(){window.clearInterval(timer);message.textContent='Il riepilogo resta aperto. Puoi continuare con i pulsanti qui sopra.';}
 pause.hidden=false;pause.addEventListener('click',stop);
 document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();});
})();
