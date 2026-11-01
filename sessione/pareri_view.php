<?php
if (!isset($id,$db,$idpg)) { http_response_code(404); exit('Apri i pareri dalla lista sessioni'); }
$id = (int)$id;
$session = $db->query('SELECT * FROM sessioni WHERE id=? AND run=0', [$id])[0] ?? null;
if (!$session) { http_response_code(404); exit('Sessione chiusa non trovata'); }
$participants = array_filter(explode(';', $session['partecipanti']));
$canVote = in_array((string)$idpg,$participants,true) && $idpg !== (int)$session['masterin'] && $idpg !== (int)$session['masterout'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($azione,['aggiungiparere','modificaparere'],true)) {
    alphano_require_csrf();
    if (!$canVote) { http_response_code(403); exit('Non puoi votare questa sessione'); }
    $vote = filter_var($_POST['parere'] ?? null,FILTER_VALIDATE_INT);
    if (!in_array($vote,[-1,0,1],true)) { http_response_code(400); exit('Voto non valido'); }
    $motivation = trim((string)($_POST['motivazione'] ?? ''));
    $old = $db->query('SELECT pg FROM parerisessioni WHERE sessione=? AND pg=?', [$id,$idpg]);
    if ($old) $db->execute('UPDATE parerisessioni SET parere=?,motivazione=? WHERE sessione=? AND pg=?', [$vote,$motivation,$id,$idpg]);
    else $db->execute('INSERT INTO parerisessioni(sessione,pg,parere,motivazione) VALUES (?,?,?,?)', [$id,$idpg,$vote,$motivation]);
    header('Location: /sessione/viewlog.php?azione=pareri&id='.$id); exit;
}
$votes = $db->query('SELECT p.*,u.nome FROM parerisessioni p LEFT JOIN utenti u ON u.id=p.pg WHERE sessione=?', [$id]);
$ownVote=null;
foreach ($votes as $vote) if ((int)$vote['pg']===$idpg) $ownVote=$vote;
?>
<!doctype html><html lang="it"><meta charset="utf-8"><title>Pareri sessione</title><body>
<h1>Pareri: <?=htmlspecialchars($session['nome'],ENT_QUOTES)?></h1>
<?php foreach ($votes as $vote): ?><p><?=htmlspecialchars($vote['nome'] ?? 'Personaggio cancellato',ENT_QUOTES)?>: <?=(int)$vote['parere']?><?php if(trim((string)$vote['motivazione'])!==''): ?><br><?=nl2br(htmlspecialchars($vote['motivazione'],ENT_QUOTES))?><?php endif; ?></p><?php endforeach ?>
<?php if ($canVote): ?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(alphano_csrf_token(),ENT_QUOTES)?>"><input type="hidden" name="azione" value="<?=$ownVote?'modificaparere':'aggiungiparere'?>"><input type="hidden" name="id" value="<?=$id?>"><label>Il tuo parere <select name="parere"><?php foreach([1=>'Positivo',0=>'Neutro',-1=>'Negativo'] as $value=>$label): ?><option value="<?=$value?>" <?=$value===(int)($ownVote['parere'] ?? 1)?'selected':''?>><?=$label?></option><?php endforeach; ?></select></label><label>Motivazione <textarea name="motivazione"><?=htmlspecialchars((string)($ownVote['motivazione'] ?? ''),ENT_QUOTES)?></textarea></label><button>Salva parere</button></form><?php endif ?>
<a href="/sessione/viewlog.php">Torna alle sessioni</a></body></html>
