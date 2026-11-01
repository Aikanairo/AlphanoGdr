/* One public identity marker for every character link, including dynamically loaded lists. */
(()=>{'use strict';
 const cache=new Map();let pending=false,busy=false,again=false;
 function reference(link){
  if(link.closest('.game-navigation,[data-character-markers="off"]')||link.matches('.action-btn,[data-icon]')||!link.textContent.trim())return null;
  try{const url=new URL(link.getAttribute('href')||'',location.href);if(url.origin===location.origin&&/^\/profili\/(?:index\.php|png\.php)?$/.test(url.pathname)){const id=Number(url.searchParams.get('Id')||url.searchParams.get('id'));if(Number.isSafeInteger(id)&&id>0)return {key:(url.pathname.endsWith('png.php')?'npc:':'pg:')+id,id};}}catch{}
  const match=((link.getAttribute('href')||'')+' '+(link.getAttribute('onclick')||'')).match(/(?:javascript:)?\bopenProfile(?:Id)?\(\s*['"]?(\d+)/i);
  return match?{key:'pg:'+Number(match[1]),id:Number(match[1])}:null;
 }
 function links(){document.querySelectorAll('.game-navigation .character-markers,[data-character-markers="off"] .character-markers').forEach(marker=>marker.remove());return [...document.querySelectorAll('a[href],button[onclick]')].map(link=>({link,ref:reference(link)})).filter(x=>x.ref);}
 function paint(link,ref,data){
  if(!reference(link))return;
  const host=link.querySelector('strong')||link;let marker=host.querySelector(':scope > .character-markers');
  const sex=data.sex==='Maschio'?'male':data.sex==='Femmina'?'female':'unknown',state=sex+':'+Boolean(data.online)+':'+data.race_id+':'+data.race+':'+Boolean(data.is_new);
  if(marker?.dataset.state===state)return;
  if(!marker){marker=document.createElement('span');marker.className='character-markers';host.append(marker);}
  marker.dataset.state=state;marker.replaceChildren();
  if(sex!=='unknown'){const icon=document.createElement('span');icon.className='character-sex-icon';icon.textContent=sex==='female'?'♀':'♂';icon.title=data.sex;icon.setAttribute('aria-label',data.sex);marker.append(icon);}
  if(Number(data.race_id)>0){const race=document.createElement('span');race.className='character-race';race.title=data.race;race.textContent=data.race;marker.append(race);}
  const dot=document.createElement('span');dot.className='character-online-dot '+(data.online?'is-online':'is-offline');dot.setAttribute('role','img');const label=ref.key.startsWith('npc:')?(data.online?'PNG attivo':'PNG non attivo'):(data.online?'Online':'Offline');dot.setAttribute('aria-label',label);dot.title=label;marker.append(dot);
  if(data.is_new&&ref.key.startsWith('pg:')){const fresh=document.createElement('span');fresh.className='character-new-player';fresh.textContent='✦';fresh.setAttribute('role','img');fresh.setAttribute('aria-label','Nuovo player: registrato da meno di 48 ore');fresh.title='Nuovo player · registrato da meno di 48 ore';marker.append(fresh);}
 }
 async function update(force=false){
  if(busy){again=true;return;}if(document.hidden)return;busy=true;
  try{
   const current=links(),missing=new Map();for(const {link,ref} of current){const hit=cache.get(ref.key);if(hit)paint(link,ref,hit.data);if(force||!hit||Date.now()-hit.time>29000)missing.set(ref.key,ref);}
   const requested=[...missing.values()];for(let offset=0;offset<requested.length;offset+=100){const chunk=requested.slice(offset,offset+100),query=new URLSearchParams({players:chunk.filter(r=>r.key.startsWith('pg:')).map(r=>r.id).join(','),npcs:chunk.filter(r=>r.key.startsWith('npc:')).map(r=>r.id).join(',')});const response=await fetch('/api/character-markers.php?'+query,{cache:'no-store',signal:AbortSignal.timeout(10000)});if(!response.ok)break;const result=await response.json();for(const ref of chunk){const data=result.items[ref.key];if(data)cache.set(ref.key,{data,time:Date.now()});}}
   for(const {link,ref} of current){const hit=cache.get(ref.key);if(link.isConnected&&hit)paint(link,ref,hit.data);}
  }catch{/* Keep the last known state during a temporary connection failure. */}finally{busy=false;if(again){again=false;schedule();}}
 }
 function schedule(){if(pending)return;pending=true;setTimeout(()=>{pending=false;update();},80);}
 function init(){update();new MutationObserver(changes=>{if(changes.some(c=>!c.target.closest?.('.character-markers')&&(c.type==='attributes'||[...c.addedNodes].some(n=>n.nodeType===1&&!n.matches?.('.character-markers')))))schedule();}).observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['href','onclick']});setInterval(()=>update(true),30000);document.addEventListener('visibilitychange',()=>update(true));}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
