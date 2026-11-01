document.addEventListener('DOMContentLoaded', () => {
  'use strict';
  const F=window.alphanoForum, {esc,button}=F, config=F.config;
  const menu=document.getElementById('menu-list'),title=document.getElementById('panel-title'),panel=document.getElementById('panel-container');
  const modal=document.getElementById('modal'),form=modal.querySelector('form'),modalTitle=modal.querySelector('.modal-title');
  let tipo=config.tipo,showClosed=false,canModerate=false,currentThread=0,page='list',generation=0;
  async function api(params,method='GET') {
    const opts={method,headers:{'X-CSRF-Token':config.csrfToken}};
    let url=config.apiBaseUrl+'/discussioni/index.php';
    if(method==='GET')url+='?'+new URLSearchParams({...params,tipo});else opts.body=new URLSearchParams({...params,tipo});
    const res=await fetch(url,opts),data=await res.json();if(!res.ok)throw new Error(data.error||'Richiesta non riuscita');return data;
  }
  const controls=(item,reply=false)=>`${!reply?button('Apri','open',`data-id="${item.id}"`,'book'):''}${item.can_edit?button('Modifica','edit',`data-id="${item.id}"`,'settings'):''}${!reply&&item.can_moderate?button(item.chiusa?'Riapri':'Chiudi','toggle',`data-id="${item.id}" data-closed="${item.chiusa?1:0}"`,'shield'):''}${item.can_edit?button('Elimina','delete',`data-id="${item.id}"`,'settings'):''}`;
  async function loadList(){
    const request=++generation;page='list';currentThread=0;title.textContent=config.channels[tipo]+(showClosed?' · Archivio':'');panel.innerHTML=F.notice('Caricamento…');
    try {
      const data=await api({action:showClosed?'list_closed':'list_open'});if(request!==generation)return;canModerate=!!data.can_moderate;
      const items=await Promise.all(data.threads.map(async item=>({...item,profile:await F.profile(item.author_id)})));if(request!==generation)return;
      panel.innerHTML=items.length?F.table(['Autore','Discussione','Attività','Azioni'],items.map(item=>`<tr><td>${F.author(item.author_id,item.profile,item.author)}</td><td><button type="button" class="ui-text-action" data-action="open" data-id="${item.id}"><strong>${esc(item.oggetto)}</strong></button> ${F.newBadge(item.unread)}<small class="ui-cell-meta">${item.ontop?'In evidenza · ':''}${F.date(item.data)}</small></td><td>${Number(item.numrisp)} risposte<small class="ui-cell-meta">${item.chiusa?'Chiusa':'Aperta'}</small></td><td><div class="ui-row-actions">${controls(item)}</div></td></tr>`).join(''),'Discussioni di '+config.channels[tipo]):F.notice('Nessuna discussione '+(showClosed?'chiusa.':'presente.'));
    }catch(error){if(request===generation)panel.innerHTML=F.notice(error.message,true);}
  }
  let currentPosts=[];
  async function openThread(id){
    const request=++generation;page='thread';currentThread=Number(id);panel.innerHTML=F.notice('Caricamento…');
    try {
      const {thread,posts}=await api({action:'show',id});const all=[thread,...posts];
      const rendered=await Promise.all(all.map(async (item,index)=>F.post({id:item.id,userId:item.author_id,name:item.author,profile:await F.profile(item.author_id),message:item.messaggio,title:index===0?item.oggetto:'',timestamp:item.data,actions:(!thread.chiusa?button('Rispondi','reply'):'')+controls(item,true)})));
      if(request!==generation)return;currentPosts=all;canModerate=!!thread.can_moderate;title.textContent=config.channels[tipo];
      panel.innerHTML=`<div class="forum-thread-bar">${button('Torna alle discussioni','back','','book')}<span>${posts.length} risposte · ${thread.chiusa?'Discussione chiusa':'Discussione aperta'}</span></div><div class="forum-posts">${rendered.join('')}</div><footer class="ui-list-footer">${!thread.chiusa?button('Rispondi','reply',`data-id="${id}"`):'<p>Questa discussione è chiusa.</p>'}${thread.can_moderate?button(thread.chiusa?'Riapri':'Chiudi','toggle',`data-id="${id}" data-closed="${thread.chiusa?1:0}"`,'shield'):''}</footer>`;
      F.markRead('discussion',id,tipo,all);
    }catch(error){if(request===generation)panel.innerHTML=F.notice(error.message,true);}
  }
  function openForm(action,item={}){
    modalTitle.textContent={create:'Nuova discussione',reply:'Rispondi',modify:'Modifica messaggio'}[action];
    form.innerHTML=`<input type="hidden" name="action" value="${action}">${action==='reply'?`<input type="hidden" name="replyID" value="${currentThread}">`:''}${action==='modify'?`<input type="hidden" name="id" value="${item.id}">`:''}<label>Oggetto<input type="text" name="oggetto" maxlength="255" required value="${esc(item.oggetto|| (action==='reply'?'Re: '+(currentPosts[0]?.oggetto||'Discussione'):''))}"></label><label>Messaggio<textarea name="messaggio" rows="9" required>${esc(item.message_raw??'')}</textarea></label>${action==='create'&&canModerate?'<label class="forum-check"><input type="checkbox" name="ontop"> In evidenza</label>':''}${action!=='modify'&&canModerate?'<label class="forum-check"><input type="checkbox" name="chiudi"> Chiudi dopo l’invio</label>':''}<div class="ui-row-actions"><button type="submit" data-icon="check">Invia</button><button type="button" data-cancel>Annulla</button></div>`;
    F.editor(form);form.querySelector('[data-cancel]').onclick=()=>modal.classList.remove('open');modal.classList.add('open');form.querySelector('input[type=text]').focus();
  }
  form.onsubmit=async event=>{event.preventDefault();const submit=form.querySelector('[type=submit]');submit.disabled=true;try{const data=Object.fromEntries(new FormData(form));await api(data,'POST');modal.classList.remove('open');await(page==='thread'?openThread(currentThread):loadList());}catch(error){alert(error.message);}finally{submit.disabled=false;}};
  panel.addEventListener('click',async event=>{
    const target=event.target.closest('[data-action]');if(!target)return;const id=Number(target.dataset.id),action=target.dataset.action;
    try {
      if(action==='open')return openThread(id);
      if(action==='back')return loadList();
      if(action==='reply')return openForm('reply');
      if(action==='edit'){let item=currentPosts.find(post=>post.id===id);if(!item)item=(await api({action:'show',id})).thread;return openForm('modify',item);}
      if(action==='toggle'){const closed=target.dataset.closed==='1';if(confirm(closed?'Riaprire la discussione?':'Chiudere la discussione?'))await api({action:closed?'open':'close',id},'POST');}
      if(action==='delete'){if(!confirm('Eliminare questo messaggio e le eventuali risposte?'))return;await api({action:'delete',id},'POST');if(id===currentThread)return loadList();}
      await(page==='thread'?openThread(currentThread):loadList());
    }catch(error){alert(error.message);}
  });
  menu.addEventListener('click',event=>{const target=event.target.closest('[data-tipo]');if(!target)return;tipo=target.dataset.tipo;menu.querySelectorAll('[data-tipo]').forEach(button=>{button.classList.toggle('active',button===target);button.setAttribute('aria-pressed',String(button===target));});history.replaceState(null,'','?tipo='+encodeURIComponent(tipo));loadList();});
  document.getElementById('btn-toggle-closed').onclick=event=>{showClosed=!showClosed;const target=event.currentTarget;target.textContent=showClosed?'Mostra aperti':'Mostra chiusi';target.setAttribute('aria-pressed',String(showClosed));loadList();};
  document.getElementById('btn-create').onclick=()=>openForm('create');modal.querySelector('.close-modal').onclick=()=>modal.classList.remove('open');modal.onclick=event=>{if(event.target===modal)modal.classList.remove('open');};const initial=Number(new URLSearchParams(location.search).get('thread'));if(initial)openThread(initial);else loadList();
});
