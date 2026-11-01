document.addEventListener('DOMContentLoaded',()=>{
  const anchors={proposte:'new',modifiche:'modified','funzioni-organizzazioni':'roles',abilita:'abilities'};
  const showHash=()=>{const tab=anchors[location.hash.slice(1)||new URLSearchParams(location.search).get('panel')];if(tab)document.querySelector(`[data-academy-tab="${tab}"]`)?.click();};
  document.querySelectorAll('[data-academy-tab]').forEach(button=>button.addEventListener('click',()=>{
    const tab=button.dataset.academyTab;
    document.querySelectorAll('[data-academy-tab]').forEach(item=>{const selected=item===button;item.classList.toggle('active',selected);item.setAttribute('aria-pressed',String(selected));});
    document.querySelectorAll('[data-academy-panel]').forEach(panel=>{panel.hidden=panel.dataset.academyPanel!==tab;});
  }));
  document.querySelectorAll('[data-academy-confirm]').forEach(button=>button.addEventListener('click',event=>{if(!confirm(button.dataset.academyConfirm))event.preventDefault();}));
  window.addEventListener('hashchange',showHash);
  showHash();
});
