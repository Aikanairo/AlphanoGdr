<?php
declare(strict_types=1);
require_once __DIR__.'/player-runtime.inc.php';
require_once __DIR__.'/profile-art.inc.php';
require_once __DIR__.'/libferite.inc.php';
require_once __DIR__.'/session-control.inc.php';

/** A Master may inspect the current public cast, never a remote/private room. */
function alphano_participants_actor(int $actor,string $chat):array {
    if($chat===''||strlen($chat)>100)throw new DomainException('Luogo non valido.',422);
    $user=ConnectDB()->query('SELECT id,nome,chat,admin,fatepower,bloccato FROM utenti WHERE id=?',[$actor])[0]??null;
    if(!$user||!empty($user['bloccato'])||(empty($user['admin'])&&empty($user['fatepower'])))throw new DomainException('Accesso riservato ai Master.',403);
    if($user['chat']!==$chat)throw new DomainException('Entra nella chat prima di gestirne i personaggi.',403);
    return $user;
}
function alphano_participants_state(int $actor,string $chat):array {
    $user=alphano_participants_actor($actor,$chat);$db=ConnectDB();
    $session=$db->query('SELECT id,nome,partecipanti FROM sessioni WHERE BINARY chat=BINARY ? AND run=1 ORDER BY id DESC LIMIT 1',[$chat])[0]??null;
    $members=$session?array_map('intval',array_filter(explode(';',(string)$session['partecipanti']))):[];
    $people=[];
    foreach($db->query("SELECT id,nome,puntif,ferite,denari,sesso,razza,vampire_human_form,avatar,avatar2 FROM utenti WHERE BINARY chat=BINARY ? AND bloccato=0 AND posizione<>'off' ORDER BY nome,id",[$chat]) as $row){
        $portrait=alphano_profile_portrait($row);
        $people[]=['kind'=>'pg','id'=>(int)$row['id'],'name'=>$row['nome'],'portrait'=>$portrait['url'],'fallback'=>$portrait['default'],
            'hp'=>(int)$row['puntif'],'max_hp'=>maxpuntif,'wounds'=>$row['ferite'],'crowns'=>(int)$row['denari'],
            'member'=>in_array((int)$row['id'],$members,true),'alive'=>(int)$row['puntif']>0,'self'=>(int)$row['id']===$actor];
    }
    $manage=!empty($user['admin'])||alphano_session_can_manage($actor,$chat);
    return ['chat'=>$chat,'actor'=>$actor,'can_manage'=>$manage,'session'=>$session?['id'=>(int)$session['id'],'name'=>$session['nome'],'participants'=>$members]:null,
        'participants'=>$people,'max_targets'=>20,'max_quantity'=>100,'server_time'=>time(),
        'notice'=>$manage?'Gli interventi modificano le schede e lasciano una ricevuta nel registro.':'Puoi consultare i presenti. Apri o assumi la regia della sessione per intervenire.'];
}
function alphano_participants_catalog(int $actor,string $chat,array $input):array {
    alphano_participants_actor($actor,$chat);$query=player_text($input,'q',100);$type=player_text($input,'type',20);
    if(!in_array($type,['','objects','materials'],true))throw new DomainException('Filtro del catalogo non valido.',422);
    $offset=filter_var($input['offset']??0,FILTER_VALIDATE_INT);if($offset===false||$offset<0||$offset>10000)throw new DomainException('Pagina del catalogo non valida.',422);
    $where=['e.approvato=1','e.corp=0'];$parameters=[];
    if($query!==''){$where[]='e.shortDesc LIKE ?';$parameters[]='%'.$query.'%';}
    if($type!==''){$where[]='LOWER(e.materiaprima) '.($type==='materials'?'IN':'NOT IN').' ('.implode(',',array_fill(0,count(MATERIE_PRIME),'?')).')';array_push($parameters,...MATERIE_PRIME);}
    $condition=implode(' AND ',$where);$db=ConnectDB();$total=(int)$db->query('SELECT COUNT(*) n FROM equip e WHERE '.$condition,$parameters)[0]['n'];
    $items=[];foreach($db->query('SELECT e.id,e.shortDesc,e.immagine,e.materiaprima,e.durata FROM equip e WHERE '.$condition.' ORDER BY e.shortDesc,e.id LIMIT 24 OFFSET '.(int)$offset,$parameters) as $item)
        $items[]=['id'=>(int)$item['id'],'name'=>$item['shortDesc'],'image'=>alphano_equipment_image($item['immagine']),'material'=>in_array(mb_strtolower($item['materiaprima']),MATERIE_PRIME,true),'durability'=>(int)$item['durata']];
    return ['items'=>$items,'total'=>$total,'offset'=>(int)$offset,'next'=>$offset+count($items)<$total?$offset+24:null];
}
function alphano_participants_inventory(int $actor,string $chat,int $target):array {
    alphano_participants_actor($actor,$chat);$db=ConnectDB();
    if(!$db->query("SELECT id FROM utenti WHERE id=? AND BINARY chat=BINARY ? AND posizione<>'off' AND bloccato=0",[$target,$chat]))throw new DomainException('Il personaggio non è più presente.',409);
    $items=[];foreach($db->query('SELECT p.id,p.uid,p.nome,p.slot,p.idpadre,p.consumo,e.shortDesc,e.immagine,e.durata,e.materiaprima FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.idpg=? ORDER BY e.shortDesc,p.id',[$target]) as $item)
        $items[]=['id'=>(int)$item['id'],'model'=>(int)$item['uid'],'name'=>$item['nome']?:$item['shortDesc'],'image'=>alphano_equipment_image($item['immagine']),
            'slot'=>$item['slot'],'container'=>(int)$item['idpadre'],'used'=>(int)$item['consumo'],'durability'=>(int)$item['durata'],'remaining'=>max(0,(int)$item['durata']-(int)$item['consumo']),
            'broken'=>(int)$item['consumo']>=(int)$item['durata'],'material'=>in_array(mb_strtolower($item['materiaprima']),MATERIE_PRIME,true)];
    return ['target'=>$target,'items'=>$items];
}
function alphano_participants_int(array $input,string $name,int $min,int $max):int {
    $raw=$input[$name]??null;
    if(!is_int($raw)&&(!is_string($raw)||!preg_match('/^[0-9]+$/D',$raw)))throw new DomainException('Valore non valido: '.$name.'.',422);
    $value=filter_var($raw,FILTER_VALIDATE_INT);
    if($value===false||$value<$min||$value>$max)throw new DomainException('Valore non valido: '.$name.'.',422);
    return $value;
}
/** Reserve the whole batch before effects, then recheck management and presence. */
function alphano_participants_mutate(int $actor,string $chat,array $input):array {
    alphano_participants_actor($actor,$chat);$action=player_text($input,'action',30,true);
    if(!in_array($action,['health','note','crowns','item','remove','break','consume'],true))throw new DomainException('Intervento non valido.',422);
    $reason=player_text($input,'reason',4000,true);if(mb_strlen($reason)<5||mb_strlen($reason)>1000)throw new DomainException('Descrivi la motivazione dell’intervento, da 5 a 1000 caratteri.',422);
    $key=player_text($input,'request_id',100,true);if(!preg_match('/^[a-zA-Z0-9:_-]{16,100}$/D',$key))throw new DomainException('Identificativo della richiesta non valido.',422);
    $targets=$input['targets']??null;
    if(!is_array($targets)||!array_is_list($targets)||!$targets||count($targets)>20)throw new DomainException('Seleziona da uno a venti personaggi.',422);
    $ids=[];foreach($targets as $target)$ids[]=alphano_participants_int(['target'=>$target],'target',1,PHP_INT_MAX);
    if(count(array_unique($ids))!==count($ids))throw new DomainException('Un personaggio non può comparire due volte.',422);sort($ids);
    $expectedSession=alphano_participants_int($input,'session_id',0,PHP_INT_MAX);$values=[];
    if($action==='health'){$values['hp']=alphano_participants_int($input,'hp',1,maxpuntif);$values['wounds']=player_text($input,'wounds',1020);if(mb_strlen($values['wounds'])>255)throw new DomainException('Descrizione delle ferite troppo lunga.',422);$values['expected']=$input['expected']??null;if(!is_array($values['expected']))throw new DomainException('Ricarica le condizioni dei personaggi prima di modificarle.',409);}
    elseif($action==='note'){$values['note']=player_text($input,'note',16000,true);if(mb_strlen($values['note'])>4000)throw new DomainException('Annotazione troppo lunga.',422);}
    elseif($action==='crowns')$values['amount']=alphano_participants_int($input,'amount',1,1000000000);
    elseif($action==='item'){$values['item_id']=alphano_participants_int($input,'item_id',1,PHP_INT_MAX);$values['quantity']=alphano_participants_int($input,'quantity',1,100);}
    else{if(count($ids)!==1)throw new DomainException('Gli oggetti si gestiscono per un personaggio alla volta.',422);$values['instance']=alphano_participants_int($input,'instance',1,PHP_INT_MAX);if($action==='consume')$values['quantity']=alphano_participants_int($input,'quantity',1,2147483647);}
    $db=ConnectDB();$guard=alphano_session_guard($chat);
    if(!(int)($db->query('SELECT GET_LOCK(?,5) locked',[$guard])[0]['locked']??0))throw new DomainException('La regia sta aggiornando la scena. Riprova.',409);
    $db->beginTransaction();
    try{
        $db->query('SELECT chat_key FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$chat]);
        alphano_session_current($chat,true);
        if(!alphano_operation_once('master-participants',$actor,$key,[$chat,$expectedSession,$action,$ids,$reason,$values])){
            $db->commit();return ['success'=>true,'replayed'=>true,'message'=>'Intervento già registrato: nessun effetto ripetuto.','state'=>alphano_participants_state($actor,$chat)];
        }
        $session=alphano_session_require_manager($actor,$chat);
        if((int)($session['id']??0)!==$expectedSession)throw new DomainException('La sessione è cambiata. Ricarica il pannello.',409);
        $members=array_map('intval',array_filter(explode(';',(string)($session['partecipanti']??''))));
        $lockIds=array_values(array_unique([...$ids,$actor]));sort($lockIds);
        $users=array_column($db->query('SELECT id,nome,chat,posizione,bloccato,puntif,ferite FROM utenti WHERE id IN('.implode(',',array_fill(0,count($lockIds),'?')).') ORDER BY id FOR UPDATE',$lockIds),null,'id');
        foreach($ids as $id){
            $target=$users[$id]??null;
            if(!$target||$target['chat']!==$chat||$target['posizione']==='off'||!empty($target['bloccato']))throw new DomainException('Un personaggio selezionato non è più presente.',409);
            if(in_array($action,['crowns','item'],true)&&($id===$actor||!$session||!in_array($id,$members,true)))throw new DomainException('I premi richiedono un partecipante della sessione diverso dal Master.',422);
            if($action==='health'){$old=$values['expected'][(string)$id]??null;if(!is_array($old)||(int)($old['hp']??-1)!==(int)$target['puntif']||($old['wounds']??null)!==$target['ferite'])throw new DomainException('Le condizioni di '.$target['nome'].' sono cambiate. Aggiorna prima di intervenire.',409);}
        }
        foreach($ids as $id){
            $subKey='participants:'.substr(hash('sha256',$key.':'.$id),0,64);$replayed=false;
            $ok=match($action){
                'health'=>SetFerite($id,$values['hp'],$values['wounds'],$actor,$chat,$subKey,$replayed,$reason,true),
                'note'=>NotaMaster($id,$actor,$chat,$values['note'],$subKey,$replayed,$reason,true),
                'crowns'=>Ricompensa($id,$values['amount'],$actor,$chat,$subKey,$replayed,$reason,true),
                'item'=>DaiOggetto($id,$values['item_id'],$actor,$chat,$subKey,$replayed,$values['quantity'],$reason,true),
                'remove'=>RimuoviOggettoMaster($id,$values['instance'],$actor,$chat,$subKey,$replayed,$reason,true),
                'break'=>RompiOggettoMaster($id,$values['instance'],$actor,$chat,$subKey,$replayed,$reason,true),
                'consume'=>alphano_participants_consume($id,$actor,$chat,$values,$subKey,$reason),
            };
            if(!$ok)throw new DomainException('L’intervento non è applicabile alla scheda selezionata.',422);
        }
        $db->commit();ai_quest_sync_logs();return ['success'=>true,'replayed'=>false,'message'=>'Intervento registrato per '.count($ids).' '.(count($ids)===1?'personaggio.':'personaggi.'),'state'=>alphano_participants_state($actor,$chat)];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{$db->query('SELECT RELEASE_LOCK(?)',[$guard]);}
}
function alphano_participants_consume(int $target,int $actor,string $chat,array $values,string $key,string $reason):bool {
    $item=ConnectDB()->query('SELECT p.consumo,e.durata FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.id=? AND p.idpg=? FOR UPDATE',[$values['instance'],$target])[0]??null;
    if(!$item)throw new DomainException('L’oggetto non appartiene più al personaggio selezionato.',409);
    $remaining=max(0,(int)$item['durata']-(int)$item['consumo']);$quantity=$values['quantity'];
    if($quantity>$remaining)throw new DomainException('Il consumo supera la durata residua dell’oggetto.',422);
    $replayed=false;return ConsumaMaster($target,$values['instance'],$quantity,$actor,$chat,$key,$replayed,$reason,true);
}
