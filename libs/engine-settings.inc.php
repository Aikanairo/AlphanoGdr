<?php
declare(strict_types=1);
require_once __DIR__.'/currency.inc.php';
function alphano_themes():array{return ['blue'=>'Blu notte','black'=>'Nero e argento','brown'=>'Marrone e rame','red'=>'Rosso rubino','purple'=>'Viola ametista'];}
function alphano_escape(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
/** Technical details remain in private storage; the browser receives a reference only. */
function alphano_error_reference(Throwable $error,string $component): string {
 $reference=bin2hex(random_bytes(6));$dir=dirname(__DIR__).'/private';
 if(is_dir($dir)&&is_writable($dir)){
  $file=$dir.'/runtime-errors.log';$entry=json_encode(['at'=>gmdate('c'),'reference'=>$reference,'component'=>$component,'type'=>get_class($error),'message'=>$error->getMessage(),'file'=>basename($error->getFile()),'line'=>$error->getLine()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
  error_log($entry."\n",3,$file);chmod($file,0600);
 }
 return $reference;
}
function alphano_settings_write(array $settings):void{
 $dir=dirname(__DIR__).'/private';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Directory privata non scrivibile.');
 $tmp=tempnam($dir,'settings-');try{chmod($tmp,0600);if(file_put_contents($tmp,json_encode($settings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($tmp,$dir.'/settings.json'))throw new RuntimeException('Impossibile salvare le impostazioni.');}finally{if(is_file($tmp))unlink($tmp);}
}
function alphano_validate_site(array $in,bool $install=false):array{
 $out=[];foreach(['game_name'=>80,'game_description'=>500,'owner_name'=>120,'support_email'=>100,'mail_from'=>100,'smtp_host'=>253,'smtp_user'=>200,'welcome_subject'=>150,'welcome_body'=>6000,'privacy_text'=>20000,'terms_text'=>20000] as $k=>$max){$v=$in[$k]??'';if(!is_string($v)||mb_strlen($v)>$max||str_contains($v,"\0"))throw new DomainException('Campo non valido: '.$k);$out[$k]=trim($v);}
 $currency=$in['currency_name']??($install?'Corone':alphano_currency_name());
 if(!is_string($currency)||trim($currency)===''||mb_strlen($currency)>60||preg_match('/[<>\x00-\x1f\x7f]/u',$currency))throw new DomainException('Nome della valuta non valido: usa da 1 a 60 caratteri di testo, senza HTML.');
 $out['currency_name']=trim($currency);
 foreach(['game_name','owner_name','privacy_text','terms_text'] as $k)if($out[$k]==='')throw new DomainException('Compilare il campo '.$k.'.');
 foreach(['support_email','mail_from'] as $k)if($out[$k]!==''&&(!filter_var($out[$k],FILTER_VALIDATE_EMAIL)||strpbrk($out[$k],"\r\n")))throw new DomainException('Indirizzo email non valido.');
 foreach(['smtp_host','smtp_user','welcome_subject'] as $k)if(strpbrk($out[$k],"\r\n"))throw new DomainException('Intestazione non valida.');
 $base=$in['base_url']??'';$url=is_string($base)?parse_url($base):false;
 if(!$url||!in_array($url['scheme']??'',['https','http'],true)||empty($url['host'])||!empty($url['user'])||!empty($url['pass'])||!empty($url['query'])||!empty($url['fragment'])||!in_array($url['path']??'',['','/'],true)||preg_match('/[\s\x00-\x1f]/',$base))throw new DomainException('Inserire l’URL assoluto del dominio o sottodominio, senza sottocartelle.');
 $out['base_url']=rtrim($base,'/');
 foreach(['theme'=>array_keys(alphano_themes()),'sidebar_position'=>['left','right'],'header_position'=>['top','bottom'],'mail_transport'=>['log','smtp','mail','disabled'],'smtp_security'=>['starttls','smtps','none']] as $k=>$values){if(!in_array($in[$k]??'', $values,true))throw new DomainException('Opzione non valida: '.$k);$out[$k]=$in[$k];}
 $port=filter_var($in['smtp_port']??587,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if($port===false)throw new DomainException('Porta SMTP non valida.');$out['smtp_port']=$port;$out['smtp_tls']=$out['smtp_security']==='starttls';
 foreach(['abilities_enabled','registration_enabled'] as $key)$out[$key]=!empty($in[$key]);
 $out['ai_enabled']=$install?false:!empty($in['ai_enabled']);
 if(!$install){$endpoint=trim((string)($in['ai_url']??''));$parts=parse_url($endpoint);if($out['ai_enabled']&&(!$parts||!in_array($parts['scheme']??'',['https','http'],true)||empty($parts['host'])||!empty($parts['user'])||!empty($parts['pass'])||!empty($parts['query'])||!empty($parts['fragment'])||preg_match('/[\s\x00-\x1f]/',$endpoint)))throw new DomainException('Endpoint IA non valido.');$out['ai_url']=rtrim($endpoint,'/');$out['ai_model']=mb_substr(trim((string)($in['ai_model']??'')),0,150);}
 foreach(['smtp_pass','ai_api_key'] as $key){$v=$in[$key]??'';if(!is_string($v)||strlen($v)>2000)throw new DomainException('Segreto non valido.');if($v!==''||$install)$out[$key]=$v;if(!empty($in['clear_'.$key]))$out[$key]='';}
 if($out['mail_transport']==='smtp'&&$out['smtp_host']==='')throw new DomainException('Indicare il server SMTP.');
 if(in_array($out['mail_transport'],['smtp','mail'],true)&&($out['mail_from']===''||$out['support_email']===''))throw new DomainException('Per inviare email servono mittente e indirizzo di assistenza.');
 return $out;
}
