/* Avvisi condivisi del template. Un solo dialogo alla volta, anche dalle viste in iframe. */
(() => {
  'use strict';
  if(window.alphanoDialog)return;
  let pending=Promise.resolve(),serial=0;
  function show(options){
    return new Promise(resolve=>{
      const id='alphano-notice-'+(++serial),previous=document.activeElement;
      const dialog=document.createElement('dialog');dialog.className='modal-window ui-notice-dialog';
      const game=options.variant==='board-game';if(game){dialog.classList.add('ui-board-notice');dialog.dataset.tone=['victory','defeat','draw','invite','start','update'].includes(options.gameTone)?options.gameTone:'update';}
      const activity=options.variant==='activity';if(activity)dialog.classList.add('ui-activity-dialog');
      dialog.setAttribute('aria-labelledby',id+'-title');dialog.setAttribute('aria-describedby',id+'-message');
      const header=document.createElement('header');header.className='modal-header';
      const heading=document.createElement('div');heading.className='ui-notice-heading';
      const imagePath=['/assets/placeholder.svg','/assets/placeholder.svg'].includes(options.iconImage)?options.iconImage:null;
      if(imagePath){const art=document.createElement('img');art.src=imagePath;art.alt='';art.className='ui-notice-artwork';heading.append(art);}
      else{const icon=window.alphanoUI?.icon?.('scroll');if(icon)heading.append(icon);}
      const titles=document.createElement('div'),eyebrow=document.createElement('p'),title=document.createElement('h2');
      eyebrow.className='ui-eyebrow';eyebrow.textContent=options.eyebrow||'Alphano · Avviso';
      title.id=id+'-title';title.textContent=options.title||'Avviso';titles.append(eyebrow,title);heading.append(titles);
      const close=document.createElement('button');close.type='button';close.className='ui-notice-close';close.setAttribute('aria-label','Chiudi avviso');close.textContent='×';
      close.addEventListener('click',()=>dialog.close('dismiss'));header.append(heading,close);
      const body=document.createElement('div');body.className='modal-body';
      const message=document.createElement('p');message.id=id+'-message';message.className='ui-notice-message';message.textContent=String(options.message||'');body.append(message);
      if(game){
        const crest=document.createElement('div');crest.className='ui-board-crest';crest.setAttribute('aria-hidden','true');crest.textContent=String(options.gameSymbol||'♜');body.prepend(crest);
        if(Array.isArray(options.gamePlayers)&&options.gamePlayers.length){
          const matchup=document.createElement('div');matchup.className='ui-board-matchup';
          options.gamePlayers.slice(0,2).forEach((player,index)=>{
            if(index){const vs=document.createElement('span');vs.className='ui-board-versus';vs.textContent='VS';matchup.append(vs);}
            const person=document.createElement('div');person.className='ui-board-person';
            for(const path of [player.portrait,player.fallback]){try{const url=new URL(path||'',location.origin);if(path&&(url.origin===location.origin||url.protocol==='https:')){const img=document.createElement('img');img.src=url.href;img.alt='';img.width=64;img.height=64;img.addEventListener('error',()=>{if(player.fallback&&img.getAttribute('src')!==player.fallback)img.src=player.fallback;else img.hidden=true;},{once:true});person.append(img);break;}}catch{}}
            const name=document.createElement('strong');name.textContent=String(player.nome||'Giocatore');person.append(name);matchup.append(person);
          });body.append(matchup);
        }
        if(Array.isArray(options.gameChips)&&options.gameChips.length){const chips=document.createElement('div');chips.className='ui-board-chips';options.gameChips.slice(0,6).forEach(text=>{const chip=document.createElement('span');chip.textContent=String(text);chips.append(chip);});body.append(chips);}
      }
      if(Array.isArray(options.items)&&options.items.length){
        const section=document.createElement('section');section.className=activity?'ui-notice-updates':'ui-notice-order';
        const label=document.createElement('h3');label.textContent=options.itemsTitle||'Ordine dei turni';section.append(label);
        const list=document.createElement(activity?'ul':'ol');
        options.items.forEach((item,index)=>{
          const row=document.createElement('li'),name=document.createElement('strong');name.textContent=String(item.label||'');
          const copy=activity?document.createElement('div'):row;
          if(activity){const mark=document.createElement('span');mark.className='ui-notice-mark';mark.setAttribute('aria-hidden','true');if(item.image==='/assets/placeholder.svg'){const art=document.createElement('img');art.src=item.image;art.alt='';mark.append(art);}else mark.textContent=String(item.symbol||'✦');row.append(mark);copy.className='ui-notice-copy';row.append(copy);}
          copy.append(name);if(item.meta){const meta=document.createElement('span');meta.textContent=String(item.meta);copy.append(meta);}
          if(activity&&item.actionUrl){
            try{const url=new URL(item.actionUrl,location.origin);if(url.origin===location.origin){const link=document.createElement('a');link.className='ui-notice-item-link';link.href=url.pathname+url.search+url.hash;link.textContent=item.actionLabel||'Leggi';link.setAttribute('aria-label',link.textContent+': '+String(item.label||''));link.addEventListener('click',event=>{if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;event.preventDefault();dialog.close('item:'+index);if(window.alphanoWorkspace)window.alphanoWorkspace.open(link.getAttribute('href'),String(item.label||'Notifica'));else location.assign(link.href);});copy.append(link);}}catch(_){/* Un riferimento non valido resta un avviso leggibile. */}
          }
          list.append(row);
        });
        section.append(list);body.append(section);
      }
      if(options.detail){const detail=document.createElement('p');detail.className='ui-notice-detail';detail.textContent=String(options.detail);body.append(detail);}
      if(options.contentUrl){
        const url=new URL(options.contentUrl,location.origin);
        // Only the game's fortune wheel and authenticated commerce cards may be embedded.
        if(url.origin===location.origin&&(url.pathname==='/fortuna/'||(url.pathname==='/commercio/'&&/^\d+$/.test(url.searchParams.get('id')||'')))){
          dialog.classList.add('ui-frame-notice');
          const frame=document.createElement('iframe');frame.className='ui-notice-frame';frame.src=url.href;frame.title=url.pathname==='/fortuna/'?'Ruota della sorte':'Trattativa commerciale';body.append(frame);
        }
      }
      const footer=document.createElement('footer');footer.className='modal-footer';
      const accept=document.createElement('button');accept.type='button';accept.className='ui-notice-accept';accept.autofocus=true;accept.textContent=options.buttonLabel||'Ho capito';accept.addEventListener('click',()=>dialog.close('ack'));footer.append(accept);
      if(options.cancelLabel){const cancel=document.createElement('button');cancel.type='button';cancel.textContent=options.cancelLabel;cancel.addEventListener('click',()=>dialog.close('dismiss'));footer.prepend(cancel);}
      if(options.actionUrl){accept.addEventListener('click',()=>{const url=new URL(options.actionUrl,location.origin);if(url.origin===location.origin){if(window.alphanoWorkspace)window.alphanoWorkspace.open(url.pathname+url.search+url.hash,options.title||'Notifiche');else location.assign(url.href);}});}
      dialog.append(header,body,footer);
      dialog.addEventListener('click',event=>{const r=dialog.getBoundingClientRect();if(event.target===dialog&&(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom))dialog.close('dismiss');});
      dialog.addEventListener('close',()=>{const result=dialog.returnValue;dialog.remove();if(previous?.isConnected)previous.focus({preventScroll:true});resolve(result);},{once:true});
      document.body.append(dialog);dialog.showModal();accept.focus({preventScroll:true});
    });
  }
  window.alphanoDialog={notice(options={}){
    if(typeof options==='string')options={message:options};
    // La cornice principale rende inerte l'intera interfaccia, non solo il contenuto della chat.
    try{if(window.parent!==window&&location.pathname!=='/commercio/'&&window.parent.location.origin===location.origin&&window.parent.alphanoDialog)return window.parent.alphanoDialog.notice(options);}catch(_){/* Finestra autonoma o cornice di altra origine. */}
    const result=pending.then(()=>show(options));pending=result.catch(()=>{});return result;
  }};
})();
