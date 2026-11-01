/* One page heading, shared artwork and live controls. Nodes keep their listeners. */
(() => {
  'use strict';
  function refresh(body,config){
    const heading=body.querySelector('[data-page-heading]');
    if(!heading)return;
    const view=body.dataset.view||'',family=view.split('-')[0];
    heading.classList.add('ui-integrated-heading');
    if(heading.dataset.headerImage&&heading.dataset.headerImage!=='/assets/placeholder.svg'&&!heading.classList.contains('rpg-hero')&&!heading.querySelector('.ui-header-art')){
      const art=document.createElement('img');art.className='ui-header-art';art.alt='';art.setAttribute('aria-hidden','true');art.decoding='async';
      art.src=heading.dataset.headerImage||'/assets/placeholder.svg';
      art.addEventListener('error',()=>{if(!art.src.endsWith('/assets/placeholder.svg'))art.src='/assets/placeholder.svg';});
      heading.prepend(art);
    }
    const selectors=['.tabs','.ui-tablist','.ui-module-toolbar','nav.forum-channels','.race-switcher','.ai-tabs','.city-assembly-nav','.media-admin-toolbar',...(config.headerControls?.[view]||[])];
    for(const selector of selectors)body.querySelectorAll(selector).forEach(nav=>{
      if(heading.contains(nav)){if(nav.parentElement===heading)nav.classList.add('ui-heading-control');return;}
      if(nav.closest('.modal,dialog,[role="tabpanel"],.tab-content,[data-rpg-panel-content]'))return;
      // Never detach submit fields from their form or move an in-content editor toolbar.
      if(nav.closest('form')!==nav&&nav.closest('form')&&nav.querySelector('input[name],select[name],textarea[name],button[type="submit"]'))return;
      heading.append(nav);nav.classList.add('ui-heading-control');
    });
    // Legacy articles may carry their own page h1. Keep a single document title,
    // while preserving distinct chapter headings and every child node/id.
    const title=heading.querySelector('h1')?.textContent.trim().toLocaleLowerCase();
    body.querySelectorAll('h1').forEach(old=>{
      if(heading.contains(old)||old.closest('.modal,dialog')||old.classList.contains('ui-replaced-heading'))return;
      if(old.textContent.trim().toLocaleLowerCase()===title){old.classList.add('ui-replaced-heading');return;}
      const section=document.createElement('h2');for(const attr of old.attributes)section.setAttribute(attr.name,attr.value);
      while(old.firstChild)section.append(old.firstChild);old.replaceWith(section);
    });
    // Embedded equipment has no second identity heading, but keeps its counters/filters.
    if(body.classList.contains('equipment-embedded'))heading.classList.add('ui-heading-embedded');
  }
  window.alphanoHeaders={refresh};
})();
