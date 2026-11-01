(() => {
 'use strict';const npc=Number(document.body.dataset.socialNpc),player=Number(document.body.dataset.socialPlayer);
 const memories=JSON.parse(document.getElementById('npc-admin-memories').textContent);
 const memorySelect=document.getElementById('npc-memory-select');if(memorySelect)memorySelect.onchange=event=>{const m=memories.find(x=>Number(x.id)===Number(event.target.value));const f=event.target.closest('form');f.elements.summary.value=m?.summary||'';f.elements.visible.checked=m?!!Number(m.visible):true;f.elements.active.checked=m?!!Number(m.active):true;};
 document.querySelectorAll('[data-relation-editor]').forEach(form=>{
  form.querySelector('[data-copy-relation-context]')?.addEventListener('click',event=>{const field=form.elements.context_description;field.value=event.currentTarget.dataset.copyRelationContext;field.focus();});
  const preview=card=>{const range=card.querySelector('[type=range]'),number=card.querySelector('[type=number]');const value=Math.max(Number(number.min),Math.min(100,Number(number.value)||0));range.value=number.value=value;card.querySelector('output').textContent=(value>0?'+':'')+value;const point=(value+100)/2;card.style.setProperty('--relation-from',Math.min(50,point)+'%');card.style.setProperty('--relation-to',Math.max(50,point)+'%');card.style.setProperty('--relation-colour',value<0?'#c4768a':'#e3c485');range.setAttribute('aria-valuemin',number.min);range.setAttribute('aria-valuenow',String(value));};
  form.querySelectorAll('[data-relation-control]').forEach(card=>{preview(card);card.addEventListener('input',event=>{if(event.target.type==='range')card.querySelector('[type=number]').value=event.target.value;preview(card);});});
 });
 document.querySelectorAll('[data-admin-action]').forEach(form=>form.onsubmit=async event=>{
  event.preventDefault();if(form.dataset.busy)return;form.dataset.busy='1';const button=form.querySelector('[type=submit]');button.disabled=true;
  const values=Object.fromEntries(new FormData(form));form.querySelectorAll('[type=checkbox]').forEach(c=>values[c.name]=c.checked?1:0);form.querySelectorAll('[type=number],[type=range]').forEach(c=>c.name&&(values[c.name]=Number(c.value)));
  if(values.id!==undefined)values.id=Number(values.id);
  form.dataset.nonce ||= crypto.randomUUID();const payload={action:form.dataset.adminAction,npc_id:npc,player_id:player,request_id:form.dataset.nonce,...(form.dataset.adminAction==='admin_rules'?{settings:values}:values)};
  try{const res=await fetch('/api/ai-social.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify(payload)});const data=await res.json();if(!res.ok)throw Error(data.error||'Operazione non riuscita.');location.reload();}catch(e){document.querySelector('[data-admin-feedback]').textContent=e.message;button.disabled=false;delete form.dataset.busy;}
 });
})();
