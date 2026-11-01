<?php
declare(strict_types=1);
require_once __DIR__.'/player-runtime.inc.php';
require_once __DIR__.'/profile-art.inc.php';

/** Equal orders remain peers: the schema defines levels, not individual reporting lines. */
function alphano_hierarchy_tiers(array $roles,bool $descending):array {
 $tiers=[];foreach($roles as $role)$tiers[(int)$role['livello']][]=$role;
 $descending?krsort($tiers,SORT_NUMERIC):ksort($tiers,SORT_NUMERIC);
 return array_map(static fn($level,$items)=>['level'=>$level,'roles'=>$items],array_keys($tiers),array_values($tiers));
}
function alphano_hierarchy_chart(array $roles,array $unassigned=[],bool $descending=false):array {
 $general=[];$branches=[];$members=0;
 foreach($roles as $role){$members+=count($role['members']);$branch=(int)$role['branch_id'];if(!$branch){$general[]=$role;continue;}
  if(!isset($branches[$branch]))$branches[$branch]=['id'=>$branch,'name'=>$role['branch_name']?:'Ramo non disponibile','level'=>(int)$role['branch_level'],'roles'=>[]];
  $branches[$branch]['roles'][]=$role;
 }
 uasort($branches,static fn($a,$b)=>[$a['level'],$a['name'],$a['id']]<=>[$b['level'],$b['name'],$b['id']]);$bands=[];
 foreach($branches as $branch){$branch['tiers']=alphano_hierarchy_tiers($branch['roles'],$descending);unset($branch['roles']);$bands[$branch['level']][]=$branch;}
 $bands=array_map(static fn($level,$items)=>['level'=>$level,'branches'=>$items],array_keys($bands),array_values($bands));
 return ['general'=>alphano_hierarchy_tiers($general,$descending),'bands'=>$bands,'unassigned'=>$unassigned,'role_count'=>count($roles),'branch_count'=>count($branches),'member_count'=>$members+count($unassigned),'descending'=>$descending];
}
function alphano_hierarchy_member(array $u):array {
 $portrait=alphano_profile_portrait($u);
 return ['id'=>(int)$u['id'],'nome'=>$u['nome'],'portrait'=>$portrait['url'],'portrait_default'=>$portrait['default']];
}

/** Keep old 25px emblems crisp while displaying modern rank artwork at full size. */
function alphano_hierarchy_small_badge(string $url):bool {
 static $cache=[];if(isset($cache[$url]))return $cache[$url];$path=(string)parse_url($url,PHP_URL_PATH);
 if(!str_starts_with($path,'/')||str_contains($path,'..'))return false;
 foreach([htmlroot,dirname(__DIR__)] as $root)if(is_file($root.$path)){$size=@getimagesize($root.$path);return $cache[$url]=$size&&max($size[0],$size[1])<64;}
 return $cache[$url]=false;
}
