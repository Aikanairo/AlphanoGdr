/* Every game overlay is rendered in the outer shell, even from nested chat frames. */
(()=>{
 'use strict';if(window.alphanoBoardNotices)return;
 const memory=new Map(),inflight=new Map();
 function storage(key,value){try{if(value!==undefined){sessionStorage.setItem(key,value);const keys=Object.keys(sessionStorage).filter(k=>k.startsWith('alphano:board-overlay:'));keys.slice(0,Math.max(0,keys.length-150)).forEach(k=>sessionStorage.removeItem(k));}return sessionStorage.getItem(key);}catch{return memory.get(key);}}
 function urlOf(g){return g.url||'/chat/index.php?'+new URLSearchParams({chat:g.chat||'',board_game:g.id});}
 function options(g){
  const viewer=Number(g.viewer),r=g.result||{},winner=Number(r.winner)||0,own=viewer===Number(g.a)||viewer===Number(g.b),game=g.game==='dama'?'Dama italiana':'Scacchi';
  const who=id=>g.players?.find(p=>Number(p.id)===Number(id))?.nome||'L’avversario';
  let tone='update',title='Un aggiornamento al tavolo',message=g.detail||'',symbol='♜',button='Apri il tavolo';
  if(g.status==='pending'){
   tone='invite';symbol='♞';title=viewer===Number(g.proposer)?'La sfida è stata inviata':'Un invito al tuo tavolo';
   message=viewer===Number(g.proposer)?'La proposta attende una risposta. Le poste rimangono disponibili fino all’accettazione.':who(g.proposer)+' ti invita a giocare. Esamina tempi e poste prima di accettare: puoi anche rifiutare o proporre modifiche.';
   button=viewer===Number(g.proposer)?'Rivedi la proposta':'Esamina e rispondi';
  }else if(g.status==='active'&&g.draw_by&&Number(g.draw_by)!==viewer){tone='invite';symbol='⚖';title='Una proposta di patta';message=who(g.draw_by)+' propone di concludere il confronto in parità. Valuta la proposta dal tavolo: nessuna decisione viene presa automaticamente.';button='Esamina la proposta';}
  else if(g.status==='active'){tone='start';title='La partita ha inizio';message='Il tavolo è pronto. Rispetta il turno indicato e continua a raccontare la tua scena nella chat.';symbol='♟';}
  else if(g.status==='finished'){
   tone=winner?(own?(winner===viewer?'victory':'defeat'):'victory'):'draw';symbol=tone==='victory'?'♛':tone==='draw'?'⚖':'♚';
   title=winner?(own?(winner===viewer?'Vittoria!':'La partita è conclusa'):who(winner)+' vince'):'Una partita alla pari';
   message=(winner?who(winner)+' si aggiudica la partita. ':'Il confronto termina in parità. ')+(r.reason||'');
   button='Rivedi esito e rivincita';
  }else if(g.status==='expired'){title='L’invito è scaduto';message='Il tempo per rispondere è terminato. Nessuna posta è stata impegnata: puoi proporre una nuova sfida.';}
  else if(g.status==='declined'){title='La sfida si chiude qui';message='L’invito è stato rifiutato o ritirato. Nessuna posta è stata addebitata.';}
  else if(g.status==='cancelled'){title='Partita annullata';message=r.reason||g.detail||'Le eventuali poste sono state restituite ai proprietari.';}
  const chips=[];
  if(g.status==='pending'&&g.terms){chips.push(Math.round(Number(g.terms.total||0)/60)+' min a testa','+'+Number(g.terms.increment||0)+' s per mossa');if(g.expires_at)chips.push('Scade alle '+new Date(g.expires_at*1000).toLocaleTimeString('it-IT',{hour:'2-digit',minute:'2-digit'}));}
  if(g.status==='finished'){chips.push(Math.floor(Number(r.duration||0)/60)+' min '+Number(r.duration||0)%60+' s',Number(r.plies||0)+(Number(r.plies)===1?' mossa individuale':' mosse individuali'));if(r.computer)chips.push('Allenamento · senza premi o ELO');else if(own){chips.push('Intelligenza +'+Number(r.intelligence?.[viewer]||0));const elo=Number(r.elo?.[viewer]||0);chips.push('ELO '+(elo>=0?'+':'')+elo);}}
  return {variant:'board-game',gameTone:tone,gameSymbol:symbol,eyebrow:'Alphano · '+game+' · Tavolo #'+Number(g.id),title,message,gamePlayers:g.players||[],gameChips:chips,
   detail:g.status==='finished'&&!r.computer?(r.prizes?.length?(winner?'Le poste sono state assegnate al vincitore.':'Le poste sono state restituite ai rispettivi proprietari.'):'Nessuna posta da trasferire.'):undefined,
   iconImage:'/assets/placeholder.svg',buttonLabel:g.onTable?'Torna al tavolo':button,cancelLabel:g.onTable?undefined:'Più tardi',actionUrl:g.onTable?undefined:urlOf(g)};
 }
 async function show(game){
  try{if(window.parent!==window&&window.parent.location.origin===location.origin&&window.parent.alphanoBoardNotices)return window.parent.alphanoBoardNotices.show(game);}catch{}
  if(!window.alphanoDialog)return 'unavailable';
  const g={...game},viewer=Number(g.viewer||document.getElementById('app-script')?.dataset.idpg||0);
  g.viewer=viewer;
  const draw=g.status==='active'&&g.draw_by&&Number(g.draw_by)!==viewer;const version=g.status==='pending'||draw?Number(g.version||1):0,key='alphano:board-overlay:'+viewer+':'+g.id+':'+g.status+(draw?':draw':'')+':'+version;
  const previous=memory.get(key)||storage(key);if(previous)return previous;
  if(inflight.has(key))return inflight.get(key);
  const task=window.alphanoDialog.notice(options(g));inflight.set(key,task);
  try{const result=await task;memory.set(key,result);storage(key,result);return result;}
  finally{inflight.delete(key);}
 }
 window.alphanoBoardNotices={show,options,async fromNotice(n,viewer){
  let p=n.data?.presentation||{};
  if(p.status==='pending'){
   try{const r=await fetch('/api/board-games.php?'+new URLSearchParams({action:'view',id:n.data?.game_id||p.id}),{cache:'no-store'});if(!r.ok)return 'dismiss';const live=await r.json();if(live.status!=='pending')return 'dismiss';p=live;}catch{return 'dismiss';}
  }
  return show({...p,id:Number(n.data?.game_id||p.id||0),status:p.status||'update',game:p.game||(n.title?.includes('Dama')?'dama':'scacchi'),url:n.url,detail:n.detail,viewer});
 }};
})();
