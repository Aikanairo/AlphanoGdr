<?php
declare(strict_types=1);
require_once __DIR__.'/rpg.inc.php';
require_once __DIR__.'/profile-art.inc.php';
require_once __DIR__.'/manuals.inc.php';
function alphano_race_member_counts():array {return array_column(ConnectDB()->query('SELECT '.alphano_race_sql().' AS race_id,COUNT(*) AS total FROM utenti GROUP BY race_id'),'total','race_id');}
function alphano_race_members(?int $raceId=null,int $offset=0,?int $limit=null):array {
 $groups=[];$where=$raceId===null?'':' WHERE '.alphano_race_sql().'=?';$params=$raceId===null?[]:[$raceId];
 $pagination=$limit===null?'':' LIMIT '.max(1,min(20,$limit)).' OFFSET '.max(0,$offset);
 foreach(ConnectDB()->query('SELECT id,nome,razza,vampire_human_form,sesso,infetto,ancestrale,avatar,avatar2,'.alphano_race_sql().' AS logical_race FROM utenti'.$where.' ORDER BY nome,id'.$pagination,$params) as $user){
  $race=(int)$user['logical_race'];$portrait=alphano_profile_portrait($user);$visible=alphano_visible_race($user);$groups[$race][]=['id'=>(int)$user['id'],'name'=>$user['nome'],'portrait'=>$portrait['url'],'portrait_default'=>$portrait['default'],'human_form'=>$visible===alphano_human_race_id()&&$visible!==$race];
 }
 return $groups;
}
/** Public racial lore is read from the existing manuals, never copied into another catalogue. */
function alphano_race_lore(array $race):array {
 return ['html'=>'<p>'.htmlspecialchars($race['description'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>','source'=>null];
}

function alphano_race_view(?int $id,string $sex='Maschio'):array {
 $catalog=rpg_catalog();$races=$catalog['races'];$selected=null;$counts=alphano_race_member_counts();$members=$id===null?[]:alphano_race_members($id,0,20);
 foreach($races as &$race){$race['banner']=alphano_profile_default_banner((int)$race['id'],$sex);$race['icon']=alphano_race_icon((int)$race['id'],$sex);$race['members']=$members[$race['id']]??[];$race['members_count']=(int)($counts[$race['id']]??0);$race['counts']=['innate'=>0,'special'=>0,'purchasable'=>0];foreach($catalog['abilities'] as $a)if($a['enabled']&&rpg_ability_race_allowed($a,(int)$race['id']))$race['counts'][$a['category']]++;if($id===$race['id'])$selected=$race;}unset($race);
 if($id!==null&&!$selected)throw new DomainException('Razza non trovata.',404);
 $groups=['innate'=>[],'purchasable'=>[]];$abilityNames=array_column($catalog['abilities'],'name','id');$statNames=array_column($catalog['stats'],'name','key');
 if($selected)foreach($catalog['abilities'] as $a)if($a['enabled']&&rpg_ability_race_allowed($a,(int)$selected['id'])){
  $a['requirements_text']=[];foreach($a['requirements']['stats']??[] as $key=>$value)$a['requirements_text'][]=$statNames[$key].' ≥ '.$value;
  foreach($a['requirements']['abilities']??[] as $required)$a['requirements_text'][]=$abilityNames[$required]??'Abilità #'.$required;
  if(($a['requirements']['sentient']??null)===true)$a['requirements_text'][]='Senzienza';
  if(($a['requirements']['sentient']??null)===false)$a['requirements_text'][]='Forma Pura';
  $groups[$a['category']==='purchasable'?'purchasable':'innate'][]=$a;
 }
 $groups['all']=array_merge($groups['innate'],$groups['purchasable']);
 $familyKind=$selected?(in_array($selected['id'],[2,5],true)?'vampire':(in_array($selected['id'],[3,6],true)?'lycan':null)):null;
 $families=[];
 if($familyKind)foreach(ConnectDB()->query('SELECT u.id,u.nome,u.razza,u.vampire_human_form,u.sesso,u.avatar,u.avatar2 FROM lineage_members l JOIN utenti u ON u.id=l.player_id WHERE l.kind=? AND l.creator_id IS NULL ORDER BY u.dataregistrazione,u.id',[$familyKind]) as $u){$portrait=alphano_profile_portrait($u);$families[]=['id'=>(int)$u['id'],'name'=>$u['nome'],'portrait'=>$portrait['url'],'portrait_default'=>$portrait['default']];}
 $learnable=[];
 foreach(['attack'=>'Attacco','defence'=>'Difesa','heal'=>'Cura','mental'=>'Discipline mentali','perception'=>'Percezione','narrative'=>'Talenti narrativi','passive'=>'Talenti passivi'] as $kind=>$title){$abilities=array_values(array_filter($groups['purchasable'],static fn($a)=>$a['kind']===$kind));if($abilities)$learnable[]=['title'=>$title,'abilities'=>$abilities];}
 return ['family_kind'=>$familyKind,'families'=>$families,'learnable_groups'=>$learnable,'races'=> $races,'selected'=>$selected,'stats'=>$catalog['stats'],'abilities'=>$groups,'lore'=>$selected?alphano_race_lore($selected):null];
}
