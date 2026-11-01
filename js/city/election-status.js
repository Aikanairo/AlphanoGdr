/* Public election phases share the civic register; browsing never opens or advances a round. */
(()=>{'use strict';
 const cards=[...document.querySelectorAll('[data-city-election]')];if(!cards.length)return;
 const labels={candidacy:'Candidature aperte',voting:'Votazioni in corso',runoff:'Ballottaggio in corso',waiting:'Nessuna elezione in corso',unavailable:'Registro in preparazione'};
 const actions={candidacy:'Candidature e programmi',voting:'Vai alle votazioni',runoff:'Vai al ballottaggio'};
 const states=new Map();let busy=false;
 function render(card,state){
  states.set(card,{state,offset:state.server_time*1000-Date.now()});card.classList.toggle('is-active',state.active);
  card.querySelector('[data-city-election-phase]').textContent=labels[state.phase]||labels.unavailable;
  const count=card.querySelector('[data-city-election-candidates]');count.hidden=!state.active;count.textContent=state.candidates+' '+(state.candidates===1?'candidatura':'candidature');
  const date=card.querySelector('[data-city-election-date]');date.hidden=!state.deadline;const time=date.querySelector('time');time.dateTime=state.deadline?new Date(state.deadline*1000).toISOString():'';time.textContent=state.deadline_label;
  card.querySelector('[data-election-action-label]').textContent=actions[state.phase]||'Elezioni e candidature';
  const item=card.closest('[data-registry-item]');if(item)item.dataset.electionActive=state.active?'1':'0';
 }
 function countdown(){for(const [card,{state,offset}] of states){const el=card.querySelector('[data-city-election-countdown]');el.hidden=!state.deadline;if(!state.deadline)continue;
  const seconds=Math.max(0,Math.floor((state.deadline*1000-Date.now()-offset)/1000));const days=Math.floor(seconds/86400),hours=Math.floor(seconds%86400/3600),minutes=Math.floor(seconds%3600/60);
  el.textContent=seconds?'Mancano '+(days?days+'g ':'')+hours+'h '+minutes+'m':'Scadenza raggiunta · aggiornamento della tornata in corso';
 }}
 async function refresh(){if(busy||document.hidden)return;busy=true;try{const r=await fetch('/api/citta/elezioni.php?summary=1',{cache:'no-store'});if(!r.ok)return;const data=await r.json();for(const card of cards){const state=data.summaries.find(s=>s.city_id===Number(card.dataset.cityElection));if(state)render(card,state);}countdown();document.querySelectorAll('[data-registry]').forEach(root=>root.dispatchEvent(new Event('registry:update')));}catch{}finally{busy=false;}}
 for(const card of cards)render(card,JSON.parse(card.dataset.electionState));countdown();setInterval(countdown,1000);setInterval(refresh,30000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
})();
