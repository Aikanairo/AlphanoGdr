document.addEventListener('DOMContentLoaded',()=>{
 const fallback=img=>{if(img.dataset.defaultSrc&&img.getAttribute('src')!==img.dataset.defaultSrc)img.src=img.dataset.defaultSrc;};
 document.querySelectorAll('.ranking-directory img[data-default-src]').forEach(img=>{img.addEventListener('error',()=>fallback(img));if(img.complete&&!img.naturalWidth)fallback(img);});
});
