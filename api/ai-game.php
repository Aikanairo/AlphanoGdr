<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/ai.inc.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');$db=ai_db();
try{
    $uid=alphano_require_login(true);$user=alphano_user();
    if(empty($user['admin']))throw new DomainException('Il pannello tecnico IA è riservato agli amministratori. Usa la regia della sessione.',403);
    $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))throw new DomainException('Metodo non consentito.',405);
    $in=$method==='POST'?alphano_request_json():$_GET;if($method==='POST'){
        alphano_require_csrf(true);
        if(!in_array(ai_field($in,'action',20,true),['save','advance'],true))throw new DomainException('Azione non riconosciuta.',422);
    }
    $sid=ai_integer($in,'quest_id',1,PHP_INT_MAX);if($method==='POST')$db->beginTransaction();
    $s=$db->query('SELECT * FROM sessioni WHERE id=?'.($method==='POST'?' FOR UPDATE':''),[$sid])[0]??null;
    if(!$s||!$s['run']||$s['chat']!==$user['chat'])throw new DomainException('Raggiungi la chat della quest attiva per gestirne la regia.',403);
    $g=ai_game_guidance($sid);
    $inputVersion=(int)$g['version'];
    if($method==='POST'&&ai_quest_row($s['chat'])){
      $q=ai_quest_row($s['chat'],true);ai_conductor_set($q,$uid,'human');
      $g=ai_game_guidance($sid);
    }
    if($method==='GET'){
        $cast=[];foreach($db->query('SELECT id,nome FROM utenti WHERE chat=? AND bloccato=0 ORDER BY nome',[$s['chat']]) as $p)$cast[]=['kind'=>'pg','id'=>(int)$p['id'],'name'=>$p['nome']];
        foreach($db->query('SELECT id,name FROM ai_npcs WHERE chat_key=? AND active=1 ORDER BY name',[$s['chat']]) as $n)$cast[]=['kind'=>'npc','id'=>(int)$n['id'],'name'=>$n['name']];
        echo json_encode(['session'=>array_intersect_key($s,array_flip(['id','nome','chat','dadi','lock'])),'guidance'=>$g,'cast'=>$cast],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    if(ai_integer($in,'version',0,PHP_INT_MAX)!==$inputVersion)throw new DomainException('Regia aggiornata da un altro Master. Ricarica prima di modificare.',409);
    $action=ai_field($in,'action',20,true);
    if($action==='save'){
        $notes=ai_field($in,'notes',1500);$phase=ai_field($in,'phase',20,true);$paused=ai_integer($in,'paused',0,1);
        if(!in_array($phase,['narrazione','combattimento','attesa'],true))throw new DomainException('Fase non valida.',422);
        $order=$in['turn_order']??[];if(!is_array($order)||!array_is_list($order)||count($order)>30)throw new DomainException('Massimo 30 partecipanti nella turnazione.',422);
        $clean=[];$seen=[];
        foreach($order as $entry){
            if(!is_array($entry)||!in_array($entry['kind']??null,['pg','npc'],true))throw new DomainException('Partecipante non valido.',422);
            $id=ai_integer($entry,'id',1,PHP_INT_MAX);$kind=$entry['kind'];$key=$kind.':'.$id;
            if(isset($seen[$key]))throw new DomainException('Un partecipante compare due volte.',422);$seen[$key]=true;
            $exists=$kind==='pg'?$db->query('SELECT id FROM utenti WHERE id=? AND chat=? AND bloccato=0',[$id,$s['chat']]):$db->query('SELECT id FROM ai_npcs WHERE id=? AND chat_key=? AND active=1',[$id,$s['chat']]);
            if(!$exists)throw new DomainException('Un partecipante non è presente nella chat.',422);
            $clean[]=['kind'=>$kind,'id'=>$id];
        }
        $encoded=json_encode($clean,JSON_THROW_ON_ERROR);$changed=$encoded!==$g['turn_order'];
        $db->execute('INSERT INTO ai_session_guidance(session_id,notes,phase,paused,turn_order,turn_index,round_number,version,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE notes=VALUES(notes),phase=VALUES(phase),paused=VALUES(paused),turn_order=VALUES(turn_order),turn_index=VALUES(turn_index),round_number=VALUES(round_number),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$sid,$notes,$phase,$paused,$encoded,$changed?0:(int)$g['turn_index'],$changed&&ai_quest_row($s['chat'])?(int)$g['round_number']+1:($changed?1:(int)$g['round_number']),(int)$g['version']+1,$uid,time()]);
        if($changed&&($q??null)){$db->execute('UPDATE ai_quest_cast SET active=0 WHERE session_id=?',[$sid]);foreach($clean as $member)$db->execute("INSERT INTO ai_quest_cast(session_id,kind,actor_id,active,state) VALUES(?,?,?,1,'{}') ON DUPLICATE KEY UPDATE active=1",[$sid,$member['kind'],(int)$member['id']]);$db->execute("UPDATE ai_quests SET status='active',version=version+1 WHERE session_id=?",[$sid]);}
    }elseif($action==='advance'){
        $order=json_decode($g['turn_order'],true)?:[];if(!$order)throw new DomainException('Configura prima un ordine di turno.',422);
        $next=((int)$g['turn_index']+1)%count($order);$round=(int)$g['round_number']+($next===0?1:0);
        $db->execute('UPDATE ai_session_guidance SET turn_index=?,round_number=?,version=version+1,updated_by=?,updated_at=? WHERE session_id=?',[$next,$round,$uid,time(),$sid]);
    }else throw new DomainException('Azione non riconosciuta.',422);
    $db->execute("UPDATE ai_jobs SET status='cancelled',finished_at=?,error='Regia della quest aggiornata. Invia un nuovo turno.' WHERE chat_key=? AND kind='play' AND status IN ('queued','running')",[time(),$s['chat']]);
    if($q??null){$db->execute('UPDATE ai_session_guidance SET turn_started_at=? WHERE session_id=?',[time(),$sid]);ai_quest_schedule(ai_quest_row($s['chat'],true));}
    $db->commit();echo json_encode(['ok'=>true]);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$code=$e instanceof DomainException?$e->getCode():500;if($code<400||$code>499)$code=500;if($code===500)error_log('[Regia IA] '.$e->getMessage());http_response_code($code);echo json_encode(['error'=>$code===500?'Regia non aggiornata. Riprova.':$e->getMessage()],JSON_UNESCAPED_UNICODE);}
