<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/pvt/rentals.inc.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
try{
 $uid=alphano_require_login(true);
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $key=$_GET['chat']??'';if(!is_string($key))throw new DomainException('Camera non valida.',400);
  $r=alphano_rental_info($key,$uid);if(!$r)throw new DomainException('Camera non disponibile.',403);
  echo json_encode(['expires_at'=>(int)$r['expires_at'],'server_time'=>time(),'expired'=>(bool)$r['closed_at'],'host_key'=>$r['host_key'],'host_title'=>$r['host_title']],JSON_UNESCAPED_UNICODE);exit;
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new DomainException('Metodo non consentito.',405);alphano_require_csrf(true);$in=alphano_request_json();
 foreach(['host','word','action','request_id'] as $field)if(isset($in[$field])&&!is_string($in[$field]))throw new DomainException('Parametri non validi.',400);
 $hours=filter_var($in['hours']??1,FILTER_VALIDATE_INT);if($hours===false)throw new DomainException('Durata non valida.',422);
 echo json_encode(alphano_rental_enter($uid,$in['host']??'',$in['word']??'',$in['action']??'',$hours,$in['request_id']??''),JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){$status=$e instanceof DomainException?$e->getCode():500;http_response_code($status>=400&&$status<=499?$status:500);if($status===500)error_log('[Affitto camere] '.$e->getMessage());echo json_encode(['error'=>$status===500?'Operazione non riuscita. Riprova.':$e->getMessage()],JSON_UNESCAPED_UNICODE);}
