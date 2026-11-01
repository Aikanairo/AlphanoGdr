<?php
declare(strict_types=1);
require_once __DIR__.'/ai-scheduler.inc.php';
/** Autonomous recovery is optional; human ownership and explicit pauses always win. */
function ai_quest_auto_continue(array $quest,?array $room=null):bool{
 $room??=ai_room($quest['chat_key']);$policy=json_decode($quest['policy']??'{}',true)?:[];
 return $quest['mode']==='auto'&&!empty($room['enabled'])&&!empty($room['quest_auto_continue'])&&($policy['review_mode']??'narrative')!=='human'&&!ai_game_guidance((int)$quest['session_id'])['paused'];
}
/** Retain the technical review in the ledger; do not execute even one proposed effect. */
function ai_quest_continue_narratively(int $vid):bool{
 $db=ai_db();if(!$db->inTransaction())throw new LogicException('Prosecuzione fuori transazione.');
 $v=$db->query('SELECT * FROM ai_quest_verdicts WHERE id=? FOR UPDATE',[$vid])[0]??null;if(!$v||$v['status']!=='review')return false;
 $sid=(int)$v['session_id'];$q=$db->query('SELECT * FROM ai_quests WHERE session_id=? FOR UPDATE',[$sid])[0];$g=ai_game_guidance($sid);
 if($q['status']!=='review'||!ai_quest_auto_continue($q)||(int)$v['round_number']!==(int)$g['round_number'])return false;
 $s=$db->query('SELECT run FROM sessioni WHERE id=? FOR UPDATE',[$sid])[0];if(!$s['run']||$db->query('SELECT id FROM ai_quest_effects WHERE verdict_id=?',[$vid]))return false;
 $order=json_decode($g['turn_order'],true)?:[];if((int)$g['turn_index']<count($order))return false;
 $db->execute("UPDATE ai_quest_verdicts SET status='narrative' WHERE id=?",[$vid]);
 ai_quest_publish($sid,'verdict:'.$vid.':narrative','Giro '.$v['round_number'].' concluso in modalità narrativa. Le azioni restano tentativi: nessun colpo, presa, ferita o contagio è confermato. Gli esiti che richiedono verifica restano nel registro del Master, senza modifiche alle schede. Si può proseguire con il prossimo giro.');
 ai_quest_audit($sid,'narrative_continuation',['verdict'=>$vid,'round'=>(int)$v['round_number'],'effects_applied'=>0]);
 $room=ai_room($q['chat_key']);ai_quest_next_round($sid,0,!empty($room['quest_auto_close'])&&ai_scene_can_finish($sid));return true;
}
/** Estimates cover actual queued work, never a player turn or an intentional human hold. */
function ai_quest_progress(array $q,array $g):array{
 if($g['paused']||$q['mode']!=='auto')return ['state'=>'human','label'=>'Regia affidata al Master: nessun conto alla rovescia automatico.'];
 $db=ai_db();$job=$db->query("SELECT id,kind,status,created_at,started_at,available_at,attempts,chat_key,user_id,source_id FROM ai_jobs WHERE session_id=? AND status IN('queued','running') ORDER BY id LIMIT 1",[(int)$q['session_id']])[0]??null;
 if(!$job){
  if(in_array($q['status'],['review','attention','resolving'],true))return ai_quest_auto_continue($q)?['state'=>'recovering','label'=>'Il Master sta recuperando il giro: le azioni sono conservate.']:['state'=>'human','label'=>'Serve una verifica del Master umano; non è una risposta IA ancora in coda.'];
  return ['state'=>'player','label'=>''];
 }
 $worker=$db->query('SELECT * FROM ai_worker WHERE id=1')[0]??null;$budget=ai_worker_job_budget($job['kind']);
 if(!$worker||$worker['state']==='stopped'||time()-(int)$worker['heartbeat']>max(30,$budget+30))return ['state'=>'unavailable','label'=>'Regia temporaneamente indisponibile: le azioni sono conservate. La coda riprenderà quando il servizio sarà operativo.'];
 $durations=array_map(static fn($r)=>(int)$r['seconds'],$db->query("SELECT finished_at-started_at seconds FROM ai_jobs WHERE kind=? AND status='done' AND started_at>0 AND finished_at>started_at AND finished_at-started_at BETWEEN 10 AND 240 ORDER BY id DESC LIMIT 15",[$job['kind']]));
 sort($durations);$base=$durations?$durations[intdiv(count($durations),2)]:($job['kind']==='verdict'?75:55);$base=max(10,min($budget,$base));
 $capacity=max(1,min(2,(int)($worker['pool_slots']??1)));$groups=[];$running=0;$busy=[];
 $group=static fn($j)=>$j['chat_key'].':'.$j['user_id'].':'.((int)$j['source_id']>0?'source:'.$j['source_id']:'job:'.$j['id']);
 $ownGroup=$group($job);
 foreach($db->query("SELECT id,kind,status,started_at,chat_key,user_id,source_id FROM ai_jobs WHERE status IN('queued','running') AND kind IN('play','npc-turn','verdict') ORDER BY id LIMIT 256") as $other){
  $key=$group($other);$groups[$key]=true;
  if($other['status']==='running'){$running++;if((int)$other['id']!==(int)$job['id'])$busy[]=max(5,min($base,ai_worker_job_budget($other['kind'])-max(0,time()-(int)$other['started_at'])));}
 }
 $others=count($groups)-(isset($groups[$ownGroup])?1:0);
 // Fair scheduling rotates conversation groups. Global FIFO IDs no longer say
 // who is ahead, so these are deliberately broad estimates of queue waves.
 $wait=$job['status']==='running'?0:(int)(ceil(max(0,$others-count($busy))/$capacity)*$base+(($busy&&count($busy)>=$capacity)?min($busy):0));
 $wait+=max(0,(int)$job['available_at']-time());
 $elapsed=$job['status']==='running'?max(0,time()-(int)$job['started_at']):0;
 $late=$elapsed>min($budget,(int)ceil($base*1.5));$remaining=max(5,$base-$elapsed);$min=(int)(ceil(max(10,($wait+$remaining)*0.5)/10)*10);$max=(int)(ceil(max($min+10,($wait+$remaining)*1.8)/10)*10);
 $format=static fn($seconds)=>$seconds<60?$seconds.' s':str_replace('.',',',(string)round($seconds/60,1)).' min';
 $label=($job['kind']==='verdict'?'Verdetto del Master':'Turno del PNG').($job['status']==='queued'?' in coda equa':' in elaborazione').': circa '.$format($min).'–'.$format($max).'. Stima ampia: '.$capacity.' '.($capacity===1?'risposta simultanea':'risposte simultanee').', '.count($groups).' gruppi attivi; gli altri dialoghi proseguono.';
 if($job['status']==='queued'&&(int)$job['attempts']>0)$label='Nuovo tentativo in attesa. '.$label;
 if($late)$label='La risposta richiede più tempo del previsto. Questo tentativo ha un limite di '.$budget.' secondi, poi viene recuperato senza fermare gli altri dialoghi.';
 return ['state'=>$late?'delayed':$job['status'],'label'=>$label,'eta_min_seconds'=>$late?null:$min,'eta_max_seconds'=>$late?null:$max,'approximate'=>true,'ahead'=>max(0,$others),'capacity'=>$capacity,'active_groups'=>count($groups),'running'=>$running,'attempt'=>max(1,(int)$job['attempts'])];
}
