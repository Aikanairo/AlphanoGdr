<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/session-control.inc.php';
$uid=alphano_require_login();$u=alphano_user();
$chat=$_GET['chat']??($u['chat']??'');
try{
 if(!is_string($chat))throw new DomainException('Luogo non valido.',400);
 if($chat===''||$chat==='off'){
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'||isset($_GET['status']))throw new DomainException('Entra in un luogo prima di dirigere la sessione.',409);
  if(empty($u['admin'])&&empty($u['fatepower'])||!empty($u['bloccato']))throw new DomainException('Accesso riservato ai Master.',403);
  $locations=ai_db()->query("SELECT m.chat_key,m.title,s.nome AS session_name,CASE WHEN q.mode='auto' THEN 'Master IA' ELSE COALESCE(conductor.nome,opener.nome,'Nessuna regia') END AS master_name FROM mappe m LEFT JOIN sessioni s ON s.id=(SELECT MAX(x.id) FROM sessioni x WHERE BINARY x.chat=BINARY m.chat_key AND x.run=1) LEFT JOIN ai_quests q ON q.session_id=s.id LEFT JOIN utenti conductor ON conductor.id=COALESCE(NULLIF(q.conductor_id,0),NULLIF(s.conductor_id,0)) LEFT JOIN utenti opener ON opener.id=s.masterin WHERE m.chat=1 AND m.chat_key<>'' ORDER BY s.id IS NULL,m.title");
  $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  require __DIR__.'/../templates/sessions/location-picker.php';exit;
 }
 $actor=alphano_session_actor($uid,$chat,false);
 $method=$_SERVER['REQUEST_METHOD']??'GET';
 if(!in_array($method,['GET','POST'],true))throw new DomainException('Metodo non consentito.',405);
 if($method==='POST'){
  alphano_require_csrf(true);
  $input=str_contains($_SERVER['CONTENT_TYPE']??'','application/json')?alphano_request_json():$_POST;
  $action=$input['azione']??$input['action']??'';
  if(!is_string($action))throw new DomainException('Comando non valido.',422);
  $result=alphano_session_control_action($uid,$chat,$action,$input);
  header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
  echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
 }
 $state=alphano_session_control_state($uid,$chat);
 if(isset($_GET['status'])){
  header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
  echo json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
 }
 $row=alphano_session_current($chat);$quest=ai_quest_row($chat);$room=ai_room($chat);
 $posizione=ai_db()->query('SELECT title FROM mappe WHERE chat_key=?',[$chat])[0]['title']??$chat;
 $nomeMaster=$state['conductor']['name'];$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 require __DIR__.'/../templates/sessions/manager.php';
}catch(Throwable $error){
 $code=(int)$error->getCode();
 $status=($error instanceof DomainException||($error instanceof RuntimeException&&in_array($code,[400,409,422],true)))?$code:($error instanceof JsonException?400:500);
 if(!in_array($status,[400,401,403,404,405,409,422,429,503],true))$status=500;
 if($status===500){error_log('[Session control] '.$error->getMessage());alphano_error_reference($error,'session-control');}
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'||isset($_GET['status'])){
  http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
  echo json_encode(['error'=>$status===500?'Operazione non completata. Riprova.':$error->getMessage()],JSON_UNESCAPED_UNICODE);
 }else alphano_fail($status===500?'Il pannello non è momentaneamente disponibile.':$error->getMessage(),$status);
}
