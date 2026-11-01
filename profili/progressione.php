<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/profile-art.inc.php';
$viewerId=alphano_require_login();
$kind=($_GET['kind']??'player')==='npc'?'npc':'player';
$raw=$_GET['Id']??$viewerId;
if(!is_scalar($raw)||filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)alphano_fail('Personaggio non valido.',400);
$sheet=['rpg_kind'=>$kind,'rpg_id'=>(int)$raw];
?><!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Caratteristiche e abilità</title></head><body class="ui-page"><main class="rpg-standalone"><header class="ui-page-heading" data-page-heading><span data-icon="swords" aria-hidden="true"></span><div><div class="ui-eyebrow">Alphano · Progressione</div><h1>Caratteristiche e abilità</h1><p>La stirpe, l’esperienza e le tecniche del personaggio.</p></div><a class="action-btn" data-icon="user" href="<?=$kind==='npc'?'/profili/png.php':'/profili/'?>?Id=<?=(int)$raw?>">Profilo</a></header><?php require __DIR__.'/../templates/profiles/progression.php'; ?></main></body></html>
