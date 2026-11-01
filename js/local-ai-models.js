(() => {
  'use strict';
  const form=document.getElementById('local-model-form');if(!form)return;
  const current=document.getElementById('local-model-current'),phase=document.getElementById('local-model-phase'),bar=document.getElementById('local-model-progress'),button=document.getElementById('local-model-switch');
  let snapshot=null,timer,initialized=false,submitted=null,disposed=false,editing=false;
  const keys=['threads','slots','context','temperature','top_p','top_k','min_p','repeat_penalty'];
  async function api(body){const options={credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(6000)};if(body)Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(body)});const response=await fetch('/api/ai-models.php',options),result=await response.json();if(!response.ok)throw new Error(result.error||'Controllore locale non disponibile.');return result;}
  function render(data){
    const previousRevision=snapshot?.active.revision;snapshot=data;current.textContent='Ora i PNG hanno il modello: '+data.active.model;
    if(!initialized){form.elements.id.replaceChildren(...data.models.map(model=>new Option(model.name+' · '+model.quant+' · '+(model.bytes/1024**3).toFixed(2)+' GiB',model.id)));initialized=true;}
    if(!editing&&previousRevision!==data.active.revision){form.elements.id.value=data.active.id;for(const key of keys)form.elements[key].value=data.active.settings[key];}
    const op=data.operation;button.disabled=!!data.busy;
    if(op){if(op.progress===null)bar.removeAttribute('value');else bar.value=op.progress;phase.textContent=op.phase+(op.error?' · '+op.error:'');if(op.id===submitted&&op.state==='done'){form.elements.id.value=data.active.id;for(const key of keys)form.elements[key].value=data.active.settings[key];submitted=null;editing=false;window.dispatchEvent(new CustomEvent('ai:model-changed'));}}
    else{bar.value=100;phase.textContent='Modello pronto. Puoi scegliere un altro modello locale.';}
  }
  form.addEventListener('input',()=>{editing=true;});form.addEventListener('change',()=>{editing=true;});
  async function poll(){if(disposed)return;try{render(await api());}catch(error){phase.textContent=error.message+' Il modello già attivo non viene fermato.';button.disabled=true;}finally{if(!disposed)timer=setTimeout(poll,snapshot?.busy?1000:5000);}}
  form.addEventListener('submit',async event=>{event.preventDefault();if(!snapshot)return;button.disabled=true;clearTimeout(timer);try{const settings=Object.fromEntries(keys.map(key=>[key,Number(form.elements[key].value)]));const result=await api({id:form.elements.id.value,settings,revision:snapshot.active.revision});submitted=result.operation?.id;render(result);}catch(error){phase.textContent=error.message;button.disabled=false;}finally{timer=setTimeout(poll,1000);}});
  window.addEventListener('pagehide',()=>{disposed=true;clearTimeout(timer);});poll();
})();
