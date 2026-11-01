const mailEsc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const mailData=document.getElementById('mail-data'),posta=JSON.parse(mailData.textContent),mailViewer=Number(mailData.dataset.viewer);
let activeMailbox=0,mailListPending=false;
function mailSource(box){
 if(box!==0)return posta[box];
 const unique=new Map();for(const item of [...posta[0],...posta[2].filter(x=>x.kind==='personal')])unique.set(item.id,item);return [...unique.values()];
}
function mailGroups(box){
 const groups=new Map();
 for(const item of mailSource(box)){
  const personal=item.kind==='personal',other=item.from.id===mailViewer?item.to:item.from,key=personal?'person-'+other.id:'notice-'+item.id;
  if(!groups.has(key))groups.set(key,{key,person:other,personal,items:[]});groups.get(key).items.push(item);
 }
 for(const group of groups.values()){
  group.items.sort((a,b)=>b.id-a.id);group.latest=group.items[0];group.unread=group.items.filter(x=>x.to.id===mailViewer&&!x.letto).length;
 }
 return [...groups.values()].sort((a,b)=>b.latest.id-a.latest.id);
}
function Carica(box){
 activeMailbox=box;
 document.querySelectorAll('.mail-tabs .hdr').forEach((tab,i)=>{tab.classList.toggle('active',i===box);tab.setAttribute('aria-selected',String(i===box));});
 const query=document.getElementById('mail-search').value.trim().toLocaleLowerCase('it'),kind=document.getElementById('mail-kind').value,state=document.getElementById('mail-state').value;
 const all=mailGroups(box),groups=all.filter(g=>g.items.some(x=>(!query||(g.person.name+' '+x.oggetto+' '+(x.preview||'')).toLocaleLowerCase('it').includes(query))&&document.querySelector(`[data-mail-type="${x.kind||'system'}"]`)?.checked&&(state==='all'||x.commerce?.key===state))&&(kind!=='unread'||g.unread>0));
 document.getElementById('mail-filter-count').textContent=groups.length+' di '+all.length+' conversazioni e avvisi';
 document.getElementById('Lista').innerHTML='<div class="mail-contact-list" aria-label="Conversazioni per personaggio">'+(groups.length?groups.map(g=>{
  const item=g.latest,p=g.person,date=new Date(item.data*1000),stamp=new Intl.DateTimeFormat('it',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}).format(date);
  return `<button type="button" data-ui-no-icon class="mail-contact ${g.unread?'mail-contact-unread':''}" data-open-mail="${item.id}">${p.portrait?`<img class="mail-contact-portrait" src="${mailEsc(p.portrait)}" data-mail-fallback="${mailEsc(p.fallback)}" alt="" loading="lazy">`:'<span class="mail-contact-seal" aria-hidden="true">✉</span>'}<span class="mail-contact-copy"><span class="mail-contact-top"><strong>${mailEsc(g.personal?p.name:item.commerce?.label||'Alphano · Avviso')}</strong><time>${mailEsc(stamp)}</time></span><span class="mail-contact-subject">${item.oggetto}</span><span class="mail-contact-preview">${item.from.id===mailViewer?'Tu: ':''}${mailEsc(item.preview||'Apri la missiva')}</span><span class="mail-contact-meta">${g.personal?g.items.length+(g.items.length===1?' missiva':' missive')+' nella casella':mailEsc(item.commerce?.next||'Comunicazione di gioco')}</span></span>${g.unread?`<span class="mail-contact-count" aria-label="${g.unread} ${g.unread===1?'non letto':'non letti'}">${g.unread}</span>`:'<span class="mail-contact-chevron" aria-hidden="true">›</span>'}</button>`;
 }).join(''):'<p class="ui-empty">Nessuna conversazione corrisponde ai filtri.</p>')+'</div>';
 document.querySelectorAll('[data-open-mail]').forEach(el=>el.addEventListener('click',()=>ReadMail(Number(el.dataset.openMail))));
 document.getElementById('Opzioni').innerHTML='<div class="ui-list-footer"><a class="scrivimissiva" href="./writeto.php">Scrivi una nuova missiva</a><p>Le missive sono raccolte per personaggio. Puoi eliminare i singoli messaggi dalla conversazione.</p></div>';
 const labels=['Conversazioni','Baule','Inviata'];labels.forEach((label,i)=>document.getElementById('hdr'+i).textContent=label+' ('+mailGroups(i).length+')');
}
function ReadMail(id){const form=document.getElementById('mail-actions-form');form.elements.namedItem('id').value=id;form.elements.namedItem('baule').disabled=true;form.action='./showmsg.php';form.submit();}
window.addEventListener('DOMContentLoaded',()=>{
 for(const el of document.querySelectorAll('[data-mail-type],#mail-search,#mail-kind,#mail-state'))el.addEventListener(el.type==='search'?'input':'change',()=>Carica(activeMailbox));
 document.getElementById('mail-filter-reset').addEventListener('click',()=>{document.querySelectorAll('[data-mail-type]').forEach(el=>el.checked=true);document.getElementById('mail-search').value='';document.getElementById('mail-kind').value='all';document.getElementById('mail-state').value='all';Carica(activeMailbox);});Carica(0);
});
async function refreshMailList(){if(mailListPending||document.hidden)return;mailListPending=true;try{const response=await fetch('/api/mail-list.php',{cache:'no-store'});if(!response.ok)return;const next=await response.json();if(JSON.stringify(next)!==JSON.stringify(posta)){posta.splice(0,posta.length,...next);Carica(activeMailbox);}}catch{}finally{mailListPending=false;}}
document.addEventListener('alphano:commerce-refresh',refreshMailList);document.addEventListener('alphano:mail-read',refreshMailList);setInterval(refreshMailList,30000);
