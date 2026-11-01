<?php
declare(strict_types=1);
function ai_scene_rules():array {return rpg_combat_settings();}
function ai_conductor_can(int $uid,string $chat):bool{
 $u=ai_db()->query('SELECT admin,fatepower,chat,bloccato FROM utenti WHERE id=?',[$uid])[0]??[];
 return $u&&!$u['bloccato']&&$u['chat']===$chat&&($u['admin']||$u['fatepower']);
}
function ai_turn_window(array $q,array $g,int $viewer):array{
 $t=ai_game_turn($g);$start=(int)($g['turn_started_at']??0)?:((int)($g['updated_at']??0)?:time());$deadline=$start+ai_scene_rules()['turn_seconds'];
 $active=($q['mode']??'')==='auto'&&$q['status']==='active'&&!$g['paused']&&($t['kind']??'')==='pg'&&(int)($t['id']??0)!==(int)($q['conductor_id']??0);
 return ['active'=>$active,'server_time'=>time(),'deadline'=>$active?$deadline:null,'remaining'=>$active?max(0,$deadline-time()):null,'mine'=>$active&&(int)$t['id']===$viewer,'key'=>$q['session_id'].':'.$g['round_number'].':'.$g['turn_index']];
}
function ai_turn_expire(array $q,array $g):bool{
 $w=ai_turn_window($q,$g,0);if(!$w['active']||$w['remaining']>0)return false;$db=ai_db();$sid=(int)$q['session_id'];$t=ai_game_turn($g);
 $insert=$db->execute("INSERT IGNORE INTO ai_quest_actions(session_id,round_number,kind,actor_id,content,details,status,created_at) VALUES(?,?, 'pg',?,?,'{}','skipped',?)",[$sid,(int)$g['round_number'],(int)$t['id'],'Turno scaduto: nessuna dichiarazione entro il tempo concesso',time()]);
 if($insert){ai_quest_publish($sid,'quest:'.$sid.':timeout:'.$g['round_number'].':'.$t['id'],ai_quest_name('pg',(int)$t['id']).' non agisce entro il tempo concesso e perde questo turno.');ai_quest_audit($sid,'turn_expired',['player'=>(int)$t['id'],'round'=>(int)$g['round_number']]);if((int)$g['round_number']>1){$prior=$db->query("SELECT status,content FROM ai_quest_actions WHERE session_id=? AND round_number=? AND kind='pg' AND actor_id=?",[$sid,(int)$g['round_number']-1,(int)$t['id']])[0]??[];if(($prior['status']??'')==='skipped'&&str_starts_with($prior['content'],'Turno scaduto')){$db->execute("UPDATE ai_quest_cast SET active=0 WHERE session_id=? AND kind='pg' AND actor_id=?",[$sid,(int)$t['id']]);$db->execute("UPDATE ai_quest_cast SET state=JSON_SET(state,'$.withdrew',true) WHERE session_id=? AND kind='pg' AND actor_id=?",[$sid,(int)$t['id']]);if(!(int)$db->query("SELECT COUNT(*) n FROM ai_quest_cast WHERE session_id=? AND kind='pg' AND active=1",[$sid])[0]['n']){ai_scene_close($sid,'I partecipanti hanno lasciato trascorrere due turni consecutivi senza agire. La scena viene sospesa e archiviata senza nuovi colpi automatici.');return true;}}}ai_quest_advance($q);}return (bool)$insert;
}
function ai_quest_tick(string $chat):void{
 $q=ai_quest_row($chat);if(!$q)return;$g=ai_game_guidance((int)$q['session_id']);$w=ai_turn_window($q,$g,0);if(!$w['active']||$w['remaining']>0)return;
 $db=ai_db();$own=!$db->inTransaction();if($own)$db->beginTransaction();try{$db->query('SELECT chat_key FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$chat]);$q=ai_quest_row($chat,true);if($q)ai_turn_expire($q,ai_game_guidance((int)$q['session_id']));if($own)$db->commit();}catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
}
function ai_quest_publish_queue(int $sid,string $key):void{
 $q=ai_db()->query('SELECT * FROM ai_quests WHERE session_id=?',[$sid])[0];$state=ai_quest_turn_state($q);$queue=$state['remaining'];if(!$queue)return;
 $text='Giro '.(int)ai_game_guidance($sid)['round_number'].' · ordine di gioco: '.implode(' → ',array_column($queue,'name')).'. Tocca a '.$queue[0]['name'].'.';
 if(($q['mode']??'')==='auto')$text.=' Tempo a disposizione: '.(int)ceil(ai_scene_rules()['turn_seconds']/60).' minuti.';
 ai_quest_publish($sid,$key,$text);
}
function ai_conductor_set(array $q,int $uid,string $mode):void{
 if(!in_array($mode,['human','auto'],true)||!ai_conductor_can($uid,$q['chat_key']))throw new DomainException('Regia riservata a un Master presente.',403);
 $db=ai_db();$sid=(int)$q['session_id'];if($q['mode']==='human'&&(int)$q['conductor_id']!==$uid){$u=$db->query('SELECT admin FROM utenti WHERE id=?',[$uid])[0];if(!$u['admin'])throw new DomainException('La sessione è già diretta da un altro Master.',403);}
 $db->execute('UPDATE ai_quests SET mode=?,conductor_id=?,version=version+1 WHERE session_id=?',[$mode,$mode==='human'?$uid:0,$sid]);
 $db->execute('UPDATE ai_session_guidance SET paused=0,turn_started_at=?,version=version+1,updated_by=?,updated_at=? WHERE session_id=?',[time(),$uid,time(),$sid]);
 $db->execute("UPDATE ai_jobs SET status='cancelled',finished_at=?,error='Cambio di regia' WHERE session_id=? AND status IN('queued','running')",[time(),$sid]);
 ai_quest_audit($sid,'conductor_changed',['mode'=>$mode],$uid);$fresh=ai_quest_row($q['chat_key'],true);
 if($mode==='auto'&&in_array($fresh['status'],['review','resolving','attention'],true)){
  $g=ai_game_guidance($sid);$v=$db->query('SELECT id,status FROM ai_quest_verdicts WHERE session_id=? AND round_number=?',[$sid,(int)$g['round_number']]);
  if($v&&in_array($v[0]['status'],['pending','review'],true)){ai_quest_audit($sid,'verdict_superseded',['previous'=>$db->query('SELECT * FROM ai_quest_verdicts WHERE id=?',[(int)$v[0]['id']])[0],'reason'=>'Ritorno alla regia automatica'],$uid);$db->execute('DELETE FROM ai_quest_verdicts WHERE id=?',[(int)$v[0]['id']]);$v=[];}
  if(!$v){$db->execute("UPDATE ai_quests SET status='resolving' WHERE session_id=?",[$sid]);ai_quest_enqueue($fresh,'verdict',0,0,ai_quest_job_key($fresh,'verdict'),['round'=>(int)$g['round_number']]);}
 }else ai_quest_schedule($fresh);
 ai_quest_publish_queue($sid,'quest:'.$sid.':conductor:'.$fresh['version']);
}
function ai_conductor_verdict(array $q,int $source,int $uid,string $text):bool{
 if((int)$q['conductor_id']!==$uid||!ai_conductor_can($uid,$q['chat_key']))throw new DomainException('Solo il Master che dirige la quest può scriverne il verdetto.',403);
 if($q['status']!=='review')return true; // Mid-round directions do not silently erase pending player turns.
 $db=ai_db();$sid=(int)$q['session_id'];$g=ai_game_guidance($sid);
 $db->execute("INSERT IGNORE INTO ai_quest_verdicts(session_id,round_number,status,snapshot,payload,technical,narrative,created_at,reviewed_by) VALUES(?,?,'human',?,'[]','{}',?,?,?)",[$sid,(int)$g['round_number'],ai_quest_snapshot($sid),ai_text(ltrim($text,'# '),3500),time(),$uid]);
 $db->execute("UPDATE ai_quest_verdicts SET status='human',narrative=?,reviewed_by=? WHERE session_id=? AND round_number=? AND status IN('pending','review','narrative')",[ai_text(ltrim($text,'# '),3500),$uid,$sid,(int)$g['round_number']]);
 ai_quest_audit($sid,'human_verdict',['source'=>$source,'round'=>(int)$g['round_number']],$uid);ai_quest_next_round($sid,$uid);return true;
}
