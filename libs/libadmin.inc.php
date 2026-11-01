<?php
declare(strict_types=1);

use Alphano\Database\PDOMySQLDriver;

require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';

function OptimizeAll(): string
{
    $db = ConnectDB();

    // recupera tutte le tabelle
    $rows = $db->query('SHOW TABLES', []);
    $tables = array_map(static fn(array $row): string => (string)reset($row), $rows);

    if (empty($tables)) {
        return 'Nessuna tabella trovata';
    }

    // comando OPTIMIZE
    $sql = 'OPTIMIZE TABLE ' . implode(', ', array_map(fn($t)=>"`$t`", $tables));
    $ok = $db->query($sql, []);
    return !empty($ok)
        ? 'Ottimizzazione completata'
        : 'Errore nell\'ottimizzazione';
}

function SetFlag(
    int    $esecutore,
    string $destinatarioNome,
    int    $fmaster,
    int    $fguaritore,
    int    $fresurrettore,
    int    $fstatosociale,
    int    $flock,
    int    $accademia,
    int    $fadminorg,
    int    $fadmincorp,
    int    $fcatalogo,
    int    $fordini,
    int    $fsindaco,
    int    $fupload,
    int    $fvacanza
): string {
    $db = ConnectDB();
    $id = GetPGID($destinatarioNome);
    if (!$id) {
        return "L'utente non esiste";
    }

    require_once __DIR__.'/admin-governance.inc.php';
    alphano_governance_apply($esecutore,(int)$id,[
        'fatepower'=>$fmaster,'adminferite'=>$fguaritore,'adminmorti'=>$fresurrettore,'adminstatosociale'=>$fstatosociale,
        'bloccato'=>$flock,'adminaccademia'=>$accademia,'adminorganizzazione'=>$fadminorg,'admincorporazione'=>$fadmincorp,
        'admincatalogo'=>$fcatalogo,'admintrasfcorp'=>$fordini,'sindaco'=>$fsindaco,'upload'=>$fupload,'vacanza'=>$fvacanza,
    ],'Aggiornamento flag dal pannello utenti');

    // log
    $now = time();
    $sql = <<<SQL
INSERT INTO logs_flag
    (data, esecutore, destinatario,
     adminorg, admincorp, fatepower,
     adminferite, adminmorti, adminstatosociale,
     bloccato, accademia, catalogo,
     ordini, sindaco, upload, vacanza)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL;
    $logParams = [
        $now, $esecutore, $id,
        $fadminorg, $fadmincorp, $fmaster,
        $fguaritore, $fresurrettore, $fstatosociale,
        $flock, $accademia, $fcatalogo,
        $fordini, $fsindaco, $fupload, $fvacanza
    ];
    $db->execute($sql, $logParams);

    return "FLAG correttamente impostati a $destinatarioNome";
}

function SetProfile(int $esecutore,string $destinatarioNome,string $sesso='',string $etnia='',string $allineamento='',string $razza='',?int $creatorId=null):string {
    require_once __DIR__.'/lineages.inc.php';
    $db=ConnectDB();$own=!$db->inTransaction();if($own)$db->beginTransaction();
    try {
        if(in_array($razza,['2','5','3','6'],true))lineage_policy_lock(in_array($razza,['2','5'],true)?'vampire':'lycan');
        $row=$db->query('SELECT * FROM utenti WHERE nome=? FOR UPDATE',[$destinatarioNome])[0]??null;
        if(!$row){if($own)$db->commit();return "Il pg $destinatarioNome non esiste";}
        $id=(int)$row['id'];$sesso=$sesso?:$row['sesso'];$etnia=$etnia!==''?$etnia:(string)$row['etnia'];$allineamento=$allineamento?:$row['allineamento'];
        if(!in_array($sesso,['Maschio','Femmina',''],true))throw new InvalidArgumentException('Sesso non valido');
        if(!in_array($allineamento,['LB','NB','CB','LN','NN','CN','LM','NM','CM'],true))throw new InvalidArgumentException('Allineamento non valido');
        if((int)$etnia!==0&&!$db->query('SELECT id FROM etnie WHERE id=?',[(int)$etnia]))throw new InvalidArgumentException('Etnia non valida');
        $notice='';
        if($razza!==''){
            if(!ctype_digit($razza)||!$db->query('SELECT id FROM razze WHERE id=?',[(int)$razza]))throw new InvalidArgumentException('Razza non valida');
            $assignment=lineage_assign_race($row,(int)$razza,$creatorId,$esecutore);$row=$assignment['user'];$notice=$assignment['message'];
        }else lineage_ensure($row);
        $db->execute('UPDATE utenti SET sesso=?,etnia=?,allineamento=? WHERE id=?',[$sesso,$etnia,$allineamento,$id]);
        $db->execute('INSERT INTO logs_profili(data,esecutore,destinatario,razza,sesso,etnia,allineamento,infetto,ancestrale) VALUES(?,?,?,?,?,?,?,?,?)',[time(),$esecutore,$id,$row['razza'],$sesso,(string)GetEtnia((int)$etnia),$allineamento,$row['infetto'],$row['ancestrale']]);
        if($own)$db->commit();
        $msg='Profilo aggiornato con successo a '.htmlspecialchars($destinatarioNome,ENT_QUOTES).' · '.htmlspecialchars(GetRazza((int)$row['razza']),ENT_QUOTES);
        if(!empty($row['alternate_form']))$msg.=' · Forma alternativa';
        if($notice!=='')$msg.=' · '.htmlspecialchars($notice,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return $msg;
    }catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
}

function AdminSetNome(int $esecutore, string $destinatarioNome, string $nuovonome): string
{
    $db = ConnectDB();
    $id = GetPGID($destinatarioNome);
    if (!$id) {
        return "Impossibile cambiare nome a $destinatarioNome";
    }

    $nuovonome = trim($nuovonome);
    if ($nuovonome === '' || mb_strlen($nuovonome) > 50) throw new InvalidArgumentException('Nome non valido');
    $other = $db->query('SELECT id FROM utenti WHERE nome=? AND id<>?', [$nuovonome,$id]);
    if ($other) throw new InvalidArgumentException('Nome già utilizzato');
    $db->execute(
        'UPDATE utenti SET nome = ? WHERE id = ?',
        [$nuovonome, $id]
    );

    $now = time();
    $db->execute(
        'INSERT INTO logs_profili (data, esecutore, destinatario, nuovonome)
         VALUES (?, ?, ?, ?)',
        [$now, $esecutore, $id, $nuovonome]
    );

    return "Il pg $destinatarioNome ora si chiama $nuovonome";
}

function SetPass(int $esecutore, string $destinatarioNome, string $pass): string
{
    $db = ConnectDB();
    $id = GetPGID($destinatarioNome);
    if (!$id) {
        return "Impossibile cambiare la password a $destinatarioNome";
    }

    if (trim($pass) === '' || strlen($pass) > 4096) throw new InvalidArgumentException('Password non valida');
    $hashed = password_hash($pass, PASSWORD_DEFAULT);
    $db->execute(
        'UPDATE utenti SET password = ? WHERE id = ?',
        [$hashed, $id]
    );

    $now = time();
    $db->execute(
        'INSERT INTO logs_profili (data, esecutore, destinatario, nuovapass)
         VALUES (?, ?, ?, ?)',
        [$now, $esecutore, $id, '[password aggiornata]']
    );

    return "Password cambiata con successo a $destinatarioNome";
}

function DelUser(
    int    $esecutore,
    string $destinatarioNome,
    bool   $profilo = true,
    bool   $equip   = true,
    bool   $posta   = true,
    bool   $bacheca = false
): string {
    $db = ConnectDB();
    $id = GetPGID($destinatarioNome);
    if (!$id) {
        return "Utente inesistente";
    }

    require_once __DIR__.'/libeconomia.inc.php';
    return EconomiaAtomicaResiliente(static function()use($db,$id,$esecutore,$destinatarioNome,$profilo,$equip,$posta,$bacheca):string{
        $db->query('SELECT id FROM utenti WHERE id=? FOR UPDATE',[$id]);
    if (($profilo || $equip) && $db->query("SHOW TABLES LIKE 'board_games'") && $db->query("SELECT id FROM board_games WHERE status='active' AND (a=? OR b=?) LIMIT 1",[$id,$id])) {
        return 'Il personaggio ha una partita attiva. Annullala dal pannello Giochi, con rimborso delle poste, prima di cancellare profilo o equipaggiamento.';
    }

    $msg = "All'utente $destinatarioNome è stato cancellato:<br>";
    $ok  = true;

    if ($profilo) {
        $ok = $db->execute('DELETE FROM utenti WHERE id = ?', [$id]);
        $msg .= $ok !== false ? "Profilo cancellato<br>" : "Errore nel profilo<br>";
    }
    if ($equip) {
        $ok = $db->execute('DELETE FROM equippg WHERE idpg = ?', [$id]);
        $db->execute('DELETE FROM armadio WHERE idpg = ?', [$id]);
        $msg .= $ok !== false ? "Equipaggiamento cancellato<br>" : "Errore nell'equipaggiamento<br>";
    }
    if ($posta) {
        $ok = $db->execute(
            'DELETE FROM mail WHERE idmittente = ? OR iddestinatario = ?',
            [$id, $id]
        );
        $msg .= $ok !== false ? "Posta cancellata<br>" : "Errore nella posta<br>";
    }
    if ($bacheca) {
        $ok = $db->execute('DELETE FROM bacheche WHERE idpg = ?', [$id]);
        $msg .= $ok !== false ? "Bacheche cancellate<br>" : "Errore nelle bacheche<br>";
    }

    // log
    $now = time();
    $db->execute(
        'INSERT INTO logs_deleteuser
         (data, esecutore, destinatario, profilo, equipaggiamento, posta, bacheca)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$now, $esecutore, $id, (int)$profilo, (int)$equip, (int)$posta, (int)$bacheca]
    );

    return $msg;
    });
}

function DelPosta(
    int    $esecutore,
    string $destinatarioNome,
    string $invio,
    string $baule,
    string $letto
): string {
    $db = ConnectDB();
    $id = GetPGID($destinatarioNome);
    if (!$id) {
        return "Utente inesistente";
    }

    // costruisci WHERE dinamico
    $conds = [];
    switch ($invio) {
        case 'ric':
        case 'dest':  $conds[] = "iddestinatario = $id"; break;
        case 'both':  $conds[] = "(iddestinatario = $id OR idmittente = $id)"; break;
        default:      $conds[] = "idmittente = $id"; break;
    }
    if ($baule === 'si')   $conds[] = "baule = '1'";
    elseif ($baule === 'no') $conds[] = "baule = '0'";
    if ($letto === 'si')   $conds[] = "letto = '1'";
    elseif ($letto === 'no') $conds[] = "letto = '0'";

    $sql = 'DELETE FROM mail WHERE ' . implode(' AND ', $conds);
    $ok  = $db->execute($sql, []);

    if ($ok) {
        $now = time();
        $db->execute(
            'INSERT INTO logs_deletemail
             (data, esecutore, destinatario, invio, baule, letto)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$now, $esecutore, $id, $invio, $baule, $letto]
        );
        return "La posta di $destinatarioNome è stata cancellata";
    } else {
        return "Nessuna missiva corrisponde ai filtri per $destinatarioNome";
    }
}

/** Letterale SQL senza perdita di NULL, apostrofi, backslash o stringhe vuote. */
function AlphanoSQLValue(mixed $value): string
{
    if ($value === null) return 'NULL';
    if ((string)$value === '') return "''";
    return 'CONVERT(0x'.bin2hex((string)$value).' USING utf8mb4)';
}

/** Inserimento SQL autosufficiente per dump e backup recuperabili. */
function AlphanoRowInsert(string $table,array $row): string
{
    if (!$row || !preg_match('/^[a-zA-Z0-9_]+$/D',$table)) throw new InvalidArgumentException('Tabella backup non valida');
    $columns=implode(',',array_map(static fn($column) => '`'.str_replace('`','``',(string)$column).'`',array_keys($row)));
    return 'INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.implode(',',array_map('AlphanoSQLValue',$row)).')';
}

/** Esportazione SQL completa tramite il driver configurato, senza credenziali in comando. */
function AlphanoDatabaseDump(): string
{
    $db = ConnectDB();
    $out = "-- Alphano: esportazione locale\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
    foreach ($db->query('SHOW TABLES') as $tableRow) {
        $table = (string)reset($tableRow);
        $safe = '`' . str_replace('`', '``', $table) . '`';
        $create = $db->query("SHOW CREATE TABLE $safe")[0];
        $out .= "DROP TABLE IF EXISTS $safe;\n" . $create['Create Table'] . ";\n";
        foreach ($db->query("SELECT * FROM $safe") as $row) $out .= AlphanoRowInsert($table,$row).";\n";
    }
    return $out . "SET FOREIGN_KEY_CHECKS=1;\n";
}
