<?php
declare(strict_types=1);
/** Optional player-published handles; legacy free-form contacts remain owner-only. */
function alphano_profile_contacts_ready(bool $refresh=false):bool {
 static $ready=null;if($refresh)$ready=null;
 return $ready??=count(ConnectDB()->query("SHOW COLUMNS FROM utenti WHERE Field IN ('telegram','discord')"))===2;
}
function alphano_profile_contact_value(string $field,string $value):string {
 $value=trim($value);
 if($field==='telegram'){
  $value=ltrim($value,'@');if($value==='')return '';
  if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$value))throw new DomainException('Telegram: inserisci il nome utente, con o senza @, usando lettere, numeri e trattini bassi.');
  return '@'.$value;
 }
 if($field!=='discord')throw new DomainException('Contatto non valido.');
 if(mb_strlen($value)>64||preg_match('/[\x00-\x1f\x7f]/u',$value))throw new DomainException('Discord: inserisci un nome utente di massimo 64 caratteri.');
 return $value;
}
