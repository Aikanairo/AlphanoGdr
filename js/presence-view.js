import './game-status.js';
/* Public location headings and the same per-player NPC preference on both surfaces. */
const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const key=owner=>'alphano:presence:npcs:'+owner;
export function presenceShowsNpcs(owner){try{return localStorage.getItem(key(owner))!=='false';}catch{return true;}}
export function presenceSaveNpcs(owner,show){try{localStorage.setItem(key(owner),String(show));}catch{}}
export function presencePlaceHeading(place){
 const title=esc(place.title),image=place.image?`<img class="presence-place-image" src="${esc(place.image)}" alt="" width="40" height="30" loading="lazy">`:'';
 const heading=place.url?`<a class="presence-link presence-place-link" href="${esc(place.url)}" data-url="${esc(place.url)}" data-title="${title}">${image}<span>${title}</span></a>`:`<span class="presence-place-link">${title}</span>`;
 const turn=place.my_turn?`<span class="presence-my-turn"><span class="presence-session-dot" aria-hidden="true"></span>È il tuo turno di gioco · <time data-game-deadline="${Number(place.my_turn.deadline)}" data-server-time="${Number(place.my_turn.server_time)}"></time></span>`:'';
 return heading+turn+(place.session_active?'<span class="presence-session"><span class="presence-session-dot" aria-hidden="true"></span>Sessione in corso</span>':'');
}

export function presenceGameAction(person){
 if(person.kind!=='player'||!person.game_invite_visible)return '';
 const label=person.can_invite?'Invita '+person.name+' a Scacchi o Dama':'Invito non disponibile: '+person.name+' non ha una connessione attiva';
 const art='<img src="/assets/placeholder.svg" alt="" width="36" height="36">';
 return person.can_invite?`<a class="presence-game-btn" data-presence-game href="/giochi/invita.php?opponent=${Number(person.id)}" title="${esc(label)}" aria-label="${esc(label)}">${art}</a>`:`<button type="button" class="presence-game-btn is-unavailable" disabled aria-disabled="true" title="${esc(label)}" aria-label="${esc(label)}">${art}</button>`;
}
