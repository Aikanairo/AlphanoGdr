<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/kernel.inc.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$method=$_SERVER['REQUEST_METHOD'];
if($method==='GET'){echo json_encode(['version'=>ALPHANO_PRIVACY_VERSION,'externalMedia'=>alphano_external_media_allowed()]);exit;}
if($method!=='POST')alphano_fail('Metodo non consentito.',405,true);
alphano_require_csrf(true);
try{$input=alphano_request_json();}catch(Throwable){alphano_fail('Richiesta non valida.',400,true);}
if(($input['version']??null)!==ALPHANO_PRIVACY_VERSION||!is_bool($input['externalMedia']??null))alphano_fail('Preferenze non valide: ricarica la pagina.',400,true);
$id=bin2hex(random_bytes(16));$now=time();$expires=$now+15552000;$allowed=$input['externalMedia'];$db=ConnectDB();
$db->execute('DELETE FROM privacy_preference_receipts WHERE expires_at<?',[$now]);
$db->execute('INSERT INTO privacy_preference_receipts(id,version,external_media,created_at,expires_at) VALUES (?,?,?,?,?)',[$id,ALPHANO_PRIVACY_VERSION,$allowed?1:0,$now,$expires]);
setcookie('alphano_privacy','v1.'.($allowed?'media':'essential').'.'.$id,['expires'=>$expires,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>false,'samesite'=>'Lax']);
echo json_encode(['saved'=>true,'externalMedia'=>$allowed,'version'=>ALPHANO_PRIVACY_VERSION]);
