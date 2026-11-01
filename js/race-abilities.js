import {renderLearningBalance,canLearnAbility} from './ability-learning.js';
import {createAbilityGrimoire} from './ability-grimoire.js?v=innate-learning-20261030';
const roots=[...document.querySelectorAll('[data-race-grimoire]')],shop=document.querySelector('#race-shop');
if(roots.length){
 let sheet=null,busy=false;const feedback=shop?.querySelector('[data-ability-feedback]');
 async function api(action,payload){const response=await fetch('/api/rpg.php?'+new URLSearchParams({action}),{credentials:'same-origin',cache:'no-store',...(payload?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||await window.alphanoRuntime.csrfToken()},body:JSON.stringify({action,...payload})}:{})});const data=await response.json();if(!response.ok)throw Error(data.error||'Operazione non disponibile.');return data;}
 const views=roots.map(root=>({catalog:JSON.parse(root.querySelector('[data-grimoire-catalog]').textContent),view:createAbilityGrimoire(root.querySelector('[data-grimoire-mount]'),{title:root.dataset.grimoireTitle,onLearn:learn})}));
 function paint(){
  if(sheet)renderLearningBalance(shop?.querySelector('[data-rpg-learning-balance]'),sheet);
  const owned=new Map((sheet?.abilities||[]).map(a=>[Number(a.id),a]));
  for(const {catalog,view} of views)view.update(catalog.map(a=>({...a,...(owned.get(Number(a.id))||{owned:false,available:false,reason:sheet?'Non disponibile per la tua stirpe o forma attuale':'Verifica disponibilità…'}),requirements_text:a.requirements_text})),{editable:Boolean(sheet?.can_edit)&&!busy});
 }
 async function load(){sheet=await api('profile');paint();}
 async function learn(ability){
  if(busy||!canLearnAbility(ability,sheet?.can_edit))return;busy=true;paint();if(feedback)feedback.textContent='';
  try{const answer=await window.alphanoDialog.notice({title:'Apprendi '+ability.name,message:'Investire '+ability.cost+' PE? Dopo l’acquisto resteranno '+(sheet.xp.available-ability.cost)+' PE.',detail:ability.description,buttonLabel:'Apprendi',eyebrow:'Alphano · Progressione'});if(answer!=='ack')return;await api('buy_ability',{ability_id:ability.id,request_id:crypto.randomUUID()});if(feedback)feedback.textContent=ability.name+' appresa. Punti esperienza aggiornati.';}
  catch(error){if(feedback)feedback.textContent=error.message;}
  finally{try{await load();}catch(error){if(feedback)feedback.textContent=error.message;}busy=false;paint();}
 }
 paint();load().catch(error=>{if(feedback)feedback.textContent=error.message;const balance=shop?.querySelector('[data-rpg-learning-balance]');if(balance)balance.textContent='PE non disponibili';});
 window.addEventListener('focus',()=>{if(!busy)load().catch(()=>{});});
}
