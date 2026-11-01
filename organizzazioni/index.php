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
 * admin/organizzazioni.php
 * Lista Organizzazioni – PHP 8 + PDO
 ********************************************************/

/* ─────────────────── 1) Bootstrap ─────────────────── */
require_once __DIR__ . '/../libs/kernel.inc.php';       // initSession(), ClosePage()
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') alphano_require_csrf();
require_once __DIR__ . '/../libs/libaccademia.inc.php'; // MakeRichiestaOrg(), GetRichiesteOrgPG(), GetOrgApprovati(), GetOrgMasters()
require_once __DIR__ . '/../libs/libposta.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';       // GetIDPG()
require_once __DIR__ . '/../chat/libs/chat-core.php';  // ConnectDB()



initSession();
$pgid = GetIDPG(false, true);
$db   = ConnectDB();

// Recupera organizzazione corrente e permessi admin
$user = $db->query(
    'SELECT organizzazione, admin FROM utenti WHERE id = ?',
    [$pgid]
)[0] ?? null;

$org    = (int)($user['organizzazione'] ?? 0);
$isAdmin = !empty($user['admin']);

if ($isAdmin) {
    EnsureAdmin();
}

// Gestione richiesta di ammissione
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['org']) && $org === 0) {
    $targetOrg = (int)$_POST['org'];
    try {
$ok=MakeRichiestaOrg($targetOrg, $pgid);
        $msg = $ok ? 'Richiesta di ammissione inviata con successo' : 'Richiesta non valida o già presente';
    } catch (\Throwable $e) {
        $msg = 'Errore nell\'invio della richiesta di ammissione';
    }
}

// Elenco delle richieste pendenti per questo PG
$denied = GetRichiesteOrgPG($pgid);

// Elenco di tutte le organizzazioni approvate
$organizzazioni = [];
foreach (GetOrgApprovati() as $row) {
    $organizzazioni[] = $row;
}


// Il controller prepara i dati; il template comune contiene soltanto la presentazione.
$groups=[];
foreach ($organizzazioni as $group) {
    $group['founder']=GetPGFromID((int)$group['idmaster']);
    $group['masters']=GetOrgMasters((int)$group['id']);
    $groups[]=$group;
}
$groupPage=['title'=>'Organizzazioni','singular'=>'Organizzazione','parameter'=>'org','member'=>(int)$org,'pending'=>(bool)$denied,'catalogue'=>false,'proposal'=>'./neworg.php','message'=>$msg];
require_once __DIR__.'/../libs/group-listing.inc.php';
$groups=alphano_group_listing($groups,'organizzazioni');
require __DIR__.'/../templates/groups/index.php';
ClosePage();
