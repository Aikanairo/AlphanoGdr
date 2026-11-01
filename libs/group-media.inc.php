<?php
declare(strict_types=1);
require_once __DIR__.'/player-runtime.inc.php';require_once __DIR__.'/activity.inc.php';
function group_media_context(string $scope,int $id,array $user):array {
 if(!in_array($scope,['corporazioni','organizzazioni'],true))throw new DomainException('Gruppo non valido.',422);$field=$scope==='corporazioni'?'corporazione':'organizzazione';
 if(empty($user['admin'])&&((int)$user[$field]!==$id||empty($user['admin'.$field])))throw new DomainException('Gestione riservata ai responsabili del gruppo.',403);$g=ConnectDB()->query('SELECT id,nome,stendardo,sfondotop FROM '.$scope.' WHERE id=?',[$id])[0]??null;if(!$g)throw new DomainException('Gruppo non trovato.',404);return $g;
}
function group_media_upload(array $file,string $scope,int $id,string $field):string {
 if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])||$file['size']>10*1024*1024)throw new DomainException('Carica un’immagine valida entro 10 MB.',422);
 $bytes=file_get_contents($file['tmp_name']);$size=getimagesizefromstring($bytes);if(!$size||$size[0]*$size[1]>24000000||!in_array($size['mime'],['image/png','image/jpeg','image/webp'],true))throw new DomainException('Formato ammesso: PNG, JPEG o WebP.',422);$source=@imagecreatefromstring($bytes);if(!$source)throw new DomainException('Immagine non leggibile da questo server: prova PNG o JPEG.',422);
 $path='/uploads/groups/'.$scope.'-'.$id.'-'.$field.'-'.substr(hash('sha256',$bytes),0,20).(function_exists('imagewebp')?'.webp':'.png');$folder=htmlroot.'/uploads/groups';if(!is_dir($folder))mkdir($folder,0755,true);$ratio=min(1,2200/max($size[0],$size[1]));$dest=imagecreatetruecolor((int)round($size[0]*$ratio),(int)round($size[1]*$ratio));imagealphablending($dest,false);imagesavealpha($dest,true);imagecopyresampled($dest,$source,0,0,0,0,imagesx($dest),imagesy($dest),$size[0],$size[1]);if(!(function_exists('imagewebp')?imagewebp($dest,htmlroot.$path,88):imagepng($dest,htmlroot.$path,6)))throw new RuntimeException('Salvataggio immagine fallito.');chmod(htmlroot.$path,0644);return $path;
}

function group_header_image(string $scope,int $id):string {if(!in_array($scope,['corporazioni','organizzazioni'],true))return '';return (string)(ConnectDB()->query('SELECT sfondotop FROM '.$scope.' WHERE id=?',[$id])[0]['sfondotop']??'');}
