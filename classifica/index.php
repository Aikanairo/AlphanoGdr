<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/rankings.inc.php';
$viewer=alphano_require_login();$metric=$_GET['categoria']??'caratteristiche';$page=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
if(!is_string($metric)||$page===false)alphano_fail('Classifica non valida.',400);
if($metric==='giochi'){require_once __DIR__.'/../libs/board-games.inc.php';if(!bg_ready())alphano_fail('Giochi in preparazione.',503);require __DIR__.'/../templates/board-games/ranking.php';exit;}
try{$ranking=alphano_ranking_view($metric,$page,$viewer);}catch(DomainException $e){alphano_fail($e->getMessage(),$e->getCode());}
require __DIR__.'/../templates/rankings/index.php';
