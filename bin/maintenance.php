<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/libs/email-notifications.inc.php';
$collected=alphano_email_collect(50);$sent=alphano_email_deliver(20,15);
echo json_encode(['collected'=>$collected,'delivery'=>$sent],JSON_UNESCAPED_UNICODE).PHP_EOL;
