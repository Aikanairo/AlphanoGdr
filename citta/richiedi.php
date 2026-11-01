<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/libposta.inc.php';
require_once __DIR__.'/../libs/activity.inc.php';
$actor=alphano_require_login();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');alphano_fail('Metodo non consentito.',405);}
alphano_require_csrf();
$raw=$_POST['city']??null;
if(!is_scalar($raw)||filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)alphano_fail('Città non valida.',400);
$city=(int)$raw;$db=ConnectDB();$db->beginTransaction();
try{
 $user=$db->query('SELECT id,araldica FROM utenti WHERE id=? FOR UPDATE',[(int)$actor])[0]??null;
 $target=$db->query('SELECT id,city FROM city WHERE id=?',[$city])[0]??null;
 if(!$target)throw new DomainException('Città non trovata.',404);
 $current=(int)($db->query('SELECT city FROM araldiche WHERE id=?',[(int)($user['araldica']??0)])[0]['city']??0);
 if($current===$city)throw new DomainException('Appartieni già a questa città.',409);
 if(GetRichiesteCityPG((int)$actor)>0)throw new DomainException('Hai già una richiesta di cittadinanza in attesa.',409);
 MakeRichiestaCity($city,(int)$actor);
 alphano_record_activity('city:application',(int)$actor,[['scope'=>'citta','id'=>$city,'role'=>'admin','section'=>'cittadinanza'],['scope'=>'personale','id'=>(int)$actor,'role'=>'member','section'=>'cittadinanza']],'Richiesta di cittadinanza','Richiesto ingresso a '.$target['city'],['city'=>$city]);
 $db->commit();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof DomainException)alphano_fail($e->getMessage(),$e->getCode());throw $e;}
header('Location: /citta/?richiesta=inviata',true,303);exit;
