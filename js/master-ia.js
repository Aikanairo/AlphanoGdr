(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const requested=new URLSearchParams(location.search);
  let data = { room:null, npcs:[], memory:[] }, chat = requested.get('chat')||'', previewTimer;
  let initialNpc=requested.get('npc'),controls=null,controlRequest=0;
  const states={queued:'In coda',running:'In scrittura',done:'Completata',failed:'Non riuscita',cancelled:'Annullata'};
  async function api(action, values={}, post=false) {
    const options={credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(10000)};
    const params={action,...values};
    if(post) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(params)});
    const response=await fetch('/api/ai.php'+(post?'':'?'+new URLSearchParams(params)),options);
    const result=await response.json();if(!response.ok)throw new Error(result.error||'Operazione non riuscita.');return result;
  }
  function feedback(text){$('ai-feedback').textContent=text;}
  function fill(form, values) {
    form.reset();Object.entries(values||{}).forEach(([key,value])=>{const field=form.elements.namedItem(key);if(field){if(field.type==='checkbox')field.checked=Number(value)===1;else field.value=value;}});
  }
  function values(form) {
    const v=Object.fromEntries(new FormData(form));form.querySelectorAll('input[type="checkbox"]').forEach(f=>{v[f.name]=f.checked?1:0;});return v;
  }
  function option(value,label){return new Option(label,value);}
  function addDetail(label='',value='') {
    const row=document.createElement('div');row.className='ai-detail-row';
    const heading=document.createElement('input');heading.placeholder='Titolo';heading.maxLength=80;heading.dataset.detailLabel='';heading.value=label;heading.setAttribute('aria-label','Titolo dell’informazione');
    const text=document.createElement('textarea');text.placeholder='Informazione pubblica';text.maxLength=2000;text.dataset.detailValue='';text.value=value;text.setAttribute('aria-label','Contenuto dell’informazione');
    const remove=document.createElement('button');remove.type='button';remove.textContent='Rimuovi';remove.onclick=()=>row.remove();row.append(heading,text,remove);$('ai-profile-details').append(row);
  }
  function showNpc(){
    const npc=data.npcs.find(n=>String(n.id)===$('ai-npc').value);const p=typeof npc?.profile==='string'?JSON.parse(npc.profile):npc?.profile||{};
    fill($('ai-npc-form'),{...npc,...Object.fromEntries(Object.entries(p).map(([key,value])=>['profile_'+key,value])),...Object.fromEntries(Object.entries(npc?.adult||{}).map(([key,value])=>['adult_'+key,value]))});
    updateAdultAge();
    $('ai-profile-details').replaceChildren();(p.details||[]).forEach(d=>addDetail(d.label,d.value));
    $('ai-social-admin-link').href='/admin/png-relazioni.php'+(npc?'?npc='+npc.id:'');
    $('ai-npc-portrait-preview').src=p.portrait||'/assets/placeholder.svg';$('ai-portrait-feedback').textContent='';
    loadControls(npc);
  }
  function pathGet(object,path){return path.split('.').reduce((value,key)=>value?.[key],object);}
  function pathSet(object,path,value){const keys=path.split('.'),last=keys.pop();let parent=object;for(const key of keys)parent=parent[key]??=( {} );parent[last]=value;}
  async function loadControls(npc){
    const request=++controlRequest;controls=null;$('ai-controls-fields').replaceChildren();$('ai-controls-save').disabled=true;$('ai-controls-prompt').textContent='';$('ai-controls-title').textContent=npc?'Regole e capacità · '+npc.name:'Salva prima un personaggio';if(!npc)return;
    $('ai-controls-equipment').href='/admin/master-engine.php?section=characters&npc='+npc.id;$('ai-controls-relations').href='/admin/png-relazioni.php?npc='+npc.id;
    try{const loaded=await api('controls',{chat,npc_id:npc.id});if(request!==controlRequest)return;controls={...loaded,npc:npc.id};const groups=new Map();for(const field of loaded.schema){
      if(!groups.has(field.group)){const details=document.createElement('details');details.className='ai-control-group';details.open=groups.size===0;const summary=document.createElement('summary');summary.textContent=field.group;const grid=document.createElement('div');grid.className='ai-control-grid';details.append(summary,grid);$('ai-controls-fields').append(details);groups.set(field.group,grid);}
      const label=document.createElement('label');label.textContent=field.label;const input=document.createElement(['select','multiple'].includes(field.type)?'select':['textarea','lines'].includes(field.type)?'textarea':'input');input.dataset.controlScope=field.scope;input.dataset.controlPath=field.key;input.dataset.controlType=field.type;const value=pathGet(loaded[field.scope],field.key);
      if(['select','multiple'].includes(field.type)){if(field.type==='multiple'){input.multiple=true;input.size=4;}for(const item of field.options){const opt=new Option(item.label,item.value);opt.selected=field.type==='multiple'?(value||[]).map(String).includes(String(item.value)):String(item.value)===String(value);input.add(opt);}}
      else if(field.type==='checkbox'){input.type='checkbox';input.checked=Number(value)===1;}
      else{if(input.tagName==='INPUT')input.type=['number','range'].includes(field.type)?field.type:'text';if(field.type==='textarea'||field.type==='lines')input.rows=3;if(field.type==='lines')input.value=(value||[]).join('\n');else input.value=value??'';if(field.min!==undefined)input.min=field.min;if(field.max!==undefined){if(['number','range'].includes(field.type))input.max=field.max;else input.maxLength=field.max;}if(field.type==='range'){const out=document.createElement('output');out.textContent=input.value;input.addEventListener('input',()=>out.textContent=input.value);label.append(out);}}
      label.append(input);groups.get(field.group).append(label);
    }$('ai-controls-save').disabled=false;}catch(error){if(request===controlRequest)feedback(error.message);}
  }
  $('ai-controls-form').addEventListener('submit',event=>{event.preventDefault();guard(async()=>{if(!controls)throw new Error('Seleziona un PNG salvato.');const payload={rules:structuredClone(controls.rules),game:structuredClone(controls.game)};for(const input of event.currentTarget.querySelectorAll('[data-control-path]')){const kind=input.dataset.controlType;let value=kind==='checkbox'?(input.checked?1:0):kind==='multiple'?[...input.selectedOptions].map(o=>Number(o.value)):kind==='lines'?input.value.split('\n').map(x=>x.trim()).filter(Boolean):['range','number'].includes(kind)?Number(input.value):input.value;if(input.dataset.controlScope==='game'&&['organization_office_id','corporation_office_id'].includes(input.dataset.controlPath))value=Number(value);pathSet(payload[input.dataset.controlScope],input.dataset.controlPath,value);}await api('save_controls',{chat,npc_id:controls.npc,revision:controls.revision,reason:event.currentTarget.elements.reason.value,...payload},true);await loadControls(data.npcs.find(n=>Number(n.id)===Number(controls.npc)));feedback('Regole, contesto e comportamento salvati. I nuovi turni useranno questi valori.');});});
  $('ai-controls-inspect').onclick=()=>guard(async()=>{if(!controls)throw new Error('Seleziona un PNG salvato.');const result=await api('inspect',{chat,npc_id:controls.npc});$('ai-controls-prompt').textContent=result.note+'\nModello: '+result.model+' · limite '+result.context_limit+' token\n\n'+result.messages.map(m=>m.role.toUpperCase()+'\n'+m.content).join('\n\n');});
  function updateAdultAge(){const form=$('ai-npc-form');form.elements.profile_age.min=form.elements.adult_sexuality_enabled.checked||form.elements.adult_companion_enabled.checked?'18':'0';}
  $('ai-npc-form').querySelectorAll('[data-adult][type=checkbox]').forEach(field=>field.addEventListener('change',updateAdultAge));
  function showMemory(){ const memory=data.memory.find(m=>String(m.npc_id)===$('ai-memory-owner').value);$('ai-summary').value=memory?.canonical||'';$('ai-chronicle').textContent=memory?.summary||'Nessun intervento ancora registrato.'; }
  async function load() {
    if(!chat){$('ai-editor').hidden=true;return;}
    const selected=$('ai-npc').value;
    data=await api('load',{chat});$('ai-editor').hidden=false;fill($('ai-room-form'),data.room);
    $('ai-npc').replaceChildren(option(0,'Nuovo PNG…'));
    $('ai-test-npc').replaceChildren();$('ai-memory-owner').replaceChildren(option(0,'Luogo · fatti condivisi'));
    for(const npc of data.npcs){$('ai-npc').add(option(npc.id,npc.name+(Number(npc.active)?' · presente':' · non attivo')));$('ai-test-npc').add(option(npc.id,npc.name));$('ai-memory-owner').add(option(npc.id,npc.name));}
    const wanted=initialNpc||selected;initialNpc=null;
    $('ai-npc').value=data.npcs.some(n=>String(n.id)===wanted)?wanted:'0';showNpc();showMemory();
    $('ai-room-form').elements.mode.value='request';$('ai-room-form').elements.trigger_every.value='1';
    $('ai-jobs').replaceChildren();
    for(const job of data.jobs){const row=document.createElement('tr');for(const value of ['#'+job.id,job.kind==='preview'?'Prova':'Chat',states[job.status]||job.status,job.error||new Date(Number(job.created_at)*1000).toLocaleString('it-IT')]){const td=document.createElement('td');td.textContent=value;row.append(td);}const td=document.createElement('td');if(['queued','running'].includes(job.status)){const button=document.createElement('button');button.type='button';button.textContent='Annulla';button.onclick=()=>guard(async()=>{await api('cancel',{chat,id:job.id},true);await load();});td.append(button);}row.append(td);$('ai-jobs').append(row);}
  }
  async function overview(){
    const d=await api('overview');
    $('ai-room').replaceChildren(option('','Seleziona una chat…'));
    for(const room of d.rooms) $('ai-room').add(option(room.chat_key,room.title+(Number(room.enabled)?' · IA attiva':'')));
    $('ai-room').value=chat;
    $('ai-engine').textContent=d.model_ready&&d.worker_alive
      ? '✦ '+d.model+' · '+(d.worker.state==='generating'?'sta scrivendo':'pronto')
      : 'Motore non disponibile · verifica i servizi locali';
  }
  async function guard(fn){try{await fn();}catch(e){feedback(e.message);}}
  for(const [form,action] of [['ai-room-form','save_room'],['ai-npc-form','save_npc'],['ai-memory-form','memory']]) $(form).addEventListener('submit',event=>{event.preventDefault();const current=event.currentTarget;guard(async()=>{const v=values(current);if(action==='save_room')v.version=data.room?.version||1;if(action==='save_npc'){
    v.id=$('ai-npc').value;v.adult={};current.querySelectorAll('[data-adult]').forEach(field=>{v.adult[field.dataset.adult]=field.type==='checkbox'?(field.checked?1:0):(field.hasAttribute('data-number')?Number(field.value):field.value);delete v[field.name];});v.profile={};current.querySelectorAll('[data-profile]').forEach(field=>{v.profile[field.dataset.profile]=field.hasAttribute('data-number')?Number(field.value):field.value;delete v[field.name];});
    v.profile.details=[...$('ai-profile-details').children].map(row=>({label:row.querySelector('[data-detail-label]').value,value:row.querySelector('[data-detail-value]').value}));
  }await api(action,{chat,...v},true);await load();await overview();feedback('Modifiche salvate.');});});
  $('ai-add-profile-detail').onclick=()=>{if($('ai-profile-details').children.length<12)addDetail();};
  $('ai-portrait-upload').onchange=()=>guard(async()=>{
    const file=$('ai-portrait-upload').files[0];if(!file)return;if(!chat)throw new Error('Seleziona prima il luogo.');
    const body=new FormData();body.set('action','upload_portrait');body.set('chat',chat);body.set('portrait',file);
    $('ai-portrait-feedback').textContent='Caricamento del ritratto…';
    const response=await fetch('/api/ai.php',{method:'POST',body,headers:{'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content}});const result=await response.json();
    if(!response.ok)throw new Error(result.error||'Caricamento non riuscito.');
    $('ai-npc-form').elements.profile_portrait.value=result.url;$('ai-npc-portrait-preview').src=result.url;$('ai-portrait-feedback').textContent='Ritratto caricato. Salva il personaggio per applicarlo.';
  });
  $('ai-room').addEventListener('change',()=>guard(async()=>{clearTimeout(previewTimer);chat=$('ai-room').value;$('ai-preview').hidden=true;$('ai-test-submit').disabled=false;await load();feedback('');}));
  $('ai-reload').onclick=()=>guard(async()=>{await overview();await load();feedback('Dati aggiornati.');});
  $('ai-npc').onchange=showNpc;$('ai-memory-owner').onchange=showMemory;
  $('ai-new-npc').onclick=()=>{$('ai-npc').value='0';showNpc();$('ai-npc-form').elements.name.focus();};
  $('ai-test-form').addEventListener('submit',event=>{event.preventDefault();const v=values(event.currentTarget);guard(async()=>{
    $('ai-test-submit').disabled=true;
    try{const result=await api('preview',{chat,...v,request_id:crypto.randomUUID()},true);const originalChat=chat;$('ai-preview').hidden=false;$('ai-preview').textContent='Il personaggio sta preparando una risposta…';let failures=0;
      const poll=async()=>{if(chat!==originalChat)return;try{const d=await api('job',{id:result.job.id});failures=0;if(['queued','running'].includes(d.job.status)){previewTimer=setTimeout(poll,2000);return;}$('ai-preview').textContent=d.job.result?.testo||d.job.error||'Prova interrotta.';$('ai-test-submit').disabled=false;await load();await overview();}catch(e){if(++failures<3)previewTimer=setTimeout(poll,4000);else{$('ai-test-submit').disabled=false;feedback('Connessione interrotta. La richiesta resta visibile nell’attività del luogo.');}}};await poll();
    }catch(e){$('ai-test-submit').disabled=false;throw e;}
  });});
  const tabs=[...document.querySelectorAll('.ai-tabs [role="tab"]')];
  function activate(tab){for(const t of tabs){const active=t===tab;t.setAttribute('aria-selected',active);t.tabIndex=active?0:-1;$(t.getAttribute('aria-controls')).hidden=!active;}}
  $('ai-controls-adult').onclick=()=>{activate($('tab-npcs'));$('ai-adult-settings').open=true;$('ai-adult-settings').scrollIntoView({behavior:'smooth',block:'start'});};
  tabs.forEach((tab,index)=>{tab.onclick=()=>activate(tab);tab.onkeydown=e=>{let i;if(e.key==='ArrowRight')i=(index+1)%tabs.length;if(e.key==='ArrowLeft')i=(index+tabs.length-1)%tabs.length;if(e.key==='Home')i=0;if(e.key==='End')i=tabs.length-1;if(i!==undefined){e.preventDefault();activate(tabs[i]);tabs[i].focus();}};});
  window.addEventListener('pagehide',()=>{clearTimeout(previewTimer);});
  window.addEventListener('ai:model-changed',()=>guard(overview));
  guard(async()=>{await overview();if(chat)await load();if(['scene','npcs','controls','memory','test'].includes(requested.get('tab')))activate($('tab-'+requested.get('tab')));else if(requested.get('npc'))activate($('tab-npcs'));});
})();
