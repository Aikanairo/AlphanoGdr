<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/treasury.inc.php';
$viewer=alphano_require_login();$input=$_GET;$scope=player_text($input,'scope',20)?:'citta';
try{$id=alphano_treasury_int($input['id']??0);if(!$id){$s=alphano_treasury_scope($scope);$u=alphano_user();$id=$scope==='citta'?(int)(ConnectDB()->query('SELECT city FROM araldiche WHERE id=?',[(int)$u['araldica']])[0]['city']??0):(int)$u[$s['user_scope']];if(!$id&&!empty($u['admin']))$id=(int)(ConnectDB()->query('SELECT id FROM '.$s['table'].' ORDER BY id LIMIT 1')[0]['id']??0);}
    $view=alphano_treasury_view($scope,$id,$viewer);
}catch(DomainException $e){alphano_fail($e->getMessage(),$e->getCode());}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$manage=$view['can_manage'];$s=alphano_treasury_scope($scope);
$dest=$scope==='citta'?'/admin/city.php?city='.$id:($scope==='corporazioni'?'/corporazioni/admin.php?corp='.$id:'/organizzazioni/admin.php?org='.$id);
$rolesUrl=$scope==='citta'?'/admin/cariche.php':$dest.($scope==='corporazioni'?'#iscritti':'#tab-iscritti');
$memberUrl=$scope==='citta'?'/admin/city.php?city='.$id.'#richieste-cittadinanza':$dest.($scope==='corporazioni'?'#iscritti':'#tab-iscritti');
$requestCount=$manage?count(match($scope){'citta'=>GetRichiesteCity($id),'corporazioni'=>GetRichiesteCorp($id),default=>GetRichiesteOrg($id)}):0;
require __DIR__.'/../templates/admin/governance.php';
