<?php
declare(strict_types=1);
require_once __DIR__.'/player-runtime.inc.php';
require_once __DIR__.'/ai-profile.inc.php';
require_once __DIR__.'/media-assets.inc.php';
require_once __DIR__.'/rpg-combat.inc.php';
require_once __DIR__.'/ai-game.inc.php';
require_once __DIR__.'/ai-conductor.inc.php';
require_once __DIR__.'/transformations.inc.php';

require_once __DIR__.'/online-presence.inc.php';
/** Presenze pubbliche: un solo contratto per sidebar e directory. Nessun dato privato dei PNG. */
function alphano_presence(): array
{
    $db=ConnectDB();
    $races=array_column($db->query('SELECT id,nome FROM razze'),'nome','id');
    $groups=[];
    $sessions=array_column($db->query('SELECT chat,MAX(id) AS id FROM sessioni WHERE run=1 GROUP BY chat'),'id','chat');
    $viewer=alphano_user();$myTurn=null;
    if(!in_array((string)$viewer['posizione'],['','off','online'],true)&&isset($sessions[$viewer['chat']])){
      $sid=(int)$sessions[$viewer['chat']];
      $g=$db->query('SELECT g.*,q.status AS quest_status,q.conductor_id FROM ai_session_guidance g JOIN ai_quests q ON q.session_id=g.session_id WHERE g.session_id=?',[$sid])[0]??null;
      if($g){$window=ai_turn_window(['session_id'=>$sid,'status'=>$g['quest_status'],'conductor_id'=>$g['conductor_id']],$g,(int)$viewer['id']);if($window['mine'])$myTurn=$window;}
    }
    foreach ($db->query("SELECT chat_key,title,scene_thumbnail FROM mappe WHERE chat=1 AND chat_key<>'' ORDER BY title") as $room) {
        $groups[$room['chat_key']]=['key'=>$room['chat_key'],'title'=>$room['title'],'url'=>'/chat/index.php?chat='.rawurlencode($room['chat_key']),
            'image'=>alphano_media_file($room['scene_thumbnail']??'')?$room['scene_thumbnail']:null,'session_active'=>isset($sessions[$room['chat_key']]),'my_turn'=>$room['chat_key']===($viewer['chat']??'')?$myTurn:null,'players'=>0,'npcs'=>0,'members'=>[]];
    }
    $person=static function(string $kind,int $id,string $name,int $race,string $sex) use ($races): array {
        if ($race===ALPHANO_RACE_LEGACY_SPIRIT) $race=ALPHANO_RACE_SPIRIT;
        return ['kind'=>$kind,'id'=>$id,'name'=>$name,'profile_url'=>($kind==='npc'?'/profili/png.php?Id=':'/profili/?Id=').$id,
            'icon'=>alphano_race_icon($race,$sex),'race'=>$races[$race]??'','sex'=>$sex];
    };
    $players=0;$npcs=0;
    foreach ($db->query("SELECT id,nome,razza,vampire_human_form,sesso,posizione,chat,lastmove,bloccato FROM utenti WHERE posizione<>'off' ORDER BY nome") as $user) {
        if(!alphano_player_online($user))continue;
        $key=$user['posizione']==='online'?'@parked':(isset($groups[$user['chat']])?$user['chat']:'@other');
        if (!isset($groups[$key])) $groups[$key]=['key'=>$key,'title'=>$key==='@parked'?'Fuori scena':'Altri luoghi e stanze riservate','url'=>null,'image'=>null,'session_active'=>false,'players'=>0,'npcs'=>0,'members'=>[]];
        $member=$person('player',(int)$user['id'],$user['nome'],alphano_visible_race($user),$user['sesso']);$member['game_invite_visible']=(int)$user['id']!==(int)$viewer['id'];$member['can_invite']=alphano_player_online($user)&&(int)$user['id']!==(int)$viewer['id'];$groups[$key]['members'][]=$member;
        $groups[$key]['players']++;$players++;
    }
    foreach ($db->query('SELECT n.id,n.name,n.chat_key,n.profile FROM ai_npcs n JOIN mappe m ON m.chat_key=n.chat_key COLLATE utf8mb4_unicode_ci WHERE n.active=1 AND m.chat=1 AND NOT EXISTS(SELECT 1 FROM companion_appointments a WHERE a.active_npc_id=n.id AND a.finished_at=0 AND a.expires_at>?) ORDER BY n.name',[time()]) as $npc) {
        if (!isset($groups[$npc['chat_key']])) continue;
        $p=ai_npc_profile($npc);
        $groups[$npc['chat_key']]['members'][]=$person('npc',(int)$npc['id'],$npc['name'],(int)$p['race_id'],$p['sex']);
        $groups[$npc['chat_key']]['npcs']++;$npcs++;
    }
    $places=array_values($groups);usort($places,static fn($a,$b)=>(int)$b['session_active']<=>(int)$a['session_active']);
    return ['players'=>$players,'npcs'=>$npcs,'total'=>$players+$npcs,'places'=>$places];
}

/** The server-rendered directory uses the same public location metadata as the sidebar. */
function alphano_presence_heading_html(array $place): string
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $image=$place['image']?'<img class="presence-place-image" src="'.$h($place['image']).'" alt="" width="40" height="30" loading="lazy">':'';
    $heading=$place['url']?'<a class="presence-place-link" href="'.$h($place['url']).'">'.$image.'<span>'.$h($place['title']).'</span></a>':'<span class="presence-place-link">'.$h($place['title']).'</span>';
    return $heading.(!empty($place['my_turn'])?'<span class="presence-my-turn"><span class="presence-session-dot" aria-hidden="true"></span>È il tuo turno di gioco · <time data-game-deadline="'.(int)$place['my_turn']['deadline'].'" data-server-time="'.(int)$place['my_turn']['server_time'].'">'.(int)$place['my_turn']['remaining'].'s</time></span>':'').($place['session_active']?'<span class="presence-session"><span class="presence-session-dot" aria-hidden="true"></span>Sessione in corso</span>':'');
}

/** An inactive invitation icon explains stale presence without permitting an invite. */
function alphano_presence_game_html(array $member):string {
 if(empty($member['game_invite_visible'])||$member['kind']!=='player')return '';
 $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$label=!empty($member['can_invite'])?'Invita '.$member['name'].' a Scacchi o Dama':'Invito non disponibile: '.$member['name'].' non ha una connessione attiva';
 $art='<img src="/assets/placeholder.svg" alt="" width="36" height="36">';
 return !empty($member['can_invite'])?'<a class="presence-game-btn" data-presence-game href="/giochi/invita.php?opponent='.(int)$member['id'].'" title="'.$h($label).'" aria-label="'.$h($label).'">'.$art.'</a>':'<button type="button" class="presence-game-btn is-unavailable" disabled aria-disabled="true" title="'.$h($label).'" aria-label="'.$h($label).'">'.$art.'</button>';
}
