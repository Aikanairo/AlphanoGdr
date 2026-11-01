/* Presentazione dei risultati; query e autorizzazioni rimangono nel controller PHP. */
const ricerca=JSON.parse(document.getElementById('search-data').textContent);
let orderby='nome',ordersort=true,searchLimit=20;
function Carica(order){
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const raceIcon=()=>'/assets/placeholder.svg';
 if(order){searchLimit=20;if(orderby===order)ordersort=!ordersort;else{orderby=order;ordersort=true;}}
 const rows=[...ricerca].sort((a,b)=>String(a[orderby]??'').localeCompare(String(b[orderby]??''),'it',{numeric:true})*(ordersort?1:-1));
 const fields=[['Nome','nome'],['Razza','razza'],['Allineamento','allineamento'],['Cariche','org'],['Posizione','posizione']];
 const header=fields.map(([label,field])=>`<th scope="col" aria-sort="${orderby===field?(ordersort?'ascending':'descending'):'none'}"><button class="ui-sort" type="button" data-sort="${field}">${label}${orderby===field?(ordersort?' ↑':' ↓'):''}</button></th>`).join('');
 const badge=(url,name,description)=>url?`<img src="${esc(url)}" width="25" height="25" alt="${esc(name)}" title="${esc([name,description].filter(Boolean).join(' · '))}">`:'';
 document.getElementById('risultato').innerHTML=`<table class="ui-data-table search-table"><caption>${rows.length} personaggi trovati</caption><thead><tr>${header}</tr></thead><tbody>${rows.slice(0,searchLimit).map(r=>`<tr>
  <td class="search-character"><img class="search-portrait" src="${esc(r.portrait)}" data-mail-fallback="${esc(r.portrait_default)}" alt="" loading="lazy"><a href="/profili/?Id=${Number(r.idpg)}"><strong>${esc(r.nome)}</strong></a></td>
  <td data-label="Razza"><span class="ui-person"><img class="race-emblem" src="${raceIcon(r.imgrazza)}" width="26" height="26" alt="${esc(r.razza)}" title="${esc(r.razza)}"> ${esc(r.razza)}</span></td>
  <td data-label="Allineamento">${esc(r.allineamento)}</td>
  <td data-label="Cariche"><span class="ui-badge-row">${badge(r.org_url,r.org,r.descorg)}${badge(r.corp_url,r.corp,r.desccorp)}${badge(r.araldica_url,r.araldica,r.descaral)}</span></td>
  <td data-label="Posizione">${esc(r.posizione)}</td></tr>`).join('')||'<tr><td colspan="5" class="ui-empty">Nessun personaggio corrisponde alla ricerca.</td></tr>'}</tbody></table>${rows.length>searchLimit?`<div class="search-more"><span>${Math.min(searchLimit,rows.length)} di ${rows.length} personaggi</span><button type="button" data-search-more>Mostra altri 20</button></div>`:''}`;
 document.querySelector('[data-search-more]')?.addEventListener('click',()=>{searchLimit+=20;Carica();});
 document.querySelectorAll('[data-sort]').forEach(button=>button.addEventListener('click',()=>Carica(button.dataset.sort)));
}
