/* Missing portraits retain the same framed, gender-specific silhouette. */
document.addEventListener('DOMContentLoaded',()=>{
 for(const img of document.querySelectorAll('[data-profile-banner]')){const fallback=()=>{if(img.getAttribute('src')!==img.dataset.defaultSrc)img.src=img.dataset.defaultSrc;};img.addEventListener('error',fallback);if(img.complete&&!img.naturalWidth)fallback();}
 document.querySelectorAll('a[href="#equipment"]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();window.alphanoUI.reveal(document.getElementById('equipment'));history.replaceState(null,'','#equipment');}));
 const equipment=document.querySelector('[data-profile-equipment]');
 if(equipment){let observer,scheduled;
  const attach=()=>{observer?.disconnect();cancelAnimationFrame(scheduled);try{
   const body=equipment.contentDocument?.body;
   // Only the intrinsic equipment document supports auto-height. A full page
   // (including an old commerce redirect) must never drive a viewport feedback loop.
   if(!body?.classList.contains('equipment-embedded')){equipment.style.height='850px';return;}
   const resize=()=>{cancelAnimationFrame(scheduled);scheduled=requestAnimationFrame(()=>{
    if(!equipment.isConnected||equipment.contentDocument?.body!==body||!equipment.getClientRects().length)return;
    const height=Math.min(8000,Math.max(500,Math.ceil(body.getBoundingClientRect().height)));
    if(Math.abs(equipment.clientHeight-height)>2)equipment.style.height=height+'px';
   });};
   observer=new ResizeObserver(resize);observer.observe(body);resize();
  }catch{equipment.style.height='850px';}};
  equipment.addEventListener('load',attach);if(equipment.contentDocument?.readyState==='complete')attach();
  document.querySelectorAll('[data-tab="equipment"]').forEach(button=>button.addEventListener('click',attach));
  window.addEventListener('pagehide',()=>{observer?.disconnect();cancelAnimationFrame(scheduled);});
 }
 document.querySelector('[data-profile-return]')?.addEventListener('click',()=>{try{if(parent!==window&&parent.alphanoReturnToLocation){parent.alphanoReturnToLocation();return;}}catch{}location.assign('/main.php');});
 for(const img of document.querySelectorAll('[data-profile-portrait]')){const fallback=()=>{img.classList.add('is-race-portrait');if(img.getAttribute('src')!==img.dataset.defaultSrc)img.src=img.dataset.defaultSrc;};img.addEventListener('error',fallback);if(img.complete&&!img.naturalWidth)fallback();}
 for(const img of document.querySelectorAll('.rpg-emblem img'))img.addEventListener('error',()=>{const label=document.createElement('span');label.textContent='✦';label.setAttribute('aria-label',img.alt);img.replaceWith(label);},{once:true});
});
