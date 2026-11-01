<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/player-runtime.inc.php';
require_once __DIR__.'/../libs/rpg.inc.php';
player_api(function():array {
 $actor=alphano_require_login(true);$user=alphano_user();$admin=!empty($user['admin']);$in=player_input();$action=player_text($in,'action',40)?:'profile';$kind=in_array($action,['save_race','save_stat','save_ability','save_settings'],true)?'pg':rpg_kind(player_text($in,'kind',8)?:'pg');$id=in_array($action,['save_race','save_stat','save_ability','save_settings'],true)?$actor:rpg_int($in['id']??$actor,1,PHP_INT_MAX,'Personaggio');$edit=$admin||($kind==='pg'&&$id===$actor);
 $own=$kind==='pg'&&$id===$actor;
 // Public profile presentation is distinct from administrative edit rights.
 $profileView=static function(array $sheet)use($own,$edit,$admin,$user):array{
  if(!$own)$sheet['abilities']=array_values(array_filter($sheet['abilities'],static fn($ability)=>!empty($ability['owned'])));
  $sheet['can_manage_malus']=$admin||!empty($user['fatepower']);$sheet['is_owner']=$own;$sheet['can_edit']=$edit;$sheet['is_admin']=$admin;return $sheet;
 };
 if($_SERVER['REQUEST_METHOD']==='GET'){
  if($action==='admin'){if(!$admin)throw new DomainException('Permessi amministrativi richiesti.',403);return rpg_admin_data();}
  if($action==='malus_targets'){if(!$admin&&empty($user['fatepower']))throw new DomainException('Permessi Master richiesti.',403);return ['players'=>getDB()->query('SELECT id,nome AS name FROM utenti ORDER BY nome LIMIT 1000'),'npcs'=>getDB()->query('SELECT id,name FROM ai_npcs ORDER BY name LIMIT 1000')];}
  if($action==='players'){if(!$admin)throw new DomainException('Permessi amministrativi richiesti.',403);$q=player_text($in,'q',50);return ['players'=>getDB()->query('SELECT id,nome AS name,razza AS race_id FROM utenti WHERE nome LIKE ? ORDER BY nome LIMIT 50',[$q.'%'])];}
  if($action==='creation_catalog')return rpg_creation_catalog();
  if($action==='catalog')return rpg_catalog()+['settings'=>['stat_cap'=>100,'initial_points'=>(int)alphano_config('initial_points'),'xp_per_stat'=>1]];
  if($action==='history'){if(!$edit)throw new DomainException('Questo registro è personale.',403);return rpg_history($kind,$id,rpg_int($in['before']??0,0,PHP_INT_MAX,'Pagina'));}
  if($action!=='profile')throw new DomainException('Azione non riconosciuta.',422);return $profileView(rpg_sheet($kind,$id,$edit));
 }
 player_mutation();
 if($action==='save_malus'){if(!$admin&&empty($user['fatepower']))throw new DomainException('Permessi Master richiesti.',403);return $profileView(rpg_save_malus($kind,$id,$in['conditions']??null,player_text($in,'reason',500,true),$actor));}
 if($action==='upload_ability_image'){
  if(!$admin)throw new DomainException('Permessi amministrativi richiesti.',403);
  require_once __DIR__.'/../libs/media-assets.inc.php';
  $path=alphano_media_upload($_FILES['image']??[],'rpg/abilities/uploads',null,[1024,1024]);
  rpg_admin_audit($actor,'upload_ability_image',$path,null,['image'=>$path]);return ['image'=>$path];
 }
 if(in_array($action,['save_race','save_stat','save_ability','save_settings','grant_xp'],true)){
  if(!$admin)throw new DomainException('Permessi amministrativi richiesti.',403);
  $result=match($action){'save_race'=>rpg_admin_save_race($in,$actor),'save_stat'=>rpg_admin_save_stat($in,$actor),'save_ability'=>rpg_admin_save_ability($in,$actor),'save_settings'=>rpg_admin_save_settings($in['settings']??null,$actor),'grant_xp'=>rpg_grant_xp($kind,$id,rpg_int($in['amount']??0,1,1000000,'Esperienza'),player_text($in,'reason',500,true),'admin:'.rpg_request_key($in['request_id']??''),$actor)};return ['success'=>true,'result'=>$result];
 }
 if(!$edit)throw new DomainException('Puoi gestire soltanto il tuo personaggio.',403);
 if($action==='allocation_preview')return ['allocation'=>rpg_decode(rpg_progress($kind,$id)['initial_stats'],array_fill_keys(RPG_STATS,0)),'sheet'=>rpg_sheet($kind,$id),'claimed'=>(bool)rpg_progress($kind,$id)['initial_claimed'],'points'=>(int)alphano_config('initial_points')];
 $request=rpg_request_key($in['request_id']??'');$result=match($action){'creation_complete'=>rpg_complete_creation($kind,$id,$in['allocation']??null,$in['innates']??null,$request,$actor),'allocation_claim'=>rpg_claim_initial($kind,$id,$request,$actor,$in['allocation']??null),'stat_increase'=>rpg_increase_stat($kind,$id,player_text($in,'stat',32,true),rpg_int($in['points']??0,1,100,'Punti'),$request,$actor),'buy_ability'=>rpg_buy_ability($kind,$id,rpg_int($in['ability_id']??0,1,PHP_INT_MAX,'Abilità'),$request,$actor),default=>throw new DomainException('Azione non riconosciuta.',422)};return $profileView($result);
});
