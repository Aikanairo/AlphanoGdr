<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

try {
    require_once __DIR__ . '/libs/http-chat.php';
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new DomainException('Metodo non consentito', 405);
    }
    $idpg = GetIDPG(false);
    if (!$idpg) {
        throw new DomainException('Devi effettuare il login', 401);
    }
    if ($method === 'GET') session_write_close();
    $chat = $_GET['chat'] ?? '';
    if (!is_string($chat)) throw new DomainException('Chat non valida',400);
    $room = httpChatRoom($chat, $idpg);
    $db = getDB();
    $user = $db->query('SELECT id, bloccato, chat, lastmove, razza FROM utenti WHERE id = ?', [$idpg])[0] ?? null;
    if (!$user || (int)$user['bloccato']) {
        throw new DomainException('Accesso alla chat non consentito', 403);
    }

    require_once __DIR__.'/../libs/lycan.inc.php';
    $form=alphano_lycan_sync($idpg);$user['razza']=$form['race'];

    if ($method === 'GET') {
        $since = filter_var($_GET['since'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($since === false) {
            throw new DomainException('Cursore non valido', 400);
        }
        if ($user['chat'] === $chat && (int)$user['lastmove'] < time() - 60) {
            $db->execute('UPDATE utenti SET lastmove = ? WHERE id = ? AND chat = ?', [time(), $idpg, $chat]);
        }
        $initial = ($_GET['initial'] ?? '') === '1';
        require_once __DIR__.'/../libs/ai.inc.php';if(!isset($room['private_kind']))ai_quest_tick($chat);
        $result = httpChatHistory($chat, $since, $idpg, $initial, (int)$user['razza']);
        require_once __DIR__.'/../libs/ai.inc.php';
        $result['typing']=!isset($room['private_kind'])||companionship_room_npc($chat,$idpg)?ai_pending_public($chat):[];
        $result['quest']=isset($room['private_kind'])?null:ai_quest_public($chat,$idpg);
        $revision=$result['quest']?($result['quest']['id'].':'.$result['quest']['version'].':'.$result['quest']['round']):'';
        if($revision!==''&&$revision!==($_GET['questVersion']??'')){require_once __DIR__.'/../libs/chat-presentation.inc.php';$result['characters']=[];foreach($db->query('SELECT kind,actor_id FROM ai_quest_cast WHERE session_id=?',[(int)$result['quest']['id']]) as $c){$sheet=alphano_chat_character($c['kind'],(int)$c['actor_id']);if($sheet)$result['characters'][]=$sheet;}}
        $result['questVersion']=$revision;
        if(array_key_exists('relationSince',$_GET)){
            $relationSince=filter_var($_GET['relationSince'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($relationSince===false)throw new DomainException('Cursore dei rapporti non valido.',400);
            $result['relations']=ai_relation_notices($chat,$idpg,$relationSince,$initial);
        }
        if (!$initial && !$result['messages'] && !$result['more'] && !$result['typing'] && !$result['quest'] && empty($result['relations']['events']) && empty($result['relations']['more'])) {
            header('X-Chat-Cursor: ' . $result['lastId']);
            if(isset($result['relations']))header('X-Relation-Cursor: '.$result['relations']['cursor']);
            http_response_code(204);
        } else {
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        exit;
    }

    $isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    $input = $GLOBALS['alphano_legacy_ai_request'] ?? ($isJson ? alphano_request_json() : $_POST);
    if (!is_array($input) || array_is_list($input)) {
        throw new DomainException('Richiesta non valida', 400);
    }
    alphano_require_csrf(true);
    $requestId = $input['requestId'] ?? ($isJson ? '' : bin2hex(random_bytes(16)));
    if (!is_string($requestId) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $requestId)) {
        throw new DomainException('Identificativo invio non valido', 400);
    }
    $text = $input['msg'] ?? $input['message'] ?? $input['frase'] ?? null;
    $location = $input['location'] ?? '';
    if (!is_string($text) || trim($text) === '' || strlen($text) > 16000
        || !is_string($location) || strlen($location) > 255) {
        throw new DomainException('Messaggio vuoto o troppo lungo', 400);
    }
    $abilityId=filter_var($input['rpg_ability_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if($abilityId===false)throw new DomainException('Abilità non valida.',422);
    $submittedAbilityId=$abilityId;$itemId=filter_var($input['rpg_item_instance']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($itemId===false)throw new DomainException('Oggetto non valido.',422);$itemOperation=(string)($input['rpg_item_operation']??'activate');$itemProvider=max(0,(int)($input['rpg_item_provider']??0));$GLOBALS['alphano_item_claim']=$itemId?['instance'=>$itemId,'operation'=>$itemOperation,'provider'=>$itemProvider]:null;
    require_once __DIR__.'/../libs/session-control.inc.php';
    require_once __DIR__.'/../libs/chat-work.inc.php';
    $workId=rpg_int($input['work_id']??0,0,2147483647,'Lavoro');
    $workQuote=rpg_text($input['work_quote']??'',64);
    $workConfirmed=rpg_bool($input['work_confirmed']??false);
    if($workId&&($abilityId||$itemId||isset($room['private_kind'])))throw new DomainException('Il lavoro richiede un’azione pubblica separata da abilità e oggetti.',422);
    if(!$workId&&str_starts_with(ltrim($text),'+')&&$db->query('SELECT id FROM lavori WHERE chat=? AND azione=? LIMIT 1',[$chat,trim(substr(ltrim($text),1))]))throw new DomainException('Questa è un’azione lavoro: selezionala dal menu Lavori e raccolta e conferma il tentativo.',409);
    if(!$workId&&!$itemId&&!$abilityId&&!isset($room['private_kind'])&&(ai_room($chat)['quest_rules']??'legacy')==='alphano_scene_v1')$abilityId=rpg_combat_infer_ability($idpg,$text);
    $hasDeclaration=$abilityId||$itemId;if($itemId&&$abilityId)throw new DomainException('Scegli un oggetto oppure un’abilità per questa azione.',422);
    $autoAddress=array_key_exists('ai_auto',$input)?filter_var($input['ai_auto'],FILTER_VALIDATE_BOOLEAN):!array_key_exists('ai_request',$input);
    if($workId){$autoAddress=false;}
    $atomicChat=$hasDeclaration||$workId>0||(!isset($room['private_kind'])&&alphano_work_pending($idpg));
    $skillHash=hash('sha256',json_encode([$text,$location,$submittedAbilityId,...($itemId?[$itemId,$itemOperation,$itemProvider]:[]),(int)($input['umore']??0),(int)($input['ai_npc_id']??0),...(array_key_exists('ai_auto',$input)?[$autoAddress]:[]),...($workId?[$workId,$workQuote,$workConfirmed]:[])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    // Il lock della sessione serializza gli invii dello stesso giocatore.
    // Una risposta HTTP persa può essere richiesta di nuovo senza ripetere azioni/ricompense.
    $receiptKey = $chat . ':' . $requestId;
    $receipt = $_SESSION['chat_receipts'][$receiptKey] ?? null;
    if(isset($_SESSION['chat_receipt_hashes'][$receiptKey])&&!hash_equals($_SESSION['chat_receipt_hashes'][$receiptKey],$skillHash))throw new DomainException('Identificativo già usato per un altro messaggio.',409);
    if(!$hasDeclaration){$saved=$db->query('SELECT payload_hash,response_json FROM rpg_chat_receipts WHERE player_id=? AND chat_key=? AND request_key=?',[$idpg,$chat,$requestId])[0]??null;if($saved){if(!hash_equals($saved['payload_hash'],$skillHash))throw new DomainException('Identificativo già usato per un altro messaggio.',409);session_write_close();echo $saved['response_json'];exit;}}
    if ($receipt !== null && !$hasDeclaration) {
        session_write_close();
        echo json_encode($receipt, JSON_THROW_ON_ERROR);
        exit;
    }
    require_once __DIR__ . '/libs/chat-core.php';
    require_once __DIR__.'/../libs/ai.inc.php';
    if(!isset($room['private_kind'])){
      $guard=alphano_session_guard($chat);
      if(!(int)$db->query('SELECT GET_LOCK(?,5) acquired',[$guard])[0]['acquired'])throw new DomainException('Regia occupata, riprova.',409);
      register_shutdown_function(static function()use($db,$guard){$db->query('SELECT RELEASE_LOCK(?)',[$guard]);});
      ai_quest_tick($chat);

      if(str_starts_with(trim($text),'#')){ $s=alphano_session_current($chat);if($s&&!alphano_session_can_manage($idpg,$chat))throw new DomainException('La regia di questa sessione appartiene al Master che la dirige.',403); $q=ai_quest_row($chat);if($q&&$q['mode']==='human'&&((int)$q['conductor_id']!==$idpg||!ai_conductor_can($idpg,$chat)))throw new DomainException('La regia di questa quest appartiene al Master che l’ha presa in carico.',403); }
    }
    if($atomicChat){
        if($hasDeclaration&&(isset($room['private_kind'])||preg_match('/^(?:\/\/|[$&#])/u',ltrim($text))))throw new DomainException('Seleziona un’abilità soltanto per un’azione pubblica in gioco.',422);
        $db->beginTransaction();
        $db->query('SELECT chat_key FROM ai_rooms WHERE chat_key=? FOR UPDATE',[$chat]);
        $db->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$idpg]);
        $saved=$db->query('SELECT payload_hash,response_json FROM rpg_chat_receipts WHERE player_id=? AND chat_key=? AND request_key=?',[$idpg,$chat,$requestId])[0]??null;
        if($saved){if(!hash_equals($saved['payload_hash'],$skillHash))throw new DomainException('Identificativo già usato per un altro messaggio.',409);$db->commit();session_write_close();echo $saved['response_json'];exit;}
        if($abilityId){$ability=rpg_get_ability('pg',$idpg,$abilityId);rpg_combat_assert_equipment('pg',$idpg,$ability);}if($itemId){require_once __DIR__.'/../libs/item-engine.inc.php';item_instance($itemId,$idpg,true);if(!ai_quest_row($chat))throw new DomainException('Per dichiarare un oggetto dalla chat serve una quest attiva. Fuori quest usa l’equipaggiamento.',409);}
        if($hasDeclaration&&!str_starts_with(ltrim($text),'+'))$text='+'.ltrim($text);
    }
    $workPrepared=$workId?alphano_work_prepare($idpg,$chat,$workId,$text,$workConfirmed,$workQuote):null;
    if(!isset($room['private_kind']))ai_quest_check_turn($chat,$idpg,$text);
    $GLOBALS['vampiredamage'] = (bool)$room['vampiredamage'];
    $GLOBALS['private_chat'] = isset($room['private_kind']);
    if($atomicChat)$GLOBALS['alphano_defer_chat_log']=true;
    $parsed = parseClient([
        'chat' => $chat, 'pg' => $idpg, 'msg' => $text,
        'umore' => (int)($input['umore'] ?? 0), 'location' => $location,
        'position' => $room['position'],
    ]);
    if ($parsed['html'] === '') {
        throw new DomainException('Questo messaggio non può essere inviato', 422);
    }
    if($abilityId)$parsed['html'].=rpg_combat_badge($ability);
    clearOldMessages($chat);
    $messageId = insertHtml($chat, $parsed['html'], $idpg, (int)$parsed['dest'], $parsed['location'], $parsed['lican']);
    if(!isset($room['private_kind']))alphano_session_capture_message($chat,$messageId,$idpg,$text,(int)$parsed['dest']);
    require_once __DIR__.'/../libs/board-games.inc.php';bg_narrative($chat,$idpg,(int)$parsed['dest'],$text,$messageId);
    $result = ['ok'=>true, 'id' => $messageId, 'location' => $parsed['location']];
    if($workPrepared){$result['work']=alphano_work_complete($idpg,$chat,$requestId,$messageId,$workPrepared);}
    else try {
        require_once __DIR__.'/../libs/ai.inc.php';
        $result += ai_after_message($chat,$messageId,$idpg,$parsed['html'],$text,(int)$parsed['dest'],$parsed['lican'],!empty($input['ai_request']),max(0,(int)($input['ai_npc_id']??0)),$abilityId,$autoAddress);
    } catch (Throwable $aiError) { if($atomicChat)throw $aiError;error_log('[Master IA hook] '.$aiError->getMessage()); }
    if(!$workId&&$atomicChat)alphano_work_narrated($idpg,$chat,$text,(int)$parsed['dest'],$messageId);
    if($atomicChat){$logSession=(int)($GLOBALS['alphano_deferred_chat_session']??(ai_quest_row($chat)['session_id']??0));if($logSession)$db->execute('INSERT IGNORE INTO ai_quest_outbox(session_id,event_key,html,created_at,chat_id) VALUES(?,?,?,?,?)',[$logSession,'rpg:chat:'.$messageId,$parsed['html'],time(),$messageId]);$db->execute('INSERT INTO rpg_chat_receipts(player_id,chat_key,request_key,payload_hash,response_json,created_at) VALUES(?,?,?,?,?,?)',[$idpg,$chat,$requestId,$skillHash,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),time()]);$db->commit();}
    $_SESSION['chat_receipt_hashes'][$receiptKey]=$skillHash;
    $_SESSION['chat_receipt_hashes']=array_slice($_SESSION['chat_receipt_hashes'],-100,null,true);
    $_SESSION['chat_receipts'][$receiptKey] = $result;
    $_SESSION['chat_receipts'] = array_slice($_SESSION['chat_receipts'], -100, null, true);
    session_write_close();
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $status = $e instanceof DomainException ? $e->getCode() : ($e instanceof JsonException ? 400 : 500);
    if($status<400||$status>599)$status=500;
    http_response_code($status);
    if ($status === 500) {
        error_log('[chat HTTP] ' . $e);
        $reference=bin2hex(random_bytes(6));
        $entry=json_encode(['at'=>gmdate('c'),'reference'=>$reference,'type'=>get_class($e),'message'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE);
        error_log($entry."\n",3,dirname(__DIR__).'/private/chat-errors.log');
    }
    $failure=['error' => $status === 500 ? 'Invio non riuscito. Riprova.' : $e->getMessage()];
    if(isset($reference))$failure['reference']=$reference;
    if($e instanceof AlphanoRentalExpired)$failure['rental_expired']=$e->rental;
    if(class_exists('AIQuestTurnException',false)&&$e instanceof AIQuestTurnException){
        try{$failure['notice']=['kind'=>'quest_turn','quest'=>ai_quest_public($chat,$idpg)];}catch(Throwable $ignored){/* L'errore originale resta disponibile anche senza lo stato aggiornato. */}
    }
    echo json_encode($failure, JSON_UNESCAPED_UNICODE);
}
