(() => {
'use strict';
const picker=document.querySelector('[data-session-location-picker]');
if(picker){
 const search=picker.querySelector('#session-location-search'),cards=[...picker.querySelectorAll('[data-session-location]')],count=picker.querySelector('#session-location-count'),empty=picker.querySelector('#session-location-empty');
 const normalize=value=>String(value).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('it');
 search.addEventListener('input',()=>{const query=normalize(search.value.trim());let visible=0;for(const card of cards){card.hidden=!normalize(card.dataset.search).includes(query);if(!card.hidden)visible++;}count.textContent=visible+(visible===1?' luogo disponibile':' luoghi disponibili');empty.hidden=visible>0;});
 return;
}
const root=document.querySelector('[data-session-manager]');if(!root)return;
const $=id=>root.querySelector('#'+id),endpoint=new URL(root.dataset.endpoint,location.href);
let state=null,busy=false,timer,stopped=false,lastSession=0,draft=[],dirty=false,roster=[],people=[],pending=null,draftVersion=0,draftGuidance=0;
const keyOf=person=>person.kind+':'+person.id;
const normalize=items=>Array.isArray(items)?items.filter(p=>p&&['pg','npc'].includes(p.kind)&&Number(p.id)>0).map(p=>({kind:p.kind,id:Number(p.id),name:String(p.name||'Personaggio')})):[];
function feedback(message,tone='success'){$('session-feedback').textContent=message;$('session-feedback').dataset.tone=tone;$('session-feedback').hidden=!message;}
function permissions(){
 const auto=state?.conductor?.mode==='auto'||state?.quest?.mode==='auto';
 const can=Boolean(state?.can_manage),s=state?.session;
 root.querySelectorAll('[data-session-mutating]').forEach(control=>{const rule=control.dataset.sessionMutating;control.disabled=busy||!state||(rule==='takeover'?!state.can_takeover:rule==='create'?(!!s||!state.can_open):!s||!can||(rule==='turns'&&auto));});
 root.querySelector('[data-session-action="ai"]').disabled ||= !state?.ai_available||auto;
 $('session-master-select').disabled ||= !$('session-master-select').value;
 root.querySelector('#session-transfer-form button').disabled ||= !$('session-master-select').value;
 $('session-turn-add').disabled ||= !$('session-turn-select').value;
 $('session-turn-all').disabled ||= !roster.length;
 $('session-turn-save').disabled ||= !dirty||!draft.length;
 $('session-turn-free').disabled ||= auto;
 root.querySelector('[data-session-action="next"]').disabled ||= !(state?.turn_order?.length);
 $('session-manage-note').hidden=!s||can;
 root.querySelectorAll('[data-edge]').forEach(button=>button.disabled=true);
}
function renderTurns(){
 const list=$('session-turn-list');list.replaceChildren();
 draft.forEach((person,index)=>{
  const li=document.createElement('li'),number=document.createElement('span'),name=document.createElement('span'),type=document.createElement('small'),controls=document.createElement('div');
  number.className='session-control-turn-number';number.textContent=String(index+1);name.className='session-control-turn-name';name.textContent=person.name;type.textContent=person.kind==='npc'?'PNG':'Personaggio';name.append(type);controls.className='session-control-turn-controls';
  for(const [action,label,text] of [['up','Sposta prima '+person.name,'↑'],['down','Sposta dopo '+person.name,'↓'],['remove','Togli '+person.name+' dall’ordine','×']]){
   const b=document.createElement('button');b.type='button';b.textContent=text;b.setAttribute('aria-label',label);b.setAttribute('data-ui-no-icon','');b.dataset.sessionMutating='turns';
   if((action==='up'&&index===0)||(action==='down'&&index===draft.length-1))b.dataset.edge='1';
   b.addEventListener('click',()=>{if(busy||!state?.can_manage)return;markDraft();if(action==='remove')draft.splice(index,1);else {const next=index+(action==='up'?-1:1);if(next<0||next>=draft.length)return;[draft[index],draft[next]]=[draft[next],draft[index]];}renderTurns();});controls.append(b);
  }
  li.append(number,name,controls);list.append(li);
 });
 $('session-turn-empty').hidden=Boolean(draft.length);$('session-turn-draft').hidden=!dirty;$('session-turn-reset').hidden=!dirty;
 const select=$('session-turn-select'),selected=select.value;select.replaceChildren();select.add(new Option('Scegli fra i presenti…',''));
 for(const person of roster)if(!draft.some(p=>keyOf(p)===keyOf(person)))select.add(new Option(person.name+(person.kind==='npc'?' · PNG':''),keyOf(person)));
 if([...select.options].some(o=>o.value===selected))select.value=selected;permissions();
}
function markDraft(){if(!dirty){draftVersion=state.version;draftGuidance=state.guidance_version;}dirty=true;}
function refreshRoster(){
 const own=Number(state?.conductor?.id||0),available=Array.isArray(state?.turn_candidates)?normalize(state.turn_candidates):normalize(people),entries=[...available,...normalize(state?.turn_order)];
 roster=[...new Map(entries.filter(p=>p.kind!=='pg'||p.id!==own).map(p=>[keyOf(p),p])).values()];renderTurns();
}
function render(next){
 state=next;const s=state.session,q=state.quest,auto=state.conductor?.mode==='auto'||q?.mode==='auto';
 $('session-create').hidden=Boolean(s);$('session-running').hidden=!s;$('session-end').hidden=!s;$('session-quick-actions').hidden=!s;$('session-workspace').dataset.active=String(Boolean(s));$('session-current-name').textContent=s?.nome||'Nessuna sessione aperta';
 const statuses={active:'Giocata in corso',resolving:'Si conclude il giro',review:'Esiti da convalidare',attention:'Serve il Master',closed:'Giocata conclusa'};
 $('session-current-state').textContent=s?(statuses[q?.status]||'Giocata in corso'):'Il tavolo è pronto';$('session-current-state').dataset.mode=auto?'auto':'human';
 $('session-conductor').textContent=s?(auto?'Master IA':state.conductor?.name||'Master umano'):'—';
 $('session-summary-detail').textContent=s?(auto?'La scena è affidata al Master IA. Puoi assumerne la conduzione quando hai i permessi.':'La scena è guidata da un Master umano. Regia, turni e interventi sono raccolti qui.'):'Dai un titolo alla giocata per aprire una sessione e conservarne il resoconto.';
 $('session-direction-description').textContent=auto?'Il Master IA sta conducendo. La presa di regia interrompe la conduzione automatica e conserva la storia già svolta.':'Conduce '+(state.conductor?.name||'il Master umano')+'. Puoi affidare la scena a un altro Master o alla regia automatica disponibile.';
 $('session-ai-availability').textContent=state.ai_available?(auto?'Il Master IA sta già conducendo questa giocata.':'La conduzione automatica è disponibile nel luogo.'):'La conduzione automatica non è disponibile nel luogo.';
 $('session-round').textContent=state.round?String(state.round):'Gioco libero';$('session-turn').textContent=typeof state.turn==='string'?state.turn:state.turn?.name||'Intervento libero';
 if(s){
  if(lastSession&&lastSession!==Number(s.id)){dirty=false;pending=null;}lastSession=Number(s.id);
  $('session-lock').textContent=Number(s.locked)?'riservato':'aperto';$('session-lock-description').textContent=Number(s.locked)?'La giocata conserva il gruppo dei partecipanti.':'I presenti possono unirsi alla giocata.';$('session-lock-button').textContent=Number(s.locked)?'Apri l’ingresso':'Rendi riservata';
  $('session-dice').textContent=Number(s.dadi)?'disponibili':'disattivati';$('session-dice-button').textContent=Number(s.dadi)?'Disattiva i dadi':'Attiva i dadi';
 }else if(lastSession){$('session-last-log').href='/sessione/viewlog.php?azione=aprilog&id='+lastSession;$('session-last-log').hidden=false;dirty=false;}
 const select=$('session-master-select'),selected=select.value;select.replaceChildren();
 for(const m of state.masters||[])if(Number(m.id)!==Number(state.conductor?.id))select.add(new Option(String(m.name),String(m.id)));
 if(!select.options.length)select.add(new Option('Nessun altro Master attivo qui',''));if([...select.options].some(o=>o.value===selected))select.value=selected;
 $('session-transfer-note').textContent=select.value?'Il trasferimento riesce solo se il Master scelto è ancora attivo nella stessa chat.':'Per trasferire la regia, l’altro Master deve essere attivo nella stessa chat.';
 if(!dirty)draft=normalize(state.turn_order);refreshRoster();permissions();root.dispatchEvent(new CustomEvent('alphano:session-state',{bubbles:true,detail:state}));
}
async function refresh(){
 clearTimeout(timer);if(stopped)return;
 if(!busy)try{const url=new URL(endpoint);url.searchParams.set('status','1');const response=await fetch(url,{cache:'no-store',credentials:'same-origin',signal:AbortSignal.timeout(6000)}),result=await response.json();if(!response.ok)throw new Error(result.error||'Impossibile leggere la scena.');render(result);$('session-connection').hidden=true;}
 catch(_){$('session-connection').textContent='Aggiornamento momentaneamente non disponibile. Le scelte che stai preparando restano nel pannello.';$('session-connection').hidden=false;}
 if(!stopped)timer=setTimeout(refresh,document.hidden?10000:5000);
}
async function action(name,fields={},confirmation=null){
 if(busy||!state)return;busy=true;permissions();
 try{
  if(confirmation){const answer=await window.alphanoDialog.notice({cancelLabel:'Annulla',...confirmation});if(answer!=='ack')return;}
  const raw=new URLSearchParams(fields);raw.set('azione',name);const signature=raw.toString()+':'+Number(state.session?.id||0);
  if(!pending||pending.signature!==signature){raw.set('session_id',String(state.session?.id||0));raw.set('version',String(name==='turns'&&dirty?draftVersion:state.version??''));raw.set('guidance_version',String(name==='turns'&&dirty?draftGuidance:state.guidance_version??''));raw.set('_csrf',root.dataset.csrf);raw.set('request_key',crypto.randomUUID());pending={signature,body:raw.toString()};}
  const response=await fetch(endpoint,{method:'POST',body:new URLSearchParams(pending.body),credentials:'same-origin',signal:AbortSignal.timeout(15000)}),result=await response.json();
  if(!response.ok){pending=null;throw new Error(result.error||'Operazione non completata.');}
  pending=null;if(['turns','crea','chiudi'].includes(name))dirty=false;if(result.state)render(result.state);else if(Object.prototype.hasOwnProperty.call(result,'session'))render(result);
  if(result.logFile)for(const id of ['session-log','session-last-log']){$(id).href=result.logFile;$(id).hidden=false;}
  const messages={crea:'La giocata è aperta.',chiudi:'Giocata conclusa. Il resoconto è disponibile.',takeover:'Hai assunto la regia della scena.',ai:'La scena è stata affidata al Master IA.',transfer:'La regia è stata trasferita.',turns:'Ordine degli interventi aggiornato.',next:'Il prossimo personaggio può intervenire.',cambia:'Ingresso aggiornato.',dadi:'Disponibilità dei dadi aggiornata.'};feedback(messages[name]||'Scelta aggiornata.');root.dispatchEvent(new CustomEvent('alphano:session-updated',{bubbles:true}));
 }catch(error){feedback(error.name==='TimeoutError'?'La conferma sta impiegando più tempo. Puoi riprovare: la stessa richiesta non verrà applicata due volte.':error.message,'error');}
 finally{busy=false;permissions();refresh();}
}
root.querySelectorAll('[data-session-help]').forEach(b=>b.addEventListener('click',()=>{const p=$(b.dataset.sessionHelp),open=b.getAttribute('aria-expanded')==='true';b.setAttribute('aria-expanded',String(!open));p.hidden=open;}));
root.querySelectorAll('[data-session-scroll]').forEach(button=>button.addEventListener('click',()=>{$(button.dataset.sessionScroll)?.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});}));
$('session-create-form').addEventListener('submit',event=>{event.preventDefault();action('crea',new URLSearchParams(new FormData(event.currentTarget)));});
$('session-transfer-form').addEventListener('submit',event=>{event.preventDefault();const master=$('session-master-select');if(master.value)action('transfer',{master_id:master.value},{title:'Trasferisci la regia',message:'Affidare la giocata a '+master.selectedOptions[0].textContent+'?',detail:'Il nuovo Master deve essere attivo nella stessa chat. La storia è conservata.',buttonLabel:'Trasferisci la regia'});});
root.querySelectorAll('[data-session-action]').forEach(b=>b.addEventListener('click',()=>{
 const name=b.dataset.sessionAction;let confirm=null;
 if(name==='chiudi')confirm={title:'Concludi la giocata',message:'Chiudere la sessione attuale?',detail:'La chiusura viene annunciata in chat. Il resoconto resta nell’archivio e le risposte automatiche vengono fermate.',buttonLabel:'Chiudi la giocata'};
 if(name==='takeover')confirm={title:'Assumi la regia',message:'Vuoi condurre personalmente la giocata?',detail:'La conduzione precedente viene sostituita; la storia e gli interventi già svolti restano conservati.',buttonLabel:'Assumi la regia'};
 if(name==='ai')confirm={title:'Affida al Master IA',message:'Lasciare la conduzione automatica della scena?',detail:'Il Master IA proseguirà dal contesto e dai personaggi presenti. Potrai riprendere la regia con i permessi necessari.',buttonLabel:'Affida la scena'};
 action(name,{},confirm);
}));
$('session-master-select').addEventListener('change',permissions);$('session-turn-select').addEventListener('change',permissions);
$('session-turn-add').addEventListener('click',()=>{const found=roster.find(p=>keyOf(p)===$('session-turn-select').value);if(found&&!draft.some(p=>keyOf(p)===keyOf(found))){markDraft();draft.push({...found});renderTurns();}});
$('session-turn-all').addEventListener('click',()=>{markDraft();draft=roster.map(p=>({...p}));renderTurns();});
$('session-turn-save').addEventListener('click',()=>action('turns',{turn_order:JSON.stringify(draft.map(({kind,id})=>({kind,id})))}));
$('session-turn-reset').addEventListener('click',()=>{dirty=false;draft=normalize(state?.turn_order);renderTurns();});
$('session-turn-free').addEventListener('click',()=>action('turns',{turn_order:'[]'}));
document.addEventListener('alphano:participants-state',event=>{if(event.detail?.chat===state?.chat_key||event.detail?.chat===endpoint.searchParams.get('chat')){people=event.detail.participants;refreshRoster();}});
$('session-close-panel').addEventListener('click',()=>{try{if(typeof window.parent.CloseSessionManager==='function'){window.parent.CloseSessionManager();return;}}catch(_){}if(window.opener)window.close();else if(history.length>1)history.back();else location.href='/chat/index.php?chat='+encodeURIComponent(endpoint.searchParams.get('chat')||'');});
window.addEventListener('pagehide',()=>{stopped=true;clearTimeout(timer);});document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
try{const initial=JSON.parse($('session-initial-state').textContent);if(initial)render(initial);}catch(_){}permissions();refresh();
})();
