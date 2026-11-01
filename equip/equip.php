<?php
declare(strict_types=1);

foreach (array_merge($_GET,$_POST) as $value) {
    if (is_array($value) || is_object($value)) {
        http_response_code(400);
        echo 'Parametri non validi';
        exit;
    }
}

use Alphano\Database\PDOMySQLDriver;


require_once __DIR__ . '/../libs/kernel.inc.php';     // initSession(), ConnectDB()
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') alphano_require_csrf();
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libeconomia.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';      // GetIDPG(), GetRazza(), ProcessRazza(), ProcessAl()
require_once __DIR__ . '/../libs/libequip.inc.php';   // getSlottedEquip(), getEquipInZaino(), getPesoTotale(), getAffaticamento(), getInformation(), checkSlot(), ecc.
require_once __DIR__ . '/../libs/libposta.inc.php';
require_once __DIR__.'/../libs/profile-presentation.inc.php';

initSession();
$idpg = GetIDPG(false);
if (!$idpg) {
    header('Location:/login.php');
    exit;
}

$db = ConnectDB();

// 1) Identifico il PG da visualizzare
$embedded=($_GET['embedded']??$_POST['embedded']??'')==='1';
$pgid = filter_input(INPUT_GET, 'pgid', FILTER_VALIDATE_INT) ?: 0;
if (!$pgid) {
    http_response_code(400);
    exit('Personaggio non specificato');
}

// 2) Permessi
$me = $db->query(
    "SELECT admin,fatepower,bloccato,razza,admintrasfcorp,admincorporazione,corporazione AS corp
       FROM utenti
      WHERE id = ?",
    [$idpg]
)[0] ?? [];
$admin     = (bool)($me['admin'] ?? false);
$fatepower = (bool)($me['fatepower'] ?? false);
$lock      = (bool)($me['bloccato'] ?? false);
$corp      = $me['corp'] ?? '';
$canPurchase=(!empty($me['admintrasfcorp']) || !empty($me['admincorporazione'])) && !in_array((int)($me['razza'] ?? 0),[ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT],true);
$isSame    = $idpg === $pgid;

// 3) Gestione ACTIONS (equip/unequip/usa/etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $id       = (int)($_POST['id'] ?? 0);
    $slot     = $_POST['slot'] ?? '';
    $side     = $_POST['side'] ?? '';
    $padre    = (int)($_POST['padre'] ?? 0);
    $to       = $_POST['to'] ?? '';
    $nome     = $_POST['nome'] ?? '';
    $detail   = (int)($_POST['detail_id'] ?? 0);

    if ($id<=0 || !$db->query('SELECT 1 FROM equippg WHERE id=? AND idpg=?',[$id,$pgid])) { http_response_code(403); exit('Oggetto non appartenente al personaggio'); }
    if ($action==='equipaggiapadre' && ($padre===$id || !$db->query('SELECT 1 FROM equippg WHERE id=? AND idpg=?',[$padre,$pgid]))) { http_response_code(400); exit('Contenitore non valido'); }
    switch ($action) {
        case 'equipaggia':
            if ($isSame || $admin || $fatepower) equipObject($id, $slot, $side);
            break;
        case 'disequipaggia':
            if ($isSame || $admin || $fatepower) unequipObject($id);
            break;
        case 'equipaggiapadre':
            if ($isSame || $admin || $fatepower) equipObjectPadre($id, $slot, $padre);
            break;
        case 'usa':
            if ($isSame) useObject($id, $pgid, $idpg);
            break;
        case 'delete':
            if (($isSame || $admin || $fatepower) && !$lock) deleteObject($id, $pgid, $idpg);
            break;
        case 'send':
            $dest = GetPGID($to);
            if (($isSame || $admin || $fatepower) && !$lock && $dest) moveObject($id, $pgid, $dest, $idpg);
            break;
        case 'setnome':
            if ($isSame) setNome($id, $nome, $idpg);
            break;
        case 'acquista':
            if (!$corp || $isSame || (!($me['admintrasfcorp'] ?? false) && !($me['admincorporazione'] ?? false))) { http_response_code(403); exit('Solo un responsabile può acquistare per la propria corporazione'); }
            if (in_array((int)$me['razza'],[ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT],true)) { http_response_code(403); exit('Non puoi acquistare quando sei morto'); }
            $qta=CommercioIntero($_POST['qta'] ?? '1',1,10000);
            if ($qta===null) { http_response_code(400); exit('Inserisci una quantità intera tra 1 e 10000'); }
            try { $ok=buyObject($id,$pgid,$idpg,(int)$corp,$qta,$_POST['_request_key'] ?? null); }
            catch (RuntimeException $error) {
                $code=in_array($error->getCode(),[400,409],true) ? $error->getCode() : 500;
                http_response_code($code); exit(htmlspecialchars($code<500 ? $error->getMessage() : 'Acquisto non completato. Riprova.',ENT_QUOTES));
            }
            if (!$ok) { http_response_code(400); exit('Richiesta non creata: verifica quantità disponibile e trattative già aperte.'); }
            $_SESSION['success']='Richiesta di acquisto inviata. Il venditore deve proporre il prezzo: nessun pagamento è stato effettuato.';
            $_SESSION['equipment_purchase']=['seller'=>$pgid,'corp'=>(int)$corp];
            header('Location: /equip/equip.php?pgid='.$pgid.($embedded?'&embedded=1':'').($detail?'&detail_id='.$detail:'').'#equipment-detail',true,303);
            exit;


    }

    // Redirect per evitare resubmit
    $url = "equip.php?pgid={$pgid}".($embedded?'&embedded=1':'');
    if ($detail) {
        $url .= "&detail_id={$detail}";
    }
    header("Location: $url");
    exit;
}

// 4) Carico dati PG + EQUIP & ZAINO
$user = $db->query(
    "SELECT nome,razza,sesso,allineamento
       FROM utenti
      WHERE id = ?",
    [$pgid]
)[0] ?? [];

if(!$user){http_response_code(404);exit('Personaggio non trovato');}
$pgName       = $user['nome'];
$idRazza      = (int)($user['razza'] ?? 0);
$razzaLabel   = GetRazza($idRazza);
$sesso        = $user['sesso'] ?? '';
$allineamento = $user['allineamento'] ?? '';

$forzaRazza = alphano_profile_strength('pg',$pgid,$idRazza);
$pesotot    = getPesoTotale($pgid);

$equip        = getSlottedEquip($pgid);
$rawBackpack  = getEquipInZaino($pgid);

// Raggruppo materie prime
$grouped = [];
foreach ($rawBackpack as $itm) {
    if ($itm['materiaprima'] !== '') {
        $key = $itm['materiaprima'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['itm' => $itm, 'count' => 0];
        }
        $grouped[$key]['count']++;
    } else {
        $grouped[] = ['itm' => $itm, 'count' => 1];
    }
}
$backpackItems = array_values($grouped);

$slots     = ['guanti','elmo','mantello','veste','armasx','armatura','armadx','bracciali','faretra','stivali','scudo','musica','anellosx','collana','anellodx','attrezzi'];

$slotLabels = ['armasx'=>'Arma sinistra','armadx'=>'Arma destra','anellosx'=>'Anello sinistro','anellodx'=>'Anello destro','musica'=>'Strumento musicale'];

$detail_id = filter_input(INPUT_GET, 'detail_id', FILTER_VALIDATE_INT) ?: 0;

$purchaseReceipt=$_SESSION['equipment_purchase']??null;unset($_SESSION['equipment_purchase']);
$purchaseReceipt=is_array($purchaseReceipt)&&(int)$purchaseReceipt['seller']===$pgid&&(int)$purchaseReceipt['corp']===(int)$corp?$purchaseReceipt:null;
$equipmentNotice = $_SESSION['success'] ?? $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
$o = null;
$maxQty = 0;
if ($detail_id) {
    $o = getInformation($detail_id);
    if (!$o || (int)$o['owner'] !== $pgid) { http_response_code(404); exit('Oggetto non trovato'); }
$maxQty=(int)($db->query('SELECT COUNT(*) AS n FROM equippg WHERE uid=? AND idpg=?',[$o['uid'],$pgid])[0]['n'] ?? 0);
}
$can = 0;
$equipmentCreatorName = '';
if ($o) {
    if ($o['slot'] === '' && ($isSame || $admin || $fatepower)) {
        $can = in_array(strtolower($o['tipoG']), ['arma','armasx','armadx'], true)
            ? checkSlotWeapon($pgid, (int)$o['mani'])
            : ($o['equipaggiabile'] ? checkSlot($o['tipoG'], $pgid) : 0);
    }
    if ($o['idcreatore']) $equipmentCreatorName = GetPGFromID($o['idcreatore']);
}
$equipmentUrl=static fn(int $item=0):string=>'/equip/equip.php?pgid='.$pgid.($embedded?'&embedded=1':'').($item?'&detail_id='.$item.'#equipment-detail':'');
require __DIR__ . '/../templates/equipment/sheet.php';
