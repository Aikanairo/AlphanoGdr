<?php
declare(strict_types=1);
require_once __DIR__ . '/../../libs/player-runtime.inc.php';
require_once __DIR__ . '/../../libs/libposta.inc.php';
require_once __DIR__ . '/../../libs/forum-presentation.inc.php';

player_api(function (): array {
    $idpg = alphano_require_login(true);
    $db = getDB();
    $user = alphano_user();
    $admin = !empty($user['admin']);
    $input = player_input();
    $action = player_text($input, 'action', 40, true);
    $readActions = ['getIndex','getCities','getBoards','getThreads','getPosts','getHistory'];
    if (!in_array($action, $readActions, true)) player_mutation();
    if ($action === 'getCities') {
        $boards=$db->query('SELECT l.id,l.location,SUM(b.replyid=0) AS threadsCount,SUM(b.replyid<>0) AS postsCount FROM luoghi l LEFT JOIN bacheche b ON b.bacheca=l.id GROUP BY l.id,l.location ORDER BY l.location');
        $cities=[];
        foreach ($boards as $board) {
            $name=$board['location'];
            if (!isset($cities[$name])) $cities[$name]=['location'=>$name,'boardsCount'=>0,'threadsCount'=>0,'postsCount'=>0];
            $cities[$name]['boardsCount']++;
            if (player_board_permissions($db,(int)$board['id'],$user)['allowed']) {
                $cities[$name]['threadsCount']+=(int)$board['threadsCount'];
                $cities[$name]['postsCount']+=(int)$board['postsCount'];
            }
        }
        $cities=array_values($cities);
        return ['success'=>true,'cities'=>$cities];
    }
    if(in_array($action,['getIndex','getBoards'],true)){
        $location=$action==='getBoards'?player_text($input,'location',255,true):'';
        $boards=$db->query("SELECT l.*,(SELECT COUNT(*) FROM bacheche b WHERE b.bacheca=l.id AND b.replyid=0) AS threadsCount,(SELECT COUNT(*) FROM bacheche b WHERE b.bacheca=l.id AND b.replyid<>0) AS postsCount,(SELECT MAX(b.id) FROM bacheche b WHERE b.bacheca=l.id) AS last_id FROM luoghi l".($location!==''?' WHERE l.location=?':'').' ORDER BY l.nome,l.id',$location!==''?[$location]:[]);
        $lastIds=array_values(array_filter(array_column($boards,'last_id')));$last=[];
        if($lastIds){$marks=implode(',',array_fill(0,count($lastIds),'?'));foreach($db->query('SELECT b.id,b.replyid,b.idpg,b.data,b.oggetto,COALESCE(t.id,b.id) AS thread_id,COALESCE(t.oggetto,b.oggetto) AS subject FROM bacheche b LEFT JOIN bacheche t ON t.id=b.replyid AND t.bacheca=b.bacheca AND t.replyid=0 WHERE b.id IN ('.$marks.')',$lastIds) as $post)$last[(int)$post['id']]=$post;}
        $unread=[];foreach($db->query("SELECT DISTINCT p.bacheca FROM bacheche p LEFT JOIN activity_notification_reads nr ON nr.recipient=? AND nr.notice_key=CONCAT('board:',p.id) WHERE p.idpg<>? AND nr.notice_key IS NULL",[$idpg,$idpg]) as $row)$unread[(int)$row['bacheca']]=true;
        foreach($boards as &$board){
            $board['id']=(int)$board['id'];$board+=player_board_permissions($db,$board['id'],$user);$board['image']=alphano_board_image($board);$post=$last[(int)$board['last_id']]??null;unset($board['last_id']);
            $board['threadsCount']=$board['allowed']?(int)$board['threadsCount']:0;$board['postsCount']=$board['allowed']?(int)$board['postsCount']:0;$board['unread']=$board['allowed']&&isset($unread[$board['id']]);
            $board['lastPostId']=$board['allowed']&&$post?(int)$post['thread_id']:null;$board['lastPostPg']=$board['allowed']&&$post?(int)$post['idpg']:null;$board['lastPostDate']=$board['allowed']&&$post?(int)$post['data']:null;$board['lastPostSubject']=$board['allowed']&&$post?$post['subject']:null;
        }unset($board);
        return ['success'=>true,'boards'=>$boards,'sections'=>alphano_board_sections($boards)];
    }
    if (in_array($action, ['getThreads','getPosts','postThread','postReply'], true)) {
        $board = (int)($input['board'] ?? 0);
        player_require_board($db, $board, $user);
    }
    if ($action === 'getThreads') {
        $threads=$db->query('SELECT b.*,EXISTS(SELECT 1 FROM bacheche p LEFT JOIN activity_notification_reads nr ON nr.recipient=? AND nr.notice_key=CONCAT(\'board:\',p.id) WHERE p.bacheca=b.bacheca AND (p.id=b.id OR p.replyid=b.id) AND p.idpg<>? AND nr.notice_key IS NULL) AS unread,(SELECT COUNT(*) FROM bacheche p WHERE p.replyid=b.id AND p.bacheca=b.bacheca) AS replyCount FROM bacheche b WHERE b.bacheca=? AND b.replyid=0 ORDER BY b.data DESC,b.id DESC', [$idpg,$idpg,$board]);
        foreach ($threads as &$thread) {
            $thread['id']=(int)$thread['id']; $thread['idpg']=(int)$thread['idpg']; $thread['chiusa']=(string)(int)$thread['chiusa'];
            $thread['message_raw']=$thread['messaggio']; $thread['messaggio']=ProcessText($thread['messaggio']);
        }
        $info=$db->query('SELECT * FROM luoghi WHERE id=?',[$board])[0];$info['image']=alphano_board_image($info);return ['success'=>true,'threads'=>$threads,'board'=>$info];
    }
    if (in_array($action, ['getPosts','postReply'], true)) {
        $threadId=(int)($input['thread'] ?? 0);
        $thread=$db->query('SELECT * FROM bacheche WHERE id=? AND bacheca=? AND replyid=0', [$threadId,$board])[0] ?? null;
        if (!$thread) throw new RuntimeException('Thread non trovato',404);
    }
    if ($action === 'getPosts') {
        $db->execute('UPDATE bacheche SET visite=visite+1 WHERE id=?', [$threadId]);
        $posts=$db->query('SELECT b.*,u.nome FROM bacheche b LEFT JOIN utenti u ON u.id=b.idpg WHERE b.bacheca=? AND b.replyid=? ORDER BY b.id',[$board,$threadId]);
        foreach ($posts as &$post) {
            $post['id']=(int)$post['id']; $post['idpg']=(int)$post['idpg'];
            $post['message_raw']=$post['messaggio']; $post['messaggio']=ProcessText($post['messaggio']);
        }
        return ['success'=>true,'posts'=>$posts];
    }
    if (in_array($action, ['postThread','postReply'],true)) {
        $subject=player_text($input,'subject',255) ?: ($action === 'postThread' ? 'Nessun Oggetto' : '');
        $message=player_text($input,'message',100000,true);
        if (player_text($input,'firma',1)==='1') $message.=FirmaPG($idpg);
        $notify=player_text($input,'segnalazione',1)==='1';
        $db->beginTransaction();
        try {
            if ($action === 'postReply') {
                $locked=$db->query('SELECT chiusa,segnalazione,idpg,oggetto FROM bacheche WHERE id=? AND bacheca=? AND replyid=0 FOR UPDATE',[$threadId,$board])[0] ?? null;
                if (!$locked) throw new RuntimeException('Thread non trovato',404);
                if (!empty($locked['chiusa'])) throw new RuntimeException('Thread chiuso',409);
            }
            $db->execute('INSERT INTO bacheche(replyid,data,oggetto,messaggio,idpg,bacheca,chiusa,segnalazione) VALUES(?,?,?,?,?,?,0,?)',[$action === 'postThread' ? 0 : $threadId,time(),$subject,$message,$idpg,$board,$action==='postThread'&&$notify ? 1 : 0]);
            $newId=(int)$db->lastInsertId();
            if ($action==='postReply' && !empty($locked['segnalazione']) && (int)$locked['idpg']!==$idpg) {
                InviaMissiva('Notifica risposta: '.$locked['oggetto'],GetPGFromID($idpg).' ha risposto alla tua discussione.',(int)$locked['idpg']);
            }
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        return ['success'=>true,'id'=>$newId,'message'=>$action === 'postThread' ? 'Thread creato' : 'Risposta pubblicata'];
    }
    $messageId=(int)($input['id'] ?? 0);
    $original=$db->query('SELECT * FROM bacheche WHERE id=?',[$messageId])[0] ?? null;
    if (!$original) throw new RuntimeException('Messaggio non trovato',404);
    player_require_board($db,(int)$original['bacheca'],$user);
    if ($action === 'getHistory') {
        $history=$db->query('SELECT s.*,u.nome FROM storicobacheche s LEFT JOIN utenti u ON u.id=s.idpg WHERE oldid=? ORDER BY s.id',[$messageId]);
        foreach ($history as &$entry) $entry['messaggio']=ProcessText($entry['messaggio']);
        return ['success'=>true,'history'=>$history];
    }
    if (in_array($action,['close','open'],true)) {
        if (!$admin) throw new RuntimeException('Permesso negato',403);
        if ((int)$original['replyid'] !== 0) throw new RuntimeException('Non è un thread',400);
        $db->execute('UPDATE bacheche SET chiusa=? WHERE id=?',[$action === 'close' ? '1':'0',$messageId]);
        return ['success'=>true,'message'=>$action === 'close' ? 'Thread chiuso':'Thread riaperto'];
    }
    if ((int)$original['idpg'] !== $idpg && !$admin) throw new RuntimeException('Permesso negato',403);
    if ($action === 'modify') {
        $subject=player_text($input,'subject',255) ?: $original['oggetto'];
        $message=player_text($input,'message',100000,true);
        if (player_text($input,'firma',1)==='1') $message.=FirmaPG($idpg);
        $notify=player_text($input,'segnalazione',1)==='1';
        $db->beginTransaction();
        try {
            $original=$db->query('SELECT * FROM bacheche WHERE id=? FOR UPDATE',[$messageId])[0] ?? null;
            if (!$original) throw new RuntimeException('Messaggio non trovato',404);
            $db->execute('INSERT INTO storicobacheche(oldid,data,oggetto,messaggio,idpg) VALUES(?,?,?,?,?)',[$messageId,time(),$original['oggetto'],$original['messaggio'],$original['idpg']]);
            $db->execute('UPDATE bacheche SET oggetto=?,messaggio=? WHERE id=?',[$subject,$message,$messageId]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        return ['success'=>true,'message'=>'Messaggio aggiornato'];
    }
    if ($action === 'delete') {
        $db->execute('DELETE FROM storicobacheche WHERE oldid IN (SELECT id FROM bacheche WHERE id=? OR replyid=?)',[$messageId,$messageId]);
        $db->execute('DELETE FROM bacheche WHERE id=? OR replyid=?',[$messageId,$messageId]);
        return ['success'=>true,'message'=>'Cancellazione eseguita'];
    }
    throw new RuntimeException('Azione sconosciuta',400);
});
