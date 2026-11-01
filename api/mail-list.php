<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/mail-presentation.inc.php';
header('Cache-Control: private, no-store');
player_api(static function():array{
 $uid=alphano_require_login(true);
 $rows=ConnectDB()->query('SELECT id,messaggio,data,idmittente,iddestinatario,baule,letto,oggetto,deldest,delmitt FROM mail WHERE (iddestinatario=? AND deldest=0) OR (idmittente=? AND delmitt=0) ORDER BY id DESC',[$uid,$uid]);
 return alphano_mail_boxes($rows,$uid);
});
