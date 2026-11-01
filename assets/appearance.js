/* Settings propagate to open documents without discarding a chat draft. */
(() => {
 'use strict';
 const key='alphano:appearance';
 async function refresh(){
  try{
   const response=await fetch('/api/appearance.php?page='+encodeURIComponent(location.pathname+location.search),{cache:'no-store'});
   if(!response.ok)return;
   const data=await response.json(),body=document.body;
   body.dataset.palette=data.theme;body.dataset.sidebar=data.sidebar;body.dataset.header=data.header;
   for(const kind of ['background','header']){const image=data.page[kind];if(image){body.dataset[kind==='background'?'customBackground':'customHeader']='1';body.style.setProperty('--custom-'+kind,`url("${image}")`);}else{delete body.dataset[kind==='background'?'customBackground':'customHeader'];body.style.removeProperty('--custom-'+kind);}}
   document.getElementById('alphano-page-appearance')?.remove();
   const link=document.querySelector('link[data-alphano-theme]');if(link&&link.getAttribute('href')!==data.stylesheet)link.href=data.stylesheet;
   window.dispatchEvent(new Event('alphano:appearance-updated'));
  }catch(_){/* A later navigation uses the server configuration as well. */}
 }
 window.addEventListener('storage',event=>{if(event.key===key)refresh();});
 window.addEventListener('message',event=>{if(event.origin===location.origin&&event.data?.type===key)refresh();});
 if(document.body.hasAttribute('data-appearance-saved')){
  try{localStorage.setItem(key,String(Date.now()));}catch(_){}
  if(window.parent!==window)window.parent.postMessage({type:key},location.origin);
 }
 const filter=document.querySelector('[data-appearance-filter]');
 filter?.addEventListener('input',()=>{const text=filter.value.trim().toLocaleLowerCase();document.querySelectorAll('[data-appearance-row]').forEach(row=>{row.hidden=!row.textContent.toLocaleLowerCase().includes(text);});});
 document.querySelectorAll('input[type=file][data-image-preview]').forEach(input=>input.addEventListener('change',()=>{
  const image=document.getElementById(input.dataset.imagePreview),file=input.files[0];if(!image||!file)return;
  const url=URL.createObjectURL(file);image.src=url;image.hidden=false;image.onload=()=>URL.revokeObjectURL(url);
 }));
 document.querySelectorAll('[data-default-art-picker]').forEach(select=>select.addEventListener('change',()=>{const preview=document.getElementById(select.dataset.defaultArtPicker);if(preview)preview.src=select.value||preview.dataset.defaultSrc;}));
})();
