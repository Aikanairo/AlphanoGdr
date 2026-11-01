<?php
/** Moderazione PG e chat: SQL parametrico e registrazione atomica. */
require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__ . '/libchat.inc.php';

function AlphanoModerate(int $id, int $executor, string $action, string $sql, array $params, string $description = '', string $chat = ''): bool
{
    $db = ConnectDB();
    if ($id && !$db->query('SELECT id FROM utenti WHERE id=?', [$id])) return false;
    $own=!$db->inTransaction();if($own)$db->beginTransaction();
    try {
        $db->execute($sql, $params);
        $db->execute('INSERT INTO logs_adminpg (azione,data,esecutore,destinatario,descr,chat) VALUES (?,?,?,?,?,?)',
            [$action,time(),$executor,$id,$description,$chat]);
        if($own)$db->commit();
        return true;
    } catch (Throwable $e) {
        if($own&&$db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function LockUser($idpg, $data, $motivo, $idesecutore): bool
{
    $idpg = (int)$idpg;
    $motivo = trim((string)$motivo);
    if (!$idpg || $motivo === '' || (int)$data <= time()) return false;
    $row = ConnectDB()->query('SELECT chat FROM utenti WHERE id=?', [$idpg])[0] ?? null;
    if (!$row) return false;
    $dump = $row['chat'] !== '' ? DumpChat($row['chat']) : '';
    return AlphanoModerate($idpg,(int)$idesecutore,'Blocco',
        'UPDATE utenti SET bloccato=1,datasblocco=?,motivo=?,lockby=? WHERE id=?',
        [(int)$data,$motivo,(int)$idesecutore,$idpg],$motivo,(string)$dump);
}
function UnLockUser($idpg)
{
    return ConnectDB()->execute("UPDATE utenti SET bloccato=0,datasblocco=0,motivo='',lockby=0 WHERE id=? AND datasblocco>0 AND datasblocco<=?", [(int)$idpg,time()]);
}
function ForceUnlock($iddest, $esecutore): bool
{
    return AlphanoModerate((int)$iddest,(int)$esecutore,'Sblocca',
        "UPDATE utenti SET bloccato=0,datasblocco=0,motivo='',lockby=0 WHERE id=?", [(int)$iddest]);
}
function Incarcera($iddest,$city,$esecutore): bool
{
    if (!ConnectDB()->query('SELECT id FROM city WHERE id=?', [(int)$city])) return false;
    return AlphanoModerate((int)$iddest,(int)$esecutore,'Incarcera',
        'UPDATE utenti SET imprigionato=? WHERE id=?', [(int)$city,(int)$iddest],(string)(int)$city);
}
function Scarcera($iddest,$esecutore): bool
{
    return AlphanoModerate((int)$iddest,(int)$esecutore,'Scarcera',
        'UPDATE utenti SET imprigionato=0 WHERE id=?', [(int)$iddest]);
}
function SvuotaChat($esecutore,$chat): bool
{
    $chat = (string)$chat;
    if ($chat === '') return false;
    return AlphanoModerate(0,(int)$esecutore,"Svuota($chat)",
        'DELETE FROM chat WHERE chat=?', [$chat],'',(string)DumpChat($chat));
}
