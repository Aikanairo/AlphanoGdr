/* Same invitation entry point from the full directory and the main sidebar. */
(()=>{'use strict';document.addEventListener('click',event=>{const link=event.target.closest('[data-presence-game]');if(!link||event.button>0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;event.preventDefault();window.alphanoOpenView(link.href,'Invita a Scacchi o Dama');});})();
