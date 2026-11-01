<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
$idpg = GetIDPG(true);
$user = alphano_user();
$channels = [];
if (!empty($user['fatepower']) || !empty($user['admin'])) $channels['M'] = 'Tracce Quest';
if (!empty($user['adminaccademia']) || !empty($user['admin'])) $channels['A'] = 'Accademia';
$channels['Appunti' . $idpg] = 'Appunti personali';
if (!empty($user['organizzazione'])) $channels['Org' . (int)$user['organizzazione']] = GetOrg((int)$user['organizzazione']);
if (!empty($user['corporazione'])) $channels['Corp' . (int)$user['corporazione']] = GetCorporazione((int)$user['corporazione']);
if (!empty($user['admin'])) {
    foreach (getDB()->query('SELECT id,nome FROM organizzazioni WHERE approvato=1 ORDER BY nome') as $group) $channels['Org'.(int)$group['id']]=$group['nome'];
    foreach (getDB()->query('SELECT id,nome FROM corporazioni WHERE approvato=1 ORDER BY nome') as $group) $channels['Corp'.(int)$group['id']]=$group['nome'];
}
$requested = $_GET['tipo'] ?? '';
$tipo = is_string($requested) && isset($channels[$requested]) ? $requested : array_key_first($channels);
$forum = ['mode'=>'discussion', 'title'=>'Discussioni', 'channels'=>$channels, 'tipo'=>$tipo,
    'apiBaseUrl'=>'/api', 'currentUserId'=>$idpg, 'csrfToken'=>alphano_csrf_token()];
require __DIR__ . '/../templates/forum/index.php';
