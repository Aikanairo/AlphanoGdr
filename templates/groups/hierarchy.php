<?php
/** @var array $hierarchy */
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gerarchia · <?=$h($hierarchy['name'])?></title></head><body class="ui-page">
<header class="ui-page-heading" data-page-heading data-header-image="<?=$h($hierarchy['header_image']??'')?>"><span data-icon="<?=$h($hierarchy['icon'])?>" aria-hidden="true"></span><div><div class="ui-eyebrow">Alphano · <?=$h($hierarchy['kind'])?></div><h1><?=$h($hierarchy['name'])?></h1><p>Gerarchia, cariche e personaggi assegnati.</p></div></header>
<?php $chart=$hierarchy['chart'];require __DIR__.'/../shared/hierarchy-chart.php'; ?>
<footer class="ui-list-footer"><a class="action-btn" data-icon="<?=$h($hierarchy['icon'])?>" href="/<?=$h($hierarchy['kind'])?>/">Torna alle <?=$h($hierarchy['kind'])?></a></footer>
</body></html>
