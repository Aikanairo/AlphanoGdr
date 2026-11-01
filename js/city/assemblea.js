'use strict';

/*
  js/city/assemblea.js  –  front-end Assemblea Cittadina
  Grafico Canvas locale, senza dipendenze di rete.
*/

document.addEventListener('DOMContentLoaded', () => {
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
  // 1) Parametri URL
  const params   = new URLSearchParams(window.location.search);
  const cityName = params.get('nomecity') || document.querySelector('meta[name="city-name"]')?.content;
  if (!cityName) {
    showToast('Parametro “nomecity” mancante.', true);
    return;
  }

  // 2) Stato globale
  let dataObj   = null;


  // 3) Inizializza menu e pannelli
  initMenu();
  showPanel('situazione');

  function initMenu() {
    const items = [...document.querySelectorAll('.city-assembly-nav button[data-panel]')];
    items.forEach((item, index) => {
      item.addEventListener('click', () => {
        showPanel(item.dataset.panel);
      });
      item.addEventListener('keydown', event => {
        let next=index;
        if(event.key==='ArrowRight')next=(index+1)%items.length;
        else if(event.key==='ArrowLeft')next=(index+items.length-1)%items.length;
        else if(event.key==='Home')next=0;
        else if(event.key==='End')next=items.length-1;
        else return;
        event.preventDefault();items[next].click();items[next].focus();
      });
    });
  }

  function showPanel(panel) {
    document.querySelectorAll('.city-assembly-panel').forEach(p => {
      const active=p.id===`panel-${panel}`;
      p.classList.toggle('active',active);p.hidden=!active;
    });
    document.querySelectorAll('.city-assembly-nav button').forEach(button=>{
      const active=button.dataset.panel===panel;
      button.classList.toggle('active',active);button.setAttribute('aria-selected',String(active));button.tabIndex=active?0:-1;
    });
    document.getElementById('panel-title').textContent =
      document.querySelector(`.city-assembly-nav button[data-panel="${panel}"]`).textContent;
    loadDataAndRender(panel);
  }

  // 4) Fetch + rendering
  function fetchData() {
    return fetch(`/api/citta/assemblea.php?nomecity=${encodeURIComponent(cityName)}`)
      .then(res => {
        if (!res.ok) throw new Error(`Server error: ${res.status}`);
        return res.json();
      })
      .then(json => {
        if (json.error) throw new Error(json.message || 'Errore API');
        dataObj = json;
        return json;
      });
  }

  function loadDataAndRender(panel) {
    fetchData()
      .then(() => {
        if (!dataObj) return;
        if (panel === 'situazione') renderSituazione();
        else if (panel === 'assemblea') renderAssemblea();
        else if (panel === 'elezioni') renderElezioni();
      })
      .catch(err => showToast(err.message, true));
  }

  // 5) Situazione Attuale
  function renderSituazione() {
    const { cityName, organization, inhabitants, electionsEnabled, monthsToNextElection } = dataObj;
    document.getElementById('panel-situazione').innerHTML = `
      <h3>🏙️ ${escapeHtml(cityName)}</h3>
      <p><strong>🏢 Organizzazione:</strong> ${escapeHtml(organization)}</p>
      <p><strong>👥 Abitanti:</strong> ${inhabitants}</p>
      <p><strong>✅ Elezioni abilitate:</strong> ${electionsEnabled ? 'Sì' : 'No'}</p>
      <p><strong>⏳ Mesi a prossime ordinarie:</strong> ${monthsToNextElection ?? '–'}</p>
    `;
  }

  // 6) Assemblea Cittadina
  function renderAssemblea() {
    const { current, proposed, votes, userCanVote } = dataObj.statute;
    const container = document.getElementById('panel-assemblea');
    let html = `<h3>📜 Statuto corrente</h3><p>${current}</p>`;

    if (proposed) {
      html += `
        <div class="api-output">
          <h4>🆕 Statuto proposto</h4>
          <p>${proposed}</p>
          <p>👍 ${votes.yes} 👎 ${votes.no}</p>
          ${userCanVote
            ? `<form id="form-vota-statuto">
                 <label><input type="radio" name="statuto" value="1" checked> Favorevole</label>
                 <label><input type="radio" name="statuto" value="0"> Contrario</label><br>
                 <button type="submit" class="action-btn">🗳️ Vota Statuto</button>
               </form>`
            : `<p><em>Hai già votato o non puoi votare.</em></p>`}
        </div>
      `;
    } else {
      html += `<p>Non ci sono modifiche proposte.</p>`;
    }

    container.innerHTML = html;
    const form = container.querySelector('#form-vota-statuto');
    if (form) form.addEventListener('submit', onSubmitStatuto);
  }

  function onSubmitStatuto(event) {
    event.preventDefault();
    doPost({ azione: 'vota', statuto: Number(new FormData(event.target).get('statuto')) }, () => {
      showToast('Voto statuto inviato ✅');
    });
  }

  // 7) Elezioni + Candidature + Grafico + Banner vincitore
  function renderElezioni() {
    window.location.href='/citta/elezioni.php?city='+encodeURIComponent(dataObj.cityId);return;
    const {
      yourCandidature,
      candidacyOpen,
      election: { inProgress, canVote, results, winner }
    } = dataObj;

    const container = document.getElementById('panel-elezioni');

    // ——— se esiste il vincitore, mostro solo lui + grafico dei risultati ———
    if (winner) {
      // 1) banner vincitore
      container.innerHTML = `
        <div class="api-output success">
          ✅ <strong>Elezioni completate</strong><br>
          Il vincitore è <strong>${escapeHtml(winner.name)}</strong>.
        </div>
        <div class="api-output">
          <h4>📊 Risultati finali</h4>
          <canvas id="chart-votes" height="200"></canvas>
        </div>
      `;
      // 2) disegno il grafico dai dati storici (consultazioni)
      renderVoteChart(winner.consultazioni);
      return;
    }


    // altrimenti, fase candidature / votazioni in corso
    const { candidacyDeadline, startCandidature, startElezioni, endElezioni, electionType } = dataObj;
    const tipo = electionType ? electionType.charAt(0).toUpperCase() + electionType.slice(1) : 'Elezioni';

    // date
    const dtC = new Date(startCandidature?.replace(' ', 'T') || ''),
          dtE = new Date(startElezioni?.replace(' ', 'T')   || ''),
          dtX = new Date(endElezioni?.replace(' ', 'T')     || '');

    let html = `
      <div class="api-output">
        <h4>🗳️ ${tipo}</h4>
        ${candidacyOpen
          ? `<p>✏️ Candidature aperte fino al ${formatDate(dtE)}</p>`
          : inProgress
            ? `<p>🔔 Votazioni in corso (fino al ${formatDate(dtX)})</p>`
            : `<p>⌛ In attesa di tornata</p>`}
        ${candidacyOpen
          ? `<form id="form-candidati">
               <textarea name="programma" rows="3" placeholder="Il tuo programma..." required>${escapeHtml(yourCandidature)}</textarea><br>
               <button type="submit" class="action-btn">${yourCandidature ? 'Aggiorna programma' : '✏️ Candidati'}</button>
             </form>`
          : yourCandidature ? `<p>📝 Il tuo programma:<br>${escapeHtml(yourCandidature)}</p>` : ''}
      </div>

      <div class="api-output">
        <h4>👥 Lista Candidati</h4>
        <ul id="list-candidati"></ul>
      </div>

      <div class="api-output">
        <h4>📊 Classifica Voti</h4>
        <canvas id="chart-votes" height="200"></canvas>
      </div>
    `;

    container.innerHTML = html;

    // bind candidatura
    const formC = container.querySelector('#form-candidati');
    if (formC) formC.addEventListener('submit', onSubmitCandidatura);

    // render lista con pulsanti di voto
    renderCandidateList(dataObj.election.candidates, yourCandidature, inProgress, canVote);

    // render grafico
    renderVoteChart(results);
  }

  function onSubmitCandidatura(event) {
    event.preventDefault();
    const prog = event.target.programma.value.trim();
    if (!prog) return showToast('Inserisci un programma.', true);
    doPost({ azione: 'candidati', programma: prog }, () => {
      showToast('Candidatura registrata ✅');
    });
  }

  // 8) Lista candidati con “Vota”
  function renderCandidateList(cands, yourProg, inProg, canVote) {
    const ul = document.getElementById('list-candidati');
    ul.innerHTML = '';

    if (cands.length === 0 && !yourProg) {
      ul.innerHTML = '<li><em>Nessun candidato al momento.</em></li>';
      return;
    }

    const list = [...cands];
    if (yourProg && !list.some(c => c.program === yourProg)) {
      list.push({ id:'tu', name:'Tu', organization:'', program: yourProg, votes: 0 });
    }

    list.forEach(cand => {
      const li = document.createElement('li');
      li.className = 'api-output';
      li.innerHTML = `
        <strong>👤 ${escapeHtml(cand.name)}</strong><br>
        <p><em>${escapeHtml(cand.program || '— nessun programma —').replace(/\n/g,'<br>')}</em></p>
        <p><strong>Voti:</strong> ${cand.votes}</p>
        ${inProg && canVote
          ? `<button class="action-btn btn-vota" data-id="${cand.id}">
               🗳️ Vota ${escapeHtml(cand.name)}
             </button>`
          : ''}
      `;
      ul.appendChild(li);
      const btn = li.querySelector('.btn-vota');
      if (btn) btn.addEventListener('click', () => {
        if (!confirm(`Vuoi votare per ${escapeHtml(cand.name)}?`)) return;
        doPost({ azione: 'elezioni', pg: cand.id }, () => {
          showToast('Voto inviato ✅');
        });
      });
    });
  }

  // 9) Grafico locale: le barre restano leggibili anche senza accesso a Internet.
  function renderVoteChart(candidates) {
    const canvas=document.getElementById('chart-votes');
    if (!canvas) return;
    const rows=Array.isArray(candidates) ? candidates : [];
    const width=Math.max(280,canvas.parentElement.clientWidth-24), rowHeight=34;
    canvas.width=width; canvas.height=Math.max(60,rows.length*rowHeight+16);
    canvas.setAttribute('role','img');
    canvas.setAttribute('aria-label',rows.map(row=>`${row.name}: ${row.votes} voti`).join(', ') || 'Nessun voto');
    const ctx=canvas.getContext('2d');
    if (!ctx) return;
    const labelWidth=Math.min(150,width*.4), chartWidth=width-labelWidth-38;
    const maximum=Math.max(1,...rows.map(row=>Number(row.votes)||0));
    ctx.font='13px sans-serif'; ctx.textBaseline='middle';
    rows.forEach((row,index)=>{
      const y=index*rowHeight+20, votes=Number(row.votes)||0;
      ctx.fillStyle=getComputedStyle(document.body).getPropertyValue('--text').trim();
      ctx.fillText(String(row.name),0,y,labelWidth-8);
      ctx.fillStyle=getComputedStyle(document.body).getPropertyValue('--gold').trim(); ctx.fillRect(labelWidth,y-10,chartWidth*votes/maximum,20);
      ctx.fillStyle=getComputedStyle(document.body).getPropertyValue('--text').trim();
      ctx.fillText(String(votes),labelWidth+chartWidth*votes/maximum+5,y);
    });
    if (!rows.length) ctx.fillText('Nessun voto',8,25);
  }

  // 10) POST helper + ricarica + re-render
  function doPost(body, onSuccess = () => {}) {
    const buttons=[...document.querySelectorAll('.city-assembly-panel.active button')];
    buttons.forEach(button=>button.disabled=true);
    fetch(`/api/citta/assemblea.php?nomecity=${encodeURIComponent(cityName)}`, {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams(body)
    }).then(async response=>{
      const json=await response.json();
      if (!response.ok || json.error) throw new Error(json.message || `Errore ${response.status}`);
      dataObj=json;
      onSuccess();
      const panel=document.querySelector('.city-assembly-panel.active')?.id;
      if (panel==='panel-assemblea') renderAssemblea();
      else if (panel==='panel-situazione') renderSituazione();
      else renderElezioni();
    }).catch(error=>showToast(error.message || String(error),true))
      .finally(()=>buttons.forEach(button=>button.disabled=false));
  }

  // Utils
  function formatDate(input) {
    const d = input instanceof Date ? input : new Date(input.replace(' ', 'T'));
    return d.toLocaleString('it-IT', {
      year:'numeric',month:'long',day:'numeric',
      hour:'2-digit',minute:'2-digit'
    });
  }
  function showToast(msg, isError=false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = isError ? 'error show' : 'show';
    setTimeout(()=>t.className='', 3000);
  }
});
