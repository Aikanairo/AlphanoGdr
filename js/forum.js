/* Contratto unico per bacheche cittadine, gruppi, Accademia e appunti. */
(() => {
  'use strict';
  const config = JSON.parse(document.getElementById('forum-config')?.textContent || '{}');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const profiles = new Map();
  const date = timestamp => new Date(Number(timestamp)*1000).toLocaleString('it-IT');
  async function profile(id) {
    const key=Number(id);
    if (!profiles.has(key)) profiles.set(key, fetch('/api/profile.php?uid='+encodeURIComponent(key)).then(async response=>{
      if (!response.ok) throw new Error('Profilo non disponibile');
      return response.json();
    }).catch(error=>{profiles.delete(key);throw error;}));
    return profiles.get(key);
  }
  function author(id,person,name='',large=false) {
    const fallback=person.avatar_default_url||'/assets/placeholder.svg';
    return `<div class="forum-person${large?' forum-person-large':''}"><a href="/profili/?Id=${Number(id)}" data-character-markers="off" class="forum-portrait-link" aria-label="Profilo di ${esc(name||person.nome)}"><img class="forum-avatar" data-avatar-fallback data-fallback="${esc(fallback)}" src="${esc(person.avatar_url?.trim()||fallback)}" alt=""></a><div class="forum-person-info"><a class="forum-person-name" href="/profili/?Id=${Number(id)}">${esc(name||person.nome||'Personaggio')}</a>${person.etnia?`<small class="ui-cell-meta">${esc(person.etnia)}</small>`:''}</div></div>`;
  }
  function post({id,userId,name,profile:person,message,title='',timestamp,actions=''}) {
    return `<article class="forum-post" data-post-id="${Number(id)}"><aside class="forum-post-author" aria-label="Autore del messaggio">${author(userId,person,name,true)}</aside><div class="forum-post-content"><header class="forum-post-header"><time datetime="${new Date(Number(timestamp)*1000).toISOString()}">${date(timestamp)}</time><a class="forum-post-permalink" href="#post-${Number(id)}" aria-label="Messaggio ${Number(id)}">#${Number(id)}</a></header>${title?`<h3 class="forum-post-title" id="post-${Number(id)}">${esc(title)}</h3>`:`<span id="post-${Number(id)}"></span>`}<div class="post-body forum-post-body">${message}</div><footer class="forum-post-actions ui-row-actions">${actions}</footer></div></article>`;
  }
  function notice(message,error=false) { return `<p class="ui-empty${error?' error':''}" role="${error?'alert':'status'}">${esc(message)}</p>`; }
  function table(headers,rows,label,kind='topics') { return `<div class="ui-table-scroll" tabindex="0" role="region" aria-label="${esc(label)}"><table class="ui-data-table forum-table forum-table-${esc(kind)}"><thead><tr>${headers.map(h=>`<th scope="col">${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div>`; }
  function button(label,action,extra='',icon='messages') {return `<button type="button" class="action-btn" data-action="${esc(action)}" data-icon="${icon}" ${extra}>${esc(label)}</button>`;}
  const newBadge = unread => unread ? '<span class="ui-new-badge" aria-label="Nuovi messaggi"><span aria-hidden="true">✦</span> NEW</span>' : '';
  function markRead(source,thread,scope,posts) {
    const maxId=Math.max(Number(thread),...posts.map(p=>Number(p.id)||0));
    try { Promise.resolve(window.top.alphanoNotifications?.readThread({source,thread:Number(thread),scope,max_id:maxId})).catch(()=>{}); } catch (_) { /* L'avviso resta disponibile se il frame è autonomo. */ }
  }
  function editor(form) {
    const textarea=form.querySelector('textarea');if(!textarea)return;
    const toolbar=document.createElement('div');toolbar.className='forum-editor-tools';toolbar.setAttribute('aria-label','Formattazione GML');
    [['Grassetto','b'],['Corsivo','c'],['Citazione','q']].forEach(([label,tag])=>{
      const item=document.createElement('button');item.type='button';item.textContent=label;
      item.addEventListener('click',()=>{const start=textarea.selectionStart,end=textarea.selectionEnd;textarea.setRangeText(`[${tag}]${textarea.value.slice(start,end)}[/${tag}]`,start,end,'select');textarea.focus();});toolbar.append(item);
    });const label=textarea.closest('label');if(label){textarea.id=textarea.id||'forum-message';label.htmlFor=textarea.id;label.before(toolbar);}else textarea.before(toolbar);
  }
  document.addEventListener('error',event=>{const image=event.target;if(image instanceof HTMLImageElement&&image.classList.contains('forum-avatar')&&!image.dataset.fallbackUsed){image.dataset.fallbackUsed='1';image.src=image.dataset.fallback||'/assets/placeholder.svg';}},true);
  document.addEventListener('keydown',event=>{if(event.key==='Escape')document.querySelector('.forum-modal.open')?.classList.remove('open');});
  window.alphanoForum={config,esc,date,profile,author,post,notice,table,button,markRead,editor,newBadge};
})();
