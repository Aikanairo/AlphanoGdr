(()=>{
 'use strict';
 const trigger=document.getElementById('chat-board-games-open');if(!trigger)return;
 const panel=document.getElementById('chat-board-games-panel'),reduce=panel.querySelector('header button'),frame=panel.querySelector('iframe');
 const links=document.querySelector('.scene-mobile-links');let mobile;
 function show(open){
  panel.hidden=!open;for(const button of [trigger,mobile])button?.setAttribute('aria-expanded',String(open));
  if(open){if(!frame.src)frame.src=trigger.dataset.url+(new URLSearchParams(location.search).get('board_game')?'&id='+Number(new URLSearchParams(location.search).get('board_game')):'');reduce.focus();}
  else (matchMedia('(max-width:760px)').matches?document.getElementById('chat-mobile-menu'):trigger)?.focus();
 }
 trigger.onclick=()=>show(panel.hidden);
 if(links){mobile=document.createElement('button');mobile.type='button';mobile.textContent='Scacchi e Dama';const artwork=trigger.querySelector('.scene-control-art');if(artwork)mobile.prepend(artwork.cloneNode(true));mobile.setAttribute('aria-controls',panel.id);mobile.setAttribute('aria-expanded','false');mobile.onclick=()=>{document.getElementById('chat-drawer-close')?.click();show(true);};links.append(mobile);}
 reduce.onclick=()=>show(false);
 panel.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();show(false);}});
 if(new URLSearchParams(location.search).has('board_game'))show(true);
})();
