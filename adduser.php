<?php
declare(strict_types=1);
require_once __DIR__.'/libs/kernel.inc.php';require_once __DIR__.'/libs/rpg.inc.php';require_once __DIR__.'/libs/libregister.inc.php';require_once __DIR__.'/libs/sendmail.inc.php';
if($_SERVER['REQUEST_METHOD']!=='POST')alphano_fail('Metodo non consentito.',405);alphano_require_csrf();if(!alphano_config('registration_enabled'))alphano_fail('Registrazioni chiuse.',403);
$name=trim(is_string($_POST['pgName']??null)?$_POST['pgName']:'');$email=trim(is_string($_POST['mail']??null)?$_POST['mail']:'');$pass=is_string($_POST['passwd']??null)?$_POST['passwd']:'';$sex=$_POST['sesso']??'';
if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,19}$/D',$name)||!valido($name)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>100||strlen($pass)<12||strlen($pass)>72||$pass!==($_POST['passwdConfirm']??null)||!in_array($sex,['Maschio','Femmina'],true)||($_POST['adult']??'')!=='1'||($_POST['terms']??'')!=='1')alphano_fail('Controlla nome, email, password, maggiore età e condizioni.',422);
$defaultRace=alphano_human_race_id();if($defaultRace<1)alphano_fail('La gestione deve configurare una razza iniziale attiva prima di aprire le registrazioni.',403);
$ethnicity=0;if(alphano_config('ethnicities_enabled')){$ethnicity=(int)($_POST['ethnicity']??0);if(!ConnectDB()->query('SELECT id FROM etnie WHERE id=?',[$ethnicity]))alphano_fail('Seleziona una etnia valida.',422);}
$points=(int)alphano_config('initial_points');$allocation=$_POST['allocation']??[];try{if(!is_array($allocation)||count($allocation)!==8||array_diff(array_keys($allocation),RPG_STATS))throw new DomainException('Distribuisci i punti iniziali.');foreach($allocation as $k=>$v)$allocation[$k]=rpg_int($v,0,$points,'Punti');if(array_sum($allocation)!==$points)throw new DomainException('La somma dei punti deve essere '.$points.'.');}catch(DomainException $e){alphano_fail($e->getMessage(),422);}
$db=ConnectDB();$locked=false;$ip=(string)($_SERVER['REMOTE_ADDR']??'');$lock='alphano:register:'.substr(hash('sha256',alphano_config('db_db').':'.$ip),0,40);
try{
 $locked=(int)$db->query('SELECT GET_LOCK(?,5) AS acquired',[$lock])[0]['acquired']===1;if(!$locked)throw new DomainException('Registrazione in corso. Riprova tra poco.');
 $db->beginTransaction();if(!alphano_registration_is_local()&&$db->query('SELECT 1 FROM logs_registrazione WHERE ip=? AND data>=? LIMIT 1',[$ip,strtotime('today')]))throw new DomainException('È consentita una registrazione al giorno per indirizzo.');
 if($db->query('SELECT id FROM utenti WHERE nome=? OR email=? LIMIT 1',[$name,$email]))throw new DomainException('Nome o email già registrati.');
 $db->execute('INSERT INTO utenti(nome,password,email,razza,sesso,etnia,araldica,motivo,dataregistrazione) VALUES(?,?,?,?,?,?,0,?,?)',[$name,password_hash($pass,PASSWORD_DEFAULT),$email,$defaultRace,$sex,$ethnicity,'',time()]);$id=(int)$db->lastInsertId();
 rpg_complete_creation('pg',$id,$allocation,[],'registration:'.$id,$id);
 $legal=require __DIR__.'/config/legal.php';$db->execute('INSERT INTO user_policy_acceptances(user_id,policy_version,accepted_at,adult_confirmed,terms_accepted,ai_policy_accepted) VALUES(?,?,?,1,1,?)',[$id,$legal['version'],time(),alphano_config('ai_enabled')?1:0]);
 $db->execute('INSERT INTO logs_registrazione(data,nome,id,ip,host) VALUES(?,?,?,?,?)',[time(),$name,$id,$ip,'']);$db->commit();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();alphano_fail($e instanceof DomainException?$e->getMessage():'Registrazione non completata.',409);}finally{if($locked)$db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
$sent=sendMail($email,$name,'','');
?><!doctype html><html lang="it"><head><meta charset="utf-8"><title>Registrazione completata</title></head><body class="portal"><main class="portal-wrap"><h1>Il tuo personaggio è pronto.</h1><p>Accedi con il nome e la password che hai scelto. <?=$sent?'La conferma email è stata elaborata.':'La conferma email non è stata inviata; puoi già accedere.'?></p><a href="/">Torna all’accesso</a></main></body></html>
