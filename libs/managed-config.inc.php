<?php
declare(strict_types=1);
/** Private, atomic editor state with optimistic concurrency across browser tabs. */
function alphano_managed_read(string $name):array{
 $file=dirname(__DIR__).'/private/'.$name.'.json';$value=is_file($file)?json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR):[];return is_array($value)?$value:[];
}
function alphano_managed_revision(string $name):string{$file=dirname(__DIR__).'/private/'.$name.'.json';return is_file($file)?hash_file('sha256',$file):hash('sha256','');}
function alphano_managed_save(string $name,array $value,string $revision):void{
 $dir=dirname(__DIR__).'/private';$lock=fopen($dir.'/'.$name.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Salvataggio non disponibile.');
 try{if(!hash_equals(alphano_managed_revision($name),$revision))throw new DomainException('Le impostazioni sono cambiate in un’altra finestra. Ricarica la pagina prima di salvare.');$tmp=tempnam($dir,'config-');try{chmod($tmp,0600);if(file_put_contents($tmp,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($tmp,$dir.'/'.$name.'.json'))throw new RuntimeException('Salvataggio non riuscito.');}finally{if(is_file($tmp))unlink($tmp);}}
 finally{flock($lock,LOCK_UN);fclose($lock);}
}
