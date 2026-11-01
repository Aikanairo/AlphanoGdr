<?php
declare(strict_types=1);

// ———————————————————————————
// MASSIMO DEBUG & ERROR HANDLING
// ———————————————————————————
function h(string|int|null $v): string {
    return htmlentities((string)$v, ENT_QUOTES, 'UTF-8');
}

// ———————————————————————————
// INCLUDI LIBRERIE
// ———————————————————————————
$root = dirname(__DIR__);
require_once "$root/libs/kernel.inc.php";
require_once "$root/libs/libgrafica.inc.php";
require_once "$root/libs/libpg.inc.php";
require_once "$root/libs/libposta.inc.php";
require_once "$root/libs/libtext.inc.php";
require_once "$root/libs/mail-presentation.inc.php";






// ———————————————————————————
// LOGIN & COLORI
// ———————————————————————————
$idpg      = alphano_require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') alphano_require_csrf();
$db        = ConnectDB();





// ———————————————————————————
// RACCOLTA PARAMETRI
// ———————————————————————————
$oldid      = $_POST['oldid']    ?? '';
// supporto GET per pre‐riempimento in modal
$destId     = (int)($_POST['idpgDest'] ?? $_GET['idpg'] ?? 0);
$pgName     = $_POST['pgName']   ?? ($_GET['pgName'] ?? '');
$send       = !empty($_POST['send']);
$oggetto    = trim($_POST['oggetto']   ?? '');
$messaggio  = trim($_POST['messaggio'] ?? '');
$salva      = 0; // Le risposte restano nella conversazione.


// ———————————————————————————
// CARICAMENTO UTENTE
// ———————————————————————————
$user = $db->query('SELECT * FROM utenti WHERE id = ?', [$idpg])[0] ?? null;
if (!$user) die('❗ Utente non trovato');
if (!empty($user['admin'])) {
    EnsureAdmin();
}

// ———————————————————————————
// RECUPERO VECCHIO MESSAGGIO
// ———————————————————————————
$oldmessaggio = $oldoggetto = '';
$replySource = null;
if ($oldid) {
    $row = $db->query('SELECT id,idmittente,iddestinatario,oggetto,messaggio FROM mail WHERE id = ? AND (idmittente=? OR iddestinatario=?)', [(int)$oldid,$idpg,$idpg])[0] ?? null;
    if ($row) {
        $replySource = $row;
        $oldoggetto   = preg_replace('/^Re:/','',$row['oggetto']);
        $oldmessaggio = $row['messaggio'];
    }
}

if(!$send && $destId>0){unset($row);require __DIR__.'/../templates/mail/workspace.php';ClosePage();exit;}

// ———————————————————————————
// COSTRUZIONE QUERY DESTINATARI
// ———————————————————————————
$luogo      = $_POST['luogo']       ?? '';
$razza      = $_POST['razza']       ?? '';
$online     = $_POST['online']      ?? '';
$araldica   = $_POST['araldica']    ?? '';
$categoria  = $_POST['categoria']   ?? '';
$corp       = $_POST['corporazione']?? '';
$org        = $_POST['org']         ?? '';
$al         = $_POST['allineamento']?? '';
$sesso      = $_POST['sesso']       ?? '';
$city       = $_POST["citta'"]      ?? '';

$params     = [];
$conditions = ["1=1"];
if ($destId > 0) { $conditions[]="utenti.id=?"; $params[]=$destId; if ($pgName==='') $pgName=GetPGFromID($destId); }

if ($pgName !== '' && $pgName !== '%') {
    if (strpos($pgName,';') !== false) {
        $names = array_map('trim', explode(';',$pgName));
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $conditions[] = "utenti.nome IN ($placeholders)";
        $params = array_merge($params, $names);
    } else {
        $conditions[] = "utenti.nome LIKE ?";
        $params[] = $pgName;
    }
} elseif ($pgName === '%') {
    // lascio 1=1: tutti
}

if ($luogo!=='')     { $conditions[] = "utenti.posizione LIKE ?";       $params[] = "%$luogo%"; }
if ($araldica!=='')  { $conditions[] = "utenti.araldica = ?";             $params[] = $araldica; }
if ($corp!=='')      { $conditions[] = "utenti.corporazione = ?";        $params[] = $corp; }
if ($org!=='')       { $conditions[] = "utenti.organizzazione = ?";       $params[] = $org; }
if ($al!=='')        { $conditions[] = "utenti.allineamento = ?";        $params[] = $al; }
if ($sesso!=='')     { $conditions[] = "utenti.sesso = ?";              $params[] = $sesso; }

if ($razza!=='') {
    switch ($razza) {
        case '3': $conditions[] = "utenti.infetto = 1";      break;
        case '6': $conditions[] = "utenti.ancestrale = 1";   break;
        default:  $conditions[] = "utenti.razza = ?";       $params[] = $razza; break;
    }
}

if ($categoria!=='') {
    static $mapCat = [
        'Accademia'=>'adminaccademia','Master'=>'fatepower','Governatori'=>'admin',
        'Capi Organizzazione'=>'adminorganizzazione','Capi Corporazione'=>'admincorporazione',
        'Guaritori'=>'adminferite','Resurrettori'=>'adminmorti','Cerimonieri'=>'adminstatosociale',
        'Sindaci'=>'sindaco'
    ];
    if (isset($mapCat[$categoria])) {
        $conditions[] = "utenti.{$mapCat[$categoria]} = 1";
    }
}

if ($city!=='')    { $conditions[] = "araldiche.city = ?";   $params[] = $city; }
if ($online==='Si'){ $conditions[] = "utenti.posizione != 'off'"; }
elseif ($online==='No'){ $conditions[] = "utenti.posizione = 'off'"; }

$sql = "
  SELECT DISTINCT utenti.id, utenti.nome
    FROM utenti
    JOIN araldiche ON araldiche.id = utenti.araldica
   WHERE " . implode(' AND ', $conditions) . "
   ORDER BY utenti.nome ASC
";
$destRows = $db->query($sql, $params);

// ———————————————————————————
// INVIO MISSIVA
// ———————————————————————————
if ($send) {
    if ($pgName === '' && count($conditions) === 1) alphano_fail('Specifica almeno un destinatario.',400);
    if ($messaggio === '') alphano_fail('Inserisci un messaggio.',400);
    if ($oggetto === '') {
        echo "<!DOCTYPE html><html lang='it'><head><meta charset='UTF-8'><title>Oggetto Mancante</title></head>"
           . "<body class='message-page'>"
           . "<p>Devi inserire un oggetto per la missiva</p>"
           . "<p><a href='javascript:history.back()'>Torna Indietro</a></p>"
           . "</body></html>";
        exit;
    }
    // Render each part independently: GML reply, signature, then the authorized quote.
    $messaggio = ProcessText($messaggio);
    if (!empty($_POST['firma'])) $messaggio .= alphano_mail_signature($idpg);
    if (!empty($_POST['include_previous']) && $oldmessaggio !== '') {
        $messaggio .= '<div class="mail-quoted-history"><p class="mail-history-heading">Conversazione precedente</p><blockquote>'.alphano_mail_render($oldmessaggio).'</blockquote></div>';
    }
    $pass = $failed = [];
    foreach ($destRows as $row) {
        $toId = (int)$row['id'];
        $deliveryBody = $messaggio;
        if ($replySource && in_array($toId,[(int)$replySource['idmittente'],(int)$replySource['iddestinatario']],true)) {
            $deliveryBody = '<span class="mail-parent-reference" id="mail-parent-'.(int)$replySource['id'].'"></span>'.$deliveryBody;
        }
        if (InviaMissiva($oggetto, $deliveryBody, $toId, $idpg, $salva)) {
            $pass[] = $row['nome'];
        } else {
            $failed[] = $row['nome'];
        }
    }
    require __DIR__.'/../templates/mail/result.php';
    ClosePage();
    exit;
}

// ———————————————————————————
// FORM DI COMPOSIZIONE
// ———————————————————————————
$composePeople=count($destRows)===1?alphano_mail_people([$idpg,(int)$destRows[0]['id']]):[];
require __DIR__.'/../templates/mail/compose.php';
ClosePage();
