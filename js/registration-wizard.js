import {createCharacterBuilder} from './character-creation.js?v=registration-wizard-20261030';

export function createRegistrationWizard(form){
 const panels=[...form.querySelectorAll('[data-registration-step]')],steps=form.querySelector('[data-registration-steps]'),back=form.querySelector('[data-registration-back]'),next=form.querySelector('[data-registration-next]'),submit=form.querySelector('[type=submit]'),stage=form.querySelector('[data-registration-stage]'),caption=form.querySelector('[data-registration-caption]'),review=form.querySelector('[data-registration-review]');
 let index=0,furthest=0,data=null,points=null,innates=null,busy=false;
 const buttons=panels.map((panel,i)=>{
  const li=document.createElement('li'),b=document.createElement('button');
  b.type='button';b.setAttribute('aria-label',`Passaggio ${i+1}: ${panel.dataset.title}`);
  b.innerHTML='<span class="registration-step-medallion" aria-hidden="true"><img alt="" width="48" height="48"><span class="registration-step-number"></span></span><small></small>';
  b.querySelector('img').src='/assets/placeholder.svg';
  b.querySelector('.registration-step-number').textContent=i+1;
  b.querySelector('small').textContent=panel.dataset.short;
  b.addEventListener('click',()=>{if(!busy&&i<=furthest)show(i);});li.append(b);steps.append(li);return b;
 });
 function focus(){panels[index].querySelector('h3').focus({preventScroll:true});}
 function show(i,moveFocus=true){const changed=index!==i;index=i;furthest=Math.max(furthest,i);panels.forEach((p,n)=>p.hidden=n!==index);buttons.forEach((b,n)=>{b.disabled=busy||n>furthest;if(n===index)b.setAttribute('aria-current','step');else b.removeAttribute('aria-current');b.classList.toggle('is-complete',n<index);});caption.textContent=`Passaggio ${index+1} di ${panels.length} · ${panels[index].dataset.title}`;back.hidden=index===0;next.hidden=index===panels.length-1;submit.hidden=index!==panels.length-1;back.disabled=busy;next.disabled=busy||!data;submit.disabled=busy||!data;stage.scrollTop=0;const art=document.querySelector('[data-registration-art]');if(art)art.src=panels[index].querySelector('.registration-step-image').src;document.querySelector('[data-registration-art-caption]').textContent=panels[index].dataset.title;if(index===panels.length-1)summary();if(moveFocus)focus();if(changed)form.dispatchEvent(new CustomEvent('registration:step'));}
 function validate(i){
  if(!data)throw Error('Attendi il caricamento delle guide e riprova.');
  if(i===0){const c=form.elements.passwdConfirm;c.setCustomValidity(c.value===form.elements.passwd.value?'':'Le password non corrispondono.');}
  const invalid=[...panels[i].querySelectorAll('input,select,textarea')].find(el=>!el.checkValidity());if(invalid){show(i,false);invalid.reportValidity();return false;}
  if(i===3||i===4){try{(i===3?points:innates).payload();}catch(e){show(i);throw e;}}
  return true;
 }
 function advance(){if(busy)return;try{if(validate(index))show(Math.min(index+1,panels.length-1));}catch(e){form.dispatchEvent(new CustomEvent('registration:error',{detail:e.message}));}}
 next.addEventListener('click',advance);back.addEventListener('click',()=>{if(!busy)show(Math.max(0,index-1));});
 for(const name of ['passwd','passwdConfirm'])form.elements[name].addEventListener('input',()=>form.elements.passwdConfirm.setCustomValidity(''));
 function payload(){return {allocation:points.payload().allocation,innates:innates.payload().innates};}
 function summary(){
  if(!data)return;review.replaceChildren();
  const p=payload(),chosen=data.creation.abilities.filter(a=>p.innates.includes(a.id)),selected=(list,value,key='id')=>list.find(a=>String(a[key])===value);
  const alignment=selected(data.alignments,form.elements.allineamento.value,'code'),race=selected(data.races,form.elements.race.value),ethnicity=selected(data.ethnicities,form.elements.etnia.value);
  const wandering=form.elements.city.value==='0',city=wandering?data.wanderer:selected(data.cities,form.elements.city.value);
  // Use the same sex symbols as character-markers; other artwork comes from the selected guide.
  const sexImage={Maschio:'/assets/placeholder.svg',Femmina:'/assets/placeholder.svg'}[form.elements.sesso.value];
  const basics=document.createElement('dl'),identity=document.createElement('dl'),details=document.createElement('dl');
  basics.className='registration-review-basics';identity.className='registration-review-identity';details.className='registration-review-details';
  const rows=[
   ['Personaggio',form.elements.pgName.value],['E-mail',form.elements.mail.value],
   ['Sesso',form.elements.sesso.value,sexImage],
   ['Allineamento',alignment?.name,alignment?.image],['Razza',race?.nome,race?.image],
   ['Caratteristiche',data.creation.stats.map(s=>`${s.name}: ${s.base+Number(p.allocation[s.key])} (+${p.allocation[s.key]})`).join(' · ')],
   ['Quattro innate gratuite',chosen.map(a=>a.name).join(' · ')],
   ['Etnia',ethnicity?.nome,ethnicity?.image],
   ['Cittadinanza',wandering?'Viandante · nessuna cittadinanza':city?.city,city?.image]
  ];
  for(const [label,value,src] of rows){
   const row=document.createElement('div'),dt=document.createElement('dt'),dd=document.createElement('dd'),text=document.createElement('span');
   dt.textContent=label;text.textContent=value||'—';
   if(src){
    const img=document.createElement('img');img.src=src;img.alt='';img.width=38;img.height=38;img.className='registration-review-icon';
    if(label==='Sesso')img.classList.add('is-sex');
    dd.className='registration-review-choice';dd.append(img);
   }
   dd.append(text);row.append(dt,dd);
   (src?identity:['Personaggio','E-mail'].includes(label)?basics:details).append(row);
  }
  review.append(basics,identity,details);
 }
 function setCatalog(catalog){data=catalog;points=createCharacterBuilder(form.querySelector('[data-registration-points]'),{catalog:data.creation,needsPoints:true,needsInnates:false,registration:true});innates=createCharacterBuilder(form.querySelector('[data-registration-innates]'),{catalog:data.creation,needsPoints:false,needsInnates:true,registration:true});show(index,false);}
 show(0,false);
 return {setCatalog,open(){show(index,false);focus();},reset(){index=0;furthest=0;if(data)setCatalog(data);},isFinal(){return index===panels.length-1;},advance,payload,validateAll(){for(let i=0;i<panels.length;i++)if(!validate(i))return false;return true;},setBusy(value){busy=value;stage.inert=value;form.setAttribute('aria-busy',String(value));show(index,false);},showConfirmations(){show(panels.length-1);}};
}
