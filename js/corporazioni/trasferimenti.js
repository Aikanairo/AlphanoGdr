/* Ordini, acquisti e produzione della propria corporazione. */
const tradeTabs = ['personal','stacq','corp','stven','produzione','storico'];
const tradeEscape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const tradeNumber = (id, value, label, max=2147483647) => `<input id="${id}" type="number" min="1" max="${max}" step="1" value="${Number(value)}" aria-label="${label}">`;
const tradeButton = (action, id, text) => `<button type="button" onclick="${action}(${Number(id)})">${text}</button>`;
const tradeProfile = (id, name) => id ? `<a href="/profili/index.php?Id=${Number(id)}" onclick="event.preventDefault();openProfile(${Number(id)})">${tradeEscape(name)}</a>` : tradeEscape(name || '—');
let tradeBusy = false;

document.addEventListener('DOMContentLoaded', () => {
  tradeTabs.forEach(id => document.getElementById('m'+id)?.addEventListener('click', () => openTab(id)));
  openTab(location.hash.slice(1) || 'corp');
  window.addEventListener('hashchange',()=>openTab(location.hash.slice(1) || 'corp'));
  loadAll().then(()=>{const id=Number(new URLSearchParams(location.search).get('trade'));if(id>0)manageTrade(id);});
});
function openTab(active) {
  if (!document.getElementById(active)) active='personal';
  if(location.hash!=='#'+active)history.replaceState(null,'','#'+active);
  tradeTabs.forEach(id => {
    const panel=document.getElementById(id), button=document.getElementById('m'+id);
    if (panel) { panel.hidden=id!==active; panel.classList.toggle('active',id===active); }
    if (button) {button.classList.toggle('active',id===active);button.setAttribute('aria-selected',String(id===active));}
  });
}
function tplTable(headers, rows) {
  return `<div class="ui-table-scroll" tabindex="0" role="region" aria-label="Trasferimenti della corporazione"><table class="ui-data-table"><thead><tr>${headers.map(h=>`<th scope="col">${h}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
}
function tradeSection(id,title,headers,rows,empty) {
  const panel=document.getElementById(id);
  if (panel) panel.innerHTML=`<h3>${title}</h3>`+(rows.length ? tplTable(headers,rows) : `<p>${empty}</p>`);
}
async function loadAll() {
  try {
    const response=await fetch('/api/corporazioni/trasferimenti.php?azione=list&corp='+encodeURIComponent(document.body.dataset.corporation||''));
    const data=await response.json();
    if (!response.ok || !data.success) throw Error(data.message || 'Dati non disponibili');
    const balance=document.getElementById('corporate-trade-balance');
    if (balance) balance.textContent=String(data.budget);
    renderTransazioni(data.transazioni || []);
    renderStoricoAcq(data.storicoAcq || []);
    renderOrdiniClienti(data.ordini || []);
    renderOrdiniPresi(data.ordiniPresi || []);
    renderMagazzino(data.magazzino || []);
    renderConsegne(data.consegne || []);
    renderStoricoVendite(data.storicoVen || []);
    tradeCount('stven',data.ordiniPresi?.length||0,'ordini in carico');tradeCount('produzione',data.consegne?.length||0,'ordini da consegnare');
    return true;
  } catch (error) { tradeStatus('Impossibile caricare i trasferimenti. Ricarica la pagina e riprova.',true); return false; }
}
function renderTransazioni(rows) {
  tradeSection('personal','Acquisti dai giocatori',['Data','Venditore','Oggetto','Quantità','Prezzo totale','Stato','Azioni'],rows.map(d=>{
    const ready=Number(d.stato)===3;
    const qty=Number(d.qta);
    const price=Number(d.prezzo)+window.AlphanoCurrency.html(' Corone');
    const actions=tradeButton('manageTrade',d.id,'Gestisci trattativa');
    return `<tr><td>${tradeEscape(d.data)}</td><td>${tradeProfile(d.pg,d.interlocutore)}</td><td>${tradeEscape(d.nomeobj)}</td><td>${qty}</td><td>${price}</td><td>${tradeEscape(d.statoText)}</td><td>${actions}</td></tr>`;
  }),'Nessuna contrattazione in corso.');
}
function historyRows(rows, unit=false) {
  return rows.map(d=>`<tr><td>${tradeEscape(d.data)}</td><td>${tradeEscape(d.pgcorpName || '—')}</td><td>${tradeProfile(d.pg,d.interlocutore)}</td><td>${tradeEscape(d.nomeobj)}</td><td>${Number(d.qta)}</td><td>${Number(d.prezzo)} ${window.AlphanoCurrency.html("Corone")}${unit ? ' / cad.' : ' totali'}</td><td>${tradeEscape(d.statoText)}</td></tr>`);
}
function renderStoricoAcq(rows) {tradeSection('stacq','Storico acquisti',['Data','Responsabile','Venditore','Oggetto','Quantità','Prezzo totale','Stato'],historyRows(rows),'Nessun acquisto concluso.');}
function renderStoricoVendite(rows) {tradeSection('storico','Storico ordini e vendite',['Data','Responsabile','Cliente','Oggetto','Quantità','Prezzo unitario','Stato'],historyRows(rows,true),'Nessun ordine concluso.');}
function renderOrdiniClienti(rows) {
  tradeSection('corp','Ordini clienti',['Data','Responsabile','Cliente','Oggetto','Quantità','Prezzo unitario','Stato','Azioni'],rows.map(d=>{
    const taken=Number(d.pgcorp)>0;
    const actions=tradeButton('manageTrade',d.id,'Gestisci trattativa');
    return `<tr><td>${tradeEscape(d.data)}</td><td>${taken ? tradeProfile(d.pgcorp,d.pgcorpName) : 'Da assegnare'}</td><td>${tradeProfile(d.pg,d.interlocutore)}</td><td>${tradeEscape(d.nomeobj)}</td><td>${Number(d.qta)}</td><td>${Number(d.prezzo)} ${window.AlphanoCurrency.html("Corone")}</td><td>${tradeEscape(d.statoText)}</td><td>${actions}</td></tr>`;
  }),'Nessun ordine cliente in corso.');
}
function renderOrdiniPresi(rows) {
  tradeSection('stven','I miei ordini in carico',['Data','Cliente','Oggetto','Quantità','Prezzo unitario','Totale','Stato','Azioni'],rows.map(d=>{
    const state=Number(d.stato), edit=[1,2].includes(state);
    const qty=Number(d.qta);
    const price=Number(d.prezzo)+window.AlphanoCurrency.html(' Corone');
    const actions=tradeButton('manageTrade',d.id,'Gestisci trattativa');
    return `<tr><td>${tradeEscape(d.data)}</td><td>${tradeProfile(d.pg,d.interlocutore)}</td><td>${tradeEscape(d.nomeobj)}</td><td>${qty}</td><td>${price}</td><td>${Number(d.qta)*Number(d.prezzo)} ${window.AlphanoCurrency.html("Corone")}</td><td>${tradeEscape(d.statoText)}</td><td>${actions}</td></tr>`;
  }),'Nessun ordine assegnato a te.');
}
function tradeCount(tab,count,label){const button=document.getElementById('m'+tab);if(!button)return;button.dataset.commerceCount='true';let badge=button.querySelector('.notification-badge');if(!badge){badge=document.createElement('span');badge.className='notification-badge';button.append(badge);}badge.hidden=!count;badge.textContent=String(count);badge.setAttribute('aria-label',`${count} ${label}`);}
const materialLayout=new ResizeObserver(entries=>{for(const {target} of entries){const count=target.children.length,cols=Math.min(count,Math.max(Math.ceil(count/2),Math.floor(target.parentElement.clientWidth/96)));target.style.gridTemplateColumns=`repeat(${Math.max(1,cols)},minmax(76px,1fr))`;if(target.parentElement.dataset.restoreScroll){target.parentElement.scrollLeft=Number(target.parentElement.dataset.restoreScroll);delete target.parentElement.dataset.restoreScroll;}}});
function renderMagazzino(rows) {
  const panel=document.getElementById('produzione');if(!panel)return;const scrollLeft=panel.querySelector('.commerce-material-scroll')?.scrollLeft||0;panel.querySelectorAll('.commerce-material-grid').forEach(el=>materialLayout.unobserve(el));
  const stock=rows.filter(d=>Number(d.qta)>0);
  panel.innerHTML='<h3>Magazzino materie prime</h3>'+(stock.length?`<div class="commerce-material-scroll" role="region" aria-label="Materiali disponibili" tabindex="0"><div class="commerce-material-grid">${stock.map(d=>`<figure class="commerce-material"><div class="armory-item"><img src="${tradeEscape(d.image)}" alt="${tradeEscape(d.name||d.materia)}" loading="lazy"><b class="armory-quantity" aria-label="Quantità ${Number(d.qta)}">x${Number(d.qta)}</b></div><figcaption>${tradeEscape(d.name||d.materia)}</figcaption></figure>`).join('')}</div></div>`:'<p>Magazzino vuoto.</p>');
  const grid=panel.querySelector('.commerce-material-grid');if(grid){grid.parentElement.dataset.restoreScroll=String(scrollLeft);materialLayout.observe(grid);}
}
function renderConsegne(rows) {
  const panel=document.getElementById('produzione');if (!panel)return;
  panel.insertAdjacentHTML('beforeend','<h3>Ordini da consegnare</h3><p>La consegna produce gli oggetti, consuma le materie prime e addebita al cliente il totale concordato.</p>'+(rows.length ? tplTable(['Data','Cliente','Oggetto','Quantità','Prezzo unitario','Totale','Azioni'],rows.map(d=>`<tr><td>${tradeEscape(d.data)}</td><td>${tradeProfile(d.pg,d.interlocutore)}</td><td><span class="commerce-delivery-item"><img src="${tradeEscape(d.image)}" alt="" loading="lazy"><strong>${tradeEscape(d.nomeobj)}</strong></span></td><td>${Number(d.qta)}</td><td>${Number(d.prezzo)} ${window.AlphanoCurrency.html("Corone")}</td><td>${Number(d.qta)*Number(d.prezzo)} ${window.AlphanoCurrency.html("Corone")}</td><td>${tradeButton('manageTrade',d.id,'Gestisci consegna')}</td></tr>`)) : '<p>Nessuna consegna assegnata a te.</p>'));
}
function openProfile(id){window.openProfileId(id);}
function tradeStatus(message,error=false) {
  const feedback=document.getElementById('corporate-trade-feedback');
  if(feedback){feedback.hidden=false;feedback.textContent=message;feedback.classList.toggle('error',error);}
}
function tradeValue(id,label,max=2147483647) {
  const input=document.getElementById(id),raw=input?.value || '';
  if(!/^[0-9]+$/.test(raw) || Number(raw)<1 || Number(raw)>max){
    tradeStatus('Inserisci '+label+' come numero intero tra 1 e '+max+'.',true);
    input?.setAttribute('aria-invalid','true');input?.focus();return null;
  }
  input.removeAttribute('aria-invalid');return raw;
}
async function postAction(payload) {
  if (tradeBusy) return;
  tradeBusy=true;
  document.querySelectorAll('button').forEach(b=>b.disabled=true);
  tradeStatus('Operazione in corso…');
  try {
    const response=await fetch('/api/corporazioni/trasferimenti.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({...payload,corp:document.body.dataset.corporation||''})});
    const data=await response.json();
    if(!response.ok || !data.success){tradeStatus(data.message || data.msg || 'Operazione non riuscita.',true);return;}
    document.body.dataset.commerceDirty='';window.alphanoCommerce?.changed();const refreshed=await loadAll();
    tradeStatus((data.message || data.msg || 'Operazione completata.')+(refreshed===false ? ' Ricarica la pagina per aggiornare i dati.' : ''));
    return data;
  } catch(error) {tradeStatus('Connessione interrotta. Ricarica i dati prima di riprovare.',true);}
  finally {tradeBusy=false;document.querySelectorAll('button').forEach(b=>b.disabled=false);}
}
async function takeOrder(id){const result=await postAction({azione:'prendiincarico',id});if(result?.success){openTab('stven');tradeStatus('Ordine preso in carico: puoi proporre il prezzo da I miei ordini.');}return result;}
function acceptTransf(id){return postAction({azione:'admintransf',id,accetta:1});}
function updateTransf(id){const prezzo=tradeValue('prz_t'+id,'il prezzo totale');if(prezzo===null)return;const qta=tradeValue('qta_t'+id,'la quantità',10000);if(qta===null)return;return postAction({azione:'admintransf',id,prezzo,qta});}
function deleteTransf(id){return postAction({azione:'admintransf',id,elimina:1});}
function acceptOrder(id){return postAction({azione:'adminorder',id,accetta:1});}
function updateOrder(id){const prezzo=tradeValue('prezzo_o'+id,'il prezzo unitario');if(prezzo===null)return;const qta=tradeValue('qta_o'+id,'la quantità',10000);if(qta===null)return;return postAction({azione:'adminorder',id,prezzo,qta});}
function deleteOrder(id){return postAction({azione:'adminorder',id,elimina:1});}
function deliver(id){return postAction({azione:'consegna',id});}

function manageTrade(id){return window.alphanoCommerce.open(id);}
let corporateRefreshBusy=false;document.addEventListener('alphano:commerce-refresh',async event=>{if(event.detail?.action==='take'&&Number(event.detail.corp)===Number(document.body.dataset.corporation)){openTab('stven');tradeStatus('Ordine #'+Number(event.detail.id)+' preso in carico. Prosegui con Gestisci trattativa.');}if(tradeBusy||corporateRefreshBusy||document.body.dataset.commerceDirty==='true'||document.activeElement?.matches('input,select,textarea'))return;corporateRefreshBusy=true;try{await loadAll();}finally{corporateRefreshBusy=false;}});setInterval(()=>{if(!document.hidden)document.dispatchEvent(new CustomEvent('alphano:commerce-refresh'));},30000);

document.addEventListener('input',event=>{if(event.target.matches('input,select,textarea'))document.body.dataset.commerceDirty='true';});
