/* Server-calibrated deadlines shared by profile, sidebar and presence. */
export const escapeStatus=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const calibrated=new Map();
export function updateGameClocks(){
 for(const el of document.querySelectorAll('[data-game-deadline]')){
  const signature=el.dataset.serverTime;let clock=calibrated.get(signature);
  if(!clock||clock.signature!==signature){clock={signature,base:Number(el.dataset.serverTime)*1000,at:performance.now()};calibrated.set(signature,clock);if(calibrated.size>500)calibrated.delete(calibrated.keys().next().value);}
  const seconds=Math.max(0,Math.ceil(Number(el.dataset.gameDeadline)-(clock.base+performance.now()-clock.at)/1000));
  const days=Math.floor(seconds/86400),hours=Math.floor(seconds%86400/3600),minutes=Math.floor(seconds%3600/60),secs=seconds%60;
  el.textContent=seconds?(days?days+'g ':'')+(hours||days?String(hours).padStart(2,'0')+'h ':'')+String(minutes).padStart(2,'0')+'m '+String(secs).padStart(2,'0')+'s':(el.dataset.due||'Tempo scaduto');
 }
}
export function renderMetamorphosis(root,state){
 if(!root)return;
 root.classList.toggle('has-metamorphosis',!!state);root.dataset.metamorphosis=state?.kind||'';
 let notice=root.querySelector('[data-metamorphosis-notice]');
 if(!state){notice?.remove();return;}
 if(!notice){notice=document.createElement('div');notice.dataset.metamorphosisNotice='';notice.className='metamorphosis-notice';const anchor=root.matches('.profile-card')?root.querySelector('.character-top'):root.querySelector('.rpg-identity-line');if(anchor)anchor.after(notice);else root.append(notice);}
 const key=JSON.stringify(state);if(notice.dataset.state===key)return;notice.dataset.state=key;
 notice.innerHTML=`<strong>${escapeStatus(state.label)}</strong><span>${escapeStatus(state.detail)} <time data-game-deadline="${Number(state.deadline)}" data-server-time="${Number(state.server_time)}" data-due="${escapeStatus(state.due)}"></time></span>`;
 updateGameClocks();
}
setInterval(updateGameClocks,1000);
document.addEventListener('visibilitychange',updateGameClocks);
