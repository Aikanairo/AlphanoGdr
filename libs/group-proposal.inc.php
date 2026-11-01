<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';
require_once __DIR__.'/libaccademia.inc.php';
require_once __DIR__.'/libequip.inc.php';
require_once __DIR__.'/libposta.inc.php';
$idpg=alphano_require_login();
$db=ConnectDB();
$isCorporation=$proposalKind==='corp';
$title=$isCorporation?'Proponi una corporazione':'Proponi un’organizzazione';
$listUrl=$isCorporation?'/corporazioni/':'/organizzazioni/';
$table=$isCorporation?'corporazioni':'organizzazioni';
$pending=$db->query("SELECT nome FROM $table WHERE idmaster=? AND approvato=0 LIMIT 1",[$idpg]);
$cities=$db->query('SELECT id,city FROM city ORDER BY city');
$subtypes=$isCorporation?$db->query('SELECT id,nome FROM subtipi ORDER BY id'):[];
$categories=[];if($isCorporation)foreach($db->query('SELECT subtipo,nome FROM tipo WHERE subtipo>0 ORDER BY nome') as $row)$categories[(int)$row['subtipo']][]=$row['nome'];
$quality=QUALITY;$maxPoints=MAX_POINT_VALUE;
$message='';$saved=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    alphano_require_csrf();
    foreach($_POST as $value)if(!is_string($value))alphano_fail('Parametri non validi.',400);
    try{
        $name=trim($_POST['nome']??'');$site=trim($_POST['sito']??'');$statute=trim($_POST['statuto']??'');
        if(mb_strlen($site)>255)throw new DomainException('L’indirizzo del sito è troppo lungo.');
        if($site!==''&&(!filter_var($site,FILTER_VALIDATE_URL)||!in_array(strtolower(parse_url($site,PHP_URL_SCHEME)??''),['http','https'],true)))throw new DomainException('Indica un sito HTTP o HTTPS valido.');
        if(trim(html_entity_decode(strip_tags($statute),ENT_QUOTES|ENT_HTML5,'UTF-8'))==='')throw new DomainException('Scrivi lo statuto del gruppo.');
        if(mb_strlen($statute)>60000)throw new DomainException('Lo statuto è troppo lungo.');
        $productions=[];foreach($subtypes as $row)$productions[(int)$row['id']]=(int)($_POST['produzione'.$row['id']]??0);
        $message=$isCorporation?InsertCorp($name,$idpg,$site?:null,$statute,(int)($_POST['city']??0),$productions):InsertOrg($name,$idpg,$site?:null,$statute,(int)($_POST['city']??0));
        $saved=(bool)$db->query("SELECT id FROM $table WHERE nome=? AND idmaster=? AND approvato=0",[$name,$idpg]);
    }catch(DomainException $e){$message=$e->getMessage();}catch(Throwable $e){error_log('[Proposta gruppo] '.$e->getMessage());$message='Impossibile inviare la proposta. Riprova.';}
}
require __DIR__.'/../templates/groups/proposal.php';
