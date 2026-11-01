/* Mobile uses the same controllers, permissions and forms as the desktop game. */
(() => {
 'use strict';
 const compact=matchMedia('(max-width:900px), (max-width:1100px) and (max-height:500px)'),phone=matchMedia('(max-width:900px), (max-width:1100px) and (max-height:500px)');
 const create=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text)n.textContent=text;return n;};
 const icon=(node,name)=>{if(window.alphanoUI)node.prepend(window.alphanoUI.icon(name));};
 function shell(){
  if(!document.body.matches('[data-view=main]')||window.alphanoMobile||!window.alphanoWorkspace)return;
  const content=document.querySelector('.game-content'),bar=document.querySelector('.topbar'),sidebar=document.getElementById('game-sidebar'),map=document.getElementById('map');let active=null,previous=null;
  const backdrop=create('button','mobile-shell-backdrop');backdrop.type='button';backdrop.setAttribute('aria-label','Chiudi menu e pannelli');backdrop.hidden=true;
  const heading=create('div','mobile-drawer-heading'),title=create('strong','','Alphano · Menu del gioco'),close=create('button','','');close.type='button';close.setAttribute('aria-label','Chiudi menu del gioco');icon(close,'x');heading.append(title,close);bar.prepend(heading);if(!document.querySelector('.mobile-privacy-choice')){const privacy=create('button','icon-btn mobile-privacy-choice');privacy.type='button';privacy.setAttribute('aria-label','Preferenze di gioco e riservatezza');privacy.title='Preferenze di gioco e riservatezza';icon(privacy,'settings');privacy.addEventListener('click',()=>window.alphanoPrivacy?.open());(document.querySelector('.profile-card-tools')||bar).append(privacy);}
  const sideHead=create('div','mobile-drawer-heading'),sideTitle=create('strong','','Personaggio e mondo'),sideClose=create('button');sideClose.type='button';sideClose.setAttribute('aria-label','Chiudi personaggio e mondo');icon(sideClose,'x');sideHead.append(sideTitle,sideClose);sidebar.prepend(sideHead);
  const nav=create('nav','mobile-game-dock');nav.setAttribute('aria-label','Accesso rapido al gioco');const items={};
  function button(key,label,glyph,action){const b=create('button','mobile-dock-item');b.type='button';b.dataset.mobileAction=key;b.setAttribute('aria-label',label);icon(b,glyph);b.append(create('span','',label));b.addEventListener('click',action);nav.append(b);items[key]=b;return b;}
  const menu=button('menu','Gioco','menu',()=>toggle('menu'));
  button('map','Mappa','map',()=>{closePanel();window.alphanoWorkspace.open('/mappa.php','Mappa dei territori');});
  button('scene','Luogo','messages',()=>{closePanel();window.alphanoWorkspace.close();});
  const mail=button('mail','Posta','mail',()=>{closePanel();window.alphanoWorkspace.open('/posta/','Posta');});const badge=create('span','notification-badge');badge.hidden=true;mail.append(badge);
  const world=button('world','Mondo','user',()=>toggle('world'));menu.setAttribute('aria-controls','menu-groups');world.setAttribute('aria-controls','game-sidebar');
  function sync(){const mobile=compact.matches;document.body.classList.toggle('mobile-game-menu',mobile&&active==='menu');document.body.classList.toggle('mobile-world-open',mobile&&active==='world');sidebar.classList.toggle('active',mobile&&active==='world');sidebar.inert=mobile&&active!=='world';bar.inert=mobile&&active!=='menu';if(mobile)bar.setAttribute('aria-hidden',String(active!=='menu'));else bar.removeAttribute('aria-hidden');map.inert=mobile&&!!active;backdrop.hidden=!mobile||!active;menu.setAttribute('aria-expanded',String(active==='menu'));world.setAttribute('aria-expanded',String(active==='world'));document.getElementById('sidebar-toggle')?.setAttribute('aria-expanded',String(active==='world'));for(const [key,item] of Object.entries(items))item.classList.toggle('is-active',active?active===key||active==='world'&&key==='world':key===(map.querySelector('.workspace-frame')?(map.querySelector('.workspace-frame').getAttribute('src').startsWith('/mappa')?'map':map.querySelector('.workspace-frame').getAttribute('src').startsWith('/posta')?'mail':''):'scene'));}
  function closePanel(restore=false){active=null;sync();if(restore&&previous?.isConnected)previous.focus({preventScroll:true});}
  function toggle(which){if(!compact.matches)return;previous=document.activeElement;active=active===which?null:which;sync();if(active)(active==='menu'?close:sideClose).focus({preventScroll:true});}
  let menuSwipe=null;
  bar.addEventListener('touchstart',event=>{menuSwipe=active==='menu'&&event.touches.length===1?{x:event.touches[0].clientX,y:event.touches[0].clientY}:null;},{passive:true});
  bar.addEventListener('touchend',event=>{const start=menuSwipe;menuSwipe=null;const end=event.changedTouches[0];if(start&&end&&end.clientX-start.x<-70&&Math.abs(end.clientX-start.x)>Math.abs(end.clientY-start.y)*1.6){if(event.cancelable)event.preventDefault();closePanel(true);}},{passive:false});
  bar.addEventListener('touchcancel',()=>{menuSwipe=null;});
  close.addEventListener('click',()=>closePanel(true));sideClose.addEventListener('click',()=>closePanel(true));backdrop.addEventListener('click',()=>closePanel(true));
  document.addEventListener('keydown',event=>{if(!active)return;if(event.key==='Escape'){event.preventDefault();closePanel(true);}if(event.key==='Tab'){const panel=active==='menu'?bar:sidebar;const nodes=[...panel.querySelectorAll('button,a[href],input,select,textarea')].filter(n=>!n.disabled&&n.getClientRects().length);if(!nodes.length)return;const first=nodes[0],last=nodes.at(-1);if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}}});
  new MutationObserver(sync).observe(map,{childList:true});
  const unread=document.getElementById('mail-unread');if(unread)new MutationObserver(()=>{badge.textContent=unread.textContent;badge.hidden=unread.hidden;}).observe(unread,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});
  compact.addEventListener('change',()=>{active=null;sync();});window.alphanoMobile={menu:()=>toggle('menu'),world:()=>toggle('world'),close:closePanel};content.after(nav,backdrop);sync();
 }
 function chatTools(){
  const composer=document.querySelector('body[data-view=chat-index] .composer');if(!composer||document.querySelector('.mobile-composer-tools'))return;
  const redesigned=composer.classList.contains('g-composer');
  if(!redesigned){
  const tools=create('button','mobile-composer-tools');tools.type='button';tools.setAttribute('aria-label','Strumenti di chat: lavori, umore, abilità, dadi e registrazione');tools.title='Strumenti di chat';icon(tools,'settings');
  const dialog=create('dialog','mobile-chat-tools-dialog');dialog.setAttribute('aria-labelledby','mobile-tools-title');const head=create('header'),title=create('h2','','Strumenti di chat');title.id='mobile-tools-title';const close=create('button');close.type='button';close.setAttribute('aria-label','Chiudi strumenti');icon(close,'x');head.append(title,close);const body=create('div','mobile-chat-tools-body');dialog.append(head,body);document.body.append(dialog);composer.append(tools);
  const definitions=[['chat-work-select','Lavori e raccolta'],['moodSelect','Umore'],['rpg-skills-button','Scegli un’abilità'],['diceBtn','Lancia un dado'],['recordBtn','Registra / salva giocata'],['sessionBtn','Gestione della sessione']];const moved=[];
  function adapt(){if(!phone.matches){if(dialog.open)dialog.close();for(const {node,marker} of moved)marker.after(node);return;}for(const [id,label]of definitions){const node=document.getElementById(id);if(!node)continue;let entry=moved.find(item=>item.node===node);if(!entry){const marker=document.createComment('mobile-tools-'+id);node.before(marker);entry={node,marker};moved.push(entry);}if(node.parentElement!==body){if(id==='moodSelect'||id==='chat-work-select'){const labelNode=create('label','',label);labelNode.htmlFor=id;body.append(labelNode);}node.setAttribute('aria-label',label);body.append(node);}}}
  tools.addEventListener('click',()=>{adapt();dialog.showModal();});close.addEventListener('click',()=>dialog.close());body.addEventListener('click',event=>{if(event.target.closest('button'))dialog.close();},true);dialog.addEventListener('click',event=>{if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();}});
  phone.addEventListener('change',adapt);adapt();
  }
  const input=document.getElementById('comodo');
  if(input&&window.myPgId&&window.chatRoom){
   const key='alphano:chat-draft:'+window.myPgId+':'+window.chatRoom;let saveTimer;
   try{const draft=JSON.parse(sessionStorage.getItem(key)||'null');if(draft&&Date.now()-draft.saved<86400000&&typeof draft.text==='string'&&!input.value)input.value=draft.text;}catch(_){}
   const save=()=>{clearTimeout(saveTimer);try{if(input.value)sessionStorage.setItem(key,JSON.stringify({text:input.value,saved:Date.now()}));else sessionStorage.removeItem(key);}catch(_){}};
   input.addEventListener('input',()=>{clearTimeout(saveTimer);if(!input.value)save();else saveTimer=setTimeout(save,200);});window.addEventListener('pagehide',save);document.addEventListener('visibilitychange',()=>{if(document.hidden)save();});
   const keyboard=()=>input.enterKeyHint=phone.matches?'enter':'send';phone.addEventListener('change',keyboard);keyboard();
  }
  if(!redesigned)input?.addEventListener('input',()=>{input.style.height='auto';input.style.height=Math.min(120,input.scrollHeight)+'px';});
 }
 function viewport(){
  // iOS leaves the layout viewport tall when its keyboard opens, including in iframes.
  let owner=window;try{if(window.top.location.origin===location.origin)owner=window.top;}catch(_){}
  if(owner!==window){document.addEventListener('focusin',event=>{if(event.target.matches('input,textarea,select'))owner.dispatchEvent(new Event('alphano:field-focus'));});return;}
  const vv=window.visualViewport;if(!vv)return;let raf=0;
  const update=()=>{raf=0;const typing=/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName||'')||[...document.querySelectorAll('iframe')].some(frame=>{try{return /^(INPUT|TEXTAREA|SELECT)$/.test(frame.contentDocument?.activeElement?.tagName||'');}catch(_){return false;}});const keyboard=compact.matches&&typing&&window.innerHeight-vv.height>120;document.documentElement.style.setProperty('--game-viewport-height',Math.round(vv.height)+'px');document.body.classList.toggle('mobile-keyboard-open',keyboard);};
  const queue=()=>{if(!raf)raf=requestAnimationFrame(update);};vv.addEventListener('resize',queue);vv.addEventListener('scroll',queue);window.addEventListener('resize',queue);document.addEventListener('focusin',queue);document.addEventListener('focusout',queue);window.addEventListener('alphano:field-focus',queue);update();
 }
 function tables(){
  for(const table of document.querySelectorAll('body.ui-page table')){
   const headerRow=table.tHead?.rows[table.tHead.rows.length-1]||[...table.rows].find(row=>row.querySelector('th')),headers=headerRow?[...headerRow.cells]:[];
   if(table.matches('.ui-data-table,.session-table')&&headers.length>1&&table.tBodies.length){
    table.dataset.mobileCards='true';headerRow.dataset.mobileHead='true';for(const row of table.rows){if(row===headerRow)continue;for(const [index,cell] of [...row.cells].entries())if(cell.colSpan===1)cell.dataset.columnLabel=headers[index]?.textContent.trim()||'';}
   }
   if(table.closest('.ui-table-scroll,.table-responsive,.mobile-table-scroll')||table.closest('dialog'))continue;
   const region=create('div','mobile-table-scroll');region.setAttribute('role','region');region.setAttribute('aria-label',table.caption?.textContent.trim()||'Tabella: scorri lateralmente per tutte le colonne');region.tabIndex=0;table.before(region);region.append(table);
  }
 }

 function boot(){try{if(window.parent!==window&&window.parent.document.body.matches('[data-view=main]'))document.body.classList.add('is-game-frame');}catch(_){}shell();chatTools();viewport();tables();
  if(!document.body.matches('[data-view=main],[data-view=map],[data-view=chat-index]')){window.addEventListener('load',tables,{once:true});let pending=false;new MutationObserver(records=>{if(pending||!records.some(record=>[...record.addedNodes].some(node=>node.nodeType===1&&(node.matches('table,thead,tbody,tr,th,td')||node.querySelector('table')))))return;pending=true;requestAnimationFrame(()=>{pending=false;tables();});}).observe(document.body,{childList:true,subtree:true});}
 }
 document.addEventListener('alphano:shell-ready',shell);if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
