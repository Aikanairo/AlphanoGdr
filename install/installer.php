<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/libs/engine-settings.inc.php';
require_once dirname(__DIR__).'/libs/runtime-requirements.inc.php';
require_once dirname(__DIR__).'/libs/http-context.inc.php';
require_once dirname(__DIR__).'/libs/database-lock.inc.php';
require_once dirname(__DIR__).'/libs/managed-config.inc.php';
function alphano_install_requirements():array{
 $checks=['PHP 8.4 o successivo; rilevato '.PHP_VERSION=>alphano_php_supported(),'Pacchetto completo: vendor/autoload.php'=>is_file(dirname(__DIR__).'/vendor/autoload.php'),'PDO MySQL'=>extension_loaded('pdo_mysql'),'cURL'=>extension_loaded('curl'),'DOM/XML per i PDF'=>extension_loaded('dom')&&extension_loaded('xml'),'iconv'=>extension_loaded('iconv'),'mbstring'=>extension_loaded('mbstring'),'fileinfo'=>extension_loaded('fileinfo'),'OpenSSL'=>extension_loaded('openssl'),'Sessioni'=>extension_loaded('session'),'config/ scrivibile'=>is_writable(dirname(__DIR__).'/config'),'private/ scrivibile'=>is_writable(dirname(__DIR__).'/private'),'uploads/ scrivibile'=>is_writable(dirname(__DIR__).'/uploads')];return $checks;
}
function alphano_seed_insert(PDO $pdo,string $table,array $values):int{
 // Trusted internal table names only. Fill required legacy columns for strict MySQL.
 foreach($pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $c)if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&!str_contains($c['Extra'],'auto_increment'))$values[$c['Field']]=preg_match('/int|decimal|float|double/',$c['Type'])?0:'';
 $cols=array_keys($values);$stmt=$pdo->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')');$stmt->execute(array_values($values));return (int)$pdo->lastInsertId();
}
function alphano_install(array $in):void{
 $root=dirname(__DIR__);$guard=fopen($root.'/private/installer.mutex','c');if(!$guard||!flock($guard,LOCK_EX|LOCK_NB))throw new DomainException('Installazione già in corso.');
 $created=[];$pdo=null;$databaseLock=false;$tempConfig=null;$completed=false;$mapWritten=false;$previousMap=null;
 try{
  if(is_file($root.'/config/local.php')||is_file($root.'/private/installed.lock'))throw new DomainException('Installazione già completata.');
  foreach(alphano_install_requirements() as $label=>$ok)if(!$ok)throw new DomainException('Requisito mancante: '.$label);
  if(alphano_runtime_warning()!==''&&($in['accept_php_risk']??'')!=='1')throw new DomainException('Per proseguire con questa versione PHP, leggi l’avviso e accetta esplicitamente il rischio. Nessuna tabella è stata creata.');
  if(($in['accept_license']??'')!=='1'||!is_string($in['license_hash']??null)||!hash_equals(hash_file('sha256',$root.'/LICENSE'),$in['license_hash']))throw new DomainException('Leggi e accetta la versione corrente della licenza prima di continuare. Nessuna tabella è stata creata.');
  $settings=alphano_validate_site($in,true);
  $host=trim((string)($in['db_host']??''));$name=trim((string)($in['db_name']??''));$user=trim((string)($in['db_user']??''));$pass=(string)($in['db_pass']??'');$socket=(string)($in['db_socket']??'');
  $port=filter_var($in['db_port']??3306,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
  if(!preg_match('/^[a-zA-Z0-9_.:-]+$/D',$host)||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$name)||$user===''||strlen($user)>100||$port===false||preg_match('/[;\r\n\x00]/',$socket)||($socket!==''&&!str_starts_with($socket,'/')))throw new DomainException('Parametri del database non validi.');
  $admin=(string)($in['admin_name']??'');$password=(string)($in['admin_password']??'');
  if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,19}$/D',$admin)||strlen($password)<12||strlen($password)>72||$password!==($in['admin_password_confirm']??''))throw new DomainException('Nome amministratore: 3–20 caratteri. Password: 12–72 caratteri, con conferma uguale.');
  $dsn='mysql:'.($socket!==''?'unix_socket='.$socket:'host='.$host.';port='.$port).';dbname='.$name.';charset=utf8mb4';
  try{$pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,\Pdo\Mysql::ATTR_MULTI_STATEMENTS=>false]);}catch(Throwable){throw new DomainException('Connessione MySQL non riuscita. Verificare host, porta, database, utente e password.');}
  $lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([alphano_lock_name('install',$name,$name)]);if((int)$lock->fetchColumn()!==1)throw new DomainException('Il database è già in uso da un’altra installazione.');$databaseLock=true;
  if($pdo->query('SHOW TABLES')->fetchColumn()!==false)throw new DomainException('Il database deve essere completamente vuoto. Nessuna tabella esistente è stata modificata.');
  $config=['db_host'=>$host,'db_port'=>$port,'db_socket'=>$socket,'db_db'=>$name,'db_user'=>$user,'db_pass'=>$pass,'base_url'=>$settings['base_url']];
  $tempConfig=tempnam($root.'/config','setup-');chmod($tempConfig,0600);if(file_put_contents($tempConfig,"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX)===false)throw new RuntimeException('Scrittura della configurazione non riuscita.');
  // The bundled schema contains no data and no delimiters inside string literals.
  foreach(preg_split('/;\s*(?:\r?\n|$)/',file_get_contents($root.'/database/schema.sql')) as $sql){$sql=trim($sql);if($sql==='')continue;$pdo->exec($sql);if(preg_match('/CREATE TABLE\s+`?([a-z_]+)`?/i',$sql,$m))$created[]=$m[1];}
  $pdo->beginTransaction();$insert=static fn(string $table,array $values)=>alphano_seed_insert($pdo,$table,$values);$now=time();
  $seed=json_decode(file_get_contents($root.'/config/rpg-seed.json'),true,64,JSON_THROW_ON_ERROR);
  foreach($seed['stats'] as $s)$insert('rpg_characteristics',['stat_key'=>$s['key'],'name'=>$s['name'],'description'=>$s['description'],'icon'=>$s['icon'],'position'=>$s['position']]);
  foreach($seed['settings'] as $k=>$v)$insert('rpg_settings',['setting_key'=>$k,'value'=>$v]);
  $insert('board_game_settings',['id'=>1,'settings_json'=>file_get_contents($root.'/config/board-games.json'),'updated_at'=>$now]);
  $insert('chat_work_settings',['id'=>1,'settings'=>'{}','updated_at'=>$now]);
  $id=$insert('utenti',['nome'=>$admin,'password'=>password_hash($password,PASSWORD_DEFAULT),'email'=>$settings['support_email'],'razza'=>0,'sesso'=>'Maschio','etnia'=>0,'araldica'=>0,'sindaco'=>'0','allineamento'=>'NN','admin'=>'1','adminaccademia'=>'1','adminregole'=>'1','fatepower'=>'1','dataregistrazione'=>$now,'posizione'=>'off','motivo'=>'']);
  $insert('rpg_creation',['owner_kind'=>'pg','owner_id'=>$id,'manual_points'=>1,'innates_chosen'=>1,'updated_at'=>$now]);
  $insert('rpg_progress',['owner_kind'=>'pg','owner_id'=>$id,'initial_claimed'=>1,'initial_stats'=>'{}','trained_stats'=>'{}']);
  $insert('game_welcome',['id'=>1,'enabled'=>0,'title'=>'Benvenuto','body'=>'La tua avventura comincia qui.']);
  $insert('meteo',['ora'=>12,'temperatura'=>18,'tempo'=>0,'cielo'=>0,'vento'=>0]);
  $insert('diritti',['bacheca'=>'generale','all'=>'1']);
  $insert('sezioniregole',['id'=>1,'nome'=>'Guida al gioco','manual_key'=>'guide','pos'=>1]);
  $insert('regole',['sezione'=>1,'pos'=>1,'oggetto'=>'Cominciare a giocare','manual_key'=>'getting-started','spiegazione'=>'<h2>Benvenuto</h2><p>Apri la mappa, scegli una città e una chat. Scrivi le azioni del tuo personaggio e rispetta gli altri partecipanti. Lo staff può personalizzare questa guida dalla gestione dei manuali.</p>','summary'=>'Prima guida dimostrativa.','ultimamodifica'=>$now]);
  $settings['registration_enabled']=false;$settings['initial_race']=0;$settings['ethnicities_enabled']=false;$settings['organizations_enabled']=false;$settings['corporations_enabled']=false;
  foreach(['general','chats','races','ethnicities','cities','organizations','corporations','appearance','rules','review'] as $step)$insert('engine_setup_steps',['step_key'=>$step,'status'=>$step==='general'?'completed':'pending','updated_by'=>$id,'updated_at'=>$now]);
  if(($in['sample_data']??'')==='1'){require_once __DIR__.'/demo-data.php';$demoMap=alphano_install_demo_data($pdo,$id,$settings);$previousMap=is_file($root.'/private/world-map.json')?file_get_contents($root.'/private/world-map.json'):null;alphano_managed_save('world-map',$demoMap,alphano_managed_revision('world-map'));$mapWritten=true;}
  alphano_settings_write($settings);
  $acceptance=json_encode(['license_id'=>'AGPL-3.0-only','sha256'=>$in['license_hash'],'accepted_at'=>gmdate('c'),'accepted_by'=>$admin],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
  if(file_put_contents($root.'/private/license-acceptance.json',$acceptance,LOCK_EX)!==strlen($acceptance))throw new DomainException('Registrazione del consenso non riuscita.');chmod($root.'/private/license-acceptance.json',0600);
  foreach(['mail','sessions','temp'] as $dir)if(!is_dir($root.'/private/'.$dir))mkdir($root.'/private/'.$dir,0700,true);
  foreach(['temp','profili/pg','uploads/world','uploads/equipment','uploads/equipment-pending','sessione/log'] as $dir)if(!is_dir($root.'/'.$dir))mkdir($root.'/'.$dir,0755,true);
  $pdo->commit();
  if(!rename($tempConfig,$root.'/config/local.php'))throw new RuntimeException('Attivazione della configurazione non riuscita.');$tempConfig=null;$completed=true;
  file_put_contents($root.'/private/installed.lock',gmdate('c')."\n",LOCK_EX);chmod($root.'/private/installed.lock',0600);
 }catch(Throwable $e){
  if($pdo&&$pdo->inTransaction())$pdo->rollBack();
  if(!$completed&&$pdo&&$created){$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach(array_reverse($created) as $table)$pdo->exec('DROP TABLE `'.$table.'`');$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
  if(!$completed&&$mapWritten){if($previousMap===null)unlink($root.'/private/world-map.json');else file_put_contents($root.'/private/world-map.json',$previousMap,LOCK_EX);}
  if($e instanceof DomainException)throw $e;error_log('[Alphano setup] '.$e->getMessage());throw new DomainException('Installazione non completata. Le nuove tabelle sono state rimosse; consultare il log PHP e riprovare.');
 }finally{
  if($tempConfig&&is_file($tempConfig))unlink($tempConfig);
  if($databaseLock&&$pdo){$s=$pdo->prepare('SELECT RELEASE_LOCK(?)');$s->execute([alphano_lock_name('install',$name,$name)]);}
  flock($guard,LOCK_UN);fclose($guard);
 }
}
