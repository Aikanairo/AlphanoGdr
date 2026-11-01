<?php
declare(strict_types=1);
require_once __DIR__.'/../libs/media-assets.inc.php';
$admin=alphano_require_login();if(!alphano_can('admin'))alphano_fail('Accesso riservato agli amministratori.',403);
$db=ConnectDB();$known=require __DIR__.'/../config/weather-assets.php';$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 alphano_require_csrf();$uploaded=null;
 try{
  foreach($_POST as $v)if(!is_string($v))throw new DomainException('Parametri non validi.');
  $key=$_POST['asset_key']??'';$label=trim($_POST['label']??'');$description=trim($_POST['description']??'');
  if(!isset($known[$key])||$label===''||mb_strlen($label)>120||mb_strlen($description)>500)throw new DomainException('Controlla nome e descrizione dell’icona.');
  $row=$db->query('SELECT * FROM weather_assets WHERE asset_key=?',[$key])[0]??null;if(!$row)throw new DomainException('Icona non disponibile.');$path=$row['image_path'];
  if(isset($_POST['restore']))$path=alphano_weather_asset_path($key);
  if(isset($_FILES['image'])&&($_FILES['image']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$path=$uploaded=alphano_media_upload($_FILES['image'],'meteo/uploads',100);
  $db->execute('UPDATE weather_assets SET label=?,description=?,image_path=?,updated_by=?,updated_at=? WHERE asset_key=?',[$label,$description,$path,$admin,time(),$key]);
  $notice='Icona salvata. La barra meteo utilizzerà questa immagine al prossimo aggiornamento.';
 }catch(Throwable $e){if($uploaded&&($file=alphano_media_file($uploaded)))@unlink($file);$error=$e instanceof DomainException?$e->getMessage():'Impossibile salvare l’icona.';if(!($e instanceof DomainException))error_log('[Icone meteo] '.$e->getMessage());}
}
$assets=$db->query('SELECT * FROM weather_assets ORDER BY FIELD(asset_key,\'clear-day\',\'clear-night\',\'cloudy\',\'storm\',\'steady\',\'rain\',\'snow\',\'fog\',\'calm\',\'breeze\',\'wind\',\'gale\',\'moon-new\',\'moon-waxing\',\'moon-full\',\'moon-waning\')');
require __DIR__.'/../templates/admin/weather-assets.php';
