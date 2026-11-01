<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/presence.inc.php';
alphano_require_login();
$presence=alphano_presence();
function presence_h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
require __DIR__.'/../templates/presence/index.php';
