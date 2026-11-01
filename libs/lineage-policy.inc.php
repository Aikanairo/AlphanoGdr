<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';
require_once __DIR__.'/transformations.inc.php';

function lineage_kind(array $user):?string {
    if(!alphano_config('lineages_enabled'))return null;
    $r=(int)($user['razza']??0);
    return in_array($r,[2,5],true)?'vampire':((in_array($r,[3,6],true)||(in_array($r,[1,alphano_human_race_id()],true)&&(!empty($user['ancestrale'])||!empty($user['infetto']))))?'lycan':null);
}
function lineage_primordial(array $user,string $kind):bool {
    return $kind==='vampire'?(int)$user['razza']===5:((int)$user['razza']===6||!empty($user['ancestrale']));
}
/** Primordial age means registration date; ID breaks ties deterministically. */
function lineage_family_users(string $kind,int $exclude=0):array {
    if(!in_array($kind,['vampire','lycan'],true))throw new DomainException('Stirpe non valida.',422);
    $human=alphano_human_race_id();$where=$kind==='vampire'?'u.razza IN(2,5)':"(u.razza IN(3,6) OR (u.razza IN(1,$human) AND (u.infetto=1 OR u.ancestrale=1)))";
    $db=ConnectDB();
    // A locking/current read is essential after waiting on the family mutex: an older
    // REPEATABLE READ snapshot could otherwise nominate two simultaneous first founders.
    return $db->query("SELECT u.* FROM utenti u LEFT JOIN lineage_members l ON l.player_id=u.id AND l.kind=? WHERE $where AND u.puntif>0 AND u.id<>? AND (l.status IS NULL OR l.status='active') ORDER BY u.dataregistrazione,u.id".($db->inTransaction()?' FOR UPDATE':''),[$kind,$exclude]);
}
function lineage_policy_lock(string $kind):void {
    if(!ConnectDB()->inTransaction())throw new LogicException('La stirpe richiede una transazione.');
    ConnectDB()->query('SELECT kind FROM lineage_policy_locks WHERE kind=? FOR UPDATE',[$kind]);
}
/** Move the entire branch together and reject a creator from its own descendants. */
function lineage_store_parent(array $user,string $kind,?array $parent,string $origin,int $executor=0,string $reason='Assegnazione genealogica'):array {
    $db=ConnectDB();$id=(int)$user['id'];lineage_policy_lock($kind);
    $before=$db->query('SELECT * FROM lineage_members WHERE player_id=? AND kind=? FOR UPDATE',[$id,$kind])[0]??null;
    if($before&&in_array($before['status'],['eliminated','cancelled','pending'],true))throw new DomainException('La stirpe ha un evento storico o un contagio in corso: non può essere sostituita implicitamente.',409);
    $creator=$parent?(int)$parent['player_id']:null;$root=$parent?(int)$parent['root_id']:$id;
    if($before&&($before['creator_id']===null?null:(int)$before['creator_id'])===$creator&&(int)$before['root_id']===$root&&$before['origin']===$origin)return $before;
    $all=$db->query('SELECT player_id,creator_id FROM lineage_members WHERE kind=? ORDER BY player_id FOR UPDATE',[$kind]);$children=[];
    foreach($all as $node)$children[(int)$node['creator_id']][]=(int)$node['player_id'];
    $desc=[$id=>true];$queue=[$id];for($i=0;$i<count($queue);$i++)foreach($children[$queue[$i]]??[] as $child)if(!isset($desc[$child])){$desc[$child]=true;$queue[]=$child;}
    if($creator!==null&&isset($desc[$creator]))throw new DomainException('Il creatore non può essere il personaggio stesso o un suo discendente.',422);
    if($parent&&($parent['kind']!==$kind||$parent['status']!=='active'))throw new DomainException('Il creatore non ha una stirpe attiva compatibile.',422);
    if(!$before)$db->execute("INSERT INTO lineage_members(player_id,kind,creator_id,root_id,origin,status) VALUES(?,?,?,?,?,'active')",[$id,$kind,$creator,$root,$origin]);
    else $db->execute('UPDATE lineage_members SET creator_id=?,root_id=?,origin=? WHERE player_id=? AND kind=?',[$creator,$root,$origin,$id,$kind]);
    foreach(array_keys($desc) as $child)$db->execute('UPDATE lineage_members SET root_id=? WHERE player_id=? AND kind=?',[$root,$child,$kind]);
    $after=$db->query('SELECT * FROM lineage_members WHERE player_id=? AND kind=?',[$id,$kind])[0];
    if($before!==$after)$db->execute('INSERT INTO logs_adminpg(azione,data,chat,esecutore,destinatario,qta,obj,descr) VALUES(?,?,?,?,?,0,0,?)',['stirpe-genealogia',time(),(string)($user['chat']??''),$executor,$id,mb_substr($reason,0,80).' · '.$kind.' · creatore '.($before['creator_id']??'nessuno').' → '.($creator??'nessuno').' · capostipite '.($before['root_id']??'nessuno').' → '.$root]);
    return $after;
}
function lineage_make_primordial(array $user,string $kind,int $executor=0):array {
    $db=ConnectDB();$id=(int)$user['id'];lineage_policy_lock($kind);
    if(!lineage_primordial($user,$kind)){
        if($kind==='vampire')$db->execute('UPDATE utenti SET razza=5,infetto=0,ancestrale=0,senziente=0 WHERE id=?',[$id]);
        else $db->execute('UPDATE utenti SET razza=?,ancestrale=1,infetto=0,senziente=1 WHERE id=?',[in_array((int)$user['razza'],[3,6],true)?6:alphano_human_race_id(),$id]);
        $db->execute('INSERT INTO logs_adminpg(azione,data,chat,esecutore,destinatario,qta,obj,descr) VALUES(?,?,?,?,?,0,0,?)',['stirpe-capostipite',time(),(string)($user['chat']??''),$executor,$id,'Primo membro della stirpe: promosso automaticamente a '.'capostipite'.'.']);
    }
    return lineage_store_parent($user,$kind,null,$kind==='vampire'?'original':'ancestral',$executor,'Capostipite senza creatore');
}
/** Single orphan policy used by migration, profile reads, worker and administrative writes. */
function lineage_resolve_user(array $user):?array {
    $kind=lineage_kind($user);if(!$kind)return null;$db=ConnectDB();$own=!$db->inTransaction();if($own)$db->beginTransaction();
    try{
        lineage_policy_lock($kind);
        $user=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[(int)$user['id']])[0]??null;
        if(!$user||lineage_kind($user)!==$kind){if($own)$db->commit();return null;}
        $node=$db->query('SELECT * FROM lineage_members WHERE player_id=? AND kind=? FOR UPDATE',[$user['id'],$kind])[0]??null;
        if($node&&$node['status']!=='active'){if($own)$db->commit();return $node;}
        if(lineage_primordial($user,$kind))$node=lineage_make_primordial($user,$kind);
        elseif($node&&$node['creator_id']!==null&&$db->query('SELECT player_id FROM lineage_members WHERE player_id=? AND kind=?',[$node['creator_id'],$kind])){/* A dead sire must trigger death, never reparent a surviving child. */}
        elseif((int)$user['puntif']>0){
            $members=lineage_family_users($kind);$founders=array_values(array_filter($members,static fn($u)=>lineage_primordial($u,$kind)));
            $founder=$founders[0]??$members[0]??$user;
            $parent=lineage_make_primordial($founder,$kind);
            $node=(int)$founder['id']===(int)$user['id']?$parent:lineage_store_parent($user,$kind,$parent,'infected',0,'Creatore predefinito: capostipite più anziano');
        }
        if($own)$db->commit();return $node;
    }catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
}
function lineage_reconcile():int {
    $human=alphano_human_race_id();$count=0;foreach(ConnectDB()->query("SELECT * FROM utenti WHERE puntif>0 AND (razza IN(2,3,5,6) OR (razza IN(1,$human) AND (infetto=1 OR ancestrale=1))) ORDER BY CASE WHEN razza IN(5,6) OR ancestrale=1 THEN 0 ELSE 1 END,dataregistrazione,id") as $user){lineage_ensure($user);$count++;}return $count;
}
/** Read-only preview; the mutation recomputes this after acquiring the family lock. */
function lineage_admin_plan(array $target,int $requested):array {
    if(!alphano_config('lineages_enabled'))return ['requires_creator'=>false,'promotes'=>false,'creators'=>[],'message'=>''];
    $kind=in_array($requested,[2,5],true)?'vampire':(in_array($requested,[3,6],true)?'lycan':null);
    if(!$kind)return ['requires_creator'=>false,'promotes'=>false,'creators'=>[],'message'=>''];
    $explicit=in_array($requested,[5,6],true);$others=lineage_family_users($kind,(int)$target['id']);
    $existing=ConnectDB()->query('SELECT * FROM lineage_members WHERE player_id=? AND kind=?',[$target['id'],$kind])[0]??null;
    $founders=array_values(array_filter($others,static fn($u)=>lineage_primordial($u,$kind)));
    if($explicit)return ['kind'=>$kind,'requires_creator'=>false,'promotes'=>false,'creators'=>[],'message'=>'Il capostipite non ha un creatore.'];
    if(!$others)return ['kind'=>$kind,'requires_creator'=>false,'promotes'=>true,'creators'=>[],'message'=>'Non esistono altri membri di questa stirpe: il personaggio diventerà automaticamente '.'capostipite'.'.'];
    $default=$founders[0]??$others[0];$eligible=$kind==='lycan'?($founders?:[$default]):$others;
    // Exclude descendants from the list, including indirect ones.
    $edges=ConnectDB()->query('SELECT player_id,creator_id FROM lineage_members WHERE kind=?',[$kind]);$desc=[(int)$target['id']=>true];do{$added=false;foreach($edges as $edge)if(isset($desc[(int)$edge['creator_id']])&&!isset($desc[(int)$edge['player_id']])){$desc[(int)$edge['player_id']]=true;$added=true;}}while($added);
    $eligible=array_values(array_filter($eligible,static fn($u)=>!isset($desc[(int)$u['id']])));
    if(!$eligible)return ['kind'=>$kind,'requires_creator'=>false,'promotes'=>true,'creators'=>[],'message'=>'Non esiste un creatore esterno a questo ramo: il personaggio resta capostipite.'];
    $creators=array_map(static fn($u)=>['id'=>(int)$u['id'],'name'=>$u['nome'],'primordial'=>lineage_primordial($u,$kind)],$eligible);
    $ids=array_column($creators,'id');$selected=(int)($existing['creator_id']??0);if(!in_array($selected,$ids,true))$selected=in_array((int)$default['id'],$ids,true)?(int)$default['id']:($ids[0]??0);
    return ['kind'=>$kind,'requires_creator'=>true,'promotes'=>false,'creators'=>$creators,'default_creator_id'=>$selected,'message'=>'Chi lo sta generando? Il valore predefinito è il capostipite più anziano.'];
}
/** Administrative race selection is immediate; an in-game vampire bite still waits six hours. */
function lineage_assign_race(array $target,int $requested,?int $creator,int $executor):array {
    $db=ConnectDB();$id=(int)$target['id'];$kind=!alphano_config('lineages_enabled')?null: (in_array($requested,[2,5],true)?'vampire':(in_array($requested,[3,6],true)?'lycan':null));
    if($kind)lineage_policy_lock($kind);
    $message='';$plan=lineage_admin_plan($target,$requested);
    if(!empty($plan['promotes'])){$requested=$kind==='vampire'?5:6;$message=$plan['message'];}
    if($kind&&(int)$target['puntif']<=0)throw new DomainException('Resuscita prima il personaggio: il cambio razza non restituisce punti ferita.',422);
    if($kind&&in_array($requested,[2,3],true)){
        $creator??=(int)($plan['default_creator_id']??0);
        if(!in_array($creator,array_column($plan['creators'],'id'),true))throw new DomainException('Scegli un creatore valido della stessa stirpe, esterno alla discendenza del personaggio.',422);
        $p=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$creator])[0];
        $parent=lineage_ensure($p);
        if(!$parent||$parent['status']!=='active')throw new DomainException('Creatore non disponibile.',409);
    }
    $race=match($requested){3,6=>alphano_human_race_id(),default=>$requested};$inf=(int)($requested===3);$anc=(int)($requested===6);$sent=(int)($requested===6);
    $appearance=alphano_transformation_ready()?',vampire_human_form=?':'';$values=[$race,$inf,$anc,$sent];if($appearance!=='')$values[]=in_array($requested,[2,5],true)&&in_array((int)$target['razza'],[2,5],true)?(int)($target['vampire_human_form']??0):0;$db->execute('UPDATE utenti SET razza=?,infetto=?,ancestrale=?,senziente=?'.$appearance.' WHERE id=?',[...$values,$id]);
    $fresh=$db->query('SELECT * FROM utenti WHERE id=?',[$id])[0];
    if($kind){
        if(in_array($requested,[5,6],true))lineage_make_primordial($fresh,$kind,$executor);
        else{lineage_store_parent($fresh,$kind,$parent,'infected',$executor,'Cambio razza dal pannello admin');$message='Creatore: '.$p['nome'].'.';}
    }
    return ['user'=>$db->query('SELECT * FROM utenti WHERE id=?',[$id])[0],'message'=>$message];
}
