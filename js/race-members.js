import {escapeStatus as esc} from './game-status.js';
const init=()=>{
 const fallback=img=>{if(img.dataset.defaultSrc&&img.getAttribute('src')!==img.dataset.defaultSrc)img.src=img.dataset.defaultSrc;};
 document.addEventListener('error',event=>{if(event.target.matches?.('.race-member-card img'))fallback(event.target);},true);
 document.querySelectorAll('.race-member-card img').forEach(img=>{if(img.complete&&!img.naturalWidth)fallback(img);});
 const grid=document.querySelector('[data-race-members]'),button=document.querySelector('[data-race-more]'),status=document.querySelector('[data-race-loaded]');if(!grid||!button)return;
 let offset=grid.children.length,busy=false,done=false;
 async function load(){
  if(busy||done||button.closest('[hidden]'))return;busy=true;button.disabled=true;status.textContent='Caricamento dei personaggi…';
  try{
   const response=await fetch('/api/razze.php?'+new URLSearchParams({race:grid.dataset.raceMembers,offset}),{signal:AbortSignal.timeout(10000)});
   if(!response.ok)throw Error();const data=await response.json();
   const known=new Set(Array.from(grid.querySelectorAll('a'),a=>a.getAttribute('href')));
   for(const p of data.items){const href='/profili/?Id='+Number(p.id);if(known.has(href))continue;const card=document.createElement('a');card.className='race-member-card';card.href=href;card.innerHTML=`<img src="${esc(p.portrait)}" data-default-src="${esc(p.portrait_default)}" data-privacy-compact width="64" height="64" alt="" loading="lazy"><span><strong>${esc(p.name)}</strong><small>${p.human_form?'Attualmente in forma umana':'Scheda del personaggio'}</small></span>`;grid.append(card);}
   done=data.next===null;offset=data.next??offset+data.items.length;status.textContent=grid.children.length+' di '+data.total+' personaggi';button.hidden=done;
  }catch{status.textContent='Caricamento non riuscito. Puoi riprovare.';}finally{busy=false;button.disabled=false;}
 }
 button.addEventListener('click',load);
 if('IntersectionObserver' in window){const observer=new IntersectionObserver(entries=>{if(entries.some(e=>e.isIntersecting))load();},{rootMargin:'80px'});observer.observe(button);}
};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
