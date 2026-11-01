<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/treasury.inc.php';
player_api(static function():array{
    $actor=alphano_require_login(true);$input=player_input();$scope=player_text($input,'scope',20,true);$id=alphano_treasury_int($input['id']??null,1);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        player_mutation();$action=player_text($input,'action',30,true);
        match($action){
            'save'=>alphano_treasury_save($scope,$id,$actor,$input),
            'payroll'=>alphano_treasury_payroll($scope,$id,$actor,player_text($input,'request_id',100,true)),
            'donate'=>alphano_treasury_donate($scope,$id,$actor,alphano_treasury_int($input['amount']??null,1),player_text($input,'request_id',100,true),player_text($input,'reason',300)?:'Donazione'),
            'mandate'=>alphano_treasury_donation_mandate($scope,$id,$actor,$input),
            default=>throw new DomainException('Operazione non valida.',422)
        };
    }
    return alphano_treasury_view($scope,$id,$actor)+['csrfToken'=>alphano_csrf_token()];
});
