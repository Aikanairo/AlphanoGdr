<?php
/** Compact progression shares one API and one renderer in both the profile and standalone view. */
$rpgKind=$sheet['rpg_kind']??(isset($sheet['npc_id'])?'npc':'player');
$rpgId=(int)($sheet['rpg_id']??$sheet['npc_id']??0);
?>
<div class="progression"<?php if(empty($sheet['header_progression'])): ?> data-rpg-profile data-kind="<?=$rpgKind==='npc'?'npc':'player'?>" data-id="<?=$rpgId?>"<?php endif; ?>>
 <?php if(empty($sheet['header_progression']))require __DIR__.'/progression-header.php'; ?>
 <section class="racial-malus" data-rpg-malus></section>
 <div class="progression-section-heading"><h3>Grimorio delle abilità</h3><span class="rpg-learning-balance" data-rpg-learning-balance role="status"></span></div>
 <p data-rpg-abilities-summary class="progression-ability-summary"></p>
 <details class="progression-history" data-rpg-lineage hidden><summary>Stirpe, forma e patrimonio umano</summary><p data-rpg-lineage-summary></p><p data-rpg-lineage-balance></p><a href="/manuali/?chapter=eredita-abilita">Consulta compatibilità e regole di eredità</a></details>
 <div class="grimoire-host" data-rpg-abilities></div>
 <details class="progression-history" data-rpg-history-section><summary><span data-icon="scroll" aria-hidden="true"></span>Storico e dettaglio dell’esperienza</summary><div data-rpg-xp-detail class="progression-xp-detail"></div><div class="ui-table-scroll"><table class="ui-data-table"><thead><tr><th>Data</th><th>Evento</th><th>Esperienza</th><th>Dettaglio</th></tr></thead><tbody data-rpg-history></tbody></table></div><button type="button" data-rpg-history-more hidden>Mostra precedenti</button></details>
 <dialog class="modal-window rpg-train-dialog" data-rpg-train-dialog aria-labelledby="rpg-train-title"><header class="modal-header"><h2 id="rpg-train-title">Allena una caratteristica</h2><button type="button" data-rpg-train-close data-icon="x" aria-label="Chiudi allenamento"></button></header><form class="modal-body" data-rpg-train-form><p data-rpg-train-description></p><label>Punti da aggiungere<input name="points" type="number" min="1" step="1" value="1" required></label><p data-rpg-train-preview aria-live="polite"></p><p data-rpg-train-feedback role="status"></p><button type="submit" data-icon="check">Conferma investimento</button></form></dialog>
</div>
<script src="/js/ui-dialog.js" defer></script><script type="module" src="/js/rpg-profile.js?v=<?=filemtime(dirname(__DIR__,2).'/js/rpg-profile.js')?>"></script>
