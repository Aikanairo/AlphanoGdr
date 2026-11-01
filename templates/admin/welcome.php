<?php $h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<!doctype html>
<html lang="it"><head><meta charset="utf-8"><title>Messaggio di benvenuto</title></head><body class="ui-page">
<header class="ui-page-heading" data-page-heading><span data-icon="mail"></span><div><div class="ui-eyebrow">Alphano · Amministrazione</div><h1>Messaggio di benvenuto</h1><p>Accogli i giocatori e segnala le opportunità per partecipare alla vita del mondo.</p></div></header>
<?php if ($error): ?><p class="ui-notice" role="alert"><?=$h($error)?></p><?php endif; ?>
<?php if ($notice): ?><p class="ui-notice" role="status"><?=$h($notice)?></p><?php endif; ?>
<div class="welcome-admin-grid">
<form method="post" class="welcome-admin-form panel">
 <input type="hidden" name="_csrf" value="<?=$h(alphano_csrf_token())?>">
 <input type="hidden" name="revision" value="<?=$h($form['revision'])?>">
 <h2>Accoglienza dei giocatori</h2>
 <label class="welcome-enabled"><input type="checkbox" name="enabled" value="1" <?=$form['enabled']?'checked':''?>> Mostra il benvenuto a tutti gli utenti</label>
 <label>Titolo<input name="title" required maxlength="120" value="<?=$h($form['title'])?>"></label>
 <label>Messaggio<textarea name="body" required maxlength="3000" rows="7"><?=$h($form['body'])?></textarea></label>
 <p class="ui-muted">Testo semplice, con gli a capo che preferisci. Il collegamento «Scrivi alla gestione» è aggiunto automaticamente.</p>
 <button type="submit" data-icon="check">Salva benvenuto</button>
</form>
<section class="welcome-admin-preview panel" aria-label="Benvenuto attualmente pubblicato">
 <div class="ui-eyebrow">Attualmente <?=$message['enabled']?'pubblicato':'disattivato'?></div>
 <h2><?=$h($message['title'])?></h2>
 <p class="welcome-message-copy"><?=$h($message['body'])?></p>
 <?php if ($message['contact_url']): ?><a class="action-btn" data-icon="mail" href="<?=$h($message['contact_url'])?>">Scrivi alla gestione</a><?php endif; ?>
 <p class="ui-muted">Ogni giocatore vede il messaggio finché non lo chiude. Le modifiche pubblicano una nuova versione anche per chi ha già letto quella precedente. Ogni salvataggio è registrato nei log.</p>
</section>
</div>
</body></html>
