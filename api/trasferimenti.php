<?php
declare(strict_types=1);

foreach (array_merge($_GET,$_POST) as $value) {
    if (is_array($value) || is_object($value)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>true,'message'=>'Parametri non validi']);
        exit;
    }
}

// ─── disabilito warning/output accidentale ─────────────────────────────────
ini_set('display_errors','0');
error_reporting(E_ALL);

// ─── header JSON ────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

// ─── librerie ───────────────────────────────────────────────────────────────
require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libeconomia.inc.php';
require_once __DIR__ . '/../libs/libposta.inc.php';
require_once __DIR__ . '/../libs/libequip.inc.php';

/**
 * Restituisce il nome del PG dato l’ID (helper per cronologia denaro).
 */
function getUserName(int $uid): string {
    global $db;
    $row = $db->query('SELECT nome FROM utenti WHERE id = ?', [$uid])[0] ?? null;
    return $row['nome'] ?? '—';
}

/** risposta JSON + exit */
function respond(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ─── autenticazione ──────────────────────────────────────────────────
    $idpg = alphano_require_login(true);
    if (!$idpg) respond(['success'=>false,'error'=>true,'message'=>'Non autenticato']);

    $db   = getDB();
    $user = $db->query(
        'SELECT admin,corporazione,nome,admintrasfcorp,admincorporazione,denari,razza
           FROM utenti WHERE id = ?', [$idpg]
    )[0] ?? null;

    if (!$user) respond(['success'=>false,'error'=>true,'message'=>'Utente non trovato']);
    if (in_array((int)$user['razza'],[ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT],true)) { http_response_code(403); respond(['success'=>false,'error'=>true,'message'=>'Non puoi amministrare i tuoi soldi quando sei morto']); }

    $corp          = (int)$user['corporazione'];
    $pgname        =  (string)$user['nome'];
    $saldo         = (int)$user['denari'];
    $isAdminTransf = !empty($user['admin']) || (bool)$user['admintrasfcorp'] || (bool)$user['admincorporazione'];
    $isAdminCorp   = (bool)$user['admincorporazione'];

    // ─── routing azione ──────────────────────────────────────────────────
    $azione = (string)($_REQUEST['azione'] ?? 'list');
    if ($azione!=='list' && ($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST') {
        http_response_code(405); header('Allow: GET, POST'); respond(['success'=>false,'error'=>true,'message'=>'Usare POST per modificare dati']);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') alphano_require_csrf(true);


    if (in_array($azione,['adminorder','admintransf','consegna','prendiincarico'],true)) {
        foreach (['id','prezzo','qta'] as $field) {
            if (isset($_POST[$field]) && CommercioIntero($_POST[$field],1,$field==='qta' ? 10000 : 2147483647)===null) {
                http_response_code(400); respond(['success'=>false,'error'=>true,'message'=>alphano_currency_text('Inserisci quantità e Corone intere nei limiti consentiti')]);
            }
        }
    }

    switch ($azione) {

    /* ====================================================================
     * 0) TRASFERIMENTO DENARO PG → PG
     * ==================================================================*/
    case 'monete':
        $dest   = EconomiaIntero($_POST['dest'] ?? null,1,2147483647) ?? 0;
        $import = EconomiaIntero($_POST['soldi'] ?? null,1,2147483647) ?? 0;
        $motivo = (string)($_POST['motivo'] ?? '');
        if ($dest > 0 && $dest !== $idpg && $import > 0) {
            $ok = TrasferisciSoldi($idpg,$pgname,$saldo,$dest,$import,$motivo,$_POST['_request_key'] ?? null);
            respond(['success'=>$ok,
                     'message'=> $ok ? 'Trasferimento completato con successo'
                                     : 'Errore durante il trasferimento']);
        }
        respond(['success'=>false,'error'=>true,'message'=>'Parametri non esaurienti']);

    /* ====================================================================
     * 0-bis) DONAZIONE A ORGANIZZAZIONE
     * ==================================================================*/
    case 'donazione':
        $org    = EconomiaIntero($_POST['org'] ?? null,1,2147483647) ?? 0;
        $import = EconomiaIntero($_POST['soldi'] ?? null,1,2147483647) ?? 0;
        $motivo = (string)($_POST['motivo'] ?? '');
        if ($org > 0 && $import > 0) {
            $ok = CreaDonazione($idpg,$pgname,$org,$import,$motivo,$_POST['_request_key'] ?? null);
            respond(['success'=>$ok,
                     'message'=> $ok ? 'Donazione andata a buon fine'
                                     : 'Errore durante la donazione']);
        }
        respond(['success'=>false,'error'=>true,'message'=>'Parametri non esaurienti']);

    /* ====================================================================
     * 1) PRENDI IN CARICO (impiegato corp) ORDINE CLIENTE
     * ==================================================================*/
    case 'prendiincarico':
        if (!$isAdminTransf)
            respond(['success'=>false,'error'=>true,'message'=>'Permessi insufficienti']);
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) respond(['success'=>false,'error'=>true,'message'=>'Parametri non esaurienti']);
        $ok  = PrendiInCaricoOrdine($id,$idpg);
        respond(['success'=>$ok,
                 'message'=> $ok ? 'Ordine preso in carico correttamente'
                                 : 'Errore durante la presa in carico']);

    /* ====================================================================
     * 2) ADMIN TRANSFER (verso = 1) – serve permesso corp
     * ==================================================================*/
    case 'admintransf':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            respond(['success'=>false,'error'=>true,'message'=>'Parametri non esaurienti']);
        }

        // carico la riga per sapere chi è il pg proprietario
        $row = $db->query(
            'SELECT pg, pgcorp FROM trasferimenti WHERE id = ?',
            [$id]
        )[0] ?? null;
        $isOwner = $row && ((int)$row['pg'] === $idpg);

        // 1) ACCETTA (sia corp che proprietario)
        if (isset($_POST['accetta'])) {
            if (!$isAdminTransf && !$isOwner) {
                respond(['success'=>false,'error'=>true,'message'=>'Permessi insufficienti']);
            }
            $msg=FinalizeTransf($id,$idpg); respond(['success'=>$msg==='Trasferimento avvenuto correttamente','message'=>$msg]);
        }

        // 2) ELIMINA (corp o pg proprietario)
        if (isset($_POST['elimina'])) {
            if (!$isAdminTransf && !$isOwner) {
                respond(['success'=>false,'error'=>true,'message'=>'Permessi insufficienti']);
            }
            $ok = RejectTransf($id,$idpg);
            respond([
                'success'=>$ok,
                'message'=>$ok
                    ? 'Trasferimento eliminato con successo'
                    : 'Errore nell\'eliminazione'
            ]);
        }

        // 3) CONTRO-PROPOSTA (prezzo) – corp o pg
        if (isset($_POST['prezzo'])
            && is_numeric($_POST['prezzo'])
            && (float)$_POST['prezzo'] > 0
        ) {
            if (!$isAdminTransf && !$isOwner) {
                respond(['success'=>false,'error'=>true,'message'=>'Permessi insufficienti']);
            }
            // se a rispondere è il PG → stato = 3; se la corp → 2
            $newState = $isOwner ? 3 : 4;
            $ok = UpdateTransf(
                $id,
                $idpg,
                (float)$_POST['prezzo'],
                $newState,
                isset($_POST['qta']) ? (int)$_POST['qta'] : null
            );
            respond([
                'success'=>$ok,
                'message'=>$ok
                    ? 'Prezzo aggiornato correttamente'
                    : 'Errore durante l\'operazione'
            ]);
        }

        respond(['success'=>false,'error'=>true,'message'=>'Parametri non esaurienti']);
        break;


    /* ====================================================================
     * 3) ADMIN ORDER (verso = 2)
     * ==================================================================*/
    case 'adminorder':

        // ─ id valido? ───────────────────────────────────────────────────
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            respond(['success'=>false,'error'=>true,'message'=>'Parametri non esaurienti']);
        }

        // ─ l’ordine esiste? ─────────────────────────────────────────────
        $row = $db->query('SELECT pg FROM trasferimenti WHERE id = ?', [$id])[0] ?? null;
        if (!$row) {
            respond(['success'=>false,'error'=>true,'message'=>'Ordine non trovato']);
        }

        // ─ chi sta agendo? (proprietario o impiegato corp) ─────────────
        $isOwner = ((int)$row['pg'] === $idpg);
        if (!$isAdminTransf && !$isOwner) {
            respond(['success'=>false,'error'=>true,'message'=>'Permessi insufficienti']);
        }

        /* --------------------------- OPERAZIONI ------------------------ */

        // accetta l’offerta
        if (isset($_POST['accetta'])) {
            $msg = FinalizeOrdine($id, $idpg);
            respond(['success'=>in_array($msg,['Trasferimento avvenuto correttamente','Ordine accettato con successo'],true),'message'=>$msg]);
        }

        // elimina / annulla
        if (isset($_POST['elimina'])) {
            $ok  = RejectOrdine($id, $idpg);
            $msg = $ok ? 'Ordine eliminato con successo'
                       : 'Errore nell\'eliminazione';
            respond(['success'=>$ok,'message'=>$msg]);
        }

        // contro‐proposta (prezzo + quantità)
        if (
            isset($_POST['prezzo'], $_POST['qta']) &&
            is_numeric($_POST['prezzo']) && (float)$_POST['prezzo'] > 0 &&
            is_numeric($_POST['qta'])    && (int)$_POST['qta']     > 0
        ) {
            // se rilancia il cliente → stato = 2, altrimenti (impiegato) = 3
            $newState = $isOwner ? 2 : 3;

            $ok = UpdateOrdine(
                $id,
                $idpg,
                (float)$_POST['prezzo'],
                (int)$_POST['qta'],
                $newState
            );

            $msg = $ok ? 'Ordine aggiornato correttamente'
                       : 'Errore durante l\'operazione';
            respond(['success'=>$ok,'message'=>$msg]);
        }

        // se nessuna delle condizioni sopra è passata
        respond(['success'=>false,'error'=>true,'message'=>'Parametri non esaurienti']);

    /* ====================================================================
     * 4) CONSEGNA MERCE
     * ==================================================================*/
    case 'consegna':
        if (!$isAdminTransf)
            respond(['success'=>false,'error'=>true,'message'=>'Permessi insufficienti']);
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) respond(['success'=>false,'error'=>true,'message'=>'Parametri non esaurienti']);
        $msg=Consegna($id,$idpg,$corp); respond(['success'=>$msg==='Consegna andata a buon fine','message'=>$msg]);

    /* ====================================================================
     * 5) NASCONDI CRONOLOGIA CORPORATE
     * ==================================================================*/
    case 'clearcrono':
        if (!$isAdminCorp)
            respond(['success'=>false,'error'=>true,'message'=>'Permessi insufficienti']);
        $ids = [];
        foreach ($db->query('SELECT id FROM trasferimenti WHERE corp = ?',[$corp]) as $r)
            if (($_POST[(int)$r['id']] ?? '')==='s') $ids[] = (int)$r['id'];
        if ($ids){
            $ph = implode(',',array_fill(0,count($ids),'?'));
            $db->execute("UPDATE trasferimenti SET nascostocorp = 1
                           WHERE corp = ? AND id IN ($ph)",array_merge([$corp],$ids));
        }
        respond(['success'=>true,'message'=>'Storico nascosto']);

    /* ====================================================================
     * DEFAULT → LISTA COMPLETA
     * ==================================================================*/
    case 'list':
    default:
        ClearTrasferimenti($corp);

        /* 1) trattative verso = 1 (acquisti del PG) */
        $transazioni = [];
        foreach (GetTransazioniPG($idpg) as $r) {
            $pgcorpId   = (int)($r['pgcorp'] ?? 0);
            $pgcorpName =        $r['nomecorp'] ?? null;
            $interloc   = $pgcorpId ? $pgcorpName : $r['interlocutore'];
            $transazioni[] = [
                'id'=> (int)$r['id'],
                'isOwner'=> (int)$r['pg']===$idpg,
                'data'=>FormatTimestamp($r['data']),
                'pg'=> (int)$r['pg'],
                'pgcorp'=> $pgcorpId,
                'pgcorpName'=> $pgcorpName,
                'interlocutore'=> $interloc,
                'nomeobj'=> $r['nomeobj'],
                'obj'=> (int)$r['obj'],
                'qta'=>       (int)$r['qta'],
                'prezzo'=> (float)$r['prezzo'],
                'stato'=> (int)$r['stato'],
                'verso'=> (int)$r['verso'],
                'statoText'=> StatoTransf((int)$r['stato'],(int)$r['verso']),
            ];
        }

        /* 1-b) controproposte della tua corp */
        foreach (GetTransazioniCorp($corp,$idpg) as $r) {
            $pgcorpId   = (int)$r['pg'];
            $transazioni[] = [
                'id'=> (int)$r['id'],
                'isOwner'=> (int)$r['pg']===$idpg,
                'data'=>FormatTimestamp($r['data']),
                'pg'=> (int)$r['pg'],
                'pgcorp'=> $pgcorpId,
                'pgcorpName'=> GetPGFromID($pgcorpId),
                'interlocutore'=> $r['interlocutore'],
                'nomeobj'=> $r['nomeobj'],
                'obj'=> (int)$r['obj'],
                'qta'=> (int)$r['qta'],
                'prezzo'=> (float)$r['prezzo'],
                'stato'=> (int)$r['stato'],
                'verso'=> (int)$r['verso'],
                'statoText'=> StatoTransf((int)$r['stato'],(int)$r['verso']),
            ];
        }

        /* 2) storico oggetti */
        $storicoAcq = [];
        foreach (GetCronologiaPG($idpg) as $r)
            $storicoAcq[] = [
              'id'=>(int)$r['id'],
              'data'=>FormatTimestamp($r['data']),
              'pgcorp'=>(int)$r['pgcorp'],
              'pgcorpName'=>GetPGFromID((int)$r['pgcorp']),
              'interlocutore'=>$r['interlocutore'],
              'nomeobj'=>$r['nomeobj'],
              'qta'=>(int)$r['qta'],
              'verso'=>(int)$r['verso'],
              'corpName'=>$r['nomecorp'],
              'prezzo'=>(float)$r['prezzo'],
              'stato'=>(int)$r['stato'],
              'statoText'=>StatoTransf((int)$r['stato'],(int)$r['verso']),
            ];

        /* 3) cronologia denaro */
        $cronDenaro = [];
        foreach ($db->query(
            "SELECT data,azione,esecutore,destinatario,qta,descr
               FROM logs_adminpg
              WHERE (azione='trasferimento' AND (esecutore=? OR destinatario=?)) OR (azione='donazione' AND esecutore=?)
           ORDER BY data DESC", [$idpg,$idpg,$idpg]) as $r) {

            $other = ((int)$r['esecutore'] === $idpg)
                   ? (int)$r['destinatario']
                   : (int)$r['esecutore'];
            $sign  = ((int)$r['esecutore'] === $idpg) ? -1 : +1;
            $cronDenaro[] = [
              'data'=>FormatTimestamp($r['data']),
              'azione'=>$r['azione'],
              'controparte'=>$r['azione']==='donazione' ? (string)($db->query('SELECT nome FROM organizzazioni WHERE id=?',[(int)$r['destinatario']])[0]['nome'] ?? 'Organizzazione') : getUserName($other),
              'importo'=>$sign*(int)$r['qta'],
              'motivo'=>$r['descr'],
            ];
        }

        /* 4) ordini verso = 2 */
        $ordini = [];
        foreach ($db->query('SELECT t.*,c.nome AS nomecorp,u.nome AS interlocutore FROM trasferimenti t JOIN corporazioni c ON c.id=t.corp LEFT JOIN utenti u ON u.id=t.pgcorp WHERE t.pg=? AND t.verso=2 AND t.stato BETWEEN 0 AND 5 ORDER BY t.data,t.id',[$idpg]) as $r)
            $ordini[] = [
              'id'=>(int)$r['id'],
              'data'=>FormatTimestamp($r['data']),
              'corpName'=>$r['nomecorp'],
              'pgcorp'=>(int)$r['pgcorp'],
              'pgcorpName'=>GetPGFromID((int)$r['pgcorp']),
              'interlocutore'=>$r['interlocutore'],
              'nomeobj'=>$r['nomeobj'],
              'qta'=>(int)$r['qta'],
              'prezzo'=>(float)$r['prezzo'],
              'stato'=>(int)$r['stato'],
              'verso'=>(int)$r['verso'],
              'statoText'=>StatoTransf((int)$r['stato'],(int)$r['verso']),
            ];

        respond([
          'success'=>true,
          'destinatari'=>array_map(static fn($row)=>['id'=>(int)$row['id'],'nome'=>$row['nome']],$db->query('SELECT id,nome FROM utenti WHERE id<>? ORDER BY nome',[$idpg])),
          'organizzazioni'=>array_map(static fn($row)=>['id'=>(int)$row['id'],'nome'=>$row['nome'],'debito'=>(int)$row['debito']],$db->query('SELECT o.id,o.nome,COALESCE(SUM(p.qta),0) AS debito FROM organizzazioni o LEFT JOIN prestitipg p ON p.org=o.id AND p.pg=? WHERE o.approvato=1 GROUP BY o.id,o.nome ORDER BY o.nome',[$idpg])),
          'transazioni'=>$transazioni,
          'storicoAcq'=>$storicoAcq,
          'ordini'=>$ordini,
          'cronologiaDenaro'=>$cronDenaro,
          'saldo'=>(int)($db->query('SELECT denari FROM utenti WHERE id=?',[$idpg])[0]['denari'] ?? 0),
        ]);
    } // fine switch
}
/* ─── catch globale ──────────────────────────────────────────────*/
catch (\Throwable $e) {
    error_log('[trasferimenti] '.$e->getMessage());
    $status=in_array($e->getCode(),[400,409],true) ? $e->getCode() : 500;
    http_response_code($status); respond(['success'=>false,'error'=>true,'message'=>$status<500 ? $e->getMessage() : 'Impossibile completare l’operazione. Riprova.']);
}
