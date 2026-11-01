<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><title>Alphano · Prova il Master IA</title><script src="/js/demo.js" defer></script></head>
<body class="demo-page">
<main class="demo-shell">
  <header class="demo-header"><a href="/" class="demo-brand">Alphano <span>Play by Chat</span></a><span class="demo-label">Demo del Master IA</span><a href="/" class="action-btn">Torna al sito</a></header>
  <div id="demo-alert" role="status" aria-live="polite"></div>
  <section id="demo-entry" class="demo-entry">
    <div><p class="demo-kicker">Una voce dietro ogni incontro</p><h1>Entra nel mondo di Alphano</h1><p>Incontra personaggi nei luoghi configurati dalla gestione.</p><p>Scegli il tuo nome, un luogo e il personaggio a cui rivolgerti. La prova è privata e non richiede registrazione.</p></div>
    <p class="privacy-ai-disclosure" data-ai-disclosure>Prova riservata ai maggiorenni. PNG e Master IA sono sistemi automatici: non stai parlando con persone reali. <a href="/legal/?document=ai" target="_blank" rel="noopener">IA e temi maturi</a> · <a href="/legal/?document=privacy" target="_blank" rel="noopener">Privacy</a></p><form id="demo-enter" class="demo-enter-form"><h2>Il tuo viandante</h2><label for="demo-name">Nome del personaggio</label><input id="demo-name" name="name" maxlength="40" minlength="2" required autocomplete="nickname" placeholder="Come ti chiami?"><label for="demo-sex">Sesso del personaggio</label><select id="demo-sex" name="sex" required><option value="">Scegli…</option><option>Maschio</option><option>Femmina</option></select><button type="submit">Entra nella demo</button><small>Il personaggio di prova è umano, senza cariche. I dialoghi non cambiano il gioco ufficiale. La prova dura 6 ore; il testo viene eliminato entro 24 ore dalla scadenza.</small></form>
  </section>
  <section id="demo-play" class="demo-play" hidden>
    <div class="demo-toolbar"><label for="demo-room">Luogo di gioco<select id="demo-room"></select></label><span id="demo-visitor"></span><button id="demo-change" type="button">Cambia personaggio</button></div>
    <details class="demo-description"><summary>Ambientazione e personaggi presenti</summary><div id="demo-description"></div><div id="demo-cast"></div></details>
    <div class="demo-scene-heading"><h1 id="demo-room-title"></h1><span>Conversazione privata · Master IA locale</span></div>
    <div id="demo-messages" class="demo-messages" role="log" aria-live="polite" aria-label="Conversazione di prova"></div>
    <p id="demo-typing" class="demo-typing" role="status" aria-live="polite"></p>
    <form id="demo-compose" class="demo-compose"><label for="demo-npc">Rivolgiti a<select id="demo-npc"><option value="0">Nessuno</option></select></label><label for="demo-text">La tua azione<textarea id="demo-text" maxlength="1200" rows="2" required placeholder="Descrivi un gesto o parla al personaggio…"></textarea></label><button type="submit">Invia</button></form>
    <footer class="demo-footer">Seleziona un PNG per ricevere una risposta. I dialoghi ricordano gli ultimi scambi della tua prova con quel personaggio. Nessuna modifica a inventario, statistiche o denaro. <span>Le risposte possono richiedere un minuto o più e talvolta contenere imprecisioni.</span></footer>
  </section>
</main></body></html>
