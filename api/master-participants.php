<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/session-participants.inc.php';
player_api(static function():array{
    $actor=alphano_require_login(true);$method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true))throw new DomainException('Metodo non consentito.',405);
    $input=$method==='POST'?player_input():$_GET;$chat=player_text($input,'chat',100,true);
    if($method==='POST'){player_mutation();return alphano_participants_mutate($actor,$chat,$input);}
    return match(player_text($input,'action',20)?:'state'){
        'state'=>alphano_participants_state($actor,$chat),
        'catalog'=>alphano_participants_catalog($actor,$chat,$input),
        'inventory'=>alphano_participants_inventory($actor,$chat,alphano_participants_int($input,'target',1,PHP_INT_MAX)),
        default=>throw new DomainException('Sezione non disponibile.',422),
    };
});
