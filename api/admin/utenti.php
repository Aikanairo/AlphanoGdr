<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// bootstrap
require_once __DIR__ . '/../../libs/kernel.inc.php';
require_once __DIR__ . '/../../libs/libadmin.inc.php';
require_once __DIR__ . '/../../libs/libpg.inc.php';
require_once __DIR__ . '/../../libs/libgrafica.inc.php';
require_once __DIR__ . '/../../libs/sendmail.inc.php';
require_once __DIR__.'/../../libs/lineages.inc.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// identificazione utente
$idpg = alphano_require_login(true);
$db   = ConnectDB();

// permessi
$user = $db->query(
    "SELECT admin, adminstatosociale
       FROM utenti
      WHERE id = :id",
    ['id' => $idpg]
)[0] ?? null;

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Utente non autenticato']);
    exit;
}

$admin             = (bool)$user['admin'];
$adminstatosociale = (bool)$user['adminstatosociale'];

if ($admin) {
    EnsureAdmin();
} elseif (!$adminstatosociale) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accesso negato']);
    exit;
}

$maintenance = [];
$etnie = $admin ? $db->query('SELECT id,nome FROM etnie') : [];
$razze = $admin ? $db->query('SELECT id,nome FROM razze') : [];

// lettura input JSON/form
$data = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    try { $data = alphano_request_json(); }
    catch (Throwable) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'JSON non valido']); exit; }
}
foreach($data as $key=>$value)if($key!=='changes'&&(is_array($value)||is_object($value))){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Parametro non valido']);exit;}
if(!is_string($data['azione']??'none')){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Azione non valida']);exit;}
$action = $data['azione'] ?? 'none';
$readonly = ['incarichi','none','listarazze','stirpe','visualizzaflag','doppipg','mostrastatosociale','mostraemailreg'];
if (!$admin && !in_array($action, ['none','statosociale','mostrastatosociale'], true)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Privilegi amministrativi richiesti']); exit;
}
if (!in_array($action, $readonly, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    alphano_require_csrf(true);
}


$response = ['success' => false];

// switch sulle azioni (come prima, ma con PDO)
try {
    $transaction = !in_array($action, array_merge($readonly,['dump','ottimizza']), true);
    if ($transaction) $db->beginTransaction();
    $targets = ['profile','cambiapass','cancella','cancellaposta','cambianome','setflag','statosociale','emailreg'];
    if (in_array($action,$targets,true) && !GetPGID((string)($data['nome'] ?? ''))) throw new InvalidArgumentException('Personaggio non trovato');
    switch ($action) {
        case 'incarichi':
            require_once __DIR__.'/../../libs/admin-governance.inc.php';
            $name=$data['nome']??'';if(!is_string($name)||mb_strlen($name)>100)throw new DomainException('Nome non valido.',422);
            $result=['catalog'=>alphano_governance_catalog()];
            if($name!==''){$target=$db->query('SELECT * FROM utenti WHERE nome=?',[$name])[0]??null;if(!$target)throw new DomainException('Personaggio non trovato.',404);$result['person']=['id'=>(int)$target['id'],'name'=>$target['nome'],'values'=>alphano_governance_state($target),'revision'=>alphano_governance_revision($target)];}
            $result['history']=alphano_governance_history((int)($target['id']??0));
            $response=['success'=>true,'data'=>$result];
            break;
        case 'salvaincarichi':
            require_once __DIR__.'/../../libs/admin-governance.inc.php';
            $target=filter_var($data['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$target||!is_array($data['changes']??null)||!is_string($data['revision']??null)||!is_string($data['reason']??null))throw new DomainException('Dati degli incarichi incompleti.',422);
            $result=alphano_governance_apply($idpg,$target,$data['changes'],$data['reason'],$data['revision']);
            $response=['success'=>true,'data'=>['person'=>$result,'history'=>alphano_governance_history($target),'issues'=>alphano_governance_issues()],'message'=>'Incarichi e permessi salvati e registrati.'];
            break;

        case 'stirpe':
            $name=$data['nome']??'';$race=filter_var($data['razza']??null,FILTER_VALIDATE_INT);
            if(!is_string($name)||!in_array($race,[2,3,5,6],true))throw new DomainException('Personaggio o razza non validi.',422);
            $target=$db->query('SELECT * FROM utenti WHERE nome=?',[$name])[0]??null;
            if(!$target)throw new DomainException('Personaggio non trovato.',404);
            $response=['success'=>true,'plan'=>lineage_admin_plan($target,$race)];
            break;

        // il frontend prima carica lookup: etnie+razze
        case 'none':
        case 'listarazze':
            // non serve altro, etnie/razze le mando in output
            $response = ['success'=>true];
            break;

        // — azioni “bana”: reset pos, visite, ottimizza, dump
        case 'resetpos':
            $db->execute("UPDATE utenti SET chat='', posizione='off'");
            $response = ['success'=>true,'message'=>'Tutti i pg sono stati messi offline'];
            break;

        case 'resetcount':
            $db->execute("UPDATE utenti SET visite=0");
            $response = ['success'=>true,'message'=>'Conteggio visite azzerato'];
            break;

        case 'ottimizza':
            $msg = OptimizeAll();
            $response = ['success'=>true,'message'=>$msg];
            break;

        case 'dump':
            $db->execute("INSERT INTO logs_adminpg(data,esecutore,destinatario,azione,descr,chat) VALUES(?,?,0,'admin-utenti','Esportazione database richiesta','')",[time(),$idpg]);
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="alphano-' . date('Ymd-His') . '.sql"');
            echo AlphanoDatabaseDump();
            exit;

        case 'manutenzione':

    //
    // 1) utenti mai entrati (>7 giorni)
    //
    $timer = GeneratePastTimestamp(7);
    $rows  = $db->query(
        "SELECT nome,email
           FROM utenti
          WHERE ultimoingresso = 0
            AND dataregistrazione <= :timer
            AND admin <> 1",
        ['timer' => $timer]
    );
    $emailList = [];
    foreach ($rows as $r) {
        $emailList[] = $r;
    }
    $cnt = $db->execute(
        "DELETE FROM utenti
          WHERE ultimoingresso = 0
            AND dataregistrazione <= :timer
            AND admin <> 1",
        ['timer' => $timer]
    );
    if ($cnt > 0) {
        foreach ($emailList as $notice) SendDelAdvice($notice);
        $maintenance[] = "Sono stati eliminati {$cnt} PG che si sono registrati ma non sono mai entrati";
    }

    //
    // 2) utenti inattivi (>60 giorni) con backup
    //
    $timer1 = GeneratePastTimestamp(60);
    $timer2 = GeneratePastTimestamp(90);
    $rows   = $db->query(
        "SELECT *
           FROM utenti
          WHERE ((ultimoingresso <= :t1 AND vacanza <> '1') OR ultimoingresso <= :t2)
            AND ultimoingresso > 0
            AND admin <> 1",
        ['t1' => $timer1, 't2' => $timer2]
    );
    $emailAdv = [];
    foreach ($rows as $r) {
        $uid       = $r['id'];
        $emailAdv[] = ['id' => $uid, 'nome' => $r['nome'], 'email' => $r['email'], 'ultimoingresso' => $r['ultimoingresso']];

        // Dump e recupero condividono la stessa serializzazione SQL senza perdere NULL.
        $backup = [AlphanoRowInsert('utenti',$r)];
        foreach (['equippg','armadio'] as $table) {
            foreach ($db->query("SELECT * FROM $table WHERE idpg=?",[$uid]) as $item) {
                $backup[] = AlphanoRowInsert($table,$item);
            }
        }
        // salvo in recuperopg
        $db->execute(
            "REPLACE INTO recuperopg (id, data, query) VALUES (:id, :data, :qry)",
            ['id' => $uid, 'data' => time(), 'qry' => serialize($backup)]
        );
    }
    $cnt2 = $db->execute(
        "DELETE FROM utenti
          WHERE ((ultimoingresso <= :t1 AND vacanza <> '1') OR ultimoingresso <= :t2)
            AND ultimoingresso > 0
            AND admin <> 1",
        ['t1' => $timer1, 't2' => $timer2]
    );
    if ($cnt2 > 0) {
        foreach ($emailAdv as $notice) SendDelAdvice($notice);
        $maintenance[] = "Sono stati eliminati {$cnt2} PG che non entravano da più di 60 giorni";
    }

    //
    // 3) pulisco backup >7 giorni
    //
    $timer = GeneratePastTimestamp(7);
    $c     = $db->execute("DELETE FROM recuperopg WHERE data <= :tm", ['tm' => $timer]);
    if ($c > 0) {
        $maintenance[] = "Sono stati eliminati {$c} backup di PG cancellati";
    }

    //
    // 4) svuoto la posta di utenti inattivi >30 giorni
    //
    $timer = GeneratePastTimestamp(30);
    $rows  = $db->query(
        "SELECT id
           FROM utenti
          WHERE ultimoingresso <= :timer
            AND admin <> 1
            AND vacanza <> '1'",
        ['timer' => $timer]
    );
    $ids = array_map(fn($r) => $r['id'], $rows);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $c  = $db->execute("DELETE FROM mail WHERE iddestinatario IN ($ph)", $ids);
        if ($c > 0) {
            $maintenance[] = "Sono state cancellate {$c} missive appartenenti a PG che non entravano da più di 1 mese";
        }
    }

    //
    // 5) integrità referenziale utenti → candidati, elezioni, richieste
    //
    $rows = $db->query("SELECT id FROM utenti", []);
    $uids = array_map(fn($r) => $r['id'], $rows);
    if ($uids) {
        $ph     = implode(',', array_fill(0, count($uids), '?'));
        $c1     = $db->execute("DELETE FROM candidati WHERE idpg NOT IN ($ph)", $uids);
        $c2     = $db->execute(
            "DELETE FROM elezioni WHERE idcandidato NOT IN ($ph) OR idvotante NOT IN ($ph)",
            array_merge($uids, $uids)
        );
        $c3     = $db->execute("DELETE FROM richieste WHERE idpg NOT IN ($ph)", $uids);
        if ($c1 > 0) $maintenance[] = "Sono state cancellate {$c1} candidature alle elezioni che appartenevano a pg cancellati";
        if ($c2 > 0) $maintenance[] = "Sono state cancellate {$c2} votazioni alle elezioni che interessavano pg cancellati";
        if ($c3 > 0) $maintenance[] = "Sono state cancellate {$c3} richieste di accesso a organizzazioni o corporazioni effettuate da pg cancellati";
    }

    //
    // 6) pulizia equipaggiamenti orfani
    //
    if ($uids) {
        $ph  = implode(',', array_fill(0, count($uids), '?'));
        $d1  = $db->execute("DELETE FROM equippg WHERE idpg NOT IN ($ph)", $uids);
        $d2  = $db->execute("DELETE FROM armadio WHERE idpg NOT IN ($ph)", $uids);
        $sum = $d1 + $d2;
        if ($sum > 0) {
            $maintenance[] = "Sono stati cancellati {$sum} oggetti che appartenevano a pg cancellati";
        }
    }

    //
    // 7) cleanup “equip” invisibili
    //
    $rows = $db->query("SELECT id FROM equip WHERE visibile = 0", []);
    $del  = array_map(fn($r) => $r['id'], $rows);
    if ($del) {
        // rimuovo quelli ancora presenti in equippg/armadio
        $ph    = implode(',', array_fill(0, count($del), '?'));
        $inEq  = $db->query("SELECT DISTINCT(uid) AS id FROM equippg WHERE uid IN ($ph)", $del);
        $inArm = $db->query("SELECT DISTINCT(uid) AS id FROM armadio WHERE uid IN ($ph)", $del);
        $keep  = array_unique(array_merge(
            array_map(fn($r) => $r['id'], $inEq),
            array_map(fn($r) => $r['id'], $inArm)
        ));
        $toDel = array_diff($del, $keep);
        foreach ($toDel as $eid) {
            @unlink(htmlroot . "/uploads/equipment/{$eid}");
            @unlink(htmlroot . "/uploads/equipment-pending/{$eid}");
        }
        if ($toDel) {
            $ph2 = implode(',', array_fill(0, count($toDel), '?'));
            $c   = $db->execute("DELETE FROM equip WHERE visibile = 0 AND id IN ($ph2)", array_values($toDel));
            if ($c > 0) {
                $maintenance[] = "Sono stati cancellati {$c} oggetti cancellati dai cataloghi che nessuno possedeva";
            }
        }
        // rimuovo equips orfani
        $all = $db->query("SELECT id FROM equip", []);
        $all = array_map(fn($r) => $r['id'], $all);
        if ($all) {
            $ph3  = implode(',', array_fill(0, count($all), '?'));
            $cEq  = $db->execute("DELETE FROM equippg WHERE uid NOT IN ($ph3)", $all);
            $cAr  = $db->execute("DELETE FROM armadio WHERE uid NOT IN ($ph3)", $all);
            $sum2 = $cEq + $cAr;
            if ($sum2 > 0) {
                $maintenance[] = "Sono stati cancellati {$sum2} oggetti dagli equipaggiamenti dei pg poichè non più presenti nel database";
            }
        }
    }

    //
    // 8) posta orfana
    //
    if ($uids) {
        $ph = implode(',', array_fill(0, count($uids), '?'));
        $c  = $db->execute("DELETE FROM mail WHERE iddestinatario NOT IN ($ph)", $uids);
        if ($c > 0) {
            $maintenance[] = "Sono state cancellate {$c} missive appartenevano a pg cancellati";
        }
    }

    //
    // 9) messaggi bacheche orfani
    //
    $roots = $db->query("SELECT id FROM bacheche WHERE replyid = 0", []);
    $rids  = array_map(fn($r) => $r['id'], $roots);
    if ($rids) {
        $ph = implode(',', array_fill(0, count($rids), '?'));
        $c  = $db->execute("DELETE FROM bacheche WHERE replyid NOT IN (0,$ph)", $rids);
        if ($c > 0) {
            $maintenance[] = "Sono stati cancellati {$c} post senza relativo thread";
        }
    }

    //
    // 10) pulizia per ciascuna “bacheca” in luoghi clear=1
    //
    $places = $db->query("SELECT id,nome FROM luoghi WHERE clear = '1'", []);
    $cutoff = time() - 1209600; // 2 settimane
    foreach ($places as $pl) {
        $bc      = $pl['id'];
        $bname   = $pl['nome'];
        $cntLoc  = 0;
        $reps    = $db->query(
            "SELECT replyid, MAX(data) AS lastreply
               FROM bacheche
              WHERE replyid > 0
                AND bacheca = :bc
           GROUP BY replyid",
            ['bc' => $bc]
        );
        $replyIds = [];
        $oldIds   = [];
        foreach ($reps as $rp) {
            $replyIds[] = $rp['replyid'];
            if ($cutoff >= $rp['lastreply']) {
                $oldIds[] = $rp['replyid'];
            }
        }
        if ($oldIds) {
            $ph1     = implode(',', array_fill(0, count($oldIds), '?'));
            $cntLoc += $db->execute(
                "DELETE FROM bacheche
                  WHERE (id IN ($ph1) OR replyid IN ($ph1))
                    AND bacheca = {$bc}",
                array_merge($oldIds, $oldIds)
            );
        }
        if ($replyIds) {
            $ph2     = implode(',', array_fill(0, count($replyIds), '?'));
            $cntLoc += $db->execute(
                "DELETE FROM bacheche
                  WHERE id NOT IN ($ph2)
                    AND replyid = 0
                    AND data < {$cutoff}
                    AND bacheca = {$bc}",
                $replyIds
            );
        }
        if ($cntLoc > 0) {
            $maintenance[] = "Sono stati cancellati {$cntLoc} messaggi più vecchi di due settimane dalla bacheca {$bname}";
        }
    }

    //
    // 11) correzione araldica non valide
    //
    $ars   = $db->query("SELECT id FROM araldiche", []);
    $arIds = array_map(fn($r) => $r['id'], $ars);
    if ($arIds) {
        $ph = implode(',', array_fill(0, count($arIds), '?'));
        $c  = $db->execute("UPDATE utenti SET araldica = '1' WHERE araldica NOT IN ($ph)", $arIds);
        if ($c > 0) {
            $maintenance[] = "Sono state trovate e corrette {$c} araldiche inesistenti assegnate a pg";
        }
    }

    //
    // 12) correzione organizzazioni non valide
    //
    $orgs   = $db->query("SELECT id FROM organizzazioni", []);
    $orgIds = array_map(fn($r) => $r['id'], $orgs);
    if ($orgIds) {
        $ph = implode(',', array_fill(0, count($orgIds), '?'));
        $c  = $db->execute(
            "UPDATE utenti
                SET organizzazione = '0', caricaorganizzazione = '0'
              WHERE organizzazione NOT IN ($ph)",
            $orgIds
        );
        if ($c > 0) {
            $maintenance[] = "Sono state trovate e corrette {$c} organizzazioni inesistenti assegnate a pg";
        }
    }

    //
    // 13) correzione corporazioni non valide
    //
    $corps   = $db->query("SELECT id FROM corporazioni", []);
    $corpIds = array_map(fn($r) => $r['id'], $corps);
    if ($corpIds) {
        $ph  = implode(',', array_fill(0, count($corpIds), '?'));
        $c1  = $db->execute(
            "UPDATE utenti
                SET corporazione = '0', caricacorporazione = '0'
              WHERE corporazione NOT IN ($ph)",
            $corpIds
        );
        if ($c1 > 0) {
            $maintenance[] = "Sono state trovate e corrette {$c1} corporazioni inesistenti assegnate a pg";
        }
        $c2 = $db->execute(
            "DELETE FROM produzionicorporazioni WHERE corporazione NOT IN ($ph)",
            $corpIds
        );
        if ($c2 > 0) {
            $maintenance[] = "Sono state cancellate {$c2} produzioni di corporazioni inesistenti";
        }
    }

    //
    // 14) cancellazione log >60 giorni, statistiche >2 giorni, visite <oggi
    //
    $timerL = GeneratePastTimestamp(60);
    $logs   = [
        'logs_adminpg','logs_deletemail','logs_deleteuser','logs_dimore',
        'logs_equip','logs_flag','logs_ingressi','logs_meteo',
        'logs_profili','logs_registrazione'
    ];
    $cntLog = 0;
    foreach ($logs as $tbl) {
        $cntLog += $db->execute("DELETE FROM {$tbl} WHERE data < ?", [$timerL]);
    }
    $cntLog += $db->execute("DELETE FROM logs_statistica WHERE data < ?", [GeneratePastTimestamp(2)]);
    $cntLog += $db->execute("DELETE FROM logs_visite WHERE data < ?", [GeneratePastTimestamp(0)]);
    if ($cntLog > 0) {
        $maintenance[] = "Sono state cancellate {$cntLog} righe di log considerate obsolete";
    }

    //
    // 15) transazioni nascoste >30 giorni
    //
    $ti = GeneratePastTimestamp(30);
    $c  = $db->execute(
        "DELETE FROM trasferimenti
          WHERE data < ?
            AND nascostopg = '1'
            AND nascostocorp = '1'",
        [$ti]
    );
    if ($c > 0) {
        $maintenance[] = "Sono state cancellate {$c} transazioni nascoste più vecchie di 30 giorni";
    }

    //
    // 16) richieste ammissione >14 giorni
    //

    $requestsBefore = GeneratePastTimestamp(14);
    $c  = $db->execute("DELETE FROM richieste WHERE data < ?", [$requestsBefore]);
    if ($c > 0) {
        $maintenance[] = "Sono state cancellate {$c} richieste di ammissione più vecchie di 14 giorni";
    }


            $response = ['success'=>true,'message'=>implode("\n",$maintenance) ?: 'Nessun archivio scaduto'];
            break;

        // — profilo
        case 'profile':
            $creatorRaw=$data['creator_id']??null;$creator=null;
            if($creatorRaw!==null&&$creatorRaw!==''){$creator=filter_var($creatorRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($creator===false)throw new DomainException('Creatore non valido.',422);}
            if(alphano_config('lineages_enabled')&&in_array((string)($data['razza']??''),['2','3'],true)){
                lineage_policy_lock((string)$data['razza']==='2'?'vampire':'lycan');
                $target=$db->query('SELECT * FROM utenti WHERE nome=?',[(string)($data['nome']??'')])[0];
                if(lineage_admin_plan($target,(int)$data['razza'])['requires_creator']&&$creator===null)throw new DomainException('Chi lo sta generando? Seleziona un creatore.',422);
            }
            $msg = SetProfile(
                $idpg,
                (string) ($data['nome']           ?? ''),
                (string) ($data['sesso']          ?? ''),
                (string) ($data['etnia']          ?? ''),  // ID etnia come stringa
                (string) ($data['allineamento']   ?? ''),
                (string) ($data['razza']          ?? ''),
                $creator
            );
            $response = ['success' => true, 'message' => $msg];
            break;

        // — cambio password
        case 'cambiapass':
            $msg = SetPass(
                $idpg,
                (string)($data['nome'] ?? ''),
                (string)($data['pass'] ?? '')
            );
            $response = ['success'=>true,'message'=>$msg];
            break;

        // — cancella utente
        case 'cancella':
            $response = [
                'success'=>true,
                'message'=>DelUser(
                    $idpg,
                    (string)($data['nome'] ?? ''),
                    !empty($data['profilo']),
                    !empty($data['equip']),
                    !empty($data['posta']),
                    !empty($data['bacheche'])
                )
            ];
            break;

        // — cancella posta
        case 'cancellaposta':
            $response = [
                'success'=>true,
                'message'=>DelPosta(
                    $idpg,
                    (string)($data['nome'] ?? ''),
                    (string)($data['invio'] ?? 'ric'),
                    (string)($data['baule'] ?? 'no'),
                    (string)($data['letta'] ?? 'si')
                )
            ];
            break;

        // — rinomina
        case 'cambianome':
            $old = (string)($data['nome'] ?? '');
            $new = (string)($data['nuovonome'] ?? '');

            // 1) rinomina su DB
            $msg = (strtoupper($old)===strtoupper($new) || ! GetPGID($new))
                ? AdminSetNome($idpg, $old, $new)
                : "Il nome {$new} è già in uso";

            // 2) se ho appena rinominato me stesso, aggiorno la sessione
            if (isset($_SESSION['username']) && $_SESSION['username'] === $old) {
                $_SESSION['username'] = $new;
                session_regenerate_id(true);
            }

            $response = ['success'=>true,'message'=>$msg];
            break;

        // — visualizza flag
        case 'visualizzaflag':
            $d = $db->query(
                "SELECT fatepower, adminferite, adminmorti, adminstatosociale, bloccato,
                        adminaccademia, adminorganizzazione, admincorporazione,
                        admincatalogo, admintrasfcorp, sindaco, upload, vacanza
                   FROM utenti
                  WHERE nome = :nome",
                ['nome'=> (string)($data['nome'] ?? '')]
            )[0] ?? [];
            $response = ['success'=>true,'data'=>$d];
            break;

        // — setflag
        case 'setflag':
            $f = $data;
            $msg = SetFlag(
                $idpg,
                (string)($f['nome'] ?? ''),
                (int)!empty($f['master']),
                (int)!empty($f['guaritore']),
                (int)!empty($f['resurrettore']),
                (int)!empty($f['statosociale']),
                (int)!empty($f['bloccato']),
                (int)!empty($f['accademia']),
                (int)!empty($f['adminorg']),
                (int)!empty($f['admincorp']),
                (int)!empty($f['catalogo']),
                (int)!empty($f['admintrasfcorp']),
                (int)!empty($f['sindaco']),
                (int)!empty($f['upload']),
                (int)!empty($f['vacanza'])
            );

            $response = ['success'=>true,'message'=>$msg];
            break;

        // — trova doppi pg
        case 'doppipg':
            $ids = TrovaDoppi(
                (int)GetPGID((string)($data['nome'] ?? '')),
                !empty($data['trasferimenti']),
                !empty($data['ricompense']),
                !empty($data['concatena'])
            );
            if (!$ids) {
                $response = ['success'=>false,'message'=>'Nessun doppio trovato'];
            } else {
                $ph   = implode("','",$ids);
                $rows = $db->query("SELECT nome FROM utenti WHERE id IN ('$ph')");
                $dups = array_map(fn($r) => $r['nome'], $rows);
                $response = ['success'=>true,'duplicates'=>$dups];
            }
            break;

        // — cambia stato sociale
        case 'statosociale':
            if (trim((string)($data['stato'] ?? '')) === '' || mb_strlen((string)$data['stato'])>60) throw new InvalidArgumentException('Stato sociale non valido');
            if (!($admin||$adminstatosociale)) {
                throw new RuntimeException('Non autorizzato');
            }
            $db->execute(
                "UPDATE utenti SET statosociale=:stato WHERE nome=:nome",
                [
                    'stato'=> (string)($data['stato'] ?? ''),
                    'nome' => (string)($data['nome'] ?? '')
                ]
            );
            $response = [
                'success'=>true,
                'message'=> "{$data['nome']} ora è {$data['stato']}"
            ];
            break;

        // — mostra stato sociale
        case 'mostrastatosociale':
            $row = $db->query(
                "SELECT statosociale FROM utenti WHERE nome=:nome",
                ['nome'=> (string)($data['nome'] ?? '')]
            )[0] ?? [];
            $response = ['success'=>true,'statosociale'=>$row['statosociale'] ?? ''];
            break;

        // — cambia email registrazione
        case 'emailreg':
            if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email non valida');
            $db->execute(
                "UPDATE utenti SET email=:email WHERE nome=:nome",
                [
                    'email'=> (string)($data['email'] ?? ''),
                    'nome' => (string)($data['nome'] ?? '')
                ]
            );
            $response = ['success'=>true,'message'=>'Email registrazione aggiornata'];
            break;

        // — mostra email registr.
        case 'mostraemailreg':
            $row = $db->query(
                "SELECT email FROM utenti WHERE nome=:nome",
                ['nome'=> (string)($data['nome'] ?? '')]
            )[0] ?? [];
            $response = ['success'=>true,'email'=>$row['email'] ?? ''];
            break;

        default:
            http_response_code(400);
            $response = ['success'=>false,'message'=>"Azione '{$action}' non valida"];
            break;
    }
    if(!empty($response['success'])&&!in_array($action,array_merge($readonly,['setflag','salvaincarichi']),true))$db->execute("INSERT INTO logs_adminpg(data,esecutore,destinatario,azione,descr,chat) VALUES(?,?,?,'admin-utenti',?,'')",[time(),$idpg,(int)GetPGID(is_string($data['nome']??null)?$data['nome']:''),'Operazione amministrativa: '.$action]);
    if ($transaction) $db->commit();

} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if($action==='salvaincarichi'&&$admin){try{$db->execute("INSERT INTO logs_adminpg(data,esecutore,destinatario,azione,descr,chat) VALUES(?,?,?,'incarichi-rifiutati',?,'')",[time(),$idpg,(int)(filter_var($data['id']??0,FILTER_VALIDATE_INT)?:0),mb_substr($e instanceof DomainException?$e->getMessage():'Operazione non completata',0,255)]);}catch(Throwable $logError){error_log('[audit incarichi] '.$logError->getMessage());}}
    $domain=$e instanceof DomainException&&$e->getCode()>=400&&$e->getCode()<500;
    http_response_code($domain?$e->getCode():($e instanceof InvalidArgumentException?400:500));
    error_log('[admin utenti] '.$e->getMessage());
    $response = ['success'=>false,'message'=>($domain||$e instanceof InvalidArgumentException)?$e->getMessage():'Operazione non completata'];
}

// output finale
echo json_encode([
    'success'   => (bool)($response['success'] ?? false),
    'etnie'     => $etnie,
    'razze'     => $razze,
    'response'  => $response,
    'maintenance' => $maintenance,
], JSON_UNESCAPED_UNICODE);
