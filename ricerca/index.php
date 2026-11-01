<?php
/*************************************************************************
 * Ricerca Personaggi – versione PHP 8 con driver che restituisce array
 * Copia & incolla questo file così com’è.
 *************************************************************************/

// ------------------------------------------------------------------
// 1. Inclusioni
// ------------------------------------------------------------------
require("../libs/kernel.inc.php");
require("../libs/libgrafica.inc.php");
require("../libs/libpg.inc.php");
require("../libs/libtext.inc.php");
require_once __DIR__."/../libs/player-runtime.inc.php";








$db      = ConnectDB();
$pgid    = GetIDPG();

// Titolo del sito (cambia se vuoi)
function TitoloSito(): string { return 'Alphano'; }

// ------------------------------------------------------------------
// 2. Setup variabili di base
// ------------------------------------------------------------------

$db      = ConnectDB();
$pgid    = GetIDPG();

// privilegi utente
// nuovo: estraggo prima il risultato, poi controllo se c’è almeno una riga
$tmp = $db->query("SELECT admin, fatepower FROM utenti WHERE id='$pgid'");
if (count($tmp) > 0) {
    $userRow = $tmp[0];
} else {
    $userRow = ['admin' => 0, 'fatepower' => 0];
}
if ($userRow['admin']) {
    EnsureAdmin();
}
$admin = ($userRow['admin'] || $userRow['fatepower']);


// ------------------------------------------------------------------
// 3. Parametri GET
// ------------------------------------------------------------------
$params = ["nome","luogo","razza","online","araldica","caricacorporazione",
           "caricaorganizzazione","categoria","corporazione","organizzazione",
           "al","sesso","etnia","city"];
foreach ($params as $p) $$p = $_GET[$p] ?? "";

// ------------------------------------------------------------------
// 4. Query dinamica
// ------------------------------------------------------------------
$categoriaMap=['Accademia'=>'adminaccademia','Master'=>'fatepower','Governatori'=>'admin','Capi Organizzazione'=>'adminorganizzazione','Capi Corporazione'=>'admincorporazione','Guaritori'=>'adminferite','Resurrettori'=>'adminmorti','Cerimonieri'=>'adminstatosociale','Sindaci'=>'sindaco'];
[$q,$searchParameters] = player_search_query($_GET);

// ------------------------------------------------------------------
// 5. Tabelle di supporto (array semplici)
// ------------------------------------------------------------------
$mapRaz  = array_column(ListaRazze($admin),  'nome','id');
$mapEtn  = array_column(ListaEtnie(),        'nome','id');
$listaOrg  = ListaOrg();           // [{id,nome,descrizione}]
$listaCorp = ListaCorporazioni();  // [{id,nome,descrizione}]
$mapCity   = array_column(GetListaCity(), 'city','id');

$listaaraldiche = [];
foreach (ListaAraldiche() as $r) $listaaraldiche[$r['city']][] = $r;

// ------------------------------------------------------------------
// 6. Esegui query e raccogli ID per descrizioni
// ------------------------------------------------------------------
$rows   = $db->query($q,$searchParameters);
$orgIDs = $corpIDs = $araldIDs = [];

foreach ($rows as $d) {
    if ($d['caricaorganizzazione']) $orgIDs[$d['caricaorganizzazione']] = true;
    if ($d['caricacorporazione'])   $corpIDs[$d['caricacorporazione']] = true;
    $araldIDs[$d['araldica']] = true;
}

// cariche org
$org = [];
if ($orgIDs) {
    $ids = "'".implode("','", array_keys($orgIDs))."'";
    foreach ($db->query("SELECT id,nome,descrizione FROM caricheorganizzazioni WHERE id IN ($ids)") as $r)
        $org[$r['id']] = $r;
}
// cariche corp
$corp = [];
if ($corpIDs) {
    $ids = "'".implode("','", array_keys($corpIDs))."'";
    foreach ($db->query("SELECT id,nome,descrizione FROM carichecorporazioni WHERE id IN ($ids)") as $r)
        $corp[$r['id']] = $r;
}
// araldiche
$arald = [];
if ($araldIDs) {
    $ids = "'".implode("','", array_keys($araldIDs))."'";
    foreach ($db->query("SELECT id,nome,descrizione FROM araldiche WHERE id IN ($ids)") as $r)
        $arald[$r['id']] = $r;
}

// ------------------------------------------------------------------
// 7. Costruisci array "ricerca" completo
// ------------------------------------------------------------------
$ricerca = [];
foreach ($rows as $d) {
    $portrait=alphano_profile_portrait($d);
    $obj = [
        'portrait'=>$portrait['url'],'portrait_default'=>$portrait['default'],
        'idpg'        => $d['id'],
        'nome'        => $d['nome'],
        'imgrazza'    => $d['logical_race'].($d['sesso']==="Maschio"?'m':'f'),
        'razza'       => ($mapRaz[$d['logical_race']] ?? '').' '.($mapEtn[$d['etnia']] ?? ''),
        'allineamento'=> processAl($d['allineamento']),
        'posizione'   => html_entity_decode(html_entity_decode(GetPosizione($d['posizione']), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'imgaraldica' => $d['araldica'],
        'araldica_url'=>alphano_badge_url('cariche',(int)$d['araldica']),
        'araldica'    => $arald[$d['araldica']]['nome']        ?? '',
        'descaral'    => $arald[$d['araldica']]['descrizione'] ?? '',
        'imgorg'      => '',
        'org'         => '',
        'descorg'     => '',
        'imgcorp'     => '',
        'corp'        => '',
        'desccorp'    => '',
    ];

    if ($d['caricaorganizzazione']) {
        $obj['imgorg']  = $d['organizzazione'].'/'.$d['caricaorganizzazione'];
        $obj['org']     = $org[$d['caricaorganizzazione']]['nome']        ?? '';
        $obj['descorg'] = $org[$d['caricaorganizzazione']]['descrizione'] ?? '';
    }
    if ($d['caricacorporazione']) {
        $obj['imgcorp']  = $d['corporazione'].'/'.$d['caricacorporazione'];
        $obj['corp']     = $corp[$d['caricacorporazione']]['nome']        ?? '';
        $obj['desccorp'] = $corp[$d['caricacorporazione']]['descrizione'] ?? '';
    }
    $obj['org_url']=$d['caricaorganizzazione']?alphano_badge_url('organizzazioni',(int)$d['caricaorganizzazione'],(int)$d['organizzazione']):'';
    $obj['corp_url']=$d['caricacorporazione']?alphano_badge_url('corporazioni',(int)$d['caricacorporazione'],(int)$d['corporazione']):'';
    $ricerca[] = $obj;
}

// ------------------------------------------------------------------
// 8. Posizioni per la combo
// ------------------------------------------------------------------
$posQuery = $admin
  ? "SELECT DISTINCT posizione FROM utenti WHERE posizione NOT IN ('off','online') ORDER BY posizione"
  : "SELECT DISTINCT posizione FROM utenti
       WHERE posizione NOT IN ('off','online')
         AND chat NOT LIKE 'pvt%%'
         AND chat NOT LIKE '%%(Spettatore)'
       ORDER BY posizione";
$posizioni = array_column($db->query($posQuery), 'posizione');

// ------------------------------------------------------------------
// 9. Output
// ------------------------------------------------------------------
require __DIR__.'/../templates/search/index.php';
