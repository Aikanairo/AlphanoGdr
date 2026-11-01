<?php
/** Controller-prepared public and authorized profile data; no permission decisions in the view. */
$h=static fn(mixed $v):string=>htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$sheet['header_progression']=true;
if(isset($sheet['npc_id']))$sheet['sections']=array_values(array_filter($sheet['sections'],static fn($s)=>$s['template']!=='board-games'));
$d=$sheet['design'];$defaultPortrait=$sheet['portrait']===''||str_contains($sheet['portrait'],'/assets/placeholder.svg');
$emblem=static function(array $a)use($h):void { ?><span class="rpg-emblem" title="<?=$h($a['label']??$a['name'])?>"><?php if($a['src']): ?><img src="<?=$h($a['src'])?>" alt="<?=$h($a['label']??$a['name'])?>"><?php elseif(!empty($a['glyph'])): ?><?=$h($a['glyph'])?><?php else: ?><span data-icon="<?=$h($a['icon'])?>" aria-hidden="true"></span><?php endif; ?></span><?php };
$fields=static function(array $rows)use($h):void { ?><dl class="rpg-fields"><?php foreach($rows as $r): ?><div><dt><?=$h($r['label'])?></dt><dd><?=$r['html']?></dd></div><?php endforeach; ?></dl><?php };
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Profilo di <?=$h($sheet['title'])?></title><script type="module" src="/js/profile-status.js"></script><script type="module" src="/js/profile-cards.js?v=<?=filemtime(dirname(__DIR__,2).'/js/profile-cards.js')?>"></script><script src="/js/profile-sheet.js?v=<?=filemtime(dirname(__DIR__,2).'/js/profile-sheet.js')?>" defer></script><?php if(isset($sheet['npc_id'])): ?><script src="/js/npc-social.js" defer></script><?php endif; ?></head>
<body class="ui-page"<?php if(isset($sheet['npc_id'])): ?> data-social-npc="<?=(int)$sheet['npc_id']?>"<?php endif; ?>>
<main class="profile character-sheet rpg-sheet" data-rpg-profile data-rpg-sex="<?=$d['sex']==='Femmina'?'female':'male'?>" data-rpg-seal="<?=$h($sheet['seal']??alphano_profile_race_seal((int)$sheet['race_id'],(string)$d['sex']))?>" data-rpg-seal-label="<?=$h($sheet['seal_label']??(GetRazza((int)$sheet['race_id']).' · '.$d['sex']))?>" data-kind="<?=isset($sheet['npc_id'])?'npc':'player'?>" data-id="<?=(int)$sheet['rpg_id']?>">
 <header class="rpg-hero rpg-constellation-hero" data-sex="<?=$d['sex']==='Femmina'?'female':'male'?>" data-page-heading data-metamorphosis-state="<?=$h(json_encode($sheet['metamorphosis']??null,JSON_UNESCAPED_UNICODE))?>">
  <img class="rpg-hero-art" data-profile-banner src="<?=$h($sheet['art']['url'])?>" data-default-src="<?=$h($sheet['art']['default'])?>" alt="" aria-hidden="true">
  <aside class="rpg-portrait-column"><div class="rpg-portrait <?=$d['sex']==='Femmina'?'is-female':'is-male'?>"><div class="rpg-silhouette"><img data-profile-portrait data-default-src="<?=$h($sheet['art']['default'])?>" class="<?=$defaultPortrait?'is-race-portrait':''?>" src="<?=$h($defaultPortrait?$sheet['art']['default']:$sheet['portrait'])?>" alt="Ritratto di <?=$h($sheet['title'])?>"></div></div>
   <dl class="rpg-portrait-vitals" aria-label="Salute"><div class="rpg-vital-health"><dt><img class="rpg-vital-symbol" src="<?=$h(alphano_profile_symbol('health'))?>" alt="" width="28" height="28">Vita</dt><dd><?=$d['hp']?> / <?=$d['max_hp']?></dd><meter min="0" max="<?=$d['max_hp']?>" value="<?=max(0,$d['hp'])?>" aria-label="Punti ferita: <?=$d['hp']?> su <?=$d['max_hp']?>"></meter></div></dl>
  </aside>
  <div class="rpg-identity">
   <span class="rpg-eyebrow">Alphano · Storie della comunità</span>
   <div class="rpg-title-line"><h1><?=$h($sheet['title'])?></h1><div class="rpg-title-actions"><?php foreach($sheet['actions'] as $a):if($a['icon']!=='settings')continue; ?><a class="rpg-edit-action" href="<?=$h($a['url'])?>">Modifica</a><?php endforeach; ?><?php foreach($sheet['actions'] as $a):if(!in_array($a['icon'],['mail','compass'],true))continue; ?><a class="rpg-title-action" data-icon="<?=$h($a['icon'])?>" href="<?=$h($a['url'])?>" aria-label="<?=$h($a['title'])?>" title="<?=$h($a['title'])?>"></a><?php endforeach; ?></div></div>
   <div class="rpg-identity-line"><?php if(!empty($sheet['origin_image'])): ?><img class="rpg-ethnicity-emblem" data-profile-ethnicity src="<?=$h($sheet['origin_image'])?>" alt="" width="36" height="36"><?php endif; ?><span><?=$h($sheet['origin_label']??'')?></span><span class="rpg-header-fact"><img src="<?=$h(alphano_profile_symbol('crowns'))?>" alt="" width="30" height="30"><span><small><?=alphano_currency_html()?></small><strong><?=$h($sheet['metrics']['Corone']??'0')?></strong></span></span><span class="rpg-header-fact"><img src="<?=$h(alphano_profile_symbol('status'))?>" alt="" width="30" height="30"><span><small>Stato</small><strong><?=$h($sheet['status_label']??'')?></strong></span></span></div><?php if($sheet['subtitle']): ?><p class="rpg-subtitle"><?=$h($sheet['subtitle'])?></p><?php endif; ?>

  </div>
  <div class="rpg-identity-section"><?php require __DIR__.'/identity-cards.php'; require __DIR__.'/identity-context.php'; ?></div>
  <aside class="rpg-hero-attributes" aria-label="Caratteristiche ed esperienza"><?php require __DIR__.'/progression-header.php'; ?></aside>
  <nav class="tabs character-tabs rpg-profile-tabs" data-character-tabs aria-label="Scheda del personaggio"><?php foreach($sheet['sections'] as $i=>$s): ?><button type="button" class="tab<?=$i===0?' active':''?>" data-tab="<?=$h($s['id'])?>" data-ui-no-icon><img class="rpg-tab-symbol" src="<?=$h(alphano_profile_tab_symbol($s['template']))?>" alt="" width="32" height="32"><?=$h($s['title'])?><?php if($s['id']==='progressione'): ?><span class="rpg-points-dot" data-profile-points-dot hidden aria-label="Punti disponibili"></span><?php endif; ?></button><?php endforeach; ?><span class="rpg-archive-label">Archivio del personaggio</span></nav>
 </header>
 <div class="rpg-dossier">

 <?php foreach($sheet['sections'] as $i=>$s): ?><section id="<?=$h($s['id'])?>" class="tab-content rpg-pane<?=$i===0?' active':''?>"<?=$i===0?'':' hidden'?>>
  <?php if($s['template']==='board-games'): require __DIR__.'/board-games.php'; elseif($s['template']==='victims'): require __DIR__.'/victims.php'; elseif($s['template']==='npc-knowledge'): require __DIR__.'/npc-knowledge.php'; elseif($s['template']==='revelations'): require __DIR__.'/revelations.php'; elseif($s['template']==='lineage'): require __DIR__.'/../lineages/tree.php'; elseif($s['template']==='progression'): require __DIR__.'/progression.php'; elseif($s['template']==='history'): require __DIR__.'/history.php'; elseif($s['template']==='equipment'): ?><iframe class="rpg-equipment-frame" data-profile-equipment loading="lazy" title="Equipaggiamento di <?=$h($sheet['title'])?>" src="<?=$h($sheet['equipment_url'])?>"></iframe><?php else: ?>
   <?php require __DIR__.'/identity.php'; ?>
  <?php endif; ?>
 </section><?php endforeach; ?>
</div></main></body></html>
