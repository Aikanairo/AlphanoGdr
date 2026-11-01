// js/corporazioni/catalogo.js

const Catalogo = (function(){
  // ─── VAR DI STATO ───────────────────────────────────────────────────────────
  let catalogo        = [];
  let catalogoPers    = [];
  let catalogoPersOrg = [];
  let riparazioni     = [];
  let tipi            = [];
  let taglie          = [];
  let organizzazioni  = [];
  let materie         = [];
  let nome            = '';
  let organizzazione  = '';
  let magicimg        = '';
  let approvedimg     = '';
  let oldtree         = 'Catalogo';
  let corpId;
  let isAdminCatalogo = false;
  let isAdminCorp     = false;
  let busy=false;
  const pendingKeys=new Map();

  // ─── INIT E CARICAMENTO DATI ─────────────────────────────────────────────────
  function init(options) {
    corpId           = options.corp;
    nome             = options.nome           || '';
    organizzazione   = options.organizzazione || '';
    magicimg         = options.magicimg       || '/assets/placeholder.svg';
    approvedimg      = options.approvedimg    || '/assets/placeholder.svg';
    taglie           = options.taglie         || [];
    organizzazioni   = options.organizzazioni || [];
    materie          = options.materie        || [];
    isAdminCatalogo  = !!options.admincatalogo;
    isAdminCorp      = !!options.admincorp;
    tipi             = options.tipi           || [];

    fetchData(corpId)
    .then(data => {
    catalogo        = data.catalogo       || [];
    catalogoPers    = data.catalogoPers   || [];
    catalogoPersOrg = data.catalogoPersOrg|| [];
    riparazioni     = data.riparazioni    || [];
    tipi            = data.tipi           || [];
    isAdminCatalogo = !!data.admincatalogo;
    isAdminCorp     = !!data.admincorp;
    const repairTab=document.getElementById('Riparazioni');if(repairTab)repairTab.hidden=!data.canRepair;
    const departments=new Map([...catalogo,...catalogoPers,...catalogoPersOrg].map(o=>[o.department||String(o.production),o.department||({15:'Armi bianche e da taglio',2:'Armi da fuoco',5:'Armature e scudi',1:'Animali',7:'Attrezzi'})[o.production]||'Altri manufatti']));document.getElementById('catalogue-department')?.replaceChildren(new Option('Tutti i reparti',''),...[...departments].map(([k,v])=>new Option(v,k)));
    setupTabs();
    for(const id of ['catalogue-search','catalogue-department'])document.getElementById(id)?.addEventListener('input',()=>Cambia(oldtree));
    Cambia(oldtree);
    })
      .catch(err => {const panel=document.getElementById('corpo');if(panel)panel.textContent='Catalogo non disponibile: '+err.message;});
  }

  function fetchData(corp) {
    return fetch(`/api/corporazioni/catalogo.php?corp=${encodeURIComponent(corp)}`, {
      headers: { 'Accept':'application/json' }
    })
    .then(res => {
      if (!res.ok) throw new Error(res.statusText);
      return res.json();
    }).then(data=>{if(data.error)throw Error(data.message || 'Catalogo non disponibile');return data;});
  }

  // ─── SETUP UI ────────────────────────────────────────────────────────────────
  function setupTabs() {
    ['Catalogo','CatalogoPers','CatalogoPersOrg','Riparazioni'].forEach(view=>{
      const td = document.getElementById(view);
      if (!td) return;
      td.onclick = () => Cambia(view);
    });
    const chk = document.getElementById('chkShowAll');
    if (chk) {chk.onchange=()=>MostraTutti(chk.checked);chk.closest('label').hidden=!isAdminCatalogo;}
    const btnAdd = document.getElementById('btnAdd');
    if (btnAdd) {btnAdd.onclick=AggiungiNuovo;btnAdd.hidden=!isAdminCatalogo;}
    const btnBack = document.getElementById('btnBack');
    if (btnBack) btnBack.onclick = ()=>{location.href='/corporazioni/';};
  }

  // ─── NAVIGAZIONE ─────────────────────────────────────────────────────────────
  function Cambia(view) {

    oldtree = view;
    ['Catalogo','CatalogoPers','CatalogoPersOrg','Riparazioni'].forEach(id=>{const button=document.getElementById(id);if(button){button.classList.toggle('active',id===view);button.setAttribute('aria-selected',String(id===view));}});
    const fn = Catalogo['Carica'+view];
    if (typeof fn === 'function') fn();
    else console.error(`Catalogo.Carica${view} non è una funzione`);
  }

  // ─── RENDERING LISTE ──────────────────────────────────────────────────────────
  function CaricaCatalogo()        { renderList(catalogo,        'tipo',         'shortDesc'); }
  function CaricaCatalogoPers()    { renderList(catalogoPers,    'destinatario', 'shortDesc'); }
  function CaricaCatalogoPersOrg() { renderList(catalogoPersOrg, 'destorg',      'shortDesc'); }
  function CaricaRiparazioni() { renderList(riparazioni,'tipo','shortDesc'); }

  function renderList(arr, groupKey, labelKey) {
    const lista = document.getElementById('lista'),
          corpo = document.getElementById('corpo');
    lista.innerHTML = '';
    corpo.innerHTML = '';
    if (!arr.length) {
      const count=document.getElementById('catalogue-count');if(count)count.textContent='0 articoli';
      lista.innerHTML = `<p class="ui-empty">Nessun oggetto in questa sezione.</p>`;
      return;
    }
    const source=arr,search=(document.getElementById('catalogue-search')?.value||'').toLocaleLowerCase(),department=document.getElementById('catalogue-department')?.value||'';
    arr=arr.filter(o=>(!department||(o.department||String(o.production))===department)&&(!search||(o.shortDesc+' '+o.tipo+' '+o.desc).toLocaleLowerCase().includes(search)));
    const count=document.getElementById('catalogue-count');if(count)count.textContent=arr.length+' articoli';
    if(!arr.length){lista.innerHTML='<p class="ui-empty">Nessun articolo corrisponde ai filtri.</p>';return;}
    const departments={15:'Armi bianche e da taglio',2:'Armi da fuoco',5:'Armature e scudi'};
    const map = {};
    arr.forEach(o=>{
      const k = groupKey==='tipo'?(o.department||departments[o.production]||o[groupKey]||'Altri manufatti'):o[groupKey]||'';
      (map[k] = map[k]||[]).push(o);
    });
    let html = '<div class="catalogue-index">';
    for (const g in map) {
      html += `<section class="catalogue-category"><h2>${escapeHtml(g||'Oggetti')}</h2><ul>`;
      map[g]
        .sort((a,b)=>a[labelKey].localeCompare(b[labelKey]))
        .forEach(o=>{
          const idx = source.indexOf(o);
          html += `<li><button type="button" data-ui-no-icon data-catalogue-item="${idx}">${o.immagine?`<img src="${escapeHtml(o.immagine)}" alt="" loading="lazy">`:''}<span><strong>${escapeHtml(o[labelKey])}</strong><small>${escapeHtml(o.tipo||'')} · ${Number(o.peso||0)} kg</small><span class="catalogue-quality">${escapeHtml(o.quality||'')} · ${o.approvato?(o.rules?.kind==='animal'?(o.available>0?o.available+' disponibili':'Allevamento esaurito'):'Su ordinazione'):'In revisione'}</span></span><span aria-hidden="true">›</span></button></li>`;
        });
      html += '</ul></section>';
    }
    html += '</div>';
    lista.innerHTML = html;
    lista.onclick=event=>{const button=event.target.closest('[data-catalogue-item]');if(!button)return;lista.querySelectorAll('[aria-current]').forEach(el=>el.removeAttribute('aria-current'));button.setAttribute('aria-current','true');Mostra(Number(button.dataset.catalogueItem));};
    corpo.innerHTML='<p class="ui-empty">Seleziona un oggetto per consultarne dettagli e ordinazione.</p>';
  }

  // ─── MOSTRA DETTAGLI ─────────────────────────────────────────────────────────
  function Mostra(idx) { if(oldtree==='Riparazioni'){if(riparazioni[idx])renderRepair(riparazioni[idx]);return;} const rows=oldtree==='CatalogoPers' ? catalogoPers : oldtree==='CatalogoPersOrg' ? catalogoPersOrg : catalogo; if(rows[idx])renderDetails(rows[idx]); }
  function MostraRip(idx) { renderRepair(riparazioni[idx]); }

  function materialsTable(values) {
    const rows=Object.entries(values || {}).filter(([,quantity])=>Number(quantity)>0);
    return rows.length ? `<div class="ui-table-scroll" tabindex="0" role="region" aria-label="Materie prime necessarie"><table class="ui-data-table"><thead><tr><th scope="col">Materia</th><th scope="col">Quantità</th></tr></thead><tbody>${rows.map(([material,quantity])=>`<tr><td>${escapeHtml(material)}</td><td>${Number(quantity)}</td></tr>`).join('')}</tbody></table></div>` : '<p>Nessuna materia prima richiesta.</p>';
  }
  function renderDetails(o) {
    const panel=document.getElementById('corpo');
    const rules=o.rules||{};const ruleFacts=(rules.kind==='animal'?`<dt>Allevamento</dt><dd>${Number(o.available)} disponibili · addestramento ${Number(rules.training||0)}/3</dd><dt>Carico massimo</dt><dd>${Number(rules.capacity||0)} kg</dd>`:rules.kind==='container'?`<dt>Capacità</dt><dd>${Number(rules.capacity)} kg</dd>`:rules.kind?`<dt>Dosi / cariche</dt><dd>${Number(rules.charges)}</dd><dt>Ricarica / recupero</dt><dd>${Number(rules.recharge_rounds)} / ${Number(rules.cooldown_rounds)} turni</dd>`:'');
    const orderForm=o.approvato ? `<form id="catalogo-order-${o.id}" class="ui-form" method="post" action="/api/corporazioni/catalogo.php" onsubmit="event.preventDefault();Catalogo.OrdinaObj(${o.id},this);return false" aria-label="Ordina ${escapeHtml(o.shortDesc)}">
      <input type="hidden" name="azione" value="ordina"><input type="hidden" name="id" value="${o.id}"><input type="hidden" name="corp" value="${corpId}">
      <label for="catalogo-order-quantity-${o.id}">Quantità</label>
      <input id="catalogo-order-quantity-${o.id}" name="qta" type="number" min="1" max="10000" step="1" value="1" required aria-describedby="catalogo-order-feedback-${o.id}">
      <button type="submit">Ordina oggetto</button>
      <p id="catalogo-order-feedback-${o.id}" class="notice" role="status" aria-live="polite" hidden></p>
    </form>` : '<p>Oggetto non ancora approvato.</p>';
    let actions='';
    if(isAdminCatalogo || isAdminCorp)actions+=`<button type="button" onclick="Catalogo.ModificaObj(${o.id})">Modifica</button>`;
    if(isAdminCorp)actions+=`<button type="button" onclick="Catalogo.CancellaObj(${o.id})">Ritira dal catalogo</button>`;
    panel.innerHTML=`<article class="ui-form catalogue-detail"><button type="button" class="catalogue-back" data-ui-no-icon onclick="document.getElementById('lista').scrollIntoView({block:'start'})">← Torna alla vetrina</button><h2>${escapeHtml(o.shortDesc)}</h2><div class="equipment-detail ui-reading"><img src="${escapeHtml(o.immagine || '/assets/placeholder.svg')}" alt="${escapeHtml(o.shortDesc)}" onload="Catalogo.Resize(this)"><p>${escapeHtml(o.desc).replace(/\n/g,'<br>')}</p></div><dl class="catalogue-facts">${ruleFacts}<dt>Tipo</dt><dd>${escapeHtml(o.tipo)}</dd><dt>Taglia</dt><dd>${escapeHtml(o.taglia)}</dd><dt>Peso</dt><dd>${Number(o.peso)} kg</dd><dt>Qualità e durata</dt><dd>${escapeHtml(o.quality)} · ${Number(o.durata)}</dd>${o.destinatario ? `<dt>Destinatario</dt><dd>${escapeHtml(o.destinatario)}</dd>` : ''}${o.destorg ? `<dt>Organizzazione</dt><dd>${escapeHtml(o.destorg)}</dd>` : ''}${o.lavorazione ? `<dt>Lavorazione</dt><dd>${escapeHtml(o.lavorazione)}</dd>` : ''}</dl><h3>Materie per pezzo</h3>${materialsTable(o.materie)}<p>La corporazione proporrà prezzo e quantità. Il pagamento avviene alla consegna.</p>${orderForm}<div class="ui-actions">${actions}</div></article>`;
    if(matchMedia('(max-width:760px)').matches)panel.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion:reduce)').matches?'auto':'smooth',block:'start'});
  }
  function renderRepair(o) {
    document.getElementById('corpo').innerHTML=`<article class="ui-form"><h2>Ripara ${escapeHtml(o.shortDesc)}</h2><p>Danneggiamento: ${Number(o.damage)}%. La riparazione consuma queste materie dal magazzino della tua corporazione.</p>${materialsTable(o.materie)}<button type="button" onclick="Catalogo.RiparaObj(${o.id})">Ripara oggetto</button></article>`;
  }
  function OrdinaObj(id,form=document.getElementById('catalogo-order-'+id)) {
    if(!form || form.dataset.completed==='true')return;
    const input=form.elements.namedItem('qta');
    const raw=input.value;
    if(!/^[0-9]+$/.test(raw) || Number(raw)<1 || Number(raw)>10000){
      input.setAttribute('aria-invalid','true');
      showFeedback('Inserisci una quantità intera tra 1 e 10000.',true,form);window.alphanoCommerce?.notify('Inserisci una quantità intera tra 1 e 10000.',true);
      input.focus();return;
    }
    input.removeAttribute('aria-invalid');
    return postAction({azione:'ordina',id,qta:Number(raw)},form);
  }
  function RiparaObj(id) {
    return postAction({azione:'ripara',id});
  }
  function AggiungiNuovo() {
    buildForm({
      id:0, shortDesc:'', desc:'', tipo:'', taglia:'', peso:0,
      mani:1, sacro:false, restore:0, lavorazione:'',
      destinatario:'', destorg:0, materie:{}, approvato:true
    });
  }
  function ModificaObj(id) {
    let o = catalogo.find(x=>x.id===id)
         || catalogoPers.find(x=>x.id===id)
         || catalogoPersOrg.find(x=>x.id===id);
    if (o) buildForm(o);
  }
  async function CancellaObj(id) {
    if (await window.alphanoDialog.notice({title:'Ritira dal catalogo',message:'Vuoi ritirare questo oggetto dalla vetrina?',buttonLabel:'Ritira',cancelLabel:'Indietro'})!=='ack') return;
    postAction({ azione:'cancellaobj', id });
  }
  function MostraTutti(v) {
    Cambia(oldtree);
  }

  // ─── FORM DINAMICO ────────────────────────────────────────────────────────────
  function buildForm(o) {
    o = {...o, tipo:o.tipoId || o.tipo, taglia:o.tagliaId ?? o.taglia, destorg:o.destorgId ?? o.destorg};
    //  Se arriva o.tipo come stringa, trasformalo in ID
    if (typeof o.tipo === 'string') {
      const found = tipi.find(t=>t.nome===o.tipo);
      o.tipo = found ? found.id : (tipi[0] && tipi[0].id) || 0;
    }
    // Garantisco che tipo e taglia siano interi
    if (!Number.isInteger(o.tipo)) o.tipo = tipi.length>0 ? tipi[0].id : 0;
    if (!Number.isInteger(o.taglia)) o.taglia = 0;

    const corpo = document.getElementById('corpo');
    const options=(list,current)=>list.map((item,index)=>{const id=typeof item==='object'?item.id:index;const label=typeof item==='object'?item.nome:item;return `<option value="${Number(id)}"${Number(id)===Number(current)?' selected':''}>${escapeHtml(label)}</option>`;}).join('');
    const html=`<form enctype="multipart/form-data" id="objForm" class="ui-form catalogue-edit" data-ui-fields="true">
      <input type="hidden" name="azione" value="${o.id?'modifica':'aggiungi'}"><input type="hidden" name="id" value="${Number(o.id||0)}"><input type="hidden" name="corp" value="${corpId}">
      <h2>${o.id?'Modifica oggetto':'Nuovo oggetto'}</h2>
      <div class="ui-form-fields">
      <label>Nome<input name="shortDesc" value="${escapeHtml(o.shortDesc||'')}" maxlength="255" required></label>
      <label>Immagine<input type="file" name="immagine" accept="image/*"><input type="hidden" name="imgurl" value="${escapeHtml(o.immagine||'')}"></label>
      <label class="catalogue-full">Descrizione<textarea name="desc" rows="6" required>${escapeHtml(o.desc||'')}</textarea></label>
      <label>Tipo<select name="tipo" required>${options(tipi,o.tipo)}</select></label>
      <label>Taglia<select name="taglia" required>${options(taglie,o.taglia)}</select></label>
      <label>Peso (kg)<input type="number" name="peso" min="0" step="0.1" value="${Number(o.peso||0)}" required></label>
      <label>Mani<select name="mani"><option value="1"${Number(o.mani)===1?' selected':''}>Una mano</option><option value="2"${Number(o.mani)===2?' selected':''}>Due mani</option></select></label>
      <label>Ripristino PF<input type="number" name="restore" value="${Number(o.restore||0)}" min="0"></label>
      <label>Lavorazione<input name="lavorazione" value="${escapeHtml(o.lavorazione||'')}"></label>
      <label><input type="checkbox" name="sacro"${o.sacro?' checked':''}> Oggetto sacro</label>
      <label><input type="checkbox" name="pers"${(o.destinatario||o.destorg)?' checked':''}> Personalizzato</label>
      <label>Destinatario<input name="destinatario" value="${escapeHtml(o.destinatario||'')}"></label>
      <label>Organizzazione destinataria<select name="destorg"><option value="0">Nessuna</option>${options(organizzazioni,o.destorg)}</select></label>
      </div>
      <fieldset><legend>Materie prime per pezzo</legend><div class="catalogue-materials">${materie.map(m=>`<label>${escapeHtml(m)}<input type="number" name="${escapeHtml(m)}" value="${Number(o.materie?.[m]||0)}" min="0"></label>`).join('')}</div></fieldset>
      <div class="ui-row-actions"><button type="submit">Salva oggetto</button><button type="button" onclick="Catalogo.Cambia(Catalogo.currentView())">Annulla</button></div>
    </form>`;
    corpo.innerHTML = html;
    corpo.querySelector('form').onsubmit=event=>{event.preventDefault();submitForm();};
  }

  function submitForm() {
    const form = document.getElementById('objForm');
    const data = new FormData(form);
    fetch('/api/corporazioni/catalogo.php', {
      method:'POST',
      body:data
    })
    .then(r=>r.json())
    .then(result=> {
      if (result.success === false || result.error) throw new Error(result.msg || result.message || 'Operazione non riuscita');
      return fetchData(corpId);
    })
    .then(data=>{
      catalogo        = data.catalogo         || [];
      catalogoPers    = data.catalogoPers     || [];
      catalogoPersOrg = data.catalogoPersOrg  || [];
      riparazioni     = data.riparazioni      || [];
      Cambia(oldtree);
    })
    .catch(error => showFeedback(error.message || 'Salvataggio non riuscito. Riprova.',true));
  }

  // ─── HELPERS ─────────────────────────────────────────────────────────────────
  function showFeedback(message,error=false,form=null,orderLink=false) {
    const feedback=(form && form.querySelector('[role="status"]')) || document.getElementById('catalogo-feedback');
    if(!feedback)return;
    feedback.hidden=false;
    feedback.classList.toggle('error',error);
    feedback.textContent=message;
    if(orderLink){const link=document.createElement('a');link.href='/trasferimenti/';link.textContent=' Vai ai miei ordini';feedback.appendChild(link);}
  }
  async function postAction(payload,form=null) {
    if(busy)return;
    busy=true;
    payload.corp=corpId;
    const identity=JSON.stringify(payload);
    if(!pendingKeys.has(identity))pendingKeys.set(identity,crypto.randomUUID());
    payload._request_key=pendingKeys.get(identity);
    document.querySelectorAll('#corpo button').forEach(button=>button.disabled=true);
    showFeedback(payload.azione==='ordina' ? 'Invio dell’ordine…' : 'Operazione in corso…',false,form);
    try {
      const response=await fetch('/api/corporazioni/catalogo.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
      const result=await response.json();
      if(!response.ok || result.success===false || result.error) {
        if(response.status<500)pendingKeys.delete(identity);
        throw Error(result.msg || result.message || 'Operazione non riuscita. Riprova.');
      }
      pendingKeys.delete(identity);
      if(payload.azione==='ordina'){
        if(form){form.dataset.completed='true';form.elements.namedItem('qta').readOnly=true;}
        showFeedback(result.msg || 'Ordine creato correttamente.',false,form,true);window.alphanoCommerce?.changed();window.alphanoCommerce?.notify(result.msg||'Ordine inviato.',false,result.orderId);
      }else{
        const data=await fetchData(corpId);
        catalogo=data.catalogo || [];catalogoPers=data.catalogoPers || [];catalogoPersOrg=data.catalogoPersOrg || [];riparazioni=data.riparazioni || [];
        Cambia(oldtree);
        showFeedback(result.msg || 'Operazione completata.');
      }
      return result;
    } catch(error) {const message=['TypeError','SyntaxError'].includes(error.name)?'Connessione interrotta. Puoi riprovare l’invio.':(error.message||'Operazione non riuscita. Riprova.');showFeedback(message,true,form);window.alphanoCommerce?.notify(message,true);}
    finally {
      busy=false;
      document.querySelectorAll('#corpo button').forEach(button=>button.disabled=!!form && form.dataset.completed==='true' && form.contains(button));
    }
  }

  function Resize(img){
    img.decoding='async';
  }

  function escapeHtml(s){
    return String(s||'')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }

  // ─── EXPORT ──────────────────────────────────────────────────────────────────
  return {currentView:()=>oldtree,
    init,
    Cambia,
    CaricaCatalogo, CaricaCatalogoPers, CaricaCatalogoPersOrg, CaricaRiparazioni,
    Mostra, MostraRip,
    OrdinaObj, RiparaObj, AggiungiNuovo, ModificaObj, CancellaObj, MostraTutti,
    Resize,
    submitForm
  };
})();
 