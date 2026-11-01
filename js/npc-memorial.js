(() => {
 'use strict';
 const form=document.querySelector('[data-npc-death-correction]');if(!form)return;
 const button=form.querySelector('button'),feedback=form.querySelector('[data-correction-feedback]');let busy=false;
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(busy||!form.reportValidity())return;busy=true;button.disabled=true;
  feedback.textContent='Registrazione della rettifica…';
  try{
   const data=new FormData(form),response=await fetch('/api/ai.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({action:'correct_npc_death',chat:form.dataset.chat,npc_id:Number(form.dataset.npc),death_id:Number(form.dataset.death),hit_points:Number(data.get('hit_points')),reason:data.get('reason'),confirmation:'RETTIFICA'})});
   const answer=await response.json();if(!response.ok)throw Error(answer.error||'Rettifica non completata.');
   feedback.textContent='Rettifica registrata. Il PNG rimane inattivo; puoi riattivarlo dal pannello amministrativo.';
   for(const input of form.querySelectorAll('input,textarea'))input.disabled=true;
   button.textContent='Rettifica registrata';
  }catch(error){feedback.textContent=error.message;busy=false;button.disabled=false;}
 });
})();
