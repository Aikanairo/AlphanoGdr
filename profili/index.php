<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/player-runtime.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__.'/../libs/profile-presentation.inc.php';
require_once __DIR__.'/../libs/profile-contacts.inc.php';
require_once __DIR__.'/../libs/profile-art.inc.php';
require_once __DIR__.'/../libs/lineages.inc.php';
require_once __DIR__.'/../libs/transformations.inc.php';
require_once __DIR__.'/../libs/lycan.inc.php';
require_once __DIR__.'/../libs/revelations.inc.php';
require_once __DIR__.'/../libs/player-labels.inc.php';
require_once __DIR__.'/../libs/npc-mortality.inc.php';

$visitorId = alphano_require_login();
$viewer = alphano_user();
$db = ConnectDB();
alphano_transformation_sync($visitorId);
$escape = static fn(mixed $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$rawId = $_GET['Id'] ?? null;
$rawName = $_GET['Nome'] ?? $_POST['Nome'] ?? '';
if (($rawId !== null && (!is_string($rawId) || filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) || !is_string($rawName)) alphano_fail('Personaggio non valido.', 400);
$cid = $rawId !== null ? (int)$rawId : null;
$name = trim($rawName);
if ($cid === null && $name === '') $cid = $visitorId;
$row = $db->query('SELECT u.*,a.descrizione AS descrizione_araldica,org.descrizione AS descr_org,corp.descrizione AS descr_corp
    FROM utenti u LEFT JOIN araldiche a ON a.id=u.araldica
    LEFT JOIN caricheorganizzazioni org ON org.id=u.caricaorganizzazione
    LEFT JOIN carichecorporazioni corp ON corp.id=u.caricacorporazione
    WHERE (:cid IS NOT NULL AND u.id=:cid) OR (:cid IS NULL AND u.nome=:name) LIMIT 1', [':cid' => $cid, ':name' => $name])[0] ?? null;
if (!$row) alphano_fail('Personaggio inesistente o nome errato.', 404);
$cid = (int)$row['id'];
$isOwner = $visitorId === $cid;
$transformation=alphano_transformation_state($row);
$visibleRace=alphano_visible_race($row);
$isAlt = alphano_profile_uses_alt_art($row);
$avatarKey = $isAlt ? 'avatar2' : 'avatar';
$avatar = (string)(($row[$avatarKey] ?? '') ?: ($row['avatar'] ?? ''));
if ($avatar !== '' && !empty($row['upload']) && is_file(__DIR__ . '/pg/' . $cid . '/' . $avatar)) $avatar = '/profili/pg/' . $cid . '/' . $avatar;
$doppiRaw = TrovaDoppi($cid) ?: [];
$visits = (int)$row['visite'];
if (!in_array($visitorId, array_map('intval', $doppiRaw), true)) $visits += IncrementaVisite($visitorId, $cid);
$doppi = [];
if ($doppiRaw) {
    $placeholders = implode(',', array_fill(0, count($doppiRaw), '?'));
    foreach ($db->query("SELECT id,nome FROM utenti WHERE id IN ($placeholders) ORDER BY nome", $doppiRaw) as $other) $doppi[] = '<a href="/profili/?Id=' . (int)$other['id'] . '">' . $escape($other['nome']) . '</a>';
}
$textRow = static fn(string $label, mixed $value, string $fallback = 'Non dichiarato'): array => ['label' => $label, 'html' => $escape($value !== null && $value !== '' ? $value : $fallback)];
$richRow = static fn(string $label, mixed $value, string $fallback = 'Non rivelato'): array => ['label' => $label, 'html' => $value ? ProcessText((string)$value) : $escape($fallback)];
$sections = [
    ['id'=>'general','title'=>'Identità','icon'=>'user','rows'=>[]],
    ['id' => 'personal', 'title' => 'Storia e relazioni', 'icon' => 'book', 'rows' => [
        $richRow('Storia', $row['storiapg'], 'Non rivelata'),
        $richRow('Personalità', $row['psicologia']),
        $textRow('Stato sociale', $row['statosociale']),
        $richRow('Amicizie', $row['amicizie'], 'Non rivelate'),
        $richRow('Affetti', $row['affetti'], 'Non rivelati'),
        $richRow('Animali', $row['animali'], 'Nessuno'),
        $richRow('Note pubbliche', $row['note'], 'Nessuna'),
    ]],
    ['id' => 'stato', 'title' => 'Condizione', 'icon' => 'heart', 'rows' => [
        $textRow('Punti ferita', $row['puntif']),
        $textRow('Forza effettiva', alphano_profile_strength('pg',$cid,(int)$row['razza'])),
        $textRow('Ferite', $row['ferite'], 'Nessuna'),
        $textRow(alphano_currency_text('Corone'), number_format((int)$row['denari'], 0, ',', '.')),
        $textRow('Posizione', alphano_profile_label(GetPosizione((string)$row['posizione']))),
        ['label' => 'Personaggi collegati', 'html' => $doppi ? implode(' · ', $doppi) : 'Nessuno'],
    ]],
];
if (!empty($viewer['admin']) || !empty($viewer['fatepower'])) {
    $notes = $db->query('SELECT n.data,n.master,n.nota,u.nome AS author FROM notepg n LEFT JOIN utenti u ON u.id=n.master WHERE n.pg=? ORDER BY n.id', [$cid]);
    if ($notes) $sections[] = ['id' => 'note', 'title' => 'Note del Master', 'icon' => 'book', 'rows' => array_map(static fn(array $note): array => ['label' => FormatTimestamp((int)$note['data']) . ' · ' . (string)($note['author'] ?: 'Master'), 'html' => ProcessText((string)$note['nota'])], $notes)];
}
$adminRows=[];
if (!empty($viewer['admin']) || !empty($viewer['adminaccademia'])) {
    $flags = [];
    foreach (['admin' => 'Governatore', 'fatepower' => 'Master', 'adminmorti' => 'Resurrettore', 'adminferite' => 'Curatore', 'bloccato' => 'Bloccato', 'adminorganizzazione' => 'Responsabile organizzazione', 'admincorporazione' => 'Responsabile corporazione', 'adminaccademia' => 'Accademia', 'adminstatosociale' => 'Cerimoniere', 'infetto' => 'Infetto', 'ancestrale' => 'Ancestrale', 'admincatalogo' => 'Amministratore catalogo', 'sindaco' => 'Sindaco', 'upload' => 'Caricamento file', 'vacanza' => 'In vacanza'] as $key => $label) if (!empty($row[$key])) $flags[] = $label;
    if (!empty($row['imprigionato'])) $flags[] = 'Imprigionato a ' . ($db->query('SELECT city FROM city WHERE id=?', [(int)$row['imprigionato']])[0]['city'] ?? 'città non disponibile');
    $adminRows = [$textRow('Abilitazioni', $flags ? implode(' · ', $flags) : 'Nessuna')];
    if (!empty($viewer['admin'])) array_unshift($adminRows, $textRow('E-mail', $row['email']));
    if ($isOwner || !empty($viewer['admin'])) $adminRows[] = $textRow('Provincia', $row['provincia'], 'Non dichiarata');

}
$sections[]=['id'=>'admin','title'=>'Informazioni','icon'=>'scroll','rows'=>[$textRow('Registrazione',FormatTimestamp((int)$row['dataregistrazione'])),$textRow('Ultimo ingresso',FormatTimestamp((int)$row['ultimoingresso'],'Mai')),...($adminRows??[])]];
$contactRows=[];
$telegram=(string)($row['telegram']??'');$discord=(string)($row['discord']??'');
if($telegram!=='')$contactRows[]=['label'=>'Telegram','html'=>'<a href="https://t.me/'.rawurlencode(ltrim($telegram,'@')).'" target="_blank" rel="noopener noreferrer">'.$escape($telegram).'</a>'];
if($discord!=='')$contactRows[]=$textRow('Discord',$discord);
if($isOwner&&trim((string)$row['contatti'])!=='')$contactRows[]=$textRow('Appunti personali · solo per te',$row['contatti']);
if(!$contactRows)$contactRows[]=$textRow('Recapiti','Nessun contatto offgame condiviso.');
$sections[]=['id'=>'offgame','title'=>'Contatti offgame','icon'=>'mail','rows'=>$contactRows];
$sheet = [
    'title' => (string)$row['nome'], 'kind' => 'Personaggio giocante',
    'portrait' => player_media_url($avatar, $cid, '/assets/placeholder.svg'),
    'identity_html' => ProcessRazza($visibleRace, (string)$row['sesso']) . ' ' . $escape(implode(' · ', array_filter([GetRazza($visibleRace), $row['sesso'], GetEtnia((int)$row['etnia'])]))),
    'subtitle' => '', 'motto_html' => ProcessText((string)($row[$isAlt ? 'motto2' : 'motto'] ?? '')),
    'music' => player_media_url((string)($row[$isAlt ? 'musica2' : 'musica'] ?? ''), $cid),
    'metrics' => ['Punti ferita' => $row['puntif'], 'Corone' => number_format((int)$row['denari'], 0, ',', '.'), 'Visite' => $visits],
    'sections' => $sections,
    'actions' => [
        ['title' => 'Scrivi a ' . $row['nome'], 'icon' => 'mail', 'url' => '/posta/writeto.php?idpg=' . $cid . '&pgName=' . rawurlencode((string)$row['nome'])],
        ['title' => 'Equipaggiamento', 'icon' => 'shield', 'url' => '#equipment'],
    ],
];
$sheet['design']=alphano_profile_design(['name'=>$row['nome'],'sex'=>$row['sesso'],'race'=>$visibleRace,'corp'=>$row['corporazione'],'org'=>$row['organizzazione'],'corp_rank'=>$row['caricacorporazione'],'org_rank'=>$row['caricaorganizzazione'],'heraldry'=>$row['araldica'],'alignment_code'=>$row['allineamento'],'alignment'=>processAl($row['allineamento']),'hp'=>$row['puntif'],'location'=>GetPosizione($row['posizione'])]);
$sheet['transformation']=$isOwner?$transformation:null;
$sheet['origin_label']=GetEtnia((int)$row['etnia'])?:'Origine non indicata';
$sheet['origin_image']=alphano_ethnicity_art($sheet['origin_label']);
$sheet['status_label']=$row['posizione']==='off'?'Non connesso':($row['posizione']==='online'?'Fuori scena':'In scena');
$sheet['rpg_kind']='player';$sheet['rpg_id']=$cid;$sheet['race_id']=$visibleRace;
array_splice($sheet['sections'],1,0,[['id'=>'progressione','title'=>'Caratteristiche e abilità','icon'=>'swords','rows'=>[],'template'=>'progression']]);
if ($isOwner) $sheet['actions'][] = ['title' => 'Modifica profilo', 'icon' => 'settings', 'url' => '/profili/modifyuser.php'];
if ($isOwner&&(!empty($transformation['visible'])||alphano_lycan_can_grant($row))) $sheet['actions'][]=['title'=>!empty($transformation['visible'])?$transformation['label']:'Doni ancestrali','icon'=>'moon','url'=>'/admin/ancestrali.php'];
$sheet['sections']=alphano_profile_compact_sections($sheet['sections']);
$sheet['registration']=alphano_registration_label((int)$row['dataregistrazione']);
$sheet['revelations']=alphano_revelations_profile('pg',$cid,$visitorId);
$sheet['relations']=alphano_relations_profile_pg($cid,$visitorId);
$sheet['victims']=alphano_npc_victims($cid);
$sheet['sections'][]=['id'=>'vittime','title'=>'Registro delle vittime','icon'=>'scroll','template'=>'victims','rows'=>[]];
$sheet['sections'][]=['id'=>'rivelazioni','title'=>'Relazioni e rivelazioni','icon'=>'users','template'=>'revelations','rows'=>[]];
lineage_ensure($row);
$family=$db->query('SELECT * FROM lineage_members WHERE player_id=? ORDER BY infected_at DESC',[$cid])[0]??null;
if($family){
 $lineage=lineage_tree($cid,$family['kind']);
 $sheet['sections'][]=['id'=>'discendenza','title'=>'Discendenza','icon'=>'users','template'=>'lineage','rows'=>[]];
 if($family['status']==='eliminated')foreach($sheet['sections'] as &$section)if($section['template']==='history')$section['rows'][]=['label'=>'✕ Eliminato','html'=>'<p>'.$escape(date('d/m/Y H:i',(int)$family['killed_at']).' · '.$family['killer_name']).'</p><p>'.$escape($family['death_reason']).'</p>'];
 unset($section);
}
if($isOwner&&lineage_vampire_can_infect($row))$sheet['actions'][]=['title'=>'Mordi e infetta','icon'=>'moon','url'=>'/admin/vampiri.php'];
require_once __DIR__.'/../libs/metamorphosis.inc.php';
$sheet['metamorphosis']=alphano_metamorphosis($row);
$sheet['art']=alphano_profile_banner($row);
$sheet['seal']=alphano_profile_race_seal(alphano_profile_lineage_race($row),(string)$row['sesso']);
$sheet['seal_label']=GetRazza(alphano_profile_lineage_race($row)).' · '.$row['sesso'];
$sheet['equipment_url']='/equip/equip.php?pgid='.$cid.'&embedded=1';
$sheet['sections'][]=['id'=>'equipment','title'=>'Equipaggiamento','icon'=>'shield','template'=>'equipment','rows'=>[]];
require __DIR__ . '/../templates/profiles/sheet.php';
ClosePage();
