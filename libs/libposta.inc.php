<?php
declare(strict_types=1);

use Alphano\Database\PDOMySQLDriver;

/**
 * Invia una missiva al database.
 *
 * @param string  $oggetto
 * @param string  $messaggio
 * @param int     $destinatario
 * @param int     $mittente
 * @param int     $salva      flag storico, mantenuto per compatibilità; le missive personali conservano sempre la copia inviata
 * @return bool
 */
function InviaMissiva(string $oggetto, string $messaggio, int $destinatario, int $mittente = 0, int $salva = 0): bool
{
    $db = $GLOBALS['db'] ?? ConnectDB();
    // A conversation must retain both directions. Hiding a sent copy is now an
    // explicit mailbox action, never an accidental side effect of sending it.
    if ($mittente > 0) $salva = 0;

    $sql = <<<SQL
INSERT INTO mail
    (`data`, `oggetto`, `iddestinatario`, `idmittente`, `messaggio`, `delmitt`)
VALUES
    (:now, :oggetto, :dest, :mitt, :msg, :salva)
SQL;

    $params = [
        ':now'     => time(),
        ':oggetto' => $oggetto,
        ':dest'    => $destinatario,
        ':mitt'    => $mittente,
        ':msg'     => $messaggio,
        ':salva'   => $salva,
    ];

    $res = $db->execute($sql, $params);
    return $res !== false;
}

/**
 * Pulisce la casella del destinatario:
 * - Mantiene massimo maxmissive messaggi letti non nel baule
 * - Cancella i messaggi più vecchi di 14 giorni
 *
 * @param int $idpg
 */
function PurgePosta(int $idpg): void
{
    $db = $GLOBALS['db'] ?? ConnectDB();

    // 2) Cancello i messaggi più vecchi di 14 giorni (no baule),
    //    o quelli contrassegnati cancellati da entrambe le parti
    $sqlDel = <<<SQL
DELETE FROM mail
 WHERE baule = 0
   AND letto = 1
   AND (
       (TO_DAYS(CURDATE()) - TO_DAYS(FROM_UNIXTIME(`data`))) >= 14
    OR (deldest = 1 AND delmitt = 1)
   )
SQL;
    $db->execute($sqlDel);

    // Retain the newest maxmissive read messages with one cutoff and one update.
    $cutoff=$db->query('SELECT id FROM mail WHERE iddestinatario=? AND deldest=0 AND letto=1 AND baule=0 ORDER BY id DESC LIMIT '.(int)maxmissive.',1',[$idpg])[0]['id'] ?? null;
    if ($cutoff!==null) $db->execute('UPDATE mail SET deldest=1 WHERE iddestinatario=? AND deldest=0 AND letto=1 AND baule=0 AND id<=?',[$idpg,(int)$cutoff]);


}
