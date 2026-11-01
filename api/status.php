<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__.'/../libs/online-presence.inc.php';
require_once __DIR__ . '/../libs/astro.inc.php';
require_once __DIR__.'/../libs/world-chronology.inc.php';
require_once __DIR__ . '/../libs/navigation.inc.php';
require_once __DIR__.'/../libs/media-assets.inc.php';
require_once __DIR__.'/../libs/welcome.inc.php';
require_once __DIR__.'/../libs/revelations.inc.php';
require_once __DIR__.'/../libs/elections.inc.php';
require_once __DIR__.'/../libs/pvt/rentals.inc.php';
require_once __DIR__.'/../libs/navigation-context.inc.php';
alphano_rental_expire();


use Alphano\Database\PDOMySQLDriver;

/* ------------------------------------------------------------------
 * 1) Identificazione utente
 * ----------------------------------------------------------------*/
$idpg = GetIDPG(true);
alphano_presence_touch($idpg);

/* ------------------------------------------------------------------
 * 2) Meteo + alba/tramonto
 * ----------------------------------------------------------------*/
$meteo             = GetMeteo();
[$alba, $tramonto] = SorgeCala();

/* ------------------------------------------------------------------
 * 3) Luna
 * ----------------------------------------------------------------*/
$lunaPhase  = FaseLunare();
$daysToFull = DaysToFullMoon();
$isLycanNow = LicanTime();

/* ------------------------------------------------------------------
 * 4) User & posta
 * ----------------------------------------------------------------*/
$db   = db();
require_once __DIR__.'/../libs/transformations.inc.php';
alphano_transformation_sync($idpg);
$user = $db->query('SELECT * FROM utenti WHERE id = ?', [$idpg])[0];

$leggere = (int)$db->query(
    'SELECT COUNT(*) n FROM mail
      WHERE letto = 0 AND baule = 0 AND deldest = 0 AND iddestinatario = ?',
    [$idpg]
)[0]['n'];

$totMail = (int)$db->query(
    'SELECT COUNT(*) n FROM mail
      WHERE baule = 0 AND deldest = 0 AND iddestinatario = ?',
    [$idpg]
)[0]['n'];

if ($user['bloccato']) {
    UnLockUser($idpg);
}

/* ------------------------------------------------------------------
 * 5) Quick-links fissi (da inserire dopo Trasferimenti)
 * ----------------------------------------------------------------*/
$quickLinks = [
    ['url'=>'/fortuna/','label'=>'Ruota della sorte','w'=>1100,'h'=>850],
    ['url'=>'/presenti/','label'=>'Presenti','w'=>1000,'h'=>750],
    ['url'=>'/bacheche/','label'=>'Bacheche',       'w'=>900,'h'=>750],
    ['url'=>'/corporazioni/',     'label'=>'Corporazioni',   'w'=>1000,'h'=>750],
    ['url'=>'/mappa.php','label'=>'Mappa',         'w'=>900,'h'=>750],
    ['url'=>'/classifica/','label'=>'Classifica','w'=>1100,'h'=>850],
    ['url'=>'/razze/','label'=>'Razze','w'=>1000,'h'=>750],
    ['url'=>'/regole/',           'label'=>'Manuali',        'w'=>1000,'h'=>750],
    ['url'=>'/organizzazioni/',   'label'=>'Organizzazioni', 'w'=>1000,'h'=>750],
    ['url'=>'/ricerca/','label'=>'Ricerca',        'w'=>900,'h'=>750],
    ['url'=>'/sessione/viewlog.php','label'=>'Sessioni',     'w'=>950,'h'=>750],
    ['url'=>'/sondaggi/sondaggi.php','label'=>'Sondaggi',    'w'=>900,'h'=>750],
    ['url'=>'/statistiche/',        'label'=>'Statistiche',  'w'=>900,'h'=>750],
];

/* ------------------------------------------------------------------
 * 6) Costruzione menu utente
 * ----------------------------------------------------------------*/
$menu = [];

/* ----- voci base ----- */
$menu[] = ['url'=>"/posta/",                      'label'=>"Posta ($leggere/$totMail)", 'w'=>600,'h'=>600];
$menu[] = ['url'=>"/profili/?Id=$idpg",          'label'=>'Profilo',                 'w'=>700,'h'=>700];
$menu[] = ['url'=>"/equip/equip.php?pgid=$idpg", 'label'=>'Equipaggiamento',         'w'=>750,'h'=>700];

/* ----- trasferimenti (no fantasmi/zombie) ----- */
if (!in_array((int)$user['razza'], [ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT], true)) {
    $menu[] = ['url'=>'/trasferimenti/','label'=>'Trasferimenti','w'=>900,'h'=>850];
}

/* ----- quick-links subito dopo “Trasferimenti” (o dopo Equip se Trasferimenti assente) ----- */
$menu = array_merge($menu, $quickLinks);

/* ----- admin ferite / anime / morti ----- */
if ($user['admin'] || $user['fatepower'] || $user['adminferite'] || $user['adminmorti']) {
    $menu[] = ['url'=>'/admin/ferite.php','label'=>'Admin Ferite','w'=>600,'h'=>600];
}

/* ----- fate-power (master) ----- */
if ($user['admin']) $menu[] = ['url'=>'/admin/weather-assets.php','label'=>'Icone meteo e luna','w'=>1000,'h'=>700];
if ($user['admin']) $menu[] = ['url'=>'/admin/welcome.php','label'=>'Messaggio di benvenuto','w'=>1000,'h'=>750];
if ($user['admin'] || $user['fatepower']) $menu[] = ['url'=>'/admin/malus.php','label'=>'Vulnerabilità e condizioni','w'=>1100,'h'=>820];
if ($user['admin']) $menu[] = ['url'=>'/admin/rpg.php','label'=>'Razze, abilità ed esperienza','w'=>1200,'h'=>850];
if ($user['admin']) $menu[] = ['url'=>'/admin/master-ia.php','label'=>'Master IA','w'=>1100,'h'=>800];
if (alphano_relations_staff($idpg)) $menu[] = ['url'=>'/admin/png-relazioni.php','label'=>'Relazioni dei PNG','w'=>1100,'h'=>800];
if ($user['admin'] || $user['fatepower']) {
    $menu[] = ['url'=>'/sessione/sessione.php','label'=>'Regia sessione','w'=>1250,'h'=>880];
    $menu[] = ['url'=>'/discussioni/?tipo=M','label'=>'Tracce Quest','w'=>800,'h'=>600];
    $menu[] = ['url'=>'/admin/blocco.php','label'=>'Blocco Utenti','w'=>700,'h'=>400];
}

/* ----- chat / admin accademia ----- */
if ($user['admin'] || $user['fatepower'] || $user['adminaccademia']) {
    $menu[] = ['url'=>'/admin/chats.php','label'=>'Admin Chat','w'=>1000,'h'=>700];
    if ($user['admin']) $menu[] = ['url'=>'/admin/mappe.php','label'=>'Admin Mappe','w'=>1000,'h'=>700];
}

if ($user['admin']) $menu[]=['url'=>'/admin/assistenza.php','label'=>'Assistenza Utenti','w'=>1200,'h'=>850];
if ($user['admin']) $menu[]=['url'=>'/admin/giochi.php','label'=>'Giochi da tavolo','w'=>1200,'h'=>850];
if ($user['admin'] || $user['adminaccademia']) $menu[] = ['url'=>'/admin/lavori.php','label'=>'Lavori e materie prime','w'=>1200,'h'=>850];

/* ----- accademia ----- */
if ($user['admin'] || $user['adminaccademia']) {
    $menu[] = ['url'=>'/admin/accademia.php','label'=>'Accademia','w'=>800,'h'=>500];
    $menu[] = ['url'=>'/discussioni/?tipo=A','label'=>'Discus. Acc.','w'=>800,'h'=>600];
    $menu[] = ['url'=>'/admin/log.php','label'=>'Log Viewer','w'=>800,'h'=>600];
}

/* ----- organizzazione ----- */
if ($user['organizzazione']) {
    $org = (int)$user['organizzazione'];
    $menu[] = ['url'=>'/governo/?scope=organizzazioni&id='.$org,'label'=>'Tesoreria organizzazione','w'=>1100,'h'=>850];
    $menu[] = ['url'=>"/discussioni/?tipo=Org$org",'label'=>'Bacheca Org','w'=>800,'h'=>600];
    if ($user['adminorganizzazione']) {
        $menu[]=['url'=>'/organizzazioni/admin.php?org='.$org.'&panel=abilita#tab-abilita','label'=>'Abilità organizzazione','w'=>1200,'h'=>850];
        $menu[] = ['url'=>'/governo/?scope=organizzazioni&id='.$org,'label'=>'Admin Org','w'=>1000,'h'=>500];
    }
}

if(!empty($user['admin'])&&!$user['organizzazione'])$menu[]=['url'=>'/governo/?scope=organizzazioni','label'=>'Admin Org','w'=>1100,'h'=>850];
/* ----- corporazione ----- */
if ($user['corporazione']) {
    $corp = (int)$user['corporazione'];
    $menu[] = ['url'=>'/governo/?scope=corporazioni&id='.$corp,'label'=>'Tesoreria corporazione','w'=>1100,'h'=>850];
    $menu[] = ['url'=>"/discussioni/?tipo=Corp$corp",'label'=>'Bacheca Corp','w'=>800,'h'=>600];
    if ($user['admincorporazione']) {
        $menu[] = ['url'=>'/governo/?scope=corporazioni&id='.$corp,'label'=>'Admin Corp','w'=>1000,'h'=>500];
    }
    $menu[] = ['url'=>"/corporazioni/catalogo.php?corp={$corp}",      'label'=>'Catalogo','w'=>1000,'h'=>500];
    $menu[] = ['url'=>'/corporazioni/trasferimenti.php', 'label'=>'Corporazione','w'=>1000,'h'=>500];
}

if(!empty($user['admin'])&&!$user['corporazione'])$menu[]=['url'=>'/governo/?scope=corporazioni','label'=>'Admin Corp','w'=>1100,'h'=>850];
/* ----- sindaco / città ----- */
$mayorCity=(int)($db->query('SELECT city FROM araldiche WHERE id=?',[(int)$user['araldica']])[0]['city']??0);
$menu[] = ['url'=>'/citta/elezioni.php'.($mayorCity>0?'?city='.$mayorCity:''),'label'=>'Elezioni pubbliche','w'=>1100,'h'=>850];
if($mayorCity>0)$menu[] = ['url'=>'/governo/?scope=citta&id='.$mayorCity,'label'=>'Tesoreria cittadina','w'=>1100,'h'=>850];
if ($user['admin'] || ($user['sindaco'] && $mayorCity>0)) {
    $cityUrl='/admin/city.php'.($mayorCity>0?'?city='.$mayorCity:'');
    $menu[] = ['url'=>'/governo/?scope=citta'.($mayorCity>0?'&id='.$mayorCity:''),'label'=>'Admin Città','w'=>1100,'h'=>800];
    $menu[] = ['url'=>$cityUrl.'#richieste-cittadinanza','label'=>'Cittadinanze e richieste','w'=>1100,'h'=>800];
    $menu[] = ['url'=>'/admin/cariche.php','label'=>'Admin Araldiche','w'=>1000,'h'=>600];
    $menu[] = ['url'=>'/admin/bacheche.php','label'=>'Admin Bacheche','w'=>800,'h'=>500];
}

/* ----- utenti ----- */
if ($user['admin']) {
    $menu[] = ['url'=>'/admin/utenti.php','label'=>'Admin Utenti','w'=>800,'h'=>600];
} elseif ($user['adminstatosociale']) {
    $menu[] = ['url'=>'/admin/utenti.php','label'=>'Admin Stato Soc.','w'=>650,'h'=>200];
}

/* ----- ancestrale ----- */
require_once __DIR__.'/../libs/lineages.inc.php';
require_once __DIR__.'/../libs/lycan.inc.php';
if(lineage_vampire_can_infect($user))$menu[]=['url'=>'/admin/vampiri.php','label'=>'Infetta · Stirpe vampirica','w'=>800,'h'=>650];
if (alphano_transformation_state($user)['visible']||alphano_lycan_can_grant($user)) {
    $menu[] = ['url'=>'/admin/ancestrali.php','label'=>'Trasformazione','w'=>500,'h'=>700];
}

/* ----- OFF-Game tools ----- */
$menu[] = ['url'=>'/vari/menuvarie.php','label'=>'Strumenti OFF','w'=>800,'h'=>600];
$menu[] = ['url'=>'/logout.php','label'=>'Esci dal gioco','w'=>0,'h'=>0];

if (!$menu) {
    $menu[] = ['url'=>'#','label'=>'Nessuna voce','w'=>400,'h'=>200];
}

/* ------------------------------------------------------------------
 * 7) Output JSON
 * ----------------------------------------------------------------*/
if(!empty($user['admin'])){ $menu[]=['url'=>'/admin/setup.php','label'=>'Configurazione del gioco','w'=>1100,'h'=>850];$menu[]=['url'=>'/admin/appearance.php','label'=>'Template e homepage','w'=>1200,'h'=>850];$menu[]=['url'=>'/admin/settings.php','label'=>'Impostazioni del gioco','w'=>1100,'h'=>850];$menu[]=['url'=>'/admin/world.php','label'=>'Gestione del mondo','w'=>1100,'h'=>850];$menu[]=['url'=>'/admin/world-map.php','label'=>'Mappa visiva','w'=>1400,'h'=>900];$menu[]=['url'=>'/admin/privacy.php','label'=>'Cookie e privacy','w'=>1100,'h'=>850];}
if(!alphano_config('ai_enabled'))$menu=array_values(array_filter($menu,static fn($item)=>!str_contains($item['url'],'ai')&&!str_contains($item['url'],'png-relazioni')));
$navigation=alphano_navigation($menu);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'serverTimeMs' => round(microtime(true) * 1000),
    'summerOffUTC' => date('I') ? 2 : 1,
    'alba'         => date('H:i', $alba),
    'tramonto'     => date('H:i', $tramonto),

    'worldCalendar' => alphano_world_calendar(),
    'weatherIcons' => alphano_weather_icons($meteo,$lunaPhase,time()<$alba||time()>=$tramonto),
    'cielo'        => abs($meteo['cielo']),
    'descrCielo'   => $GLOBALS['cielo'][$meteo['cielo']],
    'tempoCode'    => abs($meteo['tempo']),
    'descrTempo'   => GetDescrTempo($meteo['tempo'], $meteo['temperatura']),
    'ventoCode'    => abs($meteo['vento']),
    'descrVento'   => $GLOBALS['vento'][$meteo['vento']],
    'temperatura'  => (int)$meteo['temperatura'],

    'lunaPhase'    => $lunaPhase,
    'daysToFull'   => $daysToFull,

    'postaNonLette'=> $leggere,
    'welcome'      => alphano_welcome_message($idpg),
    'civicInvitation'=>alphano_elections_invitation($idpg),
    'csrfToken'    => alphano_csrf_token(),

    'menu'         => $navigation['items'],
    'menuGroups'   => $navigation['groups'],
    'recentScene'  => alphano_navigation_recent_scene($user),
    'status'       => $isLycanNow ? 1 : 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
