<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/player-runtime.inc.php';
require_once dirname(__DIR__).'/libs/experience.inc.php';
player_api(function():array{
 $user=alphano_require_login(true);
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $experienceSettings=alphano_experience_settings($user);ob_start();require dirname(__DIR__).'/templates/partials/game-preferences.php';$form=ob_get_clean();
  return ['settings'=>$experienceSettings,'environment'=>alphano_map_environment(),'confirmed'=>alphano_experience_confirmed($user),'form'=>$form];
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')alphano_fail('Metodo non consentito.',405,true);
 alphano_require_csrf(true);$input=alphano_request_json();
 if(!isset($input['settings'])||!is_array($input['settings'])||array_is_list($input['settings']))throw new DomainException('Preferenze non valide.',400);
 $confirm=$input['confirm']??false;if(!is_bool($confirm))throw new DomainException('Conferma non valida.',400);
 if($confirm&&array_diff(array_filter(array_diff(array_keys(alphano_experience_defaults()),['relationship_effects','chess_skin']),static fn($k)=>!str_starts_with($k,'email_')),array_keys($input['settings'])))throw new DomainException('Completa tutte le preferenze prima di confermare.',400);
 return ['settings'=>alphano_experience_save($user,$input['settings'],$confirm),'confirmed'=>alphano_experience_confirmed($user)];
});
