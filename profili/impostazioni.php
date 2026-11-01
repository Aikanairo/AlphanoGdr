<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/kernel.inc.php';
alphano_require_login();
header('Location: /profili/modifyuser.php', true, 303);
exit;
