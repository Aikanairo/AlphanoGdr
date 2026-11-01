<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';
const ALPHANO_SCENE_ICONS=['compass'=>'Bussola','city'=>'Città','map'=>'Mappa','book'=>'Libro','shield'=>'Stemma','moon'=>'Luna','users'=>'Personaggi','swords'=>'Armi'];
const ALPHANO_MAP_IMAGE_FIELDS=['scene_image'=>'Immagine rappresentativa · 16:9','scene_thumbnail'=>'Miniatura incorniciata · 4:3','mappa'=>'Mappa del luogo','sfondotop'=>'Sfondo superiore','sfondo'=>'Sfondo originale'];
function alphano_media_file(string $path):?string {
 if(!preg_match('~^/(assets|uploads)/[a-zA-Z0-9/_ .-]+$~D',$path,$match)||str_contains($path,'..'))return null;
 foreach([rtrim(htmlroot,'/'),dirname(__DIR__)] as $root){$file=realpath($root.$path);$base=realpath($root.'/'.$match[1]);if($file&&$base&&str_starts_with($file,$base.'/')&&is_file($file))return $file;}
 return null;
}
/** Re-encode uploads to strip metadata, prevent active payloads and preserve transparency. */
function alphano_media_upload(array $file,string $folder,?int $size=null,?array $dimensions=null):string {
 if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>8*1024*1024)throw new DomainException('Scegli un’immagine PNG, JPEG, WebP o GIF entro 8 MB.',422);
 $info=@getimagesize($file['tmp_name']);if(!$info||$info[0]*$info[1]>16000000||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_GIF,IMAGETYPE_WEBP],true))throw new DomainException('Immagine non valida: massimo 16 megapixel.',422);
 if(!function_exists('imagecreatefromstring'))throw new DomainException('Il ridimensionamento richiede l’estensione PHP GD.',422);
 $image=@imagecreatefromstring(file_get_contents($file['tmp_name']));if(!$image)throw new DomainException('Immagine non leggibile.',422);
 if($size){$canvas=imagecreatetruecolor($size,$size);imagealphablending($canvas,false);imagesavealpha($canvas,true);imagefill($canvas,0,0,imagecolorallocatealpha($canvas,0,0,0,127));$ratio=min($size/$info[0],$size/$info[1]);$w=max(1,(int)round($info[0]*$ratio));$h=max(1,(int)round($info[1]*$ratio));imagecopyresampled($canvas,$image,(int)(($size-$w)/2),(int)(($size-$h)/2),0,0,$w,$h,$info[0],$info[1]);imagedestroy($image);$image=$canvas;}
 if($dimensions){[$w,$h]=$dimensions;$canvas=imagecreatetruecolor($w,$h);imagealphablending($canvas,false);imagesavealpha($canvas,true);$ratio=max($w/imagesx($image),$h/imagesy($image));$sw=(int)round($w/$ratio);$sh=(int)round($h/$ratio);imagecopyresampled($canvas,$image,0,0,(int)((imagesx($image)-$sw)/2),(int)((imagesy($image)-$sh)/2),$w,$h,$sw,$sh);imagedestroy($image);$image=$canvas;}
 if(!preg_match('~^[a-z0-9/-]+$~D',$folder)||str_contains($folder,'..'))throw new DomainException('Archivio immagini non valido.',422);
 $path='/uploads/'.$folder.'/'.bin2hex(random_bytes(16)).'.png';$dest=rtrim(htmlroot,'/').$path;$dir=dirname($dest);
 try{if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new RuntimeException('Archivio immagini non disponibile.');imagesavealpha($image,true);if(!imagepng($image,$dest))throw new RuntimeException('Salvataggio immagine non riuscito.');}finally{imagedestroy($image);}
 return $path;
}
function alphano_media_library():array {
 $out=[];foreach(glob(dirname(__DIR__).'/uploads/world/*.{jpg,png,webp}',GLOB_BRACE)?:[] as $file)$out[]=['path'=>'/uploads/world/'.basename($file),'label'=>basename($file)];return $out;
}
/** Refresh the bundled artwork while preserving images uploaded by administrators. */
function alphano_weather_asset_path(string $key,string $path=''):string {
 if($path!=='')return $path;$icon=str_starts_with($key,'moon')||$key==='clear-night'?'moon':match($key){'clear-day'=>'sun','rain','storm'=>'rain','snow'=>'snow','cloudy','fog'=>'cloud',default=>'wind'};return '/assets/weather-'.$icon.'.svg';
}
function alphano_weather_icons(array $weather,string $phase,bool $night):array {
 $known=require __DIR__.'/../config/weather-assets.php';$rows=[];
 foreach(ConnectDB()->query('SELECT * FROM weather_assets') as $r)$rows[$r['asset_key']]=$r;
 $keys=['cielo'=>abs((int)$weather['cielo'])===2?'storm':(abs((int)$weather['cielo'])===1?'cloudy':($night?'clear-night':'clear-day')),'tempo'=>abs((int)$weather['tempo'])===2?((int)$weather['temperatura']>0?'rain':'snow'):'steady','vento'=>['fog','calm','breeze','wind','gale'][min(4,abs((int)$weather['vento']))],'luna'=>match($phase){'Luna Nuova'=>'moon-new','Luna Crescente'=>'moon-waxing','Luna Piena'=>'moon-full',default=>'moon-waning'}];
 $out=[];foreach($keys as $slot=>$key){$r=$rows[$key]??[];$url=alphano_weather_asset_path($key,$r['image_path']??'');$out[$slot]=['key'=>$key,'src'=>alphano_media_file($url)?$url:alphano_weather_asset_path($key),'label'=>$r['label']??$known[$key][0],'description'=>$r['description']??$known[$key][1]];}return $out;
}
