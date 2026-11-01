/** Illustrated racial limitations. Values and recorded conditions remain server-authoritative. */
export function renderRacialMalus(host,sheet){
 if(!host||!sheet.malus)return;
 host.replaceChildren();
 const el=(tag,text,className)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(className)e.className=className;return e;};
 const malus=sheet.malus,heading=el('h3','Vulnerabilità della stirpe');
 host.append(heading,el('p','Limiti innati e condizioni attuali di '+sheet.identity.name+'. Le penalità dello stesso tipo non si sommano; condizioni diverse restano distinte.'));
 const physical=malus.physical,mental=malus.mental,balance=el('div',undefined,'malus-current');
 for(const [name,result] of [['Prove fisiche',physical],['Prove mentali',mental]]){
  const chip=el('div',undefined,'malus-penalty');chip.append(el('span',name),el('strong',result.penalty>0?'−'+result.penalty+' p.p.':'Nessuna penalità'));
  if(result.penalty>0)chip.classList.add('has-penalty');balance.append(chip);
 }
 host.append(balance);
 if(physical.incapacitated)host.append(el('p','Incapacità di agire registrata.', 'malus-alert'));
 if(physical.regeneration_blocked)host.append(el('p','Rigenerazione della zona ferita sospesa.', 'malus-alert'));
 if(malus.command)host.append(el('p','Comando soprannaturale attivo: '+malus.command.command,'malus-alert'));
 if(malus.corruption)host.append(el('p',malus.corruption.resisted?'Offerta corruttiva: resistenza riuscita.':'Offerta corruttiva: concessione temporanea e proporzionata, senza obbedienza assoluta.'));
 if(!malus.catalog.length){host.append(el('p','Nessun malus di questa stirpe è definito nel manuale Umani e Malus v2. Consulta il regolamento specifico.'));return;}
 const active=new Set([...(physical.active||[]),...(mental.active||[])]);
 if(malus.command)active.add(malus.family==='vampire'?8:2);
 if(malus.corruption&&!malus.corruption.resisted)active.add(3);
 if(malus.state.destroyed||malus.state.dismembered)active.add(6);
 const family={human:'Umani',vampire:'Vampiri',lycan:'Lycan'},grid=el('div',undefined,'malus-catalog');
 for(const m of malus.catalog){
  const card=el('details',undefined,'malus-card'),summary=el('summary'),copy=el('span',undefined,'malus-card-copy'),labels=el('span',undefined,'malus-card-labels');
  card.dataset.malusKey=m.key;card.dataset.family=m.family;
  const image=el('img');image.src=m.image;image.alt='';image.width=88;image.height=88;image.loading='lazy';image.decoding='async';image.className='malus-card-image';
  labels.append(el('span',family[m.family]||'Stirpe','malus-family'),el('span',active.has(m.number)?'Effetto attivo':'Limite innato','malus-card-state'));
  if(active.has(m.number))card.classList.add('is-active');
  copy.append(labels,el('strong',m.name),el('span',m.effect,'malus-card-effect'),el('span','Dettagli e regole','malus-card-hint'));
  summary.append(image,copy,el('span','⌄','malus-card-chevron'));summary.lastChild.setAttribute('aria-hidden','true');
  const body=el('div',undefined,'malus-card-body');body.append(el('p',m.description));
  for(const [label,value] of [['Innesco',m.trigger],['Durata',m.duration],['Effetto',m.effect],['Regola',m.mechanic],['Nota',m.note]])if(value){const p=el('p');p.append(el('strong',label+': '),document.createTextNode(value));body.append(p);}
  card.append(summary,body);grid.append(card);
 }
 host.append(grid);
 if(!sheet.can_manage_malus)return;
 const details=el('details',undefined,'malus-management'),summary=el('summary','Master · Gestisci le condizioni'),form=el('form'),fields=el('div'),reason=el('textarea'),save=el('button','Registra le condizioni'),feedback=el('p');
 fields.className='creation-stat-grid';details.append(summary);
 for(const [key,[label,min,max]] of Object.entries(malus.fields)){
  const l=el('label',label),i=el('input');i.type='number';i.name=key;i.min=min;i.max=max;i.step='1';i.value=malus.state[key]||0;i.required=true;l.append(i);fields.append(l);
 }
 const why=el('label','Motivo e contesto accertato');reason.required=true;reason.maxLength=500;reason.rows=3;why.append(reason);feedback.setAttribute('role','status');save.type='submit';form.append(fields,why,save,feedback);details.append(form);host.append(details);
 form.addEventListener('submit',async e=>{
  e.preventDefault();save.disabled=true;
  try{
   const conditions=Object.fromEntries(Object.keys(malus.fields).map(k=>[k,Number(form.elements[k].value)]));
   const response=await fetch('/api/rpg.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':await window.alphanoRuntime.csrfToken()},body:JSON.stringify({action:'save_malus',kind:sheet.identity.kind,id:sheet.identity.id,conditions,reason:reason.value})});
   const result=await response.json();if(!response.ok)throw Error(result.error||'Condizioni non salvate.');renderRacialMalus(host,result);
  }catch(error){feedback.textContent=error.message;}finally{save.disabled=false;}
 });
}
