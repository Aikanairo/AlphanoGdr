/* ========================================================================
 *  js/trasferimenti.js  –  front-end ordini / denaro / donazioni (PG)
 *  versione completa 2025-08-01 (aggiornata per quantità)
 * ===================================================================== */

let transferDestinatari=[],transferOrganizations=[];
const transferKeys=new Map();

/* -------------------- INIT & TAB HANDLER ------------------------------ */
document.addEventListener('DOMContentLoaded', () => {
  const tabs = ['monete','donazione','trasf','cronobj','cronmoney'];
  tabs.forEach(id=>{
    const btn=document.getElementById('m'+id);
    if(btn) btn.addEventListener('click',()=>openTab(id));
  });
  openTab(location.hash==='#donazione' || new URLSearchParams(location.search).has('organizzazione') ? 'donazione' : 'trasf');
  loadAll().then(()=>{const id=Number(new URLSearchParams(location.search).get('trade'));if(id>0)manageTrade(id);});
});

function openTab(active){
  ['monete','donazione','trasf','cronobj','cronmoney'].forEach(id=>{
    const panel=document.getElementById(id);if(panel){panel.classList.toggle('active',id===active);panel.hidden=id!==active;}
    const b=document.getElementById('m'+id);
    if(b) b.classList.toggle('active', id===active);
  });
}

/* ------------------------ LOAD ALL DATA ------------------------------- */
async function loadAll(){

  let j;
  try{
    const r=await fetch('/api/trasferimenti.php?azione=list');
    j=await r.json();
    if(!j.success) throw new Error(j.message||'Errore server');
  }catch(e){showTransferStatus('Impossibile caricare i dati. Ricarica la pagina e riprova.',true);return false;}

  ['transazioni','storicoAcq','ordini','ordiniPresi','consegne',
   'cronologiaDenaro'].forEach(k=>{
     j[k]=(j[k]||[]).map(d=>({...d,
       stato:Number(d.stato||0),
       qta:   Number(d.qta   ||0),    // ora metto sempre la quantità
       prezzo:Number(d.prezzo||0)}));
  });

  transferDestinatari=j.destinatari || [];transferOrganizations=j.organizzazioni || [];
  renderFormMonete();renderFormDonazione();
  const balance=document.getElementById('transfer-balance');
  if (balance && Number.isFinite(Number(j.saldo))) balance.textContent=String(j.saldo);
  renderTransazioni     (j.transazioni);
  renderStoricoAcq      (j.storicoAcq);
  renderOrdiniUtente    (j.ordini);
  renderOrdiniPresi     (j.ordiniPresi);
  renderMagazzino       (j.magazzino);
  renderConsegne        (j.consegne);
  renderCronologiaDenaro(j.cronologiaDenaro);
  return true;
}

/* ===================================================================== *
 * 1.  FORM DENARO
 * ===================================================================== */
function renderFormMonete(){
  document.getElementById('monete').innerHTML=`
    <h3>Trasferisci ${window.AlphanoCurrency.html("Corone")} a un personaggio</h3>
    <form class="ui-form" onsubmit="event.preventDefault();doMonete(this);return false">
      <div class="ui-form-fields"><label>Destinatario
        <select name="dest" required><option value="">Scegli un personaggio</option>${transferDestinatari.map(pg=>`<option value="${Number(pg.id)}">${commerceEscape(pg.nome)}</option>`).join('')}</select></label>
      <label>${window.AlphanoCurrency.html("Corone")} da trasferire
        <input name="soldi" type="number" min="1" max="2147483647" step="1" required></label></div>
      <label>Motivazione<textarea name="motivo" maxlength="2000"></textarea></label>
      <button type="submit">Invia ${window.AlphanoCurrency.html("Corone")}</button>
    </form>`;
}
async function doMonete(f){
  const b=f.querySelector('button'); b.disabled=true;
  try {
  const r=await postAction({azione:'monete',...Object.fromEntries(new FormData(f))});
  if(r.success) await loadAll();
  showTransferStatus(r.message || 'Operazione non riuscita.',!r.success);
  } catch(error) { showTransferStatus('Connessione interrotta. Puoi riprovare l’invio.',true); }
  finally { b.disabled=false; }
  return false;
}

/* -------------------- DONAZIONE ORG ----------------------------------- */
function renderFormDonazione(){
  const selected=Number(new URLSearchParams(location.search).get('organizzazione'));
  document.getElementById('donazione').innerHTML=`
    <h3>Rimborsa un prestito o dona a un’organizzazione</h3>
    <form class="ui-form" onsubmit="event.preventDefault();doDonazione(this);return false">
      <div class="ui-form-fields"><label>Organizzazione
        <select name="org" required onchange="renderDonationDebt(this.value)"><option value="">Scegli un’organizzazione</option>${transferOrganizations.map(org=>`<option value="${Number(org.id)}"${Number(org.id)===selected ? ' selected' : ''}>${commerceEscape(org.nome)}</option>`).join('')}</select></label>
      <label>${window.AlphanoCurrency.html("Corone")} da versare<input name="soldi" type="number" min="1" max="2147483647" step="1" required></label></div>
      <p id="donation-debt" role="status"></p><p>Il versamento rimborsa prima i prestiti più vecchi. La somma eccedente diventa una donazione.</p>
      <label>Motivazione<textarea name="motivo" maxlength="2000"></textarea></label>
      <button type="submit">Versa ${window.AlphanoCurrency.html("Corone")}</button>
    </form>`;
  renderDonationDebt(selected);
}
function renderDonationDebt(id){
  const org=transferOrganizations.find(item=>Number(item.id)===Number(id));
  document.getElementById('donation-debt').textContent=org ? 'Prestiti da rimborsare a '+org.nome+': '+Number(org.debito)+window.AlphanoCurrency.text(' Corone.') : 'Seleziona un’organizzazione per vedere il debito residuo.';
}
async function doDonazione(f){
  const b=f.querySelector('button'); b.disabled=true;
  try {
  const r=await postAction({azione:'donazione',...Object.fromEntries(new FormData(f))});
  if(r.success) await loadAll();
  showTransferStatus(r.message || 'Operazione non riuscita.',!r.success);
  } catch(error) { showTransferStatus('Connessione interrotta. Puoi riprovare l’invio.',true); }
  finally { b.disabled=false; }
  return false;
}

/* ===================== GENERIC HELPERS ================================ */
const tplTable=(h,rows)=>`<div class="ui-table-scroll" tabindex="0" role="region" aria-label="Movimenti e ordini"><table class="ui-data-table"><thead><tr>${h.map(t=>`<th scope="col">${t}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
function openProfile(id){window.openProfileId(id);}

function showTransferStatus(message,error=false){
  let feedback=document.getElementById('transfer-feedback');
  if(!feedback){
    feedback=document.createElement('p');feedback.id='transfer-feedback';feedback.className='notice';
    feedback.setAttribute('role','status');feedback.setAttribute('aria-live','polite');
    const anchor=document.getElementById('transfer-balance')?.closest('h2') || document.getElementById('trasf');
    anchor?.insertAdjacentElement('afterend',feedback);
  }
  feedback.hidden=false;feedback.textContent=message;feedback.classList.toggle('error',error);
}
function commerceValue(id,label,max=2147483647){
  const input=document.getElementById(id),raw=input?.value || '';
  if(!/^[0-9]+$/.test(raw) || Number(raw)<1 || Number(raw)>max){
    showTransferStatus('Inserisci '+label+' come numero intero tra 1 e '+max+'.',true);
    input?.setAttribute('aria-invalid','true');input?.focus();return null;
  }
  input.removeAttribute('aria-invalid');return raw;
}

async function postAction(data){
  const identity=JSON.stringify(data);
  if(!transferKeys.has(identity))transferKeys.set(identity,crypto.randomUUID());
  const response=await fetch('/api/trasferimenti.php',{
    method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({...data,_request_key:transferKeys.get(identity)})});
  const result=await response.json();if(result.success){document.body.dataset.commerceDirty='';window.alphanoCommerce?.changed();}
  // Keep the key on an uncertain transport failure, so retrying cannot debit twice.
  if(response.status<500)transferKeys.delete(identity);
  return result;
}

/* ===================================================================== *
 * 2.  CONTRATTAZIONI (verso = 1) – **AGGIORNATO PER QUANTITÀ**
 * ===================================================================== */

const commerceEscape=value=>String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const commerceInput=(id,value,label,max=2147483647)=>`<input id="${id}" type="number" min="1" max="${max}" step="1" value="${Number(value)}" aria-label="${label}">`;
const commerceButton=(action,id,text)=>`<button type="button" onclick="${action}(${Number(id)})">${text}</button>`;
function renderTransazioni(rows) {
  const panel=document.getElementById('trasf');
  if (!rows.length) {panel.innerHTML='<p>Nessuna contrattazione in corso.</p>';return;}
  const html=rows.map(d=>{
    const owner=d.isOwner!==false,state=Number(d.stato);
    const edit=owner ? [2,4].includes(state) : state===3;
    const accept=owner ? state===4 : state===3;
    const qty=Number(d.qta);
    const price=Number(d.prezzo)+window.AlphanoCurrency.html(' Corone');
    const actions=commerceButton('manageTrade',d.id,'Gestisci trattativa');
    return `<tr><td>${commerceEscape(d.data)}</td><td>${commerceEscape(d.interlocutore)}</td><td>${commerceEscape(d.nomeobj)}</td><td>${qty}</td><td>${price}</td><td>${commerceEscape(d.statoText)}</td><td>${actions}</td></tr>`;
  });
  panel.innerHTML='<h3>Compravendita di oggetti posseduti</h3><p>Il prezzo indicato è il totale per tutti i pezzi dell’offerta.</p>'+tplTable(['Data','Controparte','Oggetto','Quantità','Prezzo totale','Stato','Azioni'],html);
}
function renderStoricoAcq(rows) {
  const panel=document.getElementById('cronobj');
  if (!rows.length) {panel.innerHTML='<p>Nessuna compravendita conclusa.</p>';return;}
  const html=rows.map(d=>`<tr><td>${commerceEscape(d.data)}</td><td>${commerceEscape(d.corpName)}</td><td>${commerceEscape(d.pgcorpName || '—')}</td><td>${commerceEscape(d.nomeobj)}</td><td>${Number(d.qta)}</td><td>${Number(d.prezzo)} ${window.AlphanoCurrency.html("Corone")}${Number(d.verso)===2 ? ' / cad.' : ' totali'}</td><td>${commerceEscape(d.statoText)}</td></tr>`);
  panel.innerHTML='<h3>Cronologia oggetti e ordini</h3>'+tplTable(['Data','Corporazione','Responsabile','Oggetto','Quantità','Prezzo','Stato'],html);
}
function renderOrdiniUtente(rows) {
  const panel=document.getElementById('trasf');
  if (!rows.length) {panel.innerHTML+='<p>Nessun ordine in corso da parte tua.</p>';return;}
  const html=rows.map(d=>{
    const state=Number(d.stato),edit=state===3;
    const qty=Number(d.qta);
    const price=Number(d.prezzo)+window.AlphanoCurrency.html(' Corone');
    const actions=commerceButton('manageTrade',d.id,'Gestisci trattativa');
    return `<tr><td>${commerceEscape(d.data)}</td><td>${commerceEscape(d.corpName)}</td><td>${commerceEscape(d.pgcorpName || 'Da assegnare')}</td><td>${commerceEscape(d.nomeobj)}</td><td>${qty}</td><td>${price}</td><td>${Number(d.qta)*Number(d.prezzo)} ${window.AlphanoCurrency.html("Corone")}</td><td>${commerceEscape(d.statoText)}</td><td>${actions}</td></tr>`;
  });
  panel.innerHTML+='<h3>I miei ordini</h3><p>Il pagamento avviene alla consegna. Puoi annullare fino a quel momento.</p>'+tplTable(['Data','Corporazione','Responsabile','Oggetto','Quantità','Prezzo unitario','Totale','Stato','Azioni'],html);
}

/* ===================================================================== *
 * 5.  ORDINI PRESI IN CARICO (solo se l’utente è impiegato)
 * ===================================================================== */
function renderOrdiniPresi(rows){
  const c=document.getElementById('cronobj');
  if(!rows.length) return;

  const b=rows.map(d=>{
    const edit = d.stato <= 2;
    const qtaCell   = edit
      ? `<input id="qta_o${d.id}" size="3" value="${d.qta}">`
      : d.qta;
    const prezzoCell = edit
      ? `<input id="prezzo_o${d.id}" size="3" value="${d.prezzo}"> ${window.AlphanoCurrency.html("Corone")}`
      : `${d.prezzo} ${window.AlphanoCurrency.html("Corone")}`;
    const azioni=edit
      ? `<button onclick="updateOrder(${d.id})">Invia</button>
         <button onclick="deleteOrder(${d.id})">Elimina</button>`
      : `<button onclick="deleteOrder(${d.id})">Elimina</button>`;

    return `<tr>
      <td>${d.data}</td>
      <td><a href="#" onclick="openProfile(${d.pg})">${d.interlocutore}</a></td>
      <td>${d.nomeobj}</td>
      <td>${qtaCell}</td>
      <td>${prezzoCell}</td>
      <td>${d.statoText}</td>
      <td>${azioni}</td>
    </tr>`;
  });

  c.innerHTML+=`<h3>Ordini Presi</h3>`+
               tplTable(['Data','Cliente','Oggetto','Q.tà','Prezzo/U','Stato','Azioni'],b);
}

/* ===================================================================== *
 * 6.  MAGAZZINO, CONSEGNE, DENARO
 * ===================================================================== */
function renderMagazzino(m){
  if(!m||!Object.keys(m).length)return;
  const c=document.getElementById('cronobj');
  const h=Object.keys(m).map(k=>`<th>${k}</th>`).join('');
  const r=Object.keys(m).map(k=>`<td>${m[k]}</td>`).join('');
  c.innerHTML+=`<h3>Magazzino</h3><div class="ui-table-scroll" tabindex="0" role="region" aria-label="Magazzino"><table class="ui-data-table"><thead><tr>${h}</tr></thead><tbody><tr>${r}</tr></tbody></table></div>`;
}

function renderConsegne(rows){
  const c=document.getElementById('cronobj');
  if(!rows.length)return;
  const b=rows.map(d=>`<tr>
    <td>${d.data}</td>
    <td>${d.interlocutore}</td>
    <td>${d.nomeobj}</td>
    <td>${d.qta}</td>
    <td>${d.prezzo} ${window.AlphanoCurrency.html("Corone")}</td>
    <td>
      <button onclick="deliver(${d.id})">Consegna</button>
      <button onclick="deleteOrder(${d.id})">Elimina</button>
    </td>
  </tr>`);
  c.innerHTML+=`<h3>Consegne</h3>`+
               tplTable(['Data','Cliente','Oggetto','Q.tà','Prezzo/U','Azioni'],b);
}

function renderCronologiaDenaro(rows){
  const c=document.getElementById('cronmoney');
  if(!rows.length){c.innerHTML='<p>Nessun movimento di denaro.</p>';return;}
  const b=rows.map(d=>`<tr>
    <td>${commerceEscape(d.data)}</td>
    <td>${commerceEscape(d.azione)}</td>
    <td>${commerceEscape(d.controparte)}</td>
    <td>${d.importo} ${window.AlphanoCurrency.html("Corone")}</td>
    <td>${commerceEscape(d.motivo)}</td>
  </tr>`);
  c.innerHTML=`<h3>Cronologia Denaro</h3>`+
              tplTable(['Data','Azione','Controparte','Importo','Motivo'],b);
}

/* ===================================================================== *
 * 7.  AJAX ACTIONS
 * ===================================================================== */
let commerceBusy=false;
async function commerceRun(payload) {
  if(commerceBusy)return;
  commerceBusy=true;
  document.querySelectorAll('#trasf button').forEach(button=>button.disabled=true);
  try {
    const result=await postAction(payload);
    if(result.success)await loadAll();
    showTransferStatus(result.message || 'Operazione non riuscita.',!result.success);
  } catch(error) {showTransferStatus('Connessione interrotta. Ricarica i dati prima di riprovare.',true);}
  finally {commerceBusy=false;document.querySelectorAll('#trasf button').forEach(button=>button.disabled=false);}
}
function acceptTransf(id){return commerceRun({azione:'admintransf',id,accetta:1});}
function updateTransf(id){const prezzo=commerceValue('prz_t'+id,'il prezzo totale');if(prezzo===null)return;const qta=commerceValue('qta_t'+id,'la quantità',10000);if(qta===null)return;return commerceRun({azione:'admintransf',id,prezzo,qta});}
function deleteTransf(id){return commerceRun({azione:'admintransf',id,elimina:1});}
function acceptOrder(id){return commerceRun({azione:'adminorder',id,accetta:1});}
function updateOrder(id){const prezzo=commerceValue('prezzo_o'+id,'il prezzo unitario');if(prezzo===null)return;const qta=commerceValue('qta_o'+id,'la quantità',10000);if(qta===null)return;return commerceRun({azione:'adminorder',id,prezzo,qta});}
function deleteOrder(id){return commerceRun({azione:'adminorder',id,elimina:1});}
function deliver(id){return commerceRun({azione:'consegna',id});}
function takeOrder(id){return commerceRun({azione:'prendiincarico',id});}

function manageTrade(id){return window.alphanoCommerce.open(id);}
let personalRefreshBusy=false;document.addEventListener('alphano:commerce-refresh',async()=>{if(personalRefreshBusy||document.body.dataset.commerceDirty==='true'||document.querySelector('input:focus,select:focus,textarea:focus'))return;personalRefreshBusy=true;try{await loadAll();}finally{personalRefreshBusy=false;}});setInterval(()=>{if(!document.hidden)document.dispatchEvent(new CustomEvent('alphano:commerce-refresh'));},30000);

document.addEventListener('input',event=>{if(event.target.matches('input,select,textarea'))document.body.dataset.commerceDirty='true';});
