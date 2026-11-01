<?php
declare(strict_types=1);

foreach (array_merge($_GET,$_POST) as $value) {
    if (is_array($value) || is_object($value)) {
        http_response_code(400);
        echo 'Parametri non validi';
        exit;
    }
}

require_once __DIR__ . '/../libs/kernel.inc.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') alphano_require_csrf();
require_once __DIR__ . '/../libs/libaccademia.inc.php';
require_once __DIR__ . '/../libs/libposta.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';


use Alphano\Database\PDOMySQLDriver;

// Inizializzazione utente
$pgId = GetIDPG(false);
if ($pgId === null) {
    header('Location: /login.php');
    exit;
}

// Dati del personaggio e delle corporazioni




// Controllo permessi admin
$user = getDB()->query('SELECT corporazione, admin FROM utenti WHERE id = ?', [$pgId])[0];
$corporazione = (int)$user['corporazione'];
if ((bool)$user['admin']) {
    EnsureAdmin();
}

// Gestione richiesta di ammissione
if (!empty($_POST['corp']) && $corporazione === 0) {
    MakeRichiestaCorp((int)$_POST['corp'], $pgId);
}

// Verifica se ha già richieste pendenti
$denied = !empty(GetRichiesteCorpPG($pgId));

// Elenco corporazioni approvate
$corporazioni = GetCorpApprovate();

// Il controller prepara i dati; il template comune contiene soltanto la presentazione.
$groups=[];
foreach ($corporazioni as $group) {
    $group['founder']=GetPGFromID((int)$group['idmaster']);
    $group['masters']=GetCorpMasters((int)$group['id']);
    $groups[]=$group;
}
$groupPage=['title'=>'Corporazioni','singular'=>'Corporazione','parameter'=>'corp','member'=>(int)$corporazione,'pending'=>(bool)$denied,'catalogue'=>true,'proposal'=>'./newcorp.php','message'=>''];
require_once __DIR__.'/../libs/group-listing.inc.php';
$groups=alphano_group_listing($groups,'corporazioni');
require __DIR__.'/../templates/groups/index.php';
ClosePage();
