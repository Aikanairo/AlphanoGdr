<?php
declare(strict_types=1);
require_once __DIR__.'/../../libs/elections.inc.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
try{
 $viewer=alphano_require_login(true);$method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))throw new DomainException('Metodo non consentito.',405);
 if($method==='GET'&&($_GET['summary']??'')==='1'){echo json_encode(['summaries'=>array_values(alphano_elections_summaries())],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
 $input=$method==='POST'?(str_contains($_SERVER['CONTENT_TYPE']??'','application/json')?alphano_request_json():$_POST):$_GET;
 $raw=$input['city']??0;if(!is_scalar($raw)||filter_var($raw,FILTER_VALIDATE_INT)===false||(int)$raw<1)throw new DomainException('Città non valida.',400);$city=(int)$raw;$receipt=null;
 if($method==='POST'){
  alphano_require_csrf(true);$action=$input['action']??'';if(!is_string($action))throw new DomainException('Azione non valida.',400);
  if($action==='candidate'){$program=$input['program']??'';$expected=$input['round_id']??0;if(!is_string($program)||!is_scalar($expected)||filter_var($expected,FILTER_VALIDATE_INT)===false||(int)$expected<0)throw new DomainException('Programma o tornata non validi.',422);$receipt=alphano_elections_candidate($city,$viewer,$program,null,(int)$expected);}
  elseif($action==='vote'){$id=$input['candidate']??null;$round=$input['round_id']??null;$ballot=$input['ballot']??null;foreach([$id,$round,$ballot] as $value)if(!is_scalar($value)||filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<1)throw new DomainException('Candidato o turno di voto non validi.',422);$receipt=alphano_elections_vote($city,$viewer,(int)$id,null,(int)$round,(int)$ballot);}
  elseif($action==='acknowledge'){alphano_elections_acknowledge($viewer,$city);$receipt=['message'=>'Invito letto.'];}
  else throw new DomainException('Azione non riconosciuta.',400);
 }
 echo json_encode(['state'=>alphano_elections_state($city,$viewer),'receipt'=>$receipt],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){$status=$e instanceof DomainException?(int)$e->getCode():500;if($status<400||$status>599)$status=500;http_response_code($status);if($status===500)error_log('[elezioni] '.$e);echo json_encode(['error'=>$status===500?'Operazione non riuscita. Riprova.':$e->getMessage()],JSON_UNESCAPED_UNICODE);}
