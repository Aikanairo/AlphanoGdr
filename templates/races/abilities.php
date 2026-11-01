<?php /** Shared grimoire renderer consumes the real public catalogue; JSON is inert and escaped. */ ?>
<div data-race-grimoire data-grimoire-title="<?=$h($grimoireTitle??'Grimorio della stirpe')?>">
 <script type="application/json" data-grimoire-catalog><?=json_encode($abilities,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)?></script>
 <div data-grimoire-mount><p>Caricamento del grimorio…</p></div>
 <noscript><?php foreach($abilities as $ability): ?><article><h3><?=$h($ability['name'])?></h3><img class="rpg-ability-art" src="<?=$h($ability['image'])?>" alt="" width="96" height="96"><p><?=$h($ability['description'])?></p><p><?=$ability['cost']?> PE</p></article><?php endforeach; ?></noscript>
</div>
