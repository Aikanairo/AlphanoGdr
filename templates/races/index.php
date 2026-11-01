<?php
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$race=$races['selected'];
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=$h($race?$race['name'].' · Razze':'Razze')?></title><script type="module" src="/js/race-members.js"></script><script src="/js/ui-dialog.js" defer></script><script type="module" src="/js/race-abilities.js?v=<?=filemtime(dirname(__DIR__,2).'/js/race-abilities.js')?>"></script></head><body class="ui-page">
<main class="race-directory">
<header class="ui-page-heading race-heading" data-page-heading<?php if($race): ?> data-header-image="<?=$h($race['banner'])?>"<?php endif; ?>>
 <span data-icon="users" aria-hidden="true"></span><div><span class="ui-eyebrow">Alphano · Stirpi</span><h1><?=$h($race?$race['name']:'Razze')?></h1><p><?=$h($race?$race['description']:'Esplora le stirpi di Alphano, le loro origini e le capacità dei personaggi.')?></p></div>
 <?php if($race): ?><a class="action-btn race-backlist" data-icon="book" href="/razze/" aria-label="Elenco razze" title="Elenco razze"></a>
 <nav class="tabs race-tabs" data-character-tabs aria-label="Conoscere la stirpe">
  <button type="button" class="tab active" data-tab="race-story" data-icon="book">Storia</button>
  <button type="button" class="tab" data-tab="race-innate" data-icon="skill">Caratteristiche</button>
  <button type="button" class="tab" data-tab="race-members" data-icon="users">Famiglie e discendenze</button>
  <button type="button" class="tab" data-tab="race-shop" data-icon="scroll">Tutte le abilità</button>
  <a class="action-btn race-my-abilities" href="/profili/#progressione" data-icon="user">Le mie abilità</a>
 </nav><?php else: ?><nav class="ui-heading-control"><a class="action-btn" href="/profili/#progressione" data-icon="user">Le mie abilità</a></nav><?php endif; ?>
</header>
<nav class="race-atlas" aria-label="Scegli razza">
 <?php foreach($races['races'] as $r): ?><a class="race-tile" href="/razze/?id=<?=$r['id']?>"<?=$race&&$r['id']===$race['id']?' aria-current="page"':''?>>
  <img src="<?=$h($r['banner'])?>" alt="" loading="lazy"><span class="race-tile-copy"><strong><?=$h($r['name'])?></strong><span><span data-icon="users" aria-hidden="true"></span><?=$r['members_count']?> <?=$r['members_count']===1?'personaggio':'personaggi'?></span></span>
 </a><?php endforeach; ?>
</nav>
<?php if($race): ?>
<section id="race-story" class="race-pane active"><article class="race-reading"><?=preg_replace('~<(\/?)h1\b~i','<$1h2',$races['lore']['html'])?></article><?php if($races['lore']['source']): ?><p class="race-source">Dal manuale: <?=$h($races['lore']['source']['title'])?>. La storia è condivisa con la stirpe di origine; caratteristiche e capacità distinguono la forma attuale.</p><?php endif; ?></section>
<section id="race-innate" class="race-pane" hidden>
 <div class="race-section-heading"><h2>Caratteristiche di base</h2><p>I valori iniziali della stirpe, prima della crescita individuale del personaggio.</p></div>
 <div class="race-base-stats" aria-label="Caratteristiche di base"><?php foreach($races['stats'] as $stat): ?><div title="<?=$h($stat['description'])?>"><?php if(str_starts_with($stat['icon'],'/uploads/statistics/')): ?><img src="<?=$h($stat['icon'])?>" alt=""><?php else: ?><span data-icon="<?=$h($stat['icon'])?>" aria-hidden="true"></span><?php endif; ?><span><?=$h($stat['name'])?></span><strong><?=$race['stats'][$stat['key']]?></strong><meter min="0" max="100" value="<?=$race['stats'][$stat['key']]?>" aria-label="<?=$h($stat['name'])?>: <?=$race['stats'][$stat['key']]?> su 100"></meter></div><?php endforeach; ?></div>
 <p>Le innate e le altre discipline sono raccolte nello stesso Grimorio, distinguibili per colore e tipologia. <a href="#race-shop">Consulta tutte le abilità</a>.</p>
</section>
<section id="race-members" class="race-pane" hidden>
 <?php if($races['family_kind']): ?><section class="race-family-directory"><div class="race-section-heading"><h2>Famiglie e discendenze</h2><p>Apri un capostipite per consultare creatori e discendenti della stirpe.</p></div><div class="race-member-grid">
 <?php foreach($races['families'] as $family): ?><a class="race-member-card" href="/razze/stirpi.php?kind=<?=$h($races['family_kind'])?>&amp;root=<?=$family['id']?>"><img src="<?=$h($family['portrait'])?>" data-default-src="<?=$h($family['portrait_default'])?>" data-privacy-compact width="64" height="64" alt=""><span><strong><?=$h($family['name'])?></strong><small>Capostipite · Esplora la discendenza</small></span></a><?php endforeach; ?>
 <?php if(!$races['families']): ?><p>Nessuna famiglia documentata.</p><?php endif; ?></div></section><?php endif; ?>
 <div class="race-section-heading"><h2>Personaggi · <?=$h($race['name'])?></h2><span class="race-total"><?=$race['members_count']?> appartenenti</span></div>
 <div class="race-member-grid" data-race-members="<?=$race['id']?>" data-total="<?=$race['members_count']?>"><?php foreach($race['members'] as $member): ?><a class="race-member-card" href="/profili/?Id=<?=$member['id']?>"><img src="<?=$h($member['portrait'])?>" data-default-src="<?=$h($member['portrait_default'])?>" data-privacy-compact width="64" height="64" alt=""><span><strong><?=$h($member['name'])?></strong><?php if($member['human_form']): ?><small>Attualmente in forma umana</small><?php endif; ?></span></a><?php endforeach; ?><?php if(!$race['members']): ?><p>Nessun personaggio appartiene attualmente a questa razza.</p><?php endif; ?></div>
 <?php if($race['members_count']>20): ?><div class="race-load-more"><p data-race-loaded role="status">20 di <?=$race['members_count']?> personaggi</p><button class="action-btn" type="button" data-race-more data-icon="plus">Mostra altri 20</button></div><?php endif; ?>
</section>
<section id="race-shop" class="race-pane" hidden>
 <div class="race-section-heading"><h2>Abilità innate e altre discipline</h2><p>Un solo catalogo per tutta la stirpe: innate in ambra, altre discipline in azzurro ed eredità speciali in viola. Scegli quattro innate umane gratuite alla creazione; in seguito puoi acquistare anche quelle non scelte con i PE indicati, rispettando requisiti e compatibilità.</p></div>
 <div class="rpg-learning-toolbar"><span class="rpg-learning-balance" data-rpg-learning-balance role="status">Caricamento dei PE…</span><p data-ability-feedback role="status" aria-live="polite"></p></div>
 <?php $abilities=$races['abilities']['all']; $grimoireTitle='Grimorio della stirpe'; if($abilities)require __DIR__.'/abilities.php'; ?>
 <?php if(!$abilities): ?><p class="rpg-empty">Non sono previste capacità per questa forma.</p><?php endif; ?>
</section>
<?php endif; ?>
</main></body></html>
