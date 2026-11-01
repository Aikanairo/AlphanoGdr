/* Il contenuto delle ricevute arriva solo dall’API autenticata. */
(() => {
 'use strict';
 document.addEventListener('DOMContentLoaded',()=>{
  const group=document.getElementById('inbox-group'),view=document.getElementById('inbox-view');if(!group||!view)return;
  const rows=document.getElementById('inbox-rows'),status=document.getElementById('inbox-status'),message=document.getElementById('inbox-message');
  const params=new URLSearchParams(location.search),labels={gioco:'Personaggio e scambi',organizzazioni:'Organizzazioni',corporazioni:'Corporazioni',citta:'Città',accademia:'Accademia',bacheche:'Bacheche',master:'Master',gestione:'Gestione'};
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const date=v=>v?new Date(Number(v)*1000).toLocaleString('it-IT',{dateStyle:'short',timeStyle:'short'}):'In attesa';
  group.value=Object.hasOwn(labels,params.get('group'))?params.get('group'):'';view.value=params.get('view')==='history'?'history':'unread';
  let section=/^[a-z0-9_-]{1,64}$/.test(params.get('section')||'')?params.get('section'):'',page=1,totalPages=1,items=[],issued=0,opened=params.get('notice'),lastRows='';
  document.getElementById('inbox-reset').hidden=!section;
  function safeUrl(value){try{const url=new URL(value,location.origin);return url.origin===location.origin?url.pathname+url.search+url.hash:'/notifiche/';}catch{return '/notifiche/';}}
  function openSection(item){const url=safeUrl(item.url);try{if(parent!==window&&parent.alphanoWorkspace){parent.alphanoWorkspace.open(url,item.title);return;}}catch{}location.assign(url);}
  async function acknowledge(item){
   if(item.read_at)return;
   const session=await fetch('/api/session.php',{cache:'no-store'});if(!session.ok)throw new Error('Sessione scaduta');
   const {csrfToken}=await session.json();const response=await fetch('/api/notifications.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({keys:[item.key]})});
   if(!response.ok)throw new Error('Lettura non salvata. Riprova.');
   item.read_at=Math.floor(Date.now()/1000);
   try{parent.alphanoNotifications?.refresh();}catch{}
  }
  async function show(item){
   message.hidden=false;message.innerHTML=`<header><div><span class="ui-eyebrow">${esc(labels[item.group]||'Cronaca')}</span><h2>${esc(item.title)}</h2></div><button type="button" data-close>Chiudi</button></header><div class="inbox-message-body"><p class="inbox-message-meta">${esc(date(item.created_at))}${item.actor?' · '+esc(item.actor):''}</p><p class="inbox-message-detail">${esc(item.detail)}</p><p class="inbox-read-state" role="status">Salvataggio della lettura…</p><button type="button" data-destination data-icon="compass">Apri la sezione</button></div>`;
   message.querySelector('[data-close]').addEventListener('click',()=>{message.hidden=true;});
   message.querySelector('[data-destination]').addEventListener('click',()=>openSection(item));
   if(item.data?.order_id){const card=document.createElement('section');card.dataset.commerceThread=String(Number(item.data.order_id));message.querySelector('.inbox-message-body').append(card);window.alphanoCommerceMount?.(card);message.querySelector('[data-destination]').hidden=true;}
   message.scrollIntoView({block:'nearest'});
   try{await acknowledge(item);message.querySelector('.inbox-read-state').textContent='Letta · '+date(item.read_at);await load(false);}
   catch(error){message.querySelector('.inbox-read-state').textContent=error.message;const retry=document.createElement('button');retry.type='button';retry.textContent='Riprova a salvare la lettura';retry.addEventListener('click',()=>show(item));message.querySelector('.inbox-message-body').append(retry);}
  }
  async function load(allowOpen=true){
   const sequence=++issued;if(allowOpen)status.textContent='Caricamento…';
   const query=new URLSearchParams({box:'1',view:view.value,page:String(page)});if(group.value)query.set('group',group.value);if(section)query.set('section',section);
   try{
    const response=await fetch('/api/notifications.php?'+query,{cache:'no-store'});if(!response.ok)throw new Error('Notifiche non disponibili. Riprova.');const data=await response.json();if(sequence!==issued)return;
    const focused=document.activeElement?.closest('[data-open],[data-go]');const focusType=focused?.hasAttribute('data-go')?'go':'open';const focusKey=focused?items[Number(focused.dataset[focusType])]?.key:null;
    const inbox=data.inbox;items=inbox.items;page=inbox.page;totalPages=inbox.pages;
    status.textContent=`${inbox.total} ${inbox.total===1?'notifica':'notifiche'}${section?' · sezione '+section.replaceAll('-',' '):''}`;
    const rendered=items.length?items.map((item,index)=>`<tr class="${item.read_at?'':'inbox-unread notice-unread'} ${item.commerce?'trade-row-'+esc(item.commerce.key):''}"><td><button type="button" class="ui-text-action" data-open="${index}">${item.read_at?'':'<span class="unread-flag">Da leggere</span> '}<strong>${esc(item.title)}</strong></button><span class="ui-cell-meta">${esc(item.actor||item.detail.slice(0,130))}</span>${item.commerce?`<span class="trade-status trade-${esc(item.commerce.key)}"><strong>${esc(item.commerce.label)}</strong> · ${esc(item.commerce.next)}</span>`:''}</td><td>${esc(labels[item.group]||item.group)}</td><td><time>${esc(date(item.created_at))}</time><span class="ui-cell-meta">${item.read_at?'Letta':'Da leggere'}</span></td><td><div class="ui-row-actions"><button type="button" data-open="${index}">Leggi</button><button type="button" data-go="${index}">${item.data?.order_id?'Gestisci':'Apri sezione'}</button></div></td></tr>`).join(''):'<tr><td colspan="4" class="ui-empty">Nessuna notifica in questo registro.</td></tr>';
    if(rendered!==lastRows){rows.innerHTML=rendered;lastRows=rendered;if(focusKey){const index=items.findIndex(i=>i.key===focusKey);rows.querySelector(`[data-${focusType}="${index}"]`)?.focus();}}
    document.getElementById('inbox-page').textContent=`Pagina ${page} di ${totalPages}`;document.getElementById('inbox-previous').disabled=page<=1;document.getElementById('inbox-next').disabled=page>=totalPages;
    if(allowOpen&&opened){const item=items.find(item=>item.key===opened);opened=null;if(item)await show(item);}
   }catch(error){if(sequence===issued)status.textContent=error.message;}
  }
  rows.addEventListener('click',async event=>{const open=event.target.closest('[data-open]'),go=event.target.closest('[data-go]');if(open)await show(items[Number(open.dataset.open)]);if(go){const item=items[Number(go.dataset.go)];await show(item);if(item.read_at&&!item.data?.order_id)openSection(item);}});
  for(const element of [group,view])element.addEventListener('change',()=>{page=1;message.hidden=true;load();});
  document.getElementById('inbox-reset').addEventListener('click',()=>{section='';page=1;document.getElementById('inbox-reset').hidden=true;load();});
  document.getElementById('inbox-refresh').addEventListener('click',()=>load());
  document.getElementById('inbox-previous').addEventListener('click',()=>{if(page>1){page--;load();}});
  document.getElementById('inbox-next').addEventListener('click',()=>{if(page<totalPages){page++;load();}});
  load();setInterval(()=>{if(!document.hidden)load(false);},30000);document.addEventListener('alphano:commerce-refresh',()=>load(false));
 });
})();
