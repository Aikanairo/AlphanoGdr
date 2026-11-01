import {renderMetamorphosis} from './game-status.js';
const init=()=>{
 const sheet=document.querySelector('[data-rpg-profile][data-kind="player"]');if(!sheet)return;
 const header=sheet.querySelector('.rpg-hero');try{renderMetamorphosis(header,JSON.parse(header.dataset.metamorphosisState||'null'));}catch{}let busy=false;
 async function refresh(){if(busy||document.hidden)return;busy=true;try{const response=await fetch('/api/profile.php?uid='+encodeURIComponent(sheet.dataset.id),{cache:'no-store',signal:AbortSignal.timeout(10000)});if(response.ok)renderMetamorphosis(header,(await response.json()).metamorphosis);}catch{}finally{busy=false;}}
 refresh();setInterval(refresh,15000);
};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
