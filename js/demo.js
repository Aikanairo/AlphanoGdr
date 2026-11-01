/* Public trial: no normal-game API, account or websocket. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  let visitor = null, rooms = [], current = '', busy = false, sending = false, choosing = false, signature = '', timer;
  const drafts = new Map();
  async function api(data, chat = '') {
    const response = await fetch('/api/demo.php' + (data ? '' : '?chat=' + encodeURIComponent(chat) + (chat ? '&poll=1' : '')), data ? {
      method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(data)
    } : {cache: 'no-store'});
    const out = await response.json();
    if (!response.ok) throw new Error(out.error || 'La prova è temporaneamente occupata.');
    return out;
  }
  function notice(text = '') { $('demo-alert').textContent = text; }
  function element(tag, text, cls) { const node = document.createElement(tag); if (text) node.textContent = text; if (cls) node.className = cls; return node; }
  function options(select, rows, value) {
    select.replaceChildren(...rows.map(([id, text]) => { const o = element('option', text); o.value = id; return o; }));
    if (value && rows.some(r => String(r[0]) === value)) select.value = value;
  }
  function narrative(text, sex) {
    const box = element('div', '', 'demo-narrative ' + (sex === 'Femmina' ? 'demo-female' : 'demo-male'));
    // Only text nodes: model output and player text can never introduce HTML.
    const chunks = text.split(/(«[^»]*»|“[^”]*”|"[^"\n]*")/gu);
    chunks.forEach((part, i) => box.append(element(i % 2 ? 'span' : 'strong', part)));
    return box;
  }
  function roomDescription() {
    const room = rooms.find(r => r.chat_key === current);
    if (!room) return;
    $('demo-room-title').textContent = room.title;
    $('demo-description').textContent = room.public_setting || 'Un luogo del mondo di Alphano.';
    $('demo-cast').replaceChildren(...room.npcs.map(n => {
      const row = element('section', '', 'demo-cast-member');
      row.append(element('h3', n.name), element('p', n.occupation), element('p', n.appearance));
      return row;
    }));
    options($('demo-npc'), [['0', 'Nessuno'], ...room.npcs.map(n => [n.id, n.name])], '0');
    $('demo-messages').replaceChildren(); signature = ''; busy = false; $('demo-typing').textContent = '';
  }
  function render(turns) {
    const mark = JSON.stringify(turns);
    busy = turns.some(t => ['queued', 'running'].includes(t.status));
    const pending = turns.find(t => ['queued', 'running'].includes(t.status));
    $('demo-typing').textContent = pending ? pending.npc_name + ' sta scrivendo…' + (pending.status === 'queued' ? ' (in attesa del Master)' : '') : '';
    $('demo-compose').querySelector('button').disabled = busy || sending;
    if (mark === signature) return;
    signature = mark;
    const log = $('demo-messages'), nearBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 100;
    const nodes = [];
    if (!turns.length) nodes.push(element('p', 'Il luogo attende la tua prima azione. Scegli un PNG nel menu “Rivolgiti a” per iniziare a parlare.', 'demo-empty'));
    for (const t of turns) {
      const time = new Date(t.time * 1000).toLocaleTimeString('it-IT', {hour: '2-digit', minute: '2-digit'});
      const row = element('article', '', 'demo-turn');
      row.append(element('div', time + ' · ' + (visitor.sex === 'Femmina' ? '♀' : '♂') + ' ' + visitor.name + (t.npc_id ? ' → ' + t.npc_name : ''), 'demo-speaker'));
      row.append(element('p', t.text)); nodes.push(row);
      if (t.reply) {
        const npc = rooms.find(r => r.chat_key === current)?.npcs.find(n => n.id === t.npc_id);
        const reply = element('article', '', 'demo-turn');
        reply.append(element('div', time + ' · ' + (npc?.sex === 'Femmina' ? '♀' : '♂') + ' ' + t.npc_name + ' · PNG', 'demo-speaker ' + (npc?.sex === 'Femmina' ? 'demo-female' : 'demo-male')));
        reply.append(narrative(t.reply, npc?.sex)); nodes.push(reply);
      } else if (['failed', 'cancelled'].includes(t.status)) nodes.push(element('p', 'La risposta non è arrivata. Puoi riprovare con un nuovo messaggio.', 'demo-error'));
    }
    log.replaceChildren(...nodes);
    if (nearBottom || turns.length < 2) log.scrollTop = log.scrollHeight;
  }
  async function refresh(initial = false) {
    const requested = current;
    try {
      const out = await api(null, requested);
      if (requested !== current) return;
      visitor = out.visitor; rooms = out.rooms || rooms;
      if (choosing) return;
      $('demo-entry').hidden = !!visitor; $('demo-play').hidden = !visitor;
      if (!visitor) { busy = false; return; }
      $('demo-visitor').textContent = visitor.name + ' · ' + visitor.sex;
      if (initial || !rooms.some(r => r.chat_key === current)) {
        current = rooms[0]?.chat_key || '';
        options($('demo-room'), rooms.map(r => [r.chat_key, r.title]), current);
        roomDescription();
        if (!current) { notice('Nessun luogo disponibile al momento.'); return; }
        return refresh();
      }
      render(out.turns);
    } catch (e) { notice(e.message); }
    finally { clearTimeout(timer); if (!choosing) timer = setTimeout(() => refresh(), busy ? 2000 : 10000); }
  }
  $('demo-enter').addEventListener('submit', async e => {
    e.preventDefault(); const button = e.currentTarget.querySelector('button'); button.disabled = true; notice();
    try { await api({action: 'enter', name: $('demo-name').value.trim(), sex: $('demo-sex').value}); choosing = false; current = ''; signature = ''; drafts.clear(); await refresh(true); }
    catch (error) { notice(error.message); } finally { button.disabled = false; }
  });
  $('demo-change').addEventListener('click', () => { choosing = true; $('demo-play').hidden = true; $('demo-entry').hidden = false; clearTimeout(timer); $('demo-name').focus(); });
  $('demo-room').addEventListener('change', () => { current = $('demo-room').value; roomDescription(); notice(); refresh(); });
  $('demo-compose').addEventListener('submit', async e => {
    e.preventDefault(); if (busy || sending) return;
    const text = $('demo-text').value.trim(); if (!text) return;
    const room = current, npc = Number($('demo-npc').value), draft = JSON.stringify([room, npc, text]);
    if (!drafts.has(draft)) drafts.set(draft, crypto.randomUUID());
    sending = true; e.currentTarget.querySelector('button').disabled = true; notice();
    try { await api({action: 'send', chat: room, npc, text, key: drafts.get(draft)}); drafts.delete(draft); if (current === room) $('demo-text').value = ''; await refresh(); }
    catch (error) { notice(error.message); }
    finally { sending = false; $('demo-compose').querySelector('button').disabled = busy; }
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !choosing) refresh(); });
  refresh(true);
})();
