<?php
declare(strict_types=1);
require_once __DIR__.'/experience.inc.php';
require_once __DIR__.'/profile-symbols.inc.php';
/** One immutable receipt owns the presentation of a score change; no second score table. */
function ai_relation_revision(array $relation):string {
    $values=[];foreach(AI_RELATION_METRICS as $key=>$_)$values[$key]=(int)($relation[$key]??0);
    $context=ai_relation_context_current((int)($relation['npc_id']??0),(int)($relation['player_id']??0));
    return hash('sha256',json_encode([...$values,'state'=>$relation['state']??'conoscenza','context_id'=>(int)($context['id']??0)],JSON_THROW_ON_ERROR));
}
function ai_relation_event(int $npc,int $player,array $before,array $after,string $reason,array $context=[],int $job=0,int $actor=0):int {
    $db=ai_db();$changes=[];foreach(AI_RELATION_METRICS as $key=>$_){$delta=(int)($after[$key]??0)-(int)($before[$key]??0);if($delta)$changes[$key]=$delta;}
    $chat=(string)($context['chat_key']??'');$source=(int)($context['source_id']??0);
    if($changes&&$chat!==''){
        $origin=$source>0?($db->query('SELECT pg,chat,dest FROM chat WHERE id=?',[$source])[0]??[]):[];
        $owner=$db->query('SELECT chat,bloccato FROM utenti WHERE id=?',[$player])[0]??[];
        $validSource=$origin&&(int)$origin['pg']===$player&&$origin['chat']===$chat&&(int)$origin['dest']===0;
        // A metamorphosis is authored by the system (pg=0); its witness ledger
        // supplies the actor instead of treating arbitrary Master prose as proof.
        if(!$validSource&&$origin&&(int)$origin['pg']===0&&$origin['chat']===$chat&&(int)$origin['dest']===0)$validSource=(bool)$db->query("SELECT e.id FROM ai_scene_events e JOIN ai_scene_witnesses w ON w.event_id=e.id WHERE e.source_id=? AND BINARY e.chat_key=BINARY ? AND e.kind='transformation' AND e.actor_id=? AND w.npc_id=?",[$source,$chat,$player,$npc]);
        $staffHere=$actor>0&&($owner['chat']??'')===$chat;
        if(($validSource||$staffHere)&&!empty($owner)&&empty($owner['bloccato'])){
            $mode=alphano_experience_settings($player)['relationship_effects'];$audience=[];
            if($mode==='public')foreach($db->query('SELECT id FROM utenti WHERE BINARY chat=BINARY ? AND bloccato=0 AND lastmove>? ORDER BY id LIMIT 250',[$chat,time()-900]) as $u)$audience[]=(int)$u['id'];
            $after['_notice']=['chat_key'=>$chat,'source_id'=>$source,'visibility'=>$mode,'audience'=>$audience,'deltas'=>$changes];
        }
    }
    $db->execute('INSERT INTO ai_relationship_events(npc_id,player_id,job_id,before_state,after_state,reason,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)',[$npc,$player,$job>0?$job:null,json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$reason,$actor,time()]);
    return (int)$db->lastInsertId();
}
/** Indexed PK cursor, at most 100 receipts; never disclose old history on room entry. */
function ai_relation_notices(string $chat,int $viewer,int $since,bool $initial=false):array {
    $db=ai_db();$last=(int)($db->query('SELECT MAX(id) AS last_id FROM ai_relationship_events')[0]['last_id']??0);
    $out=['cursor'=>$last,'events'=>[],'more'=>false];
    if($initial||$since>$last)return $out;
    $out['cursor']=$since;$mode=alphano_experience_settings($viewer)['relationship_effects'];
    $who=$db->query('SELECT chat,bloccato FROM utenti WHERE id=?',[$viewer])[0]??[];
    if($mode==='off'||($who['chat']??'')!==$chat||!empty($who['bloccato'])){$out['cursor']=$last;return $out;}
    $rows=$db->query('SELECT id,npc_id,player_id,before_state,after_state,created_at FROM ai_relationship_events WHERE id>? ORDER BY id LIMIT 100',[$since]);
    $out['more']=count($rows)===100;
    $people=[];$npcs=[];$ownerModes=[$viewer=>$mode];
    foreach($rows as $row){
        $out['cursor']=(int)$row['id'];if((int)$row['created_at']<time()-120)continue;
        $after=json_decode($row['after_state'],true)?:[];$note=$after['_notice']??[];$uid=(int)$row['player_id'];$nid=(int)$row['npc_id'];
        if(($note['chat_key']??'')!==$chat||($note['visibility']??'off')==='off')continue;
        if($uid!==$viewer&&($mode!=='public'||($note['visibility']??'')!=='public'||!in_array($viewer,$note['audience']??[],true)||($ownerModes[$uid]??=alphano_experience_settings($uid)['relationship_effects'])!=='public'))continue;
        $deltas=[];$before=json_decode($row['before_state'],true)?:[];
        foreach(AI_RELATION_METRICS as $key=>$label){$change=(int)($after[$key]??0)-(int)($before[$key]??0);if($change)$deltas[]=['key'=>$key,'label'=>$label,'delta'=>$change,'icon'=>alphano_profile_symbol($key)];}
        if(!$deltas)continue;
        $person=$people[$uid]??=($db->query('SELECT id,nome FROM utenti WHERE id=?',[$uid])[0]??[]);
        $npc=$npcs[$nid]??=($db->query('SELECT id,name,profile FROM ai_npcs WHERE id=?',[$nid])[0]??[]);
        if(!$person||!$npc)continue;
        // A public overlay must not identify someone the observing NPC cannot identify.
        if($uid!==$viewer&&!ai_recognition_view($nid,$uid,['chat_key'=>$chat,'source_id'=>(int)($note['source_id']??0)])['identified'])continue;
        $p=ai_npc_profile($npc);
        $out['events'][]=['id'=>(int)$row['id'],'npc_id'=>$nid,'npc_name'=>$npc['name'],'portrait'=>$p['portrait']?:'/assets/placeholder.svg','player_id'=>$uid,'player_name'=>$person['nome'],'own'=>$uid===$viewer,'deltas'=>$deltas];
    }
    return $out;
}
