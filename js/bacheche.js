document.addEventListener('DOMContentLoaded', () => {
  'use strict';
  const F=window.alphanoForum,{esc,button}=F,config=F.config;
  const header=document.getElementById('panel-header'),panel=document.getElementById('panel-container'),modal=document.getElementById('modal'),form=modal.querySelector('form');
  const query=new URLSearchParams(location.search),fixedLocation=config.initialLocation||query.get('location');
  let currentBoard=0,currentThread=0,threads=[],posts=[],boards=new Map(),cities=[],selectedCity=fixedLocation||'',generation=0;
  async function api(action,data={},method='GET') {
    const opts={method,headers:{'X-CSRF-Token':config.csrfToken}};let url=config.apiBaseUrl+'/index.php';
    if(method==='GET')url+='?'+new URLSearchParams({action,...data});else opts.body=new URLSearchParams({action,...data});
    const response=await fetch(url,opts),result=await response.json();if(!response.ok||!result.success)throw new Error(result.error||'Richiesta non riuscita');return result;
  }
  function toast(message,error=false){const el=document.getElementById('toast');el.textContent=message;el.dataset.state=error?'error':'success';el.classList.add('show');clearTimeout(toast.timer);toast.timer=setTimeout(()=>el.classList.remove('show'),3500);}
  function headerIndex(){header.innerHTML=`<div><h2>Voci dai territori</h2><p class="forum-summary">Le bacheche generali, poi le comunità di ogni città.</p></div><label class="forum-location">Mostra<select id="forum-location"><option value="">Tutti i territori</option>${cities.map(city=>`<option value="${esc(city.location)}"${selectedCity===city.location?' selected':''}>${esc(city.location)}</option>`).join('')}</select></label>`;header.querySelector('select').onchange=event=>{selectedCity=event.target.value;renderIndex();};}
  async function renderIndex(){
    const request=++generation;currentBoard=0;currentThread=0;panel.innerHTML=F.notice('Caricamento delle bacheche…');header.classList.remove('forum-board-hero');header.style.removeProperty('--board-cover');setHeadingArt('/assets/placeholder.svg','Bacheche','Notizie, racconti e conversazioni della comunità.');
    try{
      const result=await api('getIndex');if(request!==generation)return;
      cities=result.sections.map(section=>({location:section.location}));if(selectedCity&&!cities.some(c=>c.location===selectedCity))selectedCity='';headerIndex();
      const sections=result.sections.filter(section=>!selectedCity||section.location===selectedCity);
      const content=await Promise.all(sections.map(async section=>{
        const rows=await Promise.all(section.boards.map(async board=>{
          boards.set(board.id,board);let last='<span class="forum-no-activity">Nessun intervento</span>';const description=esc((board.descrizione||'').replace(/<[^>]+>/g,''));
          const link=content=>board.allowed?`<a class="forum-board-link" data-action="board" data-id="${board.id}" href="/bacheche/?board=${board.id}">${content}</a>`:content;
          if(board.lastPostDate&&board.lastPostPg){const person=await F.profile(board.lastPostPg);last=`${F.author(board.lastPostPg,person)}<button type="button" class="ui-text-action" data-action="last" data-board="${board.id}" data-thread="${board.lastPostId}"><strong>${esc(board.lastPostSubject||'Discussione')}</strong></button><small class="ui-cell-meta">${F.date(board.lastPostDate)}</small>`;}
          return `<tr${board.unread?' class="forum-board-unread"':''}><td>${link(`<img class="forum-board-thumbnail" src="${esc(board.image)}" alt="" width="200" height="112" loading="lazy"><strong>${esc(board.nome)}</strong>`)} ${F.newBadge(board.unread)}</td><td><div class="ui-cell-summary">${link(description||'Consulta le discussioni')}</div>${!board.allowed?`<small class="ui-cell-meta">Riservata a: ${esc((board.required||[]).join(', '))}</small>`:''}</td><td>${Number(board.threadsCount)} discussioni<small class="ui-cell-meta">${Number(board.postsCount)} risposte</small></td><td><div class="forum-last-activity">${last}</div>${!board.allowed?'<span class="forum-restricted">Riservata</span>':''}</td></tr>`;
        }));
        const name=section.location==='Generale'?'La comunità di Alphano':section.location,subtitle=section.location==='Generale'?'Annunci, idee, racconti e nuovi viandanti.':'Cronache, proclami e conversazioni del territorio.';
        return `<section class="forum-directory-section"><header class="forum-section-heading"><img src="${esc(section.boards[0]?.image)}" alt="" width="1440" height="810" loading="lazy"><div><span class="manual-eyebrow">${section.location==='Generale'?'SEZIONE GENERALE':'BACHECHE CITTADINE'}</span><h2>${esc(name)}</h2><p>${subtitle}</p></div><span class="manual-counter">${section.boards.length} bacheche</span></header>${F.table(['Bacheca','Descrizione','Attività','Ultimo intervento'],rows.join(''),'Bacheche di '+section.location,'boards')}</section>`;
      }));
      if(request===generation)panel.innerHTML=content.join('')||F.notice('Nessuna bacheca disponibile.');
    }catch(error){if(request===generation)panel.innerHTML=F.notice(error.message,true);}
  }
  function setHeadingArt(image,title,description){const heading=header.closest('[data-page-heading]')||document.querySelector('[data-page-heading]');if(!heading)return;heading.dataset.headerImage=image;const art=heading.querySelector('.ui-header-art');if(art)art.src=image;const h1=heading.querySelector('h1');if(h1)h1.textContent=title;const copy=heading.querySelector(':scope > div > p');if(copy)copy.textContent=description;}
  function boardHero(board){header.classList.add('forum-board-hero');header.dataset.mode='board';header.style.setProperty('--board-cover',`url("${board.image}")`);setHeadingArt(board.image,board.nome,(board.descrizione||board.location||'').replace(/<[^>]+>/g,''));}
  function controls(item,root=true){const own=Number(item.idpg)===Number(config.currentUserId)||config.isAdmin;return `${root?button('Apri','thread',`data-id="${item.id}"`,'book'):''}${own?button('Modifica','edit',`data-id="${item.id}"`,'settings'):''}${config.isAdmin&&root?button(item.chiusa==='1'?'Riapri':'Chiudi','toggle',`data-id="${item.id}"`,'shield'):''}${own?button('Elimina','delete',`data-id="${item.id}"`,'settings'):''}${!root?button('Cita','quote',`data-id="${item.id}"`)+button('Storico','history',`data-id="${item.id}"`,'scroll'):''}`;}
  async function openBoard(id,openId=0){
    const request=++generation;currentBoard=Number(id);currentThread=0;panel.innerHTML=F.notice('Caricamento…');
    header.innerHTML=`<div class="forum-breadcrumb">${button('Bacheche','index','','book')}<h2>${esc(boards.get(currentBoard)?.nome||'Discussioni')}</h2></div>${button('Nuova discussione','create','','messages')}`;
    try{
      const result=await api('getThreads',{board:currentBoard});if(request!==generation)return;threads=result.threads;if(result.board){boards.set(currentBoard,result.board);header.querySelector('h2').textContent=result.board.nome;boardHero(result.board);}
      if(openId)return showThread(openId);
      const rows=await Promise.all(threads.map(async item=>{const person=await F.profile(item.idpg);return `<tr><td>${F.author(item.idpg,person)}</td><td><button type="button" class="ui-text-action" data-action="thread" data-id="${item.id}"><strong>${esc(item.oggetto||'Discussione')}</strong></button> ${F.newBadge(item.unread)}<small class="ui-cell-meta">${F.date(item.data)}</small></td><td>${Number(item.replyCount||0)} risposte<small class="ui-cell-meta">${item.chiusa==='1'?'Chiusa':'Aperta'} · ${Number(item.visite||0)} visite</small></td><td><div class="ui-row-actions">${controls(item)}</div></td></tr>`;}));
      if(request!==generation)return;panel.innerHTML=rows.length?F.table(['Autore','Discussione','Attività','Azioni'],rows.join(''),'Discussioni della bacheca'):F.notice('Nessuna discussione presente. Inizia tu.');
    }catch(error){if(request===generation)panel.innerHTML=F.notice(error.message,true);}
  }
  async function showThread(id){
    const request=++generation;header.dataset.mode='thread';currentThread=Number(id);const thread=threads.find(item=>item.id===currentThread);if(!thread){panel.innerHTML=F.notice('Discussione non disponibile.',true);return;}
    header.innerHTML=`<div class="forum-breadcrumb">${button('Discussioni','back','','book')}<h2>${esc(thread.oggetto||'Discussione')}</h2></div>`;panel.innerHTML=F.notice('Caricamento…');
    try{
      const result=await api('getPosts',{board:currentBoard,thread:currentThread});const all=[thread,...result.posts];
      const rendered=await Promise.all(all.map(async(item,index)=>F.post({id:item.id,userId:item.idpg,profile:await F.profile(item.idpg),message:item.messaggio,title:index===0?item.oggetto:'',timestamp:item.data,actions:(thread.chiusa!=='1'?button('Rispondi','reply'):'')+controls(item,false)})));
      if(request!==generation)return;posts=result.posts;panel.innerHTML=`<div class="forum-thread-bar"><span>${posts.length} risposte · ${thread.chiusa==='1'?'Discussione chiusa':'Discussione aperta'}</span></div><div class="forum-posts">${rendered.join('')}</div><footer class="ui-list-footer">${thread.chiusa!=='1'?button('Rispondi','reply'):'<p>Questa discussione è chiusa.</p>'}</footer>`;F.markRead('board',currentThread,currentBoard,all);
    }catch(error){if(request===generation)panel.innerHTML=F.notice(error.message,true);}
  }
  function openForm(action,item={}){
    modal.querySelector('.modal-title').textContent={postThread:'Nuova discussione',postReply:'Rispondi',modify:'Modifica messaggio'}[action];
    const reply=action==='postReply'||Number(item.replyid)>0;
    form.innerHTML=`<input type="hidden" name="action" value="${action}"><input type="hidden" name="board" value="${currentBoard}">${currentThread?`<input type="hidden" name="thread" value="${currentThread}">`:''}${action==='modify'?`<input type="hidden" name="id" value="${item.id}">`:''}${!reply?`<label>Oggetto<input type="text" name="subject" maxlength="255" required value="${esc(item.oggetto||'')}"></label>`:''}<label>Messaggio<textarea name="message" rows="9" required>${esc(item.message_raw??'')}</textarea></label>${action!=='modify'?'<label class="forum-check"><input type="checkbox" name="firma" value="1" checked> Inserisci la firma</label>':''}${action==='postThread'?'<label class="forum-check"><input type="checkbox" name="segnalazione" value="1"> Avvisami via missiva delle risposte</label>':''}<div class="ui-row-actions"><button type="submit" data-icon="check">Invia</button><button type="button" data-cancel>Annulla</button></div>`;
    F.editor(form);form.querySelector('[data-cancel]').onclick=()=>modal.classList.remove('open');modal.classList.add('open');form.querySelector('input[type=text],textarea').focus();
  }
  async function dispatch(event){
    const target=event.target.closest('[data-action]');if(!target)return;if(target.tagName==='A')event.preventDefault();const id=Number(target.dataset.id),action=target.dataset.action;
    try{
      if(action==='index')return renderIndex();if(action==='board')return openBoard(id);if(action==='last')return openBoard(Number(target.dataset.board),Number(target.dataset.thread));if(action==='back')return openBoard(currentBoard);if(action==='thread')return showThread(id);
      if(action==='create')return openForm('postThread');if(action==='reply')return openForm('postReply');
      const item=[...threads,...posts].find(item=>item.id===id);if(!item)return;
      if(action==='edit')return openForm('modify',item);
      if(action==='quote'){const person=await F.profile(item.idpg);return openForm('postReply',{message_raw:'[q '+person.nome+']'+(item.message_raw??'')+'[/q]\n'});}
      if(action==='history'){const result=await api('getHistory',{id});modal.querySelector('.modal-title').textContent='Storico delle modifiche';form.innerHTML=result.history.length?'<div class="forum-posts">'+(await Promise.all(result.history.map(async entry=>F.post({id:entry.id,userId:entry.idpg,profile:await F.profile(entry.idpg),name:entry.nome,message:entry.messaggio,title:entry.oggetto,timestamp:entry.data})))).join('')+'</div>':F.notice('Nessuna modifica precedente.');modal.classList.add('open');return;}
      if(action==='delete'){if(!confirm('Eliminare questo messaggio e le eventuali risposte?'))return;await api('delete',{id},'POST');if(id===currentThread)return openBoard(currentBoard);}
      if(action==='toggle'){const closed=item.chiusa==='1';if(!confirm(closed?'Riaprire la discussione?':'Chiudere la discussione?'))return;await api(closed?'open':'close',{id},'POST');}
      await openBoard(currentBoard,currentThread);toast('Operazione riuscita');
    }catch(error){toast(error.message,true);}
  }
  panel.addEventListener('click',dispatch);header.addEventListener('click',dispatch);
  form.onsubmit=async event=>{event.preventDefault();const submit=form.querySelector('[type=submit]');submit.disabled=true;try{const data=Object.fromEntries(new FormData(form)),result=await api(data.action,data,'POST');modal.classList.remove('open');toast(result.message||'Operazione riuscita');await openBoard(currentBoard,data.action==='postThread'?Number(result.id):currentThread);}catch(error){toast(error.message,true);}finally{submit.disabled=false;}};
  modal.querySelector('.close-modal').onclick=()=>modal.classList.remove('open');modal.onclick=event=>{if(event.target===modal)modal.classList.remove('open');};
  const initialBoard=Number(config.initialBoard||query.get('board')),initialThread=Number(config.initialThread||query.get('thread'));if(initialBoard>0)openBoard(initialBoard,initialThread||0);else renderIndex();
});
