<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
$idpg=GetIDPG(true);
$user=alphano_user();
if (empty($user['admin']) && empty($user['sindaco'])) alphano_fail('Accesso negato.',403);
$mayorCity=null;
if (empty($user['admin'])) {
    $mayorCity=getDB()->query('SELECT c.city FROM city c JOIN araldiche a ON a.city=c.id WHERE a.id=?',[(int)$user['araldica']])[0]['city'] ?? null;
    if (!$mayorCity) alphano_fail('Città non trovata.',404);
}
$boardAdmin=['apiUrl'=>'/api/admin/bacheche.php','csrfToken'=>alphano_csrf_token(),'canManageAll'=>!empty($user['admin']),'mayorCity'=>$mayorCity];
require __DIR__ . '/../templates/forum/admin.php';
