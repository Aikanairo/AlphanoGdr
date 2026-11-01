<?php
declare(strict_types=1);
require_once __DIR__ . '/../../libs/player-runtime.inc.php';

player_api(function (): array {
    $idpg=alphano_require_login(true); $user=alphano_user(); $db=getDB(); $in=player_input();
    $type=player_text($in,'tipo',50,true); $moderator=player_discussion_access($type,$user);
    $action=player_text($in,'action',30) ?: 'list_open';
    if (!in_array($action,['list_open','list_closed','show'],true)) player_mutation();
    $format = function (array $post) use ($idpg,$moderator): array {
        return ['id'=>(int)$post['id'],'oggetto'=>$post['oggetto'],'messaggio'=>ProcessText($post['messaggio']),
            'message_raw'=>$post['messaggio'],'data'=>(int)$post['data'],'author_id'=>(int)$post['idpg'],'author'=>$post['author'] ?? 'Sistema',
            'unread'=>!empty($post['unread']),'chiusa'=>!empty($post['chiusa']),'ontop'=>!empty($post['ontop']),
            'can_edit'=>$moderator || (int)$post['idpg']===$idpg,'can_moderate'=>$moderator,'numrisp'=>(int)($post['numrisp'] ?? 0)];
    };
    if (in_array($action,['list_open','list_closed'],true)) {
        $rows=$db->query('SELECT d.*,u.nome AS author,EXISTS(SELECT 1 FROM discussioni p LEFT JOIN activity_notification_reads nr ON nr.recipient=? AND nr.notice_key=CONCAT(\'discussion:\',p.id) WHERE p.tipo=d.tipo AND (p.id=d.id OR p.replyid=d.id) AND p.idpg<>? AND nr.notice_key IS NULL) AS unread,(SELECT COUNT(*) FROM discussioni p WHERE p.replyid=d.id AND p.tipo=d.tipo) AS numrisp FROM discussioni d LEFT JOIN utenti u ON u.id=d.idpg WHERE d.replyid=0 AND d.tipo=? AND d.chiusa=? ORDER BY d.ontop DESC,d.data DESC,d.id DESC',[$idpg,$idpg,$type,$action==='list_closed' ? '1':'0']);
        return ['threads'=>array_map($format,$rows),'can_moderate'=>$moderator];
    }
    if ($action==='create') {
        $subject=player_text($in,'oggetto',255) ?: 'Nessun Oggetto'; $message=player_text($in,'messaggio',100000,true);
        $db->execute('INSERT INTO discussioni(data,oggetto,messaggio,tipo,idpg,ontop,chiusa) VALUES(?,?,?,?,?,?,?)',[time(),$subject,$message,$type,$idpg,$moderator && !empty($in['ontop']) ? '1':'0',$moderator && !empty($in['chiudi']) ? '1':'0']);
        return ['new_id'=>(int)$db->lastInsertId()];
    }
    $id=(int)($action==='reply' ? ($in['replyID'] ?? 0) : ($in['id'] ?? 0));
    $row=$db->query('SELECT d.*,u.nome AS author FROM discussioni d LEFT JOIN utenti u ON u.id=d.idpg WHERE d.id=? AND d.tipo=?',[$id,$type])[0] ?? null;
    if (!$row) throw new RuntimeException('Messaggio non trovato',404);
    if ($action==='show') {
        if ((int)$row['replyid']!==0) throw new RuntimeException('Thread non trovato',404);
        $posts=$db->query('SELECT d.*,u.nome AS author FROM discussioni d LEFT JOIN utenti u ON u.id=d.idpg WHERE d.replyid=? AND d.tipo=? ORDER BY d.id',[$id,$type]);
        return ['thread'=>$format($row),'posts'=>array_map($format,$posts)];
    }
    if ($action==='reply') {
        if ((int)$row['replyid']!==0) throw new RuntimeException('Thread non trovato',404);
        if (!empty($row['chiusa'])) throw new RuntimeException('Thread chiuso',409);
        $subject=player_text($in,'oggetto',255) ?: 'Nessun Oggetto'; $message=player_text($in,'messaggio',100000,true);
        $db->beginTransaction();
        try {
            // Recheck under the same row lock used by close/delete.
            $locked=$db->query('SELECT chiusa FROM discussioni WHERE id=? AND tipo=? FOR UPDATE',[$id,$type])[0] ?? null;
            if (!$locked) throw new RuntimeException('Thread non trovato',404);
            if (!empty($locked['chiusa'])) throw new RuntimeException('Thread chiuso',409);
            $db->execute('INSERT INTO discussioni(replyid,data,oggetto,messaggio,tipo,idpg) VALUES(?,?,?,?,?,?)',[$id,time(),$subject,$message,$type,$idpg]);
            $newId=(int)$db->lastInsertId();
            if ($moderator && !empty($in['chiudi'])) $db->execute('UPDATE discussioni SET chiusa=1 WHERE id=? AND tipo=?',[$id,$type]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
        return ['post_id'=>$newId];
    }
    if ($action==='close' || $action==='open') {
        if (!$moderator) throw new RuntimeException('Permesso negato',403);
        if ((int)$row['replyid']!==0) throw new RuntimeException('Non è un thread',400);
        $db->execute('UPDATE discussioni SET chiusa=? WHERE id=? AND tipo=?',[$action==='close'?'1':'0',$id,$type]);
        return [$action==='close'?'closed':'opened'=>true];
    }
    if (!$moderator && (int)$row['idpg']!==$idpg) throw new RuntimeException('Permesso negato',403);
    if ($action==='modify') {
        $subject=player_text($in,'oggetto',255) ?: 'Nessun Oggetto'; $message=player_text($in,'messaggio',100000,true);
        $db->execute('UPDATE discussioni SET oggetto=?,messaggio=? WHERE id=? AND tipo=?',[$subject,$message,$id,$type]);
        return ['modified'=>true];
    }
    if ($action==='delete') {
        $db->execute('DELETE FROM discussioni WHERE (id=? OR replyid=?) AND tipo=?',[$id,$id,$type]);
        return ['deleted'=>true];
    }
    throw new RuntimeException('Azione non valida',400);
});
