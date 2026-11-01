<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/commerce-thread.inc.php';
player_api(static function():array{
 $uid=alphano_require_login(true);$in=player_input();$id=CommercioIntero($in['id']??null,1,2147483647);
 if(!$id)throw new DomainException('Trattativa non valida.',400);
 if($_SERVER['REQUEST_METHOD']==='GET')$t=alphano_trade_thread($id,$uid);
 else{player_mutation();$t=alphano_trade_thread_act($id,$uid,$in);}
 $t['progress']=alphano_trade_summary($t);return $t;
});
