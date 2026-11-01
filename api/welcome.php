<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/welcome.inc.php';
player_api(static function(): array {
    $user=alphano_require_login(true);
    if ($_SERVER['REQUEST_METHOD']==='GET') return ['message'=>alphano_welcome_message($user),'csrfToken'=>alphano_csrf_token()];
    player_mutation(); $input=player_input();
    if (player_text($input,'action',12,true)!=='read') throw new DomainException('Operazione non valida.',400);
    $revision=filter_var($input['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($revision===false) throw new DomainException('Versione del messaggio non valida.',422);
    return ['success'=>true,'message'=>alphano_welcome_read($user,$revision)];
});
