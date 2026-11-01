<?php
declare(strict_types=1);
require_once __DIR__.'/transformations.inc.php';
/** One artwork selection for sheets, editor and equipment; an empty override follows race and sex. */
function alphano_profile_default_banner(int $race,string $sex):string {
 return '/assets/placeholder.svg';
}
/** Shared circular portrait source, including the transformed form and gender fallback. */
function alphano_profile_uses_alt_art(array $player):bool {
 $race=(int)($player['razza']??1);
 // Vampire portraits already represent the native form: the optional secondary portrait is their human disguise.
 return in_array($race,[3,6],true)||(in_array($race,[2,5],true)&&!empty($player['vampire_human_form']));
}
function alphano_profile_portrait(array $player):array {
 $race=alphano_visible_race($player);$id=(int)$player['id'];$default='/assets/avatar.svg';
 $custom=(string)(alphano_profile_uses_alt_art($player)&&!empty($player['avatar2'])?$player['avatar2']:($player['avatar']??''));
 if(str_ends_with($custom,'/assets/placeholder.svg'))$custom='';
 return ['url'=>player_media_url($custom,$id,$default),'default'=>$default];
}
function alphano_profile_banner(array $player):array {
 $race=alphano_visible_race($player);$sex=(string)($player['sesso']??'Maschio');
 $default=alphano_profile_default_banner($race,$sex);
 $custom=(string)($player[alphano_profile_uses_alt_art($player)?'banner2':'banner']??'');
 // Both forms may share the main banner until a separate image is chosen.
 if($custom==='')$custom=(string)($player['banner']??'');
 $url=$custom!==''?player_media_url($custom,(int)($player['id']??0),$default):$default;
 return ['url'=>$url,'default'=>$default];
}

/** A lineage survives the human disguise; dead/spirit forms retain their own emblem. */
function alphano_profile_lineage_race(array $player):int {
 $race=alphano_native_race($player);
 return $race===8?4:$race;
}
/** Generic, sex-specific emblem, independent of custom portraits and banners. */
function alphano_profile_race_seal(int $race,string $sex):string {
 return '/assets/placeholder.svg';
}
