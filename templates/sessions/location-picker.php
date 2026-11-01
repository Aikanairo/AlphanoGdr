<!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Regia delle sessioni · Alphano</title><script src="/js/session-manager.js?v=<?=filemtime(dirname(__DIR__,2).'/js/session-manager.js')?>" defer></script></head>
<body><main class="session-control session-location-picker" data-session-location-picker>
  <header class="ui-page-heading session-control-heading" data-page-heading><div><p class="ui-eyebrow">Alphano · Il tavolo del Master</p><h1>Regia delle sessioni</h1><p>Scegli dove si svolgerà la giocata.</p></div></header>
  <div class="session-control-content">
    <section class="session-control-panel session-location-intro"><span class="session-control-command-icon" data-icon="map" aria-hidden="true"></span><div><h2>Entra in un luogo per dirigere</h2><p>Apri la chat in cui vuoi giocare, poi premi <strong>Master</strong> nella testata. Da lì puoi aprire una sessione, guidarla e intervenire sui personaggi presenti.</p><?php if(!empty($u['admin'])): ?><p class="session-location-admin-note">Come amministratore puoi anche consultare la regia di un altro luogo. Per impartire comandi e modificare i personaggi devi prima entrare nella sua chat.</p><?php endif; ?></div></section>
    <section aria-label="Luoghi di gioco" class="session-location-section">
      <div class="session-location-search"><label for="session-location-search">Cerca un luogo<input type="search" id="session-location-search" placeholder="Nome del luogo o della sessione…" autocomplete="off"></label><p id="session-location-count" role="status"><?=count($locations)?> luoghi disponibili</p></div>
      <div class="session-location-grid">
      <?php foreach($locations as $location): $open=trim((string)($location['session_name']??''))!==''; ?>
        <article class="session-location-card" data-session-location data-search="<?=$h(($location['title']??'').' '.($location['session_name']??'').' '.($location['master_name']??''))?>">
          <div class="session-location-card-top"><span class="session-control-command-icon" data-icon="map" aria-hidden="true"></span><span class="session-control-badge" data-mode="<?=$open?'human':'free'?>"><?=$open?'Sessione aperta':'Gioco libero'?></span></div>
          <h2><?=$h($location['title'])?></h2>
          <?php if($open): ?><p class="session-location-session"><?=$h($location['session_name'])?></p><p class="session-location-master">Conduce <?=$h(($location['master_name']??'')?:'il Master della scena')?></p><?php else: ?><p class="session-location-session">Puoi iniziare una nuova giocata.</p><p class="session-location-master">Nessuna sessione aperta</p><?php endif; ?>
          <nav class="session-location-actions" aria-label="Azioni per <?=$h($location['title'])?>"><a class="action-btn session-control-primary" href="/chat/index.php?chat=<?=rawurlencode($location['chat_key'])?>" data-icon="messages">Entra nel luogo</a><?php if(!empty($u['admin'])): ?><a class="action-btn" href="/sessione/sessione.php?chat=<?=rawurlencode($location['chat_key'])?>" data-icon="eye">Apri console</a><?php endif; ?></nav>
        </article>
      <?php endforeach; ?>
      </div><p class="session-control-empty" id="session-location-empty" <?=count($locations)?'hidden':''?>>Nessun luogo corrisponde alla ricerca. Prova con un nome diverso.</p>
    </section>
  </div>
</main></body></html>
