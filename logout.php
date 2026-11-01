<?php
declare(strict_types=1);
require_once __DIR__ . '/libs/kernel.inc.php';
require_once __DIR__ . '/libs/libpg.inc.php';
if(!empty($_SESSION['support_session_id'])){require_once __DIR__.'/libs/user-support.inc.php';alphano_support_finish('logout');header('Location: /main.php',true,303);exit;}
$idpg = GetIDPG(false);
require_once __DIR__.'/libs/web-push.inc.php';
if ($idpg && isset($_SESSION['push_endpoint_hash'])) alphano_push_disable((int)$idpg, (string)$_SESSION['push_endpoint_hash']);
if ($idpg) ConnectDB()->execute("UPDATE utenti SET posizione='off',chat='',ultimauscita=? WHERE id=?", [time(),$idpg]);
foreach (['utente','utonto','utanto'] as $cookie) setcookie($cookie,'',time()-3600,'/');
doLogout();
header('Location: /',true,303);
exit;
