<?php
require_once __DIR__.'/../../libs/profile-symbols.inc.php';
/** @var array $lineage Public names and game events only; never chat contents. */
$lh=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$seen=[];
$branch=static function(int $id,int $depth=0)use(&$branch,&$seen,$lineage,$lh):void {
    if(isset($seen[$id])||!isset($lineage['nodes'][$id]))return;$seen[$id]=true;$n=$lineage['nodes'][$id];
    $dead=$n['status']==='eliminated';
    ?><li><article class="lineage-node<?=$dead?' is-eliminated':''?>"><a class="lineage-portrait-link" href="/profili/?Id=<?=$id?>" aria-label="Profilo di <?=$lh($n['nome'])?>"><img class="lineage-portrait" src="<?=$lh($n['portrait'])?>" data-default-src="<?=$lh($n['portrait_default'])?>" data-privacy-compact width="92" height="92" alt="Ritratto di <?=$lh($n['nome'])?>"></a><a href="/profili/?Id=<?=$id?>"><?=$lh($n['nome'])?></a>
    <small><?=$lh(match($n['origin']){'original'=>'Vampiro Originale · capostipite','ancestral'=>'Licantropo Ancestrale · capostipite','undocumented'=>'Origine non documentata',default=>'Creato da '.$n['creator_name']})?></small>
    <?php if($dead): ?><strong class="lineage-death">✕ Eliminato</strong><small><?=date('d/m/Y H:i',(int)$n['killed_at'])?> · <?=$lh($n['killer_name'])?></small><small><?=$lh($n['death_reason'])?></small>
    <?php elseif($n['status']==='pending'): ?><small>Contagio in corso · risveglio <?=date('d/m/Y H:i',(int)$n['ready_at'])?></small>
    <?php elseif($n['status']==='cancelled'): ?><small>Contagio interrotto</small><?php endif; ?></article>
    <?php if(!empty($lineage['children'][$id])): if($depth<40): ?><ul><?php foreach($lineage['children'][$id] as $child)$branch($child,$depth+1); ?></ul><?php else: ?><a href="/razze/stirpi.php?kind=<?=$lh($lineage['kind'])?>&amp;root=<?=$id?>">Continua la discendenza</a><?php endif; endif; ?></li><?php
};
?>
<div class="lineage-intro"><img class="rpg-dossier-hero-symbol" src="<?=$lh(alphano_profile_symbol('lineage'))?>" alt="" width="76" height="76"><div><h2><?=$lineage['kind']==='vampire'?'Discendenza di sangue':'Discendenza del branco'?></h2><p>Ogni livello mostra i personaggi contagiati direttamente da quello che li precede.</p><?php if($lineage['top']!==$lineage['root']): ?><a class="action-btn" data-icon="users" href="/razze/stirpi.php?kind=<?=$lh($lineage['kind'])?>&amp;root=<?=$lineage['root']?>">Risalire al capostipite</a><?php endif; ?></div></div>
<div class="lineage-tree" tabindex="0" role="region" aria-label="Albero della discendenza, scorribile orizzontalmente"><ul><?php $branch($lineage['top']); ?></ul></div>
