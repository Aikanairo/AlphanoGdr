<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Le tue conversazioni – <?= TitoloSito ?></title>


  <script src="/js/global.js"></script>

</head>
<body class="mail-workspace">
  <!-- NAV BAR -->
  <nav class="tabs mail-tabs" role="tablist" aria-label="Caselle postali">
    <button type="button" id="hdr0" class="hdr" role="tab" aria-controls="Lista" onclick="Carica(0,'data',0)">Conversazioni</button>
    <button type="button" id="hdr1" class="hdr" role="tab" aria-controls="Lista" onclick="Carica(1,'data',0)">Baule (<?= count($mail[1]) ?>)</button>
    <button type="button" id="hdr2" class="hdr" role="tab" aria-controls="Lista" onclick="Carica(2,'data',0)">Inviata (<?= count($mail[2]) ?>)</button>
  </nav>

  <form id="mail-actions-form" method="POST">
    <input type="hidden" name="azione" value="">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="baule" value="">
  </form>

  <section class="mail-filters mail-contact-filters" aria-label="Filtri della posta">
    <label>Cerca una conversazione<input type="search" id="mail-search" placeholder="Personaggio, oggetto o messaggio"></label>
    <label>Lettura<select id="mail-kind"><option value="all">Tutte</option><option value="unread">Da leggere</option></select></label>
    <details class="mail-filter-more"><summary>Filtri e avvisi</summary><div>
      <fieldset class="mail-type-checks"><legend>Mostra</legend><label><input type="checkbox" data-mail-type="personal" checked>Conversazioni con personaggi</label><label><input type="checkbox" data-mail-type="commerce" checked>Notifiche commerciali</label><label><input type="checkbox" data-mail-type="system" checked>Altri avvisi di gioco</label></fieldset>
      <label>Stato commerciale<select id="mail-state"><option value="all">Tutti gli stati</option><option value="action">Devi rispondere / prendere in carico</option><option value="waiting">In attesa</option><option value="delivery">Da consegnare</option><option value="completed">Conclusi</option><option value="cancelled">Annullati</option></select></label><button type="button" id="mail-filter-reset">Azzera filtri</button>
    </div></details><span id="mail-filter-count" role="status"></span>
  </section>
  <details class="mail-push-panel"><summary>Avvisi e notifiche della posta</summary><?php require dirname(__DIR__).'/partials/push-controls.php'; ?></details>
  <div id="Lista"></div>
  <div id="Opzioni"></div>

  <script type="application/json" id="mail-data" data-viewer="<?=(int)$pgid?>"><?=json_encode($mailView,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
  <script src="/js/posta.js?v=20261030-contacts-v2" defer></script>
</body></html>
