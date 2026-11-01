<?php
declare(strict_types=1);
require_once __DIR__ . '/player-runtime.inc.php';
require_once __DIR__.'/hierarchy-presentation.inc.php';

/** One read contract for both group hierarchies; no private player fields are selected. */
function alphano_group_hierarchy(string $kind, int $id, array $viewer): array
{
    $types = [
        'organizzazioni' => ['field' => 'organizzazione', 'role' => 'caricaorganizzazione', 'admin' => 'adminorganizzazione', 'icon' => 'users'],
        'corporazioni' => ['field' => 'corporazione', 'role' => 'caricacorporazione', 'admin' => 'admincorporazione', 'icon' => 'shield'],
    ];
    if (!isset($types[$kind])) throw new InvalidArgumentException('Tipo di gruppo non valido');
    $type = $types[$kind];
    $db = ConnectDB();
    $group = $db->query("SELECT id,nome,approvato FROM $kind WHERE id=?", [$id])[0] ?? null;
    $canSeeDraft = !empty($viewer['admin']) || !empty($viewer['adminaccademia']) || ((int)$viewer[$type['field']] === $id && !empty($viewer[$type['admin']]));
    if (!$group || (empty($group['approvato']) && !$canSeeDraft)) alphano_fail('Gruppo non disponibile.', 404);
    $field = $type['field'];
    $roles = $db->query("SELECT c.id,c.nome,c.descrizione,c.livello,c.prima,c.ramo AS branch_id,COALESCE(r.livello,2147483647) AS branch_level,r.nome AS branch_name
        FROM cariche$kind c LEFT JOIN rami$kind r ON r.id=c.ramo AND r.$field=c.$field
        WHERE c.$field=? ORDER BY COALESCE(r.livello,0),COALESCE(r.nome,''),c.livello,c.id", [$id]);
    $members = [];
    foreach ($db->query('SELECT id,nome,razza,vampire_human_form,sesso,avatar,avatar2,' . $type['role'] . ' AS office FROM utenti WHERE ' . $field . '=? ORDER BY nome,id', [$id]) as $member) $members[(int)$member['office']][] = alphano_hierarchy_member($member);
    foreach ($roles as &$role) {
        $role['badge'] = alphano_badge_url($kind, (int)$role['id'], $id);
        $role['badge_small']=alphano_hierarchy_small_badge($role['badge']);
        $role['description_html'] = ProcessText((string)$role['descrizione']);
        $role['members'] = $members[(int)$role['id']] ?? [];
        unset($members[(int)$role['id']]);
    }
    unset($role);
    $unassigned=array_merge([], ...array_values($members));
    $chart=alphano_hierarchy_chart($roles,$unassigned,$kind==='organizzazioni');
    return ['chart'=>$chart,'name' => $group['nome'], 'kind' => $kind, 'icon' => $type['icon'], 'roles' => $roles, 'unassigned' => $unassigned];
}
