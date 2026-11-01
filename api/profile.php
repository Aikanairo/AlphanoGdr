<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/player-runtime.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__.'/../libs/profile-art.inc.php';
require_once __DIR__.'/../libs/transformations.inc.php';
require_once __DIR__.'/../libs/metamorphosis.inc.php';

use Alphano\Database\PDOMySQLDriver;





// ────────────────────────────────────────────────────────────────────
// 3) Recupero ID utente e connessione DB
// ────────────────────────────────────────────────────────────────────
player_api(function (): array {
// id del chiamante
$visitorId = alphano_require_login(true);
// se nella query string (o POST) è passato uid, lo uso, altrimenti visitor
$uidParam = $_REQUEST['uid'] ?? null;
$profileId = $uidParam !== null ? (int)$uidParam : $visitorId;

$db        = ConnectDB();
require_once __DIR__.'/../libs/lineages.inc.php';
lineage_tick();
if ($profileId === $visitorId) alphano_transformation_sync($visitorId);

// ────────────────────────────────────────────────────────────────────
// 4) Query principale
// ────────────────────────────────────────────────────────────────────
$sql = <<<SQL
SELECT u.*,
       a.descrizione AS descrizione_araldica,
       org.descrizione AS descr_org,
       corp.descrizione AS descr_corp
  FROM utenti u
  LEFT JOIN araldiche a ON a.id = u.araldica
  LEFT JOIN caricheorganizzazioni org ON org.id = u.caricaorganizzazione
  LEFT JOIN carichecorporazioni corp ON corp.id = u.caricacorporazione
 WHERE u.id = :uid
 LIMIT 1
SQL;

$row = $db->query($sql, [':uid' => $profileId])[0] ?? null;
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Profile not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ────────────────────────────────────────────────────────────────────
// 5) Calcoli e formattazioni
// ────────────────────────────────────────────────────────────────────
// Determino quale avatar scegliere
$visibleRace = alphano_visible_race($row);
$isAlt     = alphano_profile_uses_alt_art($row);
$avatarUrl = ($isAlt && !empty($row['avatar2'])) ? $row['avatar2'] : $row['avatar'];
$mottoKey  = $isAlt ? 'motto2' : 'motto';
$musicKey  = $isAlt ? 'musica2' : 'musica';
$motto     = $row[$mottoKey] ?? '';
$music     = $row[$musicKey] ?? '';

// Flags utente
$flagsMap = [
    'admin'               => 'Governatore',
    'fatepower'           => 'Master',
    'adminmorti'          => 'Resurrettore',
    'adminferite'         => 'Curatore',
    'bloccato'            => 'Bloccato',
    'adminorganizzazione' => 'Capo Org',
    'admincorporazione'   => 'Capo Corp',
    'adminaccademia'      => 'Membro Acc.',
    'adminstatosociale'   => 'Cerimoniere',
    'infetto'             => 'Infetto',
    'ancestrale'          => 'Ancestrale',
    'admincatalogo'       => 'Admin Cat.',
    'sindaco'             => 'Sindaco',
    'upload'              => 'Upload',
    'vacanza'             => 'In Vacanza',
];
$flags = [];
foreach ($flagsMap as $field => $label) {
    if (!empty($row[$field])) {
        $flags[] = $label;
    }
}
if (!empty($row['imprigionato'])) {
    $city = $db->query('SELECT city FROM city WHERE id = ?', [(int)$row['imprigionato']])[0]['city'] ?? '';
    $flags[] = "Imprig. a $city";
}

// Note master
$notesRaw = (!empty(alphano_user()['admin']) || !empty(alphano_user()['fatepower'])) ? $db->query(
    'SELECT data, master, nota FROM notepg WHERE pg = ? ORDER BY id',
    [$profileId]
) : [];
$notes = [];
foreach ($notesRaw as $n) {
    $notes[] = [
        'data'   => $n['data'],
        'master' => [
            'id'   => (int)$n['master'],
            'name' => GetPGFromID((int)$n['master']),
        ],
        'nota'   => ProcessText($n['nota']),
    ];
}

// ────────────────────────────────────────────────────────────────────
// 6) Composizione JSON
// ────────────────────────────────────────────────────────────────────
$lineageRace = alphano_profile_lineage_race($row);
$output = [
    'sidebar_art_url' => alphano_profile_default_banner($lineageRace, (string)$row['sesso']),
    'lineage_race_label' => GetRazza($lineageRace),
    'id'           => (int)$row['id'],
    'nome'         => $row['nome'],
    'razza'        => [
        'id'    => $visibleRace,
        'label' => GetRazza($visibleRace),
        'icon'  => ProcessRazza($visibleRace, $row['sesso']),
    ],
    'etnia'        => GetEtnia((int)$row['etnia']),
    'allineamento' => processAl($row['allineamento']),
    'avatar_url'   => player_media_url($avatarUrl,$profileId,'/assets/avatar.svg'),
    'avatar_default_url' => '/assets/avatar.svg',
    'motto'        => ProcessText($motto),
    'music_url'    => player_media_url($music,$profileId),
    'visit_count'  => (int)$row['visite'],
    'denari'       => (int)$row['denari'],
    'puntif'       => (int)$row['puntif'],
    'puntif_max'   => maxpuntif,
    'ferite'       => $row['ferite'] ?: 'Nessuna',
    'corporazione' => [
        'id'   => (int)$row['corporazione'],
        'name' => GetCorporazione((int)$row['corporazione']),
        'carica'=>[
            'id'         => (int)$row['caricacorporazione'],
            'label'      => GetCaricaCorp((int)$row['corporazione'], (int)$row['caricacorporazione'], true, 'corporazione'),
            'descrizione'=> $row['descr_corp'],
        ],
    ],
    'organizzazione'=>[
        'id'   => (int)$row['organizzazione'],
        'name' => GetOrg((int)$row['organizzazione']),
        'carica'=>[
            'id'         => (int)$row['caricaorganizzazione'],
            'label'      => GetCaricaOrg((int)$row['organizzazione'], (int)$row['caricaorganizzazione'], true, 'organizzazione'),
            'descrizione'=> $row['descr_org'],
        ],
    ],
    'posizione'   => GetPosizione($row['posizione']),
    'araldica'    => [
        'id'          => (int)$row['araldica'],
        'descrizione' => $row['descrizione_araldica'],
    ],
    'timestamps'  => [
        'registrazione'   => FormatTimestamp((int)$row['dataregistrazione']),
        'ultimo_ingresso' => FormatTimestamp((int)$row['ultimoingresso'], 'Mai'),
    ],
    'personal'    => [
        'animali'       => $row['animali']   ? ProcessText($row['animali'])  : 'Nessuno',
        'amicizie'      => $row['amicizie']  ? ProcessText($row['amicizie']) : 'Non rivelate',
        'affetti'       => $row['affetti']   ? ProcessText($row['affetti'])  : 'Non rivelati',
        'stato_sociale' => $row['statosociale'],
    ],
    'notes'       => $notes,
    'flags'       => $flags,
    // se chiesta via ?uid=<altro>, isOwner=false
    'isOwner'     => $profileId === $visitorId,
    'metamorphosis' => alphano_metamorphosis($row),
    'transformation' => $profileId === $visitorId ? alphano_transformation_state($row) : null,
];

// ────────────────────────────────────────────────────────────────────
// 7) Output JSON
// ────────────────────────────────────────────────────────────────────
return $output;
});
