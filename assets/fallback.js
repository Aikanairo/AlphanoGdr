(() => {
 const fallback=img=>{if(img.dataset.alphanoFallback)return;img.dataset.alphanoFallback='1';img.src='/assets/placeholder.svg';};
 document.addEventListener('error',e=>{if(e.target instanceof HTMLImageElement)fallback(e.target);},true);
 document.querySelectorAll('img').forEach(img=>{if(img.complete&&!img.naturalWidth)fallback(img);});
})();
