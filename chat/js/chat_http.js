/* Chat HTTP incrementale. La presentazione e i comandi restano quelli del gioco. */
(() => {
  'use strict';
  const room = window.chatRoom;
  const token = window.chatToken;
  if (!room || !window.myPgId) return;

  const endpoint = `/chat/messages.php?chat=${encodeURIComponent(room)}`;
  let cursor = 0;
  let relationCursor = 0;
  let questVersion = "";
  let initial = true;
  let timer = null;
  let controller = null;
  let inFlight = false;
  let refreshPending = false;
  let stopped = false;
  let failures = 0;
  let lastActivity = Date.now();
  let sending = false;
  let pendingSubmission = null;
  let aiStateTimer = null;
  let aiStateLoading = false;
  let aiStateChecked = 0;
  const aiTimers = new Set();
  const seen = new Set();
  const locations = new Map();
  const typingNodes = new Map();
  function connectionStatus(text){const status=document.getElementById('chat-connection-status');if(status){status.textContent=text;status.hidden=!text;}}

  function renderTyping(jobs = []) {
    const box=document.getElementById('chatbox');if(!box)return;
    const stick=box.scrollHeight-box.scrollTop-box.clientHeight<80;
    const current=new Set(jobs.map(job=>String(job.id)));
    for(const [id,node] of typingNodes) if(!current.has(id)){node.remove();typingNodes.delete(id);}
    for(const job of jobs) {
      const id=String(job.id);let node=typingNodes.get(id);
      if(!node){node=document.createElement('div');node.className='riga npc-typing';node.dataset.aiTyping=id;node.setAttribute('role','status');typingNodes.set(id,node);}
      node.textContent=job.name+' sta scrivendo…';box.append(node);
    }
    if(stick)box.scrollTop=box.scrollHeight;
  }

  function renderQuest(q) {
    let bar=document.getElementById('quest-chat-status');
    if(!q){bar?.remove();window.dispatchEvent(new CustomEvent('alphano:quest',{detail:null}));return;}
    const slot=document.getElementById('quest-header-slot');if(!slot)return;
    if(!bar){bar=document.createElement('div');bar.id='quest-chat-status';bar.className='quest-chat-status';slot.append(bar);}
    const button=document.createElement('button');button.type='button';button.className='quest-header-button';
    const phase=q.paused?'En pausa':q.status==='active'?'Tocca a '+(q.turn||'…'):'Master in elaborazione';
    button.textContent=`Giro ${q.round} · ${phase}`;button.title=(q.remaining||q.next_queue||[]).map(p=>p.name).join(' → ')+(q.progress?.label?' · '+q.progress.label:'');
    button.setAttribute('aria-label','Scontro: '+button.textContent+'. Apri coda e dettagli');
    button.addEventListener('click',()=>window.dispatchEvent(new CustomEvent('alphano:open-panel',{detail:'scontro'})));
    bar.replaceChildren(button);
    const eta=document.createElement('small');eta.className='quest-header-eta';eta.textContent=q.progress?.label||'';bar.append(eta);
    const joinBox=document.getElementById('scene-join-control');joinBox?.replaceChildren();
    if(!q.participant&&q.mode==='auto'&&joinBox){const join=document.createElement('button');join.type='button';join.textContent='Partecipa dal prossimo giro';join.onclick=async()=>{join.disabled=true;try{const r=await fetch('/api/ai-engine.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({action:'join'})});const p=await r.json();if(!r.ok)throw Error(p.error);join.textContent=p.notice;}catch(e){join.textContent=e.message;join.disabled=false;}};joinBox.append(join);}
    window.dispatchEvent(new CustomEvent('alphano:quest',{detail:q}));
  }

  function addMessages(messages) {
    const box = document.getElementById('chatbox');
    const stickToBottom = initial || box.scrollHeight - box.scrollTop - box.clientHeight < 80;
    const fragment = document.createDocumentFragment();
    for (const message of messages) {
      if (seen.has(message.id)) continue;
      seen.add(message.id);
      const line = document.createElement('div');
      line.className = 'riga';
      line.dataset.messageId = message.id;
      line.dataset.pg = message.pg || 0;
      if (message.pg && message.location) locations.set(message.pg, message.location);
      const styled=message.kind==='character'?window.alphanoChatView?.character(message):message.kind==='master'?window.alphanoChatView?.master(message):null;
      if(styled){line.classList.add('has-scene-view');line.append(styled);}else line.innerHTML = message.msg;
      fragment.appendChild(line);
      window.alphanoSceneEffects?.receive(message, initial);
    }
    box.appendChild(fragment);
    const ownLocation = locations.get(window.myPgId);
    for (const line of box.children) {
      const author = Number(line.dataset.pg);
      line.classList.toggle('same-location', !!ownLocation && author !== window.myPgId && locations.get(author) === ownLocation);
    }
    // Limita il DOM anche nelle giocate lunghe. Gli ID vecchi sono già esclusi dal cursore.
    while (box.children.length > 500) {
      seen.delete(Number(box.firstElementChild.dataset.messageId));
      box.firstElementChild.remove();
    }
    if (stickToBottom) box.scrollTop = box.scrollHeight;
  }

  function delay() {
    if (failures) return Math.min(1000 * 2 ** Math.min(failures, 5), 30000);
    if (document.hidden) return 15000;
    return Date.now() - lastActivity > 30000 ? 2000 : 1000;
  }

  function schedule(ms = delay()) {
    clearTimeout(timer);
    if (!stopped && navigator.onLine !== false) timer = setTimeout(poll, ms);
  }

  async function poll() {
    clearTimeout(timer);
    if (stopped || navigator.onLine === false) return;
    if (inFlight) {
      refreshPending = true;
      return;
    }
    inFlight = true;
    let more = false;
    controller = new AbortController();
    const timeout = setTimeout(() => controller?.abort(), 10000);
    try {
      const response = await fetch(`${endpoint}&relationSince=${relationCursor}&since=${cursor}&initial=${initial ? 1 : 0}&questVersion=${encodeURIComponent(questVersion)}`, {
        credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
      });
      if(response.status===410){const data=await response.json();if(data.rental_expired){stopped=true;window.dispatchEvent(new CustomEvent('alphano:rental-expired',{detail:data.rental_expired}));return;}}
      if (response.status === 401 || response.status === 403) {
        stopped = true;
        connectionStatus('Sessione scaduta o accesso non consentito. Accedi nuovamente al gioco. La bozza è conservata.');
        return;
      }
      if (!response.ok) throw new Error(`Chat HTTP ${response.status}`);
      if (response.status === 204) {
        cursor = Math.max(cursor, Number(response.headers.get('X-Chat-Cursor')) || 0);
        relationCursor=Math.max(relationCursor,Number(response.headers.get('X-Relation-Cursor'))||0);
        renderTyping();
      } else {
        const data = await response.json();
        addMessages(data.messages);
        relationCursor=Math.max(relationCursor,Number(data.relations?.cursor)||0);
        window.alphanoRelationEffects?.consume(data.relations?.events||[]);
        renderTyping(data.typing || []);
        renderQuest(data.quest || null);
        for(const actor of data.characters||[])window.alphanoChatView?.update(actor);
        questVersion=data.questVersion||"";
        cursor = Math.max(cursor, data.lastId);
        more = data.more || !!data.relations?.more;
        if (data.messages.length) lastActivity = Date.now();
      }
      initial = false;
      failures = 0;
      connectionStatus('');
    } catch (error) {
      if (!stopped){failures += 1;connectionStatus('Collegamento alla chat interrotto. Riconnessione automatica in corso…');}
    } finally {
      clearTimeout(timeout);
      controller = null;
      inFlight = false;
      const immediate = more || refreshPending;
      refreshPending = false;
      schedule(immediate && !failures ? 0 : delay());
    }
  }

  // I pannelli di sessione salvano già sul server: qui si richiede solo l'aggiornamento.
  // Nessun echo HTML locale, quindi nessun messaggio doppio al prossimo controllo.
  window.chatSendSystem = () => {
    lastActivity = Date.now();
    return poll();
  };

  function aiStatus(text) {
    const status = document.getElementById('ai-chat-status');
    if (status) status.textContent = text;
  }

  async function chatNotice(error,title='Avviso della chat') {
    const q=error.quest;
    const options={title,message:error.message,detail:'Il testo che hai scritto è stato conservato.'};
    if(q){
      options.eyebrow='Alphano · Regia della quest';
      if(q.progress?.label)options.detail=q.progress.label+' Il testo che hai scritto è stato conservato.';
      options.title=q.paused?'Regia sospesa':q.status==='review'?'In attesa del verdetto':q.status==='resolving'?'Il Master sta valutando il giro':q.status==='attention'?'Intervento del Master': 'Attendi il tuo turno';
      options.message=q.paused?'Il Master ha sospeso la regia di questa scena.':q.status==='review'?`Il giro ${q.round} è terminato. Si attende la verifica degli esiti prima di una nuova azione.`:q.status==='resolving'?`Il giro ${q.round} è terminato. Il Master sta preparando il verdetto.`:q.status==='attention'?'La regia sta recuperando questo giro. Le azioni già inviate sono conservate.':`Adesso tocca a ${q.turn||'un altro partecipante'}. Potrai inviare la tua azione quando arriverà il tuo turno.`;
      const current=q.status==='active'&&!q.paused;
      options.itemsTitle=current?'Coda del giro':'Ordine del prossimo giro';
      options.items=((current?q.remaining:q.next_queue)||[]).map((member,index)=>({label:member.name,meta:(member.kind==='pg'?'Giocatore':'PNG')+(current&&index===0?' · Turno attuale':'')}));
    }
    if(window.alphanoDialog)return window.alphanoDialog.notice(options);
    aiStatus(options.message); // Il testo resta accessibile anche se un asset non è stato caricato.
  }

  async function loadAiState() {
    if (stopped || aiStateLoading) return;
    clearTimeout(aiStateTimer);
    aiStateLoading = true;
    aiStateChecked = Date.now();
    try {
      const response = await fetch('/api/ai.php?action=public&chat=' + encodeURIComponent(room), {cache:'no-store', signal:AbortSignal.timeout(5000)});
      if (!response.ok) return;
      const state = await response.json();
      const select = document.getElementById('ai-chat-npc');
      if(!select)return;
      const selected = select.value;
      select.replaceChildren(new Option('Automatico', '0'),new Option('Nessun PNG', '-1'),...state.npcs.map(npc => new Option(npc.name, npc.id)));
      if (selected === '-1' || state.npcs.some(npc => String(npc.id) === selected)) select.value = selected;
      else select.value=state.private&&state.npcs.length===1?String(state.npcs[0].id):'0';
      select.disabled=!state.enabled || !state.npcs.length;
      window.dispatchEvent(new Event('alphano:npc-options'));
      renderTyping(state.typing || []);
    } catch (_) { /* La chat continua anche senza il servizio IA. */ }
    finally {
      aiStateLoading = false;
      if (!stopped) aiStateTimer = setTimeout(loadAiState, 60000);
    }
  }

  function watchAiJob(job, started = Date.now()) {
    if(job.name && !typingNodes.has(String(job.id))) {
      renderTyping([...typingNodes.keys()].map(id=>({id,name:typingNodes.get(id).textContent.replace(/ sta scrivendo…$/,'')})).concat(job));
    }
    const timer = setTimeout(async () => {
      aiTimers.delete(timer);
      if (stopped) return;
      try {
        const response = await fetch('/api/ai.php?action=job&id=' + encodeURIComponent(job.id), {cache:'no-store', signal:AbortSignal.timeout(5000)});
        if (!response.ok) throw new Error('Stato non disponibile');
        const data = await response.json();
        if (['queued','running'].includes(data.job.status) && Date.now() - started < 850000) { watchAiJob(job, started); return; }
        aiStatus(data.job.status === 'done' ? '' : (data.job.error || 'Il personaggio non ha potuto rispondere. Puoi riprovare.'));
        poll();
      } catch (_) {
        if (Date.now() - started < 850000) watchAiJob(job, started);
        else aiStatus('Risposta ancora in attesa. La chat resta disponibile.');
      }
    }, document.hidden ? 15000 : 2500);
    aiTimers.add(timer);
  }

  async function submit(text, mood = 0, location = '') {
    const submittedDraft=text;
    const npcId = Number(document.getElementById('ai-chat-npc')?.value || 0);
    const aiRequested = npcId > 0;
    const abilityId=window.alphanoRpgChat?.selectedId()||0;const item=window.alphanoItemChat?.choice()||{instance:0};
    const work=window.alphanoWorkChat?.choice()||{id:0};
    if(abilityId&&!/^(?:\+|\/\/|[$&#])/.test(text.trim()))text='+'+text.trim();
    const content = JSON.stringify([text, mood, location, aiRequested, npcId,abilityId,item,work]);
    // Un nuovo tentativo dello stesso invio mantiene l'ID, anche se la risposta è andata persa.
    if (!pendingSubmission || pendingSubmission.content !== content) {
      pendingSubmission = { content, id: crypto.randomUUID() };
    }
      const response = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ msg: text, umore: mood, location, token, requestId: pendingSubmission.id, ai_request: aiRequested, ai_npc_id: npcId,ai_auto:npcId>=0,rpg_ability_id:abilityId,rpg_item_instance:item.instance,rpg_item_operation:item.operation,rpg_item_provider:item.provider,work_id:work.id,work_quote:work.quote||'',work_confirmed:!!work.id }),
        signal: AbortSignal.timeout(15000),
      });
      const data = await response.json();
      if (!response.ok) {if(data.rental_expired)window.dispatchEvent(new CustomEvent('alphano:rental-expired',{detail:data.rental_expired}));const error=new Error((data.error || 'Invio non riuscito. Riprova.')+(data.reference?' Codice assistenza: '+data.reference:''));error.quest=data.notice?.quest;error.serverRejected=true;throw error;}
      if (typeof data.location === 'string') document.querySelector('input[name="location"]').value = data.location;
      if (data.ai_job) watchAiJob(data.ai_job);
      if (data.ai_notice) aiStatus(data.ai_notice);
    pendingSubmission = null;
    if(abilityId)window.alphanoRpgChat.accepted();if(item.instance)window.alphanoItemChat.accepted();
    window.alphanoWorkChat?.accepted(data.work);
    window.dispatchEvent(new CustomEvent('alphano:message-sent',{detail:{text:submittedDraft}}));
    lastActivity = Date.now();
    poll();
  }

  let recording = false;
  async function loadRecordingState() {
    const response = await fetch(`/chat/recordings.php?chat=${encodeURIComponent(room)}`, { cache: 'no-store' });
    if (!response.ok) return;
    recording = (await response.json()).recording;
    const recordButton=document.getElementById('recordBtn');
    (recordButton.querySelector('[data-record-label]')||recordButton).textContent=recording?'Salva giocata':'Registra giocata';
    recordButton.setAttribute('aria-pressed',String(recording));
  }

  async function toggleRecording() {
    const button = document.getElementById('recordBtn');
    button.disabled = true;
    try {
      const response = await fetch(`/chat/recordings.php?chat=${encodeURIComponent(room)}`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: recording ? 'save' : 'start', token }),
      });
      if (!response.ok) throw new Error((await response.json()).error || 'Registrazione non riuscita.');
      if (recording) {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(await response.blob());
        link.download = 'giocata.alphano';
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 60000);
      }
      await loadRecordingState();
    } catch (error) { await chatNotice(error,'Registrazione della giocata'); }
    finally { button.disabled = false; }
  }

  async function sendMsg() {
    const input = document.getElementById('comodo');
    let text = input.value.trim();
    const draft=text;
    if (sending || !text) return;
    if(window.alphanoWorkChat&&!await window.alphanoWorkChat.beforeSend(text))return;
    if(sending)return;
    sending = true;
    const button = document.getElementById('sendBtn');
    button.disabled = true;
    try {
      await submit(text,
        Number(document.querySelector('input[name="umore"]').value),
        document.querySelector('input[name="location"]').value);
      // Non cancellare quello che l'utente ha scritto mentre aspettava la risposta.
      if (input.value.trim() === draft) {input.value = '';input.style.height='auto';input.dispatchEvent(new Event('input'));}
    } catch (error) {
      // Il testo resta nel campo ed è possibile riprovare senza duplicare l'azione.
      if(error.serverRejected)window.alphanoWorkChat?.failed();
      if(error.name==='TimeoutError'||error.name==='TypeError')error.message='Connessione interrotta. Premi Invia per riprovare.';
      await chatNotice(error);
      if(input.isConnected)input.focus({preventScroll:true});
    } finally {
      sending = false;
      button.disabled = false;
    }
  }

  const initialize = () => {
    const box = document.getElementById('chatbox');
    if (!box) return; // Le pagine-mappa condividono il template ma non hanno una chat.
    box.replaceChildren();
    poll();
    window.addEventListener('alphano:refresh-chat',()=>poll());
    document.getElementById('recordBtn').addEventListener('click', toggleRecording);
    document.getElementById('moodSelect').addEventListener('change', event => {
      document.querySelector('input[name="umore"]').value = event.target.value;
    });
    loadRecordingState().catch(() => {});
    const composer=document.getElementById('comodo'),messageType=document.getElementById('messageType');
    const syncType=()=>{const text=composer.value.trimStart();const kind=text.startsWith('+')?'action':text.startsWith('//')?'ooc':text.startsWith('#')?'master':'speech';messageType.value=[...messageType.options].some(option=>option.value===kind)?kind:'speech';};
    composer.addEventListener('input',syncType);
    messageType.addEventListener('change',()=>{const prefix={action:'+ ',ooc:'// ',master:'# ',speech:''}[messageType.value]||'';composer.value=prefix+composer.value.replace(/^\s*(?:\+\s*|\/\/\s*|#\s*)/,'');composer.dispatchEvent(new Event('input'));composer.focus();composer.setSelectionRange(composer.value.length,composer.value.length);});
    syncType();
    document.getElementById('sendBtn').addEventListener('click', () => sendMsg());
    window.addEventListener('alphano:work-send',()=>sendMsg());
    document.addEventListener('click', event => {
      const link=event.target.closest('a[data-npc-profile]');
      if(link && !event.ctrlKey && !event.metaKey && !event.shiftKey){event.preventDefault();window.alphanoOpenView(link.getAttribute('href'),'Profilo');}
    });
    loadAiState();
    document.getElementById('comodo').addEventListener('keydown', event => {
      lastActivity = Date.now();
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && !window.matchMedia('(max-width:900px), (max-width:1100px) and (max-height:500px)').matches) {
        event.preventDefault();
        sendMsg();
      }
    });
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        lastActivity = Date.now();
        poll();
        if (Date.now() - aiStateChecked > 5000) loadAiState();
      } else schedule();
    });
    window.addEventListener('online', () => { failures = 0; poll(); });
    window.addEventListener('focus', () => {
      if (Date.now() - aiStateChecked > 5000) loadAiState();
    });
    window.addEventListener('offline', () => { clearTimeout(timer); controller?.abort(); });
    window.addEventListener('pagehide', () => {
      stopped = true;
      clearTimeout(timer);
      clearTimeout(aiStateTimer);
      aiTimers.forEach(clearTimeout);
      aiTimers.clear();
      controller?.abort();
    });
    window.addEventListener('pageshow', event => {
      if (event.persisted) { stopped = false; poll(); loadAiState(); }
    });
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize,{once:true});else initialize();
})();
