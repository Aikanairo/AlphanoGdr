<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/ai.inc.php';
require_once __DIR__.'/../libs/companions.inc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
try {
    $player=alphano_require_login(true);$method=$_SERVER['REQUEST_METHOD'];
    if ($method==='GET') {
        if (($_GET['action']??'')==='invitations') {
            $npc=filter_var($_GET['npc_id']??null,FILTER_VALIDATE_INT);if ($npc===false || $npc<1) throw new DomainException('Persona non valida.',422);
            echo json_encode(companionship_invitations($player,$npc),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
        }
        $host=$_GET['host']??'';if (!is_string($host) || strlen($host)>100) throw new DomainException('Luogo non valido.',400);
        echo json_encode(companionship_list($player,$host),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    if ($method!=='POST') throw new DomainException('Metodo non consentito.',405);
    alphano_require_csrf(true);$input=alphano_request_json();
    foreach (['action','host','chat_key','request_id'] as $field) if (isset($input[$field]) && !is_string($input[$field])) throw new DomainException('Parametri non validi.',400);
    if (($input['action']??'')==='end') {
        echo json_encode(companionship_end($player,$input['chat_key']??''),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    if (!in_array($input['action']??'',['book','invite'],true)) throw new DomainException('Azione non valida.',422);
    $npc=filter_var($input['npc_id']??null,FILTER_VALIDATE_INT);if ($npc===false || $npc<1) throw new DomainException('Persona non valida.',422);
    $receipt=$input['action']==='invite'?companionship_invite($player,$npc,$input['chat_key']??'',$input['request_id']??''):companionship_book($player,$input['host']??'',$npc,$input['request_id']??'');
    echo json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $status=$e instanceof DomainException?(int)$e->getCode():500;if ($status<400 || $status>499) $status=500;
    http_response_code($status);
    if ($status===500) error_log('[Incontri privati] '.$e->getMessage());
    echo json_encode(['error'=>$status===500?'Prenotazione non riuscita. Nessun addebito aggiuntivo. Riprova.':$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
