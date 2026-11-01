<?php $h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?=$h($forum['title'])?> – <?=$h(TitoloSito)?></title>
<script src="/js/forum.js" defer></script><script src="/js/<?=$forum['mode']==='discussion'?'discussioni':'bacheche'?>.js?v=<?=filemtime(dirname(__DIR__,2).'/js/'.($forum['mode']==='discussion'?'discussioni':'bacheche').'.js')?>" defer></script></head><body>
<?php if(!empty($forum['message'])): ?><p class="flash" role="status"><?=$h($forum['message'])?></p><?php endif; ?>
<section class="forum-workspace" aria-label="<?=$h($forum['title'])?>">
<?php if($forum['mode']==='discussion'): ?>
<nav id="menu-list" class="forum-channels" aria-label="Bacheche disponibili">
<?php foreach($forum['channels'] as $key=>$label): ?><button type="button" data-tipo="<?=$h($key)?>" class="<?=$forum['tipo']===$key?'active':''?>" aria-pressed="<?=$forum['tipo']===$key?'true':'false'?>" data-icon="<?=str_starts_with($key,'Org')?'users':(str_starts_with($key,'Corp')?'shield':'messages')?>"><?=$h($label)?></button><?php endforeach; ?>
</nav>
<header class="forum-toolbar"><h2 id="panel-title"><?=$h($forum['channels'][$forum['tipo']])?></h2><div class="ui-row-actions"><button type="button" id="btn-toggle-closed" class="action-btn" data-icon="search" aria-pressed="false">Mostra chiusi</button><button type="button" id="btn-create" class="action-btn" data-icon="messages">Nuova discussione</button></div></header>
<?php else: ?><header id="panel-header" class="forum-toolbar"><h2>Bacheche</h2></header><?php endif; ?>
<div id="panel-container" class="forum-content" aria-live="polite"><p class="ui-empty">Caricamento…</p></div>
</section>
<div id="modal" class="modal forum-modal" role="dialog" aria-modal="true" aria-labelledby="forum-modal-title"><div class="modal-content"><button type="button" class="close-modal" aria-label="Chiudi">×</button><h2 class="modal-title" id="forum-modal-title"></h2><form class="modal-form"></form></div></div>
<div id="toast" role="status" aria-live="polite"></div>
<script type="application/json" id="forum-config"><?=json_encode($forum,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
</body></html>
