<?php
declare(strict_types=1);
/** One illustrated control catalogue for desktop chat and its mobile panels. */
function alphano_chat_control_art(string $key):string {
 static $icons=null;
 if($icons===null){$catalog=json_decode(file_get_contents(__DIR__.'/../config/chat-controls.json'),true,32,JSON_THROW_ON_ERROR);$icons=array_column($catalog['assets'],'path','key');}
 if(!isset($icons[$key]))throw new InvalidArgumentException('Unknown chat control: '.$key);
 $symbols=['dialogue'=>'message','location'=>'map','description'=>'book','present'=>'users','combat'=>'swords','options'=>'settings','expand'=>'expand','master'=>'crown','back'=>'back','rooms'=>'city','menu'=>'menu','games'=>'dice'];
 return '<span class="scene-control-art" data-icon="'.$symbols[$key].'" aria-hidden="true"></span>';
}
