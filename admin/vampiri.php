<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/lineages.inc.php';
require_once __DIR__.'/../libs/lycan.inc.php';
require_once __DIR__.'/../libs/profile-art.inc.php';
$id=alphano_require_login();$user=alphano_user();
if(!lineage_vampire_can_infect($user))alphano_fail('Poteri riservati ai vampiri vivi.',403);
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 alphano_require_csrf();
 try{$target=filter_var($_POST['target']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$target)throw new DomainException('Scegli un personaggio.',422);$key=player_text($_POST,'_request_key',100,true);lineage_infect($id,(int)$target,$key);$message='Il contagio è stato annunciato in chat. La metamorfosi avverrà dopo sei ore.';}
 catch(DomainException $e){$error=$e->getMessage();http_response_code($e->getCode()>=400?$e->getCode():400);}
}
$targets=[];
if(alphano_lycan_in_chat($user,time()))foreach(ConnectDB()->query('SELECT * FROM utenti WHERE chat=? AND id<>? AND lastmove>=? ORDER BY nome',[$user['chat'],$id,time()-3600]) as $target)if(lineage_human_eligible($target)&&!ConnectDB()->query("SELECT player_id FROM lineage_members WHERE player_id=? AND (kind='vampire' OR status='pending')",[$target['id']]))$targets[]=$target;
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="it"><head><meta charset="utf-8"><title>Il legame di sangue</title></head><body class="ui-page">
<header class="ui-page-heading" data-page-heading data-header-image="<?=$h(alphano_profile_default_banner((int)$user['razza'],(string)$user['sesso']))?>"><span data-icon="moon"></span><div><h1>Il legame di sangue</h1><p>Infetta un umano presente nella tua chat. Dopo sei ore diventerà un vampiro della tua stirpe.</p></div><a class="action-btn" data-icon="users" href="/profili/?Id=<?=$id?>#discendenza">La mia discendenza</a></header>
<section class="ui-form-panel"><?php if($message): ?><p role="status"><?=$h($message)?></p><?php endif; ?><?php if($error): ?><p role="alert"><?=$h($error)?></p><?php endif; ?>
<h2>Trasmettere il sangue</h2><p>Il legame unisce il nuovo vampiro al suo creatore e al capostipite. La morte di un vampiro elimina tutta la sua progenie vampirica, anche nelle generazioni successive.</p>
<?php if($targets): ?><form method="post"><input type="hidden" name="_csrf" value="<?=$h(alphano_csrf_token())?>"><input type="hidden" name="_request_key" value="<?=bin2hex(random_bytes(16))?>"><label for="blood-target">Umano nella stessa chat</label><select id="blood-target" name="target" required><option value="">Scegli un personaggio</option><?php foreach($targets as $target): ?><option value="<?=(int)$target['id']?>"><?=$h($target['nome'])?></option><?php endforeach; ?></select><button type="submit" data-icon="moon">Mordi e infetta</button></form><?php else: ?><p>Nessun umano compatibile è presente con te in una chat. Entra in un luogo di gioco insieme al destinatario.</p><?php endif; ?></section></body></html>
<?php ClosePage(); ?>
