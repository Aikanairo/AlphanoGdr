/* Le bacheche cittadine usano la stessa tabella e gli stessi editor del forum. */
(() => {
  'use strict';
  const config=JSON.parse(document.getElementById('board-admin-config')?.textContent||'{}');
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const confirmBoard=async message=>await window.alphanoDialog.notice({title:'Conferma gestione bacheche',message,buttonLabel:'Conferma',cancelLabel:'Indietro'})==='ack';
  let cities=[],boards=[],images=[],selectedCity='',selectedBoard=0,quill;
  const button=(label,action,id=0,icon='messages')=>`<button type="button" class="action-btn" data-action="${action}" data-id="${Number(id)}" data-icon="${icon}">${label}</button>`;
  const rightsLabels={needadmin:'Governatori',needfatepower:'Master',needadminferiti:'Curatori',needaccademia:'Accademia'};
  const content=()=>document.getElementById('board-admin-content');
  function toast(message,error=false){const el=document.getElementById('toast');el.textContent=message;el.dataset.state=error?'error':'success';el.classList.add('show');clearTimeout(toast.timer);toast.timer=setTimeout(()=>el.classList.remove('show'),3500);}
  async function fetchData(payload={}){
    const response=await fetch(config.apiUrl,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':config.csrfToken},body:JSON.stringify(payload)});
    const result=await response.json();if(!response.ok||!result.success)throw new Error(result.error||'Operazione non riuscita');return result;
  }
  function accept(data){
    boards=data.bacheche;images=data.images||[];
    cities=config.canManageAll?[...new Set([...data.cities,...boards.map(board=>board.location)])]:[config.mayorCity];
    if(!cities.includes(selectedCity))selectedCity=cities[0]||'';
    document.getElementById('board-admin-city').innerHTML=cities.map(city=>`<option value="${esc(city)}"${city===selectedCity?' selected':''}>${esc(city)}</option>`).join('');
    document.getElementById('board-admin-city').disabled=cities.length<2;
    renderList();
  }
  const access=board=>board.diritti?.all?'Pubblica':Object.entries(rightsLabels).filter(([key])=>board.diritti?.[key]).map(([,label])=>label).join(' · ')||'Accesso riservato';
  function renderList(){
    selectedBoard=0;const items=boards.filter(board=>board.location===selectedCity);
    document.getElementById('board-admin-count').textContent=items.length+' bacheche';
    content().innerHTML=`<div class="ui-table-scroll" tabindex="0" role="region" aria-label="Bacheche di ${esc(selectedCity)}"><table class="ui-data-table board-admin-table"><thead><tr><th scope="col">Bacheca</th><th scope="col">Accesso</th><th scope="col">Manutenzione</th><th scope="col">Azioni</th></tr></thead><tbody>${items.length?items.map(board=>`<tr><td><strong>${esc(board.nome)}</strong><small class="ui-cell-meta">${esc(board.location)}</small></td><td>${esc(access(board))}</td><td>${board.clear?'Pulizia periodica abilitata':'Conserva i messaggi'}</td><td><div class="ui-row-actions">${button('Dettagli','show',board.id,'book')}${button('Modifica','edit',board.id,'settings')}${button('Permessi','permissions',board.id,'shield')}</div><details class="board-row-advanced"><summary>Altre operazioni</summary><div class="ui-row-actions">${button('Svuota','clear',board.id,'scroll')}${button('Elimina','delete',board.id,'settings')}</div></details></td></tr>`).join(''):'<tr><td colspan="4" class="ui-empty">Nessuna bacheca presente in questa città.</td></tr>'}</tbody></table></div>`;
  }
  function showBoard(board){selectedBoard=board.id;content().innerHTML=`<header class="forum-toolbar"><h2>${esc(board.nome)}</h2><div class="ui-row-actions">${button('Elenco bacheche','back',0,'book')}${button('Modifica','edit',board.id,'settings')}${button('Permessi','permissions',board.id,'shield')}<a class="action-btn" data-icon="messages" href="/bacheche/?board=${board.id}">Apri forum</a></div></header><article class="manual-reading">${board.descrizione||'<p>Nessuna descrizione.</p>'}</article><footer class="ui-list-footer"><p>${esc(board.location)} · ${esc(access(board))}</p></footer>`;}
  function openForm(action,board={}){
    selectedBoard=Number(board.id)||0;const editing=action==='edit';
    content().innerHTML=`<header class="forum-toolbar"><h2>${editing?'Modifica bacheca':'Nuova bacheca'}</h2>${button('Elenco bacheche','back',0,'book')}</header><form id="board-editor" class="forum-editor-form"><div class="board-admin-fields"><label>Città<select name="location" required>${cities.map(city=>`<option value="${esc(city)}"${(board.location||selectedCity)===city?' selected':''}>${esc(city)}</option>`).join('')}</select></label><label>Nome<input type="text" name="nome" maxlength="255" required value="${esc(board.nome||'')}"></label></div><label>Immagine della bacheca<select name="header_image">${images.map(i=>`<option value="${esc(i.path)}"${i.path===board.header_image?' selected':''}>${esc(i.name)}</option>`).join('')}</select></label><img class="board-cover-preview" src="${esc(board.header_image||images[0]?.path||'/assets/default-board.svg')}" alt="Anteprima immagine della bacheca"><div><label id="board-description-label">Descrizione</label><div id="quill-editor" aria-labelledby="board-description-label"></div></div><label class="forum-check"><input type="checkbox" name="clear"${board.clear?' checked':''}> Includi nella pulizia periodica dei messaggi</label><div class="ui-row-actions"><button type="submit" data-icon="check">${editing?'Salva modifiche':'Crea bacheca'}</button><button type="button" data-action="back">Annulla</button></div></form>`;
    const editor=document.getElementById('quill-editor');
    content().querySelector('[name=header_image]').onchange=e=>content().querySelector('.board-cover-preview').src=e.target.value;
    if(typeof Quill==='function'){quill=new Quill(editor,{theme:'snow'});quill.clipboard.dangerouslyPasteHTML(board.descrizione||'');}
    else{editor.contentEditable='true';editor.classList.add('board-description-fallback');editor.innerHTML=board.descrizione||'';quill={root:editor};}
    document.getElementById('board-editor').onsubmit=async event=>{event.preventDefault();const form=event.currentTarget,submit=form.querySelector('[type=submit]');submit.disabled=true;try{if(!quill.root.textContent.trim())throw new Error('Scrivi una descrizione per la bacheca.');const fields=new FormData(form);const location=fields.get('location');await mutate(editing?'modifica':'aggiungi',{id:board.id||0,location,nome:fields.get('nome'),descrizione:quill.root.innerHTML,header_image:fields.get('header_image'),clear:fields.has('clear')?1:0},location);}catch(error){toast(error.message,true);}finally{submit.disabled=false;}};
  }
  function permissions(board){
    selectedBoard=board.id;const rights=board.diritti||{};
    content().innerHTML=`<header class="forum-toolbar"><h2>Permessi · ${esc(board.nome)}</h2>${button('Elenco bacheche','back',0,'book')}</header><form id="board-permissions" class="forum-editor-form"><p class="forum-summary">Scegli chi può leggere e scrivere in questa bacheca. Gli amministratori del gioco mantengono l’accesso alla gestione.</p><div class="board-permission-options"><label class="forum-check"><input type="checkbox" name="all"${rights.all?' checked':''}> Chiunque</label>${Object.entries(rightsLabels).map(([key,label])=>`<label class="forum-check"><input type="checkbox" name="${key}"${rights[key]?' checked':''}> ${label}</label>`).join('')}</div><div class="ui-row-actions"><button type="submit" data-icon="check">Salva permessi</button><button type="button" data-action="back">Annulla</button></div></form>`;
    document.getElementById('board-permissions').onsubmit=async event=>{event.preventDefault();const form=event.currentTarget,fields=new FormData(form),submit=form.querySelector('[type=submit]');submit.disabled=true;try{const flags=Object.fromEntries(['all',...Object.keys(rightsLabels)].map(key=>[key,fields.has(key)?1:0]));await mutate('permessi',{id:board.id,...flags});}catch(error){toast(error.message,true);}finally{submit.disabled=false;}};
  }
  async function mutate(azione,params,city=selectedCity){const data=await fetchData({azione,...params});selectedCity=city;accept(data);toast(data.message||'Operazione riuscita');}
  async function dispatch(event){
    const target=event.target.closest('[data-action]');if(!target)return;const action=target.dataset.action;
    if(action==='back')return renderList();const board=boards.find(board=>board.id===Number(target.dataset.id));if(!board)return;
    try{if(action==='show')return showBoard(board);if(action==='edit')return openForm('edit',board);if(action==='permissions')return permissions(board);
      if(action==='clear'&&await confirmBoard('Svuotare tutte le discussioni della bacheca “'+board.nome+'”?'))await mutate('svuota',{id:board.id});
      if(action==='delete'&&await confirmBoard('Eliminare la bacheca “'+board.nome+'” e tutte le sue discussioni?'))await mutate('cancella',{id:board.id,all:1});
    }catch(error){toast(error.message,true);}
  }
  document.addEventListener('DOMContentLoaded',async()=>{
    content().addEventListener('click',dispatch);document.getElementById('board-admin-city').onchange=event=>{selectedCity=event.target.value;renderList();};document.getElementById('create-board').onclick=()=>openForm('create');
    const removeCity=document.getElementById('delete-city-boards');if(removeCity)removeCity.onclick=async()=>{if(!await confirmBoard('Eliminare tutte le bacheche e le discussioni di “'+selectedCity+'”?'))return;try{await mutate('cancella',{location:selectedCity,all:1});}catch(error){toast(error.message,true);}};
    try{accept(await fetchData());}catch(error){content().innerHTML='<p class="ui-empty error" role="alert">'+esc(error.message)+'</p>';}
  });
})();
