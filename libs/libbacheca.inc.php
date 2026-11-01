<?php
declare(strict_types=1);
// libs/libbacheca_pdo.inc.php

use Alphano\Database\PDOMySQLDriver;
require_once __DIR__ . '/player-runtime.inc.php';

function bacheca_require_message(int $id, bool $write = false): array {
    $user=alphano_user();
    if (!$user) alphano_fail('Devi effettuare il login.',403);
    $message=getDB()->query('SELECT * FROM bacheche WHERE id=?',[$id])[0] ?? null;
    if (!$message) alphano_fail('Messaggio non trovato.',404);
    player_require_board(getDB(),(int)$message['bacheca'],$user);
    if ($write) {
        alphano_require_csrf();
        if ((int)$message['idpg']!==(int)$user['id'] && empty($user['admin'])) alphano_fail('Permesso negato.',403);
    }
    return $message;
}


/**
 * Restituisce l’istanza PDO del db.
 */
function getBachecaDb(): PDOMySQLDriver
{
    return getDB();
}

/**
 * Prende tutti i messaggi (thread o risposte) di una bacheca.
 * @param string $bacheca
 * @param int    $start    offset per la paginazione
 * @param int    $limit    numero di record da prendere
 * @param int    $replyId  se >0 prende solo le risposte a quel replyId
 * @return array<int,array<string,mixed>>
 */
function GetBacheca(string $bacheca, int $start = 0, int $limit = 0, int $replyId = 0): array
{
    $db = getBachecaDb();
    player_require_board($db,(int)$bacheca,alphano_user() ?? []);
    $sql = 'SELECT *
            FROM bacheche
            WHERE bacheca = :bc
              AND replyid = :rid
            ORDER BY data DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT :st, :lm';
    }

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':bc', $bacheca, \PDO::PARAM_STR);
    $stmt->bindValue(':rid', $replyId, \PDO::PARAM_INT);
    if ($limit > 0) {
        $stmt->bindValue(':st', $start, \PDO::PARAM_INT);
        $stmt->bindValue(':lm', $limit, \PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Restituisce tutte le bacheche di una certa location.
 * @param string $location
 * @return array<int,array<string,mixed>>
 */
function GetBacheche(string $location): array
{
    $db = getBachecaDb();
    $sql = 'SELECT id,nome,descrizione
            FROM luoghi
            WHERE location = :loc';
    $stmt = $db->prepare($sql);
    $stmt->execute([':loc' => $location]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Salva lo storico prima di aggiornare un messaggio, poi aggiorna.
 */
function AggiornaMessaggio(int $id, string $oggetto, string $messaggio): bool
{
    bacheca_require_message($id,true);
    $db = getBachecaDb();
    $db->beginTransaction();

    // 1) Recupero dati esistenti
    $orig = $db->query(
        'SELECT id,oggetto,messaggio,idpg
         FROM bacheche
         WHERE id = ?',
        [$id]
    )[0] ?? null;

    if (!$orig) {
        $db->rollBack();
        return false;
    }

    // 2) Inserisco nello storico
    $sql = 'INSERT INTO storicobacheche
            (oldid, data, oggetto, messaggio, idpg)
            VALUES (:oid, :dt, :o, :m, :pid)';
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':oid' => $orig['id'],
        ':dt'  => time(),
        ':o'   => $orig['oggetto'],
        ':m'   => $orig['messaggio'],
        ':pid' => $orig['idpg'],
    ]);

    // 3) Aggiorno il post
    $sql = 'UPDATE bacheche
            SET oggetto = :o, messaggio = :m
            WHERE id = :id';
    $stmt = $db->prepare($sql);
    $ok = $stmt->execute([
        ':o'  => $oggetto !== '' ? $oggetto : 'Nessun Oggetto',
        ':m'  => $messaggio,
        ':id' => $id,
    ]);

    $db->commit();
    return (bool)$ok;
}

/**
 * Legge un singolo thread (il post principale).
 */
function LeggiThread(string $bacheca, int $id): ?array
{
    player_require_board(getDB(),(int)$bacheca,alphano_user() ?? []);
    $db = getBachecaDb();

    // 1) incremento visite
    $db->execute(
        'UPDATE bacheche
         SET visite = visite + 1
         WHERE id = ? AND bacheca = ? AND replyid = 0',
        [$id, $bacheca]
    );

    // 2) leggo il post
    $row = $db->query(
        'SELECT * FROM bacheche
         WHERE id = ? AND replyid = 0 AND bacheca = ?',
        [$id, $bacheca]
    )[0] ?? null;

    if (!$row) {
        return null;
    }

    // 3) nome del PG
    $row['nome'] = GetPGFromID((int)$row['idpg']);

    // 4) flag “modificato”
    $cnt = $db->query(
        'SELECT COUNT(*) AS cnt
         FROM storicobacheche
         WHERE oldid = ?',
        [$id]
    )[0]['cnt'] ?? 0;
    $row['modificato'] = $cnt > 0;

    return $row;
}

/**
 * Legge tutti i post di risposta a un thread.
 */
function LeggiPost(string $bacheca, int $replyId): array
{
    player_require_board(getDB(),(int)$bacheca,alphano_user() ?? []);
    $db = getBachecaDb();
    $sql = 'SELECT bacheche.*, utenti.nome
            FROM bacheche
            JOIN utenti ON utenti.id = bacheche.idpg
            WHERE replyid = :rid AND bacheca = :bc
            ORDER BY bacheche.id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute([':rid'=>$replyId,':bc'=>$bacheca]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $cnt = $db->query(
            'SELECT COUNT(*) AS cnt
             FROM storicobacheche
             WHERE oldid = ?',
            [$r['id']]
        )[0]['cnt'] ?? 0;
        $r['modificato'] = $cnt > 0;
    }
    return $rows;
}

/**
 * Legge un singolo messaggio (thread o post).
 */
function LeggiMessaggio(int $id): ?array
{
    bacheca_require_message($id);
    $db = getBachecaDb();
    $sql = 'SELECT bacheche.*, utenti.nome
            FROM bacheche
            JOIN utenti ON utenti.id = bacheche.idpg
            WHERE bacheche.id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute([':id'=>$id]);
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/**
 * Restituisce tutto lo storico di un thread/post.
 */
function GetStorico(int $id): array
{
    bacheca_require_message($id);
    $db = getBachecaDb();
    $sql = 'SELECT s.*, u.nome, u.avatar, u.avatar2, u.razza, u.alternate_form, u.sesso
            FROM storicobacheche s
            JOIN utenti u ON u.id = s.idpg
            WHERE s.oldid = :oid
            ORDER BY s.id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute([':oid'=>$id]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Elimina thread e tutte le risposte.
 */
function EliminaThread(int $id): bool
{
    $original=bacheca_require_message($id,true);
    if ((int)$original['replyid']!==0) alphano_fail('Thread non trovato.',404);
    $db = getBachecaDb();
    $sql = 'DELETE FROM bacheche
            WHERE id = :id OR replyid = :id';
    $stmt = $db->prepare($sql);
    return $stmt->execute([':id'=>$id]);
}

/**
 * Elimina solo un post (non thread).
 */
function EliminaPost(int $id): bool
{
    bacheca_require_message($id,true);
    $db = getBachecaDb();
    $sql = 'DELETE FROM bacheche
            WHERE id = :id AND replyid <> 0';
    $stmt = $db->prepare($sql);
    return $stmt->execute([':id'=>$id]);
}

/**
 * Conta quanti thread (replyid=0) in una bacheca.
 */
function NumThread(string $bc): int
{
    $db = getBachecaDb();
    $row = $db->query(
        'SELECT COUNT(*) AS num
         FROM bacheche
         WHERE bacheca = ? AND replyid = 0',
        [$bc]
    )[0] ?? ['num'=>0];
    return (int)$row['num'];
}

/**
 * Restituisce il numero di risposte e ultimo risponditore.
 */
function DatiPost(int $threadId): array
{
    $db = getBachecaDb();

    // 1) numero e id massimo
    $row = $db->query(
        'SELECT COUNT(*) AS num, MAX(id) AS maxid
         FROM bacheche
         WHERE replyid = ?',
        [$threadId]
    )[0] ?? ['num'=>0,'maxid'=>null];

    $ret = ['numrisp' => (int)$row['num'], 'id'=>0, 'nome'=>'', 'data'=>0];

    if (!empty($row['maxid'])) {
        $sub = $db->query(
            'SELECT u.id, u.nome, b.data
             FROM bacheche b
             JOIN utenti u ON u.id = b.idpg
             WHERE b.id = ?',
            [(int)$row['maxid']]
        )[0] ?? null;

        if ($sub) {
            $ret['id']   = (int)$sub['id'];
            $ret['nome'] = $sub['nome'];
            $ret['data'] = $sub['data'];
        }
    }

    return $ret;
}

/**
 * Inserisce un nuovo post di risposta.
 */
function InserisciPost(
    string $bc,
    int    $replyId,
    int    $idpg,
    string $oggetto,
    string $messaggio,
    bool   $notify = false
): bool {
    $idpg=alphano_require_login(); alphano_require_csrf();
    player_require_board(getDB(),(int)$bc,alphano_user());
    $db = getBachecaDb();

    if ($oggetto === '') {
        $oggetto = 'Nessun Oggetto';
    }

    $parent=$db->query('SELECT * FROM bacheche WHERE id=? AND bacheca=? AND replyid=0',[$replyId,$bc])[0] ?? null;
    if (!$parent) alphano_fail('Thread non trovato.',404);
    if (!empty($parent['chiusa'])) alphano_fail('Thread chiuso.',409);

    // notifica al thread originario
    if ($notify && $replyId > 0) {
        $old = LeggiMessaggio($replyId);
        if ($old && $old['idpg'] !== $idpg) {
            InviaMissiva(
                "Notifica risposta: {$old['oggetto']}",
                GetPGFromID($idpg) . ' ha risposto al tuo thread',
                (int)$old['idpg']
            );
        }
    }

    $sql = 'INSERT INTO bacheche
            (replyid,data,oggetto,messaggio,idpg,bacheca,chiusa)
            VALUES(:rid, :dt, :o, :m, :pid, :bc, 0)';
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':rid' => $replyId,
        ':dt'  => time(),
        ':o'   => $oggetto,
        ':m'   => $messaggio,
        ':pid' => $idpg,
        ':bc'  => $bc,
    ]);
}

/**
 * Inserisce un nuovo thread (replyid=0).
 */
function InserisciThread(
    string $bc,
    int    $idpg,
    string $oggetto,
    string $messaggio,
    bool   $notify = false
): bool {
    $idpg=alphano_require_login(); alphano_require_csrf();
    player_require_board(getDB(),(int)$bc,alphano_user());
    $db = getBachecaDb();

    if ($oggetto === '') {
        $oggetto = 'Nessun Oggetto';
    }

    $sql = 'INSERT INTO bacheche
            (replyid,data,oggetto,messaggio,idpg,segnalazione,bacheca,chiusa)
            VALUES(0, :dt, :o, :m, :pid, :sgn, :bc, 0)';
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':dt'  => time(),
        ':o'   => $oggetto,
        ':m'   => $messaggio,
        ':pid' => $idpg,
        ':sgn' => $notify ? 1 : 0,
        ':bc'  => $bc,
    ]);
}

/**
 * Controlla se un player ha diritto di scrivere in una bacheca.
 */
function CanReply(int $pgid, string $bc): bool
{
    $db = getBachecaDb();

    // 1) controlla utente
    $u = $db->query('SELECT * FROM utenti WHERE id = ?', [$pgid])[0] ?? [];
    if (empty($u) || !empty($u['bloccato'])) {
        return false;
    }

    return player_board_permissions($db,(int)$bc,$u)['allowed'];
}

/**
 * Controlla se un thread ha nuovi post dopo un certo timestamp.
 */
function IsNewThread(int $id, int $timestamp): bool
{
    $db = getBachecaDb();
    $row = $db->query(
        'SELECT MAX(data) AS maxdata
         FROM bacheche
         WHERE id = ? OR replyid = ?',
        [$id, $id]
    )[0] ?? ['maxdata'=>0];

    return ((int)$row['maxdata'] > $timestamp);
}
