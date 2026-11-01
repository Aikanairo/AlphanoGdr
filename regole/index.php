<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libgrafica.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';
// Shared handbook links also work before login, using the existing guest policy.
if (GetIDPG(false) === null) {
    require __DIR__ . '/../manuali/index.php';
    return;
}
$idpg = GetIDPG(true);
$manuals = ['apiUrl'=>'/api/manuali.php','adminAccademia'=>(alphano_can('adminaccademia')||alphano_can('admin')),'csrfToken'=>alphano_csrf_token()];
require __DIR__ . '/../templates/manuals/index.php';
