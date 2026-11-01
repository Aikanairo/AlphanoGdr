<aside id="game-sidebar" class="sidebar right-col" aria-label="Personaggio e mondo di gioco">
  <section class="profile-card panel" aria-label="Il tuo personaggio">
    <div class="profile-card-eyebrow">Il tuo personaggio</div>
    <div class="character-top">
      <img id="profile-avatar" src="/assets/avatar.svg" alt="Ritratto del personaggio" class="avatar portrait">
      <div class="prof-info"><div id="profile-name" class="prof-name clickable">Personaggio</div><div id="profile-race" class="profile-card-race"></div><div id="profile-sub" class="prof-sub"></div>  <div class="top-actions profile-card-tools">
    <div class="quicklinks-box">
      <a id="mail-open" class="icon-btn notification-anchor" href="/posta/" data-popup="/posta/,850,700" aria-label="Posta" title="Posta" data-icon="mail"><span id="mail-unread" class="notification-badge" hidden>0</span></a>
    </div>
    <button id="header-return" type="button" class="icon-btn" aria-label="Torna al gioco" title="Torna al gioco" data-icon="back"></button>
    <button type="button" class="icon-btn mobile-privacy-choice" data-header-privacy aria-label="Preferenze di gioco e riservatezza" title="Preferenze di gioco e riservatezza" data-icon="settings"></button>
  </div></div>
    </div>
    <div class="profile-stats">
      <div class="stat money coins-row"><span data-icon="coins"><?=alphano_currency_html()?></span><strong id="profile-money">—</strong></div>
      <div class="stat points health-row"><div class="profile-health-heading"><span data-icon="heart">Salute</span><span id="profile-health-label">—</span></div><div class="bar"><div id="profile-points-bar" class="bar-inner gray"></div></div></div>
    </div>
    <a id="profile-fortune" class="profile-fortune" href="/fortuna/" aria-label="Ruota della sorte" hidden><span data-icon="dice" aria-hidden="true"></span><span class="profile-fortune-copy"><strong data-fortune-label></strong><small>Ruota della sorte · ogni 6 ore</small></span><span class="profile-fortune-action">Apri</span></a>
    <div class="profile-card-footer"><?=alphano_escape(TitoloSito)?> · Personaggio giocante</div>
  </section>
  <section class="weather-box panel"><div class="panel-header"><h2 data-icon="moon">Meteo e luna</h2></div><div id="weather" class="weather-wrap panel-body">Caricamento…</div></section>
  <section id="presenti" class="news-box panel"><div class="panel-header"><h2 data-icon="users"><a id="presenti-open" href="/presenti/">Presenti <span id="presenti-count" class="count-badge">0</span></a></h2><label class="presence-npc-toggle"><span>PNG</span><input id="presenti-show-npcs" type="checkbox" role="switch" aria-label="Mostra i PNG" checked><span class="presence-switch-track" aria-hidden="true"></span></label></div><p id="presenti-summary" class="presence-sidebar-summary"></p><nav class="presenti-nav panel-body" aria-label="Giocatori e PNG per luogo"><ul id="presenti-list"></ul></nav></section>
  <section class="news-box panel"><div class="panel-header"><h2 data-icon="bell"><a href="/notifiche/" data-open-inbox>Cronaca</a> <span id="activity-count" class="notification-badge" hidden>0</span><span id="quest-count" class="notification-badge" hidden>0</span></h2><button id="clear-news" class="quiet-btn" type="button" title="Segna le notifiche come lette" aria-label="Segna le notifiche come lette">✓</button></div><ul id="quest-notifications" class="panel-body" aria-label="Nuove quest" aria-live="polite"></ul><ul id="activity-notifications" class="panel-body" aria-label="Attività e richieste" aria-live="polite"></ul><ul id="news-list" class="panel-body"><li>Caricamento…</li></ul></section>
</aside>
