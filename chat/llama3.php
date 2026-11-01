<?php
declare(strict_types=1);
// Compatibilità con i vecchi client: stesso invio HTTP, inferenza soltanto nel worker locale.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
require_once __DIR__.'/../libs/ai.inc.php';
try {
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new DomainException('Metodo non consentito',405);
    alphano_require_login(true);
    $in=alphano_request_json();
    alphano_require_csrf(true);
    $chat=ai_field($in,'chat_key',100,true);
    $prompt=ai_field($in,'prompt',1200,true);
    $room=ai_room($chat);
    if (!$room || !(int)$room['enabled']) throw new DomainException('Nessun PNG attivo in questo luogo.',404);
    $_GET['chat']=$chat;
    $GLOBALS['alphano_legacy_ai_request']=[...$in,'msg'=>$prompt,'ai_request'=>true];
} catch (Throwable $e) {
    $status=$e instanceof DomainException?$e->getCode():400;
    http_response_code($status>=400&&$status<=599?$status:500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;
}
require __DIR__.'/messages.php';
