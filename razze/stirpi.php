<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/lineages.inc.php';
alphano_require_login();$kind=$_GET['kind']??'vampire';if(!is_string($kind)||!in_array($kind,['vampire','lycan'],true))alphano_fail('Stirpe non valida.',400);
$raw=$_GET['root']??null;$root=$raw===null?null:filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($root===false)alphano_fail('Capostipite non valido.',400);
$lineage=null;if($root!==null){try{$lineage=lineage_tree((int)$root,$kind);}catch(DomainException $e){alphano_fail($e->getMessage(),404);}}
$families=ConnectDB()->query('SELECT l.player_id,l.origin,u.nome FROM lineage_members l JOIN utenti u ON u.id=l.player_id WHERE l.kind=? AND l.creator_id IS NULL ORDER BY u.nome',[$kind]);
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="it"><head><meta charset="utf-8"><title>Famiglie e discendenze</title></head><body class="ui-page"><header class="ui-page-heading" data-page-heading><span data-icon="users"></span><div><h1><?=$kind==='vampire'?'Le famiglie del sangue':'Le stirpi ancestrali'?></h1><p>Capostipiti, creatori e discendenti.</p></div><nav class="ui-heading-control"><a class="action-btn" href="/razze/?id=<?=$kind==='vampire'?5:6?>" data-icon="book">La razza</a><a class="action-btn" href="?kind=<?=$h($kind)?>" data-icon="users">Tutte le famiglie</a></nav></header>
<?php if($lineage): require __DIR__.'/../templates/lineages/tree.php'; else: ?><section class="lineage-families"><?php foreach($families as $family): ?><a class="lineage-node" href="?kind=<?=$h($kind)?>&amp;root=<?=(int)$family['player_id']?>"><strong><?=$h($family['nome'])?></strong><small><?=$h(match($family['origin']){'original'=>'Vampiro Originale','ancestral'=>'Licantropo Ancestrale',default=>'Origine non documentata'})?></small></a><?php endforeach; ?><?php if(!$families): ?><p>Nessuna famiglia documentata.</p><?php endif; ?></section><?php endif; ?></body></html>
<?php ClosePage(); ?>
