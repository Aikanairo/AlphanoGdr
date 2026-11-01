import {presencePlaceHeading,presenceShowsNpcs,presenceSaveNpcs,presenceGameAction} from './presence-view.js?v=20261031-invites-visible';
/* La ricerca filtra soltanto i dati pubblici già presenti nella pagina. */
(() => {
 'use strict';
 const init=()=>{
  const search=document.getElementById('presence-search'),kind=document.getElementById('presence-kind');
  if(!search||!kind)return;
  const owner=document.querySelector('[data-presence-owner]').dataset.presenceOwner,toggle=document.getElementById('presence-show-npcs');
  toggle.checked=presenceShowsNpcs(owner);
  let places=Array.from(document.querySelectorAll('.presence-place'));
  function filter(){
   const query=search.value.trim().toLocaleLowerCase('it');let count=0,visiblePlaces=0;
   places.forEach(place=>{
    let visible=0;
    place.querySelectorAll('.presence-person').forEach(person=>{
     const show=(toggle.checked||person.dataset.kind==='player')&&(kind.value==='all'||person.dataset.kind===kind.value)&&person.dataset.search.toLocaleLowerCase('it').includes(query);
     person.hidden=!show;if(show)visible++;
    });
    const show=visible>0||(!query&&kind.value==='all'&&(toggle.checked||place.dataset.sessionActive==='1')); place.hidden=!show;if(show)visiblePlaces++;
    count+=visible;
   });
   document.getElementById('presence-results').textContent=`${count} ${count===1?'personaggio':'personaggi'} · ${visiblePlaces} ${visiblePlaces===1?'luogo':'luoghi'}`;
   document.getElementById('presence-no-results').hidden=count>0||visiblePlaces>0;
  }
  toggle.addEventListener('change',()=>{presenceSaveNpcs(owner,toggle.checked);filter();});
  window.addEventListener('storage',event=>{if(event.key==='alphano:presence:npcs:'+owner){toggle.checked=presenceShowsNpcs(owner);filter();}});
  search.addEventListener('input',filter);kind.addEventListener('change',filter);filter();
  const refresh=document.getElementById('presence-refresh'),status=document.getElementById('presence-refresh-status');
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function update(){
   if(refresh.disabled||document.hidden)return;
   refresh.disabled=true;
   try{
    const response=await fetch('/api/presenti.php',{cache:'no-store',signal:AbortSignal.timeout(10000)});if(!response.ok)throw new Error('Non disponibile');
    const data=await response.json();
    document.querySelector('.presence-places').innerHTML=data.places.map(place=>`
     <section class="presence-place" data-place="${esc(place.title)}" data-session-active="${place.session_active?1:0}"><header><div><div class="ui-eyebrow">Luogo di gioco</div><h2>${presencePlaceHeading(place)}</h2></div><span class="presence-place-count">${place.players} PG · ${place.npcs} PNG</span></header>
     <div class="ui-table-scroll"><table class="ui-data-table presence-table"><thead><tr><th scope="col">Personaggio</th><th scope="col">Razza e sesso</th><th scope="col">Tipo</th><th scope="col">Azioni</th></tr></thead><tbody>${place.members.map(person=>`<tr class="presence-person" data-kind="${esc(person.kind)}" data-search="${esc(person.name+' '+person.race+' '+place.title)}"><td><a href="${esc(person.profile_url)}">${esc(person.name)}</a></td><td><span class="ui-person"><img src="${esc(person.icon)}" class="race-emblem" width="28" height="28" alt="${esc(person.race+' · '+person.sex)}" title="${esc(person.race+' · '+person.sex)}"></span></td><td>${person.kind==='npc'?'PNG':'Giocatore'}</td><td><div class="ui-row-actions"><a class="action-btn" href="${esc(person.profile_url)}" data-icon="user">Profilo</a>${presenceGameAction(person)}${person.kind==='player'?`<a class="action-btn" href="/posta/writeto.php?pgName=${encodeURIComponent(person.name)}&amp;idpg=${person.id}" data-icon="mail">Scrivi</a>`:''}</div></td></tr>`).join('')}</tbody></table></div>${place.members.length?'':'<p class="presence-empty">Nessun personaggio presente.</p>'}</section>`).join('');
    const summary=document.querySelector('.presence-summary');summary.children[0].innerHTML=`<strong>${Number(data.players)}</strong> ${data.players===1?'giocatore':'giocatori'}`;summary.children[1].innerHTML=`<strong>${Number(data.npcs)}</strong> PNG nei luoghi`;
    places=Array.from(document.querySelectorAll('.presence-place'));toggle.checked=presenceShowsNpcs(owner);filter();status.textContent='Presenze aggiornate';
   }catch{status.textContent='Aggiornamento non disponibile. Riprova.';}finally{refresh.disabled=false;}
  }
  refresh.addEventListener('click',update);setInterval(update,30000);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)update();});
  window.addEventListener('pageshow',update);window.addEventListener('online',update);
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
