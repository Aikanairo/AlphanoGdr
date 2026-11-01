<?php
declare(strict_types=1);
/** Shared, versioned illustrations: no account writes and no per-icon SQL queries. */
function alphano_profile_illustrations():array {
 static $index=null;
 if($index!==null)return $index;
 $catalog=json_decode(file_get_contents(__DIR__.'/../config/profile-symbols.json'),true,64,JSON_THROW_ON_ERROR);$index=['symbols'=>[],'ethnicities'=>[]];
 foreach($catalog['assets'] as $asset){
  if($asset['group']==='profile')$index['symbols'][substr($asset['key'],8)]=$asset['path'];
  elseif($asset['group']==='ethnicity')$index['ethnicities'][mb_strtolower(trim($asset['label']))]=$asset['path'];
 }
 return $index;
}
function alphano_profile_symbol(string $key):string {
 $catalog=alphano_profile_illustrations();return $catalog['symbols'][$key]??($catalog['symbols']['identity']??'/assets/placeholder.svg');
}
function alphano_ethnicity_art(string $name):string {
 $catalog=alphano_profile_illustrations();return $catalog['ethnicities'][mb_strtolower(trim($name))]??($catalog['ethnicities']['etnia ignota']??'/assets/placeholder.svg');
}
function alphano_profile_tab_symbol(string $template):string {
 return alphano_profile_symbol(match($template){'board-games'=>'knowledge','progression'=>'abilities','identity'=>'identity','history'=>'story','victims'=>'victims','revelations'=>'relationships','equipment'=>'equipment','lineage'=>'lineage','npc-knowledge'=>'knowledge',default=>'identity'});
}

function alphano_profile_chapter_symbol(string $label):string {
 return alphano_profile_symbol(match($label){'Storia'=>'story','Personalità'=>'personality','Stato sociale'=>'social-standing','Amicizie'=>'friendship','Affetti'=>'affection','Animali'=>'animals','Note pubbliche'=>'public-notes','Note Master','Note del Master'=>'master-notes','Condizione'=>'health','Informazioni'=>'knowledge','Contatti offgame'=>'relationships',default=>'public-notes'});
}
