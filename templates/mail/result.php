<!doctype html>
<html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invio Missiva</title></head>
<body class="message-page mail-workspace"><section class="mail-delivery" role="status">
<span class="mail-delivery-seal" aria-hidden="true">✉</span><small>ALPHANO · CORRISPONDENZA</small>
<h2><?= $pass ? 'La tua missiva è partita' : 'Invio non completato' ?></h2><p class="mail-delivery-subject"><?=h($oggetto)?></p>
<?php if($pass): ?><div class="mail-delivery-ok"><strong>Consegnata a</strong><p><?=implode(' · ',array_map('h',$pass))?></p></div><?php endif; ?>
<?php if($failed): ?><div class="mail-delivery-failed"><strong>Invio non riuscito</strong><p><?=implode(' · ',array_map('h',$failed))?></p></div><?php endif; ?>
<?php if(!$pass&&!$failed): ?><p>Nessun personaggio corrisponde ai destinatari indicati.</p><?php endif; ?>
<a class="action-btn" href="/posta/">Torna alla posta</a></section></body></html>
