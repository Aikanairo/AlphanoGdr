<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/ai.inc.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
    ai_admin();if(!ai_local_model_config())throw new DomainException('Funzione disponibile solo nella copia locale abilitata.',404);
    $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))throw new DomainException('Metodo non consentito.',405);
    if($method==='POST')alphano_require_csrf(true);
    $body=$method==='POST'?alphano_request_json():null;session_write_close();
    echo json_encode(ai_local_model_call($method,$method==='POST'?'/switch':'/status',$body),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $error){$status=$error instanceof DomainException?$error->getCode():500;if($status<400||$status>599)$status=500;http_response_code($status);if($status===500)error_log('[Modelli locali] '.$error->getMessage());echo json_encode(['error'=>$status===500?'Controllore locale non disponibile: modello attuale conservato.':$error->getMessage()],JSON_UNESCAPED_UNICODE);}
