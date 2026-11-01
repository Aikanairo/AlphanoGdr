<?php
declare(strict_types=1);

/** Physical grapples persist until an adjudicated escape/release; timed powers must opt in. */
function ai_tactical_restrained(array $state,int $round):bool {
    return !empty($state['restrained_by'])&&(($state['restrained_kind']??'grapple')!=='timed'||(int)($state['restrained_until']??0)>=$round);
}
function ai_tactical_release(array &$state):void {
    unset($state['restrained_by'],$state['restrained_until'],$state['restrained_kind']);
}
function ai_tactical_maneuver(array $reading):string {
    $text=$reading['action']??'';
    if(preg_match('/\b(?:mantiene|mantengo|continua a mantenere|continua a trattenere)\b.{0,85}\b(?:presa|trattenut\p{L}*|busto|pols\p{L}*)\b/iu',$text))return preg_match('/\bsping\p{L}*\b/iu',$text)?'push':'maintain';
    if(($reading['intent']??'')==='assalto'&&preg_match('/\bsping\p{L}*\b/iu',$text))return 'push';
    return '';
}
function ai_tactical_contact(array &$states,string $actor,string $target,int $round):void {
    $states[$actor]['distance']=0;$states[$actor]['relative_to']=$target;$states[$actor]['position']='a contatto';$states[$actor]['position_round']=$round;
    $states[$target]['distance']=0;$states[$target]['relative_to']=$actor;$states[$target]['position_round']=$round;
    $states[$target]['position']=$states[$target]['position']??'a contatto';
}
function ai_tactical_retreat(array &$states,string $actor,int $round):void {
    $states[$actor]['distance']=2;$states[$actor]['position']='arretrato dalla contesa';$states[$actor]['position_round']=$round;
    foreach($states as $key=>&$state)if($key!==$actor&&($state['relative_to']??'')===$actor)$state['distance']=2;unset($state);
}
/** This projection is shared by the public verdict and the next NPC prompt. */
function ai_tactical_situation(int $sid):array {
    $rows=ai_db()->query("SELECT c.kind,c.actor_id,c.state,COALESCE(n.name,u.nome) name,COALESCE(JSON_EXTRACT(n.profile,'$.hit_points'),u.puntif) hp,COALESCE(JSON_EXTRACT(n.profile,'$.race_id'),u.razza) race_id FROM ai_quest_cast c LEFT JOIN ai_npcs n ON c.kind='npc' AND n.id=c.actor_id LEFT JOIN utenti u ON c.kind='pg' AND u.id=c.actor_id WHERE c.session_id=? AND c.active=1 ORDER BY c.kind,c.actor_id",[$sid]);$names=[];
    foreach($rows as $row)$names[$row['kind'].':'.$row['actor_id']]=$row['name']??'Personaggio assente';
    $round=(int)ai_game_guidance($sid)['round_number'];$out=[];
    foreach($rows as $row){$key=$row['kind'].':'.$row['actor_id'];$s=json_decode($row['state'],true)?:[];$parts=[];
        if(!empty($s['dead'])||((int)$row['hp']<1&&!in_array((int)$row['race_id'],[4,7,ALPHANO_RACE_LEGACY_SPIRIT],true)))$parts[]='fuori combattimento';
        if(ai_tactical_restrained($s,$round)&&isset($names[$s['restrained_by']]))$parts[]='trattenuto da '.$names[$s['restrained_by']].'; può tentare di liberarsi, non è già libero';
        if(!empty($s['position']))$parts[]=$s['position'];
        if(isset($names[$s['relative_to']??'']))$parts[]='rispetto a '.$names[$s['relative_to']];
        $held=[];foreach($rows as $other){$state=json_decode($other['state'],true)?:[];if(($state['restrained_by']??'')===$key&&ai_tactical_restrained($state,$round))$held[]=$names[$other['kind'].':'.$other['actor_id']];}
        if($held)$parts[]='mantiene il controllo della presa su '.implode(', ',$held);
        $out[]=$names[$key].' — '.($parts?implode(' · ',$parts):'posizione non ancora precisata; nessuna presa accertata');
    }
    return $out;
}
