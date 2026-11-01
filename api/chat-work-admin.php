<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';require_once __DIR__.'/../libs/chat-work.inc.php';
player_api(static function():array{
 $uid=alphano_require_login(true);if(!alphano_work_staff($uid))throw new DomainException('Gestione riservata ad amministratori e responsabili dell’Accademia.',403);$d=ConnectDB();
 if($_SERVER['REQUEST_METHOD']==='GET')return ['settings'=>alphano_work_settings(),'version'=>(int)$d->query('SELECT version FROM chat_work_settings WHERE id=1')[0]['version'],'jobs'=>alphano_work_catalog(),'resources'=>alphano_work_resources(),'rooms'=>$d->query('SELECT chat_key,title FROM mappe WHERE chat=1 ORDER BY title'),'stats'=>rpg_catalog()['stats'],'history'=>$d->query('SELECT w.id,w.player_id,u.nome,w.chat_key,w.roll,w.bonus,w.difficulty,w.quantity,w.snapshot,w.created_at FROM chat_work_attempts w LEFT JOIN utenti u ON u.id=w.player_id ORDER BY w.id DESC LIMIT 100'),'audit'=>$d->query('SELECT a.id,a.kind,a.actor_id,u.nome,a.target_id,a.created_at FROM chat_work_admin_audit a LEFT JOIN utenti u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 30')];
 player_mutation();$in=player_input();$action=rpg_text($in['action']??'',20,true);$version=rpg_int($in['version']??0,1,2147483647,'Versione');
 $d->beginTransaction();try{
  $current=$d->query('SELECT * FROM chat_work_settings WHERE id=1 FOR UPDATE')[0];if((int)$current['version']!==$version)throw new DomainException('Un altro gestore ha aggiornato il pannello. Ricarica prima di salvare.',409);
  $before=[];$after=[];$target=0;
  if($action==='settings'){
   $before=alphano_work_settings();$after=$before;
   foreach(['enabled','require_narration'] as $k)$after[$k]=rpg_bool($in[$k]??false);
   foreach(['max_units'=>[1,1000],'window_seconds'=>[60,604800],'rest_seconds'=>[60,604800],'narration_min_chars'=>[5,500],'attempt_cooldown'=>[0,3600],'bonus_step'=>[1,100],'max_bonus'=>[0,10]] as $k=>[$min,$max])$after[$k]=rpg_int($in[$k]??null,$min,$max,$k);
   $d->execute('UPDATE chat_work_settings SET settings=? WHERE id=1',[rpg_json($after)]);
  }elseif($action==='job'){
   $target=rpg_int($in['id']??0,0,2147483647,'Attività');$chat=rpg_text($in['chat']??'',50,true);$text=rpg_text($in['azione']??'',255,true);$phrase=rpg_text($in['frase']??'',1000);$prob=rpg_int($in['prob']??null,5,95,'Probabilità base');$res=rpg_int($in['res']??0,1,2147483647,'Materia prima');$quantity=rpg_int($in['quantity']??1,1,100,'Unità');$stat=rpg_text($in['stat_key']??'',20,true);$enabled=rpg_bool($in['enabled']??false);
   if(!in_array($stat,RPG_STATS,true)||!$d->query('SELECT id FROM mappe WHERE chat_key=? AND chat=1',[$chat])||!in_array($res,array_column(alphano_work_resources(),'id'),true))throw new DomainException('Seleziona luogo, caratteristica e materia prima validi.',422);
   if($d->query('SELECT id FROM lavori WHERE chat=? AND azione=? AND id<>?',[$chat,$text,$target]))throw new DomainException('Esiste già questa attività nel luogo selezionato. Modifica quella esistente.',409);
   if($target){$before=$d->query('SELECT * FROM lavori WHERE id=?',[$target])[0]??null;if(!$before)throw new DomainException('Attività non trovata.',404);$before['options']=$d->query('SELECT * FROM chat_work_options WHERE job_id=?',[$target])[0]??null;$d->execute('UPDATE lavori SET chat=?,azione=?,prob=?,res=?,frase=? WHERE id=?',[$chat,$text,$prob,$res,$phrase,$target]);}
   else{$d->execute('INSERT INTO lavori(chat,azione,prob,res,frase) VALUES(?,?,?,?,?)',[$chat,$text,$prob,$res,$phrase]);$target=(int)$d->lastInsertId();}
   $d->execute('INSERT INTO chat_work_options(job_id,enabled,quantity,stat_key) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),quantity=VALUES(quantity),stat_key=VALUES(stat_key)',[$target,$enabled,$quantity,$stat]);
   $after=['chat'=>$chat,'azione'=>$text,'prob'=>$prob,'res'=>$res,'frase'=>$phrase,'quantity'=>$quantity,'stat_key'=>$stat,'enabled'=>$enabled];
  }else throw new DomainException('Operazione non valida.',422);
  $d->execute('UPDATE chat_work_settings SET version=version+1,updated_by=?,updated_at=? WHERE id=1',[$uid,time()]);$d->execute('INSERT INTO chat_work_admin_audit(actor_id,kind,target_id,before_state,after_state,created_at) VALUES(?,?,?,?,?,?)',[$uid,$action,$target,rpg_json($before),rpg_json($after),time()]);$d->commit();return ['ok'=>true,'id'=>$target];
 }catch(Throwable $e){if($d->inTransaction())$d->rollBack();throw $e;}
});
