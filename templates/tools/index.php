<?php $h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Strumenti – <?=$h(TitoloSito)?></title></head><body>
<section class="ui-list-section" aria-label="Strumenti personali"><div class="ui-table-scroll" tabindex="0" role="region" aria-label="Strumenti disponibili"><table class="ui-data-table"><thead><tr><th scope="col">Strumento</th><th scope="col">A cosa serve</th><th scope="col">Azioni</th></tr></thead><tbody>
<?php foreach($tools as $tool): ?><tr><td><strong><?=$h($tool['title'])?></strong></td><td><?=$h($tool['description'])?></td><td><a class="action-btn" data-icon="<?=$h($tool['icon'])?>" href="<?=$h($tool['url'])?>">Apri</a></td></tr><?php endforeach; ?>
</tbody></table></div></section></body></html>
