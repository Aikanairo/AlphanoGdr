<?php
declare(strict_types=1);

foreach (array_merge($_GET,$_POST) as $value) {
    if (is_array($value) || is_object($value)) {
        http_response_code(400);
        echo 'Parametri non validi';
        exit;
    }
}

/********************************************************
 * admin.inc.php
 * Backend: inizializza sessione, carica dati, gestisce POST
 ********************************************************/

require_once __DIR__ . '/../libs/kernel.inc.php';       // initSession(), ClosePage()
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') alphano_require_csrf();
require_once __DIR__ . '/../libs/libpg.inc.php';       // GetIDPG()
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libeconomia.inc.php';
require_once __DIR__ . '/../libs/group-admin.inc.php'; // funzioni economiche
require_once __DIR__ . '/../libs/libposta.inc.php';    // funzioni posta
require_once __DIR__ . '/../libs/libelezioni.inc.php'; // ElectionTime(), VoteTime()
require_once __DIR__ . '/../libs/libtext.inc.php';     // ProcessText()
require_once __DIR__ . '/../libs/libaccademia.inc.php';// funzioni accademia
require_once __DIR__ . '/../chat/libs/chat-core.php';  // ConnectDB(), InsertIntoChat()



initSession();
$idpg = alphano_require_login();

$db      = ConnectDB();

$msg     = '';


if (!isset($activeTab)) {
    $activeTab = 'iscritti';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['action'] ?? '') {
        // ─── Tab Iscritti ───────────────────────────────
        case 'admitpg':
        case 'addramo':
        case 'modifyramo':
        case 'dropramo':
        case 'addcarica':
        case 'modifycarica':
        case 'dropcarica':
        case 'changecarica':
            $activeTab = 'iscritti';
            break;

        // ─── Tab Finanze ────────────────────────────────
        case 'prendiincarico':
        case 'adminprestito':
            $activeTab = 'finanze';
            break;

        // ─── Tab Prestiti a PG ─────────────────────────
        case 'prestito':
            $activeTab = 'prestiti';
            break;

        // ─── Tab Privilegi ──────────────────────────────
        case 'visualizzaflag':
        case 'setflag':
            $activeTab = 'flag';
            break;

        // ─── Tab Elezioni ───────────────────────────────
        case 'proponi':
            $activeTab = 'elezioni';
            break;

        // ─── Tab Interna (PVT) ─────────────────────────
        case 'modifypvt':
            $activeTab = 'pvt';
            break;
    }
}

// General administrators select context without changing their own membership.
$actor=alphano_user();$requested=$_POST['org']??$_GET['org']??null;
if($requested===null&&!empty($actor['admin'])&&empty($actor['organizzazione'])&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){header('Location: /organizzazioni/');exit;}
$selectedOrg=$requested===null?(int)$actor['organizzazione']:filter_var($requested,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$selectedOrg|| (empty($actor['admin'])&&($selectedOrg!==(int)$actor['organizzazione']||empty($actor['adminorganizzazione'])))){http_response_code(403);exit('Seleziona un’organizzazione che puoi amministrare.');}
$userRow = $db->query(
    'SELECT
        o.id AS organizzazione,
        u.adminorganizzazione,
        u.corporazione,
        o.sito,
        o.city,
        o.pvt,
        o.sfondo,
        o.sfondotop,
        o.mappa,
        o.descrizione,
        o.budget,
        o.nome AS nomeorganizzazione,
        o.resurgo,
        o.cerimonia,
        o.ferite
     FROM utenti AS u
     JOIN organizzazioni AS o ON o.id = ?
     WHERE u.id = ?',
    [$selectedOrg,$idpg]
)[0] ?? null;


if(!$userRow){http_response_code(404);exit('Organizzazione non trovata.');}
$org=(int)$userRow['organizzazione'];
$corp        = (int)$userRow['corporazione'];
$budget      = (int)$userRow['budget'];
$nomeorg     = $userRow['nomeorganizzazione'];
$resurgo     = (bool)$userRow['resurgo'];
$cerimonia   = (bool)$userRow['cerimonia'];
$ferite      = (bool)$userRow['ferite'];
$sito        = $userRow['sito'];
$cityorg     = $userRow['city'];
$pvt         = base64_decode($userRow['pvt']);
$sfondo      = $userRow['sfondo'];
$sfondotop   = $userRow['sfondotop'];
$mappa       = $userRow['mappa'];
$descrizione = $userRow['descrizione'];

// Le immagini seguono lo storage configurato, anche nei cloni di verifica.
$imgDir=htmlroot.'/uploads/badges/organizzazioni/'.$org;
if (!is_dir($imgDir) && !@mkdir($imgDir,0755,true) && !is_dir($imgDir)) $msg.='Impossibile creare la cartella immagini';

// Lista città
$listacity = [];
foreach (GetListaCity() as $r) {
    $listacity[$r['id']] = $r;
}

// Elezioni attive
$electiontime = false;
$votetime     = false;
$pgcity       = [];
foreach ($listacity as $cid => $cdata) {
    $inElection = ElectionTime($cid);
    $inVoting   = VoteTime($cid);
    if ($cdata['elezioni'] && ($inElection || $inVoting)) {
        if ($inElection) $electiontime = true;
        if ($inVoting)   $votetime     = true;
        $rows = $db->query(
            'SELECT u.id, u.nome
               FROM utenti u
               JOIN araldiche a ON a.id = u.araldica
              WHERE u.organizzazione = ? AND a.city = ?',
            [$org, $cid]
        );
        foreach ($rows as $r) {
            $pgcity[$cid][$r['id']] = $r;
        }
    }
}

// Liste per form
$datiflag = [];
// Dati iniziali per template
$requests    = GetRichiesteOrg($org);
$prestitiOrg = ListaPrestitiOrg($org);
$membersOrg  = GetPGInOrg($org);
$prestitiPG  = $db->query(
    'SELECT p.data,p.qta,u.nome,u.id
       FROM prestitipg p
       JOIN utenti u ON u.id = p.pg
      WHERE p.org = ?',
    [$org]
);



// Gestione POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $target=(int)($_POST['pg'] ?? $_POST['nome'] ?? 0);
    if (in_array($action,['changecarica','setflag','visualizzaflag','prestito'],true) && !$db->query('SELECT 1 FROM utenti WHERE id=? AND organizzazione=?',[$target,$org])) {
        http_response_code(403); exit('Il personaggio non appartiene alla tua organizzazione');
    }
    if (in_array($action,['modifycarica','dropcarica'],true) && !$db->query('SELECT 1 FROM caricheorganizzazioni WHERE id=? AND organizzazione=?',[(int)($_POST['carica'] ?? 0),$org])) {
        http_response_code(403); exit('Carica non appartenente alla tua organizzazione');
    }
    if (in_array($action,['addcarica','modifycarica'],true) && !$db->query('SELECT 1 FROM ramiorganizzazioni WHERE id=? AND organizzazione=?',[(int)($_POST['ramo'] ?? 0),$org])) {
        http_response_code(403); exit('Ramo non appartenente alla tua organizzazione');
    }
    $groupActionResult=alphano_group_admin_action('organizzazioni',$org,$idpg,(string)$action,$_POST,$_FILES);
    if ($groupActionResult!==null) $msg=$groupActionResult;
    else switch ($action) {
        case 'admitpg':
            $pg    = (int)($_POST['pg'] ?? 0);
            $admit = isset($_POST['admit']);
            $last  = GetLastCaricaOrg($org);
            if ($pg && $last !== null) {
                $ok  = AdmitPGOrg($pg, $org, $last, $admit);
                $msg = $ok
                    ? ($admit
                        ? GetPGFromID($pg) . " ammesso nell'Organizzazione"
                        : GetPGFromID($pg) . " respinto con successo")
                    : ($admit
                        ? "Errore nell'ammissione di " . GetPGFromID($pg)
                        : "Errore nella respinsione di " . GetPGFromID($pg));
            } else {
                $msg = $last === null
                    ? 'Prima carica non impostata'
                    : 'Parametri non esaurienti';
            }
            break;

        case 'addramo':
            $nome = trim($_POST['nome'] ?? '');
            $liv  = (int)($_POST['livello'] ?? -1);
            if ($nome !== '' && $liv >= 0) {
                $ok  = $db->execute(
                    'INSERT INTO ramiorganizzazioni (nome, organizzazione, livello) VALUES (?, ?, ?)',
                    [$nome, $org, $liv]
                );
                $msg = $ok
                    ? 'Nuovo ramo inserito correttamente'
                    : 'Errore nell\'inserimento del nuovo ramo';
            } else {
                $msg = 'Parametri non esaurienti';
            }
            break;

        case 'modifyramo':
            $ramo = (int)($_POST['ramo'] ?? 0);
            $nome = trim($_POST['nome'] ?? '');
            $liv  = (int)($_POST['livello'] ?? -1);
            if ($ramo && $nome !== '' && $liv >= 0) {
                $ok  = $db->execute(
                    'UPDATE ramiorganizzazioni SET nome = ?, livello = ? WHERE id = ? AND organizzazione = ?',
                    [$nome, $liv, $ramo, $org]
                );
                $msg = $ok
                    ? 'Ramo modificato con successo'
                    : 'Errore nella modifica del ramo';
            } else {
                $msg = 'Parametri non esaurienti';
            }
            break;

        case 'dropramo':
            $ramo = (int)($_POST['ramo'] ?? 0);
            if ($ramo) {
                $ok  = DropRamoOrg($org, $ramo);
                $msg = $ok
                    ? 'Ramo cancellato con successo'
                    : 'Errore nella cancellazione del ramo';
            } else {
                $msg = 'Parametri non esaurienti';
            }
            break;

        case 'addcarica':
            $carname = trim($_POST['nome'] ?? $_POST['carica'] ?? '');
            $prima    = isset($_POST['prima']);
            $ramo     = (int)($_POST['ramo'] ?? -1);
            $liv      = (int)($_POST['livello'] ?? -1);
            $descr    = trim($_POST['descrizione'] ?? '');
            $file     = $_FILES['immagine'] ?? ['error'=>UPLOAD_ERR_NO_FILE];
            if ($carname && $ramo >= 0 && $liv >= 0) {
                $msg = AddCaricaOrg($org, $carname, $prima, $ramo, $liv, $descr, $file);
            } else {
                $msg = 'Parametri non esaurienti';
            }
            break;

        case 'modifycarica':
            $cid      = (int)($_POST['carica'] ?? 0);
            $nome     = trim($_POST['nome'] ?? '');
            $prima    = isset($_POST['prima']);
            $ramo     = (int)($_POST['ramo'] ?? -1);
            $liv      = (int)($_POST['livello'] ?? -1);
            $descr    = trim($_POST['descrizione'] ?? '');
            $file     = $_FILES['immagine'] ?? ['error'=>UPLOAD_ERR_NO_FILE];
            if ($cid && $nome && $ramo >= 0 && $liv >= 0) {
                if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                    $msg = ModifyCaricaOrg($org, $cid, $nome, $prima, $ramo, $liv, $descr, $file);
                } else {
                    $ok  = $db->execute(
                        'UPDATE caricheorganizzazioni
                            SET nome=?, prima=?, ramo=?, livello=?, descrizione=?
                          WHERE id=? AND organizzazione=?',
                        [$nome, $prima, $ramo, $liv, $descr, $cid, $org]
                    );
                    $msg = $ok
                        ? 'Carica modificata con successo'
                        : 'Errore nella modifica della carica';
                }
            } else {
                $msg = 'Parametri non esaurienti';
            }
            break;

        case 'dropcarica':
            $cid = (int)($_POST['carica'] ?? 0);
            if ($cid) {
                $ok  = DropCaricaOrg($org, $cid);
                $msg = $ok
                    ? 'Carica cancellata con successo'
                    : 'Errore nella cancellazione della carica';
            } else {
                $msg = 'Parametri non esaurienti';
            }
            break;

        case 'prendiincarico':
            $pid = (int)($_POST['prestito'] ?? 0);
            if ($pid) {
                $ok  = EconomiaAzioneModulo(static fn(): bool => PrendiInCarico($pid, $idpg, $_POST['_request_key'] ?? null));
                $msg = $ok
                    ? 'Transazione presa in carico con successo'
                    : 'Errore nell\'acquisizione della transazione';
            } else {
                $msg = 'Parametri non esaurienti';
            }
            break;

        case 'adminprestito':
            $pid = EconomiaIntero($_POST['prestito'] ?? '',1);
            $acc = !empty($_POST['accetta']);
            $canc = !empty($_POST['cancella']);
            $qta = EconomiaIntero($_POST['quantita'] ?? '',1,2147483647);
            $int = EconomiaIntero($_POST['interessi'] ?? '',0,100);
            if ($pid && ($acc || $canc || ($qta!==null && $int!==null))) {
                $ok = EconomiaAzioneModulo(static function() use($pid,$idpg,$acc,$canc,$qta,$int): bool {
                    if ($acc) return AcceptPrestito($pid,$idpg,$_POST['_request_key'] ?? null);
                    if ($canc) return DeletePrestito($pid,$idpg,$_POST['_request_key'] ?? null);
                    return UpdatePrestito($pid,$idpg,$qta,$int,3,$_POST['_request_key'] ?? null);
                });
                $msg = $ok ? 'Richiesta aggiornata correttamente' : 'Operazione non consentita, già inviata o fondi insufficienti';
            } else $msg = 'Inserisci quantità intere positive e interessi tra 0 e 100';
            break;

        case 'prestito':
            $pid = EconomiaIntero($_POST['pg'] ?? '',1);
            $qta = EconomiaIntero($_POST['qta'] ?? '',1,2147483647);
            if ($pid && $qta!==null) {
                $ok = EconomiaAzioneModulo(static fn(): bool => CreaPrestitoPG($org,$pid,$qta,$_POST['_request_key'] ?? null));
                $msg = $ok ? 'Prestito creato correttamente' : 'Prestito non consentito, già inviato o fondi insufficienti';
            } else $msg = 'Inserisci un destinatario e una quantità intera positiva';
            break;

        case 'setflag':
            $pid = (int)($_POST['nome'] ?? 0);
            if ($pid) {
                $fields = [];
                $params = [];
                $fields[] = 'adminorganizzazione = ?';
                $params[] = isset($_POST['adminorg']) ? 1 : 0;
                if ($resurgo) {
                    $fields[] = 'adminmorti = ?';
                    $params[] = isset($_POST['resurgo']) ? 1 : 0;
                }
                if ($cerimonia) {
                    $fields[] = 'adminstatosociale = ?';
                    $params[] = isset($_POST['cerimonia']) ? 1 : 0;
                }
                if ($ferite) {
                    $fields[] = 'adminferite = ?';
                    $params[] = isset($_POST['ferite']) ? 1 : 0;
                }
                $params[] = $pid;
                $params[] = $org;
                $sql    = 'UPDATE utenti SET ' . implode(',', $fields) . ' WHERE id = ? AND organizzazione = ?';
                $ok     = $db->query($sql, $params);
                $msg    = $ok
                    ? 'Flag correttamente impostati'
                    : 'Errore nell\'assegnazione dei flag';
            } else {
                $msg = 'Parametri non esaurienti';
            }
            break;

        case 'proponi':
            $cid = (int)($_POST['city'] ?? 0);
            $pid = (int)($_POST['pg'] ?? 0);
            $prg = trim($_POST['programma'] ?? '');
            if ($cid && $pid && $prg && isset($pgcity[$cid][$pid]) && ElectionTime($cid)) {
                $costo = (int)$listacity[$cid]['costoelezioni'];
                $res   = InserisciCandidato($budget, $costo, $pid, $cid, $prg, $org);
                $msg   = $res === true
                    ? 'Candidato correttamente inserito'
                    : (string)$res;
            } else {
                $msg = 'Parametri non esaurienti';
            }
            break;

        case 'modifypvt':
            $cid  = (int)($_POST['city'] ?? 0);
            $pwd  = trim($_POST['pvt'] ?? '');
            $top  = trim($_POST['sfondotop'] ?? '');
            $bg   = trim($_POST['sfondo'] ?? '');
            $mp   = trim($_POST['mappa'] ?? '');
            $desc = trim($_POST['descrizione'] ?? '');
            $st   = trim($_POST['sito'] ?? '');
            if (isset($listacity[$cid]) && $pwd !== '') {
                $ok  = $db->execute(
                    'UPDATE organizzazioni
                        SET city=?, sito=?, pvt=?, sfondotop=?, sfondo=?, mappa=?, descrizione=?
                      WHERE id=?',
                    [$cid, $st, base64_encode($pwd), $top, $bg, $mp, $desc, $org]
                );
                $msg = $ok
                    ? 'Dati modificati con successo'
                    : 'Errore nella modifica dei dati';
            } else {
                $msg = 'Parametri non esaurienti';
            }
            break;

            case 'visualizzaflag':
            $pid = (int)($_POST['nome'] ?? 0);
            if ($pid) {
                $res = $db->query(
                    'SELECT id, adminorganizzazione, adminmorti, adminstatosociale, adminferite
                    FROM utenti
                    WHERE id = ? AND organizzazione = ?',
                    [$pid,$org]
                );
                $datiflag = $res[0] ?? [];
            }
            else {
                $msg = 'Parametri non esaurienti';
            }
            break;

            case 'changecarica':
                $pg  = (int)($_POST['pg']     ?? 0);
                $cid = (int)($_POST['carica'] ?? 0);

                if ($pg) {
                    // se $cid > 0 assegni, altrimenti espelli
                    $admit = $cid > 0;
                    $ok = AdmitPGOrg($pg, $org, $cid, $admit);

                    if ($ok) {
                        if ($admit) {
                            $nome = GetPGFromID($pg);
                            $car  = GetCaricaOrg($org, $cid, false);
                            $msg  = "$nome ora ricopre la carica “{$car}”";
                        } else {
                            $msg = GetPGFromID($pg) . " espulso dall'organizzazione";
                        }
                    } else {
                        $msg = "Errore nell'assegnazione della carica";
                    }
                } else {
                    $msg = 'Parametri non esaurienti';
                }

                $activeTab = 'iscritti';
                break;
    }

    // riallinea dati per template
    $requests    = GetRichiesteOrg($org);
    $prestitiOrg = ListaPrestitiOrg($org);
    $prestitiPG  = $db->query(
        'SELECT p.data,p.qta,u.nome,u.id
           FROM prestitipg p
           JOIN utenti u ON u.id = p.pg
          WHERE p.org = ?',
        [$org]
    );
    $membersOrg  = GetPGInOrg($org);
    $currentOrg=$db->query('SELECT budget,city,sito,pvt,sfondotop,sfondo,mappa,descrizione FROM organizzazioni WHERE id=?',[$org])[0];
    $budget=(int)$currentOrg['budget'];$cityorg=(int)$currentOrg['city'];$sito=(string)$currentOrg['sito'];$pvt=base64_decode((string)$currentOrg['pvt']);
    $sfondotop=(string)$currentOrg['sfondotop'];$sfondo=(string)$currentOrg['sfondo'];$mappa=(string)$currentOrg['mappa'];$descrizione=(string)$currentOrg['descrizione'];
}


// Gerarchia (rami + cariche)
$gerarchia = GetGerarchiaOrg($org);
$cariche   = [];
foreach ($gerarchia as $g) {
    $lvl = (int)$g['livello'];
    $ram = (int)$g['ramo'];
    if (!isset($cariche[$lvl][$ram])) {
        $cariche[$lvl][$ram] = [
            'nome'    => $g['nomeramo'],
            'cariche' => []
        ];
    }
    $cariche[$lvl][$ram]['cariche'][$g['idcarica']] = [
        'nomecarica' => $g['nomecarica'],
        'prima'      => (bool)$g['prima'],
    ];
}

$rami=GetListaRamiOrg($org);
$resCariche=GetListaCaricheOrg($org);

// ─── Popola $pginorg (per “Concedi nuovo prestito a PG” e “Visualizza Flag”) ────
$pginorg = [];
// GetPGInOrg restituisce già un array di righe ['id'=>..., 'nome'=>...]
foreach (GetPGInOrg($org) as $row) {
    $pginorg[(int)$row['id']] = $row['nome'];
}

// ─── Popola $prestitiPGOrg (per “Amministrazione richieste prese in carico”) ────
// Se ListaPrestitiPGOrg restituisce un array di array, lo usi così com’è:
$prestitiPGOrg = ListaPrestitiPGOrg($idpg, $org);

// ─── Popola $candidati (per “Candidature esistenti”) ────────────────────────────
$candidati = [];
// $db->query() restituisce un array di righe associative
foreach ($db->query(
    'SELECT idpg, city, programma
       FROM candidati
      WHERE organizzazione = ?',
    [$org]
) as $row) {
    $cid = (int)$row['city'];
    $pid = (int)$row['idpg'];
    $candidati[$cid] = [
        'id'        => $pid,
        'nome'      => $pgcity[$cid][$pid]['nome']
                       ?? $pginorg[$pid]
                       ?? '',
        'programma' => $row['programma'],
    ];
}

// ——————————————————————————
// Popolo $pgPerCarica: per ogni carica, la lista dei PG che la ricoprono
// ——————————————————————————
$pgPerCarica = [];
foreach ($resCariche as $c) {
    $cid = (int)$c['id'];
    $pgPerCarica[$cid] = $db->query(
        'SELECT u.id, u.nome
           FROM utenti u
          WHERE u.organizzazione = ? AND u.caricaorganizzazione = ?',
        [$org, $cid]
    );
}

// ——————————————————————————
// Costruisco $membersWithRole: un elenco piatto di tutti i membri e la loro carica
// ——————————————————————————
$membersWithRole = [];
// mappa idcarica => nome carica
$caricaNames = [];
foreach ($resCariche as $c) {
    $caricaNames[(int)$c['id']] = $c['nome'];
}
foreach ($pgPerCarica as $cid => $rows) {
    foreach ($rows as $r) {
        $membersWithRole[] = [
            'pid'    => (int)$r['id'],
            'player' => $r['nome'],
            'cid'    => $cid,
            'carica' => $caricaNames[$cid] ?? '–',
        ];
    }
}

return;
