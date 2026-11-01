<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';require_once __DIR__.'/../libs/user-support.inc.php';require_once __DIR__.'/../libs/online-presence.inc.php';require_once __DIR__.'/../libs/profile-art.inc.php';
$uid=alphano_require_login();$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 alphano_require_csrf();
 try{
  if(($_POST['action']??'')==='stop'&&!empty($_SESSION['support_session_id']))alphano_support_finish();
  else{if(empty(alphano_user()['admin']))alphano_fail('Riservato agli amministratori.',403);alphano_support_start((int)($_POST['target']??0),(string)($_POST['reason']??''));}
  header('Location: /main.php',true,303);exit;
 }catch(DomainException $e){$error=$e->getMessage();}
}
if(empty(alphano_user()['admin']))alphano_fail('Riservato agli amministratori.',403);
$q=trim(is_string($_GET['q']??'')?($_GET['q']??''):'');$online=($_GET['online']??'1')==='1';$params=[];$where='1';
if($q!==''){$where.=' AND nome LIKE ?';$params[]='%'.addcslashes($q,'%_\\').'%';}
if($online){$where.=" AND posizione<>'off' AND lastmove>=? AND bloccato=0";$params[]=time()-ALPHANO_PRESENCE_TTL;}
$users=ConnectDB()->query('SELECT id,nome,admin,bloccato,posizione,lastmove,razza,vampire_human_form,sesso,avatar,avatar2 FROM utenti WHERE '.$where.' ORDER BY nome LIMIT 100',$params);
foreach($users as &$person)$person['portrait']=alphano_profile_portrait($person);unset($person);
$logs=ConnectDB()->query('SELECT s.*,a.nome AS actor_name,t.nome AS target_name FROM support_sessions s LEFT JOIN utenti a ON a.id=s.actor LEFT JOIN utenti t ON t.id=s.target ORDER BY s.id DESC LIMIT 100');
$selected=max(0,(int)($_GET['session']??0));$audit=$selected?ConnectDB()->query('SELECT * FROM support_audit WHERE support_id=? ORDER BY id DESC LIMIT 200',[$selected]):[];
require __DIR__.'/../templates/admin/user-support.php';
