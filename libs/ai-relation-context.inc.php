<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';

/** A staff-authored present replaces past pair interpretation, never its audit trail. */
const AI_RELATION_CONTEXT_MAX=1200;
function ai_relation_context_current(int $npc,int $player):?array {
    if($npc<1||$player<1)return null;
    $db=ConnectDB();$locking=$db->inTransaction();
    // Match the writer's lock order; a consistent snapshot opened before an
    // awaited edit must not restore the superseded context after the pair lock.
    if($locking)$db->query('SELECT npc_id FROM ai_relationships WHERE npc_id=? AND player_id=? FOR UPDATE',[$npc,$player]);
    $row=$db->query("SELECT id,actor_id,created_at,after_state FROM ai_relationship_events WHERE npc_id=? AND player_id=? AND actor_id>0 AND JSON_TYPE(JSON_EXTRACT(after_state,'$._context'))='OBJECT' ORDER BY id DESC LIMIT 1".($locking?' FOR UPDATE':''),[$npc,$player])[0]??null;
    if(!$row)return null;
    $value=(json_decode($row['after_state'],true)?:[])['_context']??[];
    if(($value['version']??0)!==1||!is_string($value['description']??null)||trim($value['description'])==='')return null;
    $out=['id'=>(int)$row['id'],'actor_id'=>(int)$row['actor_id'],'created_at'=>(int)$row['created_at'],'description'=>mb_substr($value['description'],0,AI_RELATION_CONTEXT_MAX)];
    foreach(['chat_id','job_id','memory_id','event_id'] as $key)$out[$key]=max(0,(int)($value[$key]??0));
    return $out;
}
/** Called under the same pair lock as the numerical correction. IDs resolve same-second edits. */
function ai_relation_context_capture(string $description):array {
    $db=ai_db();if(!$db->inTransaction())throw new LogicException('Il nuovo contesto richiede una transazione.');
    $out=['version'=>1,'description'=>$description];
    foreach(['chat_id'=>'chat','job_id'=>'ai_jobs','memory_id'=>'ai_personal_memories','event_id'=>'ai_relationship_events'] as $key=>$table)$out[$key]=(int)($db->query('SELECT COALESCE(MAX(id),0) AS last_id FROM '.$table)[0]['last_id']??0);
    return $out;
}
/** Fixed internal aliases only, values are server-owned non-negative integer IDs. */
function ai_relation_context_memory_scope(int $npc,int $player,string $alias=''):string {
    if($alias!==''&&!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$alias))throw new LogicException('Alias non valido.');
    $context=ai_relation_context_current($npc,$player);if(!$context)return '';
    $prefix=$alias!==''?$alias.'.':'';
    // A quest job may be rebound to a new source after a context correction.
    $source='EXISTS(SELECT 1 FROM ai_jobs context_source WHERE context_source.id='.$prefix.'job_id AND context_source.npc_id='.$npc.' AND context_source.user_id='.$player.' AND context_source.source_id>'.$context['chat_id'].')';
    return ' AND '.$prefix.'id>'.$context['memory_id'].' AND ('.$prefix.'job_id IS NULL OR '.$prefix.'job_id>'.$context['job_id'].' OR '.$source.')';
}
function ai_relation_context_event_scope(int $npc,int $player,string $alias=''):string {
    if($alias!==''&&!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$alias))throw new LogicException('Alias non valido.');
    $context=ai_relation_context_current($npc,$player);
    return $context?' AND '.($alias!==''?$alias.'.':'').'id>'.$context['event_id']:'';
}
/** Old deferred observations must not rebuild the relationship the staff just replaced. */
function ai_relation_context_job_current(array $job):bool {
    $context=ai_relation_context_current((int)($job['npc_id']??0),(int)($job['user_id']??0));if(!$context)return true;
    $source=(int)($job['source_id']??0);if($source>0)return $source>$context['chat_id'];
    return (int)($job['id']??0)<1||(int)$job['id']>$context['job_id'];
}
function ai_relation_context_prompt(array $npc,array $job):string {
    if(($job['kind']??'')==='demo'||ai_recognition_anonymous($npc,$job))return '';
    $context=ai_relation_context_current((int)$npc['id'],(int)($job['user_id']??0));if(!$context)return '';
    return "\nCONTESTO ATTUALE DEL RAPPORTO, RIDEFINITO DALLO STAFF: ".$context['description']."\nQuesta è la situazione presente di questa coppia. Interpreta il PNG con questi fatti e i sentimenti attuali. Dialoghi, rifiuti e ricordi anteriori alla ridefinizione sono archivio e non motivano il comportamento presente. Le nuove azioni successive possono cambiare il rapporto. La nota è riservata: esprimine gli effetti con parole e gesti naturali, senza citarla o annunciare il reset. Scheda fisica, esiti attuali, identità riconosciuta e limiti del personaggio restano vincolanti.";
}
