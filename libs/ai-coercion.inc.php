<?php
declare(strict_types=1);
/** Survival pressure is personal, receipted and distinct from affection or a magical bond. */
function ai_coercion_assess(array $npc,array $job,array $episode):?array {
    if(empty($episode['coercive'])||!empty($episode['observer'])||!empty($episode['anonymous']))return null;
    $nid=(int)$npc['id'];$uid=(int)($job['user_id']??0);if($uid<1)return null;
    $hurt=false;$context=ai_relation_context_current($nid,$uid);$cut=(int)($context['chat_id']??0);
    foreach(ai_db()->query("SELECT v.technical FROM ai_quest_effects e JOIN ai_quest_verdicts v ON v.id=e.verdict_id WHERE e.target_kind='npc' AND e.target_id=? AND e.effect_type='hp' AND e.status='applied' AND v.status='applied' ORDER BY e.id DESC LIMIT 20",[$nid]) as $row){
        foreach(json_decode($row['technical'],true)['results']??[] as $r)if(($r['actor_kind']??'')==='pg'&&(int)($r['actor_id']??0)===$uid&&($r['target_kind']??'')==='npc'&&(int)($r['target_id']??0)===$nid&&(int)($r['damage']??0)>0&&(int)($r['source_id']??0)>$cut){$hurt=true;break 2;}
    }
    $known=ai_perception_known_race($nid,$uid);$p=ai_npc_profile($npc);$traits=ai_npc_game($nid)['traits'];
    $own=rpg_actor_stats('npc',$nid);$opponent=rpg_actor_stats('pg',$uid);
    $overwhelming=in_array($known,[2,3,5,6],true)&&(int)$opponent['strength']>max((int)$own['strength']+10,(int)round($own['strength']*1.7));
    if(!$overwhelming&&(!$hurt||((int)$opponent['strength']<(int)$own['strength']&&(int)$p['hit_points']>maxpuntif*.3)))return null;
    $survival=(int)$traits['survival_instinct'];$resistance=(int)round(((int)$traits['courage']+(int)$traits['pride'])/2);
    $mode=$survival>=$resistance?'submission':'appeasement';
    $plan=(int)$traits['vindictiveness']>=65?'seek_revenge':((int)$traits['cunning']>=60?'seek_escape':'seek_protection');
    return ['mode'=>$mode,'plan'=>$plan,'verified_harm'=>$hurt,'observed_superiority'=>$overwhelming,'source_id'=>(int)$job['source_id'],'chat_key'=>$job['chat_key'],'demand'=>ai_text($episode['evidence'],250)];
}
function ai_coercion_state(int $npc,int $player):?array {
    if($npc<1||$player<1)return null;
    foreach(ai_db()->query('SELECT after_state FROM ai_relationship_events WHERE npc_id=? AND player_id=?'.ai_relation_context_event_scope($npc,$player)." AND JSON_TYPE(JSON_EXTRACT(after_state,'$._episode.coercion'))='OBJECT' ORDER BY id DESC LIMIT 1",[$npc,$player]) as $r){
        $data=json_decode($r['after_state'],true)?:[];$state=$data['_episode']['coercion']??null;
        if($state){$rel=ai_relation($npc,$player);if((int)$rel['fear']<20&&(int)$rel['hostility']<20&&(int)$rel['trust']>=0)return null;return $state;}
    }
    return null;
}
function ai_coercion_context(array $npc,array $job):string {
    if(ai_recognition_anonymous($npc,$job))return '';
    $s=ai_coercion_state((int)$npc['id'],(int)($job['user_id']??0));if(!$s)return '';
    $demand=ai_text($s['demand'],180);
    return "\nRAPPORTO SOTTO MINACCIA: questo interlocutore ti ha imposto «".$demand.'» dopo una violenza verificata o una superiorità osservata. '.($s['mode']==='submission'?'Hai scelto di obbedire per restare vivo: davanti a lui esprimi una resa concreta, chiedi di non essere più ferito e ascolta gli ordini. Non rispondere con una fuga generica o una sfida suicida. ':'Cerchi di placarlo e guadagnare tempo: rispondi concretamente alla richiesta, senza una sfida suicida. ').'Non è amore né fiducia, e non autorizza poteri, trasferimenti o atti automatici. Fuori dal pericolo puoi '.match($s['plan']){'seek_revenge'=>'maturare rancore e cercare un piano prudente per liberarti','seek_escape'=>'cercare una possibilità concreta di fuga',default=>'cercare protezione e aiuto'}.', senza inventare alleati o vendette già riuscite. Una rettifica del Master o una riconciliazione effettiva può cambiare questo rapporto.';
}
function ai_coercion_reply_problem(array $npc,array $job,string $text):?string {
    if(ai_recognition_anonymous($npc,$job))return null;
    $s=ai_coercion_state((int)$npc['id'],(int)($job['user_id']??0));if(!$s||empty($job['episode']['coercive']))return null;
    $speech=ai_narrative_parse($text,['channel'=>'action'])['speech'];
    return preg_match('/obbed|ordini|farò|faro|come\s+(?:vuoi|volete)|non\s+(?:mi\s+)?(?:ferir|uccid|mord)|risparmi|vita|vi\s+ascolto|ti\s+ascolto/iu',$speech)?null:'Rispondi all’ultimatum presente con una resa o una richiesta concreta di risparmiare la vita, coerente con la condotta di sopravvivenza; non basta una fuga generica.';
}
function ai_coercion_fallback(array $npc):string {
    return $npc['name'].' tiene le mani visibili, con il corpo contratto per il dolore e il fiato incerto. «Basta, non ferirmi ancora. Farò come chiedi. Dimmi che cosa vuoi da me.» La resa nasce dalla paura per la propria vita; non cancella il ricordo della violenza.';
}
