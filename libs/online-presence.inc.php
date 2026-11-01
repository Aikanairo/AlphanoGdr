<?php
declare(strict_types=1);
/** Allow throttled background tabs and brief mobile reconnections; location alone is not a connection. */
const ALPHANO_PRESENCE_TTL=300;
function alphano_player_online(array $player):bool{return isset($player['id'])&&(int)$player['id']>0&&($player['posizione']??'off')!=='off'&&(int)($player['lastmove']??0)>=time()-ALPHANO_PRESENCE_TTL&&empty($player['bloccato']);}

/** Authenticated browser heartbeat. A resumed tab can return after stale-location cleanup. */
function alphano_presence_touch(int $id):void {
    if($id<=0||!empty($_SESSION['support_session_id']))return;
    ConnectDB()->execute("UPDATE utenti SET lastmove=?, posizione=CASE WHEN posizione='off' THEN 'online' ELSE posizione END WHERE id=?",[time(),$id]);
}
