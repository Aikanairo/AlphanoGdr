<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/race-presentation.inc.php';
player_api(static function():array {
 alphano_require_login(true);
 $race=filter_var($_GET['race']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$offset=filter_var($_GET['offset']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000]]);
 if($race===false||$offset===false)throw new DomainException('Pagina non valida.',422);
 if(!ConnectDB()->query('SELECT id FROM razze WHERE id=?',[$race]))throw new DomainException('Razza non trovata.',404);
 $items=alphano_race_members($race,$offset,20)[$race]??[];$total=(int)(alphano_race_member_counts()[$race]??0);$next=$offset+count($items);
 return ['items'=>$items,'total'=>$total,'next'=>$next<$total?$next:null];
});
