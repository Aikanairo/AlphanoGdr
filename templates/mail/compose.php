<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $oldid ? "Rispondi a ".h($pgName) : "Nuova Missiva" ?> – <?= TitoloSito ?></title>


  <script src="/js/global.js"></script>
  <script src="/js/gml.js"></script>
  <script>
    function init() {
      document.frm.oggetto.focus();
    }
    window.onload = init;
  </script>
</head>
<body class="mail-workspace">
  <div class="header"><?= $oldid ? "Risposta a ".h($pgName) : "Componi Missiva" ?></div>
  <div class="mail-compose">
    <?php if($composePeople): ?><?=alphano_mail_people_html(alphano_mail_person($idpg,$composePeople),alphano_mail_person((int)$destRows[0]['id'],$composePeople))?><?php endif; ?>
    <form name="frm" method="POST" class="ui-form mail-compose-form" data-ui-fields="true">
      <input type="hidden" name="send"  value="1">
      <input type="hidden" name="oldid" value="<?= h($oldid) ?>">
      <?php if ($destId): ?>
        <input type="hidden" name="idpgDest" value="<?= h($destId) ?>">
      <?php endif ?>
      <label class="mail-recipient">Destinatario:
        <?php if ($pgName !== ''): ?>
          <input type="hidden" name="pgName" value="<?= h($pgName) ?>">
          <span><?= h($pgName) ?></span>
        <?php else: ?>
          <input type="text" name="pgName" value="" placeholder="Inserisci nome destinatario">
        <?php endif; ?>
      </label>
      <label class="mail-subject">Oggetto:
        <input type="text" name="oggetto"
               value="<?= h($oldoggetto ? "Re: ".$oldoggetto : "") ?>" required maxlength="255">
      </label>
      <div class="mail-editor"><label for="mail-message">Messaggio</label>
        <div class="gml-toolbar" role="toolbar" aria-label="Formattazione del messaggio"><button type="button" onclick="GMLB(document.frm.messaggio)">Grassetto</button><button type="button" onclick="GMLC(document.frm.messaggio)">Corsivo</button><button type="button" onclick="GMLQ(document.frm.messaggio)">Citazione</button><button type="button" onclick="GMLT(document.frm.messaggio)">Titolo</button><button type="button" onclick="GMLP(document.frm.messaggio)">Paragrafo</button><button type="button" onclick="GMLCE(document.frm.messaggio)">Centra</button><button type="button" onclick="GMLI(document.frm.messaggio)">Immagine</button><button type="button" onclick="GMLLNK(document.frm.messaggio)">Collegamento</button><button type="button" onclick="ApriAnteprima(document.frm.messaggio)">Anteprima</button><details class="gml-more"><summary>Altri strumenti</summary><div><button type="button" onclick="GMLTC(document.frm.messaggio)">Colore testo</button><button type="button" onclick="GMLBGC(document.frm.messaggio)">Sfondo</button><button type="button" onclick="GMLBGI(document.frm.messaggio)">Immagine sfondo</button><button type="button" onclick="GMLTBL(document.frm.messaggio)">Tabella</button><button type="button" onclick="GMLRG(document.frm.messaggio)">Riga</button><button type="button" onclick="GMLCL(document.frm.messaggio)">Colonna</button></div></details></div>
        <textarea id="mail-message" name="messaggio" rows="8" required></textarea>
      </div>
      <?php if ($oldmessaggio !== ''): ?>
      <section class="mail-history-panel" aria-label="Conversazione precedente">
        <header><div><small>IL FILO DELLA CORRISPONDENZA</small><h2>Conversazione precedente</h2></div></header>
        <p>Scrivi la tua risposta sopra. Qui puoi rileggere la missiva ricevuta, senza modificarla.</p>
        <label class="mail-check"><input type="checkbox" name="include_previous" value="1" checked> Allega questa conversazione alla risposta</label>
        <details><summary>Leggi la conversazione · <?=h($oldoggetto)?></summary><div class="mail-parchment mail-history-content"><?=alphano_mail_render($oldmessaggio)?></div></details>
      </section>
      <?php endif; ?>
      <section class="mail-signature-panel"><label class="mail-check"><input type="checkbox" name="firma" checked> Inserisci la mia firma</label><div class="mail-parchment"><?=alphano_mail_signature($idpg)?></div></section>
      <p class="mail-saved-note">Una copia della risposta resta nella tua conversazione.</p>
      <div class="ui-row-actions mail-compose-actions">
        <button type="submit">Invia missiva</button>
        <button type="reset">Reset</button>
        <a class="action-btn" data-icon="back" href="/posta/">Torna alla posta</a>
      </div>
    </form>
  </div>
</body>
</html>
