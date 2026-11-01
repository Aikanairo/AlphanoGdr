<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/elections.inc.php';
require_once __DIR__.'/../libs/profile-art.inc.php';
$viewer=alphano_require_login();$raw=$_GET['city']??0;if(!is_scalar($raw)||filter_var($raw,FILTER_VALIDATE_INT)===false||(int)$raw<1)alphano_fail('Città non valida.',400);
try{$election=alphano_elections_state((int)$raw,$viewer);}catch(DomainException $e){alphano_fail($e->getMessage(),(int)$e->getCode());}
require __DIR__.'/../templates/cities/elections.php';ClosePage();
