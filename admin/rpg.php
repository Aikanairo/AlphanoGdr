<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
alphano_require_login();
if(!alphano_can('admin'))alphano_fail('Accesso riservato agli amministratori.',403);
require __DIR__.'/../templates/admin/rpg.php';
