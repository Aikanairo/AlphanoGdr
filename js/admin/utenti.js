// js/admin/utenti.js
document.addEventListener('DOMContentLoaded', ()=>{
  const apiUrl    = window.apiUrl;
  const menu      = document.getElementById('menu-list');
  const panels    = document.querySelectorAll('.panel');
  const title     = document.getElementById('panel-title');
  const container = document.querySelector('.panel-container');
  const output    = document.createElement('div');
  output.className = 'api-output';
  container.appendChild(output);

  function showPanel(act){
    panels.forEach(p=>p.classList.remove('active'));
    document.getElementById(`panel-${act}`).classList.add('active');
    menu.querySelectorAll('li').forEach(li=>li.classList.remove('active'));
    const li = menu.querySelector(`li[data-action="${act}"]`);
    if(li){ li.classList.add('active'); title.textContent = li.textContent; }
    output.innerHTML = '';  // reset output
  }

  menu.addEventListener('click', e=>{
    const item=e.target.closest('li[data-action]');
    if(!item||!menu.contains(item))return;
    showPanel(item.dataset.action);
  });
  menu.addEventListener('keydown', e=>{
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const item=e.target.closest('li[data-action]');
    if (!item || !menu.contains(item)) return;
    e.preventDefault();
    showPanel(item.dataset.action);
  });
  showPanel(menu.querySelector('li').dataset.action);

  // popola etnie e razze...
  if (window.canManageUsers !== false) fetch(`${apiUrl}?azione=none`)
    .then(r=>r.json())
    .then(js=>{
      js.etnie.forEach(e=> {
        document.getElementById('profile-etnia')
                .append(new Option(e.nome, e.id));
      });
    });

  if (window.canManageUsers !== false) fetch(`${apiUrl}?azione=listarazze`)
    .then(r=>r.json())
    .then(js=>{
      js.razze.forEach(rz=> {
        document.getElementById('profile-razza')
                .append(new Option(rz.nome, rz.id));
      });
      document.getElementById('profile-razza').prepend(new Option('Mantieni la razza attuale','',true,true));
    });

  const raceChoice=document.getElementById('profile-razza'),nameChoice=document.getElementById('profile-nome');
  const lineageBox=document.getElementById('profile-lineage'),lineageMessage=document.getElementById('profile-lineage-message'),creatorChoice=document.getElementById('profile-creator');
  let lineageController,lineageTimer,lineageReady=true;
  async function refreshLineage(){
    if(!raceChoice)return;
    lineageController?.abort();creatorChoice.replaceChildren();
    const special=['2','3','5','6'].includes(raceChoice.value);
    lineageBox.hidden=!special;lineageReady=!special;if(!special)return;
    creatorChoice.hidden=true;lineageBox.querySelector('label').hidden=true;
    if(!nameChoice.value.trim()){lineageMessage.textContent='Indica prima il nome del personaggio.';return;}
    lineageMessage.textContent='Verifica della stirpe…';
    lineageController=new AbortController();
    try{
      const query=new URLSearchParams({azione:'stirpe',nome:nameChoice.value.trim(),razza:raceChoice.value});
      const response=await fetch(apiUrl+'?'+query,{signal:lineageController.signal});const json=await response.json();
      if(!response.ok||!json.success)throw Error(json.response?.message||'Verifica non riuscita');
      const plan=json.response.plan;lineageMessage.textContent=plan.message;
      creatorChoice.hidden=!plan.requires_creator;lineageBox.querySelector('label').hidden=!plan.requires_creator;
      for(const person of plan.creators)creatorChoice.append(new Option(person.name+(person.primordial?' · capostipite':''),person.id));
      if(plan.default_creator_id)creatorChoice.value=String(plan.default_creator_id);
      lineageReady=!plan.requires_creator||!!creatorChoice.value;
    }catch(error){if(error.name!=='AbortError')lineageMessage.textContent=error.message;}
  }
  raceChoice?.addEventListener('change',refreshLineage);
  nameChoice?.addEventListener('input',()=>{lineageReady=false;clearTimeout(lineageTimer);lineageTimer=setTimeout(refreshLineage,250);});
  creatorChoice?.addEventListener('change',()=>{lineageReady=!!creatorChoice.value;});

  // funzione che trasforma un oggetto JS in un <dl>
  function renderResponse(obj){
    output.innerHTML = '';
    if (obj && typeof obj === 'object') {
      const dl = document.createElement('dl');
      dl.className = 'ui-response-list';
      Object.entries(obj).forEach(([key, val])=>{
        const dt = document.createElement('dt');
        dt.textContent = key;
        const dd = document.createElement('dd');
        if (val !== null && typeof val === 'object') {
          // se il valore è un oggetto annidato, lo ricorsione
          dd.appendChild(renderNested(val));
        } else {
          dd.textContent = String(val);
        }
        dl.append(dt, dd);
      });
      output.appendChild(dl);
    } else {
      // valore semplice
      const p = document.createElement('p');
      p.textContent = String(obj);
      output.appendChild(p);
    }
  }

  // funzione di supporto per oggetti annidati
  function renderNested(o){
    const container = document.createElement('div');
    container.className = 'ui-response-nested';
    Object.entries(o).forEach(([k,v])=>{
      const row = document.createElement('div');
      const key = document.createElement('strong');
      key.textContent = k + ': ';
      const val = document.createElement('span');
      val.textContent = String(v);
      row.append(key, val);
      container.appendChild(row);
    });
    return container;
  }

  // handler generale dei bottoni
  document.body.addEventListener('click', async e=>{
    const button=e.target.closest('.action-btn[data-action]');
    if(!button||!document.body.contains(button))return;
    const act = button.dataset.action;
    const data = { azione: act, _csrf: window.csrfToken };
    if (['cancella','manutenzione','resetpos','resetcount'].includes(act) && await window.alphanoDialog.notice({title:'Conferma operazione amministrativa',message:(menu.querySelector(`li[data-action="${act}"]`)?.textContent||act)+(act==='cancella'?' · '+document.getElementById('del-nome').value:' · Operazione generale sul gioco'),buttonLabel:'Conferma',cancelLabel:'Indietro'})!=='ack') return;
    if (act === 'dump') {
      const form = document.createElement('form'); form.method='POST'; form.action=apiUrl;
      for (const [key,value] of Object.entries(data)) { const input=document.createElement('input'); input.type='hidden'; input.name=key; input.value=value; form.append(input); }
      document.body.append(form); form.submit(); form.remove(); return;
    }

    // raccogli i campi a seconda dell'azione...
    switch(act){
      case 'profile':
        if(['2','3','5','6'].includes(raceChoice.value)&&!lineageReady){lineageMessage.textContent='Completa la verifica e scegli un creatore valido prima di salvare.';return;}
        ['nome','sesso','etnia','allineamento','razza']
          .forEach(id=>{
            data[id] = document.getElementById(`profile-${id}`).value;
          });
        if(!creatorChoice.hidden)data.creator_id=creatorChoice.value;
        break;
      case 'cambiapass':
        data.nome = document.getElementById('pass-nome').value;
        data.pass = document.getElementById('pass-new').value;
        break;
      case 'cancella':
        data.nome     = document.getElementById('del-nome').value;
        data.profilo  = +document.getElementById('del-profilo').checked;
        data.equip    = +document.getElementById('del-equip').checked;
        data.posta    = +document.getElementById('del-posta').checked;
        data.bacheche = +document.getElementById('del-bacheche').checked;
        break;
      case 'cancellaposta':
        data.nome  = document.getElementById('mail-nome').value;
        data.invio = document.getElementById('mail-invio').value;
        data.baule = document.getElementById('mail-baule').value;
        data.letta = document.getElementById('mail-letta').value;
        break;
      case 'cambianome':
        data.nome      = document.getElementById('cn-old').value;
        data.nuovonome = document.getElementById('cn-new').value;
        break;
      case 'doppipg':
        data.nome          = document.getElementById('dp-nome').value;
        data.trasferimenti = +document.getElementById('dp-trasf').checked;
        data.ricompense    = +document.getElementById('dp-ric').checked;
        data.concatena     = +document.getElementById('dp-concat').checked;
        break;
      case 'statosociale':
        data.nome  = document.getElementById('ss-nome').value;
        data.stato = document.getElementById('ss-val').value;
        break;
      case 'mostrastatosociale':
        data.nome = document.getElementById('mss-nome').value;
        break;
      case 'emailreg':
        data.nome  = document.getElementById('er-nome').value;
        data.email = document.getElementById('er-val').value;
        break;
      case 'mostraemailreg':
        data.nome = document.getElementById('mer-nome').value;
        break;
      // resetpos, resetcount, ottimizza, dump non hanno campi extra
    }

    fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify(data)
    })
    .then(r=>r.json())
    .then(js=>{
      // preferisci .data, poi .message, poi l'intero oggetto di risposta
      const resp = js.response?.data ?? js.response?.message ?? js.response ?? js.message ?? js;
      renderResponse(resp);
      output.setAttribute('role', js.success === false ? 'alert' : 'status');
    })
    .catch(_=>{
      output.innerHTML = '<p class="u-f75d63c1e">Errore di rete</p>';
    });
  });
});
