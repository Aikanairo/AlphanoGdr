<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/ai-profile.inc.php';
require_once __DIR__.'/../libs/race-presentation.inc.php';
require_once __DIR__.'/../libs/player-labels.inc.php';

require_once __DIR__.'/../libs/online-presence.inc.php';
/** Public character labels only: no location, last activity or private NPC profile. */
player_api(static function():array{
 alphano_require_login(true);
 $parse=static function($raw):array{
  if(!is_string($raw)||strlen($raw)>1600||($raw!==''&&!preg_match('/^\d+(?:,\d+)*$/D',$raw)))throw new DomainException('Elenco personaggi non valido.',422);
  $ids=$raw===''?[]:array_values(array_unique(array_map('intval',explode(',',$raw))));
  if(count($ids)>100||array_filter($ids,static fn($id)=>$id<1))throw new DomainException('Elenco personaggi non valido.',422);
  return $ids;
 };
 $players=$parse($_GET['players']??'');$npcs=$parse($_GET['npcs']??'');$items=[];$db=ConnectDB();$races=array_column($db->query('SELECT id,nome FROM razze'),'nome','id');
 if($players){$marks=implode(',',array_fill(0,count($players),'?'));foreach($db->query("SELECT id,sesso,posizione,lastmove,bloccato,razza,vampire_human_form,dataregistrazione FROM utenti WHERE id IN ($marks)",$players) as $u){$race=alphano_visible_race($u);if($race===ALPHANO_RACE_LEGACY_SPIRIT)$race=ALPHANO_RACE_SPIRIT;$items['pg:'.$u['id']]=['sex'=>$u['sesso'],'online'=>alphano_player_online($u),'race_id'=>$race,'race'=>$races[$race]??'Razza sconosciuta',...alphano_registration_label((int)$u['dataregistrazione'])];}}
 if($npcs){$marks=implode(',',array_fill(0,count($npcs),'?'));foreach($db->query("SELECT id,active,profile FROM ai_npcs WHERE id IN ($marks)",$npcs) as $n){$p=ai_npc_profile($n);$items['npc:'.$n['id']]=['sex'=>$p['sex'],'online'=>(bool)$n['active'],'race_id'=>(int)$p['race_id'],'race'=>$races[(int)$p['race_id']]??'Razza sconosciuta'];}}
 return ['items'=>(object)$items];
});
