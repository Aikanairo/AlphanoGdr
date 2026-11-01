<?php
declare(strict_types=1);

/** A single supervisor distributes work; consumers never inherit a PDO handle. */
function ai_worker_schema_ready():bool {
    static $ready=null;
    return $ready??=(bool)ai_db()->query("SHOW COLUMNS FROM ai_jobs LIKE 'worker_token'");
}
function ai_worker_slots():int {
    $value=getenv('ALPHANO_AI_WORKERS');
    // Two lanes are an explicit deployment choice paired with llama --parallel 2.
    return max(1,min(2,$value===false?1:(int)$value));
}
function ai_worker_job_budget(string $kind):int {
    return min(ai_deadline(),match($kind){
        'reaction'=>25,'observe'=>45,'social'=>45,'demo','preview'=>75,default=>90,
    });
}
function ai_worker_lock(string $kind,int $id=0):string {
    return alphano_lock_name('ai-worker',$kind.':'.$id,(string)alphano_config('db_db'));
}
function ai_worker_take_lock(string $key):bool {
    return (int)(ai_db()->query('SELECT GET_LOCK(?,0) acquired',[$key])[0]['acquired']??0)===1;
}
function ai_worker_release_lock(string $key):void {ai_db()->query('SELECT RELEASE_LOCK(?)',[$key]);}

/** Only an unlocked owner can be recovered, even when its lease looks old. */
function ai_worker_recover(bool $startup=false):void {
    $db=ai_db();$now=time();
    if(!ai_worker_schema_ready()){
        $db->execute("UPDATE ai_jobs SET status='queued',available_at=?,error='Worker riavviato' WHERE status='running' AND started_at<?",[$now,$startup?$now+1:$now-ai_deadline()-30]);
        if($startup)$db->execute("UPDATE ai_social_jobs SET status='queued',available_at=? WHERE status='running'",[$now]);
        return;
    }
    foreach($db->query("SELECT id,worker_token FROM ai_jobs WHERE status='running' AND (worker_token='' OR lease_until<? OR (?=1 AND started_at<?)) ORDER BY id LIMIT 256",[$now,$startup?1:0,$now-10]) as $job){
        $lock=ai_worker_lock('job',(int)$job['id']);if(!ai_worker_take_lock($lock))continue;
        try{
            $db->execute("UPDATE ai_jobs SET status=IF(attempts>=3,'failed','queued'),available_at=?,finished_at=IF(attempts>=3,?,0),worker_token='',lease_until=0,error=IF(attempts>=3,'Il motore ha interrotto tre tentativi. Invia un nuovo turno.','Risposta recuperata dopo l’interruzione del processo.') WHERE id=? AND status='running' AND worker_token=?",[$now,$now,(int)$job['id'],$job['worker_token']]);
        }finally{ai_worker_release_lock($lock);}
    }
    $social=$db->query('SELECT social_token,social_lease FROM ai_worker WHERE id=1')[0]??[];
    if(($social['social_token']??'')!==''&&(int)$social['social_lease']<$now){
        $lock=ai_worker_lock('social');
        if(ai_worker_take_lock($lock))try{
            $db->execute("UPDATE ai_social_jobs SET status=IF(attempts>=3,'failed','queued'),available_at=? WHERE status='running'",[$now]);
            $db->execute("UPDATE ai_worker SET social_token='',social_lease=0 WHERE id=1 AND social_token=?",[$social['social_token']]);
        }finally{ai_worker_release_lock($lock);}
    }elseif(($social['social_token']??'')===''&&$startup){
        $lock=ai_worker_lock('social');if(ai_worker_take_lock($lock))try{
            $db->execute("UPDATE ai_social_jobs SET status=IF(attempts>=3,'failed','queued'),available_at=? WHERE status='running'",[$now]);
        }finally{ai_worker_release_lock($lock);}
    }
    // Queueing behind other rooms is not a failed inference. A real turn gets an
    // hour to be served; optional observations and old shock details expire sooner.
    $db->execute("UPDATE ai_jobs SET status='failed',finished_at=?,error='Richiesta scaduta. Puoi inviare un nuovo turno.' WHERE status='queued' AND (attempts>=3 OR created_at<? OR (kind IN('reaction','observe') AND created_at<?))",[$now,$now-3600,$now-600]);
}

/** Fair room/player round-robin is persisted in claims, not process-local state. */
function ai_worker_claim():?array {
 if(!alphano_config('ai_enabled'))return null;
    $db=ai_db();$now=time();
    if(!ai_worker_schema_ready()){
        $db->beginTransaction();
        try{
            $job=$db->query("SELECT j.* FROM ai_jobs j WHERE j.status='queued' AND j.available_at<=? AND NOT EXISTS(SELECT 1 FROM ai_jobs p WHERE j.kind IN('play','reaction') AND j.source_id>0 AND p.kind=j.kind AND p.chat_key=j.chat_key AND p.source_id=j.source_id AND p.id<j.id AND p.status IN('queued','running')) ORDER BY CASE WHEN j.kind IN('play','npc-turn','verdict') THEN 0 WHEN j.kind IN('demo','preview') THEN 1 ELSE 2 END,j.id LIMIT 1 FOR UPDATE",[$now])[0]??null;
            if($job)$db->execute("UPDATE ai_jobs SET status='running',started_at=?,attempts=attempts+1 WHERE id=?",[$now,(int)$job['id']]);
            $db->commit();return $job;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    $db->beginTransaction();
    try{
        $worker=$db->query('SELECT dispatch_counter FROM ai_worker WHERE id=1 FOR UPDATE')[0]??null;
        if(!$worker)throw new LogicException('Migrazione del worker non completata.');
        $job=$db->query("SELECT j.*,
            COALESCE((SELECT MAX(r.dispatch_order) FROM ai_jobs r WHERE r.chat_key=j.chat_key),0) room_dispatch,
            COALESCE((SELECT MAX(u.dispatch_order) FROM ai_jobs u WHERE u.user_id=j.user_id),0) user_dispatch,
            (SELECT COUNT(*) FROM ai_jobs b WHERE b.chat_key=j.chat_key AND b.status='running') room_running
            FROM ai_jobs j WHERE j.status='queued' AND j.available_at<=?
            AND NOT EXISTS(SELECT 1 FROM ai_jobs p WHERE j.kind IN('play','reaction') AND j.source_id>0 AND p.kind=j.kind AND p.chat_key=j.chat_key AND p.source_id=j.source_id AND p.id<j.id AND p.status IN('queued','running'))
            AND NOT EXISTS(SELECT 1 FROM ai_jobs n WHERE j.npc_id>0 AND n.npc_id=j.npc_id AND n.status='running')
            ORDER BY CASE WHEN j.kind IN('play','npc-turn','verdict') OR j.available_at<=? THEN 0 WHEN j.kind IN('demo','preview') THEN 1 ELSE 2 END,
            room_running,room_dispatch,user_dispatch,j.available_at,j.id LIMIT 1 FOR UPDATE",[$now,$now-120])[0]??null;
        if(!$job){$db->commit();return null;}
        $token=bin2hex(random_bytes(16));$order=(int)$worker['dispatch_counter']+1;$lease=$now+ai_worker_job_budget((string)$job['kind'])+30;
        $db->execute("UPDATE ai_jobs SET status='running',started_at=?,attempts=attempts+1,worker_token=?,lease_until=?,dispatch_order=? WHERE id=?",[$now,$token,$lease,$order,(int)$job['id']]);
        $db->execute('UPDATE ai_worker SET dispatch_counter=? WHERE id=1',[$order]);$db->commit();
        return [...$job,'worker_token'=>$token,'lease_until'=>$lease,'dispatch_order'=>$order];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function ai_worker_social_due(bool $foreground):bool {
    $db=ai_db();$now=time();
    $task=$db->query("SELECT MIN(s.available_at) oldest FROM ai_social_jobs s JOIN ai_jobs j ON j.id=s.job_id WHERE s.status='queued' AND s.attempts<3 AND s.available_at<=?",[$now])[0]??[];
    if($task['oldest']===null)return false;
    if(!ai_worker_schema_ready())return !$foreground;
    $worker=$db->query('SELECT dispatch_counter,social_dispatch,social_token FROM ai_worker WHERE id=1')[0]??[];
    if(($worker['social_token']??'')!=='')return false;
    return !$foreground||((int)$worker['dispatch_counter']-(int)$worker['social_dispatch']>=8)||((int)$task['oldest']<$now-180&&(int)$worker['dispatch_counter']-(int)$worker['social_dispatch']>=3);
}
function ai_worker_claim_social():?string {
 if(!alphano_config('ai_enabled'))return null;
    $db=ai_db();$db->beginTransaction();
    try{
        $row=$db->query('SELECT dispatch_counter,social_token FROM ai_worker WHERE id=1 FOR UPDATE')[0]??[];
        if(($row['social_token']??'')!==''){$db->commit();return null;}
        $token=bin2hex(random_bytes(16));
        $db->execute('UPDATE ai_worker SET social_token=?,social_lease=?,social_dispatch=dispatch_counter WHERE id=1',[$token,time()+ai_worker_job_budget('social')+30]);$db->commit();return $token;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function ai_worker_social_consume(string $token=''):bool {
    $lock=ai_worker_lock('social');if(!ai_worker_take_lock($lock))return false;
    try{
        if(ai_worker_schema_ready()&&$token!==''){
            $owner=ai_db()->query('SELECT social_token FROM ai_worker WHERE id=1')[0]['social_token']??'';
            if(!hash_equals((string)$owner,$token))return false;
        }
        ai_runtime_budget(ai_worker_job_budget('social'));
        return ai_social_work_once();
    }finally{
        ai_runtime_budget(0);
        if(ai_worker_schema_ready()&&$token!=='')ai_db()->execute("UPDATE ai_worker SET social_token='',social_lease=0 WHERE id=1 AND social_token=?",[$token]);
        ai_worker_release_lock($lock);
    }
}
