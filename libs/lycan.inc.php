<?php
declare(strict_types=1);

require_once __DIR__.'/kernel.inc.php';
require_once __DIR__.'/transformations.inc.php';

/** A deliberate transfer in the action, excluding dialogue, OOC and an explicit refusal. */
function alphano_lycan_contagion_attempt(string $raw): bool
{
    if(preg_match('/^\s*\/\//u',$raw))return false;
    $action=preg_replace('/«[^»]*»|"[^"]*"|\([^)]*\)/u','',$raw);
    if(!preg_match('/mord\p{L}*|morso|azzann\p{L}*|affond\p{L}*.{0,16}denti/iu',$action))return false;
    if(preg_match('/\bnon\s+(?:(?:vuole|voglio|intende|intendo|tenta|cerca di)\s+)?(?:mord\p{L}*|azzann\p{L}*)/iu',$action))return false;
    if(preg_match('/\b(?:senza|non\s+(?:(?:lo|la|vuole|voglio|intende|intendo|tenta|cerca di)\s+)*)\s*(?:infett\p{L}*|contagi\p{L}*|trasmett\p{L}*)/iu',$action))return false;
    return (bool)preg_match('/infett\p{L}*|infezion\p{L}*|contagi\p{L}*|trasmett\p{L}*.{0,55}maledizion/iu',$action);
}

/** Compatibility permissions for the optional lineage module. */
function alphano_lycan_can_infect(array $actor, array $target): bool
{
    return alphano_lycan_can_grant($actor)
        && in_array((int)($target['razza']??0),[1,alphano_human_race_id()],true) && (int)($target['puntif']??0)>0
        && empty($target['bloccato']) && empty($target['infetto'])
        && empty($target['ancestrale']) && empty($target['senziente']);
}

/** Lineage permissions are independent of the appearance toggle. */
function alphano_lycan_can_grant(array $user):bool {
    $state=alphano_lycan_state($user);return $state['eligible']&&$state['ancestral'];
}

/** Internal mutation for a resolved bite. The caller owns adjudication and publication. */
function alphano_lycan_infect(array $actor, int $target, string $chat, string $reason, int $executor=0): void
{
    $db=ConnectDB();
    if(!$db->inTransaction())throw new LogicException('Il contagio deve condividere la transazione del morso.');
    $user=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$target])[0]??[];
    if(!alphano_lycan_can_infect($actor,$user) || ($actor['chat']??'')!==$chat || ($user['chat']??'')!==$chat
        || (($actor['kind']??'pg')==='pg' && (int)($actor['id']??0)===$target))throw new DomainException('Il personaggio non può ricevere il trasferimento di stirpe.',409);
    require_once __DIR__.'/lineages.inc.php';
    if(($actor['kind']??'pg')==='pg')lineage_register_bite($actor,$user,'lycan');
    elseif($db->query("SELECT player_id FROM lineage_members WHERE player_id=? AND status='pending'",[$target]))throw new DomainException('È già in corso un altro contagio.',409);
    $db->execute('UPDATE utenti SET infetto=1,senziente=0 WHERE id=?',[$target]);
    require_once __DIR__.'/libferite.inc.php';
    InsertNote($target,$executor,$reason.' Trasferimento di stirpe registrato. Le trasformazioni seguono le regole del modulo attivo.');
    $db->execute('INSERT INTO logs_adminpg(azione,data,chat,esecutore,destinatario,qta,obj,descr) VALUES(?,?,?,?,?,0,0,?)',
        ['contagio-licantropico',time(),$chat,$executor,$target,$reason]);
    // The shared lunar synchronizer changes the body, never this declaration of infection.
}

/** Compatibility projection: ancestral contagion powers remain exclusive to lycans. */
function alphano_lycan_state(array $user,?int $now=null):array {
    $s=alphano_transformation_state($user,$now);
    if($s['kind']!=='lycan'){$s['eligible']=false;$s['visible']=false;$s['canChoose']=false;$s['canTransform']=false;$s['ancestral']=false;}
    $s['form']=$s['form']==='native'&&$s['kind']==='lycan'?'wolf':'human';return $s;
}
function alphano_lycan_in_chat(array $user,int $now):bool {return alphano_transformation_in_chat($user,$now);}
function alphano_lycan_sync(int $id,?string $requested=null,?int $now=null):array {
    $s=alphano_transformation_sync($id,$requested,$now);
    if($s['kind']==='lycan')$s['form']=$s['form']==='native'?'wolf':'human';return $s;
}
function alphano_lycan_tick(?int $now=null):int {return alphano_transformation_tick($now);}
