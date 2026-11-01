<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/transformations.inc.php';
player_api(function():array {
    $id=alphano_require_login(true);
    header('Cache-Control: private, no-store');
    if ($_SERVER['REQUEST_METHOD']==='GET') return ['transformation'=>alphano_transformation_sync($id)];
    player_mutation();$input=player_input();
    return ['success'=>true,'transformation'=>alphano_transformation_sync($id,player_text($input,'form',10,true))];
});
