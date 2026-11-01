document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-poll-tab]').forEach(button=>button.addEventListener('click',()=>{
    const selected=button.dataset.pollTab;
    document.querySelectorAll('[data-poll-tab]').forEach(tab=>{tab.classList.toggle('active',tab===button);tab.setAttribute('aria-pressed',String(tab===button));});
    document.querySelectorAll('[data-poll-panel]').forEach(panel=>{panel.hidden=panel.dataset.pollPanel!==selected;});
  }));
});
