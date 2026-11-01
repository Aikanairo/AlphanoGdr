<?php
declare(strict_types=1);
/** Image compatibility index built only from this installation's own database. */
global $arrimgs;
$arrimgs=(static function():array{
 $index=[];
 foreach(ConnectDB()->query('SELECT chat_key,sfondotop,sfondo,mappa FROM mappe WHERE public_enabled=1') as $entry){
  $key=$entry['chat_key'];unset($entry['chat_key']);$index[$key]=$entry;
 }
 return $index;
})();
