<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Cronaca e notifiche</title><script type="module" src="/js/commerce-thread.js"></script><script src="/js/inbox.js" defer></script></head><body>
<main class="notification-inbox">
  <?php require dirname(__DIR__).'/partials/push-controls.php'; ?>
  <div class="ui-toolbar inbox-toolbar">
    <label>Sezione<select id="inbox-group"><option value="">Tutte le sezioni</option><option value="gioco">Personaggio e scambi</option><option value="organizzazioni">Organizzazioni</option><option value="corporazioni">Corporazioni</option><option value="citta">Città</option><option value="accademia">Accademia</option><option value="bacheche">Bacheche</option><option value="master">Master</option><option value="gestione">Gestione</option></select></label>
    <label>Registro<select id="inbox-view"><option value="unread">Da leggere</option><option value="history">Storico delle operazioni</option></select></label>
    <button id="inbox-reset" type="button" hidden>Togli filtro specifico</button>
    <button id="inbox-refresh" type="button" data-icon="scroll">Aggiorna</button>
  </div>
  <p id="inbox-status" class="inbox-status" role="status" aria-live="polite">Caricamento delle notifiche…</p>
  <section id="inbox-message" class="inbox-message" aria-label="Notifica aperta" hidden></section>
  <div class="ui-table-scroll"><table class="ui-data-table inbox-table"><thead><tr><th scope="col">Notifica</th><th scope="col">Sezione</th><th scope="col">Data</th><th scope="col">Azioni</th></tr></thead><tbody id="inbox-rows"></tbody></table></div>
  <nav class="ui-list-footer inbox-pagination" aria-label="Pagine delle notifiche"><button id="inbox-previous" type="button">Precedenti</button><span id="inbox-page"></span><button id="inbox-next" type="button">Successive</button></nav>
  <p class="inbox-footnote">Lo storico conserva le operazioni concluse. Le richieste ancora aperte e i nuovi messaggi compaiono in «Da leggere». Le comunicazioni riservate seguono i tuoi incarichi attuali.</p>
</main></body></html>
