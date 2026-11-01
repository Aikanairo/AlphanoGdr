/* Private relationship actions. The backend always derives the player from the session. */
(() => {
 'use strict';
 const npc=Number(document.querySelector('[data-social-npc]')?.dataset.socialNpc);if(!npc)return;
 const dossier=document.querySelector('[data-npc-knowledge]'),readOnly=dossier?.dataset.relationReadOnly==='1',player=Number(dossier?.dataset.relationPlayer);
 const text=(selector,value)=>{const el=document.querySelector(selector);if(el)el.textContent=String(value);};
 const signed=value=>Number(value)>0?'+'+Number(value):String(Number(value)||0);
 const updateMeter=(meter,value)=>{if(!meter)return;value=Math.max(-100,Math.min(100,Number(value)||0));const point=50+value/2;meter.style.setProperty('--meter-start',Math.min(50,point)+'%');meter.style.setProperty('--meter-width',Math.abs(value)/2+'%');meter.style.setProperty('--meter-point',point+'%');meter.setAttribute('aria-valuenow',value);meter.setAttribute('aria-valuetext',signed(value));};
 let refreshing=false;
 async function refresh(){
  if(refreshing||document.hidden)return;refreshing=true;
  try{
   const response=await fetch('/api/ai-social.php?action='+(readOnly?'staff_context':'profile')+'&npc_id='+encodeURIComponent(npc)+(readOnly?'&player_id='+encodeURIComponent(player):''),{cache:'no-store',credentials:'same-origin'});
   if(!response.ok)return;const profile=await response.json(),bond=profile.bond;
   if(bond){
    const panel=document.querySelector('[data-npc-bond]'),meter=document.querySelector('[data-npc-bond-meter]');
    if(panel){panel.dataset.tone=bond.tone;panel.dataset.unknown=String(bond.value===null);}
    const score=bond.signed_value??((bond.value??50)-50)*2;
    text('[data-npc-bond-value]',signed(score));text('[data-npc-bond-label]',bond.label);text('[data-npc-bond-max]','+100 · '+bond.max_label);
    updateMeter(meter,score);

   }
   text('[data-npc-relation-state]',profile.state.charAt(0).toUpperCase()+profile.state.slice(1));
   for(const name of ['encounters','interactions','meaningful'])text('[data-npc-count="'+name+'"]',profile[name]??0);
   for(const [key,metric] of Object.entries(profile.metrics??{})){
    const item=document.querySelector('[data-npc-metric="'+key+'"]');if(!item)continue;
    const meter=item.querySelector('[data-signed-meter]');updateMeter(meter,metric.value);item.dataset.unknown='false';item.dataset.metricSign=(metric.value??0)<0?'negative':'positive';
    item.querySelector('output').textContent=signed(metric.value);

   }
   if(profile.development){
    const node=(tag,content)=>{const el=document.createElement(tag);if(content!==undefined)el.textContent=content;return el;};
    const story=document.querySelector('[data-npc-development-story]'),wishes=document.querySelector('[data-npc-aspirations]');
    if(story){
     const fragment=document.createDocumentFragment();
     for(const episode of profile.development.story??[]){const item=node('li'),date=new Date(episode.created_at*1000),time=node('time',date.toLocaleString('it-IT'));time.dateTime=date.toISOString();item.append(time,node('strong',episode.category==='trauma'?'Un incontro che ha segnato il PNG':'Il legame è cambiato'),node('p',episode.summary));fragment.append(item);}
     story.replaceChildren(fragment);const empty=document.querySelector('[data-npc-development-empty]');if(empty)empty.hidden=story.children.length>0;
    }
    if(wishes){
     const fragment=document.createDocumentFragment();
     for(const wish of profile.development.aspirations??[]){const item=node('article');item.className='npc-offer';item.append(node('h4',(wish.stance==='desiderio'?'Una scelta desiderata':'Un limite dichiarato')+' · '+wish.family),node('p',wish.summary),node('blockquote',wish.evidence),node('time',new Date(wish.created_at*1000).toLocaleString('it-IT')));fragment.append(item);}
     wishes.replaceChildren(fragment);
    }
   }
   const known=document.querySelector('[data-npc-known-items]');
   if(known&&profile.knowledge?.allowed){
    const fragment=document.createDocumentFragment();
    for(const item of profile.knowledge.items??[]){
     const node=(tag,className,content)=>{const el=document.createElement(tag);if(className)el.className=className;if(content!==undefined)el.textContent=content;return el;};
     const article=node('article','revelation-card is-known'),header=node('header','revelation-card-header'),person=node('a','revelation-person');person.href=item.profile_url;person.dataset.characterMarkers='off';
     const portrait=node('img');portrait.src=item.portrait;portrait.alt='Ritratto di '+item.name;portrait.width=76;portrait.height=76;portrait.loading='lazy';
     const identity=node('span');identity.append(node('small','',item.observer_kind==='npc'?'Personaggio non giocante':'Personaggio giocante'),node('strong','',item.name));
     const markers=node('span','revelation-person-markers'),race=node('img');race.src=item.race_icon;race.alt='Razza apparente';race.width=24;race.height=24;
     const sex=node('span','revelation-sex '+(item.sex==='Femmina'?'is-female':'is-male'),item.sex==='Femmina'?'♀':'♂');sex.setAttribute('role','img');sex.setAttribute('aria-label',item.sex);markers.append(race,sex);identity.append(markers);person.append(portrait,identity);header.append(person);
     const metadata=node('dl','revelation-metadata');for(const [label,value] of [['Prima rivelazione',new Date(item.first_seen*1000).toLocaleString('it-IT')],['Luogo dell’ultima osservazione',item.place||item.chat_key]]){const row=node('div');row.append(node('dt','',label),node('dd','',value));metadata.append(row);}
     const memory=node('details','revelation-memory');memory.append(node('summary','','Il ricordo della scena'),node('p','',item.evidence));
     article.append(header,node('span','revelation-status','Conosce il segreto'),node('h3','',item.name+(readOnly?' conosce la natura di '+profile.player?.name:' conosce la tua natura')),node('p','revelation-nature','Natura rivelata: '+item.race_label),metadata,memory);fragment.append(article);
    }
    if(!fragment.childNodes.length){const empty=document.createElement('p');empty.className='npc-known-empty';empty.textContent='Nessuna rivelazione della tua natura risulta osservata da questo PNG.';fragment.append(empty);}
    known.replaceChildren(fragment);
   }
  }catch{/* Background refresh never turns a saved action into an error. */}
  finally{refreshing=false;}
 }
 window.addEventListener('focus',refresh);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
 setInterval(()=>{if(document.hasFocus())refresh();},10000);
 document.querySelectorAll('form[data-npc-action]').forEach(form=>form.addEventListener('submit',async event=>{
  event.preventDefault();if(form.dataset.busy)return;form.dataset.busy='1';
  const feedback=document.querySelector('[data-npc-feedback]');
  const buttons=[...form.querySelectorAll('button')];buttons.forEach(b=>b.disabled=true);
  const payload={...Object.fromEntries(new FormData(form)),action:form.dataset.npcAction,npc_id:npc};
  form.querySelectorAll('input[type=checkbox]').forEach(el=>payload[el.name]=el.checked?1:0);
  for(const key of ['character_age','romance_enabled','intimacy_enabled','amount','offer_id'])if(key in payload)payload[key]=Number(payload[key]);
  form.dataset.requestId ||= crypto.randomUUID();payload.request_id=form.dataset.requestId;
  try {
   const res=await fetch('/api/ai-social.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify(payload)});
   const result=await res.json();if(!res.ok)throw Error(result.error||'Operazione non riuscita.');
   feedback.textContent=result.receipt?'Movimento registrato.':'Preferenze aggiornate.';location.reload();
  }catch(error){feedback.textContent=error.message;buttons.forEach(b=>b.disabled=false);delete form.dataset.busy;}
 }));
})();
