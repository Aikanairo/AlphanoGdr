<?php
declare(strict_types=1);
require_once __DIR__.'/online-presence.inc.php';
function bg_online_player(int $id):array{$u=ConnectDB()->query('SELECT id,nome,posizione,lastmove,bloccato FROM utenti WHERE id=?',[$id])[0]??[];if(!alphano_player_online($u))throw new DomainException('Il giocatore non è più online. Aggiorna i presenti.',409);return $u;}
function bg_invite_lifetime(array $m):int{return !empty(rpg_decode($m['terms_json'])['presence_invite'])?600:(int)bg_settings()['invite_seconds'];}
function bg_access(array $m,int $uid):void{
 if($m['status']!=='active'&&!empty(rpg_decode($m['terms_json'])['presence_invite'])&&in_array($uid,[(int)$m['a'],(int)$m['b']],true)){require_once __DIR__.'/../chat/libs/http-chat.php';httpChatRoom($m['chat_key'],$uid);return;}bg_actor($uid,$m['chat_key']);
}
function bg_invite_options(int $uid,int $other,string $chat=''):array{
 if($uid===$other)throw new DomainException('Scegli un altro giocatore.',422);bg_online_player($uid);$opponent=bg_online_player($other);$s=bg_settings();if(!$s['enabled'])throw new DomainException('I giochi sono disabilitati.',403);
 $rooms=[];require_once __DIR__.'/../chat/libs/http-chat.php';foreach(ConnectDB()->query("SELECT m.chat_key,m.title,r.scacchi,r.dama FROM mappe m JOIN board_game_rooms r ON r.chat_key=m.chat_key WHERE m.chat=1 AND (r.scacchi=1 OR r.dama=1) ORDER BY m.title") as $r){if(!($s['scacchi']&&$r['scacchi'])&&!($s['dama']&&$r['dama']))continue;try{httpChatRoom($r['chat_key'],$uid);httpChatRoom($r['chat_key'],$other);$rooms[]=$r;}catch(DomainException){}}
 if(!$rooms)throw new DomainException('Nessun luogo di gioco disponibile per entrambi.',403);$chat=$chat?:$rooms[0]['chat_key'];if(!in_array($chat,array_column($rooms,'chat_key'),true))throw new DomainException('Luogo non disponibile.',403);
 return ['viewer'=>$uid,'opponent'=>$other,'rooms'=>$rooms,'chat'=>$chat,'settings'=>$s,'room'=>bg_room($chat),'players'=>[['id'=>$other,'nome'=>$opponent['nome']]],'inventory'=>bg_inventory($uid),'games'=>[],'npcs'=>[]];
}
