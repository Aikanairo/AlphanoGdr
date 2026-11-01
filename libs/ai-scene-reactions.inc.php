<?php
declare(strict_types=1);

/** Normalize only revelations already receipted by the authoritative scene ledger. */
function ai_perception_revelation_event(array $event,int $npc):?array {
    if(($event['kind']??'')==='transformation')return in_array((int)($event['native_race_id']??0),[2,3,5,6],true)&&(int)($event['actor_id']??0)>0?$event:null;
    if(!in_array($event['kind']??'',['human_master','system_master'],true))return null;
    $proof=ConnectDB()->query('SELECT player_id,race_id FROM ai_nature_observations WHERE npc_id=? AND source_id=? ORDER BY id LIMIT 1',[$npc,(int)($event['source_id']??0)])[0]??null;
    return $proof?[...$event,'actor_id'=>(int)$proof['player_id'],'native_race_id'=>(int)$proof['race_id'],'visible_race_id'=>(int)$proof['race_id'],'form'=>'native']:null;
}
/** The reaction's source must still be a live, public fact witnessed by this exact NPC. */
function ai_perception_reaction_event(array $job):?array {
    if(!ai_perception_ready())return null;
    require_once __DIR__.'/chat-history.inc.php';
    $event=ConnectDB()->query('SELECT e.* FROM ai_scene_events e JOIN ai_scene_witnesses w ON w.event_id=e.id JOIN chat c ON c.id=e.source_id AND BINARY c.chat=BINARY e.chat_key WHERE e.source_id=? AND w.npc_id=? AND BINARY e.chat_key=BINARY ? AND c.dest=0'.(alphano_chat_has_archive()?' AND c.history_only=0':''),[(int)($job['source_id']??0),(int)($job['npc_id']??0),(string)($job['chat_key']??'')])[0]??null;
    return $event?ai_perception_revelation_event($event,(int)$job['npc_id']):null;
}
/** Temporary physical posture lives on the already durable publication receipt. */
function ai_perception_posture(int $npc,int $player=0,int $before=0):array {
    $inactive=['active'=>false,'mode'=>'observe','source_id'=>0,'actor_id'=>0,'until'=>0];
    if($npc<1||!ai_perception_ready())return $inactive;
    $immediate="COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(result,'$.scene_reaction.immediate_message_id')) AS UNSIGNED),message_id)";
    $sql="SELECT result,source_id,user_id,created_at,finished_at FROM ai_jobs WHERE npc_id=? AND kind='reaction' AND message_id>0 AND created_at>=?";$params=[$npc,time()-180];
    if($player>0){$sql.=' AND user_id=?';$params[]=$player;}
    if($before>0){$sql.=' AND '.$immediate.'<?';$params[]=$before;}
    $sql.=' ORDER BY '.$immediate.' DESC,id DESC LIMIT 8';
    foreach(ConnectDB()->query($sql,$params) as $row){
        $present=ai_relation_context_current($npc,(int)$row['user_id']);
        if($present&&(int)$row['source_id']<=$present['chat_id'])continue;
        $receipt=json_decode((string)$row['result'],true)['scene_reaction']??[];
        $mode=(string)($receipt['mode']??'observe');$until=(int)($receipt['until']??0);
        if(!in_array($mode,['flee','freeze'],true)||$until<=time())continue;
        // Only a later, accepted and receipted reassurance by this same actor can end panic.
        if(function_exists('ai_social_reassured_since')&&ai_social_reassured_since($npc,(int)$row['user_id'],(int)$row['created_at'],(int)$row['source_id'],$before))continue;
        return ['active'=>true,'mode'=>$mode,'source_id'=>(int)$row['source_id'],'actor_id'=>(int)$row['user_id'],'until'=>$until];
    }
    return $inactive;
}
function ai_perception_posture_context(array $posture):string {
    return ($posture['mode']??'')==='flee'
        ?"\nPOSTURA PERSISTENTE DEL GIOCO: hai appena cercato riparo per il terrore. Rimani a distanza o protetto, con respiro e voce ancora incerti. Puoi rispondere dal riparo, senza riavvicinarti con calma, suonare o ricominciare da un saluto ordinario. Non riscrivere un nuovo primo spavento."
        :"\nPOSTURA PERSISTENTE DEL GIOCO: il terrore ti ha appena pietrificato. Stai riprendendo fiato e capacità di parlare, tremi ancora e mantieni cautela. Non riprendere improvvisamente un'attività serena; una breve risposta può uscire a fatica. Non riscrivere un nuovo primo spavento.";
}
/** No model call, rate limit or player message is required for a server event. */
function ai_perception_enqueue_event(int $eventId):int {
    require_once __DIR__.'/ai.inc.php';
    if(!ai_perception_ready())return 0;
    $db=ai_db();$own=!$db->inTransaction();if($own)$db->beginTransaction();
    try{$count=ai_perception_enqueue_event_locked($eventId);if($own)$db->commit();return $count;}
    catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
}
/** Caller owns the transaction, including job, immediate chat and fear receipts. */
function ai_perception_enqueue_event_locked(int $eventId):int {
    $db=ai_db();$event=$db->query('SELECT * FROM ai_scene_events WHERE id=?',[$eventId])[0]??null;
    if(!$event||time()-(int)$event['created_at']>300)return 0;
    $room=$db->query('SELECT * FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$event['chat_key']])[0]??null;if(!$room||!(int)$room['enabled'])return 0;
    $session=ai_game_session($event['chat_key']);if($session&&!empty($session['guidance']['paused']))return 0;
    $count=0;
    // Existing receipts prevent future arrivals from becoming retroactive witnesses.
    foreach($db->query('SELECT n.* FROM ai_scene_witnesses w JOIN ai_npcs n ON n.id=w.npc_id WHERE w.event_id=? AND n.active=1 AND BINARY n.chat_key=BINARY ? ORDER BY n.id',[$eventId,$event['chat_key']]) as $npc){
        $nid=(int)$npc['id'];$proof=ai_perception_reaction_event(['source_id'=>(int)$event['source_id'],'npc_id'=>$nid,'chat_key'=>$event['chat_key']]);
        if(!$proof||(int)ai_npc_profile($npc)['hit_points']<=0||!in_array($nid,ai_perception_witnesses($event['chat_key']),true))continue;
        $actor=(int)$proof['actor_id'];
        $present=ai_relation_context_current($nid,$actor);
        if($present&&(int)$event['source_id']<=$present['chat_id'])continue;
        if($db->query("SELECT e.id FROM ai_scene_events e JOIN ai_scene_witnesses w ON w.event_id=e.id WHERE w.npc_id=? AND e.actor_id=? AND BINARY e.chat_key=BINARY ? AND e.kind='transformation' AND e.source_id>? LIMIT 1",[$nid,$actor,$event['chat_key'],(int)$event['source_id']]))continue;
        // A new form supersedes only unpublished reactions to this same actor.
        $db->execute("UPDATE ai_jobs SET status='cancelled',finished_at=?,error='Una metamorfosi più recente sostituisce la reazione non pubblicata.' WHERE kind='reaction' AND status='queued' AND npc_id=? AND user_id=? AND BINARY chat_key=BINARY ? AND source_id<?",[time(),$nid,$actor,$event['chat_key'],(int)$event['source_id']]);
        $inserted=$db->execute("INSERT IGNORE INTO ai_jobs(chat_key,npc_id,user_id,request_key,kind,prompt,room_version,source_id,available_at,created_at) VALUES(?,?,?,?,'reaction',?,?,?,?,?)",[$event['chat_key'],$nid,$actor,'scene-reaction:'.$eventId.':'.$nid,ai_perception_event_text($proof),(int)$room['version'],(int)$event['source_id'],time(),time()]);
        if($inserted){
            $job=$db->query('SELECT * FROM ai_jobs WHERE id=?',[(int)$db->lastInsertId()])[0];
            ai_perception_publish_preface($npc,$job,$proof);
            $count++;
        }else{
            // A legacy/interrupted receipt without a physical preface is repairable.
            $job=$db->query('SELECT * FROM ai_jobs WHERE request_key=? FOR UPDATE',['scene-reaction:'.$eventId.':'.$nid])[0]??null;
            if($job&&$job['status']==='queued'&&(int)$job['message_id']===0&&empty(json_decode((string)($job['result']??'{}'),true)['scene_reaction']['immediate_message_id']))ai_perception_publish_preface($npc,$job,$proof);
        }
    }
    // The physical reactions above must be committed before turn announcements.
    // The shared quest helper respects an existing human conductor and deduplicates.
    if(function_exists('ai_quest_after_scene_event')){
        $quest=ai_quest_after_scene_event($event);
        // The quest can be born after the reactions. Attach only this event's
        // receipts so its opening physical reactions enter the same diary.
        if((int)($quest['session_id']??0)>0)$db->execute("UPDATE ai_jobs SET session_id=?,result=JSON_SET(result,'$.scene_reaction.preface_log_synced',FALSE) WHERE kind='reaction' AND source_id=? AND BINARY chat_key=BINARY ? AND session_id=0 AND message_id>0",[(int)$quest['session_id'],(int)$event['source_id'],$event['chat_key']]);
    }
    return $count;
}
/** A short physical reaction is immediate game state, independent of model latency. */
function ai_perception_publish_preface(array $npc,array $job,array $event):void {
    require_once __DIR__.'/ai-reaction-style.inc.php';
    $db=ai_db();$r=ai_perception_reaction($npc,$job);$mode=$r['mode'];$name=(string)$npc['name'];
    $profile=ai_npc_profile($npc);$now=time();
    $style=ai_reaction_style_preface($npc,$profile,$r,$event);$text=$style['text'];
    $result=['testo'=>$text,'nome'=>$name,'sent_at'=>$now,'narration_source'=>'game_reaction','character'=>['id'=>(int)$npc['id'],'name'=>$name,'profile'=>array_intersect_key($profile,array_flip(['sex','race_id','ethnicity_id']))],
        'scene_reaction'=>['source_id'=>(int)$event['source_id'],'actor_id'=>(int)$event['actor_id'],'mode'=>$mode,'until'=>$now+match($mode){'flee'=>180,'freeze'=>90,default=>0},'immediate_text'=>$text,'style'=>$style['variant'],'preface_log_synced'=>false]];
    $encoded=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$html=ai_message_html([...$job,'result'=>$encoded],$name);
    $db->execute('INSERT INTO chat(pg,chat,ora,msg,dest,lican) VALUES(0,?,?,?,0,0)',[$job['chat_key'],$now,$html]);$mid=(int)$db->lastInsertId();
    $result['scene_reaction']['immediate_message_id']=$mid;
    $db->execute('INSERT INTO registrazionichat(registrazione,testo) SELECT id,? FROM registrazioni WHERE chat=?',[$html,$job['chat_key']]);
    $sid=(int)($db->query("SELECT id FROM sessioni WHERE chat=? AND run='1' ORDER BY id DESC LIMIT 1",[$job['chat_key']])[0]['id']??0);
    $db->execute('INSERT INTO ai_events(chat_key,npc_id,source_id,speaker,content,created_at) VALUES(?,?,?,?,?,?)',[$job['chat_key'],(int)$npc['id'],$mid,$name,$text,$now]);
    // The complete physical reaction is already public. A second LLM draft would
    // occupy a dialogue slot and can repeat the first shock after it has passed.
    // Its diary is reconciled by preface_log_synced, independently of ai_sync_logs.
    $db->execute("UPDATE ai_jobs SET status='done',result=?,message_id=?,session_id=?,finished_at=?,log_synced=1,error=NULL WHERE id=?",[json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$mid,$sid,$now,(int)$job['id']]);
    ai_social_capture_perception($npc,$job);
    if(function_exists('ai_social_record_manifestation'))ai_social_record_manifestation($npc,$job,$text);
}
/** Reconcile immediate receipts into file diaries after the announcing transaction commits. */
function ai_perception_sync_prefaces():void {
    foreach(ai_db()->query("SELECT id,result,session_id FROM ai_jobs WHERE kind='reaction' AND result IS NOT NULL AND JSON_EXTRACT(result,'$.scene_reaction.immediate_message_id')>0 AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(result,'$.scene_reaction.preface_log_synced')),'false') NOT IN('true','1') ORDER BY id LIMIT 20") as $job){
        if((int)$job['session_id']>0){
            $path=htmlroot.'/sessione/log/'.(int)$job['session_id'].'.html';$file=@fopen($path,'c+');if(!$file)continue;
            try{
                if(!flock($file,LOCK_EX))continue;
                $marker='<!-- master-ia:reaction:'.(int)$job['id'].':immediate -->';$found=false;
                while(($line=fgets($file))!==false)if(str_contains($line,$marker)){$found=true;break;}
                if(!$found){$data=json_decode($job['result'],true);$mid=(int)$data['scene_reaction']['immediate_message_id'];$html=ai_db()->query('SELECT msg FROM chat WHERE id=?',[$mid])[0]['msg']??null;if($html===null)continue;fseek($file,0,SEEK_END);$line=$marker.$html."<br>\n";if(fwrite($file,$line)!==strlen($line)||!fflush($file))continue;}
                flock($file,LOCK_UN);
            }finally{fclose($file);}
        }
        ai_db()->execute("UPDATE ai_jobs SET result=JSON_SET(result,'$.scene_reaction.preface_log_synced',TRUE) WHERE id=?",[(int)$job['id']]);
    }
}
/** Recover an interrupted capture without inventing witnesses or reviving old scenes. */
function ai_perception_recover_pending():void {
    if(!ai_perception_ready())return;
    $db=ConnectDB();
    foreach($db->query("SELECT DISTINCT e.id FROM ai_scene_events e JOIN ai_scene_witnesses w ON w.event_id=e.id JOIN ai_rooms r ON r.chat_key=e.chat_key AND r.enabled=1 WHERE e.created_at>=? AND (e.kind='transformation' OR EXISTS(SELECT 1 FROM ai_nature_observations o WHERE o.source_id=e.source_id AND o.npc_id=w.npc_id)) AND (NOT EXISTS(SELECT 1 FROM ai_jobs j WHERE j.request_key=CONCAT('scene-reaction:',e.id,':',w.npc_id)) OR EXISTS(SELECT 1 FROM ai_jobs j WHERE j.request_key=CONCAT('scene-reaction:',e.id,':',w.npc_id) AND j.status='queued' AND j.message_id=0)) ORDER BY e.id LIMIT 30",[time()-300]) as $event)ai_perception_enqueue_event((int)$event['id']);
}
/** Validate again immediately before publication; a new scene can invalidate inference. */
function ai_perception_reaction_state(array $job):array {
    $db=ConnectDB();$room=ai_room((string)$job['chat_key']);
    $npc=ai_dialogue_npc((string)$job['chat_key'],(int)$job['npc_id'],(int)$job['user_id']);
    $event=ai_perception_reaction_event($job);$session=ai_game_session((string)$job['chat_key']);
    if(!$room||!$room['enabled']||(int)$room['version']!==(int)$job['room_version']||!$npc||(int)ai_npc_profile($npc)['hit_points']<=0||!$event||time()-(int)$event['created_at']>300||($session&&!empty($session['guidance']['paused'])))throw new DomainException('Reazione superata: scena, testimone o regia sono cambiati.');
    if($db->query("SELECT e.id FROM ai_scene_events e JOIN ai_scene_witnesses w ON w.event_id=e.id WHERE w.npc_id=? AND e.actor_id=? AND BINARY e.chat_key=BINARY ? AND e.kind='transformation' AND e.source_id>? LIMIT 1",[(int)$npc['id'],(int)$event['actor_id'],$job['chat_key'],(int)$event['source_id']]))throw new DomainException('Una metamorfosi più recente ha sostituito questo evento.');
    return [$room,$npc,$event];
}
/** Same publication/logging receipts as ordinary dialogue, without pretending a PG spoke. */
function ai_perception_work_job(array $job):bool {
    [$room,$npc,$event]=ai_perception_reaction_state($job);$db=ai_db();
    $snapshot=ai_game_snapshot($job,$npc);ai_heartbeat('generating');
    $result=ai_generate($room,$npc,$job);$result['nome']=$npc['name'];$result['sent_at']=time();
    $result['character']=['id'=>(int)$npc['id'],'name'=>$npc['name'],'profile'=>array_intersect_key(ai_npc_profile($npc),array_flip(['sex','race_id','ethnicity_id']))];
    $preface=json_decode((string)($db->query('SELECT result FROM ai_jobs WHERE id=?',[(int)$job['id']])[0]['result']??'{}'),true)['scene_reaction']??[];
    $result['scene_reaction']=$preface;
    $db->beginTransaction();
    try{
        $db->query('SELECT chat_key FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$job['chat_key']]);
        $locked=$db->query('SELECT status FROM ai_jobs WHERE id=? FOR UPDATE',[(int)$job['id']])[0]??[];
        ai_perception_reaction_state($job);
        if(($locked['status']??'')!=='running'||$snapshot!==ai_game_snapshot($job))throw new DomainException('Il contesto della reazione è cambiato durante la generazione.');
        $encoded=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$html=ai_message_html([...$job,'result'=>$encoded],$npc['name']);
        $db->execute('INSERT INTO chat(pg,chat,ora,msg,dest,lican) VALUES(0,?,?,?,0,0)',[$job['chat_key'],time(),$html]);$mid=(int)$db->lastInsertId();
        $db->execute('INSERT INTO registrazionichat(registrazione,testo) SELECT id,? FROM registrazioni WHERE chat=?',[$html,$job['chat_key']]);
        $sid=(int)($db->query("SELECT id FROM sessioni WHERE chat=? AND run='1' ORDER BY id DESC LIMIT 1",[$job['chat_key']])[0]['id']??0);
        $db->execute('INSERT INTO ai_events(chat_key,npc_id,job_id,speaker,content,created_at) VALUES(?,?,?,?,?,?)',[$job['chat_key'],(int)$npc['id'],(int)$job['id'],$npc['name'],$result['testo'],time()]);
        // Verified event memory evolves fear once per family via the existing ledger.
        // No fabricated PG turn, romantic inference, combat effects or turn advance.
        ai_social_capture_perception($npc,$job);
        if(function_exists('ai_social_record_manifestation'))ai_social_record_manifestation($npc,$job,(string)$result['testo']);
        $db->execute("UPDATE ai_jobs SET status='done',result=?,message_id=?,session_id=?,error=NULL,finished_at=? WHERE id=?",[$encoded,$mid,$sid,time(),(int)$job['id']]);
        $db->commit();ai_heartbeat('idle');ai_sync_logs(true);return true;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** If inference fails, the physical reaction already happened; do not publish silence or a duplicate. */
function ai_perception_complete_fallback(array $job,Throwable $error):bool {
    $db=ai_db();$db->beginTransaction();
    try{
        $row=$db->query('SELECT * FROM ai_jobs WHERE id=? FOR UPDATE',[(int)$job['id']])[0]??[];
        $result=json_decode((string)($row['result']??'{}'),true)?:[];
        if(($row['status']??'')!=='running'||empty($result['scene_reaction']['immediate_message_id'])){$db->commit();return false;}
        $result['fallback']=true;
        $db->execute("UPDATE ai_jobs SET status='done',result=?,error=NULL,finished_at=?,log_synced=1 WHERE id=?",[json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),time(),(int)$job['id']]);
        $db->commit();
        error_log('[Master IA reazione #'.(int)$job['id'].' già pubblicata; dettaglio non disponibile] '.mb_substr($error->getMessage(),0,400));
        ai_heartbeat('idle');ai_perception_sync_prefaces();return true;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
