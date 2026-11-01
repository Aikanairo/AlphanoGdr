<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/player-runtime.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';






player_api(function (): array {
alphano_require_login(true);
// ────────────────────────────────────────────────────────────────────
// 3) Connessione DB e parametri GET
// ────────────────────────────────────────────────────────────────────
$db = ConnectDB();

[$sql,$sqlParams] = player_search_query($_GET);
$rows = $db->query($sql,$sqlParams);

// ────────────────────────────────────────────────────────────────────
// 6) Raccolta dati di supporto per cariche e araldiche
// ────────────────────────────────────────────────────────────────────
$orgIDs   = $corpIDs   = $araldIDs = [];
foreach ($rows as $r) {
    if ($r['caricaorganizzazione']) $orgIDs[$r['caricaorganizzazione']] = true;
    if ($r['caricacorporazione'])   $corpIDs[$r['caricacorporazione']]   = true;
    $araldIDs[$r['araldica']] = true;
}

$org  = $corp  = $arald = [];
if ($orgIDs) {
    $in = implode(',', array_fill(0, count($orgIDs), '?'));
    $tmp = $db->query("SELECT id,nome,descrizione FROM caricheorganizzazioni WHERE id IN ($in)", array_keys($orgIDs));
    foreach ($tmp as $t) { $org[$t['id']] = $t; }
}
if ($corpIDs) {
    $in = implode(',', array_fill(0, count($corpIDs), '?'));
    $tmp = $db->query("SELECT id,nome,descrizione FROM carichecorporazioni WHERE id IN ($in)", array_keys($corpIDs));
    foreach ($tmp as $t) { $corp[$t['id']] = $t; }
}
if ($araldIDs) {
    $in = implode(',', array_fill(0, count($araldIDs), '?'));
    $tmp = $db->query("SELECT id,nome,descrizione FROM araldiche WHERE id IN ($in)", array_keys($araldIDs));
    foreach ($tmp as $t) { $arald[$t['id']] = $t; }
}

// map delle razze ed etnie
$mapRaz = array_column(ListaRazze(true), 'nome','id');
$mapEtn = array_column(ListaEtnie(),     'nome','id');

// ────────────────────────────────────────────────────────────────────
// 7) Costruzione array JSON “ricerca”
//
// usa esattamente gli stessi campi del JS originale:
// idpg, nome, imgrazza, razza, allineamento, posizione,
// imgaraldica, araldica, descaral, imgorg, org, descorg, imgcorp, corp, desccorp
// ────────────────────────────────────────────────────────────────────
$ricerca = [];
foreach ($rows as $d) {
    $portrait=alphano_profile_portrait($d);
    $visibleRace=alphano_visible_race($d);
    if($visibleRace===ALPHANO_RACE_LEGACY_SPIRIT)$visibleRace=ALPHANO_RACE_SPIRIT;
    $item = [
        'portrait'=>$portrait['url'],'portrait_default'=>$portrait['default'],
        'idpg'        => (int)$d['id'],
        'nome'        => $d['nome'],
        'imgrazza'    => $visibleRace . ($d['sesso'] === 'Maschio' ? 'm' : 'f'),
        'razza'       => ($mapRaz[$visibleRace] ?? '') . ' ' . ($mapEtn[$d['etnia']] ?? ''),
        'allineamento'=> processAl($d['allineamento']),
        'posizione'   => GetPosizione($d['posizione']),
        'imgaraldica' => $d['araldica'],
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
        $c = $org[$d['caricaorganizzazione']] ?? [];
        $item['imgorg']  = $d['organizzazione'].'/'.$d['caricaorganizzazione'];
        $item['org']     = $c['nome']        ?? '';
        $item['descorg'] = $c['descrizione'] ?? '';
    }
    if ($d['caricacorporazione']) {
        $c = $corp[$d['caricacorporazione']] ?? [];
        $item['imgcorp']  = $d['corporazione'].'/'.$d['caricacorporazione'];
        $item['corp']     = $c['nome']        ?? '';
        $item['desccorp'] = $c['descrizione'] ?? '';
    }
    $ricerca[] = $item;
}

// ────────────────────────────────────────────────────────────────────
// 8) Output JSON
// ────────────────────────────────────────────────────────────────────
return $ricerca;
});
