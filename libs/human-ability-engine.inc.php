<?php
declare(strict_types=1);
/** Human skills reserve a d100 activation; final actions still need their own opposed test. */
function rpg_human_bonus(array $actor,string $test,?array $ability=null):int {
 $best=0;foreach($actor['_human_buffs']??[] as $b)if(in_array($test,$b['tests'],true))$best=max($best,(int)$b['bonus']);
 if($ability&&isset($ability['human_rules'])&&!empty($ability['activated'])){
  $matches=($ability['effect']['type']??'')===($test==='defence'?'defence':$test);
  if($matches)$best=max($best,(int)$ability['effect']['bonus']);
 }return $best;
}
function rpg_human_active_buffs(array $state,int $round):array {return array_values(array_filter($state['human_buffs']??[],static fn($b)=>(int)$b['until']>=$round&&(int)$b['from']<=$round));}
function rpg_human_buff(array &$state,array $a,int $round,array $tests,string $source):void {
 $state['human_buffs']=array_values(array_filter($state['human_buffs']??[],static fn($b)=>$b['key']!==$a['key']));$state['human_buffs'][]=['key'=>$a['key'],'number'=>$a['human_rules']['number'],'name'=>$a['name'],'bonus'=>$a['effect']['bonus'],'tests'=>$tests,'source'=>$source,'owner_kind'=>$a['owner_kind']??null,'owner_id'=>$a['owner_id']??null,'single_use'=>!empty($a['human_rules']['single_use']),'from'=>$round,'until'=>$round+$a['human_rules']['duration']-1];
}
function rpg_weapon_silver(?array $weapon):bool {if(!$weapon)return false;$rules=rpg_decode($weapon['game_rules']??'{}');return ($rules['offensive_material']??'')==='silver';}
/** The first applicable attempt consumes single-use buffs even when the final action misses. */
function rpg_human_consume(array &$state,string $test,int $round):void {
 $state['human_buffs']=array_values(array_filter($state['human_buffs']??[],static fn($b)=>!((int)$b['from']<=$round&&(int)$b['until']>=$round&&in_array($test,$b['tests'],true)&&!empty($b['single_use']))));
}

/** Form changes interrupt incompatible ongoing techniques, including an ally’s support. */
function rpg_human_valid_buffs(array $state,int $round,array $actor):array {
 return array_values(array_filter(rpg_human_active_buffs($state,$round),static function($b)use($actor){
  if(!empty($b['owner_kind'])&&!empty($b['owner_id'])){try{rpg_get_ability($b['owner_kind'],(int)$b['owner_id'],(int)(array_column(array_filter(rpg_catalog()['abilities'],static fn($a)=>$a['key']===$b['key']),'id')[0]??0));return true;}catch(DomainException){return false;}}
  foreach(rpg_catalog()['abilities'] as $a)if($a['key']===$b['key'])return rpg_ability_form_reason($a,rpg_identity($actor['kind'],(int)$actor['id']))==='';return false;
 }));
}
