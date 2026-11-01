<?php /** Existing actions use the authoritative equipment controller. */ ?>
        <div class="equipment-detail">
          <img src="<?=htmlspecialchars(alphano_equipment_image((string)$o['immagine'],strtolower((string)$o['tipoG'])),ENT_QUOTES)?>" alt="Ritratto dell’oggetto">
          <div><?=nl2br(htmlspecialchars($o['descrizione'],ENT_QUOTES))?></div>
        </div>
        <ul class="equipment-properties">
          <li><strong>Tipo:</strong> <?=htmlspecialchars($o['tipo'],ENT_QUOTES)?></li>
          <li><strong>Taglia:</strong> <?=htmlspecialchars(getSize((int)$o['taglia']),ENT_QUOTES)?></li>
          <?php if ($o['nome']): ?>
            <li><strong>Nome:</strong> <?=htmlspecialchars($o['nome'],ENT_QUOTES)?></li>
          <?php endif; ?>
          <?php if ($o['peso']): ?>
            <li><strong>Peso:</strong> <?=round($o['peso'],2)?> kg</li>
          <?php endif; ?>
          <?php if ($o['mani']>0 && in_array(strtolower($o['tipoG']),['arma','armasx','armadx'],true)): ?>
            <li><strong>Mani richieste:</strong> <?=$o['mani']?></li>
          <?php endif; ?>
          <?php if ($o['sacro']): ?>
            <li><strong>Proprietà:</strong> Oggetto sacro</li>
          <?php endif; ?>
          <?php if ($o['restore']>0): ?>
            <li><strong>Ripristina PF:</strong> <?=$o['restore']?></li>
          <?php endif; ?>
          <?php if ($o['idcreatore']): ?>
            <li><strong>Creatore:</strong>
              <a data-equipment-profile href="/profili/?Id=<?=(int)$o['idcreatore']?>">
                <?=htmlspecialchars($equipmentCreatorName,ENT_QUOTES)?>
              </a>
            </li>
          <?php endif; ?>
          <?php if ($o['lavorazione']): ?>
            <li><strong>Lavorazione:</strong> <?=htmlspecialchars($o['lavorazione'],ENT_QUOTES)?></li>
          <?php endif; ?>
          <li><strong>Qualità:</strong> <?=htmlspecialchars(getQuality((int)$o['durata']),ENT_QUOTES)?></li>
          <li><strong>Usi:</strong> <?=$o['consumo']?> / <?=$o['durata']?></li>
        </ul>

        <div class="equipment-actions">
          <?php
          // Equip / Unequip
          if ($o['slot']==='' && ($isSame||$admin||$fatepower)) {
              if ($can) {
          ?>
            <form method="POST">
              <input type="hidden" name="action" value="equipaggia">
              <input type="hidden" name="id"     value="<?=$detail_id?>">
              <input type="hidden" name="pgid"   value="<?=$pgid?>">
              <input type="hidden" name="slot"   value="<?=strtolower($o['tipoG'])?>">
              <?php if ($can>1): ?>
                <input type="hidden" name="side" value="<?=$can===2?'dx':'sx'?>">
              <?php endif; ?>
              <input type="hidden" name="detail_id" value="<?=$detail_id?>">
              <button>Equipaggia</button>
            </form>
          <?php
              }
          } elseif ($o['slot']!=='' && ($isSame||$admin||$fatepower)) {
          ?>
            <form method="POST">
              <input type="hidden" name="action" value="disequipaggia">
              <input type="hidden" name="id"     value="<?=$detail_id?>">
              <input type="hidden" name="pgid"   value="<?=$pgid?>">
              <input type="hidden" name="detail_id" value="<?=$detail_id?>">
              <button>Disequipaggia</button>
            </form>
          <?php } ?>

          <?php if ($o['restore']>0 && $isSame): ?>
            <form method="POST" data-equipment-confirm="Vuoi usare questo oggetto?">
              <input type="hidden" name="action" value="usa">
              <input type="hidden" name="id"     value="<?=$detail_id?>">
              <input type="hidden" name="pgid"   value="<?=$pgid?>">
              <input type="hidden" name="detail_id" value="<?=$detail_id?>">
              <button>Usa</button>
            </form>
          <?php endif; ?>

          <?php if (($isSame||$admin||$fatepower) && !$lock): ?>
            <form method="POST" data-equipment-confirm="Vuoi inviare questo oggetto?">
              <input type="hidden" name="action" value="send">
              <input type="hidden" name="id"     value="<?=$detail_id?>">
              <input type="hidden" name="pgid"   value="<?=$pgid?>">
              <input type="hidden" name="detail_id" value="<?=$detail_id?>">
              <input type="text" name="to" placeholder="Nome destinatario">
              <button>Dai a…</button>
            </form>
            <form method="POST" data-equipment-confirm="Vuoi cancellare questo oggetto?">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id"     value="<?=$detail_id?>">
              <input type="hidden" name="pgid"   value="<?=$pgid?>">
              <input type="hidden" name="detail_id" value="<?=$detail_id?>">
              <button class="danger">Cancella</button>
            </form>
          <?php endif; ?>

          <?php if ($isSame && !$o['nome'] && $o['tipoG']==='Animale'): ?>
            <form method="POST" data-equipment-confirm="Vuoi assegnare un nome?">
              <input type="hidden" name="action" value="setnome">
              <input type="hidden" name="id"     value="<?=$detail_id?>">
              <input type="hidden" name="pgid"   value="<?=$pgid?>">
              <input type="hidden" name="detail_id" value="<?=$detail_id?>">
              <input type="text" name="nome" placeholder="Nome animale">
              <button>Assegna nome</button>
            </form>
          <?php endif; ?>
<?php if ($corp && !$isSame && $canPurchase && $maxQty > 0): ?>
  <form class="ui-form" method="POST">
    <input type="hidden" name="action" value="acquista">
    <input type="hidden" name="id" value="<?= $detail_id ?>">
    <input type="hidden" name="detail_id" value="<?= $detail_id ?>">
    <input type="hidden" name="_request_key" value="<?= bin2hex(random_bytes(24)) ?>">
    <label>Quantità da acquistare (disponibili: <?= $maxQty ?>)
      <input type="number" name="qta" min="1" max="<?= min(10000,$maxQty) ?>" step="1" value="1" required>
    </label>
    <p>Il venditore proporrà il prezzo totale. Puoi negoziarlo in Acquisti dai giocatori della tua corporazione.</p>
    <button type="submit">Richiedi acquisto per la corporazione</button>
  </form>
<?php endif; ?>










        </div>

<?php if($isSame): require_once __DIR__.'/../../libs/item-engine.inc.php';if(item_engine_ready()&&item_instance($detail_id,$pgid)['rules']):?><section class="ui-form" data-item-console="<?=$detail_id?>"><h3>Uso e manutenzione</h3></section><script src="/js/item-engine.js" defer></script><?php endif;endif;?>
