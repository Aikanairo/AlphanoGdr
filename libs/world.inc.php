<?php
declare(strict_types=1);
require_once __DIR__.'/kernel.inc.php';
function alphano_world_image(string $value):string{
 if(!preg_match('~^/(?:assets|uploads/world)/[a-zA-Z0-9_./-]+\.(?:svg|png|jpe?g|webp)$~D',$value)||str_contains($value,'..'))return '/assets/placeholder.svg';
 if(str_ends_with($value,'.svg')&&!str_starts_with($value,'/assets/'))return '/assets/placeholder.svg';
 return is_file(dirname(__DIR__).$value)?$value:'/assets/placeholder.svg';
}
function alphano_world_upload(array $file):string{
 if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($file['size']??0)>6*1024*1024||!is_uploaded_file($file['tmp_name']??''))throw new DomainException('Caricare una PNG, JPEG o WebP di massimo 6 MB.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??null;$dimensions=@getimagesize($file['tmp_name']);
 if(!$ext||!$dimensions||$dimensions[0]>6000||$dimensions[1]>6000)throw new DomainException('Immagine non valida o troppo grande.');
 $dir=dirname(__DIR__).'/uploads/world';if(!is_dir($dir))mkdir($dir,0755,true);$url='/uploads/world/'.bin2hex(random_bytes(16)).'.'.$ext;
 if(!move_uploaded_file($file['tmp_name'],dirname(__DIR__).$url))throw new RuntimeException('Upload non riuscito.');return $url;
}
