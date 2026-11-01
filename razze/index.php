<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/race-presentation.inc.php';
alphano_require_login();
$raw=$_GET['id']??null;
if($raw!==null&&(!is_scalar($raw)||filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false))alphano_fail('Razza non valida.',400);
try{$races=alphano_race_view($raw===null?null:(int)$raw,(string)(alphano_user()['sesso']??'Maschio'));}catch(DomainException $e){alphano_fail($e->getMessage(),$e->getCode());}
require __DIR__.'/../templates/races/index.php';
