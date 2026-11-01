<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/ai.inc.php';
require_once __DIR__.'/../libs/profile-presentation.inc.php';
require_once __DIR__.'/../libs/profile-art.inc.php';
require_once __DIR__.'/../libs/revelations.inc.php';
require_once __DIR__.'/../libs/npc-mortality.inc.php';
alphano_require_login();
$viewer = alphano_user();
$rawId = $_GET['Id'] ?? '';
if (!is_string($rawId) || filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) alphano_fail('Personaggio non valido.', 400);
$id = (int)$rawId;
$death=alphano_npc_death($id);
$npc = ai_db()->query('SELECT n.*,COALESCE(m.title,n.chat_key) AS title,m.chat AS public_chat FROM ai_npcs n LEFT JOIN mappe m ON m.chat_key=n.chat_key COLLATE utf8mb4_unicode_ci WHERE n.id=?', [$id])[0] ?? null;
// The immutable memorial remains reachable even if a location or live cast row is retired.
if(!$npc&&$death)$npc=['id'=>$id,'name'=>$death['npc_name'],'chat_key'=>$death['chat_key'],'title'=>$death['place_name'],'active'=>0,'profile'=>$death['profile_snapshot'],'public_chat'=>0];
if (!$npc || (!$death&&empty($npc['public_chat'])) || (!(int)$npc['active'] && !$death && !alphano_relations_staff((int)$viewer['id']) && (int)ai_npc_profile($npc)['hit_points']>0)) alphano_fail('Personaggio non disponibile.', 404);
$profile = ai_npc_profile($npc);
if($death||(int)$profile['hit_points']<=0){require __DIR__.'/../templates/profiles/npc-memorial.php';ClosePage();exit;}
$profile['money']=ai_npc_balance($id);
$escape = static fn(mixed $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$groups = [
    'identita' => ['title' => 'Identità', 'icon' => 'user', 'fields' => ['appearance' => 'Aspetto', 'occupation' => 'Occupazione', 'social_status' => 'Stato sociale']],
    'storia' => ['title' => 'Storia e personalità', 'icon' => 'book', 'fields' => ['biography' => 'Storia', 'psychology' => 'Personalità']],
    'relazioni' => ['title' => 'Relazioni', 'icon' => 'users', 'fields' => ['relationships' => 'Amicizie', 'affections' => 'Affetti', 'animals' => 'Animali', 'notes' => 'Note pubbliche']],
];
$sections = [];
foreach ($groups as $key => $group) {
    $rows = [];
    foreach ($group['fields'] as $field => $label) if ($profile[$field] !== '') $rows[] = ['label' => $label, 'html' => nl2br($escape($profile[$field]))];
    if ($rows) $sections[] = ['id' => $key, 'title' => $group['title'], 'icon' => $group['icon'], 'rows' => $rows];
}
$rows = [];
foreach (['Punti ferita' => $profile['hit_points'], alphano_currency_text('Corone') => number_format((int)$profile['money'], 0, ',', '.'), 'Ferite' => $profile['wounds'] ?: 'Nessuna', 'Allineamento' => processAl($profile['alignment']), 'Corporazione' => GetCorporazione((int)$profile['corporation_id']) ?: 'Nessuna', 'Organizzazione' => GetOrg((int)$profile['organization_id']) ?: 'Nessuna', 'Araldica' => GetAraldica((int)$profile['heraldry_id']) ?: 'Nessuna'] as $label => $value) $rows[] = ['label' => $label, 'html' => $escape($value)];
$sections[] = ['id' => 'stato', 'title' => 'Condizione e appartenenze', 'icon' => 'shield', 'rows' => $rows];
if ($profile['details']) $sections[] = ['id' => 'dettagli', 'title' => 'Altre informazioni', 'icon' => 'scroll', 'rows' => array_map(static fn(array $detail): array => ['label' => $detail['label'], 'html' => nl2br($escape($detail['value']))], $profile['details'])];
$gear=[];foreach(ai_game_inventory('npc',$id) as $item)if($item['slot']!=='')$gear[]=['label'=>$item['nome']?:$item['shortDesc'],'html'=>$escape($item['slot'])];if($gear)$sections[]=['id'=>'dotazioni','title'=>'Dotazioni visibili','icon'=>'shield','rows'=>$gear];
$identity = [GetRazza((int)$profile['race_id']), $profile['sex'], GetEtnia((int)$profile['ethnicity_id']) ?: 'Provenienza non indicata'];
if ($profile['age']) $identity[] = (int)$profile['age'] . ' anni';
$sheet = [
    'title' => $npc['name'], 'kind' => 'Personaggio non giocante', 'portrait' => $profile['portrait'] ?: '/assets/placeholder.svg',
    'identity_html' => processRazza((int)$profile['race_id'], $profile['sex'], (int)$profile['ethnicity_id']) . ' ' . $escape(implode(' · ', $identity)),
    'subtitle' => $profile['occupation'], 'motto_html' => nl2br($escape($profile['motto'])), 'music' => '',
    'metrics' => ['Punti ferita' => $profile['hit_points'], 'Corone' => number_format((int)$profile['money'], 0, ',', '.'), 'Forza'=>alphano_profile_strength('npc',$id,(int)$profile['race_id'])],
    'sections' => $sections, 'actions' => [['title' => $npc['title'], 'icon' => 'compass', 'url' => '/chat/index.php?chat=' . rawurlencode($npc['chat_key'])]],
];
$game=ai_npc_game($id);
$sheet['design']=alphano_profile_design(['name'=>$npc['name'],'sex'=>$profile['sex'],'race'=>$profile['race_id'],'corp'=>$profile['corporation_id'],'org'=>$profile['organization_id'],'corp_rank'=>$game['corporation_office_id'],'org_rank'=>$game['organization_office_id'],'heraldry'=>$profile['heraldry_id'],'alignment_code'=>$profile['alignment'],'alignment'=>processAl($profile['alignment']),'hp'=>$profile['hit_points'],'location'=>$npc['title']]);
$ethnicityName=GetEtnia((int)$profile['ethnicity_id'])?:'Origine non indicata';
$sheet['origin_label']=$ethnicityName.(!empty($profile['age'])?' · '.(int)$profile['age'].' anni':'');
$sheet['origin_image']=alphano_ethnicity_art($ethnicityName);
$sheet['status_label']='Personaggio di scena';
$sheet['relations_staff']=alphano_relations_staff((int)$viewer['id']);
$selectedPlayer=(int)$viewer['id'];
if(isset($_GET['player'])){
    if(!$sheet['relations_staff'])alphano_fail('Consultazione riservata allo staff di gioco.',403);
    if(!is_string($_GET['player'])||!ctype_digit($_GET['player'])||(int)$_GET['player']<1)alphano_fail('Personaggio non valido.',400);
    $selectedPlayer=(int)$_GET['player'];
}
$sheet['relation_read_only']=$sheet['relations_staff']&&isset($_GET['player']);
try{$sheet['npc_social']=$sheet['relation_read_only']?alphano_relations_npc_context($id,$selectedPlayer,(int)$viewer['id']):ai_social_private_view($id,$selectedPlayer);}catch(DomainException $e){alphano_fail($e->getMessage(),(int)$e->getCode());}
$sheet['npc_knowledge']=alphano_revelations_profile('npc',$id,$selectedPlayer);
$sheet['relation_player']=$selectedPlayer;
if($sheet['relations_staff'])$sheet['relation_overview']=$sheet['npc_social']['overview']??ai_personality_overview($id);
$sheet['npc_id']=$id;$sheet['npc_rules']=ai_social_rules($id);
$sheet['rpg_kind']='npc';$sheet['rpg_id']=$id;$sheet['race_id']=(int)$profile['race_id'];
array_splice($sheet['sections'],1,0,[['id'=>'progressione','title'=>'Caratteristiche e abilità','icon'=>'swords','rows'=>[],'template'=>'progression']]);
if (!empty($viewer['admin'])) $sheet['actions'][] = ['title' => 'Modifica personaggio', 'icon' => 'settings', 'url' => '/admin/master-ia.php?chat=' . rawurlencode($npc['chat_key']) . '&npc=' . $id];
$sheet['sections']=alphano_profile_compact_sections($sheet['sections']);
array_unshift($sheet['sections'],['id'=>'conoscenza','title'=>'Conoscenza','icon'=>'users','rows'=>[],'template'=>'npc-knowledge']);
$banner=alphano_profile_default_banner((int)$profile['race_id'],(string)$profile['sex']);$sheet['art']=['url'=>$banner,'default'=>$banner];
$sheet['seal']=alphano_profile_race_seal((int)$profile['race_id'],(string)$profile['sex']);
require __DIR__ . '/../templates/profiles/sheet.php';
ClosePage();
