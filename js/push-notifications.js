/* ES5 entry point: unsupported browsers retain the ordinary inbox without a permission prompt. */
(function () {
 'use strict';
 var supported=window.isSecureContext&&('Notification' in window)&&('PushManager' in window)&&navigator.serviceWorker;
 if(!supported){
  var fallback=function(){var boxes=document.querySelectorAll('[data-push-controls]');for(var i=0;i<boxes.length;i++){var status=boxes[i].querySelector('[data-push-status]');if(status)status.textContent='Questo browser non supporta le notifiche push. La posta e gli avvisi nel gioco restano disponibili.';}};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',fallback);else fallback();
  return;
 }
 var current=document.currentScript,script=document.createElement('script');
 script.src='/js/push-client.js'+(current&&current.src.indexOf('?')!==-1?'?'+current.src.split('?')[1]:'');
 script.defer=true;document.head.appendChild(script);
}());
