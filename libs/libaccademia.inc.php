<?php
declare(strict_types=1);

require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__ . '/libpg.inc.php';      // for getDB()
require_once __DIR__ . '/libposta.inc.php';   // for InviaMissiva()
require_once __DIR__ . '/libgrafica.inc.php';
require_once __DIR__ . '/libequip.inc.php';
require_once __DIR__ . '/libxml.inc.php';
require_once __DIR__ . '/libbacheca.inc.php';

use Alphano\Database\PDOMySQLDriver;

function AccademiaAtomica(callable $operation): bool
{
    $pdo=getDB()->getConnection();
    $own=!$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $result=(bool)$operation();
        if ($own) { if ($result) $pdo->commit(); else $pdo->rollBack(); }
        return $result;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[accademia] '.$e->getMessage());
        return false;
    }
}

/** L'annuncio pubblico è facoltativo: missiva, archivio ed esito restano autonomi. */
function AccademiaAnnunciaEsito(string $subject, string $message): void
{
    $viewer=alphano_user();
    if (!$viewer) return;
    $db=getDB();
    // I numeri delle bacheche cambiano tra installazioni e importazioni.
    $boards=$db->query("SELECT id FROM luoghi WHERE LOWER(TRIM(location))='generale' AND LOWER(TRIM(nome))='annunci' ORDER BY id FOR UPDATE");
    foreach ($boards as $board) {
        if (!player_board_permissions($db,(int)$board['id'],$viewer)['allowed']) continue;
        try {
            InserisciThread((string)$board['id'],(int)$viewer['id'],$subject,$message,false);
        } catch (Throwable $error) {
            error_log('[accademia annuncio] '.$error->getMessage());
        }
        return;
    }
}


/* ==========================================================================
   VOTANTI & PULIZIA ACCADEMIA
   ========================================================================== */

/** Restituisce il numero di votanti (admin o adminaccademia). */
function GetNumVotanti(): int
{
    $db  = getDB();
    $row = $db->query(
        'SELECT COUNT(*) AS num FROM utenti WHERE admin = ? OR adminaccademia = ?',
        [1, 1]
    )[0];
    return (int)$row['num'];
}

/** Elimina in blocco i voti “inutili” da accademia. */
function PurgeAccademia(): void
{
    $db=getDB();
    $db->execute("DELETE a FROM accademia a LEFT JOIN organizzazioni o ON o.id=a.org WHERE a.org>0 AND (o.id IS NULL OR (o.approvato=1 AND o.newstatuto='' AND o.newidmaster=0))");
    $db->execute("DELETE a FROM accademia a LEFT JOIN corporazioni c ON c.id=a.corp WHERE a.corp>0 AND (c.id IS NULL OR (c.approvato=1 AND c.newstatuto='' AND c.newidmaster=0))");
    $db->execute('DELETE a FROM accademia a LEFT JOIN equip e ON e.id=a.obj LEFT JOIN newequip n ON n.id=a.obj WHERE a.obj>0 AND (e.id IS NULL OR (e.approvato=1 AND n.id IS NULL))');
    $db->execute('DELETE a FROM accademia a LEFT JOIN giocate g ON g.id=a.giocata WHERE a.giocata>0 AND g.id IS NULL');
}


/* ==========================================================================
   ORGANIZZAZIONI
   ========================================================================== */

/** Inserisce in votazione una nuova organizzazione. */
function InsertOrg(string $nome, int $idmaster, ?string $sito, string $statuto, int $city): string
{
    $db = getDB();
    if (!getDB()->query('SELECT 1 FROM city WHERE id=?',[$city]) || !getDB()->query('SELECT 1 FROM utenti WHERE id=?',[$idmaster]) || mb_strlen($nome)>255) return 'Città, fondatore o nome non validi';
    if (getDB()->query('SELECT 1 FROM organizzazioni WHERE idmaster=? AND approvato=0',[$idmaster])) return 'Hai già una proposta in attesa';


    if ($nome === '' || $idmaster <= 0 || $statuto === '' || $city <= 0) {
        return 'Compila correttamente tutti i campi';
    }

    // Controlla esistenza
    if ($db->query('SELECT 1 FROM organizzazioni WHERE nome = ? LIMIT 1', [$nome])) {
        return 'Organizzazione già esistente';
    }

    // Inserimento
    $ok = $db->execute(
        'INSERT INTO organizzazioni (nome, idmaster, sito, statuto, city, budget)
        VALUES (?, ?, ?, ?, ?, ?)',
        [$nome, $idmaster, $sito, $statuto, $city, 5000]
    );
    return $ok
        ? 'Organizzazione inserita in votazione correttamente'
        : 'Errore nell\'inserimento dell\'Organizzazione';
}

/** Restituisce voti positivi/negativi per un’organizzazione. */
function GetVotiOrg(int $org): array
{
    $db   = getDB();
    $rows = $db->query(
        'SELECT voto, COUNT(*) AS tot
           FROM accademia
          WHERE org = ?
          GROUP BY voto',
        [$org]
    );
    $voti = ['vpos' => 0, 'vneg' => 0];
    foreach ($rows as $r) {
        $voti[$r['voto']] = (int)$r['tot'];
    }
    return $voti;
}

/** Elenco organizzazioni in attesa di approvazione. */
function GetOrgNApprovati(): array
{
    return getDB()->query(
        'SELECT * FROM organizzazioni WHERE approvato = 0 ORDER BY nome ASC',
        []
    );
}

/** Elenco organizzazioni con statuto modificato in bozza. */
function GetOrgModificati(): array
{
    return getDB()->query(
        'SELECT * FROM organizzazioni WHERE newstatuto <> ? ORDER BY nome ASC',
        ['']
    );
}

/** Elenco organizzazioni già approvate. */
function GetOrgApprovati(): array
{
    return getDB()->query(
        'SELECT o.*, c.city AS city
           FROM organizzazioni o
           JOIN city c ON c.id = o.city
          WHERE o.approvato = 1
          ORDER BY o.nome ASC',
        []
    );
}

/** Controlla se un PG può votare un’organizzazione. */
function CanVoteOrg(int $idpg, int $org): bool
{
    return count(
        getDB()->query(
            'SELECT 1 FROM accademia WHERE org = ? AND idpg = ?',
            [$org, $idpg]
        )
    ) === 0;
}

/** Approva un’organizzazione e notifica il master. */
function ApprovaOrg(int $org): bool
{
    return AccademiaAtomica(static function() use($org): bool {
        $db=getDB();
        $group=$db->query('SELECT idmaster,nome FROM organizzazioni WHERE id=? AND approvato=0 FOR UPDATE',[$org])[0] ?? null;
        if (!$group || !$db->query('SELECT 1 FROM utenti WHERE id=?',[$group['idmaster']])) return false;
        $master=(int)$group['idmaster'];
        $db->execute('INSERT INTO ramiorganizzazioni(nome,organizzazione,livello) VALUES(?,?,0)',['Direzione',$org]);
        $branch=(int)$db->lastInsertID();
        $db->execute('INSERT INTO caricheorganizzazioni(organizzazione,nome,prima,ramo,livello,descrizione) VALUES(?,?,1,?,0,?)',[$org,'Fondatore',$branch,'Fondatore']);
        $role=(int)$db->lastInsertID();
        if (!alphano_save_badge(['error'=>UPLOAD_ERR_NO_FILE],'/uploads/badges/organizzazioni/'.$org.'/'.$role.'.gif')) return false;
        $db->execute('UPDATE organizzazioni SET approvato=1 WHERE id=?',[$org]);
        $db->execute('DELETE FROM accademia WHERE org=?',[$org]);
        $db->execute('UPDATE utenti SET organizzazione=?,ramoorganizzazione=?,caricaorganizzazione=?,adminorganizzazione=1 WHERE id=?',[$org,$branch,$role,$master]);

        InviaMissiva('Approvazione Organizzazione '.$group['nome'],'La vostra Organizzazione è stata approvata.',$master);
        return true;
    });
}

/** Cancella un’organizzazione e notifica il master. */
function CancellaOrg(int $org): bool
{
    return AccademiaAtomica(static function() use($org): bool {
    if (!getDB()->query("SELECT 1 FROM organizzazioni WHERE id=? AND approvato=0",[$org])) return false;
    $db = getDB();
    // Recupera dati
    $row      = $db->query('SELECT nome, idmaster, statuto FROM organizzazioni WHERE id = ?', [$org])[0];
    $nome     = $row['nome'];
    $statuto  = $row['statuto'];
    $idmaster = (int)$row['idmaster'];
    // Raccolta motivazioni
    $notes = '';
    foreach ($db->query(
        'SELECT u.nome, a.voto, a.motivo
           FROM accademia a
           JOIN utenti u ON u.id = a.idpg
          WHERE a.org = ? AND a.motivo IS NOT NULL',
        [$org]
    ) as $v) {
        $tag   = $v['voto'] === 'vpos' ? 'positivo' : 'negativo';
        $notes .= "\n{$v['nome']} ($tag) : {$v['motivo']}";
    }
    // Operazioni
    $ok  = true;
    $ok = $ok && ($db->execute('DELETE FROM organizzazioni WHERE id = ?', [$org]) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM caricheorganizzazioni WHERE organizzazione = ?', [$org]) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM accademia WHERE org = ?', [$org]) >= 0);
    $ok = $ok && ($db->execute(
        'UPDATE utenti
            SET organizzazione = NULL, caricaorganizzazione = NULL, ramoorganizzazione=0, adminorganizzazione=0
          WHERE organizzazione = ?',
        [$org]
    ) >= 0);
    $ok = $ok && (bool)InviaMissiva(
        "Bocciatura Organizzazione $nome",
        "La vostra Organizzazione non è stata approvata.\nVotazioni:$notes\n\nStatuto:\n$statuto",
        $idmaster
    );
    return $ok;
    });
}

/** Approva modifiche di statuto organizzazione. */
function ApprovaModifOrg(int $org): bool
{
    return AccademiaAtomica(static function() use($org): bool {
    if (!getDB()->query("SELECT 1 FROM organizzazioni WHERE id=? AND (newstatuto<>'' OR newidmaster>0)",[$org])) return false;
    $db = getDB();
    $row = $db->query(
        'SELECT newstatuto, newidmaster, idmaster, nome
           FROM organizzazioni
          WHERE id = ?',
        [$org]
    )[0];
    $newStat   = $row['newstatuto'];
    $newMaster = (int)($row['newidmaster'] ?: $row['idmaster']);
    $nome      = $row['nome'];
    // Motivazioni
    $notes = '';
    foreach ($db->query(
        'SELECT u.nome, a.voto, a.motivo
           FROM accademia a
           JOIN utenti u ON u.id = a.idpg
          WHERE a.org = ? AND a.motivo IS NOT NULL',
        [$org]
    ) as $v) {
        $tag   = $v['voto'] === 'vpos' ? 'positivo' : 'negativo';
        $notes .= "\n{$v['nome']} ($tag) : {$v['motivo']}";
    }
    // Apply
    $ok  = true;
    $ok = $ok && ($db->execute(
        'UPDATE organizzazioni
            SET statuto = newstatuto,
                idmaster = IF(newidmaster>0,newidmaster,idmaster)
          WHERE id = ?',
        [$org]
    ) >= 0);
    $ok = $ok && ($db->execute(
        'UPDATE organizzazioni
            SET newstatuto = ?, newidmaster = 0
          WHERE id = ?',
        ['', $org]
    ) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM accademia WHERE org = ?', [$org]) >= 0);
    $ok = $ok && (bool)InviaMissiva(
        "Approvazione modifiche all’Organizzazione $nome",
        "Le vostre modifiche allo statuto sono state approvate.\nVotazioni:$notes",
        $newMaster
    );
    return $ok;
    });
}

/** Cancella bozza modifiche statuto organizzazione. */
function CancellaModifOrg(int $org): bool
{
    return AccademiaAtomica(static function() use($org): bool {
    if (!getDB()->query("SELECT 1 FROM organizzazioni WHERE id=? AND (newstatuto<>'' OR newidmaster>0)",[$org])) return false;
    $db = getDB();
    $row = $db->query(
        'SELECT newstatuto, newidmaster, idmaster, nome
           FROM organizzazioni
          WHERE id = ?',
        [$org]
    )[0];
    $newStat   = $row['newstatuto'];
    $newMaster = (int)($row['newidmaster'] ?: $row['idmaster']);
    $nome      = $row['nome'];
    // Motivazioni
    $notes = '';
    foreach ($db->query(
        'SELECT u.nome, a.voto, a.motivo
           FROM accademia a
           JOIN utenti u ON u.id = a.idpg
          WHERE a.org = ? AND a.motivo IS NOT NULL',
        [$org]
    ) as $v) {
        $tag   = $v['voto'] === 'vpos' ? 'positivo' : 'negativo';
        $notes .= "\n{$v['nome']} ($tag) : {$v['motivo']}";
    }
    // Reset
    $ok  = true;
    $ok = $ok && ($db->execute(
        'UPDATE organizzazioni
            SET newstatuto = ?, newidmaster = 0
          WHERE id = ?',
        ['', $org]
    ) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM accademia WHERE org = ?', [$org]) >= 0);
    $ok = $ok && (bool)InviaMissiva(
        "Bocciatura modifiche all’Organizzazione $nome",
        "Le vostre modifiche non sono state approvate.\nVotazioni:$notes\n\nStatuto modificato:\n$newStat",
        $newMaster
    );
    return $ok;
    });
}

/** Vota un’organizzazione. */
function VotaOrg(int $org, int $idpg, bool $positivo, string $motivo): bool
{
    return AccademiaAtomica(static function() use($org,$idpg,$positivo,$motivo): bool {
    $user=getDB()->query('SELECT admin,adminaccademia FROM utenti WHERE id=? FOR UPDATE',[$idpg])[0] ?? [];
    if (empty($user['admin']) && empty($user['adminaccademia'])) return false;
    if (!getDB()->query("SELECT 1 FROM organizzazioni WHERE id=? AND (approvato=0 OR newstatuto<>'' OR newidmaster>0)",[$org])) return false;
    $db = getDB();
    $motivo = trim($motivo);
    if (!$positivo && $motivo === '') {
        return false;
    }
    if (!CanVoteOrg($idpg, $org)) {
        return false;
    }
    $voto = $positivo ? 'vpos' : 'vneg';
    return ($db->execute(
        'INSERT INTO accademia (idpg, org, voto, motivo) VALUES (?, ?, ?, ?)',
        [$idpg, $org, $voto, $motivo]
    ) >= 0);
    });
}

/** Legge lo statuto (o la bozza) di un’organizzazione. */
function GetStatutoOrg(int $org, bool $new = false): string
{
    $row = getDB()->query('SELECT statuto, newstatuto FROM organizzazioni WHERE id = ?', [$org])[0] ?? [];
    return (string)($new && !empty($row['newstatuto']) ? $row['newstatuto'] : ($row['statuto'] ?? ''));
}

/** Salva bozza di statuto per un’organizzazione. */
function SalvaStatutoOrg(int $org, int $master, string $statuto): bool
{
    if (trim($statuto)==='') return false;
    return AccademiaAtomica(static function() use($org,$statuto): bool {
        $db=getDB();
        $current=$db->query('SELECT newstatuto FROM organizzazioni WHERE id=? AND approvato=1 FOR UPDATE',[$org])[0] ?? null;
        if (!$current) return false;
        if ((string)$current['newstatuto']!==$statuto) $db->execute('DELETE FROM accademia WHERE org=?',[$org]);
        $db->execute('UPDATE organizzazioni SET newstatuto=?,newidmaster=idmaster WHERE id=?',[$statuto,$org]);
        return true;
    });
}

/** Imposta i flag resurgo, cerimonia e ferite. */
function SetFlagOrg(int $org, bool $resurgo, bool $cerimonia, bool $ferite): bool
{
    return (bool)getDB()->execute(
        'UPDATE organizzazioni
            SET resurgo   = ?,
                cerimonia = ?,
                ferite    = ?
          WHERE id = ?',
        [$resurgo ? 1 : 0, $cerimonia ? 1 : 0, $ferite ? 1 : 0, $org]
    );
}


/* ==========================================================================
   CORPORAZIONI
   ========================================================================== */

/**
 * Inserisce in votazione una nuova corporazione.
 */
function InsertCorp(
    string  $nome,
    int     $idmaster,
    ?string $sito,
    string  $statuto,
    int     $city,
    array   $produzioni
): string
{
    $db = getDB();
    if (!getDB()->query('SELECT 1 FROM city WHERE id=?',[$city]) || !getDB()->query('SELECT 1 FROM utenti WHERE id=?',[$idmaster]) || mb_strlen($nome)>255) return 'Città, fondatore o nome non validi';
    if (getDB()->query('SELECT 1 FROM corporazioni WHERE idmaster=? AND approvato=0',[$idmaster])) return 'Hai già una proposta in attesa';
    $points=0;
    foreach ($produzioni as $subtype=>$quality) {
        if (!array_key_exists((int)$quality,QUALITY) || !getDB()->query('SELECT 1 FROM subtipi WHERE id=?',[(int)$subtype])) return 'Produzione o qualità non valida';
        $points+=QUALITY[(int)$quality]['costo'];
    }
    if ($points>MAX_POINT_VALUE) return 'Punto valore superato';


    if ($nome === '' || $idmaster <= 0 || $statuto === '' || $city <= 0) {
        return 'Compila correttamente tutti i campi';
    }

    if ($db->query('SELECT 1 FROM corporazioni WHERE nome = ? LIMIT 1', [$nome])) {
        return 'Corporazione già esistente';
    }

    $ok = $db->execute(
        'INSERT INTO corporazioni
            (nome, idmaster, sito, statuto, city, budget, newstatuto, descrizione)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$nome, $idmaster, $sito, $statuto, $city, 5000, '', '']
    );

    if (!$ok) {
        return 'Errore nell\'inserimento della Corporazione';
    }

    $newId = (int)$db->query(
        'SELECT id FROM corporazioni
          WHERE nome = ? AND idmaster = ? AND city = ? LIMIT 1',
        [$nome, $idmaster, $city]
    )[0]['id'];

    $res = true;
    foreach ($produzioni as $subtipo => $qualita) {
        if ($qualita) {
            $res = $res && ($db->execute(
                'INSERT INTO produzionicorporazioni
                     (corporazione, produzione, quality)
                 VALUES (?, ?, ?)',
                [$newId, $subtipo, $qualita]
            ) >= 0);
        }
    }

    return $res
        ? 'Corporazione correttamente inserita'
        : 'Errore nell\'inserimento della Corporazione';
}

/** Restituisce voti positivi/negativi per una corporazione. */
function GetVotiCorp(int $corp): array
{
    $db   = getDB();
    $rows = $db->query(
        'SELECT voto, COUNT(*) AS tot
           FROM accademia
          WHERE corp = ?
          GROUP BY voto',
        [$corp]
    );
    $voti = ['vpos' => 0, 'vneg' => 0];
    foreach ($rows as $r) {
        $voti[$r['voto']] = (int)$r['tot'];
    }
    return $voti;
}

/** Elenco corporazioni in attesa di approvazione. */
function GetCorpNApprovate(): array
{
    return getDB()->query(
        'SELECT * FROM corporazioni WHERE approvato = 0 ORDER BY nome ASC',
        []
    );
}

/** Elenco corporazioni con statuto modificato in bozza. */
function GetCorpModificati(): array
{
    return getDB()->query(
        'SELECT * FROM corporazioni WHERE newstatuto <> ? ORDER BY nome ASC',
        ['']
    );
}

/** Elenco corporazioni già approvate. */
function GetCorpApprovate(): array
{
    return getDB()->query(
        'SELECT c.*, ct.city AS city
           FROM corporazioni c
           JOIN city ct ON ct.id = c.city
          WHERE c.approvato = 1
          ORDER BY c.nome ASC',
        []
    );
}

/** Controlla se un PG può votare una corporazione. */
function CanVoteCorp(int $idpg, int $corp): bool
{
    return count(
        getDB()->query(
            'SELECT 1 FROM accademia WHERE corp = ? AND idpg = ?',
            [$corp, $idpg]
        )
    ) === 0;
}

/** Approva una corporazione e notifica il master. */
/**
 * Approva una corporazione e notifica il master,
 * nominando il proponente come Capo Corporazione.
 */
function ApprovaCorp(int $corp): bool
{
    return AccademiaAtomica(static function() use($corp): bool {
        $db=getDB();
        $group=$db->query('SELECT idmaster,nome FROM corporazioni WHERE id=? AND approvato=0 FOR UPDATE',[$corp])[0] ?? null;
        if (!$group || !$db->query('SELECT 1 FROM utenti WHERE id=?',[$group['idmaster']])) return false;
        $master=(int)$group['idmaster'];
        $db->execute('INSERT INTO ramicorporazioni(nome,corporazione,livello) VALUES(?,?,0)',['Direzione',$corp]);
        $branch=(int)$db->lastInsertID();
        $db->execute('INSERT INTO carichecorporazioni(corporazione,nome,prima,ramo,livello,descrizione) VALUES(?,?,1,?,0,?)',[$corp,'Fondatore',$branch,'Fondatore']);
        $role=(int)$db->lastInsertID();
        if (!alphano_save_badge(['error'=>UPLOAD_ERR_NO_FILE],'/uploads/badges/corporazioni/'.$corp.'/'.$role.'.gif')) return false;
        $db->execute('UPDATE corporazioni SET approvato=1 WHERE id=?',[$corp]);
        $db->execute('DELETE FROM accademia WHERE corp=?',[$corp]);
        $db->execute('UPDATE utenti SET corporazione=?,ramocorporazione=?,caricacorporazione=?,admincorporazione=1,admincatalogo=1,admintrasfcorp=1 WHERE id=?',[$corp,$branch,$role,$master]);
        foreach (MATERIE_PRIME as $mat) {
            $qty=in_array($mat,['oro','argento','platino','diamante'],true) ? 5 : 20;
            $db->execute('INSERT INTO equipcorp(corp,materia,qta) VALUES(?,?,?)',[$corp,$mat,$qty]);
        }
        InviaMissiva('Approvazione Corporazione '.$group['nome'],'La vostra Corporazione è stata approvata.',$master);
        return true;
    });
}




/** Cancella una corporazione e notifica il master. */
function CancellaCorp(int $corp): bool
{
    return AccademiaAtomica(static function() use($corp): bool {
    if (!getDB()->query("SELECT 1 FROM corporazioni WHERE id=? AND approvato=0",[$corp])) return false;
    $db = getDB();
    $row      = $db->query('SELECT nome, idmaster, statuto FROM corporazioni WHERE id = ?', [$corp])[0];
    $nome     = $row['nome'];
    $statuto  = $row['statuto'];
    $idmaster = (int)$row['idmaster'];
    $notes    = '';
    foreach ($db->query(
        'SELECT u.nome, a.voto, a.motivo
           FROM accademia a
           JOIN utenti u ON u.id = a.idpg
          WHERE a.corp = ? AND a.motivo IS NOT NULL',
        [$corp]
    ) as $v) {
        $tag   = $v['voto'] === 'vpos' ? 'positivo' : 'negativo';
        $notes .= "\n{$v['nome']} ($tag) : {$v['motivo']}";
    }
    $ok  = true;
    $ok = $ok && ($db->execute('DELETE FROM corporazioni WHERE id = ?', [$corp]) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM carichecorporazioni WHERE corporazione = ?', [$corp]) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM accademia WHERE corp = ?', [$corp]) >= 0);
    $ok = $ok && ($db->execute(
        'UPDATE utenti
            SET corporazione = NULL,
                caricacorporazione = NULL, ramocorporazione=0, admincorporazione=0, admincatalogo=0, admintrasfcorp=0
          WHERE corporazione = ?',
        [$corp]
    ) >= 0);
    $ok = $ok && (bool)InviaMissiva(
        "Bocciatura Corporazione $nome",
        "La vostra Corporazione non è stata approvata.\nVotazioni:$notes\n\nStatuto:\n$statuto",
        $idmaster
    );
    return $ok;
    });
}

/** Approva modifiche di statuto corporazione. */
function ApprovaModifCorp(int $corp): bool
{
    return AccademiaAtomica(static function() use($corp): bool {
    if (!getDB()->query("SELECT 1 FROM corporazioni WHERE id=? AND (newstatuto<>'' OR newidmaster>0)",[$corp])) return false;
    $db = getDB();
    $row = $db->query(
        'SELECT newstatuto, newidmaster, idmaster, nome
           FROM corporazioni
          WHERE id = ?',
        [$corp]
    )[0];
    $newStat   = $row['newstatuto'];
    $newMaster = (int)($row['newidmaster'] ?: $row['idmaster']);
    $nome      = $row['nome'];
    $notes     = '';
    foreach ($db->query(
        'SELECT u.nome, a.voto, a.motivo
           FROM accademia a
           JOIN utenti u ON u.id = a.idpg
          WHERE a.corp = ? AND a.motivo IS NOT NULL',
        [$corp]
    ) as $v) {
        $tag   = $v['voto'] === 'vpos' ? 'positivo' : 'negativo';
        $notes .= "\n{$v['nome']} ($tag) : {$v['motivo']}";
    }
    $ok  = true;
    $ok = $ok && ($db->execute(
        'UPDATE corporazioni
            SET statuto = newstatuto,
                idmaster = IF(newidmaster>0,newidmaster,idmaster)
          WHERE id = ?',
        [$corp]
    ) >= 0);
    $ok = $ok && ($db->execute(
        'UPDATE corporazioni
            SET newstatuto = ?, newidmaster = 0
          WHERE id = ?',
        ['', $corp]
    ) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM accademia WHERE corp = ?', [$corp]) >= 0);
    $ok = $ok && (bool)InviaMissiva(
        "Approvazione modifiche alla Corporazione $nome",
        "Le vostre modifiche allo statuto sono state approvate.\nVotazioni:$notes",
        $newMaster
    );
    return $ok;
    });
}

/** Cancella bozza modifiche statuto corporazione. */
function CancellaModifCorp(int $corp): bool
{
    return AccademiaAtomica(static function() use($corp): bool {
    if (!getDB()->query("SELECT 1 FROM corporazioni WHERE id=? AND (newstatuto<>'' OR newidmaster>0)",[$corp])) return false;
    $db = getDB();
    $row = $db->query(
        'SELECT newstatuto, newidmaster, idmaster, nome
           FROM corporazioni
          WHERE id = ?',
        [$corp]
    )[0];
    $newStat   = $row['newstatuto'];
    $newMaster = (int)($row['newidmaster'] ?: $row['idmaster']);
    $nome      = $row['nome'];
    $notes     = '';
    foreach ($db->query(
        'SELECT u.nome, a.voto, a.motivo
           FROM accademia a
           JOIN utenti u ON u.id = a.idpg
          WHERE a.corp = ? AND a.motivo IS NOT NULL',
        [$corp]
    ) as $v) {
        $tag   = $v['voto'] === 'vpos' ? 'positivo' : 'negativo';
        $notes .= "\n{$v['nome']} ($tag) : {$v['motivo']}";
    }
    $ok  = true;
    $ok = $ok && ($db->execute(
        'UPDATE corporazioni
            SET newstatuto = ?, newidmaster = 0
          WHERE id = ?',
        ['', $corp]
    ) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM accademia WHERE corp = ?', [$corp]) >= 0);
    $ok = $ok && (bool)InviaMissiva(
        "Bocciatura modifiche alla Corporazione $nome",
        "Le vostre modifiche non sono state approvate.\nVotazioni:$notes\n\nStatuto modificato:\n$newStat",
        $newMaster
    );
    return $ok;
    });
}

/** Vota una corporazione. */
function VotaCorp(int $corp, int $idpg, bool $positivo, string $motivo): bool
{
    return AccademiaAtomica(static function() use($corp,$idpg,$positivo,$motivo): bool {
    $user=getDB()->query('SELECT admin,adminaccademia FROM utenti WHERE id=? FOR UPDATE',[$idpg])[0] ?? [];
    if (empty($user['admin']) && empty($user['adminaccademia'])) return false;
    if (!getDB()->query("SELECT 1 FROM corporazioni WHERE id=? AND (approvato=0 OR newstatuto<>'' OR newidmaster>0)",[$corp])) return false;
    $db = getDB();
    $motivo = trim($motivo);
    if (!$positivo && $motivo === '') {
        return false;
    }
    if (!CanVoteCorp($idpg, $corp)) {
        return false;
    }
    $voto = $positivo ? 'vpos' : 'vneg';
    return ($db->execute(
        'INSERT INTO accademia (idpg, corp, voto, motivo) VALUES (?, ?, ?, ?)',
        [$idpg, $corp, $voto, $motivo]
    ) >= 0);
    });
}

/** Legge lo statuto (o la bozza) di una corporazione. */
function GetStatutoCorp(int $corp, bool $new = false): string
{
    $row = getDB()->query('SELECT statuto, newstatuto FROM corporazioni WHERE id = ?', [$corp])[0] ?? [];
    return (string)($new && !empty($row['newstatuto']) ? $row['newstatuto'] : ($row['statuto'] ?? ''));
}

/** Salva bozza di statuto per una corporazione. */
function SalvaStatutoCorp(int $corp, int $master, string $statuto): bool
{
    if (trim($statuto)==='') return false;
    return AccademiaAtomica(static function() use($corp,$statuto): bool {
        $db=getDB();
        $current=$db->query('SELECT newstatuto FROM corporazioni WHERE id=? AND approvato=1 FOR UPDATE',[$corp])[0] ?? null;
        if (!$current) return false;
        if ((string)$current['newstatuto']!==$statuto) $db->execute('DELETE FROM accademia WHERE corp=?',[$corp]);
        $db->execute('UPDATE corporazioni SET newstatuto=?,newidmaster=idmaster WHERE id=?',[$statuto,$corp]);
        return true;
    });
}


/* ==========================================================================
   OGGETTI (equip)
   ========================================================================== */

/** Restituisce voti positivi/negativi per un oggetto. */
function GetVotiObj(int $obj): array
{
    $db   = getDB();
    $rows = $db->query(
        'SELECT voto, COUNT(*) AS tot
           FROM accademia
          WHERE obj = ?
          GROUP BY voto',
        [$obj]
    );
    $voti = ['vpos' => 0, 'vneg' => 0];
    foreach ($rows as $r) {
        $voti[$r['voto']] = (int)$r['tot'];
    }
    return $voti;
}

/** Elenco oggetti in attesa di approvazione. */
function GetObjNApprovati(): array
{
    return getDB()->query(
        'SELECT * FROM equip WHERE approvato = 0 ORDER BY shortDesc',
        []
    );
}

/** Elenco oggetti con modifica in bozza. */
function GetObjModificati(): array
{
    return getDB()->query(
        'SELECT * FROM newequip ORDER BY shortDesc',
        []
    );
}

/** Controlla se un PG può votare un oggetto. */
function CanVoteObj(int $idpg, int $obj): bool
{
    return count(
        getDB()->query(
            'SELECT 1 FROM accademia WHERE obj = ? AND idpg = ?',
            [$obj, $idpg]
        )
    ) === 0;
}

/** Vota un oggetto. */
function VotaObj(int $obj, int $idpg, bool $positivo, string $motivo): bool
{
    return AccademiaAtomica(static function() use($obj,$idpg,$positivo,$motivo): bool {
    $user=getDB()->query('SELECT admin,adminaccademia FROM utenti WHERE id=? FOR UPDATE',[$idpg])[0] ?? [];
    if (empty($user['admin']) && empty($user['adminaccademia'])) return false;
    if (!getDB()->query('SELECT 1 FROM equip e LEFT JOIN newequip n ON n.id=e.id WHERE e.id=? AND (e.approvato=0 OR n.id IS NOT NULL)',[$obj])) return false;
    $db = getDB();
    $motivo = trim($motivo);
    if (!$positivo && $motivo === '') {
        return false;
    }
    if (!CanVoteObj($idpg, $obj)) {
        return false;
    }
    $voto = $positivo ? 'vpos' : 'vneg';
    return ($db->execute(
        'INSERT INTO accademia (idpg, obj, voto, motivo) VALUES (?, ?, ?, ?)',
        [$idpg, $obj, $voto, $motivo]
    ) >= 0);
    });
}

/** Approva un oggetto e notifica il creatore. */
function ApprovaObj(int $obj): bool
{
    return AccademiaAtomica(static function() use($obj): bool {
    if (!getDB()->query("SELECT 1 FROM equip WHERE id=? AND approvato=0",[$obj])) return false;
    $db = getDB();
    // Recupera dati
    $row      = $db->query('SELECT idcreatore, shortDesc FROM equip WHERE id = ?', [$obj])[0];
    $idmaster = (int)$row['idcreatore'];
    $nome     = $row['shortDesc'];
    // Se esiste newimage, la sposto
    $newPath = rtrim(htmlroot, '/') . '/uploads/equipment-pending/' . $obj;
    $imgPath = '/uploads/equipment/' . $obj;
    if (file_exists($newPath)) {
        if (!rename($newPath, rtrim(htmlroot, '/') . '/uploads/equipment/' . $obj)) return false;
        $db->execute(
            'UPDATE equip SET approvato = 1, immagine = ? WHERE id = ?',
            [$imgPath, $obj]
        );
    } else {
        $db->execute(
            'UPDATE equip SET approvato = 1 WHERE id = ?',
            [$obj]
        );
    }
    // Cancella voti
    $db->execute('DELETE FROM accademia WHERE obj = ?', [$obj]);
    // Notifica
    return (bool)InviaMissiva(
        "Approvazione Oggetto $nome",
        "L'Oggetto da voi proposto è stato approvato.",
        $idmaster
    );
    });
}

/** Cancella un oggetto e notifica il creatore. */
function CancellaObj(int $obj): bool
{
    return AccademiaAtomica(static function() use($obj): bool {
    if (!getDB()->query("SELECT 1 FROM equip WHERE id=? AND approvato=0",[$obj])) return false;
    $db = getDB();
    // Recupera tutti i dati per comporre la missiva
    $row = $db->query(
        'SELECT e.*, t.nome AS tipo
           FROM equip e
           JOIN tipo t ON t.id = e.tipo
          WHERE e.id = ?',
        [$obj]
    )[0];
    $idmaster = (int)$row['idcreatore'];
    $nome     = $row['shortDesc'];
    // Costruzione dettagli oggetto
    $details = "Nome: {$row['shortDesc']}\nImmagine: {$row['immagine']}\n";
    $details .= "Descrizione:\n{$row['desc']}\nTipo: {$row['tipo']}\n";
    $details .= "Peso: {$row['peso']}, Durata: {$row['durata']}, Mani: {$row['mani']}\n";
    $sizes = ['Minuta','Piccola','Media','Grande','Gigante'];
    $details .= "Taglia: {$sizes[$row['taglia']]}\n";
    if ($row['lavorazione']) {
        $details .= "Lavorazione: {$row['lavorazione']}\n";
    }
    if ($row['restore']) {
        $details .= "Ripristina PF: {$row['restore']}\n";
    }
    if ($row['sacro']) {
        $details .= "Oggetto Sacro\n";
    }
    if ($row['destinatario']) {
        $dest = GetPGFromID($row['destinatario']);
        $details .= "Per PG: {$dest}\n";
    }
    if ($row['destorg']) {
        $destorg = GetOrg($row['destorg']);
        $details .= "Per Org: {$destorg}\n";
    }
    $details .= "Materie Prime:\n";
    foreach ($row as $k => $v) {
        if (!is_int($k) && !in_array($k, ['id','desc','immagine','taglia','idcreatore','sacro','destinatario','destorg'], true) && $v > 0) {
            $details .= "$k: $v\n";
        }
    }
    // Raccolta motivazioni
    $notes = '';
    foreach ($db->query(
        'SELECT u.nome, a.voto, a.motivo
           FROM accademia a
           JOIN utenti u ON u.id = a.idpg
          WHERE a.obj = ? AND a.motivo IS NOT NULL',
        [$obj]
    ) as $v) {
        $tag   = $v['voto'] === 'vpos' ? 'positivo' : 'negativo';
        $notes .= "\n{$v['nome']} ($tag) : {$v['motivo']}";
    }
    // A catalogue deletion must not destroy an original instance held as a game stake.
    if($db->query("SHOW TABLES LIKE 'board_game_escrow'") && $db->query('SELECT s.id FROM board_game_escrow s JOIN equippg p ON p.id=s.asset_id WHERE p.uid=? AND s.released_at=0 LIMIT 1',[$obj]))throw new DomainException('Un esemplare è impegnato in una partita. Annulla prima la partita dal pannello Giochi, restituendo le poste.',409);
    // Cancellazioni
    $ok  = true;
    $ok = $ok && ($db->execute('DELETE FROM equip WHERE id = ?', [$obj]) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM equippg WHERE uid = ?', [$obj]) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM accademia WHERE obj = ?', [$obj]) >= 0);
    // Notifica
    $miss = InviaMissiva(
        "Bocciatura Oggetto $nome",
        "L'Oggetto non è stato approvato.\nVotazioni:$notes\n\nDettagli:\n$details",
        $idmaster
    );
    // Rimuove eventuale newimage residua
    $newPath = rtrim(htmlroot, '/') . '/uploads/equipment-pending/' . $obj;
    if (file_exists($newPath)) {
        unlink($newPath);
    }
    return (bool)$miss;
    });
}

/** Approva modifiche di un oggetto. */
/** Approva modifiche di un oggetto. */
function ApprovaModifObj(int $obj): bool
{
    return AccademiaAtomica(static function() use($obj): bool {
    if (!getDB()->query("SELECT 1 FROM newequip WHERE id=?",[$obj])) return false;
    $db   = getDB();
    // Prendo i dati dalla bozza
    $row  = $db->query('SELECT * FROM newequip WHERE id = ?', [$obj])[0];
    $idmaster = (int)$row['idcreatore'];
    $nome     = $row['shortDesc'];

    // Sposto l’immagine se presente
    $newPath = rtrim(htmlroot, '/') . '/uploads/equipment-pending/' . $obj;
    if (file_exists($newPath)) {
        if (!rename($newPath, rtrim(htmlroot, '/') . '/uploads/equipment/' . $obj)) return false;
        // aggiorno nel record il percorso definitivo
        $row['immagine'] = '/uploads/equipment/' . $obj;
    }

    // Costruisco array di colonne e valori per l’UPDATE parametrizzato
    $cols = [];
    $vals = [];
    foreach ($row as $k => $v) {
        // escludo id e approvato, li gestisco a parte
        if (!in_array($k, ['id', 'approvato'], true)) {
            // uso backtick per evitare parole riservate
            $cols[]  = "e.`$k` = ?";
            $vals[]  = $v;
        }
    }
    // imposto approvato = 1
    $cols[] = "e.`approvato` = 1";

    // preparo la parte SET
    $setSql = implode(', ', $cols);
    // aggiungo l’id finale per il WHERE
    $vals[]  = $obj;

    // Eseguo l’UPDATE sicuro
    $ok  = ($db->execute(
        "UPDATE equip e SET $setSql WHERE e.id = ?",
        $vals
    ) >= 0);

    // Pulisco bozza e votazioni
    $ok  = $ok && ($db->execute('DELETE FROM newequip WHERE id = ?', [$obj]) >= 0);
    $ok  = $ok && ($db->execute('DELETE FROM accademia WHERE obj = ?',    [$obj]) >= 0);

    // Notifico il creatore
    $ok  = $ok && (bool)InviaMissiva(
        "Approvazione modifiche all'Oggetto $nome",
        "Le modifiche all'Oggetto sono state approvate.",
        $idmaster
    );

    // Rimuovo eventuale immagine residua in newimages
    if (file_exists($newPath)) {
        @unlink($newPath);
    }

    return $ok;
    });
}


/** Cancella bozza di modifiche di un oggetto. */
function CancellaModifObj(int $obj): bool
{
    return AccademiaAtomica(static function() use($obj): bool {
    if (!getDB()->query("SELECT 1 FROM newequip WHERE id=?",[$obj])) return false;
    $db  = getDB();
    $row = $db->query('SELECT * FROM newequip WHERE id = ?', [$obj])[0];
    $idmaster = (int)$row['idcreatore'];
    $nome     = $row['shortDesc'];
    // Costruisce dettagli ridotto
    $details = "Nome: {$row['shortDesc']}\nImmagine: {$row['immagine']}\n…";
    // Raccolta motivazioni
    $notes = '';
    foreach ($db->query(
        'SELECT u.nome, a.voto, a.motivo
           FROM accademia a
           JOIN utenti u ON u.id = a.idpg
          WHERE a.obj = ? AND a.motivo IS NOT NULL',
        [$obj]
    ) as $v) {
        $tag   = $v['voto'] === 'vpos' ? 'positivo' : 'negativo';
        $notes .= "\n{$v['nome']} ($tag) : {$v['motivo']}";
    }
    $ok  = true;
    $ok = $ok && ($db->execute('DELETE FROM newequip WHERE id = ?', [$obj]) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM accademia WHERE obj = ?', [$obj]) >= 0);
    $ok = $ok && (bool)InviaMissiva(
        "Bocciatura modifiche all’Oggetto $nome",
        "Le modifiche non sono state approvate.\nVotazioni:$notes\n\nDettagli modificati:\n$details",
        $idmaster
    );
    $newPath = rtrim(htmlroot, '/') . '/uploads/equipment-pending/' . $obj;
    if (file_exists($newPath)) {
        unlink($newPath);
    }
    return $ok;
    });
}


/* ==========================================================================
   GIOCATE
   ========================================================================== */

/** Restituisce l'elenco di tutte le giocate in attesa. */
function GetGiocate(): array
{
    return getDB()->query('SELECT * FROM giocate', []);
}

/** Restituisce il nome leggibile di una giocata. */
function GetNomeGiocata(int $giocata, string $nome = ''): string
{
    if ($nome === '') {
        $row  = getDB()->query('SELECT nome FROM giocate WHERE id = ?', [$giocata])[0];
        $nome = $row['nome'];
    }
    return $nome !== ''
        ? "{$nome} (Giocata #{$giocata})"
        : "Giocata #{$giocata}";
}

/** Controlla se un PG può votare una giocata. */
function CanVoteGiocata(int $idpg, int $giocata): bool
{
    return count(
        getDB()->query(
            'SELECT 1 FROM accademia WHERE giocata = ? AND idpg = ?',
            [$giocata, $idpg]
        )
    ) === 0;
}

/** Restituisce voti positivi/negativi per una giocata. */
function GetVotiGiocata(int $giocata): array
{
    $db   = getDB();
    $rows = $db->query(
        'SELECT voto, COUNT(*) AS tot
           FROM accademia
          WHERE giocata = ?
          GROUP BY voto',
        [$giocata]
    );
    $voti = ['vpos' => 0, 'vneg' => 0];
    foreach ($rows as $r) {
        $voti[$r['voto']] = (int)$r['tot'];
    }
    return $voti;
}

/** Vota una giocata. */
function VotaGiocata(int $giocata, int $idpg, bool $positivo, string $motivo): bool
{
    return AccademiaAtomica(static function() use($giocata,$idpg,$positivo,$motivo): bool {
    $user=getDB()->query('SELECT admin,adminaccademia FROM utenti WHERE id=? FOR UPDATE',[$idpg])[0] ?? [];
    if (empty($user['admin']) && empty($user['adminaccademia'])) return false;
    if (!getDB()->query('SELECT 1 FROM giocate WHERE id=?',[$giocata])) return false;
    $db = getDB();
    $motivo = trim($motivo);
    if (!$positivo && $motivo === '') {
        return false;
    }
    if (!CanVoteGiocata($idpg, $giocata)) {
        return false;
    }
    $voto = $positivo ? 'vpos' : 'vneg';
    return ($db->execute(
        'INSERT INTO accademia (idpg, giocata, voto, motivo) VALUES (?, ?, ?, ?)',
        [$idpg, $giocata, $voto, $motivo]
    ) >= 0);
    });
}

/** Approva una giocata e la inserisce in sessioni, notifica e thread. */
function ApprovaGiocata(int $giocata): bool
{
    return AccademiaAtomica(static function() use($giocata): bool {
    if (!getDB()->query("SELECT 1 FROM giocate WHERE id=?",[$giocata])) return false;
    $db = getDB();
    $row = $db->query('SELECT * FROM giocate WHERE id = ?', [$giocata])[0];
    $idpg   = (int)$row['idpg'];
    $data   = $row['data'];
    $nomeG  = GetNomeGiocata($giocata, $row['nome']);
    $xml    = base64_decode($row['giocata']);
    $array  = GetArrayFromBZXML($xml, 'giocata');

    // Raccolta motivazioni
    $notes = '';
    foreach ($db->query(
        'SELECT u.nome, a.voto, a.motivo
           FROM accademia a
           JOIN utenti u ON u.id = a.idpg
          WHERE a.giocata = ? AND a.motivo IS NOT NULL',
        [$giocata]
    ) as $v) {
        $tag   = $v['voto'] === 'vpos' ? 'positivo' : 'negativo';
        $notes .= "\n{$v['nome']} ($tag) : {$v['motivo']}";
    }

    // Inserimento sessione
    $ok  = ($db->execute(
        'INSERT INTO sessioni (nome, datain, dataout, partecipanti) VALUES (?, ?, ?, ?)',
        [$nomeG, $data, $data, ';'.$idpg.';']
    ) >= 0);
    $newId = (int)$db->lastInsertID();
    if ($newId) {
        $logDirectory = rtrim(htmlroot, '/') . '/sessione/log';
        if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true)) return false;
        $logFile = $logDirectory . "/{$newId}.html";
        $logContent = implode("<br />\n", array_map('strval', $array)) . "<br />\n";
        if (file_put_contents($logFile, $logContent, LOCK_EX) === false) return false;
    }

    // Cancellazioni
    $ok = $ok && ($db->execute('DELETE FROM giocate WHERE id = ?', [$giocata]) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM accademia WHERE giocata = ?', [$giocata]) >= 0);

    // Notifiche
    $oggetto = "Approvazione giocata $nomeG";
    $ok = $ok && (bool)InviaMissiva($oggetto, "La Giocata è stata approvata.\nVotazioni:$notes", $idpg);
    if ($ok) AccademiaAnnunciaEsito($oggetto,
        "La giocata proposta da " . GetPGFromID($idpg) . " è stata approvata.\nVotazioni:$notes");

    return $ok;
    });
}

/** Cancella una giocata e notifica/ thread. */
function CancellaGiocata(int $giocata): bool
{
    return AccademiaAtomica(static function() use($giocata): bool {
    if (!getDB()->query("SELECT 1 FROM giocate WHERE id=?",[$giocata])) return false;
    $db = getDB();
    $row = $db->query('SELECT * FROM giocate WHERE id = ?', [$giocata])[0];
    $idpg   = (int)$row['idpg'];
    $nomeG  = GetNomeGiocata($giocata, $row['nome']);

    // Raccolta motivazioni
    $notes = '';
    foreach ($db->query(
        'SELECT u.nome, a.voto, a.motivo
           FROM accademia a
           JOIN utenti u ON u.id = a.idpg
          WHERE a.giocata = ? AND a.motivo IS NOT NULL',
        [$giocata]
    ) as $v) {
        $tag   = $v['voto'] === 'vpos' ? 'positivo' : 'negativo';
        $notes .= "\n{$v['nome']} ($tag) : {$v['motivo']}";
    }

    // Cancellazioni
    $ok  = ($db->execute('DELETE FROM giocate WHERE id = ?', [$giocata]) >= 0);
    $ok = $ok && ($db->execute('DELETE FROM accademia WHERE giocata = ?', [$giocata]) >= 0);

    // Notifiche
    $oggetto = "Invalidazione giocata $nomeG";
    $ok = $ok && (bool)InviaMissiva(
        $oggetto,
        "La giocata proposta è stata invalidata.\nVotazioni:$notes",
        $idpg
    );
    if ($ok) AccademiaAnnunciaEsito($oggetto,
        "La giocata proposta da " . GetPGFromID($idpg) . " è stata invalidata.\nVotazioni:$notes");

    return $ok;
    });
}

?>
