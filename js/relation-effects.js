/* Presentation of committed relationship receipts. Scores are never computed in the browser. */
(()=>{
 'use strict';if(!window.chatRoom)return;
 const seen=new Set(),pending=[];let host,timer,mode='private',connected=false,unsubscribe;
 const node=(tag,cls,text)=>{const el=document.createElement(tag);if(cls)el.className=cls;if(text!==undefined)el.textContent=text;return el;};
 function display(event){
  if(document.hidden||mode==='off')return;
  if(!host){host=node('div','relation-effects');host.setAttribute('aria-live','polite');host.setAttribute('aria-atomic','false');document.getElementById('chat')?.append(host);}
  while(host.children.length>=2)host.firstElementChild.remove();
  const card=node('article','relation-effect'),heading=node('header'),portrait=node('img');portrait.src=event.portrait;portrait.alt='';portrait.width=38;portrait.height=38;
  const title=node('span');title.append(node('strong','',event.npc_name),node('small','',event.own?'Il suo rapporto con te':'Il suo rapporto con '+event.player_name));heading.append(portrait,title,node('span','relation-effect-ornament','✦'));card.append(heading);
  const changes=node('div','relation-effect-changes');for(const [i,change] of event.deltas.entries()){
   const chip=node('div','relation-effect-chip');chip.dataset.emotion=change.key;chip.dataset.sign=change.delta<0?'negative':'positive';chip.style.setProperty('--emotion-delay',Math.min(i,9)*70+'ms');
   const image=node('img');image.src=change.icon;image.alt='';image.width=32;image.height=32;
   chip.append(image,node('b','',(change.delta>0?'+':'')+change.delta),node('span','',change.label));changes.append(chip);
  }
  card.append(changes);host.append(card);setTimeout(()=>card.remove(),7800);
 }
 function next(){clearTimeout(timer);timer=null;if(!pending.length)return;display(pending.shift());if(pending.length)timer=setTimeout(next,2200);}
 function consume(events){for(const event of events){if(seen.has(event.id))continue;seen.add(event.id);if(seen.size>256)seen.delete(seen.values().next().value);if(mode==='off'||document.hidden)continue;pending.push(event);if(pending.length>12)pending.shift();}if(pending.length&&!timer)next();}
 function connect(){const experience=window.alphanoExperience;if(!experience||connected)return;connected=true;unsubscribe=experience.subscribe(state=>{mode=state.settings.relationship_effects||'private';for(const select of document.querySelectorAll('[data-relation-visibility]'))select.value=mode;if(mode==='off'){pending.length=0;host?.replaceChildren();}});experience.ready().catch(()=>{});}
 document.querySelectorAll('[data-relation-visibility]').forEach(select=>select.addEventListener('change',async()=>{const status=document.querySelector('[data-relation-preference-status]');select.disabled=true;try{await window.alphanoExperience.save({relationship_effects:select.value});status.textContent='Preferenza salvata per il tuo personaggio.';}catch(error){select.value=mode;status.textContent=error.message;}finally{select.disabled=false;}}));
 window.alphanoRelationEffects={consume};connect();document.addEventListener('alphano:experience-ready',connect,{once:true});
 document.addEventListener('visibilitychange',()=>{if(document.hidden){pending.length=0;host?.replaceChildren();}});
 window.addEventListener('pagehide',()=>{unsubscribe?.();clearTimeout(timer);pending.length=0;host?.remove();});
})();
