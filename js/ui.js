/* Componenti di presentazione condivisi. I nodi dei form vengono conservati. */
(() => {
  'use strict';
  // Geometrie vettoriali del riferimento: un solo set, riusato da tutta l’applicazione.
  const iconShapes = {"male":[["circle",{"cx":"9","cy":"15","r":"6"}],["path",{"d":"m13 11 7-7M15 4h5v5"}]],"female":[["circle",{"cx":"12","cy":"8","r":"5"}],["path",{"d":"M12 13v9M8 18h8"}]],"menu":[["path",{"d":"M4 6h16M4 12h16M4 18h16"}]],"book":[["path",{"d":"M12 7c-3-2-6-2-9-1v13c4-1 7-1 9 1 2-2 5-2 9-1V6c-3-1-6-1-9 1zM12 7v13"}]],"shield":[["path",{"d":"m12 2 8 4v6c0 5-3 8-8 10-5-2-8-5-8-10V6zM12 7v11M8 12h8"}]],"users":[["path",{"d":"M16 19v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M12 3a4 4 0 1 1-8 0M20 19v-2a4 4 0 0 0-3-3.8M15 4a4 4 0 0 1 0 8"}]],"user":[["circle",{"cx":"12","cy":"8","r":"4"}],["path",{"d":"M4 21v-2a8 8 0 0 1 16 0v2"}]],"mail":[["rect",{"x":"3","y":"5","width":"18","height":"14","rx":"2"}],["path",{"d":"m4 7 8 6 8-6"}]],"messages":[["path",{"d":"M4 4h16v13H9l-5 4zM8 9h8M8 13h5"}]],"settings":[["circle",{"cx":"12","cy":"12","r":"3"}],["path",{"d":"m9 2-.6 3-2 1.3-2.8-1L2 8l2.4 2 .2 3L2 15l1.6 3 2.8-1 2 1.4L9 22h6l.6-3.6 2-1.4 2.8 1L22 15l-2.6-2 .2-3L22 8l-1.6-2.7-2.8 1-2-1.3L15 2z"}]],"scroll":[["path",{"d":"M8 4h12v15a3 3 0 0 1-3 3H6a3 3 0 0 0 3-3V7a3 3 0 0 0-6 0v2h5M12 10h5M12 14h5"}]],"chart":[["path",{"d":"M3 3v18h18M7 16v-4m5 4V7m5 9v-6"}]],"coins":[["path",{"d":"M20 6c0 2-4 3-8 3S4 8 4 6s4-3 8-3 8 1 8 3Zm0 0v6c0 2-4 3-8 3s-8-1-8-3V6m0 6v6c0 2 4 3 8 3s8-1 8-3v-6"}]],"search":[["path",{"d":"M21 21l-6-6m2-6a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"}]],"check":[["path",{"d":"m4 12 5 5L20 6"}]],"back":[["path",{"d":"m14 5-7 7 7 7M7 12h14"}]],"compass":[["circle",{"cx":"12","cy":"12","r":"9"}],["path",{"d":"m16 8-3 5-5 3 3-5z"}]],"heart":[["path",{"d":"M20 5c-3-3-6-1-8 1-2-2-5-4-8-1-4 4 0 10 8 16 8-6 12-12 8-16z"}]],"city":[["path",{"d":"M3 21V7l3-2 3 2V3h6v4l3-2 3 2v14zM3 11h18M10 21v-6h4v6"}]],"moon":[["path",{"d":"M20 16A8 8 0 0 1 8 4a8 8 0 1 0 12 12z"}]],"message":[["path",{"d":"M4 4h16v13H9l-5 4zM8 9h8M8 13h5"}]],"gears":[["circle",{"cx":"12","cy":"12","r":"3"}],["path",{"d":"m9 2-.6 3-2 1.3-2.8-1L2 8l2.4 2 .2 3L2 15l1.6 3 2.8-1 2 1.4L9 22h6l.6-3.6 2-1.4 2.8 1L22 15l-2.6-2 .2-3L22 8l-1.6-2.7-2.8 1-2-1.3L15 2z"}]],"swords":[["path",{"d":"m3 3 8 8M21 3l-8 8M3 21l5-5M21 21l-5-5M7 7l10 10M17 7 7 17"}]],"dice":[["path",{"d":"m12 2 9 5v10l-9 5-9-5V7zM3 7l9 5 9-5M12 12v10"}],["circle",{"cx":"12","cy":"6.5","r":".8"}],["circle",{"cx":"7","cy":"15","r":".8"}],["circle",{"cx":"17","cy":"15","r":".8"}]],"map":[["path",{"d":"m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3zM9 3v15M15 6v15"}]],"castle":[["path",{"d":"M3 21V7l3-2 3 2V3h6v4l3-2 3 2v14zM3 11h18M10 21v-6h4v6"}]],"crown":[["path",{"d":"m2 7 5 4 5-7 5 7 5-4-2 13H4zM4 17h16"}]],"bell":[["path",{"d":"M18 8a6 6 0 0 0-12 0c0 8-3 8-3 10h18c0-2-3-2-3-10M10 21h4"}]],"expand":[["path",{"d":"M8 3H3v5M16 3h5v5M3 16v5h5M21 16v5h-5"}]],"x":[["path",{"d":"M5 5l14 14M19 5 5 19"}]],"send":[["path",{"d":"m22 2-7 20-4-9-9-4zM22 2 11 13"}]],"eye":[["path",{"d":"M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z"}],["circle",{"cx":"12","cy":"12","r":"3"}]],"chev":[["path",{"d":"m6 9 6 6 6-6"}]],"pen":[["path",{"d":"m3 21 6-2L21 7l-4-4L5 15zM14 6l4 4"}]],"clock":[["circle",{"cx":"12","cy":"12","r":"9"}],["path",{"d":"M12 6v6l4 2"}]],"bag":[["path",{"d":"M6 8h12l2 13H4zM9 8V6a3 3 0 0 1 6 0v2"}]]};
  Object.assign(iconShapes,{"stat-strength":[["path",{"d":"M5 20h12l3-7-3-4-4 2-2-2 1-5-4-1-3 6 2 5-2 6zM12 12l4 2"}]],"stat-dexterity":[["path",{"d":"m13 2-8 12h6l-1 8 9-13h-6z"}]],"stat-constitution":[["path",{"d":"M12 22s-9-5-9-12V4l9-3 9 3v6c0 7-9 12-9 12zM7 11h3l2-4 2 8 2-4h2"}]],"stat-intelligence":[["path",{"d":"M9 4a3 3 0 0 0-5 3 3 3 0 0 0-1 5 3 3 0 0 0 2 5 3 3 0 0 0 5 3V4zm5 0a3 3 0 0 1 5 3 3 3 0 0 1 1 5 3 3 0 0 1-2 5 3 3 0 0 1-5 3V4zM6 9l4 1m4 4 4-2M7 16l3-1m4-7 3 1"}]],"stat-wisdom":[["path",{"d":"M3 5c3-2 6-2 9 0 3-2 6-2 9 0v15c-3-2-6-2-9 0-3-2-6-2-9 0V5zm9 0v15M6 8h3m-3 4h3m6-4h3m-3 4h3"}]],"stat-charisma":[["path",{"d":"m3 7 4 3 5-7 5 7 4-3-2 12H5L3 7zm3 9h12"}]],"stat-perception":[["path",{"d":"M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"}],["circle",{"cx":"12","cy":"12","r":"3"}]],"stat-willpower":[["path",{"d":"M12 2c3 5-1 6 2 9 1-2 2-3 4-3 0 4 4 6 2 10-2 5-11 5-14 0-3-5 3-9 6-16zm0 12c-5 6 1 9 3 5"}]],"skill":[["path",{"d":"M4 20 20 4m-7 0h7v7M3 15l6 6M3 6l2 2m13 12 2 2M12 2v3m8 9h3"}]]});
  Object.assign(iconShapes,{"equip-armor":[["path",{"d":"m8 3 4 2 4-2 5 4-3 5-2-1v10H8V11l-2 1-3-5z"}],["path",{"d":"M9 9h6M12 5v15"}]],"equip-sword":[["path",{"d":"m5 19 4-4M8 22l-6-6M10 14l11-11-2 6-8 8M7 16l3 3"}]],"equip-shield":[["path",{"d":"m12 2 8 4v5c0 5-3 8-8 11-5-3-8-6-8-11V6z"}],["path",{"d":"M12 6v12M7 11h10"}]],"equip-hat":[["path",{"d":"m6 17 3-13h6l3 13M3 18c2 5 16 5 18 0l-3-2H6z"}],["path",{"d":"M7 13h10"}]],"equip-hand":[["path",{"d":"M7 12V5a2 2 0 0 1 4 0v6-7a2 2 0 0 1 4 0v7-5a2 2 0 0 1 4 0v9c0 5-3 7-7 7-4 0-8-2-8-7V9a2 2 0 0 1 3 0z"}]],"equip-leg":[["path",{"d":"M6 2h12l-2 18-4 2-2-10-2 10-4-2z"}]],"equip-boot":[["path",{"d":"M8 3h7v12l6 3v3H2v-5h6z"}],["path",{"d":"M8 14h7"}]],"equip-amulet":[["path",{"d":"M5 3c0 7 2 9 7 9s7-2 7-9"}],["path",{"d":"m12 11 5 4-5 7-5-7z"}]],"equip-ring":[["ellipse",{"cx":"12","cy":"15","rx":"7","ry":"6"}],["path",{"d":"m9 5 3-3 3 3-3 5z"}]],"equip-belt":[["rect",{"x":"2","y":"9","width":"20","height":"7","rx":"1"}],["rect",{"x":"9","y":"8","width":"7","height":"9","rx":"1"}],["path",{"d":"M9 12h7"}]],"equip-cloak":[["path",{"d":"M10 3h4l2 4 5 13c-5 2-13 2-18 0L8 7z"}],["path",{"d":"m9 7 3 3 3-3"}]],"equip-bag":[["path",{"d":"M5 9h14l2 12H3z"}],["path",{"d":"M8 10V7a4 4 0 0 1 8 0v3"}]],"equip-book":[["path",{"d":"M4 4c3-2 6-1 8 1 2-2 5-3 8-1v16c-3-1-6-1-8 1-2-2-5-2-8-1z"}],["path",{"d":"M12 5v16"}]],"equip-star":[["path",{"d":"m12 2 3 7 7 3-7 3-3 7-3-7-7-3 7-3z"}]]});
  iconShapes.sparkles=iconShapes.skill;
  iconShapes.volume=[["path",{d:"M11 5 6 9H3v6h3l5 4V5zM15 8a6 6 0 0 1 0 8M18 5a10 10 0 0 1 0 14"}]];
  iconShapes.refresh=[["path",{d:"M20 7v5h-5M4 17v-5h5M5 8a8 8 0 0 1 13-3l2 2M4 17l2 2a8 8 0 0 0 13-3"}]];
  iconShapes.minus=[["path",{d:"M5 12h14"}]];
  iconShapes.plus=[["path",{d:"M12 5v14M5 12h14"}]];
  function icon(name) {
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');svg.setAttribute('focusable','false');svg.classList.add('ui-icon');
    for(const [tag,attributes] of iconShapes[name]||iconShapes.scroll){const shape=document.createElementNS(svg.namespaceURI,tag);for(const [key,value] of Object.entries(attributes))shape.setAttribute(key,value);svg.append(shape);}return svg;
  }
  const chooseIcon = text => /torna|indietro|annulla/i.test(text)?'back':/posta|missiv|casella|baule|inviata|avvis/i.test(text)?'mail':/ferit|ferisc|salut|resuscit|morte/i.test(text)?'heart':/coron|finanz|budget|stipend|cost|prestiti/i.test(text)?'coins':/memb|iscritt|richiest|relazion|personagg/i.test(text)?'users':/caric|araldic|rami|corporaz|gerarchia|appartenen|assegnaz|equipaggi|zaino/i.test(text)?'shield':/trasform|licantrop|senzien|ancestral/i.test(text)?'moon':/statut|storia|note|narr|regol|firma/i.test(text)?'book':/dimor|abitaz|quartier|città|cittadin|interna|territorio/i.test(text)?'city':/sicurez|access|privileg|flag|gestion/i.test(text)?'settings':/aspetto|identità|profil/i.test(text)?'user':'scroll';
  let fieldNumber=0;
  function normalizeForms(body){
    body.querySelectorAll('form table:not([id])').forEach(table=>{
      if(table.parentElement.closest('table')||table.dataset.preserveTable||table.querySelector('th'))return;
      const rows=Array.from(table.rows),fieldRows=rows.filter(row=>row.cells.length===2&&row.cells[1].querySelector('input:not([type=hidden]),select,textarea'));
      const vertical=rows.length>1&&fieldRows.length>=1&&rows.every(row=>row.cells.length<=2);
      const sequential=rows.length>2&&rows.every(row=>row.cells.length===1)&&table.querySelectorAll('input:not([type=hidden]),select,textarea').length>=2;
      if(!vertical&&!sequential)return;
      const fields=document.createElement('div');fields.className='ui-form-fields';
      for(let i=0;i<rows.length;i++){
        const cells=Array.from(rows[i].cells),row=document.createElement('div');row.className='ui-form-field';
        let labelCell=null,valueCell=null;
        if(vertical&&cells.length===2){[labelCell,valueCell]=cells;}
        else if(sequential&&!cells[0].querySelector('input,select,textarea,button')&&rows[i+1]?.cells[0]?.querySelector('input:not([type=hidden]),select,textarea')){
          labelCell=cells[0];valueCell=rows[++i].cells[0];
        }
        if(labelCell&&valueCell){
          const label=document.createElement('label'),value=document.createElement('div');value.className='ui-field-control';
          label.append(...labelCell.childNodes);value.append(...valueCell.childNodes);
          const control=value.querySelector('input:not([type=hidden]):not([type=submit]):not([type=button]),select,textarea');
          if(control){control.id=control.id||'ui-field-'+(++fieldNumber);label.htmlFor=control.id;}
          row.append(label,value);
        }else{
          row.classList.add('ui-form-wide');cells.forEach(cell=>row.append(...cell.childNodes));
        }
        fields.append(row);
      }
      table.replaceWith(fields);
    });
    const fieldNames={pospg:'Personaggio nel luogo',giorno:'Giorno',mese:'Mese',anno:'Anno',posizione:'Luogo',to:'Destinatario',nome:'Nome',nomepg:'Personaggio',pg:'Personaggio',user:'Personaggio',utente:'Personaggio',livello:'Livello',liv:'Livello',ramo:'Ramo',qta:'Quantità',quantita:'Quantità',importo:'Importo',punti:'Punti',carica:'Carica',araldica:'Araldica',stipendio:'Stipendio',stipendio_minimo:'Stipendio minimo',costo:'Costo',prezzo:'Prezzo',oggetto:'Oggetto',descrizione:'Descrizione',idcity:'Città',city:'Città',sito:'Sito',statuto:'Statuto',password:'Password',pass:'Password',email:'E-mail',tipo:'Tipo',durata:'Durata',motivo:'Motivo',nota:'Nota',testo:'Testo',budget:'Budget',nomeramo:'Nome del ramo',maxpg:'Numero massimo di personaggi',file:'File',immagine:'Immagine',newnome:'Nuovo nome',oldname:'Nome attuale',newname:'Nuovo nome'};
    body.querySelectorAll('form').forEach(form=>{
      form.classList.add('ui-form');
      if(form.dataset.uiFields||form.querySelector(':scope > label')||form.closest('.character-editor,.ai-admin,.forum-workspace'))return;
      const fields=Array.from(form.querySelectorAll(':scope > input:not([type=hidden]):not([type=button]):not([type=submit]):not([type=checkbox]):not([type=radio]),:scope > select,:scope > textarea'));
      if(!fields.length)return;
      form.dataset.uiFields='true';form.classList.add('ui-bare-form');
      fields.forEach(control=>{
        let title=control.getAttribute('aria-label')||control.getAttribute('placeholder')||control.getAttribute('title')||fieldNames[control.name.toLowerCase()]||'Valore';
        const previous=control.previousSibling;
        if(previous?.nodeType===Node.TEXT_NODE&&previous.textContent.trim()&&previous.textContent.trim().length<100){title=previous.textContent.trim().replace(/:\s*$/,'');previous.remove();}
        const label=document.createElement('label');label.className='ui-direct-field';
        const text=document.createElement('span');text.textContent=title;label.append(text);control.before(label);label.append(control);
      });
    });
  }
  function normalizeNavigation(body){
    body.querySelectorAll('.nav-panel:not([data-ui-navigation]),.sidebar:not(.right-col):not([data-ui-navigation])').forEach(nav=>{
      nav.dataset.uiNavigation='true';
      const items=Array.from(nav.querySelectorAll('li[data-action],li[onclick]'));
      if(items.length<2){nav.classList.add('ui-module-nav');return;}
      const toolbar=document.createElement('div');toolbar.className='ui-module-toolbar';
      const label=document.createElement('label'),select=document.createElement('select');
      label.append(icon('settings'),document.createTextNode('Operazione'));select.setAttribute('aria-label','Operazione');
      items.forEach((item,index)=>{const option=document.createElement('option');option.value=String(index);option.textContent=item.textContent.trim();option.selected=item.classList.contains('active');select.append(option);});
      select.addEventListener('change',()=>items[Number(select.value)].click());
      label.append(select);toolbar.append(label);nav.before(toolbar);nav.classList.add('ui-navigation-source');
    });
  }
  window.alphanoUI={icon,chooseIcon,reveal:element=>{let p=element;while(p){if(p.tagName==='DETAILS')p.open=true;const tab=(p.classList?.contains('ui-tabpanel')||p.getAttribute?.('role')==='tabpanel')&&document.getElementById(p.getAttribute('aria-labelledby'));if(tab)tab.click();p=p.parentElement;}}};
  const init=()=>{
    const body=document.body,view=body.dataset.view||'';
    body.querySelectorAll('img[data-avatar-fallback],img[data-fallback-src]').forEach(img=>{
      const defaultSrc=img.dataset.fallbackSrc||'/assets/placeholder.svg';const fallback=()=>{if(img.getAttribute('src')!==defaultSrc)img.src=defaultSrc;};
      img.addEventListener('error',fallback);if(img.complete&&!img.naturalWidth)fallback();
    });
    const config=JSON.parse(document.getElementById('alphano-ui-config')?.textContent||'{}');
    body.querySelectorAll('[data-icon]').forEach(el=>{if(!el.querySelector('svg'))el.prepend(icon(el.dataset.icon));});
    const category=config.views?.[view]||config.categories?.[view.split('-')[0]];
    if(view==='chat-index'){body.querySelectorAll('.tabs button').forEach(el=>el.prepend(icon(el.dataset.tab==='chat'?'messages':el.dataset.tab==='descrizione'?'book':'settings')));const send=body.querySelector('#sendBtn');if(send&&!send.hasAttribute('data-ui-no-icon')&&!send.querySelector('svg,img'))send.prepend(icon('send'));nativeTabs(body);return;}
    if (!category || ['vari-anteprimagml','profili-suggpass'].includes(view)) return;
    body.classList.add('ui-page');
    if(body.hasAttribute('data-ui-workspace')) return;
    const toast=body.querySelector('#toast');if(toast){toast.setAttribute('role','status');toast.setAttribute('aria-live','polite');}
    // Una sola intestazione interna. Le intestazioni con controlli rimangono intatte.
    const old=body.querySelector(':scope > h1, :scope > header h1, :scope > .page-header, :scope > .container > h1, :scope > .container > .editor > h1, :scope > .container > .view > h1, :scope > .header:not(:has(button, a, input)), :scope > .profile > .header h1');
    let title=old?.textContent.trim()||document.title.split(/\s+[–—]|\.::/)[0].trim()||category.title;
    if(view==='corporazioni-index')title='Corporazioni';
    if(view==='organizzazioni-index')title='Organizzazioni';
    const authoredHeading=body.querySelector('[data-page-heading]');
    const heading=document.createElement('header'); heading.className='ui-page-heading';
    const text=document.createElement('div'),kicker=document.createElement('div'),h1=document.createElement('h1'),description=document.createElement('p');
    kicker.className='ui-eyebrow';kicker.textContent='Alphano · '+category.title;
    h1.textContent=title;description.textContent=category.description;
    text.append(kicker,h1,description);heading.append(icon(category.icon),text);if(!authoredHeading){heading.dataset.pageHeading='';const brand=body.querySelector('[data-management-brand]');if(brand)brand.after(heading);else body.prepend(heading);}
    if(!authoredHeading && old && !old.querySelector('button,a,input,select')) {old.classList.add('ui-replaced-heading');if(old.parentElement.tagName==='HEADER' && old.parentElement.children.length===1)old.parentElement.classList.add('ui-replaced-heading');}

    const spec=config.tabs?.[view];
    if(spec){
      let panels=[];
      if(spec.sections){
        const headings=Array.from(body.querySelectorAll(spec.sections));
        if(headings.length>1){
          headings.forEach(h=>{
            const section=document.createElement('section'); section.dataset.section=h.dataset.section||h.textContent.trim();
            h.before(section);
            let next=h;
            while(next && (!headings.includes(next)||next===h)){
              const sibling=next.nextSibling;section.append(next);next=sibling;
            }
            panels.push(section);
          });
        }
      }else panels=Array.from(body.querySelectorAll(spec.selector));
      if(panels.length>1 && panels.every(p=>p.parentElement===panels[0].parentElement)){
        if(spec.groups) panels=groupPanels(panels,spec.groups);
        makeTabs(panels,spec.label,view);
      }
    }
    // Superfici narrative definite per vista, comprese quelle caricate via API.
    const reading=config.reading?.[view];
    function decorate(){
      normalizeForms(body);
      normalizeNavigation(body);
      body.querySelectorAll('table').forEach(table=>{
        if(table.parentElement.closest('table')||(!table.classList.contains('ui-data-table')&&!table.querySelector('th')))return;
        if(!table.classList.contains('session-table'))table.classList.add('ui-data-table');
        if(table.closest('.ui-table-scroll,.search-results'))return;
        const wrap=document.createElement('div');wrap.className='ui-table-scroll';wrap.tabIndex=0;wrap.setAttribute('role','region');wrap.setAttribute('aria-label','Tabella: scorrimento orizzontale');table.before(wrap);wrap.append(table);
      });
      body.querySelectorAll('.ui-tabpanel:not(.forum-category):not(.ui-tabgroup), .ui-fieldgroup').forEach(el=>el.classList.add('ui-surface'));
      body.querySelectorAll('[data-icon]').forEach(el=>{if(!el.querySelector('svg'))el.prepend(icon(el.dataset.icon));});
      Object.entries(config.actions||{}).forEach(([name,label])=>body.querySelectorAll('a.'+name).forEach(el=>{if(!el.textContent.trim()&&!el.querySelector('img')){el.textContent=label;el.prepend(icon(/mess|missiv|rispon/i.test(label)?'mail':'scroll'));el.classList.add('ui-action');el.setAttribute('aria-label',label);}}));
      const dynamic=config.dynamicTabs?.[view];
      if(dynamic){const items=Array.from(body.querySelectorAll(dynamic.selector+':not(.ui-tabpanel)'));if(items.length>1 && items.every(p=>p.parentElement===items[0].parentElement))makeTabs(items,dynamic.label,view);}
      if(reading) body.querySelectorAll(reading).forEach(el=>el.classList.add('ui-reading'));

      body.querySelectorAll('.tabs button, .tabs > li > a, .tabs > .tab:not(:has(button)), .actions > a.button').forEach(el=>{
        if(!el.hasAttribute('data-ui-no-icon')&&!el.querySelector('svg,img')&&el.textContent.trim())el.prepend(icon(chooseIcon(el.textContent)));
      });
      body.querySelectorAll('button,.action-btn,.btn,.button').forEach(el=>{
        if(el.hasAttribute('data-ui-no-icon')||el.closest('.ql-toolbar,.ql-tooltip')||el.querySelector('svg')||!el.textContent.trim()||el.classList.contains('ui-sort'))return;
        el.prepend(icon(chooseIcon(el.textContent)));
      });
      window.alphanoHeaders?.refresh(body,config);
    }
    decorate();
    nativeTabs(body);
    const revealHash=()=>{
      let id;try{id=decodeURIComponent(location.hash.slice(1));}catch(_){return;}
      const target=id&&document.getElementById(id);if(target)window.alphanoUI.reveal(target);
    };
    revealHash();
    // I controller inizializzano le proprie schede a DOMContentLoaded: il link
    // contestuale viene ripristinato anche dopo la loro selezione predefinita.
    window.addEventListener('load',revealHash,{once:true});
    window.addEventListener('hashchange',revealHash);
    let pending=false;
    new MutationObserver(records=>{
      if(pending||!records.some(r=>r.addedNodes.length))return;
      pending=true;queueMicrotask(()=>{decorate();pending=false;});
    }).observe(body,{childList:true,subtree:true});
  };
  function nativeTabs(body){
    body.querySelectorAll('.tabs').forEach(nav=>{
      const controls=Array.from(nav.querySelectorAll(':scope > button, :scope > .tab:not(:has(button)), :scope > li > a'));
      const pairs=controls.map(control=>{
        const id=control.dataset.tab || control.getAttribute('aria-controls') || (control.getAttribute('href')||'').replace(/^#/,'') || (control.id.startsWith('tab-')?control.id.replace(/-btn$/,''):control.id.startsWith('m')?control.id.slice(1):'');
        return [control,document.getElementById(id)];
      });
      if(!pairs.length||pairs.some(([,panel])=>!panel))return;
      nav.setAttribute('role','tablist');nav.setAttribute('aria-label',nav.getAttribute('aria-label')||'Sezioni');
      function sync(){pairs.forEach(([control,panel])=>{const active=control.classList.contains('active');control.setAttribute('aria-selected',String(active));control.tabIndex=active?0:-1;panel.setAttribute('role','tabpanel');if(active)panel.setAttribute('aria-labelledby',control.id);});}
      pairs.forEach(([control,panel],index)=>{
        control.setAttribute('role','tab');control.id=control.id||'ui-native-'+panel.id;control.setAttribute('aria-controls',panel.id);panel.setAttribute('aria-labelledby',control.id);
        control.addEventListener('click',()=>{
          if(nav.hasAttribute('data-character-tabs')) pairs.forEach(([tab,section])=>{const active=tab===control;tab.classList.toggle('active',active);section.classList.toggle('active',active);section.hidden=!active;});
          queueMicrotask(sync);
        });
        control.addEventListener('keydown',event=>{
          if(event.key==='Enter'||event.key===' '){event.preventDefault();control.click();return;}
          let next=index;if(event.key==='ArrowRight')next=(index+1)%pairs.length;else if(event.key==='ArrowLeft')next=(index+pairs.length-1)%pairs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=pairs.length-1;else return;
          event.preventDefault();pairs[next][0].click();pairs[next][0].focus();
        });
      });sync();window.addEventListener('load',sync,{once:true});
      new MutationObserver(sync).observe(nav,{attributes:true,attributeFilter:['class'],subtree:true});
    });
  }
  function panelTitle(panel){return panel.dataset.section||panel.querySelector('header,h2,h3,h4,.title')?.textContent.trim()||'';}
  function groupPanels(panels,groups){
    const parent=panels[0].parentElement,marker=document.createComment('schede raggruppate');
    parent.insertBefore(marker,panels[0]);
    const result=[],used=new Set();
    Object.entries(groups).forEach(([title,names])=>{
      const matching=panels.filter(p=>names.includes(panelTitle(p)));
      if(!matching.length)return;
      const section=document.createElement('section');section.dataset.section=title;section.className='ui-tabgroup';
      matching.forEach(p=>{used.add(p);p.classList.add('ui-fieldgroup');section.append(p);});result.push(section);
    });
    panels.filter(p=>!used.has(p)).forEach(p=>result.push(p));
    result.forEach(p=>parent.insertBefore(p,marker));marker.remove();return result;
  }
  function makeTabs(panels,label,view){
    const host=panels[0].parentElement,nav=document.createElement('div');nav.className='ui-tablist';nav.setAttribute('role','tablist');nav.setAttribute('aria-label',label);host.insertBefore(nav,panels[0]);host.classList.add('ui-tab-host');
    const key='alphano:tab:'+view+':'+location.search;
    const getSaved=()=>{try{return sessionStorage.getItem(key);}catch(_){return null;}};
    const save=value=>{try{sessionStorage.setItem(key,value);}catch(_){/* Spazio privato non disponibile. */}};
    const buttons=[];
    function activate(index,focus=false){
      panels.forEach((p,i)=>{p.hidden=i!==index;buttons[i].setAttribute('aria-selected',String(i===index));buttons[i].tabIndex=i===index?0:-1;});
      save(panels[index].dataset.uiTabKey);if(focus){buttons[index].focus();buttons[index].scrollIntoView({block:'nearest',inline:'nearest'});}
    }
    panels.forEach((panel,index)=>{
      const title=panelTitle(panel)||'Sezione '+(index+1);
      const id='ui-pane-'+index;panel.dataset.uiTabKey=title;panel.id=panel.id||id;panel.classList.add('ui-tabpanel');panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',id+'-tab');
      const button=document.createElement('button');button.type='button';button.id=id+'-tab';button.setAttribute('role','tab');button.setAttribute('aria-controls',panel.id);button.append(icon(chooseIcon(title)),document.createTextNode(title));
      button.addEventListener('click',()=>activate(index));button.addEventListener('keydown',event=>{
        let n=index;if(event.key==='ArrowRight')n=(index+1)%panels.length;else if(event.key==='ArrowLeft')n=(index+panels.length-1)%panels.length;else if(event.key==='Home')n=0;else if(event.key==='End')n=panels.length-1;else return;
        event.preventDefault();activate(n,true);
      });buttons.push(button);nav.append(button);
      panel.addEventListener('invalid',()=>activate(index),true);
    });
    let selected=panels.findIndex(p=>p.dataset.uiTabKey===getSaved());
    // Dopo un invio che apre un editor, il pannello di modifica ha precedenza.
    const editing=panels.findIndex(p=>Array.from(p.querySelectorAll('h2,header')).some(h=>/^Modifica (?:Araldica|Ramo|Quartiere)/i.test(h.textContent.trim())));
    if(editing>=0)selected=editing;
    const editForm=panels.findIndex(p=>p.querySelector('input[name=action][value=modifycarica], input[name=action][value=modifyramo]'));if(editForm>=0)selected=editForm;
    const requested=panels.findIndex(p=>p.querySelector('[data-ui-open]'));if(requested>=0)selected=requested;
    activate(Math.max(0,selected));
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
