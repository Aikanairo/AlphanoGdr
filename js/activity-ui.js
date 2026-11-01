/* Contatori comuni: la stessa fotografia alimenta menu e tab del contenuto. */
(() => {
 'use strict';
 let state={},pending=false;
 function canonical(value){try{const url=new URL(value,location.origin);url.pathname=url.pathname.replace(/\/index\.php$/,'/');url.searchParams.delete('v');url.searchParams.sort();return url.pathname+url.search+url.hash;}catch{return '';}}
 function setBadge(element,n){element.hidden=!n;element.textContent=String(n);element.setAttribute('aria-label',`${n} notifiche non lette`);}
 function render(){
  pending=false;const counts=state.counts||{},links=state.links||{};
  document.querySelectorAll('[data-notification-group]').forEach(el=>setBadge(el,Number(counts[el.dataset.notificationGroup])||0));
  document.querySelectorAll('[data-notification-link]').forEach(el=>{
   const url=new URL(el.dataset.notificationLink,location.origin),key=canonical(url.href);
   const n=url.pathname==='/notifiche/'?(url.searchParams.get('group')?counts[url.searchParams.get('group')]:state.activity_total):links[key];setBadge(el,Number(n)||0);
  });
  const route=canonical(location.pathname+location.search),base=route.split('#')[0];
  document.querySelectorAll('[role=tab],.ui-tab-btn').forEach(button=>{
   if(button.dataset.commerceCount)return;const target=button.getAttribute('aria-controls');if(!target)return;const panel=document.getElementById(target);if(!panel)return;
   let n=0;for(const [key,count] of Object.entries(links)){
    const [path,hash]=key.split('#');if(path!==base||!hash)continue;
    const anchor=document.getElementById(decodeURIComponent(hash));if(anchor&&(anchor===panel||panel.contains(anchor)))n+=Number(count)||0;
   }
   let badge=button.querySelector('[data-activity-tab-badge]');if(!badge&&n){badge=document.createElement('span');badge.className='notification-badge';badge.dataset.activityTabBadge='';button.append(badge);}if(badge)setBadge(badge,n);
  });
  if(location.pathname.startsWith('/notifiche/')||document.querySelector('#game-sidebar'))return;
  const n=Number(links[base])||0;let banner=document.querySelector('.activity-section-link');
  if(!banner&&n){banner=document.createElement('a');banner.className='activity-section-link';const container=document.querySelector('main')||document.body;container.prepend(banner);}
  if(banner){banner.hidden=!n;const filters=state.link_filters?.[base]||[],groups=[...new Set(filters.map(f=>f.group))],sections=[...new Set(filters.map(f=>f.section))];banner.textContent=`${n} ${n===1?'notifica':'notifiche'} in questa sezione · Leggi la cronaca`;const query=new URLSearchParams();if(groups.length===1)query.set('group',groups[0]);if(sections.length===1)query.set('section',sections[0]);banner.href='/notifiche/'+(query.size?'?'+query:'');}
 }
 function queue(){if(!pending){pending=true;queueMicrotask(render);}}
 document.addEventListener('alphano:notifications',event=>{state=event.detail||{};queue();});
 document.addEventListener('DOMContentLoaded',()=>{
  let shared=false;try{if(parent!==window&&parent.alphanoNotifications){state=parent.alphanoNotifications.getState();shared=true;}}catch{}
  queue();new MutationObserver(changes=>{if(changes.some(change=>[...change.addedNodes].some(node=>node.nodeType===1&&!node.matches?.('.notification-badge,.activity-section-link'))))queue();}).observe(document.body,{subtree:true,childList:true});
  if(!shared&&!document.getElementById('game-sidebar')){
   const fetchState=async()=>{if(document.hidden)return;try{const response=await fetch('/api/notifications.php',{cache:'no-store'});if(response.ok){state=await response.json();queue();}}catch{}};
   fetchState();setInterval(fetchState,30000);
  }
 });
})();
