<?php $h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Gestione bacheche cittadine – <?=$h(TitoloSito)?></title><link href="/js/vendor/quill/quill.snow.css" rel="stylesheet"><script src="/js/vendor/quill/quill.js" defer></script><script src="/js/admin/bacheche.js?v=<?=filemtime(dirname(__DIR__,2).'/js/admin/bacheche.js')?>" defer></script></head><body>
<section class="board-admin-workspace" aria-label="Gestione delle bacheche cittadine">
<header class="forum-toolbar"><label class="forum-location">Città<select id="board-admin-city" aria-label="Città delle bacheche"><option>Caricamento…</option></select></label><div class="ui-row-actions"><span id="board-admin-count" class="forum-summary"></span><button type="button" id="create-board" data-icon="messages">Nuova bacheca</button></div></header>
<div id="board-admin-content" aria-live="polite"><p class="ui-empty">Caricamento…</p></div>
<?php if($boardAdmin['canManageAll']): ?><footer class="ui-list-footer"><details class="board-admin-advanced"><summary>Operazioni sulla città selezionata</summary><p>Elimina tutte le bacheche e le discussioni della città. Questa operazione richiede conferma.</p><button type="button" id="delete-city-boards" data-icon="settings">Elimina tutte le bacheche della città</button></details></footer><?php endif; ?>
</section><div id="toast" role="status" aria-live="polite"></div>
<script type="application/json" id="board-admin-config"><?=json_encode($boardAdmin,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
</body></html>
