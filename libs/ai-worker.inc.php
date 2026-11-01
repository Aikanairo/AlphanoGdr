<?php
declare(strict_types=1);
require_once __DIR__ . '/ai.inc.php';
require_once __DIR__.'/lycan.inc.php';
require_once __DIR__.'/ai-scheduler.inc.php';

function ai_heartbeat(string $state, string $error=''): void {
    if($state==='idle'&&(ai_db()->query("SELECT id FROM ai_jobs WHERE status='running' LIMIT 1")||ai_db()->query("SELECT job_id FROM ai_social_jobs WHERE status='running' LIMIT 1")))$state='generating';
    ai_db()->execute('INSERT INTO ai_worker(id,heartbeat,state,last_error) VALUES(1,?,?,?) ON DUPLICATE KEY UPDATE heartbeat=VALUES(heartbeat),state=VALUES(state),last_error=VALUES(last_error)',[time(),$state,mb_substr($error,0,250)]);
}
/** Diario su file riconciliabile: un crash dopo il commit non duplica la riga alla ripartenza. */
function ai_sync_logs(bool $force=false): void {
    static $last=0;
    if (!$force && time()-$last<30) return;
    $last=time();
    ai_perception_sync_prefaces();
    foreach (ai_db()->query("SELECT id,result,npc_id,session_id,finished_at FROM ai_jobs WHERE status='done' AND kind IN('play','reaction') AND log_synced=0 ORDER BY id LIMIT 20") as $job) {
        if ((int)$job['session_id']>0) {
            $path=htmlroot.'/sessione/log/'.(int)$job['session_id'].'.html';
            $file=@fopen($path,'c+');
            if (!$file) { error_log('[Master IA] Diario di sessione non scrivibile.');continue; }
            try {
                if (!flock($file,LOCK_EX)) continue;
                $marker='<!-- master-ia:'.(int)$job['id'].' -->';
                $found=false;
                while (($line=fgets($file))!==false) if (str_contains($line,$marker)) { $found=true;break; }
                if (!$found) {
                    fseek($file,0,SEEK_END);$line=$marker.ai_message_html($job)."<br>\n";
                    if (fwrite($file,$line)!==strlen($line) || !fflush($file)) throw new RuntimeException('Scrittura diario incompleta.');
                }
                flock($file,LOCK_UN);
            } catch (Throwable $e) { error_log('[Master IA diario] '.$e->getMessage());continue; }
            finally { fclose($file); }
        }
        ai_db()->execute('UPDATE ai_jobs SET log_synced=1 WHERE id=?',[(int)$job['id']]);
    }
}
/** Non-model maintenance runs once per supervisor, outside consumer transactions. */
function ai_worker_maintenance(bool $force=false):void {
    static $last=0;$now=time();if(!$force&&$now-$last<5)return;$last=$now;
    rpg_catalog(true);rpg_settings(true);companionship_release_expired();alphano_lycan_tick();
    require_once __DIR__.'/lineages.inc.php';lineage_tick(null,true);
    ai_perception_recover_pending();ai_sync_logs();ai_quest_sync_logs();ai_quest_maintenance();
    static $demoCleaned=0;if($now-$demoCleaned>=300){ai_demo_cleanup();$demoCleaned=$now;}
    ai_worker_recover();
}
/** Compatibility entry point: exactly one bounded foreground/reflection job. */
function ai_work_once():bool {
 if(!alphano_config('ai_enabled'))return false;
    ai_heartbeat('idle');ai_worker_maintenance();
    // The one-shot CLI retains its foreground-first contract. The long-running
    // supervisor, which knows its actual lanes, applies the reflection quota.
    $job=ai_worker_claim();
    if($job)return ai_worker_consume($job,(string)($job['worker_token']??''));
    if(ai_worker_social_due(false)){
        $token=ai_worker_schema_ready()?ai_worker_claim_social():'';
        if($token!==null)return ai_worker_social_consume($token);
    }
    return false;
}
/** The per-job advisory lock is a publication fence across supervisor crashes. */
function ai_worker_consume(array $job,string $token=''):bool {
    $lock=ai_worker_lock('job',(int)$job['id']);if(!ai_worker_take_lock($lock))return false;
    try{
        $current=ai_db()->query('SELECT * FROM ai_jobs WHERE id=?',[(int)$job['id']])[0]??null;
        if(!$current||$current['status']!=='running')return false;
        if(ai_worker_schema_ready()&&!hash_equals((string)$current['worker_token'],$token))return false;
        // ai_work_job expects the attempt count before this claim, as before.
        $current['attempts']=max(0,(int)$current['attempts']-1);
        return ai_work_job($current);
    }finally{
        if(ai_worker_schema_ready())ai_db()->execute('UPDATE ai_jobs SET lease_until=0 WHERE id=? AND worker_token=?',[(int)$job['id'],$token]);
        ai_worker_release_lock($lock);
    }
}
/** Model calls happen outside transactions and have a category-specific budget. */
function ai_work_job(array $job):bool {
    $db=ai_db();ai_runtime_budget(ai_worker_job_budget((string)$job['kind']));
    try {
        if($job['kind']==='reaction')return ai_perception_work_job($job);
        if(in_array($job['kind'],['observe','npc-turn','verdict'],true))return ai_quest_work_job($job);
        $room=ai_room($job['chat_key']);
        // Demo scenes are independent copies: a live appointment cannot change their cast.
        $npc=in_array($job['kind'],['preview','demo'],true)?($db->query('SELECT * FROM ai_npcs WHERE id=? AND chat_key=?',[(int)$job['npc_id'],$job['chat_key']])[0]??null):ai_dialogue_npc($job['chat_key'],(int)$job['npc_id'],(int)$job['user_id']);
        if (!$room || !$npc || (int)$room['version']!==(int)$job['room_version'] || ($job['kind']!=='preview' && (!(int)$room['enabled'] || !(int)$npc['active']))) throw new DomainException('Configurazione cambiata o PNG disattivato.');
        ai_dialogue_room($job['chat_key'],(int)$job['user_id']);
        if((int)ai_npc_profile($npc)['hit_points']<=0)throw new DomainException('Il PNG non è in condizione di agire.');
        if($job['kind']==='play'){
            foreach(ai_dialogue_jobs($job) as $previous)if((int)$previous['id']<(int)$job['id']&&$previous['status']!=='done')throw new DomainException('Dialogo interrotto: il turno precedente non è stato pubblicato.');
            ai_game_allowed($job['chat_key'],(int)$job['npc_id']);
            $job['episode']=ai_episode_prepare($npc,$job);
        }
        if(in_array($job['kind'],['play','preview'],true)){$snapshot=ai_game_snapshot($job,$npc);$db->execute('UPDATE ai_jobs SET game_snapshot=? WHERE id=?',[$snapshot,(int)$job['id']]);}
        ai_heartbeat('generating');
        $result=$job['kind']==='play'&&(int)($job['attempts']??0)>=2?['testo'=>ai_language_output_guard($npc,ai_episode_fallback($npc,$job)),'fallback'=>!empty($job['episode']['known'])?'bounded_episode_after_retries':'bounded_dialogue_after_retries']:ai_generate($room,$npc,$job);$result['nome']=$npc['name'];
        $result=ai_situation_result($room,$npc,$job,$result);
        $result['sent_at']=time();$result['model']=ai_model_name();$profile=ai_npc_profile($npc);
        $result['character']=['id'=>(int)$npc['id'],'name'=>$npc['name'],'profile'=>array_intersect_key($profile,array_flip(['sex','race_id','ethnicity_id']))];
        $db->beginTransaction();
        // Una disattivazione/cancellazione durante l'inferenza impedisce la pubblicazione.
        $current=$db->query('SELECT enabled,version FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$job['chat_key']])[0]??null;
        $state=$db->query('SELECT status FROM ai_jobs WHERE id=? FOR UPDATE',[(int)$job['id']])[0]['status']??'';
        if (!$current || (int)$current['version']!==(int)$job['room_version'] || $state!=='running' || ($job['kind']!=='preview' && !(int)$current['enabled'])) throw new DomainException('Richiesta annullata durante la generazione.');
        if($job['kind']==='play'&&!ai_dialogue_npc($job['chat_key'],(int)$job['npc_id'],(int)$job['user_id']))throw new DomainException('La visita o la disponibilità del PNG sono cambiate.');
        if(in_array($job['kind'],['play','preview'],true)&&$snapshot!==ai_game_snapshot($job))throw new AiContextChanged('Contesto aggiornato durante la risposta: rigenerazione automatica sul messaggio già salvato.');
        $encoded=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $messageId=0;$sessionId=0;
        if ($job['kind']==='play') {
            $html=ai_message_html([...$job,'result'=>$encoded],$npc['name']);
            $db->execute('INSERT INTO chat(pg,chat,ora,msg,dest,lican) VALUES(0,?,?,?,0,0)',[$job['chat_key'],time(),$html]);
            $messageId=(int)$db->lastInsertId();
            // Archivio e registrazioni partecipano allo stesso commit dell'output e della memoria.
            $db->execute('INSERT INTO registrazionichat(registrazione,testo) SELECT id,? FROM registrazioni WHERE chat=?',[$html,$job['chat_key']]);
            $sessionId=(int)($db->query("SELECT id FROM sessioni WHERE chat=? AND run='1' LIMIT 1",[$job['chat_key']])[0]['id']??0);
            $db->execute('INSERT INTO ai_events(chat_key,npc_id,job_id,speaker,content,created_at) VALUES(?,?,?,?,?,?)',[$job['chat_key'],(int)$npc['id'],(int)$job['id'],$npc['name'],$result['testo'],time()]);
            ai_social_record($npc,$job,$result);
            ai_episode_consolidate($npc,$job,$result);
            $chronicle='Giocatore: '.ai_text($job['prompt'],250)."\n".$npc['name'].': '.ai_text($result['testo'],500);
            if(!str_starts_with($job['chat_key'],'rent_'))foreach ([0,(int)$npc['id']] as $memoryNpc) ai_append_memory($job['chat_key'],$memoryNpc,$chronicle);
        }
        if($job['kind']==='demo'){
            $visit=$db->query('SELECT v.id FROM ai_demo_visits v JOIN ai_demo_turns t ON t.visit_id=v.id WHERE t.id=? AND t.job_id=? AND v.expires_at>? FOR UPDATE',[(int)$job['source_id'],(int)$job['id'],time()]);
            if(!$visit)throw new DomainException('La sessione demo è scaduta.');
            $db->execute('UPDATE ai_demo_turns SET result=? WHERE id=? AND job_id=?',[$result['testo'],(int)$job['source_id'],(int)$job['id']]);
        }
        $db->execute("UPDATE ai_jobs SET status='done',result=?,message_id=?,session_id=?,error=NULL,finished_at=? WHERE id=?",[$encoded,$messageId,$sessionId,time(),(int)$job['id']]);
        $db->commit();ai_heartbeat('idle');ai_sync_logs(true);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        // The synchronous physical preface is already a real public reaction.
        // One unavailable/invalid elaboration must not delay it or retry for minutes.
        if($job['kind']==='reaction'&&!($e instanceof DomainException)&&ai_perception_complete_fallback($job,$e))return true;
        // A failed generation must not strand an autonomous quest. The fallback uses
        // backend intents only, rechecks publication locks and never applies effects.
        // A rejected draft gets one grounded correction; outages still recover immediately.
        $rewrite=($e instanceof RuntimeException&&!($e instanceof DomainException))?ai_expression_retry_hint($e->getMessage()):null;
        $retryDraft=$job['kind']==='npc-turn'&&(int)($job['attempts']??0)<1&&$rewrite!==null;
        // A legitimate edit requires a fresh interpretation of the saved turn,
        // not the offline recovery used when the narrative engine is unavailable.
        if(!$retryDraft&&!($e instanceof DomainException)&&!($e instanceof AiContextChanged)&&in_array($job['kind'],['npc-turn','verdict'],true)){
            $quest=ai_quest_row($job['chat_key']);
            if($quest&&ai_quest_auto_continue($quest))try{return ai_quest_work_job($job,true);}catch(Throwable $recovery){
                if($db->inTransaction())$db->rollBack();$e=$recovery;
            }
        }
        $cancel=$e instanceof DomainException;
        $attempts=(int)$job['attempts']+1;
        $status=$cancel?'cancelled':($attempts>=3?'failed':'queued');
        $notice=$cancel?$e->getMessage():($status==='queued'?($e instanceof AiContextChanged?$e->getMessage():($rewrite!==null?'Riscrittura necessaria: '.$rewrite:'Motore non disponibile o risposta non valida. Nuovo tentativo automatico.')):'Il motore non ha completato la risposta dopo tre tentativi. Il messaggio originale resta conservato.');
        $db->execute("UPDATE ai_jobs SET status=?,error=?,available_at=?,finished_at=? WHERE id=? AND status='running'",[$status,$notice,time()+($attempts===1?10:30),$status==='queued'?0:time(),(int)$job['id']]);
        ai_heartbeat('waiting',$e->getMessage());error_log('[Master IA job '.(int)$job['id'].'] '.$e->getMessage());
    } finally { ai_runtime_budget(0); }
    return true;
}
