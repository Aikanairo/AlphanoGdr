<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/ai.inc.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');$db=ai_db();
try{
 $uid=alphano_require_login(true);$u=alphano_user();$method=$_SERVER['REQUEST_METHOD'];
 if(!in_array($method,['GET','POST'],true))throw new DomainException('Metodo non consentito.',405);
 $in=$method==='POST'?alphano_request_json():$_GET;
 if($method==='POST')alphano_require_csrf(true);
 $action=ai_field($in,'action',30,true);$admin=!empty($u['admin']);
 // Ordinary Masters use the same scoped controller as the Session Manager.
 // This compatibility bridge never grants access to prompts, policy or raw data.
 if(!$admin&&$method==='POST'&&$action==='control'){
  if(!empty($in['paused']))throw new DomainException('La configurazione tecnica IA è riservata agli amministratori.',403);
  $mode=ai_field($in,'mode',12,true);
  if(!in_array($mode,['human','auto'],true))throw new DomainException('Usa la regia della sessione per assumere o affidare la conduzione.',403);
  require_once __DIR__.'/../libs/session-control.inc.php';
  $bridge=['session_id'=>ai_integer($in,'quest_id',1,PHP_INT_MAX)];
  foreach(['request_id','request_key'] as $receipt)if(array_key_exists($receipt,$in))$bridge[$receipt]=$in[$receipt];
  $result=alphano_session_control_action($uid,(string)$u['chat'],$mode==='human'?'takeover':'ai',$bridge);
  echo ai_quest_json(['ok'=>true,...$result]);exit;
 }
 $public=($method==='GET'&&$action==='state')||($method==='POST'&&$action==='join');
 if(!$public&&!$admin)throw new DomainException('Gli strumenti tecnici IA sono riservati agli amministratori. Usa la regia della sessione.',403);
 $allowed=$method==='GET'?['state','overview','npc','quest']:['join','npc_save','give_item','return_item','slot','knowledge_save','conductor','control','policy','cast_state','skip','approve','reverse','retry'];
 if(!in_array($action,$allowed,true))throw new DomainException('Azione non disponibile.',404);
 if($method==='POST'){$guard=alphano_session_guard($u['chat']);if(!(int)$db->query('SELECT GET_LOCK(?,5) acquired',[$guard])[0]['acquired'])throw new DomainException('Regia occupata, riprova.',409);register_shutdown_function(static function()use($db,$guard){$db->query('SELECT RELEASE_LOCK(?)',[$guard]);});}
 if($action==='state'&&$method==='GET'){echo ai_quest_json(['quest'=>ai_quest_public($u['chat'],$uid)]);exit;}
 if($action==='join'&&$method==='POST'){
  $db->beginTransaction();$db->query('SELECT chat_key FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$u['chat']]);$q=ai_quest_row($u['chat'],true);if(!$q||!in_array($q['mode'],['auto','human'],true))throw new DomainException('Nessuna quest IA a cui unirsi.',409);$sid=(int)$q['session_id'];$g=ai_game_guidance($sid);$old=$db->query('SELECT * FROM ai_quest_cast WHERE session_id=? AND kind=\'pg\' AND actor_id=? FOR UPDATE',[$sid,$uid])[0]??null;
  if(empty($old['active'])){$state=json_decode($old['state']??'{}',true)?:[];$state['join_round']=(int)$g['round_number']+1;$db->execute('INSERT INTO ai_quest_cast(session_id,kind,actor_id,state) VALUES(?,\'pg\',?,?) ON DUPLICATE KEY UPDATE state=VALUES(state)',[$sid,$uid,ai_quest_json($state)]);$s=$db->query('SELECT partecipanti FROM sessioni WHERE id=? FOR UPDATE',[$sid])[0];$list=array_filter(explode(';',$s['partecipanti']));$list[]=(string)$uid;$db->execute('UPDATE sessioni SET partecipanti=? WHERE id=?',[';'.implode(';',array_unique($list)).';',$sid]);ai_quest_audit($sid,'join_next_round',['player'=>$uid,'round'=>$state['join_round']],$uid);}
  $db->commit();echo ai_quest_json(['ok'=>true,'notice'=>'Parteciperai dal prossimo giro.']);exit;
 }
 if($method==='GET'){
  if($action==='overview'){echo ai_quest_json(['npcs'=>$admin?$db->query('SELECT n.id,n.name,n.chat_key,m.title AS location FROM ai_npcs n LEFT JOIN mappe m ON m.chat_key=n.chat_key COLLATE utf8mb4_unicode_ci AND m.chat=1 ORDER BY n.name'):[],'special_races'=>$admin?ai_npc_super_races():[],'knowledge'=>$db->query('SELECT * FROM ai_master_knowledge ORDER BY origin,title'),'quests'=>$db->query("SELECT q.session_id,q.chat_key,q.status,q.mode,s.nome,g.round_number,g.paused FROM ai_quests q JOIN sessioni s ON s.id=q.session_id JOIN ai_session_guidance g ON g.session_id=q.session_id ORDER BY s.run DESC,q.session_id DESC LIMIT 50")]);exit;}
  if($action==='npc'&&$admin){$id=ai_integer($in,'npc_id',1,2147483647);$n=$db->query('SELECT id,name,chat_key FROM ai_npcs WHERE id=?',[$id])[0]??null;if(!$n)throw new DomainException('PNG non trovato.',404);$row=$db->query('SELECT version FROM ai_npc_game WHERE npc_id=?',[$id])[0]??['version'=>0];$settings=ai_npc_game($id);$sheet=rpg_sheet('npc',$id,false);$capabilities=[];foreach($settings['super']['target_race_ids'] as $race)$capabilities[(int)$race]=ai_npc_super_capability($id,(int)$race);echo ai_quest_json(['npc'=>$n,'settings'=>$settings,'version'=>(int)$row['version'],'inventory'=>ai_game_inventory('npc',$id),'my_inventory'=>ai_game_inventory('pg',$uid),'combat_sheet'=>['stats'=>$sheet['stats'],'characteristics'=>$sheet['characteristics'],'xp'=>$sheet['xp'],'abilities'=>$sheet['abilities']],'super_capabilities'=>$capabilities]);exit;}
  if($action==='quest'){
   $sid=ai_integer($in,'quest_id',1,PHP_INT_MAX);$s=$db->query('SELECT * FROM sessioni WHERE id=?',[$sid])[0]??null;if(!$s||(!$admin&&$s['chat']!==$u['chat']))throw new DomainException('Quest non disponibile.',403);
   echo ai_quest_json(['session'=>$s,'quest'=>$db->query('SELECT * FROM ai_quests WHERE session_id=?',[$sid])[0]??null,'guidance'=>ai_game_guidance($sid),'cast'=>$db->query('SELECT * FROM ai_quest_cast WHERE session_id=?',[$sid]),'actions'=>$db->query('SELECT * FROM ai_quest_actions WHERE session_id=? ORDER BY id DESC LIMIT 100',[$sid]),'verdicts'=>$db->query('SELECT * FROM ai_quest_verdicts WHERE session_id=? ORDER BY id DESC LIMIT 30',[$sid]),'effects'=>$db->query('SELECT e.* FROM ai_quest_effects e JOIN ai_quest_verdicts v ON v.id=e.verdict_id WHERE v.session_id=? ORDER BY e.id DESC LIMIT 100',[$sid]),'audit'=>$db->query('SELECT * FROM ai_quest_audit WHERE session_id=? ORDER BY id DESC LIMIT 100',[$sid])]);exit;
  }throw new DomainException('Azione non disponibile.',404);
 }
 $reason=ai_field($in,'reason',500,true);$db->beginTransaction();
 if(in_array($action,['npc_save','give_item','return_item','slot','knowledge_save'],true)){
  if(!$admin)throw new DomainException('Configurazione riservata agli amministratori.',403);
  if($action==='knowledge_save'){
   $id=ai_integer($in,'id',0,PHP_INT_MAX);$v=ai_knowledge_validate($in['knowledge']??[]);$old=$id?($db->query('SELECT * FROM ai_master_knowledge WHERE id=? FOR UPDATE',[$id])[0]??null):null;if($id&&!$old)throw new DomainException('Documento non trovato.',404);if($id&&(int)$old['version']!==ai_integer($in,'version',1,PHP_INT_MAX))throw new DomainException('Documento modificato altrove: ricarica.',409);
   $args=[$v['title'],$v['topic'],$v['origin'],$v['source'],$v['summary'],ai_quest_json($v['mechanics']),$v['active'],$uid,time()];
   if($id)$db->execute('UPDATE ai_master_knowledge SET title=?,topic=?,origin=?,source=?,summary=?,mechanics=?,active=?,updated_by=?,updated_at=?,version=version+1 WHERE id=?',[...$args,$id]);else{$db->execute('INSERT INTO ai_master_knowledge(title,topic,origin,source,summary,mechanics,active,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',$args);$id=(int)$db->lastInsertId();}ai_quest_audit(0,'knowledge_saved',['id'=>$id,'reason'=>$reason,'origin'=>$v['origin']],$uid);
  }else{
   $id=ai_integer($in,'npc_id',1,2147483647);$n=$db->query('SELECT id,chat_key FROM ai_npcs WHERE id=?',[$id])[0]??null;if(!$n)throw new DomainException('PNG non trovato.',404);
   $db->query('SELECT chat_key FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$n['chat_key']]);$db->query('SELECT id FROM ai_npcs WHERE id=? FOR UPDATE',[$id]);
   if($action==='npc_save'){$old=$db->query('SELECT * FROM ai_npc_game WHERE npc_id=? FOR UPDATE',[$id])[0]??null;if((int)($old['version']??0)!==ai_integer($in,'version',0,PHP_INT_MAX))throw new DomainException('PNG modificato altrove: ricarica.',409);$v=ai_npc_game_validate($in['settings']??[],$id);$db->execute('INSERT INTO ai_npc_game(npc_id,settings,updated_by,updated_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),version=version+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$id,ai_quest_json($v),$uid,time()]);ai_quest_audit(0,'npc_configured',['npc'=>$id,'before'=>$old,'after'=>$v,'reason'=>$reason],$uid);
   }elseif($action==='slot'){ai_npc_item_slot($id,ai_integer($in,'instance',1,PHP_INT_MAX),ai_field($in,'slot',30));}
   else{
    $instance=ai_integer($in,'instance',1,PHP_INT_MAX);$key=ai_field($in,'request_id',80,true);if(alphano_operation_once('npc-inventory',$uid,$key,[$id,$instance,$action])){$from=$action==='give_item'?$uid:ai_npc_owner($id);$to=$action==='give_item'?ai_npc_owner($id):$uid;$item=$db->query('SELECT * FROM equippg WHERE id=? AND idpg=? FOR UPDATE',[$instance,$from])[0]??null;if(!$item||$db->query('SELECT id FROM equippg WHERE idpadre=?',[$instance]))throw new DomainException('Oggetto non disponibile o contenitore non vuoto.',422);$db->execute("UPDATE equippg SET idpg=?,slot='',idpadre=0 WHERE id=?",[$to,$instance]);ai_quest_audit(0,'npc_inventory',['npc'=>$id,'instance'=>$instance,'from'=>$from,'to'=>$to,'reason'=>$reason],$uid);}
   }
   // Personality/statistics belong to this NPC. Snapshot fences refresh the
   // original pending turns without interrupting the other conversations.
   if($action!=='npc_save'){$db->execute('UPDATE ai_rooms SET version=version+1 WHERE chat_key=?',[$n['chat_key']]);$db->execute("UPDATE ai_jobs SET status='cancelled',finished_at=?,error='Dotazione aggiornata' WHERE chat_key=? AND status IN('queued','running')",[time(),$n['chat_key']]);}
  }
 }else{
  $sid=ai_integer($in,'quest_id',1,PHP_INT_MAX);$s=$db->query('SELECT * FROM sessioni WHERE id=?',[$sid])[0]??null;if(!$s||$s['chat']!==$u['chat'])throw new DomainException('Raggiungi il luogo della quest per intervenire.',403);
  $db->query('SELECT chat_key FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$s['chat']]);$q=$action==='reverse'?($db->query('SELECT * FROM ai_quests WHERE session_id=? FOR UPDATE',[$sid])[0]??null):ai_quest_row($s['chat'],true);if(!$q||(int)$q['session_id']!==$sid)throw new DomainException('Quest non attiva.',409);
  $g=ai_game_guidance($sid);$version=ai_integer($in,'version',1,PHP_INT_MAX);if($version!==(int)$q['version'])throw new DomainException('Quest aggiornata: ricarica prima di agire.',409);
  if($action==='conductor'){ai_conductor_set($q,$uid,ai_field($in,'mode',12,true));
  }elseif($action==='control'){
   $mode=ai_field($in,'mode',12,true);if(!in_array($mode,['auto','human'],true))throw new DomainException('Modalità non valida.',422);$paused=ai_integer($in,'paused',0,1);$db->execute('UPDATE ai_quests SET mode=?,conductor_id=?,version=version+1 WHERE session_id=?',[$mode,$mode==='human'?$uid:0,$sid]);$db->execute('UPDATE ai_session_guidance SET paused=?,turn_started_at=?,version=version+1,updated_by=?,updated_at=? WHERE session_id=?',[$paused,time(),$uid,time(),$sid]);
  }elseif($action==='policy'){
   if(!$admin)throw new DomainException('Solo un amministratore può assegnare regole automatiche.',403);$p=ai_knowledge_policy(ai_integer($in,'knowledge_id',0,PHP_INT_MAX));$p['mandate']=!empty($in['mandate'])?$uid:0;$db->execute('UPDATE ai_quests SET policy=?,version=version+1 WHERE session_id=?',[ai_quest_json($p),$sid]);
  }elseif($action==='cast_state'){
   $kind=ai_field($in,'kind',3,true);$id=ai_integer($in,'actor_id',1,2147483647);if(!in_array($kind,['pg','npc'],true))throw new DomainException('Partecipante non valido.',422);$cast=$db->query('SELECT * FROM ai_quest_cast WHERE session_id=? AND kind=? AND actor_id=? FOR UPDATE',[$sid,$kind,$id])[0]??null;if(!$cast)throw new DomainException('Partecipante estraneo alla quest.',422);
   $state=$in['state']??[];if(!is_array($state)||array_diff(array_keys($state),['distance','ammo','ammo_instance','loaded','last_shot_round','defence_roll','mount_instance','mount_trained','clear_space','position','terrain']))throw new DomainException('Stato tattico non valido.',422);
   foreach(['distance'=>10000,'ammo'=>10000,'last_shot_round'=>100000,'defence_roll'=>20,'ammo_instance'=>PHP_INT_MAX,'mount_instance'=>PHP_INT_MAX,'clear_space'=>10000,'loaded'=>1,'mount_trained'=>1] as $k=>$max)if(isset($state[$k]))$state[$k]=ai_integer($state,$k,0,$max);
   foreach(['position','terrain'] as $k)if(isset($state[$k]))$state[$k]=ai_field($state,$k,200);$db->execute('UPDATE ai_quest_cast SET state=? WHERE session_id=? AND kind=? AND actor_id=?',[ai_quest_json($state),$sid,$kind,$id]);$db->execute('UPDATE ai_quests SET version=version+1 WHERE session_id=?',[$sid]);
  }elseif($action==='skip'){
   $t=ai_game_turn($g);if(!$t||$q['status']!=='active')throw new DomainException('Nessun turno da saltare.',409);$db->execute("INSERT IGNORE INTO ai_quest_actions(session_id,round_number,kind,actor_id,content,details,status,created_at) VALUES(?,?,?,?,?,'{}','skipped',?)",[$sid,(int)$g['round_number'],$t['kind'],(int)$t['id'],$reason,time()]);ai_quest_advance($q);
  }elseif($action==='approve'){
   $v=ai_integer($in,'verdict_id',1,PHP_INT_MAX);if(!$db->query('SELECT id FROM ai_quest_verdicts WHERE id=? AND session_id=?',[$v,$sid]))throw new DomainException('Verdetto estraneo alla quest.',422);
   $changes=$in['changes']??null;if($changes!==null&&!is_array($changes))throw new DomainException('Conseguenze non valide.',422);
   if(isset($in['narrative'])){$text=ai_field($in,'narrative',3500,true);$db->execute('UPDATE ai_quest_verdicts SET narrative=? WHERE id=? AND status=\'review\'',[$text,$v]);}
   ai_quest_apply_verdict($v,$uid,$reason,$changes,($in['confirmation']??'')==='CONFERMO');
  }elseif($action==='reverse'){
   if(!$admin)throw new DomainException('Ripristino riservato agli amministratori.',403);$effect=ai_integer($in,'effect_id',1,PHP_INT_MAX);if(!$db->query('SELECT e.id FROM ai_quest_effects e JOIN ai_quest_verdicts v ON v.id=e.verdict_id WHERE e.id=? AND v.session_id=?',[$effect,$sid]))throw new DomainException('Conseguenza estranea alla quest.',422);ai_quest_reverse_effect($effect,$uid,$reason);$db->execute('UPDATE ai_quests SET version=version+1 WHERE session_id=?',[$sid]);
  }elseif($action==='retry'){
   $db->execute("UPDATE ai_jobs SET status='cancelled',finished_at=?,error='Verdetto rigenerato dal Master' WHERE session_id=? AND status IN('queued','running')",[time(),$sid]);
   $v=$db->query("SELECT * FROM ai_quest_verdicts WHERE session_id=? AND round_number=? AND status='review' FOR UPDATE",[$sid,(int)$g['round_number']])[0]??null;if($v){$db->execute("UPDATE ai_quest_verdicts SET status='superseded' WHERE id=?",[(int)$v['id']]);ai_quest_audit($sid,'verdict_superseded',['previous'=>$v,'reason'=>$reason],$uid);$db->execute('DELETE FROM ai_quest_verdicts WHERE id=?',[(int)$v['id']]);}
   $db->execute("UPDATE ai_quests SET status=?,version=version+1 WHERE session_id=?",[(int)$g['turn_index']>=count(json_decode($g['turn_order'],true))?'resolving':'active',$sid]);
   $fresh=ai_quest_row($s['chat'],true);if($fresh['status']==='resolving')ai_quest_enqueue($fresh,'verdict',0,0,ai_quest_job_key($fresh,'verdict'),['round'=>(int)$g['round_number']]);else ai_quest_schedule($fresh);
  }else throw new DomainException('Azione non riconosciuta.',422);
  ai_quest_audit($sid,$action,['reason'=>$reason,'details'=>array_diff_key($in,array_flip(['action','reason','_csrf']))],$uid);
  if(in_array($action,['control','policy','cast_state','reverse'],true))$db->execute("UPDATE ai_jobs SET status='cancelled',finished_at=?,error='Intervento del Master: stato cambiato' WHERE session_id=? AND status IN('queued','running')",[time(),$sid]);
 }
 $db->commit();echo ai_quest_json(['ok'=>true]);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$value=(int)$e->getCode();$code=($e instanceof DomainException||($e instanceof RuntimeException&&in_array($value,[400,409,422],true)))?$value:($e instanceof JsonException?400:500);if($code<400||$code>499)$code=500;if($code===500)error_log('[Motore Master] '.$e->getMessage());http_response_code($code);echo json_encode(['error'=>$code===500?'Operazione non completata. Nessuna modifica parziale applicata.':$e->getMessage()],JSON_UNESCAPED_UNICODE);}
