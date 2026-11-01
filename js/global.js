/* --------------------------------------------------------------
 * global.js – utility comuni (ES2018+, zero dipendenze legacy)
 * ----------------------------------------------------------- */
(() => {
  'use strict';

  /* ----------------------------------------------------------
   * 0) Costanti / variabili globali
   * --------------------------------------------------------*/
  // id del DIV usato dal menu pop-up (utilizzato dai vecchi menu)
  window.nomeobj = window.nomeobj || 'mainmenu';

  // tracking posizione mouse (in sostituzione di captureEvents)
  let mouseX = 0, mouseY = 0;
  document.addEventListener('mousemove', e => {
    mouseX = e.pageX >= 0 ? e.pageX : 0;
    mouseY = e.pageY >= 0 ? e.pageY : 0;
  });

  /* ----------------------------------------------------------
   * 1) Apertura profili
   * --------------------------------------------------------*/
  window.openProfile = name => window.alphanoOpenView('/profili/?Nome=' + encodeURIComponent(name), 'Profilo');
  window.openProfileId = id => window.alphanoOpenView('/profili/?Id=' + encodeURIComponent(id), 'Profilo');

  /* ----------------------------------------------------------
   * 2) Piccole utility varie
   * --------------------------------------------------------*/
  window.compara    = (s1, s2)     => s1 === s2;                     // confronto stringhe
  window.arrotonda  = n            => n.toString().padStart(2, '0'); // zero-pad
  // ordinamento generico (richiede variabile globale orderby)
  window.ordina = (a, b) => {
    const key = window.orderby;
    const v1  = a[key], v2 = b[key];
    const n1  = Number(v1), n2 = Number(v2);
    if (!Number.isNaN(n1) && !Number.isNaN(n2)) return n1 - n2;
    const t1 = String(v1).toUpperCase(), t2 = String(v2).toUpperCase();
    return t1 < t2 ? -1 : (t1 > t2 ? 1 : 0);
  };

  /* ----------------------------------------------------------
   * 3) Tooltip vicino al mouse
   * --------------------------------------------------------*/
  window.MostraDescrizione = (id, html) => {
    let div = document.getElementById(id);
    if (!div) {
      div = document.createElement('div');
      div.id = id;
      Object.assign(div.style, {
        position       : 'absolute',
        zIndex         : '10000',
        pointerEvents  : 'none'
      });
      document.body.appendChild(div);
    }
    div.innerHTML = html;
    div.style.visibility = 'visible';

    // posizionamento (evita overflow finestra)
    const bw = div.offsetWidth,  bh = div.offsetHeight;
    let   x  = mouseX,           y  = mouseY;
    const cw = document.documentElement.clientWidth,
          ch = document.documentElement.clientHeight;

    if (x + bw > cw) x = Math.max(1, x - bw);
    if (y + bh > ch) y = Math.max(1, y - bh);

    div.style.left = `${x}px`;
    div.style.top  = `${y}px`;
  };

  window.NascondiDescrizione = id => {
    const div = document.getElementById(id);
    if (div) {
      div.style.visibility = 'hidden';
      div.innerHTML = '';
    }
  };

  /* ----------------------------------------------------------
   * 4) Ridimensiona popup mantenendo le cornici di sistema
   * --------------------------------------------------------*/

})();   // fine IIFE

// Reading a missiva updates the shared counters and any open mailbox immediately.
document.addEventListener('DOMContentLoaded',()=>{
 if(!Number(document.body?.dataset.mailRead))return;
 try{const host=parent;host.alphanoNotifications?.refresh();for(const frame of host.document.querySelectorAll('iframe'))frame.contentDocument?.dispatchEvent(new CustomEvent('alphano:mail-read'));}catch{}
});

// Portraits in mail reuse the public profile fallback and stay inside the workspace.
document.addEventListener('error',event=>{const img=event.target;if(img instanceof HTMLImageElement&&img.matches('img[data-mail-fallback]')){const fallback=img.dataset.mailFallback;delete img.dataset.mailFallback;if(fallback)img.src=fallback;}},true);
document.addEventListener('click',event=>{const link=event.target.closest('[data-mail-profile]');if(!link)return;try{if(top.alphanoOpenView){event.preventDefault();top.alphanoOpenView(link.href,'Profilo');}}catch{}});
