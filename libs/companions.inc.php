<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';
require_once __DIR__.'/ai-profile.inc.php';
require_once __DIR__.'/pvt/rentals.inc.php';

/** Paid time buys a private conversation, never affection or a sexual outcome. */
function companionship_player_consent(int $player, bool $lock=false): array {
    $row=ConnectDB()->query('SELECT * FROM ai_player_preferences WHERE player_id=?'.($lock?' FOR UPDATE':''),[$player])[0]??[];
    if ((int)($row['character_age']??0)<18 || empty($row['romance_enabled']) || empty($row['intimacy_enabled'])) {
        throw new DomainException('Dichiara un personaggio adulto e abilita separatamente trame romantiche e intimità nelle preferenze del profilo PNG.',403);
    }
    return $row;
}

function companionship_partner(array $npc): int {
    return function_exists('ai_adult_partner')?(int)ai_adult_partner((int)$npc['id']):(int)($npc['exclusive_player_id']??0);
}

/** Current game health, rather than active/public visibility, determines capacity to consent. */
function companionship_npc_available(array $npc): bool {
    $profile=ai_npc_profile($npc);
    if (empty($npc['active']) || (int)$profile['age']<18 || (int)$profile['hit_points']<=0) return false;
    $settings=ConnectDB()->query('SELECT settings FROM ai_npc_game WHERE npc_id=?',[(int)$npc['id']])[0]['settings']??'{}';
    $conditions=(string)((json_decode($settings,true)?:[])['conditions']??'');
    // This is the staff-maintained current condition field, never biography or player dialogue.
    $conditions=preg_replace('/\bnon\s+(?:è\s+)?(?:incosciente|svenut[ao]|mort[ao]|privo di sensi|priva di sensi)\b/iu','',$conditions);
    return !preg_match('/\b(?:incoscient[ei]|svenut[ao]|privo di sensi|priva di sensi|mort[ao]|decedut[ao]|unconscious|dead)\b/iu',$conditions);
}

function companionship_npc_in_quest(int $npc): bool {
    $db=ConnectDB();
    return (bool)$db->query("SELECT 1 FROM ai_quest_cast c JOIN ai_quests q ON q.session_id=c.session_id JOIN sessioni s ON s.id=c.session_id WHERE c.kind='npc' AND c.actor_id=? AND c.active=1 AND s.run=1 AND q.status IN('active','resolving','review','attention') AND EXISTS(SELECT 1 FROM mappe m WHERE BINARY m.chat_key=BINARY q.chat_key AND m.chat=1) LIMIT 1".($db->inTransaction()?' FOR UPDATE':''),[$npc]);
}

/** Called under the rental row lock by the ordinary password-join path. */
function companionship_assert_rental_join(int $rentalId,int $player): void {
    $db=ConnectDB();
    $appointment=$db->query('SELECT player_id FROM companion_appointments WHERE rental_id=? AND finished_at=0 AND expires_at>?'.($db->inTransaction()?' FOR UPDATE':''),[$rentalId,time()])[0]??null;
    if ($appointment && (int)$appointment['player_id']!==$player) throw new DomainException('Questa camera ospita una visita riservata e non può accogliere altri ospiti.',403);
}

/** Reuses the rental lock and return path; no NPC is moved out of its original public room. */
function companionship_release_expired(?string $chat=null): void {
    $db=ConnectDB();$now=time();
    $rows=$db->query('SELECT a.id,a.rental_id FROM companion_appointments a JOIN chat_rentals r ON r.id=a.rental_id WHERE a.finished_at=0 AND (a.expires_at<=? OR r.expires_at<=? OR r.closed_at>0)'.($chat!==null?' AND r.chat_key=?':''),$chat!==null?[$now,$now,$chat]:[$now,$now]);
    foreach ($rows as $row) {
        $db->beginTransaction();
        try {
            // Rental expiry elsewhere takes this same row lock first.
            $rental=$db->query('SELECT * FROM chat_rentals WHERE id=? FOR UPDATE',[(int)$row['rental_id']])[0]??null;
            $appointment=$db->query('SELECT * FROM companion_appointments WHERE id=? FOR UPDATE',[(int)$row['id']])[0]??null;
            if (!$rental || !$appointment || (int)$appointment['finished_at'] || ((int)$appointment['expires_at']>$now && (int)$rental['expires_at']>$now && !(int)$rental['closed_at'])) { $db->commit(); continue; }
            if (!(int)$rental['closed_at']) {
                $name=htmlspecialchars((string)($db->query('SELECT name FROM ai_npcs WHERE id=?',[(int)$appointment['npc_id']])[0]['name']??'La tua ospite'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                $html='<div class="MasterKey"><strong>Il tempo della visita è terminato</strong><p>'.$name.' ti saluta. La visita è conclusa.</p></div>';
                $db->execute('INSERT INTO chat(chat,ora,msg,pg,dest) VALUES(?,?,?,0,0)',[$rental['chat_key'],$now,$html]);
                $db->execute('INSERT INTO registrazionichat(registrazione,testo) SELECT id,? FROM registrazioni WHERE chat=?',[$html,$rental['chat_key']]);
                $host=$db->query('SELECT title FROM mappe WHERE chat_key=?',[$rental['host_key']])[0]['title']??'Sala comune';
                $db->execute('UPDATE utenti SET chat=?,posizione=?,lastmove=? WHERE chat=?',[$rental['host_key'],$host,$now,$rental['chat_key']]);
                $db->execute('UPDATE chat_rentals SET closed_at=?,word_lookup=NULL WHERE id=?',[$now,(int)$rental['id']]);
            }
            $db->execute('UPDATE companion_appointments SET finished_at=?,active_npc_id=NULL,active_player_id=NULL WHERE id=?',[$now,(int)$appointment['id']]);
            $db->execute('UPDATE ai_rooms SET enabled=0,version=version+1 WHERE chat_key=?',[$rental['chat_key']]);
            $db->execute("UPDATE ai_jobs SET status='cancelled',finished_at=?,error='La visita privata è terminata.' WHERE chat_key=? AND status IN('queued','running')",[$now,$rental['chat_key']]);
            $db->execute("UPDATE ai_social_jobs s JOIN ai_jobs j ON j.id=s.job_id SET s.status='cancelled',s.error='Visita terminata.' WHERE j.chat_key=? AND s.status IN('queued','running')",[$rental['chat_key']]);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
}

/** Configured NPC and rental membership, with no client-controlled NPC substitution. */
function companionship_room_npc(string $chat,?int $player=null): ?array {
    if (!preg_match('/^rent_[a-f0-9]{40}$/D',$chat)) return null;
    if (!ConnectDB()->inTransaction()) companionship_release_expired($chat);
    $db=ConnectDB();$now=time();
    $row=$db->query('SELECT n.*,a.id AS appointment_id,a.player_id AS appointment_player,a.expires_at AS appointment_expires,a.host_key AS appointment_host,a.kind AS appointment_kind FROM companion_appointments a JOIN chat_rentals r ON r.id=a.rental_id JOIN ai_npcs n ON n.id=a.npc_id JOIN ai_npc_adult p ON p.npc_id=n.id WHERE r.chat_key=? AND r.closed_at=0 AND r.expires_at>? AND a.finished_at=0 AND a.expires_at>? AND n.active=1 AND p.sexuality_enabled=1',[$chat,$now,$now])[0]??null;
    if (!$row || !companionship_npc_available($row) || companionship_npc_in_quest((int)$row['id'])) return null;
    require_once __DIR__.'/ai.inc.php';
    $owner=(int)$row['appointment_player'];
    if ($player!==null && $owner!==$player) return null;
    if (!$db->query('SELECT 1 FROM chat_rental_members m JOIN chat_rentals r ON r.id=m.rental_id WHERE r.chat_key=? AND m.player_id=?',[$chat,$owner])) return null;
    try { companionship_player_consent($owner); } catch (DomainException) { return null; }
    $user=$db->query('SELECT * FROM utenti WHERE id=?',[$owner])[0]??null;
    $partner=companionship_partner($row);
    if (!$user || $user['bloccato'] || $user['imprigionato'] || ($partner && $partner!==$owner) || !ai_romance_allowed($row,$user,ai_social_rules((int)$row['id']))) return null;
    return $row;
}

/** Public availability exposes neither the customer nor the private room key. */
function companionship_list(int $player,string $host): array {
    companionship_release_expired();
    $db=ConnectDB();
    $place=$db->query('SELECT chat_key,title,companion_enabled,companion_minutes,companion_price FROM mappe WHERE chat_key=? AND chat=1',[$host])[0]??null;
    if (!$place || !(int)$place['companion_enabled']) throw new DomainException('Questo luogo non offre incontri privati.',404);
    $preferences=$db->query('SELECT character_age,romance_enabled,intimacy_enabled FROM ai_player_preferences WHERE player_id=?',[$player])[0]??['character_age'=>0,'romance_enabled'=>0,'intimacy_enabled'=>0];
    $consenting=(int)$preferences['character_age']>=18 && !empty($preferences['romance_enabled']) && !empty($preferences['intimacy_enabled']);
    $viewer=$db->query('SELECT * FROM utenti WHERE id=?',[$player])[0]??[];
    $npcs=[];
    foreach ($db->query('SELECT n.*,p.exclusive_player_id,a.expires_at AS busy_until FROM ai_npcs n JOIN ai_npc_adult p ON p.npc_id=n.id LEFT JOIN companion_appointments a ON a.active_npc_id=n.id WHERE p.workplace_key=? AND p.companion_enabled=1 AND p.sexuality_enabled=1 AND n.active=1 ORDER BY n.name,n.id',[$host]) as $npc) {
        if (!companionship_npc_available($npc)) continue;
        $partner=companionship_partner($npc);
        $busy=!empty($npc['busy_until']);$quest=companionship_npc_in_quest((int)$npc['id']);
        $compatible=!$consenting || ($viewer && ai_romance_allowed($npc,$viewer,ai_social_rules((int)$npc['id'])));
        $available=!$busy && (!$partner || $partner===$player) && !$quest && $compatible;
        $reason=$busy?'Questa persona è impegnata in una visita.':($quest?'È impegnata in una quest.':(($partner && $partner!==$player)?'Non desidera accettare un nuovo incontro.':(!$compatible?'Le sue preferenze non consentono questo incontro.':'')));
        $npcs[]=[...ai_public_npc($npc),'available'=>$available,'status'=>$available?'available':($busy?'busy':'unavailable'),'unavailable_reason'=>$reason,'busy_until'=>(int)($npc['busy_until']??0)];
    }
    return ['host'=>$host,'title'=>$place['title'],'minutes'=>(int)$place['companion_minutes'],'price'=>(int)$place['companion_price'],'server_time'=>time(),'preferences'=>array_map('intval',$preferences),'npcs'=>$npcs];
}

function companionship_receipt(array $appointment,array $rental): array {
    return ['chat_key'=>$rental['chat_key'],'expires_at'=>(int)$appointment['expires_at'],'total_cost'=>(int)$appointment['price'],'npc_id'=>(int)$appointment['npc_id'],'appointment_id'=>(int)$appointment['id']];
}

function companionship_booking_error(Throwable $error): never {
    // Two concurrent snapshots can both see availability; the unique slot is authoritative.
    if ($error instanceof PDOException && in_array((string)$error->getCode(),['23000','40001'],true)) throw new DomainException('La disponibilità è cambiata: la persona o la camera sono già impegnate. Riprova dopo aver aggiornato l’elenco.',409,$error);
    throw $error;
}

/** Player then host/NPC locks serialize bookings; unique active slots defend every write path. */
function companionship_book(int $player,string $host,int $npcId,string $request): array {
    // Kept lazy so private-room ACLs can load this library without loading the AI worker.
    require_once __DIR__.'/ai.inc.php';
    if (!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request) || $npcId<1 || strlen($host)>100) throw new DomainException('Prenotazione non valida.',422);
    companionship_release_expired();alphano_rental_expire();
    $db=ConnectDB();$db->beginTransaction();
    try {
        $user=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$player])[0]??null;
        if (!$user || $user['bloccato'] || $user['imprigionato']) throw new DomainException('Non puoi prenotare un incontro in questo momento.',403);
        $old=$db->query('SELECT * FROM companion_appointments WHERE player_id=? AND request_key=?',[$player,$request])[0]??null;
        if ($old) {
            if ($old['host_key']!==$host || (int)$old['npc_id']!==$npcId || (int)$old['finished_at'] || (int)$old['expires_at']<=time()) throw new DomainException('Richiesta già utilizzata o visita terminata.',409);
            $rental=$db->query('SELECT * FROM chat_rentals WHERE id=?',[(int)$old['rental_id']])[0];
            if ((int)$rental['closed_at']) throw new DomainException('La visita è terminata.',409);
            $db->commit();return companionship_receipt($old,$rental);
        }
        companionship_player_consent($player,true);
        if ($user['chat']!==$host) throw new DomainException('Raggiungi prima la sala comune del luogo scelto.',403);
        $place=$db->query('SELECT * FROM mappe WHERE chat_key=? AND chat=1 FOR UPDATE',[$host])[0]??null;
        if (!$place || !(int)$place['companion_enabled']) throw new DomainException('Questo luogo non offre incontri privati.',409);
        $minutes=(int)$place['companion_minutes'];$price=(int)$place['companion_price'];
        if ($minutes<1 || $minutes>1440 || $price<0 || $price>1000000) throw new DomainException('Durata o tariffa del luogo non valida: contatta lo staff.',409);
        $npc=$db->query('SELECT n.*,p.sexuality_enabled,p.companion_enabled,p.workplace_key,p.exclusive_player_id FROM ai_npcs n JOIN ai_npc_adult p ON p.npc_id=n.id WHERE n.id=? FOR UPDATE',[$npcId])[0]??null;
        if (!$npc || !companionship_npc_available($npc) || !(int)$npc['sexuality_enabled'] || !(int)$npc['companion_enabled'] || $npc['workplace_key']!==$host) throw new DomainException('Questa persona non è disponibile in questo luogo.',409);
        if (companionship_npc_in_quest($npcId)) throw new DomainException('Questa persona è impegnata in una quest e non può lasciare la scena.',409);
        $partner=companionship_partner($npc);
        if ($partner && $partner!==$player) throw new DomainException('Questa persona non desidera accettare l’incontro.',409);
        if (!ai_romance_allowed($npc,$user,ai_social_rules($npcId))) throw new DomainException('Le preferenze e i limiti della persona non consentono questo incontro.',409);
        if ($db->query('SELECT id FROM companion_appointments WHERE active_npc_id=? OR active_player_id=?',[$npcId,$player])) throw new DomainException('La persona è già impegnata, oppure hai già una visita in corso.',409);
        if ((int)$user['denari']<$price) throw new DomainException(alphano_currency_text('Non hai abbastanza Corone per questo incontro.'),409);
        $now=time();$expires=$now+$minutes*60;$key='rent_'.bin2hex(random_bytes(20));
        // No password exists to guess or share: only this named customer's membership grants access.
        $rentalRequest='cmp:'.hash('sha256',$player.':'.$request);
        $db->execute('INSERT INTO chat_rentals(chat_key,host_key,owner_id,word_hash,word_lookup,hours,hourly_cost,total_cost,started_at,expires_at,request_key) VALUES(?,?,?,?,NULL,0,0,?,?,?,?)',[$key,$host,$player,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$price,$now,$expires,$rentalRequest]);
        $rentalId=(int)$db->lastInsertId();
        $db->execute('INSERT INTO companion_appointments(npc_id,player_id,rental_id,host_key,started_at,expires_at,request_key,price,active_npc_id,active_player_id) VALUES(?,?,?,?,?,?,?,?,?,?)',[$npcId,$player,$rentalId,$host,$now,$expires,$request,$price,$npcId,$player]);
        $id=(int)$db->lastInsertId();
        $db->execute('INSERT INTO chat_rental_members(rental_id,player_id,joined_at) VALUES(?,?,?)',[$rentalId,$player,$now]);
        companionship_prepare_ai_room($key,$place,$player,$now);
        $db->execute('UPDATE utenti SET denari=denari-?,chat=?,posizione=?,lastmove=? WHERE id=?',[$price,$key,$place['title'].' · Camera '.$rentalId,$now,$player]);
        $db->execute('INSERT INTO logs_adminpg(azione,data,chat,esecutore,destinatario,qta,obj,descr) VALUES(?,?,?,?,?,?,0,?)',['compagnia-camera',$now,$host,$player,$player,$price,$minutes.' minuti · incontro privato con '.$npc['name'].' · prenotazione #'.$id]);
        $db->commit();
        return ['chat_key'=>$key,'expires_at'=>$expires,'total_cost'=>$price,'npc_id'=>$npcId,'appointment_id'=>$id];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); companionship_booking_error($e); }
}

/** The rental and configured NPC keep their identities; the temporary scene has separate context. */
function companionship_prepare_ai_room(string $key,array $place,int $player,int $now): void {
    $db=ConnectDB();$source=$db->query('SELECT * FROM ai_rooms WHERE chat_key=?',[$place['chat_key']])[0]??[];
    $db->execute("INSERT INTO ai_rooms(chat_key,enabled,mode,setting,atmosphere,plot,style,cooldown,updated_by,updated_at) VALUES(?,1,'request',?,?,?,?,0,?,?) ON DUPLICATE KEY UPDATE enabled=1,version=version+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)",[$key,'Visita privata presso '.$place['title'].'. Rispetta le preferenze e il consenso dei partecipanti. '.($source['setting']??''),$source['atmosphere']??'',$source['plot']??'',$source['style']??'Evocativo, rispettoso, concreto.',$player,$now]);
}

/** Only the owner can invite a character into an existing rental. No shared password is revealed. */
function companionship_invitations(int $player,int $npcId): array {
    companionship_release_expired();alphano_rental_expire();$db=ConnectDB();
    $npc=$db->query('SELECT n.*,p.sexuality_enabled FROM ai_npcs n JOIN ai_npc_adult p ON p.npc_id=n.id WHERE n.id=? AND n.active=1',[$npcId])[0]??null;
    if (!$npc || !(int)$npc['sexuality_enabled'] || !companionship_npc_available($npc) || companionship_npc_in_quest($npcId)) throw new DomainException('Questa persona non accetta inviti privati in questo momento.',404);
    $rooms=$db->query('SELECT r.chat_key,r.expires_at,m.title,a.id AS appointment_id FROM chat_rentals r JOIN chat_rental_members p ON p.rental_id=r.id AND p.player_id=r.owner_id LEFT JOIN mappe m ON m.chat_key=r.host_key LEFT JOIN companion_appointments a ON a.rental_id=r.id AND a.finished_at=0 WHERE r.owner_id=? AND r.closed_at=0 AND r.expires_at>? ORDER BY r.expires_at DESC',[$player,time()]);
    return ['npc_id'=>$npcId,'server_time'=>time(),'rooms'=>array_map(static fn($r)=>['chat_key'=>$r['chat_key'],'title'=>($r['title']??'Locanda').' · Camera riservata','expires_at'=>(int)$r['expires_at'],'available'=>empty($r['appointment_id'])],$rooms)];
}

/** A free invitation requires the relationship evaluator, independent of any payment. */
function companionship_invite(int $player,int $npcId,string $chat,string $request): array {
    require_once __DIR__.'/ai.inc.php';
    if (!preg_match('/^rent_[a-f0-9]{40}$/D',$chat) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request) || $npcId<1) throw new DomainException('Invito non valido.',422);
    companionship_release_expired();alphano_rental_expire();$db=ConnectDB();$db->beginTransaction();
    try {
        // Match the rental-expiry lock order before locking the owner's account.
        $rental=$db->query('SELECT r.* FROM chat_rentals r JOIN chat_rental_members m ON m.rental_id=r.id AND m.player_id=? WHERE r.chat_key=? AND r.owner_id=? FOR UPDATE',[$player,$chat,$player])[0]??null;
        $user=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$player])[0]??null;
        if (!$user || $user['bloccato'] || $user['imprigionato']) throw new DomainException('Non puoi proporre un invito in questo momento.',403);
        companionship_player_consent($player,true);
        $old=$db->query('SELECT * FROM companion_appointments WHERE player_id=? AND request_key=?',[$player,$request])[0]??null;
        if ($old) {
            $rental=$db->query('SELECT * FROM chat_rentals WHERE id=?',[(int)$old['rental_id']])[0];
            if ($rental['chat_key']!==$chat || (int)$old['npc_id']!==$npcId || $old['kind']!=='invitation' || (int)$old['finished_at'] || (int)$rental['closed_at'] || (int)$old['expires_at']<=time()) throw new DomainException('Richiesta già utilizzata o visita terminata.',409);
            $db->commit();return ['accepted'=>true,...companionship_receipt($old,$rental)];
        }
        if (!$rental || (int)$rental['closed_at'] || (int)$rental['expires_at']<=time()) throw new DomainException('Puoi invitare soltanto in una tua camera ancora attiva.',403);
        if ($db->query('SELECT player_id FROM chat_rental_members WHERE rental_id=? AND player_id<>? FOR UPDATE',[(int)$rental['id'],$player])) throw new DomainException('Per una visita riservata scegli una camera senza altri ospiti autorizzati.',409);
        $npc=$db->query('SELECT n.*,p.sexuality_enabled,p.exclusive_player_id FROM ai_npcs n JOIN ai_npc_adult p ON p.npc_id=n.id WHERE n.id=? FOR UPDATE',[$npcId])[0]??null;
        if (!$npc || !companionship_npc_available($npc) || !(int)$npc['sexuality_enabled']) throw new DomainException('Questa persona non accetta inviti privati.',409);
        if (companionship_npc_in_quest($npcId)) throw new DomainException('Questa persona è impegnata in una quest e non può lasciare la scena.',409);
        $partner=companionship_partner($npc);
        if ($partner && $partner!==$player) { $db->commit();return ['accepted'=>false,'reason'=>'La persona resta fedele al proprio legame e declina con rispetto l’invito.']; }
        if ($db->query('SELECT id FROM companion_appointments WHERE active_npc_id=? OR active_player_id=? OR rental_id=?',[$npcId,$player,(int)$rental['id']])) throw new DomainException('La persona o la camera sono già impegnate in una visita.',409);
        $decision=function_exists('ai_adult_invitation_decision')?ai_adult_invitation_decision($npcId,$player):['allowed'=>false,'reason'=>'La persona preferisce continuare a conoscervi nella sala comune.'];
        if (empty($decision['allowed'])) { $db->commit();return ['accepted'=>false,'reason'=>(string)($decision['reason']??'La persona declina con rispetto l’invito.')]; }
        $now=time();$expires=(int)$rental['expires_at'];
        $db->execute("INSERT INTO companion_appointments(npc_id,player_id,rental_id,host_key,started_at,expires_at,request_key,price,kind,active_npc_id,active_player_id) VALUES(?,?,?,?,?,?,?,0,'invitation',?,?)",[$npcId,$player,(int)$rental['id'],$rental['host_key'],$now,$expires,$request,$npcId,$player]);
        $id=(int)$db->lastInsertId();
        $place=$db->query('SELECT * FROM mappe WHERE chat_key=?',[$rental['host_key']])[0]??['chat_key'=>$rental['host_key'],'title'=>'Locanda'];
        companionship_prepare_ai_room($chat,$place,$player,$now);
        $db->execute('UPDATE utenti SET chat=?,posizione=?,lastmove=? WHERE id=?',[$chat,$place['title'].' · Camera '.$rental['id'],$now,$player]);
        $db->commit();return ['accepted'=>true,'reason'=>(string)($decision['reason']??'L’invito viene accettato.'),'chat_key'=>$chat,'expires_at'=>$expires,'total_cost'=>0,'npc_id'=>$npcId,'appointment_id'=>$id];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack();companionship_booking_error($e); }
}

/** Ending early forfeits the remaining paid time, with a durable, repeatable close request. */
function companionship_end(int $player,string $chat): array {
    if (!preg_match('/^rent_[a-f0-9]{40}$/D',$chat)) throw new DomainException('Camera non valida.',422);
    $db=ConnectDB();$db->beginTransaction();
    try {
        $rental=$db->query('SELECT * FROM chat_rentals WHERE chat_key=? FOR UPDATE',[$chat])[0]??null;
        $appointment=$rental?($db->query('SELECT * FROM companion_appointments WHERE rental_id=? FOR UPDATE',[(int)$rental['id']])[0]??null):null;
        if (!$rental || !$appointment || (int)$rental['owner_id']!==$player || (int)$appointment['player_id']!==$player) throw new DomainException('Puoi concludere soltanto una tua visita privata.',403);
        if (!(int)$appointment['finished_at'] && !(int)$rental['closed_at']) $db->execute('UPDATE chat_rentals SET expires_at=LEAST(expires_at,?) WHERE id=?',[time(),(int)$rental['id']]);
        $db->commit();
        // A lost response is safe: the committed deadline is enough for the next poll/worker.
        companionship_release_expired($chat);
        return ['ended'=>true,'host_key'=>$rental['host_key']];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack();companionship_booking_error($e); }
}
