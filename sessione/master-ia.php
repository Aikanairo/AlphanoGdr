<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/ai.inc.php';alphano_require_login();$u=alphano_user();
if(empty($u['admin'])&&empty($u['fatepower']))alphano_fail('Accesso riservato ai Master.',403);
if(empty($u['admin'])){header('Location: /sessione/sessione.php',true,303);exit;}
$state=ai_game_session($u['chat']);if(!$state){?><!doctype html><html lang="it"><head><meta charset="utf-8"><title>Regia della sessione</title></head><body><main><h1>Regia della sessione</h1><p>In questo luogo non è aperta una quest. Puoi aprire una sessione umana dal pannello di regia; le funzioni automatiche richiedono anche l’attivazione del servizio IA.</p><a class="action-btn" href="/sessione/sessione.php">Apri il pannello di regia</a></main></body></html><?php exit;}
$session=$state['session'];$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
require __DIR__.'/../templates/sessions/master-ia.php';
