<?php
declare(strict_types=1);require_once __DIR__.'/../libs/board-games.inc.php';
player_api(static function(){
 $uid=alphano_require_login(true);if(!bg_ready())throw new DomainException('I giochi da tavolo non sono ancora disponibili.',503);$in=player_input();
 if($_SERVER['REQUEST_METHOD']!=='GET'){player_mutation();if(str_starts_with((string)($in['action']??''),'admin_'))return bg_admin_save($uid,$in);return bg_action($uid,$in);}
 $action=(string)($in['action']??'lobby');
 if($action==='invite_options')return bg_invite_options($uid,rpg_int($in['opponent']??0,1,PHP_INT_MAX),player_text($in,'chat',100));
 if($action==='history')return bg_history(rpg_int($in['player']??$uid,1,PHP_INT_MAX),rpg_int($in['page']??1,1,10000));
 if($action==='ranking')return bg_ranking((string)($in['game']??'scacchi'),rpg_int($in['page']??1,1,10000));
 if($action==='admin'){if(empty(alphano_user()['admin']))throw new DomainException('Riservato alla gestione.',403);return ['settings'=>bg_settings(),'revision'=>(int)ConnectDB()->query('SELECT revision FROM board_game_settings WHERE id=1')[0]['revision'],'rooms'=>ConnectDB()->query('SELECT m.chat_key,m.title AS nome,r.scacchi,r.dama,r.coins,r.items,r.materials FROM mappe m LEFT JOIN board_game_rooms r ON r.chat_key=m.chat_key WHERE m.chat=1 AND m.chat_key<>\'\' ORDER BY m.title'),'active'=>ConnectDB()->query("SELECT id,game_key,chat_key,a,b,status FROM board_games WHERE status IN('pending','active') ORDER BY id DESC LIMIT 100")];}
 if($action==='view'){return EconomiaAtomicaResiliente(function()use($in,$uid){$m=ConnectDB()->query('SELECT * FROM board_games WHERE id=? FOR UPDATE',[rpg_int($in['id']??0,1,PHP_INT_MAX)])[0]??null;if(!$m)throw new DomainException('Partita non trovata.',404);bg_access($m,$uid);if(!bg_can_watch($m,$uid))throw new DomainException('Interagisci narrativamente con uno dei giocatori prima di osservare.',403);bg_expire($m);return bg_view($m,$uid);});}
 return bg_lobby($uid,player_text($in,'chat',100,true));
});
