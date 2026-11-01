<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/ai.inc.php';
require_once __DIR__.'/../libs/revelations.inc.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
$db=ai_db();
try{
    $uid=alphano_require_login(true);$viewer=alphano_user();$admin=!empty($viewer['admin']);$method=$_SERVER['REQUEST_METHOD'];
    if(!in_array($method,['GET','POST'],true))throw new DomainException('Metodo non consentito.',405);
    $in=$method==='POST'?(str_contains($_SERVER['CONTENT_TYPE']??'','application/json')?alphano_request_json():$_POST):$_GET;
    if($method==='POST')alphano_require_csrf(true);
    $action=ai_field($in,'action',40,true);
    if($action==='preferences'&&$method==='GET'){
        echo json_encode($db->query('SELECT character_age,romance_enabled,intimacy_enabled FROM ai_player_preferences WHERE player_id=?',[$uid])[0]??['character_age'=>0,'romance_enabled'=>0,'intimacy_enabled'=>0]);exit;
    }
    if($action==='preferences'&&$method==='POST'){
        $age=ai_integer($in,'character_age',0,2000);$enabled=ai_integer($in,'romance_enabled',0,1);$intimacy=isset($in['intimacy_enabled'])?ai_integer($in,'intimacy_enabled',0,1):0;
        if(($enabled||$intimacy)&&$age<18)throw new DomainException('Le trame romantiche richiedono un personaggio adulto di età dichiarata.',422);
        $db->beginTransaction();
        $db->execute('INSERT IGNORE INTO ai_player_preferences(player_id,character_age,romance_enabled,updated_at) VALUES(?,0,0,?)',[$uid,time()]);
        $before=$db->query('SELECT character_age,romance_enabled,intimacy_enabled FROM ai_player_preferences WHERE player_id=? FOR UPDATE',[$uid])[0];
        if((int)$before['character_age']!==$age||(int)$before['romance_enabled']!==$enabled||(int)$before['intimacy_enabled']!==$intimacy){
            $db->execute('UPDATE ai_player_preferences SET character_age=?,romance_enabled=?,intimacy_enabled=?,updated_at=? WHERE player_id=?',[$age,$enabled,$intimacy,time(),$uid]);
            ai_invalidate_pair_work($uid,0,'Preferenze del personaggio aggiornate. Invia un nuovo turno.',false);
        }
        $db->commit();
        echo json_encode(['ok'=>true]);exit;
    }
    $nid=ai_integer($in,'npc_id',1,PHP_INT_MAX);
    $npc=$db->query('SELECT * FROM ai_npcs WHERE id=?',[$nid])[0]??null;
    $inactiveStaffAccess=($action==='staff_context'&&$method==='GET'&&alphano_relations_staff($uid))||($action==='admin_relation'&&$method==='POST'&&alphano_relations_editor($uid));
    if(!$npc||(!$admin&&!(int)$npc['active']&&!$inactiveStaffAccess))throw new DomainException('PNG non disponibile.',404);
    if($action==='profile'&&$method==='GET'){
        // player_id/uid parameters are deliberately ignored: caller sees only their own pair.
        $profile=ai_social_private_view($nid,$uid);$profile['knowledge']=alphano_revelations_profile('npc',$nid,$uid);
        echo json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    if($action==='staff_context'&&$method==='GET'){
        $player=ai_integer($in,'player_id',1,PHP_INT_MAX);
        echo json_encode(alphano_relations_npc_context($nid,$player,$uid),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    if($action==='admin_load'&&$method==='GET'){
        if(!$admin)throw new DomainException('Accesso riservato.',403);
        $player=isset($in['player_id'])?ai_integer($in,'player_id',1,PHP_INT_MAX):$uid;
        $identity=ai_player_identity($player);if(!$identity)throw new DomainException('Giocatore non disponibile.',404);
        echo json_encode(['npc'=>['id'=>$nid,'name'=>$npc['name']],'rules'=>ai_social_rules($nid),'balance'=>ai_npc_balance($nid),'relation'=>ai_relation($nid,$player),
            'bond'=>ai_relation_summary(ai_relation($nid,$player),ai_romance_allowed($npc,$identity,ai_social_rules($nid))),'current_context'=>ai_relation_context_current($nid,$player),
            'overview'=>ai_personality_overview($nid),'history'=>ai_personality_history($nid),
            'memories'=>$db->query('SELECT * FROM ai_personal_memories WHERE npc_id=? AND player_id=? ORDER BY id DESC LIMIT 100',[$nid,$player]),
            'events'=>$db->query('SELECT * FROM ai_relationship_events WHERE npc_id=? AND player_id=? ORDER BY id DESC LIMIT 40',[$nid,$player]),
            'transactions'=>$db->query('SELECT * FROM ai_npc_transactions WHERE npc_id=? ORDER BY id DESC LIMIT 100',[$nid]),
            'debts'=>$db->query('SELECT d.*,u.nome FROM ai_npc_debts d JOIN utenti u ON u.id=d.player_id WHERE d.npc_id=?',[$nid])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    if($method!=='POST')throw new DomainException('Azione non consentita.',405);
    if(str_starts_with($action,'admin_')&&!$admin&&!($action==='admin_relation'&&alphano_relations_editor($uid)))throw new DomainException('Accesso riservato.',403);
    $db->beginTransaction();$receipt=null;
    if($action==='admin_rules'){
        if(!is_array($in['settings']??null)||array_is_list($in['settings']))throw new DomainException('Impostazioni non valide.',422);
        $rules=ai_social_validate($in['settings']);
        $db->execute('INSERT INTO ai_npc_rules(npc_id,settings,updated_by,updated_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$nid,json_encode($rules,JSON_UNESCAPED_UNICODE),$uid,time()]);
        // Rules belong to the NPC, not the entire room. Existing public turns
        // keep their source; publication snapshots regenerate stale prose.
    }elseif($action==='admin_relation'){
        $player=ai_integer($in,'player_id',1,PHP_INT_MAX);$identity=ai_player_identity($player);if(!$identity)throw new DomainException('Giocatore non disponibile.',404);

        $reason=ai_field($in,'reason',500,true);$description=ai_field($in,'context_description',AI_RELATION_CONTEXT_MAX);$db->execute('INSERT IGNORE INTO ai_relationships(npc_id,player_id,created_at,updated_at) VALUES(?,?,?,?)',[$nid,$player,time(),time()]);
        $before=$db->query('SELECT * FROM ai_relationships WHERE npc_id=? AND player_id=? FOR UPDATE',[$nid,$player])[0];$after=$before;
        if(isset($in['revision'])||!$admin||$description!==''){$revision=$in['revision']??'';if(!is_string($revision)||!hash_equals(ai_relation_revision($before),$revision))throw new DomainException('Il rapporto o il contesto sono cambiati durante la modifica. Ricarica per conservare le nuove interazioni.',409);}
        ai_invalidate_pair_work($player,$nid,'Rapporto corretto dallo staff: interpretazione da aggiornare.',preserveDialogue:$description==='');
        foreach(AI_RELATION_METRICS as $k=>$_)$after[$k]=ai_integer($in,$k,in_array($k,['sympathy','trust','respect','honor'],true)?-100:0,100);
        $state=ai_field($in,'state',30,true);if(!in_array($state,AI_RELATION_STATES,true))throw new DomainException('Stato non valido.',422);
        if((in_array($state,['interesse','corteggiamento','innamoramento','relazione','crisi','riconciliazione'],true)||$after['attraction']||$after['love']||$after['jealousy'])&&!ai_romance_allowed($npc,$identity,ai_social_rules($nid)))throw new DomainException('Età, consenso o disponibilità non consentono una relazione romantica.',422);
        $after['state']=$state;$keys=[...array_keys(AI_RELATION_METRICS),'state'];
        $db->execute('UPDATE ai_relationships SET '.implode(',',array_map(static fn($k)=>$k.'=?',$keys)).',updated_at=? WHERE npc_id=? AND player_id=?',[...array_map(static fn($k)=>$after[$k],$keys),time(),$nid,$player]);
        if($description!=='')$after['_context']=ai_relation_context_capture($description);
        ai_relation_event($nid,$player,$before,$after,$reason,['chat_key'=>$npc['chat_key']],0,$uid);
    }elseif($action==='admin_memory'){
        $player=ai_integer($in,'player_id',1,PHP_INT_MAX);if(!ai_player_identity($player))throw new DomainException('Giocatore non disponibile.',404);
        // Match relationship corrections: pair, queued work, then memories.
        $db->execute('INSERT IGNORE INTO ai_relationships(npc_id,player_id,created_at,updated_at) VALUES(?,?,?,?)',[$nid,$player,time(),time()]);
        $db->query('SELECT npc_id FROM ai_relationships WHERE npc_id=? AND player_id=? FOR UPDATE',[$nid,$player]);
        ai_invalidate_pair_work($player,$nid,'Ricordi corretti dallo staff: interpretazione da aggiornare.',preserveDialogue:true);
        $id=ai_integer($in,'id',0,PHP_INT_MAX);$reason=ai_field($in,'reason',500,true);$text=ai_field($in,'summary',1500,true);$visible=ai_integer($in,'visible',0,1);$active=ai_integer($in,'active',0,1);
        if($id){
            $old=$db->query('SELECT * FROM ai_personal_memories WHERE id=? AND npc_id=? AND player_id=? FOR UPDATE',[$id,$nid,$player])[0]??null;if(!$old)throw new DomainException('Ricordo non disponibile.',404);
            $present=ai_relation_context_current($nid,$player);
            if($present&&$id<=$present['memory_id']){
                // A deliberate later correction is new evidence. Keep the
                // superseded receipt and its private-room scope in the archive.
                $db->execute('UPDATE ai_personal_memories SET active=0,updated_at=? WHERE id=?',[time(),$id]);
                $db->execute("INSERT INTO ai_personal_memories(npc_id,player_id,chat_key,category,summary,evidence,source,epistemic,visible,active,created_at,updated_at) VALUES(?,?,?,'annotazione',?,?,'admin','admin',?,?,?,?)",[$nid,$player,$old['chat_key'],$text,$reason,$visible,$active,time(),time()]);
            }else $db->execute("UPDATE ai_personal_memories SET summary=?,evidence=?,source='admin',epistemic='admin',visible=?,active=?,updated_at=? WHERE id=?",[$text,$reason,$visible,$active,time(),$id]);
        }else $db->execute("INSERT INTO ai_personal_memories(npc_id,player_id,chat_key,category,summary,evidence,source,epistemic,visible,active,created_at,updated_at) VALUES(?,?,?,'annotazione',?,?,'admin','admin',?,?,?,?)",[$nid,$player,$npc['chat_key'],$text,$reason,$visible,$active,time(),time()]);
        $db->execute('INSERT INTO ai_relationship_events(npc_id,player_id,before_state,after_state,reason,actor_id,created_at) VALUES(?,?,?,?,?,?,?)',[$nid,$player,json_encode($old??[]),json_encode(['summary'=>$text,'visible'=>$visible,'active'=>$active]),'Correzione memoria: '.$reason,$uid,time()]);
    }elseif($action==='admin_reverse'){
        $original=$db->query('SELECT * FROM ai_npc_transactions WHERE id=? AND npc_id=? FOR UPDATE',[ai_integer($in,'transaction_id',1,PHP_INT_MAX),$nid])[0]??null;
        if(!$original||str_starts_with($original['kind'],'reversal_'))throw new DomainException('Movimento da stornare non valido.',422);
        $key='reversal:'.$original['id'];$receipt=$db->query('SELECT * FROM ai_npc_transactions WHERE request_key=?',[$key])[0]??null;
        if(!$receipt){
            $reason=ai_field($in,'reason',500,true);$target=(int)$original['player_id'];$amount=(int)$original['amount'];
            $receipt=ai_money_transfer($nid,$target,(int)$original['player_delta']>0?'reversal_to_npc':'reversal_to_player',$amount,'Storno #'.$original['id'].': '.$reason,$key,$uid);
            $debt=$db->query('SELECT * FROM ai_npc_debts WHERE npc_id=? AND player_id=? FOR UPDATE',[$nid,$target])[0];
            $po=(int)$debt['player_owes'];$no=(int)$debt['npc_owes'];
            if($original['kind']==='loan_to_player')$po-=$amount;
            if($original['kind']==='loan_to_npc')$no-=$amount;
            if($original['kind']==='repay_npc')$po+=$amount;
            if($original['kind']==='repay_player')$no+=$amount;
            if($po<0||$no<0)throw new DomainException('Prestito già restituito: ricostruire prima i movimenti successivi.',409);
            $db->execute('UPDATE ai_npc_debts SET player_owes=?,npc_owes=?,updated_at=? WHERE npc_id=? AND player_id=?',[$po,$no,time(),$nid,$target]);
        }
    }elseif(in_array($action,['admin_fund','admin_withdraw','admin_theft'],true)){
        $player=$action==='admin_theft'?ai_integer($in,'player_id',1,PHP_INT_MAX):$uid;
        $nonce=ai_field($in,'request_id',80,true);if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$nonce))throw new DomainException('Identificativo non valido.',422);
        $receipt=ai_money_transfer($nid,$player,['admin_fund'=>'fund','admin_withdraw'=>'withdraw','admin_theft'=>'theft_resolved'][$action],ai_integer($in,'amount',1,1000000000),ai_field($in,'reason',500,true),'admin:'.$uid.':'.$nonce,$uid);
    }elseif($action==='gift'){
        $rules=ai_social_rules($nid);if(!$rules['accept_gifts'])throw new DomainException('Il PNG non accetta doni.',422);
        if(($viewer['chat']??'')!==$npc['chat_key'])throw new DomainException('Raggiungi il PNG nella sua chat.',403);
        $nonce=ai_field($in,'request_id',80,true);if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$nonce))throw new DomainException('Identificativo non valido.',422);
        $receipt=ai_money_transfer($nid,$uid,'gift_to_npc',ai_integer($in,'amount',1,1000000),'Dono volontario del personaggio','gift:'.$uid.':'.$nonce,$uid);
    }elseif(in_array($action,['accept_offer','reject_offer'],true)){
        $offer=$db->query('SELECT * FROM ai_npc_offers WHERE id=? AND npc_id=? AND player_id=? FOR UPDATE',[ai_integer($in,'offer_id',1,PHP_INT_MAX),$nid,$uid])[0]??null;
        if(!$offer)throw new DomainException('Proposta non disponibile.',404);
        if($offer['status']==='accepted'&&$action==='accept_offer')$receipt=$db->query('SELECT * FROM ai_npc_transactions WHERE offer_id=?',[(int)$offer['id']])[0]??null;
        else{
            if($offer['status']!=='pending'||(int)$offer['expires_at']<time())throw new DomainException('Proposta scaduta o già gestita.',409);
            if($action==='accept_offer'){
                if(($viewer['chat']??'')!==$npc['chat_key'])throw new DomainException('Raggiungi il PNG nella sua chat.',403);
                $receipt=ai_money_transfer($nid,$uid,$offer['kind'],(int)$offer['amount'],$offer['reason'],'offer:'.$offer['id'],$uid,(int)$offer['id']);
            }
            $db->execute('UPDATE ai_npc_offers SET status=? WHERE id=?',[$action==='accept_offer'?'accepted':'rejected',(int)$offer['id']]);
        }
    }else throw new DomainException('Azione non riconosciuta.',400);
    $db->commit();echo json_encode(['ok'=>true,'receipt'=>$receipt?array_intersect_key($receipt,array_flip(['id','kind','amount','player_delta','player_after'])):null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$status=$e instanceof DomainException?(int)$e->getCode():400;if($status<400||$status>499)$status=500;if($status===500)error_log('[PNG social] '.$e);http_response_code($status);echo json_encode(['error'=>$status===500?'Operazione non riuscita. Nessun movimento applicato.':$e->getMessage()],JSON_UNESCAPED_UNICODE);}
