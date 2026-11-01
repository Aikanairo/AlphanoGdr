<?php
declare(strict_types=1);
require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__ . '/libchat.inc.php';
require_once __DIR__ . '/libequip.inc.php';
require_once __DIR__ . '/operations.inc.php';
require_once __DIR__ . '/activity.inc.php';
require_once __DIR__.'/transformations.inc.php';

/** Una modifica al personaggio e il relativo log devono riuscire insieme. */
function AlphanoMasterAction(int $pg, int $executor, string $chat, string $action, callable $change, ?string $requestKey=null, array $payload=[], ?bool &$replayed=null,string $reason='',bool $journal=false): bool
{
    $db = ConnectDB();
    $replayed=false;
    $own=!$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $users=array_column($db->query('SELECT * FROM utenti WHERE id IN (?,?) ORDER BY id FOR UPDATE',[$pg,$executor]),null,'id');
        $target=$users[$pg] ?? null; $actor=$users[$executor] ?? null;
        $role=match(true) {
            str_starts_with($action,'uccidi('),$action==='impostaferite'=>!empty($actor['fatepower']) || !empty($actor['adminferite']),
            $action==='resuscita'=>!empty($actor['adminmorti']),
            default=>!empty($actor['fatepower']),
        };
        if (!$actor || !empty($actor['bloccato']) || (empty($actor['admin']) && !$role)) throw new DomainException('Mandato Master o di cura non valido',403);
        if ($chat==='' || $actor['chat']!==$chat || !$target || $target['chat']!==$chat) throw new DomainException('Il personaggio deve essere presente nella stessa chat',400);
        $session=$db->query('SELECT id,partecipanti FROM sessioni WHERE chat=? AND run=1 ORDER BY id DESC LIMIT 1 FOR UPDATE',[$chat])[0] ?? null;
        $reward=str_starts_with($action,'ricompensa(');
        if ($reward && (!$session || $pg===$executor || !in_array((string)$pg,array_filter(explode(';',$session['partecipanti'])),true))) throw new DomainException('Il premio richiede una sessione attiva e un partecipante diverso dal Master',400);
        $scope=match($action) {'ricompensa(soldi)'=>'master-reward-money','ricompensa(oggetto)'=>'master-reward-item','consuma'=>'master-consume',default=>'master-'.preg_replace('/[^a-z0-9_-]/i','-',$action)};
        $operationPayload=[$pg,$chat,$action,$reward?(int)$session['id']:0,$payload];
        if($reason!=='')$operationPayload[]=$reason;
        if (!alphano_operation_once($scope,$executor,$requestKey,$operationPayload)) {
            $replayed=true;
            if ($own) $db->commit();
            return true;
        }
        $snapshot=static function(array $user) use($db,$pg): array {
            return ['denari'=>(int)$user['denari'],'puntif'=>(int)$user['puntif'],'razza'=>(int)$user['razza'],'ferite'=>(string)$user['ferite'],
                'oggetti'=>(int)$db->query('SELECT COUNT(*) AS total FROM equippg WHERE idpg=?',[$pg])[0]['total']];
        };
        $before=$snapshot($target);
        $result = $change($db,$target);
        if ($result === false) { if ($own) $db->rollBack(); return false; }
        [$text,$quantity,$item,$description] = $result;
        $db->execute('INSERT INTO logs_adminpg (azione,data,chat,esecutore,destinatario,qta,obj,descr) VALUES (?,?,?,?,?,?,?,?)',
            [$action,time(),$chat,$executor,$pg,$quantity,$item,$description.($reason!==''?"\nMotivazione: ".$reason:'')]);
        $after=$snapshot($db->query('SELECT * FROM utenti WHERE id=?',[$pg])[0]);
        $title=match($action) {'ricompensa(soldi)'=>alphano_currency_text('Premio in Corone consegnato'),'ricompensa(oggetto)'=>'Oggetto premio consegnato','consuma'=>'Oggetto consumato dal Master','rimuovi'=>'Oggetto rimosso dal Master','rompi'=>'Oggetto rotto dal Master','nota'=>'Annotazione del Master registrata','impostaferite'=>'Condizione del personaggio aggiornata','resuscita'=>'Personaggio resuscitato',default=>'Stato del personaggio aggiornato'};
        $section=match($action) {'ricompensa(soldi)'=>'ricompensa','ricompensa(oggetto)'=>'oggetto','consuma','rimuovi','rompi'=>'consuma','nota'=>'addnote','impostaferite'=>'ferisci','resuscita'=>'resuscita',default=>'uccidi'};
        alphano_record_activity($scope,$executor,[
            ['scope'=>'master','id'=>0,'section'=>$section,'role'=>'admin'],
            ['scope'=>'personale','id'=>$pg,'section'=>in_array($action,['consuma','rimuovi','rompi','ricompensa(oggetto)'],true)?'oggetti':($action==='ricompensa(soldi)'?'trasferimenti':'profilo'),'role'=>'member'],
        ],$title,$actor['nome'].': '.html_entity_decode(strip_tags($target['nome'].' '.$text),ENT_QUOTES|ENT_HTML5,'UTF-8'),
            ['destinatario'=>$pg,'esecutore'=>$executor,'chat'=>$chat,'sessione'=>(int)($session['id'] ?? 0),'quantita'=>(int)$quantity,'oggetto'=>(int)$item,'reason'=>$reason,'before'=>$before,'after'=>$after,'operation'=>$result[4] ?? []]);
        $html='<span class="Off">'.htmlspecialchars($target['nome'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').' '.$text.'</span>';
        if($action==='nota'){
            // Notes and their existence belong to the private Master register.
        }elseif($journal&&$session){
            // The shared session outbox commits with the whole multi-PG batch.
            // A later filesystem failure can be retried without phantom history.
            require_once __DIR__.'/ai.inc.php';
            ai_quest_publish((int)$session['id'],'master:'.$executor.':'.hash('sha256',$action.':'.$pg.':'.($requestKey??bin2hex(random_bytes(16)))),'',$html);
        }else InsertIntoChat($chat,$html);
        if ($own) $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function Uccidi(int $pgId,int $esecutore,int $modo,string $chat): bool
{
    $race = $modo ? ALPHANO_RACE_SPIRIT : ALPHANO_RACE_DEAD_VAMPIRE;
    $label = $modo ? 'spirito' : 'non-morto';
    return AlphanoMasterAction($pgId,$esecutore,$chat,"uccidi($label)",static function($db,$target) use($race,$label,$esecutore) {
        if (in_array((int)$target['razza'],[ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT],true)) return false;
        require_once __DIR__.'/lineages.inc.php';
        lineage_death($target,$esecutore);
        $deadRace=in_array((int)$target['razza'],[2,5],true)?ALPHANO_RACE_DEAD_VAMPIRE:$race;
        $appearance=alphano_transformation_ready()?',vampire_human_form=0':'';
        $db->execute('UPDATE utenti SET puntif=0,razza=?,infetto=0,ancestrale=0,senziente=0'.$appearance.',datamorte=? WHERE id=?', [$deadRace,time(),$target['id']]);
        return [in_array((int)$target['razza'],[2,5],true)?'viene eliminato dalla stirpe':"diventa $label",0,0,''];
    });
}

function SetFerite(int $pgId,int $pf,string $descr,int $esecutore,string $chat,?string $requestKey=null,?bool &$replayed=null,string $reason='',bool $journal=false): bool
{
    if ($pf <= 0 || mb_strlen($descr)>255) return false;
    $pf = min($pf,maxpuntif);
    $descr = trim($descr);
    return AlphanoMasterAction($pgId,$esecutore,$chat,'impostaferite',static function($db,$target) use($pf,$descr) {
        if((int)$target['puntif']<=0||in_array((int)$target['razza'],[ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT],true))return false;
        $db->execute('UPDATE utenti SET puntif=?,ferite=? WHERE id=?', [$pf,$descr,$target['id']]);
        $safe = htmlspecialchars($descr,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return ["ora ha $pf PF (ferite: $safe)",$pf,0,$descr];
    },$requestKey,[$pf,$descr],$replayed,$reason,$journal);
}

function Resuscita(int $pgId,int $esecutore,string $chat): bool
{
    return AlphanoMasterAction($pgId,$esecutore,$chat,'resuscita',static function($db,$target) {
        if (!in_array((int)$target['razza'],[ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT],true)) return false;
        $appearance=alphano_transformation_ready()?',vampire_human_form=0':'';
        $db->execute('UPDATE utenti SET razza=?,puntif=?,ferite=\'\',infetto=0,ancestrale=0,senziente=0'.$appearance.',datamorte=0 WHERE id=?', [alphano_human_race_id(),maxpuntif,$target['id']]);
        return ['resuscita',0,0,''];
    });
}

function Ricompensa(int $pgId,int $amount,int $esecutore,string $chat,?string $requestKey=null,?bool &$replayed=null,string $reason='',bool $journal=false): bool
{
    if ($amount <= 0 || $amount > 1000000000) return false;
    return AlphanoMasterAction($pgId,$esecutore,$chat,'ricompensa(soldi)',static function($db,$target) use($amount) {
        if ((int)$target['denari']>PHP_INT_MAX-$amount) throw new DomainException('Il premio supera il saldo massimo consentito',400);
        $db->execute('UPDATE utenti SET denari=denari+? WHERE id=?', [$amount,$target['id']]);
        return [alphano_currency_text("riceve in premio $amount corone"),$amount,0,''];
    },$requestKey,[$amount],$replayed,$reason,$journal);
}

function DaiOggetto(int $pgId,int $itemId,int $esecutore,string $chat,?string $requestKey=null,?bool &$replayed=null,int $quantity=1,string $reason='',bool $journal=false): bool
{
    if($quantity<1||$quantity>100)return false;
    return AlphanoMasterAction($pgId,$esecutore,$chat,'ricompensa(oggetto)',static function($db,$target) use($itemId,$quantity) {
        $item = $db->query('SELECT id,shortDesc FROM equip WHERE id=? AND approvato=1 AND corp=0', [$itemId])[0] ?? null;
        if (!$item) throw new DomainException('Oggetto premio non disponibile',400);
        $instances=[];for($i=0;$i<$quantity;$i++){$db->execute('INSERT INTO equippg(idpg,uid) VALUES (?,?)', [$target['id'],$itemId]);$instances[]=(int)$db->lastInsertID();}
        $receipt=['istanza'=>$instances[0]];if($quantity>1)$receipt['istanze']=$instances;
        return ['riceve in premio '.($quantity>1?$quantity.' × ':'').htmlspecialchars($item['shortDesc'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),$quantity,$itemId,'',$receipt];
    },$requestKey,$quantity===1?[$itemId]:[$itemId,$quantity],$replayed,$reason,$journal);
}

function ConsumaMaster(int $pg,int $instance,int $quantity,int $executor,string $chat,?string $requestKey=null,?bool &$replayed=null,string $reason='',bool $journal=false): bool
{
    if ($instance<1 || $quantity<1 || $quantity>2147483647) return false;
    return AlphanoMasterAction($pg,$executor,$chat,'consuma',static function($db,$target) use($instance,$quantity) {
        $item=$db->query('SELECT p.uid,p.consumo,e.shortDesc FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.id=? AND p.idpg=? FOR UPDATE',[$instance,$target['id']])[0] ?? null;
        if (!$item || !Consuma((int)$target['id'],$instance,$quantity)) throw new DomainException('Oggetto del personaggio non disponibile',400);
        $after=$db->query('SELECT consumo FROM equippg WHERE id=? AND idpg=?',[$instance,$target['id']])[0] ?? null;
        return ["consuma {$quantity} unità di ".htmlspecialchars($item['shortDesc'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),$quantity,(int)$item['uid'],'istanza #'.$instance,
            ['istanza'=>$instance,'consumo_prima'=>(int)$item['consumo'],'consumo_dopo'=>$after?(int)$after['consumo']:null,'rimosso'=>$after===null]];
    },$requestKey,[$instance,$quantity],$replayed,$reason,$journal);
}

/** Broken instances persist, but cannot remain equipped or hold other items. */
function RompiOggettoMaster(int $pg,int $instance,int $executor,string $chat,?string $requestKey=null,?bool &$replayed=null,string $reason='',bool $journal=false):bool {
    if($instance<1)return false;
    return AlphanoMasterAction($pg,$executor,$chat,'rompi',static function($db,$target)use($instance,$executor){
        $item=$db->query('SELECT p.*,e.shortDesc,e.durata FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.id=? AND p.idpg=? FOR UPDATE',[$instance,$target['id']])[0]??null;
        if(!$item)throw new DomainException('Oggetto del personaggio non disponibile.',409);
        if((int)$item['consumo']>=(int)$item['durata'])throw new DomainException('L’oggetto è già rotto.',409);
        $children=$db->query('SELECT id,idpadre,slot FROM equippg WHERE idpadre=? AND idpg=? ORDER BY id FOR UPDATE',[$instance,$target['id']]);
        $hasState=(bool)$db->query("SHOW TABLES LIKE 'equipment_state'");
        $stateBefore=$hasState?($db->query('SELECT * FROM equipment_state WHERE instance_id=? FOR UPDATE',[$instance])[0]??null):null;
        $db->execute("UPDATE equippg SET consumo=?,slot='',idpadre=0 WHERE id=? AND idpg=?",[(int)$item['durata'],$instance,$target['id']]);
        $db->execute("UPDATE equippg SET idpadre=0,slot='' WHERE idpadre=? AND idpg=?",[$instance,$target['id']]);
        if($stateBefore)$db->execute("UPDATE equipment_state SET mode='',ready_round=0,ready_at=0,updated_at=? WHERE instance_id=?",[time(),$instance]);
        $db->execute("INSERT INTO logs_equip(data,esecutore,origine,obj,azione) VALUES(?,?,?,?,'ROMPI')",[time(),$executor,$target['id'],$item['uid']]);
        $after=$db->query('SELECT p.*,e.shortDesc,e.durata FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.id=? AND p.idpg=?',[$instance,$target['id']])[0];
        $stateAfter=$stateBefore?($db->query('SELECT * FROM equipment_state WHERE instance_id=?',[$instance])[0]??null):null;
        return ['vede rompersi l’oggetto '.htmlspecialchars($item['shortDesc'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),1,(int)$item['uid'],'istanza #'.$instance,['before'=>$item,'after'=>$after,'released_children'=>$children,'equipment_state_before'=>$stateBefore,'equipment_state_after'=>$stateAfter]];
    },$requestKey,[$instance],$replayed,$reason,$journal);
}

/** Remove the actual instance through the same equipment engine as player inventory. */
function RimuoviOggettoMaster(int $pg,int $instance,int $executor,string $chat,?string $requestKey=null,?bool &$replayed=null,string $reason='',bool $journal=false):bool {
    return AlphanoMasterAction($pg,$executor,$chat,'rimuovi',static function($db,$target)use($instance,$executor){
        $item=$db->query('SELECT p.*,e.shortDesc FROM equippg p JOIN equip e ON e.id=p.uid WHERE p.id=? AND p.idpg=? FOR UPDATE',[$instance,$target['id']])[0]??null;
        if(!$item||!deleteObject($instance,(int)$target['id'],$executor))throw new DomainException('Oggetto del personaggio non disponibile.',409);
        return ['perde l’oggetto '.htmlspecialchars($item['shortDesc'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),1,(int)$item['uid'],'istanza #'.$instance,['before'=>$item,'after'=>null]];
    },$requestKey,[$instance],$replayed,$reason,$journal);
}

/** A private Master note is audited, while its contents never enter public chat. */
function NotaMaster(int $pg,int $executor,string $chat,string $note,?string $requestKey=null,?bool &$replayed=null,string $reason='',bool $journal=false):bool {
    if(trim($note)===''||mb_strlen($note)>4000)return false;
    return AlphanoMasterAction($pg,$executor,$chat,'nota',static function($db,$target)use($executor,$note){
        if(!InsertNote((int)$target['id'],$executor,$note))return false;
        return ['ha ricevuto un’annotazione riservata nel registro del Master',0,0,'Annotazione Master riservata',['nota_id'=>(int)$db->lastInsertId()]];
    },$requestKey,[$note],$replayed,$reason,$journal);
}

function InsertNote(int $pgId,int $esecutore,string $text): bool
{
    $text = trim($text);
    if ($text === '' || mb_strlen($text)>65535 || !ConnectDB()->query('SELECT id FROM utenti WHERE id=?',[$pgId])) return false;
    ConnectDB()->execute('INSERT INTO notepg(master,pg,data,nota) VALUES (?,?,?,?)', [$esecutore,$pgId,time(),$text]);
    return true;
}
