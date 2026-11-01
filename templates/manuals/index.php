<?php $h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$isPublic=!empty($manuals['public']); ?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Manuali – <?=$h(TitoloSito)?></title><?php if($manuals['adminAccademia']): ?><link href="/js/vendor/quill/quill.snow.css" rel="stylesheet"><script src="/js/vendor/quill/quill.js" defer></script><?php endif; ?><script src="/js/manuali.js?rev=<?=substr(hash_file('sha256',__DIR__.'/../../js/manuali.js'),0,16)?>" defer></script></head><body class="<?=$isPublic?'manual-public-page':''?>">
<?php if($isPublic): ?><nav class="manual-public-nav" aria-label="Alphano e accesso al gioco"><a href="/#manuali">← Alphano · Homepage</a><a href="/#accesso">Accedi al gioco</a></nav><?php endif; ?>
<section class="manual-workspace manual-library" aria-label="Biblioteca di Alphano">
<header class="manual-library-heading" data-page-heading data-header-image="/assets/placeholder.svg">
<span class="manual-library-symbol" data-icon="book" aria-hidden="true"></span>
<div class="manual-library-intro"><span class="manual-eyebrow">ALPHANO · LA BIBLIOTECA DEL VIANDANTE</span><h1>Manuali</h1><p>Il tuo viaggio comincia qui. Regole, storie e strumenti, dalla prima giocata alla guida di una città.</p></div>
<div class="manual-library-tools"><a href="<?=$isPublic?'/manuali/':'/regole/'?>?chapter=personaggi-storici" class="action-btn">Personaggi storici</a><?php if($isPublic): ?><a href="/#registrazione" class="action-btn" data-icon="user">Crea il tuo personaggio</a><?php else: ?><a href="/regole/pdf.php" target="_blank" rel="noopener" class="action-btn" data-icon="book">Manuale in PDF</a><?php endif; ?><?php if($manuals['adminAccademia']): ?><button type="button" id="create-group-btn" class="action-btn" data-icon="book">Nuova raccolta</button><?php endif; ?></div>
<form class="manual-filters" role="search"><label>Cerca nel manuale<input id="manual-search" type="search" placeholder="Es. combattimento, cittadinanza, PE…" autocomplete="off"></label><label>Raccolta<select id="manual-group"><option value="0">Tutti i capitoli</option></select></label><label>Tipo di guida<select id="manual-audience"><option value="all">Tutte le guide disponibili</option><option value="public">Per giocare</option><?php if(!$isPublic): ?><option value="staff">Per il tuo incarico</option><?php endif; ?><option value="legacy">Archivio e approfondimenti</option></select></label><button type="reset" class="action-btn">Azzera filtri</button></form>
</header>
<div id="manual-status" class="manual-result-count" role="status">Preparazione della biblioteca…</div>
<div id="manual-index"></div>
<article id="manual-detail" hidden tabindex="-1"></article>
</section>
<div id="toast" role="status" aria-live="polite"></div>
<?php if($manuals['adminAccademia']): ?><div id="modal" class="modal forum-modal" role="dialog" aria-modal="true" aria-label="Redazione manuali"><div class="modal-content"><button type="button" class="close" aria-label="Chiudi">×</button><div id="modal-body"></div></div></div><?php endif; ?>
<script type="application/json" id="manuals-config"><?=json_encode($manuals,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script>
</body></html>
