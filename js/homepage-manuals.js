/* The landing page reads the same public handbook catalogue as its full library. */
(() => {
 'use strict';
 const list=document.getElementById('home-manual-chapters');if(!list)return;
 const keys=['primi-passi','razze','chat','combattimento','citta-elezioni','corporazioni'];
 const node=(tag,className,text)=>{const el=document.createElement(tag);if(className)el.className=className;if(text)el.textContent=text;return el;};
 async function load(){
  try{
   const response=await fetch('/api/manuali.php?public=1',{cache:'no-store'});
   if(!response.ok)throw Error();const data=await response.json();if(!data.success)throw Error();
   for(const key of keys){
    const chapter=data.rules.find(r=>r.key===key);if(!chapter)continue;
    const card=node('a','home-manual-card');card.href='/manuali/?chapter='+encodeURIComponent(key);
    const image=node('img');image.src=chapter.image;image.alt='';image.width=640;image.height=360;image.loading='lazy';
    const copy=node('div','home-manual-copy');copy.append(node('span','eyebrow','Guida al gioco'),node('h3','',chapter.oggetto),node('p','',chapter.summary),node('span','home-manual-read','Leggi il capitolo →'));
    card.append(image,copy);list.append(card);
   }
  }catch(_){list.replaceChildren(node('p','home-manual-fallback','Tutti i capitoli sono disponibili nella biblioteca: aprila con il pulsante qui sotto.'));}
 }
 load();
})();
