<?php
declare(strict_types=1);

require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__ . '/libpg.inc.php';
require_once __DIR__ . '/libtext.inc.php';
require_once __DIR__.'/hierarchy-presentation.inc.php';
require_once __DIR__.'/public-guides.inc.php';
require_once __DIR__.'/elections.inc.php';

/** Public city data only: proposals, finances and applications remain in their protected controllers. */
function alphano_city_directory(array $viewer, int $selected = 0): array
{
    $db = ConnectDB();
    $cities = $db->query('SELECT c.id,c.city,c.elezioni,c.statuto,
        (SELECT COUNT(*) FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE a.city=c.id) AS citizens,
        (SELECT COUNT(*) FROM araldiche a WHERE a.city=c.id) AS offices
        FROM city c ORDER BY c.city,c.id');
    $mayors = $db->query('SELECT u.id,u.nome,u.avatar,u.avatar2,u.razza,u.alternate_form,u.sesso,a.city FROM utenti u JOIN araldiche a ON a.id=u.araldica
        WHERE u.sindaco=1 ORDER BY u.nome,u.id');
    foreach($mayors as &$mayor) $mayor['portrait']=alphano_profile_portrait($mayor);
    unset($mayor);
    $pending=(int)($db->query('SELECT city FROM richieste WHERE idpg=? AND city>0 ORDER BY data,city LIMIT 1',[(int)$viewer['id']])[0]['city']??0);
    $viewerCity = (int)($db->query('SELECT city FROM araldiche WHERE id=?', [(int)($viewer['araldica'] ?? 0)])[0]['city'] ?? 0);
    $chosen = null;
    $elections=alphano_elections_summaries();$titles=alphano_governance_city_titles();
    foreach ($cities as &$city) {
        $id = (int)$city['id'];
        $city['leader_title']=$titles[$id]??'Governante';
        $city['election']=$elections[$id];
        $city['mayors'] = array_values(array_filter($mayors, static fn(array $mayor): bool => (int)$mayor['city'] === $id));
        $city['can_manage'] = !empty($viewer['admin']) || (!empty($viewer['sindaco']) && $viewerCity === $id);
        $city['is_citizen'] = $viewerCity === $id;
        $city['artwork']=alphano_city_artwork((string)$city['city']);
        $city['pending_application']=$pending===$id;
        $city['can_apply']=!$city['is_citizen']&&$pending===0;
        if ($id === $selected) $chosen = $city;
    }
    unset($city);
    $offices = [];
    $citizens = [];
    if ($chosen !== null) {
        // LEFT JOIN includes the mayoral office, which legitimately has no branch.
        $offices = $db->query('SELECT a.id,a.nome,a.stipendio,a.descrizione,a.prima,a.livello,
            a.ramo AS branch_id,COALESCE(r.livello,2147483647) AS branch_level,r.nome AS branch_name FROM araldiche a LEFT JOIN ramiaraldiche r ON r.id=a.ramo AND r.city=a.city
            WHERE a.city=? ORDER BY COALESCE(r.livello,0),COALESCE(r.nome,\'\'),a.livello DESC,a.nome,a.id', [$selected]);
        foreach ($offices as &$office) $office['badge'] = alphano_badge_url('cariche', (int)$office['id']);
        unset($office);
        $citizens = $db->query('SELECT u.id,u.nome,u.sindaco,u.razza,u.alternate_form,u.sesso,u.avatar,u.avatar2,a.nome AS office_name,
            a.id AS office_id,r.nome AS branch_name,z.nome AS race_name
            FROM utenti u JOIN araldiche a ON a.id=u.araldica
            LEFT JOIN ramiaraldiche r ON r.id=a.ramo AND r.city=a.city
            LEFT JOIN razze z ON z.id=u.razza WHERE a.city=? ORDER BY u.sindaco DESC,u.nome,u.id', [$selected]);
        foreach ($citizens as &$citizen) {
            $race = alphano_visible_race($citizen);
            $citizen['portrait_data']=alphano_hierarchy_member($citizen);
            $citizen['race_name'] = GetRazza($race)??'';
            $citizen['race_icon'] = alphano_race_icon($race,$citizen['sesso']);
        }
        unset($citizen);
        $byOffice=[];foreach($citizens as $citizen)$byOffice[(int)$citizen['office_id']][]=$citizen['portrait_data'];
        foreach($offices as &$office){$office['badge_small']=alphano_hierarchy_small_badge($office['badge']);$office['members']=$byOffice[(int)$office['id']]??[];$office['description_html']=ProcessText((string)$office['descrizione']);}unset($office);
        $chosen['statute_html'] = ProcessText((string)$chosen['statuto']);
    }
    $chart=alphano_hierarchy_chart($offices,[],true);
    return ['pending_application'=>$pending,'chart'=>$chart,'cities' => $cities, 'selected' => $chosen, 'offices' => $offices, 'citizens' => $citizens];
}
